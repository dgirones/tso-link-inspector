<?php
/**
 * Post and comment scanner.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Scanner
 */
class TSOLIIN_Scanner {

	/** Return value when a source scan lock is held by another process. */
	const SCAN_LOCK_BUSY = -1;

	/** Batch processed with zero new links; cron should continue. */
	const SCAN_BATCH_PROGRESS = -2;

	/** @var TSOLIIN_DB */
	private $db;

	/** @var array<int,bool> Post IDs that already received a pre-edit revision this request. */
	private $revision_saved_for_posts = array();

	/**
	 * Dates to restore on wp_update_post when “preserve modified date” is enabled.
	 *
	 * @var array{ID:int,post_modified:string,post_modified_gmt:string}|null
	 */
	private $preserve_modified_context = null;

	/** @var array<string,bool> Request-scoped is_url_editable_in_source() results. */
	private $editable_source_cache = array();

	/** @var int|null Request cache for get_total_posts(). */
	private $total_posts_cache = null;

	public function __construct( TSOLIIN_DB $db ) {
		$this->db = $db;
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	/** @return string[] */
	public function get_post_types() {
		$s = get_option( 'tsoliin_settings', array() );
		$t = isset( $s['post_types'] ) && is_array( $s['post_types'] ) ? $s['post_types'] : array( 'post', 'page' );
		$t = array_map( 'sanitize_key', $t );
		if ( empty( $t ) ) {
			$t = array( 'post', 'page' );
		}
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() && post_type_exists( 'product' ) && ! in_array( 'product', $t, true ) ) {
			$t[] = 'product';
		}
		return $t;
	}

	/**
	 * Builder template post types that often hold header/footer/popup links.
	 *
	 * @return string[]
	 */
	public static function builder_template_post_types() {
		return array( 'elementor_library', 'porto_builder' );
	}

	/**
	 * Public post types plus known builder types (even when not public).
	 *
	 * @return array<string, WP_Post_Type>
	 */
	public function get_settings_post_type_objects() {
		$all = get_post_types( array( 'public' => true ), 'objects' );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		foreach ( self::builder_template_post_types() as $slug ) {
			if ( isset( $all[ $slug ] ) ) {
				continue;
			}
			$obj = get_post_type_object( $slug );
			if ( $obj ) {
				$all[ $slug ] = $obj;
			}
		}
		return $all;
	}

	/**
	 * Builder types that exist on the site but are not enabled in Settings.
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	public function get_unchecked_builder_post_types() {
		$enabled = $this->get_post_types();
		$out     = array();
		foreach ( self::builder_template_post_types() as $slug ) {
			if ( ! post_type_exists( $slug ) || in_array( $slug, $enabled, true ) ) {
				continue;
			}
			$obj   = get_post_type_object( $slug );
			$label = ( $obj && ! empty( $obj->labels->singular_name ) ) ? (string) $obj->labels->singular_name : $slug;
			$out[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Whether a source_key is owned by ACF / WooCommerce / Elementor modules.
	 *
	 * @param string $source_key DB source_key.
	 * @return bool
	 */
	private function is_dedicated_source_key( $source_key ) {
		$sk = (string) $source_key;
		if ( '' === $sk ) {
			return false;
		}
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return true;
		}
		if ( class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_acf_source_key( $sk ) ) {
			return true;
		}
		if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			return true;
		}
		return false;
	}

	/** @return bool */
	private function opt( $key, $default = false ) {
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! array_key_exists( $key, $s ) ) {
			return (bool) $default;
		}
		return ! empty( $s[ $key ] );
	}

	/** @return bool */
	public function is_scan_comments_enabled() {
		return $this->opt( 'scan_comments' );
	}

	/** @return bool */
	public function is_scan_widgets_enabled() {
		return $this->opt( 'scan_widgets', true );
	}

	/**
	 * Prevent concurrent cursor updates for extended source scans (UI + cron).
	 *
	 * @param string $source comments|menus|terms|fse.
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_source_scan_lock( $source ) {
		$source = sanitize_key( (string) $source );
		if ( '' === $source ) {
			return false;
		}
		return $this->db->acquire_transient_lock( 'tsoliin_scan_lock_' . $source, 45 );
	}

	/**
	 * @param string $source comments|menus|terms|fse.
	 * @return void
	 */
	private function release_source_scan_lock( $source ) {
		$source = sanitize_key( (string) $source );
		if ( '' === $source ) {
			return;
		}
		$this->db->release_transient_lock( 'tsoliin_scan_lock_' . $source );
	}

	/**
	 * Add batch scan result to a running total (ignore lock-busy and end-of-queue sentinels).
	 *
	 * @param int $found        Running total.
	 * @param int $batch_result Return value from scan_*_batch().
	 * @return int
	 */
	private function add_scan_batch_found( $found, $batch_result ) {
		$batch_result = (int) $batch_result;
		if ( self::SCAN_LOCK_BUSY === $batch_result || self::SCAN_BATCH_PROGRESS === $batch_result || $batch_result <= 0 ) {
			return (int) $found;
		}
		return (int) $found + $batch_result;
	}

	// -------------------------------------------------------------------------
	// Post count helpers
	// -------------------------------------------------------------------------

	/** @return int */
	public function get_total_posts() {
		if ( null !== $this->total_posts_cache ) {
			return $this->total_posts_cache;
		}
		$types = $this->get_post_types();
		if ( empty( $types ) ) {
			$this->total_posts_cache = 0;
			return 0;
		}
		$q = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$this->total_posts_cache = (int) $q->found_posts;
		return $this->total_posts_cache;
	}

	/**
	 * @param int $page     1-based page.
	 * @param int $per_page Batch size.
	 * @return int[]
	 */
	public function get_post_ids( $page = 1, $per_page = TSOLIIN_BATCH_SIZE ) {
		$q = new WP_Query( array(
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, absint( $per_page ) ),
			'paged'          => max( 1, absint( $page ) ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		return $q->posts ? array_map( 'absint', $q->posts ) : array();
	}

	// -------------------------------------------------------------------------
	// Link extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract links from HTML using DOMDocument.
	 * Compatible with PHP 7.4+ (no mb_convert_encoding needed).
	 *
	 * @param string $html HTML content.
	 * @return array[]
	 */
	public function extract_links( $html ) {
		return $this->dom_extract( (string) $html, 'a', 'href', 'link', 'textContent' );
	}

	/** @return array[] */
	public function extract_images( $html ) {
		return $this->dom_extract( (string) $html, 'img', 'src', 'image', 'alt' );
	}

	/** @return array[] */
	public function extract_iframes( $html ) {
		return $this->dom_extract( (string) $html, 'iframe', 'src', 'iframe', 'title' );
	}

	/**
	 * Generic DOMDocument extractor.
	 *
	 * @param string $html      HTML content.
	 * @param string $tag       Tag name.
	 * @param string $attr      URL attribute.
	 * @param string $type      link_type value.
	 * @param string $text_key  'textContent' or attribute name for anchor text.
	 * @return array[]
	 */
	private function dom_extract( $html, $tag, $attr, $type, $text_key ) {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$dom     = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOWARNING );
		libxml_clear_errors();

		$items = array();
		foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
			$url = trim( (string) $node->getAttribute( $attr ) );
			if ( $this->skip_url( $url ) ) {
				continue;
			}
			$url = $this->clean_url( $url );
			if ( '' === $url ) {
				continue;
			}
			$anchor = ( 'textContent' === $text_key )
				? sanitize_text_field( wp_strip_all_tags( trim( (string) $node->textContent ) ) )
				: sanitize_text_field( trim( (string) $node->getAttribute( $text_key ) ) );
			if ( '' === $anchor && 'a' === $tag ) {
				$anchor = sanitize_text_field( trim( (string) $node->getAttribute( 'title' ) ) );
			}
			if ( '' === $anchor && 'a' === $tag ) {
				$anchor = $this->anchor_from_link_child_image( $node );
			}
			if ( '' === $anchor && 'img' === $tag ) {
				$anchor = sanitize_text_field( trim( (string) $node->getAttribute( 'title' ) ) );
			}
			if ( '' === $anchor && 'img' === $tag ) {
				$class = (string) $node->getAttribute( 'class' );
				if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $wm ) ) {
					$anchor = $this->enrich_image_anchor_from_attachment_id( absint( $wm[1] ) );
				}
			}
			if ( '' === $anchor && 'img' === $tag ) {
				$anchor = $this->enrich_image_anchor_from_attachment_url( $url, '' );
			}
			if ( '' === $anchor && 'img' === $tag ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( is_string( $path ) && '' !== $path ) {
					$anchor = sanitize_text_field( rawurldecode( (string) wp_basename( $path ) ) );
				}
			}
			$items[] = array( 'url' => $url, 'anchor' => $anchor, 'type' => $type );
		}
		return $this->dedup( $items );
	}

	/**
	 * Regex fallback for link extraction.
	 *
	 * @param string $html HTML string.
	 * @return array[]
	 */
	private function regex_links( $html ) {
		$items = array();
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m ) ) {
			foreach ( $m[1] as $i => $href ) {
				$href = trim( $href );
				if ( $this->skip_url( $href ) ) {
					continue;
				}
				$href = $this->clean_url( $href );
				if ( '' === $href ) {
					continue;
				}
				$items[] = array(
					'url'    => $href,
					'anchor' => sanitize_text_field( wp_strip_all_tags( trim( $m[2][ $i ] ) ) ),
					'type'   => 'link',
				);
			}
		}
		return $this->dedup( $items );
	}

	/**
	 * Extract bare http(s) URLs from plain text (common in comment_content without <a> tags).
	 *
	 * @param string $text Comment or other plain/HTML text.
	 * @return array[]
	 */
	private function extract_plain_urls( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}
		$scan_text = $this->strip_embedded_url_markup( $text );
		$items     = array();
		if ( preg_match_all( '#https?://[^\s<>"\']+#i', $scan_text, $matches ) ) {
			foreach ( $matches[0] as $raw ) {
				$url = $this->clean_url( rtrim( (string) $raw, '.,;:!?)' ) );
				if ( '' === $url || $this->skip_url( $url ) ) {
					continue;
				}
				if ( $this->url_should_never_be_plain_text( $url ) ) {
					continue;
				}
				$items[] = array(
					'url'    => $url,
					'anchor' => '',
					'type'   => 'plain',
				);
			}
		}
		return $this->dedup( $items );
	}

	/**
	 * Remove HTML regions that already embed URLs (href, src, etc.) before plain-text scan.
	 *
	 * @param string $text Raw content.
	 * @return string
	 */
	private function strip_embedded_url_markup( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		// Gutenberg block JSON is not plain text — URLs there are handled by block/image scanners.
		$text = preg_replace( '/<!--\s*\/?wp:[^>]*-->/s', ' ', $text );
		$text = preg_replace( '/<a\s[^>]*href=["\'][^"\']+["\'][^>]*>.*?<\/a>/is', ' ', $text );
		$text = preg_replace( '/<img\s[^>]*>/is', ' ', $text );
		$text = preg_replace( '/<(?:picture|source|video|audio|embed|object)\b[^>]*>.*?<\/(?:picture|video|audio|object)>/is', ' ', $text );
		$text = preg_replace( '/<(?:picture|source|video|audio|embed|object)\b[^>]*\/?>/is', ' ', $text );
		$text = preg_replace( '/\s(?:href|src|srcset|cite|poster|action|data-src|data-lazy-src|data-original|data-full-url|data-thumb|data-image|data-bg|data-background)\s*=\s*["\'][^"\']+["\']/i', ' ', $text );
		$text = preg_replace( '/url\s*\(\s*["\']?(https?:\/\/[^"\')\s]+)["\']?\s*\)/i', ' ', $text );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Whether a URL appears inside an HTML hyperlink (a[href]) in content.
	 *
	 * @param string $content Raw or rendered HTML.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post context.
	 * @return bool
	 */
	private function url_in_html_hyperlink( $content, $url, $post_id = 0 ) {
		$content = (string) $content;
		$url_key = $this->scan_url_key( (string) $url, $post_id );
		if ( '' === $content || '' === $url_key ) {
			return false;
		}
		foreach ( $this->extract_links( $content ) as $item ) {
			if ( $this->scan_url_key( $item['url'], $post_id ) === $url_key ) {
				return true;
			}
		}
		if ( false !== stripos( $content, 'href=' ) ) {
			foreach ( $this->regex_links( $content ) as $item ) {
				if ( $this->scan_url_key( $item['url'], $post_id ) === $url_key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a URL is only present as visible text, not inside an HTML link tag.
	 *
	 * @param string $content post_content.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post context.
	 * @return bool
	 */
	private function url_is_plain_text_only_in_content( $content, $url, $post_id = 0 ) {
		if ( $this->url_in_html_hyperlink( $content, $url, $post_id ) ) {
			return false;
		}
		if ( $this->url_in_html_media_markup( $content, $url, $post_id ) ) {
			return false;
		}
		if ( $this->url_in_gutenberg_media_block( $content, $url, $post_id ) ) {
			return false;
		}
		foreach ( $this->extract_plain_urls( $content ) as $item ) {
			if ( $this->scan_url_key( $item['url'], $post_id ) === $this->scan_url_key( (string) $url, $post_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a URL is used as img/src/srcset/poster markup (not plain text).
	 *
	 * @param string $content HTML or block markup.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post context.
	 * @return bool
	 */
	private function url_in_html_media_markup( $content, $url, $post_id = 0 ) {
		$content = (string) $content;
		$url_key = $this->scan_url_key( (string) $url, $post_id );
		if ( '' === $content || '' === $url_key ) {
			return false;
		}
		foreach ( array( 'src', 'srcset', 'poster' ) as $attr ) {
			if ( '' !== $this->extract_matching_tag_snippet( $content, (string) $url, $post_id, $attr ) ) {
				return true;
			}
		}
		foreach ( $this->extract_images( $content ) as $item ) {
			if ( $this->scan_url_key( $item['url'], $post_id ) === $url_key ) {
				return true;
			}
		}
		foreach ( $this->extract_responsive_media_urls( $content ) as $item ) {
			if ( $this->scan_url_key( $item['url'], $post_id ) === $url_key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a URL belongs to a Gutenberg image/gallery/media block (block attrs JSON).
	 *
	 * @param string $raw     Raw post_content.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post context.
	 * @return bool
	 */
	private function url_in_gutenberg_media_block( $raw, $url, $post_id = 0 ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return false;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return false;
		}
		$url_key = $this->scan_url_key( (string) $url, $post_id );
		if ( '' === $url_key ) {
			return false;
		}
		return $this->block_tree_has_media_url( parse_blocks( $raw ), $url_key, $post_id );
	}

	/**
	 * @param array[] $blocks  Parsed blocks.
	 * @param string  $url_key Normalized URL key.
	 * @param int     $post_id Post context.
	 * @return bool
	 */
	private function block_tree_has_media_url( array $blocks, $url_key, $post_id ) {
		$media_blocks = array(
			'core/image',
			'core/gallery',
			'core/cover',
			'core/media-text',
			'core/file',
			'core/video',
			'core/audio',
			'core/post-featured-image',
		);
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$urls = array();
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $this->urls_from_array( $block['attrs'] ) as $candidate ) {
					$urls[] = $this->scan_url_key( $candidate, $post_id );
				}
			}
			$is_media_block = in_array( $name, $media_blocks, true )
				|| ( '' !== $name && false !== strpos( $name, 'gallery' ) );
			if ( $is_media_block ) {
				foreach ( $urls as $key ) {
					if ( $key === $url_key ) {
						return true;
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( $this->block_tree_has_media_url( $block['innerBlocks'], $url_key, $post_id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_looks_like_image_file( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		return (bool) preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico)(?:\?|$)/i', $path );
	}

	/**
	 * Whether a URL points at the WordPress media library (uploads path or known attachment).
	 *
	 * Uses path heuristics only — never attachment_url_to_postid() (too expensive on scan).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_is_wordpress_media_image( $url ) {
		$url = $this->clean_url( (string) $url );
		if ( '' === $url || ! $this->url_looks_like_image_file( $url ) ) {
			return false;
		}
		return $this->url_looks_like_wp_uploads_image( $url );
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_looks_like_wp_uploads_image( $url ) {
		if ( ! $this->url_looks_like_image_file( $url ) ) {
			return false;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		return (bool) preg_match( '#/(?:wp-content/)?uploads/#i', $path );
	}

	/**
	 * Image and media-library URLs must never be stored as plain text.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_should_never_be_plain_text( $url ) {
		return $this->url_is_wordpress_media_image( $url );
	}

	/**
	 * @param string   $url      URL.
	 * @param string[] $sources  Content sources to inspect.
	 * @param int      $post_id  Post context.
	 * @return bool
	 */
	private function url_should_be_image_type( $url, array $sources, $post_id = 0 ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}
		if ( $this->url_is_wordpress_media_image( $url ) ) {
			return true;
		}
		if ( ! $this->url_looks_like_image_file( $url ) ) {
			return false;
		}
		foreach ( $sources as $src ) {
			if ( $this->url_in_html_media_markup( $src, $url, $post_id )
				|| $this->url_in_gutenberg_media_block( $src, $url, $post_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Choose link vs image for a URL taken from Gutenberg block attrs.
	 *
	 * @param array  $attrs Block attrs.
	 * @param string $url   Resolved URL.
	 * @return string link|image
	 */
	private function block_attr_url_type( array $attrs, $url ) {
		$destination = isset( $attrs['linkDestination'] ) ? (string) $attrs['linkDestination'] : '';
		if ( 'custom' === $destination ) {
			$wrap = $this->pick_url_from_assoc( array( 'link' => $attrs['link'] ?? '', 'href' => $attrs['href'] ?? '' ) );
			if ( '' === $wrap && ! empty( $attrs['link'] ) && is_array( $attrs['link'] ) ) {
				$wrap = $this->pick_url_from_assoc( $attrs['link'] );
			}
			if ( '' !== $wrap && $this->scan_url_key( $wrap, 0 ) === $this->scan_url_key( $url, 0 ) ) {
				return 'link';
			}
		}
		if ( ! empty( $attrs['id'] ) || $this->url_looks_like_image_file( $url ) ) {
			return 'image';
		}
		return 'link';
	}

	/**
	 * Prefer real markup types over plain text when the same URL is detected twice.
	 *
	 * @param string $existing Existing item type.
	 * @param string $incoming Incoming item type.
	 * @return string
	 */
	private function prefer_scan_item_type( $existing, $incoming ) {
		$rank = array(
			'iframe' => 5,
			'link'   => 4,
			'image'  => 3,
			'plain'  => 1,
		);
		$existing = isset( $rank[ $existing ] ) ? $existing : 'link';
		$incoming = isset( $rank[ $incoming ] ) ? $incoming : 'link';
		return ( $rank[ $incoming ] ?? 0 ) >= ( $rank[ $existing ] ?? 0 ) ? $incoming : $existing;
	}

	/**
	 * All link targets in comment body: <a href>, regex fallback, and plain-text URLs.
	 *
	 * @param string $content comment_content.
	 * @return array[]
	 */
	private function extract_comment_links( $content ) {
		$content = (string) $content;
		$items   = array();

		foreach ( $this->extract_links( $content ) as $item ) {
			$items[] = $item;
		}
		if ( empty( $items ) && false !== stripos( $content, 'href=' ) ) {
			foreach ( $this->regex_links( $content ) as $item ) {
				$items[] = $item;
			}
		}
		foreach ( $this->extract_plain_urls( $content ) as $item ) {
			$items[] = $item;
		}

		return $this->dedup( $items );
	}

	/**
	 * Extract URLs stored in Gutenberg block attrs (JSON), not always present in rendered HTML.
	 *
	 * @param string $raw Raw post_content.
	 * @return array[]
	 */
	private function extract_block_urls( $raw ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$items = array();
		foreach ( parse_blocks( $raw ) as $block ) {
			foreach ( $this->extract_block_link_items( $block ) as $item ) {
				$items[] = $item;
			}
		}
		return $this->dedup_prefer_anchor( $items );
	}

	/**
	 * Extract links (with anchor text) from a parsed Gutenberg block tree.
	 *
	 * @param array $block Parsed block.
	 * @return array[]
	 */
	private function extract_block_link_items( $block ) {
		$items = array();
		if ( ! is_array( $block ) ) {
			return $items;
		}

		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			foreach ( $this->extract_block_attr_link_items( $block['attrs'], $block_name ) as $item ) {
				$items[] = $item;
			}
		}

		if ( ! empty( $block['innerHTML'] ) ) {
			$inner = (string) $block['innerHTML'];
		} else {
			$inner = $this->get_block_html_snippet( $block );
		}
		if ( '' !== $inner ) {
			$found = $this->extract_links( $inner );
			if ( empty( $found ) && false !== stripos( $inner, 'href=' ) ) {
				$found = $this->regex_links( $inner );
			}
			foreach ( $found as $item ) {
				$items[] = $item;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				foreach ( $this->extract_block_link_items( $inner_block ) as $item ) {
					$items[] = $item;
				}
			}
		}

		$known_urls = array();
		foreach ( $items as $item ) {
			$known_urls[ $this->scan_url_key( (string) $item['url'], 0 ) ] = true;
		}
		foreach ( $this->collect_block_urls( array( $block ) ) as $url ) {
			if ( isset( $known_urls[ $this->scan_url_key( $url, 0 ) ] ) ) {
				continue;
			}
			$items[] = array(
				'url'    => $url,
				'anchor' => '',
				'type'   => $this->url_looks_like_image_file( $url ) ? 'image' : 'link',
			);
		}

		return $items;
	}

	/**
	 * HTML snippet from a parsed block (innerHTML or innerContent).
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private function get_block_html_snippet( $block ) {
		if ( ! is_array( $block ) ) {
			return '';
		}
		if ( ! empty( $block['innerHTML'] ) ) {
			return (string) $block['innerHTML'];
		}
		if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $block['innerContent'] as $part ) {
			if ( is_string( $part ) && '' !== trim( $part ) ) {
				$parts[] = $part;
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Fill missing anchors from raw post HTML and attachment metadata.
	 *
	 * @param array[] $items   Scanned items.
	 * @param string  $raw     Raw post_content.
	 * @param int     $post_id Post ID.
	 * @return array[]
	 */
	private function enrich_scan_item_anchors( array $items, $raw, $post_id = 0, $rendered = '' ) {
		$sources = array_unique(
			array_filter(
				array(
					(string) $raw,
					(string) $rendered,
				)
			)
		);
		foreach ( $items as &$item ) {
			$anchor = isset( $item['anchor'] ) ? trim( (string) $item['anchor'] ) : '';
			$url    = isset( $item['url'] ) ? (string) $item['url'] : '';
			$type   = isset( $item['type'] ) ? (string) $item['type'] : 'link';

			if ( '' === $anchor ) {
				foreach ( $sources as $src ) {
					$anchor = $this->find_anchor_for_url_in_raw( $src, $url, $post_id );
					if ( '' !== $anchor ) {
						break;
					}
				}
			}
			if ( '' === $anchor && in_array( $type, array( 'image', 'link' ), true ) ) {
				$anchor = $this->enrich_image_anchor_from_attachment_url( $url, '' );
			}
			if ( '' !== $anchor ) {
				$item['anchor'] = sanitize_text_field( $anchor );
			}
		}
		unset( $item );
		return $items;
	}

	/**
	 * Find visible link text for a URL inside raw post HTML.
	 *
	 * @param string $raw     post_content.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post context.
	 * @return string
	 */
	private function find_anchor_for_url_in_raw( $raw, $url, $post_id = 0 ) {
		$raw = (string) $raw;
		$url = trim( (string) $url );
		if ( '' === $raw || '' === $url ) {
			return '';
		}

		$needles = array( $url );
		$abs     = self::resolve_to_absolute_url( $url, $post_id );
		if ( '' !== $abs && $abs !== $url ) {
			$needles[] = $abs;
		}
		$play_id = TSOLIIN_HTTP::parse_play_store_app_id( $url );
		if ( '' !== $play_id ) {
			$needles[] = 'id=' . $play_id;
			$needles[] = $play_id;
		}

		$haystacks = array( $raw );
		$decoded     = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $decoded !== $raw ) {
			$haystacks[] = $decoded;
		}

		foreach ( array_unique( array_filter( $needles ) ) as $needle ) {
			foreach ( $haystacks as $haystack ) {
				$pattern = '#<a\s[^>]*href\s*=\s*["\'][^"\']*' . preg_quote( $needle, '#' ) . '[^"\']*["\'][^>]*>(.*?)</a>#is';
				if ( ! preg_match( $pattern, $haystack, $match ) ) {
					continue;
				}
				$text = sanitize_text_field( wp_strip_all_tags( trim( (string) $match[1] ) ) );
				if ( '' === $text && preg_match( '/<img\b[^>]*\salt\s*=\s*["\']([^"\']*)["\']/i', (string) $match[1], $img ) ) {
					$text = sanitize_text_field( trim( (string) $img[1] ) );
				}
				if ( '' !== $text ) {
					return $text;
				}
			}
		}
		return '';
	}

	/**
	 * Visible text for a link that wraps an image (gallery / image blocks).
	 *
	 * @param DOMElement $node Anchor element.
	 * @return string
	 */
	private function anchor_from_link_child_image( $node ) {
		if ( ! $node instanceof DOMElement ) {
			return '';
		}
		foreach ( $node->getElementsByTagName( 'img' ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			$alt = sanitize_text_field( trim( (string) $img->getAttribute( 'alt' ) ) );
			if ( '' !== $alt ) {
				return $alt;
			}
			$src = trim( (string) $img->getAttribute( 'src' ) );
			if ( '' !== $src ) {
				$from_lib = $this->enrich_image_anchor_from_attachment_url( $src, '' );
				if ( '' !== $from_lib ) {
					return $from_lib;
				}
			}
		}
		return '';
	}

	/**
	 * URL + label pairs from Gutenberg block attrs (button, image link, etc.).
	 *
	 * @param array  $attrs      Block attrs.
	 * @param string $block_name Block name (e.g. core/image); empty when unknown.
	 * @return array[]
	 */
	private function extract_block_attr_link_items( $attrs, $block_name = '' ) {
		$items = array();
		if ( ! is_array( $attrs ) ) {
			return $items;
		}

		$url = $this->pick_url_from_assoc( $attrs );
		if ( '' !== $url ) {
			$label = $this->pick_label_from_assoc( $attrs );
			if ( '' === $label && ! empty( $attrs['id'] ) && $this->block_name_may_reference_attachment( $block_name ) ) {
				$label = $this->attachment_label( absint( $attrs['id'] ) );
			}
			$items[] = array(
				'url'    => $url,
				'anchor' => $label,
				'type'   => $this->block_attr_url_type( $attrs, $url ),
			);
		}

		// Only resolve bare attachment IDs on media blocks — many other blocks use "id"
		// for non-attachment purposes and would otherwise invent false image rows.
		if ( ! empty( $attrs['id'] ) && empty( $url ) && $this->block_name_may_reference_attachment( $block_name ) ) {
			$attachment_id = absint( $attrs['id'] );
			$file_url      = $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;
			if ( is_string( $file_url ) && '' !== $file_url ) {
				$items[] = array(
					'url'    => $file_url,
					'anchor' => $this->attachment_label( $attachment_id ),
					'type'   => 'image',
				);
			}
		}

		return $items;
	}

	/**
	 * Whether a Gutenberg block name is expected to store a media library attachment id.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private function block_name_may_reference_attachment( $block_name ) {
		$name = (string) $block_name;
		if ( '' === $name ) {
			return false;
		}
		$media_blocks = array(
			'core/image',
			'core/gallery',
			'core/cover',
			'core/media-text',
			'core/file',
			'core/video',
			'core/audio',
			'core/post-featured-image',
		);
		if ( in_array( $name, $media_blocks, true ) ) {
			return true;
		}
		return false !== strpos( $name, 'image' )
			|| false !== strpos( $name, 'gallery' )
			|| false !== strpos( $name, 'cover' )
			|| false !== strpos( $name, 'media' );
	}

	/**
	 * @param array $data Associative array from block attrs.
	 * @return string
	 */
	private function pick_url_from_assoc( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$url_keys = array( 'url', 'link', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl' );
		foreach ( $url_keys as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}
			$candidate = $this->clean_url( trim( $data[ $key ] ) );
			if ( '' !== $candidate && ! $this->skip_url( $candidate ) ) {
				return $candidate;
			}
		}
		if ( ! empty( $data['link'] ) && is_array( $data['link'] ) ) {
			return $this->pick_url_from_assoc( $data['link'] );
		}
		return '';
	}

	/**
	 * @param array $data Associative array from block attrs.
	 * @return string
	 */
	private function pick_label_from_assoc( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$label_keys = array( 'text', 'title', 'linktitle', 'label', 'content', 'alt' );
		foreach ( $label_keys as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}
			$label = sanitize_text_field( trim( $data[ $key ] ) );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Alt → title → caption for a media-library attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	private function attachment_label( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return '';
		}
		$file_url = wp_get_attachment_url( $attachment_id );
		if ( is_string( $file_url ) && '' !== $file_url ) {
			return $this->enrich_image_anchor_from_attachment_url( $file_url, '' );
		}
		return '';
	}

	/**
	 * Alt / title / caption from a known media library attachment ID.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	private function enrich_image_anchor_from_attachment_id( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			return sanitize_text_field( $alt );
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return '';
		}
		if ( '' !== trim( (string) $attachment->post_title ) ) {
			return sanitize_text_field( (string) $attachment->post_title );
		}
		if ( '' !== trim( (string) $attachment->post_excerpt ) ) {
			return sanitize_text_field( (string) $attachment->post_excerpt );
		}
		return '';
	}

	/**
	 * Alt / title / caption from the media library for an uploads URL.
	 *
	 * @param string $url            Image or file URL.
	 * @param string $current_anchor Existing anchor.
	 * @return string
	 */
	private function enrich_image_anchor_from_attachment_url( $url, $current_anchor = '' ) {
		if ( '' !== trim( (string) $current_anchor ) ) {
			return (string) $current_anchor;
		}
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		// Skip reverse URL→ID lookup for non-uploads (external CDNs, etc.).
		if ( ! $this->url_looks_like_wp_uploads_image( $url )
			&& ! TSOLIIN_HTTP::parse_attachment_id_from_url( $url ) ) {
			return '';
		}

		$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( $url );
		if ( ! $attachment_id ) {
			return '';
		}
		return $this->enrich_image_anchor_from_attachment_id( $attachment_id );
	}

	/**
	 * Collapse alias URLs (e.g. Play Store id) into one row with the best anchor.
	 *
	 * @param array[] $items   Items.
	 * @param int     $post_id Post ID.
	 * @return array[]
	 */
	private function finalize_scan_items( array $items, $post_id ) {
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			$key = $this->scan_item_dedupe_key( $item, $post_id );
			if ( isset( $seen[ $key ] ) ) {
				$idx        = (int) $seen[ $key ];
				$new_anchor = isset( $item['anchor'] ) ? trim( (string) $item['anchor'] ) : '';
				$old_anchor = isset( $out[ $idx ]['anchor'] ) ? trim( (string) $out[ $idx ]['anchor'] ) : '';
				if ( '' !== $new_anchor && '' === $old_anchor ) {
					$out[ $idx ]['anchor'] = $item['anchor'];
				}
				$out[ $idx ]['url'] = $this->prefer_scan_url( $out[ $idx ]['url'], $item['url'] );
				if ( ! empty( $item['source_key'] ) && empty( $out[ $idx ]['source_key'] ) ) {
					$out[ $idx ]['source_key'] = $item['source_key'];
				}
				$existing_type       = isset( $out[ $idx ]['type'] ) ? (string) $out[ $idx ]['type'] : 'link';
				$incoming_type       = isset( $item['type'] ) ? (string) $item['type'] : 'link';
				$out[ $idx ]['type'] = $this->prefer_scan_item_type( $existing_type, $incoming_type );
				continue;
			}
			$seen[ $key ] = count( $out );
			$item['url']    = $this->prefer_stored_scan_url( $item['url'] );
			$out[]          = $item;
		}
		return $out;
	}

	/**
	 * Mark rows as link when the URL is used in an HTML hyperlink (a[href]), not only as img src.
	 *
	 * @param array[] $items    Scanned items.
	 * @param string  $content  post_content.
	 * @param int     $post_id  Post ID.
	 * @param string  $rendered Rendered post HTML.
	 * @return array[]
	 */
	private function reclassify_hyperlink_items( array $items, $content, $post_id, $rendered = '' ) {
		$sources = array_unique(
			array_filter(
				array(
					(string) $content,
					(string) $rendered,
				)
			)
		);
		foreach ( $items as &$item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			foreach ( $sources as $src ) {
				if ( $this->url_in_html_hyperlink( $src, $url, $post_id ) ) {
					$item['type'] = 'link';
					break;
				}
			}
		}
		unset( $item );
		return $items;
	}

	/**
	 * Mark link rows as plain when the URL is only visible text (no <a href>).
	 *
	 * @param array[] $items   Scanned items.
	 * @param string  $content post_content.
	 * @param int     $post_id Post ID.
	 * @return array[]
	 */
	private function reclassify_plain_text_items( array $items, $content, $post_id ) {
		if ( ! $this->opt( 'scan_plain_urls', true ) ) {
			return $items;
		}
		$sources = array_unique(
			array_filter(
				array(
					(string) $content,
				)
			)
		);
		foreach ( $items as &$item ) {
			$type = isset( $item['type'] ) ? (string) $item['type'] : 'link';
			$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( ! in_array( $type, array( 'link', 'plain' ), true ) || '' === $url ) {
				continue;
			}
			if ( $this->url_should_never_be_plain_text( $url ) ) {
				$item['type'] = 'image';
				continue;
			}
			$is_plain = false;
			foreach ( $sources as $src ) {
				if ( $this->url_is_plain_text_only_in_content( $src, $url, $post_id ) ) {
					$is_plain = true;
					break;
				}
			}
			$item['type'] = $is_plain ? 'plain' : ( 'plain' === $type ? 'link' : $type );
		}
		unset( $item );
		return $items;
	}

	/**
	 * Ensure image/gallery block URLs and img src rows are stored as image, not plain/link.
	 *
	 * @param array[] $items    Scanned items.
	 * @param string  $content  post_content.
	 * @param int     $post_id  Post ID.
	 * @param string  $rendered Rendered post HTML.
	 * @return array[]
	 */
	private function reclassify_image_items( array $items, $content, $post_id, $rendered = '' ) {
		$sources = array_unique(
			array_filter(
				array(
					(string) $content,
					(string) $rendered,
				)
			)
		);
		foreach ( $items as &$item ) {
			$type = isset( $item['type'] ) ? (string) $item['type'] : 'link';
			$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( 'iframe' === $type || '' === $url ) {
				continue;
			}
			if ( 'image' === $type ) {
				continue;
			}
			if ( $this->url_should_be_image_type( $url, $sources, $post_id ) ) {
				$item['type'] = 'image';
			}
		}
		unset( $item );
		return $items;
	}

	/**
	 * Deduplicate items keeping the first non-empty anchor for each URL.
	 *
	 * @param array[] $items Scanned items.
	 * @return array[]
	 */
	private function dedup_prefer_anchor( $items ) {
		$by_url = array();
		foreach ( $items as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			if ( ! isset( $by_url[ $url ] ) ) {
				$by_url[ $url ] = $item;
				continue;
			}
			$new_anchor = isset( $item['anchor'] ) ? trim( (string) $item['anchor'] ) : '';
			$old_anchor = isset( $by_url[ $url ]['anchor'] ) ? trim( (string) $by_url[ $url ]['anchor'] ) : '';
			if ( '' !== $new_anchor && '' === $old_anchor ) {
				$by_url[ $url ]['anchor'] = $item['anchor'];
			}
		}
		return array_values( $by_url );
	}

	/**
	 * @param array[] $blocks Parsed blocks from parse_blocks().
	 * @return string[]
	 */
	private function collect_block_urls( $blocks ) {
		$urls = array();
		if ( ! is_array( $blocks ) ) {
			return $urls;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $this->urls_from_array( $block['attrs'] ) as $url ) {
					$urls[] = $url;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( $this->collect_block_urls( $block['innerBlocks'] ) as $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Recursively collect URL-like strings from block attrs or nested arrays.
	 *
	 * @param mixed $data   Array or scalar from block attrs.
	 * @param int   $depth  Recursion guard.
	 * @return string[]
	 */
	private function urls_from_array( $data, $depth = 0 ) {
		if ( $depth > 10 || ! is_array( $data ) ) {
			return array();
		}
		$urls     = array();
		$url_keys = array( 'url', 'link', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl', 'src' );
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$key_lower = strtolower( (string) $key );
				$looks_url = in_array( $key_lower, $url_keys, true )
					|| (bool) preg_match( '/(?:url|link|href)$/i', $key_lower );
				if ( ! $looks_url ) {
					continue;
				}
				$candidate = $this->clean_url( trim( $value ) );
				if ( '' === $candidate || $this->skip_url( $candidate ) ) {
					continue;
				}
				if ( preg_match( '#^https?://#i', $candidate ) || 0 === strpos( $candidate, '//' ) || '/' === $candidate[0] ) {
					$urls[] = $candidate;
				}
			} elseif ( is_array( $value ) ) {
				foreach ( $this->urls_from_array( $value, $depth + 1 ) as $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract responsive image / media URLs (srcset, picture, video, audio, embed).
	 *
	 * @param string $html Rendered HTML.
	 * @return array[]
	 */
	private function extract_responsive_media_urls( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}
		$items = array();

		if ( preg_match_all( '/\ssrcset=["\']([^"\']+)["\']/i', $html, $srcsets ) ) {
			foreach ( $srcsets[1] as $srcset ) {
				foreach ( preg_split( '/\s*,\s*/', (string) $srcset ) as $part ) {
					$part = trim( (string) $part );
					if ( '' === $part ) {
						continue;
					}
					$chunks = preg_split( '/\s+/', $part );
					$url    = $this->clean_url( trim( (string) $chunks[0] ) );
					if ( '' !== $url && ! $this->skip_url( $url ) ) {
						$items[] = array( 'url' => $url, 'anchor' => '', 'type' => 'image' );
					}
				}
			}
		}

		foreach ( array( 'source', 'video', 'audio', 'embed' ) as $tag ) {
			foreach ( $this->dom_extract( $html, $tag, 'src', 'image', 'title' ) as $item ) {
				$items[] = $item;
			}
		}
		foreach ( $this->dom_extract( $html, 'object', 'data', 'link', 'title' ) as $item ) {
			$items[] = $item;
		}

		return $this->dedup( $items );
	}

	/**
	 * Extract URLs from common page-builder data-* attributes.
	 *
	 * @param string $html HTML or block markup.
	 * @return array[]
	 */
	private function extract_data_attr_urls( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}
		$data_attrs = array( 'data-url', 'data-href', 'data-link', 'data-button-url', 'data-bg-url' );
		$wrapped      = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$dom          = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOWARNING );
		libxml_clear_errors();

		$items = array();
		$walk  = function ( DOMNode $node ) use ( &$walk, $data_attrs, &$items ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				/** @var DOMElement $node */
				foreach ( $data_attrs as $attr ) {
					if ( ! $node->hasAttribute( $attr ) ) {
						continue;
					}
					$url = $this->clean_url( trim( (string) $node->getAttribute( $attr ) ) );
					if ( '' === $url || $this->skip_url( $url ) ) {
						continue;
					}
					$items[] = array( 'url' => $url, 'anchor' => '', 'type' => 'link' );
				}
			}
			if ( $node->hasChildNodes() ) {
				foreach ( $node->childNodes as $child ) {
					$walk( $child );
				}
			}
		};
		if ( $dom->documentElement ) {
			$walk( $dom->documentElement );
		}
		return $this->dedup( $items );
	}

	/**
	 * Normalized key for deduplicating the same target under different URL forms.
	 *
	 * @param string $url     Raw URL.
	 * @param int    $post_id Post context.
	 * @return string
	 */
	private function scan_url_key( $url, $post_id = 0 ) {
		$url = $this->clean_url( (string) $url );
		$play_id = TSOLIIN_HTTP::parse_play_store_app_id( $url );
		if ( '' !== $play_id ) {
			return 'play.google.com:' . $play_id;
		}
		$abs = self::resolve_to_absolute_url( $url, $post_id );
		$abs = $abs ? $abs : $url;
		if ( class_exists( 'TSOLIIN_HTTP' ) ) {
			$abs = TSOLIIN_HTTP::strip_noise_query_params_from_url( $abs );
		}
		$key = strtolower( rtrim( $abs, '/' ) );
		return $key;
	}

	/**
	 * Pick the URL spelling to store when two scan hits are the same resource.
	 *
	 * Prefers the form without noise-only query params (e.g. Jetpack ?ssl=1).
	 *
	 * @param string $existing Existing URL.
	 * @param string $incoming Incoming URL.
	 * @return string
	 */
	private function prefer_scan_url( $existing, $incoming ) {
		$existing = $this->clean_url( (string) $existing );
		$incoming = $this->clean_url( (string) $incoming );
		if ( '' === $incoming ) {
			return $existing;
		}
		if ( '' === $existing ) {
			return $incoming;
		}
		if ( class_exists( 'TSOLIIN_HTTP' ) ) {
			$canon_existing = TSOLIIN_HTTP::strip_noise_query_params_from_url( $existing );
			$canon_incoming = TSOLIIN_HTTP::strip_noise_query_params_from_url( $incoming );
			if ( 0 === strcasecmp( $canon_existing, $canon_incoming ) ) {
				if ( 0 === strcasecmp( $existing, $canon_existing ) ) {
					return $existing;
				}
				if ( 0 === strcasecmp( $incoming, $canon_incoming ) ) {
					return $incoming;
				}
				return strlen( $existing ) <= strlen( $incoming ) ? $existing : $incoming;
			}
		}
		return strlen( $existing ) <= strlen( $incoming ) ? $existing : $incoming;
	}

	/**
	 * Canonicalize a scanned URL for storage when only noise query params differ.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function prefer_stored_scan_url( $url ) {
		$url = $this->clean_url( (string) $url );
		if ( '' === $url || ! class_exists( 'TSOLIIN_HTTP' ) ) {
			return $url;
		}
		$stripped = TSOLIIN_HTTP::strip_noise_query_params_from_url( $url );
		return $this->prefer_scan_url( $url, $stripped );
	}

	/**
	 * Deduplicate scan batch items. Non-empty source_key (e.g. WooCommerce) stays separate from content.
	 *
	 * @param array $item    Scan item.
	 * @param int   $post_id Post ID.
	 * @return string
	 */
	private function scan_item_dedupe_key( array $item, $post_id = 0 ) {
		$url = isset( $item['url'] ) ? (string) $item['url'] : '';
		$key = $this->scan_url_key( $url, $post_id );
		$sk  = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
		if ( '' !== $sk ) {
			return $key . "\0sk:" . $sk;
		}
		return $key;
	}

	/**
	 * Add a scanned item when its URL is not already in the batch.
	 *
	 * @param array[]  $items   Items list (by ref).
	 * @param array    $seen    Map of normalized URL key → item index (by ref).
	 * @param array    $item    { url, anchor, type }.
	 * @param int      $post_id Post ID for URL normalization.
	 */
	private function push_scan_item( array &$items, array &$seen, array $item, $post_id = 0 ) {
		$url = isset( $item['url'] ) ? (string) $item['url'] : '';
		if ( '' === $url || $this->skip_url( $url ) ) {
			return;
		}
		$key = $this->scan_item_dedupe_key( $item, $post_id );
		if ( isset( $seen[ $key ] ) ) {
			$idx        = (int) $seen[ $key ];
			$new_anchor = isset( $item['anchor'] ) ? trim( (string) $item['anchor'] ) : '';
			if ( isset( $items[ $idx ] ) ) {
				$existing_type = isset( $items[ $idx ]['type'] ) ? (string) $items[ $idx ]['type'] : 'link';
				$incoming_type = isset( $item['type'] ) ? (string) $item['type'] : 'link';
				$items[ $idx ]['type'] = $this->prefer_scan_item_type( $existing_type, $incoming_type );
				if ( '' !== $new_anchor && '' === trim( (string) $items[ $idx ]['anchor'] ) ) {
					$items[ $idx ]['anchor'] = sanitize_text_field( $item['anchor'] );
				}
				if ( ! empty( $item['source_key'] ) && empty( $items[ $idx ]['source_key'] ) ) {
					$items[ $idx ]['source_key'] = (string) $item['source_key'];
				}
				if ( isset( $items[ $idx ]['url'] ) ) {
					$items[ $idx ]['url'] = $this->prefer_scan_url( $items[ $idx ]['url'], $item['url'] );
				}
			}
			return;
		}
		$item['url'] = $this->prefer_stored_scan_url( $url );
		$seen[ $key ] = count( $items );
		$items[]      = $item;
	}

	/**
	 * Sanitize a URL for storage (keep {} and accented chars, remove control chars only).
	 *
	 * Also decodes HTML entities (e.g. `&#038;` / `&amp;` — how WordPress/esc_url write a
	 * literal `&` into rendered markup). Regex-based extractors (srcset, the `regex_links()`
	 * fallback) read raw un-parsed HTML text, so without this the entity's own `#` character
	 * gets mistaken by URL-parsing code for the start of a fragment, corrupting the stored
	 * URL and defeating dedup (e.g. `...jpg?w=460&#038;ssl=1` → `...jpg#038;ssl=1`). A no-op
	 * for URLs already decoded by DOMDocument (dom_extract()) — nothing left to decode there.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function clean_url( $url ) {
		$url = (string) $url;
		if ( false !== strpos( $url, '&' ) ) {
			$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			$url = str_replace( '&amp;', '&', $url );
		}
		return trim( str_replace( array( "\0", "\r", "\n" ), '', $url ) );
	}

	/**
	 * Convert root-relative or post-relative hrefs to an absolute URL for HTTP checks.
	 * The original href is kept in the database and in post content.
	 *
	 * @param string $url     URL as stored (may be /path/, ../path, or full https://…).
	 * @param int    $post_id Post the link belongs to (for path-relative resolution).
	 * @return string Absolute http(s) URL, or the original string if it cannot be resolved.
	 */
	public static function resolve_to_absolute_url( $url, $post_id = 0 ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$home   = wp_parse_url( home_url( '/' ) );
			$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
			return $scheme . ':' . $url;
		}
		if ( '/' === $url[0] ) {
			$path_only = (string) strtok( $url, '?#' );
			$home_path = self::get_site_home_path_prefix();
			if ( '' !== $home_path && self::path_includes_site_subdirectory( $path_only, $home_path ) ) {
				$absolute = self::build_absolute_url_from_root_path( $url );
				return is_string( $absolute ) && '' !== $absolute ? $absolute : $url;
			}
			$absolute = home_url( $url );
			return is_string( $absolute ) ? $absolute : $url;
		}
		$post_id = absint( $post_id );
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				return self::join_relative_to_base( $permalink, $url );
			}
		}
		$absolute = home_url( '/' . ltrim( $url, '/' ) );
		return is_string( $absolute ) ? $absolute : $url;
	}

	/**
	 * Home URL path prefix for subdirectory installs (e.g. /blog2), or empty at site root.
	 *
	 * @return string
	 */
	private static function get_site_home_path_prefix() {
		$home_parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home_parts ) || empty( $home_parts['path'] ) ) {
			return '';
		}
		$home_path = untrailingslashit( (string) $home_parts['path'] );
		return ( '/' === $home_path ) ? '' : $home_path;
	}

	/**
	 * Whether a root-relative path already includes the WP subdirectory (wp_make_link_relative style).
	 *
	 * @param string $path      Path only (no query/fragment).
	 * @param string $home_path Site home path prefix.
	 * @return bool
	 */
	private static function path_includes_site_subdirectory( $path, $home_path ) {
		$path      = untrailingslashit( (string) $path );
		$home_path = untrailingslashit( (string) $home_path );
		if ( '' === $home_path ) {
			return false;
		}
		return $path === $home_path || 0 === strpos( $path, $home_path . '/' );
	}

	/**
	 * Build scheme://host/path from a domain-root-relative URL (/blog2/... on subdirectory installs).
	 *
	 * @param string $url Root-relative URL (may include query/fragment).
	 * @return string
	 */
	private static function build_absolute_url_from_root_path( $url ) {
		$url_parts = wp_parse_url( (string) $url );
		if ( ! is_array( $url_parts ) ) {
			return '';
		}
		$path = isset( $url_parts['path'] ) ? (string) $url_parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $base ) {
			$base_parts = wp_parse_url( (string) $base );
			if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) ) {
				continue;
			}
			$scheme = ! empty( $base_parts['scheme'] ) ? (string) $base_parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
			$port   = ! empty( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';
			$query  = isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '';
			$frag   = isset( $url_parts['fragment'] ) ? '#' . $url_parts['fragment'] : '';
			return $scheme . '://' . $base_parts['host'] . $port . $path . $query . $frag;
		}
		return '';
	}

	/**
	 * Resolve a relative reference against a base URL (RFC 3986 style, path only).
	 *
	 * @param string $base_url Absolute base URL (usually post permalink).
	 * @param string $relative Relative path (e.g. other-page/, ../sibling/).
	 * @return string
	 */
	private static function join_relative_to_base( $base_url, $relative ) {
		$parts = wp_parse_url( $base_url );
		if ( empty( $parts['host'] ) ) {
			return home_url( '/' . ltrim( $relative, '/' ) );
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( '/' !== substr( $path, -1 ) ) {
			$path = dirname( $path ) . '/';
		}
		$path = self::normalize_path( $path . ltrim( $relative, '/' ) );
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Collapse /./ and /../ in a URL path.
	 *
	 * @param string $path URL path starting with /.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$segments = explode( '/', (string) $path );
		$stack    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
		return '/' . implode( '/', $stack );
	}

	/**
	 * Possible href spellings in post_content for the same logical link.
	 *
	 * @param string $url     Stored link URL.
	 * @param int    $post_id Post ID.
	 * @return string[]
	 */
	private function url_content_variants( $url, $post_id = 0 ) {
		$url = (string) $url;
		$post_id = absint( $post_id );
		$variants = array( $url );
		$resolved = self::resolve_to_absolute_url( $url, $post_id );
		if ( '' !== $resolved && $resolved !== $url ) {
			$variants[] = $resolved;
		}
		if ( function_exists( 'wp_make_link_relative' ) ) {
			foreach ( array( $url, $resolved ) as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$rel = wp_make_link_relative( $candidate );
				if ( is_string( $rel ) && '' !== $rel && ! in_array( $rel, $variants, true ) ) {
					$variants[] = $rel;
				}
			}
		}
		$variants = self::add_internal_host_equivalent_urls( $url, $variants, $post_id );
		if ( '' !== $resolved ) {
			$variants = self::add_internal_host_equivalent_urls( $resolved, $variants, $post_id );
		}
		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * www / non-www spellings for same-site absolute URLs.
	 *
	 * @param string   $url      URL.
	 * @param string[] $variants Existing variants.
	 * @param int      $post_id  Post context.
	 * @return string[]
	 */
	private static function add_internal_host_equivalent_urls( $url, array $variants, $post_id = 0 ) {
		$url = (string) $url;
		if ( '' === $url || ! class_exists( 'TSOLIIN_HTTP' ) || ! TSOLIIN_HTTP::is_internal_link_url( $url, $post_id ) ) {
			return $variants;
		}
		$alt = TSOLIIN_HTTP::toggle_www_host_in_url( $url );
		if ( '' !== $alt && ! in_array( $alt, $variants, true ) ) {
			$variants[] = $alt;
		}
		return $variants;
	}

	/**
	 * URL strings that may appear in href/src for a stored link (frontend nofollow, etc.).
	 *
	 * @param string $url     Stored link URL.
	 * @param int    $post_id Post ID for relative resolution.
	 * @return string[]
	 */
	public static function get_href_match_variants( $url, $post_id = 0 ) {
		$url     = (string) $url;
		$post_id = absint( $post_id );
		$decoded = urldecode( $url );
		$core    = array( $url );
		$resolved = self::resolve_to_absolute_url( $url, $post_id );
		if ( '' !== $resolved && $resolved !== $url ) {
			$core[] = $resolved;
		}
		if ( function_exists( 'wp_make_link_relative' ) ) {
			foreach ( array( $url, $resolved ) as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$rel = wp_make_link_relative( $candidate );
				if ( is_string( $rel ) && '' !== $rel && ! in_array( $rel, $core, true ) ) {
					$core[] = $rel;
				}
			}
		}
		$base = array_unique( array_filter( array_merge(
			$core,
			array(
				$decoded,
				rawurldecode( $url ),
				html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ),
				str_replace( '&', '&amp;', $url ),
				str_replace( '&', '&amp;', $decoded ),
				str_replace( '&amp;', '&', $url ),
				untrailingslashit( $url ),
				trailingslashit( untrailingslashit( $url ) ),
			)
		) ) );
		foreach ( $base as $candidate ) {
			$base = self::add_internal_host_equivalent_urls( $candidate, $base, $post_id );
		}

		usort(
			$base,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);

		return array_values( $base );
	}

	/**
	 * @param string $url Raw URL.
	 * @return bool True if should be skipped.
	 */
	private function skip_url( $url ) {
		if ( '' === $url ) {
			return true;
		}
		if ( '#' === $url[0] ) {
			return true;
		}
		// Unresolved template placeholders (e.g. `${sec.image}`, `{{state.logo}}`) are not
		// real URLs — they come from inline JS/HTML templates (client-side newsletter
		// builders, etc.) whose `src="${var}"` markup is stored as literal post content
		// before the template engine ever interpolates it. They will always 404.
		if ( false !== strpos( $url, '${' ) || false !== strpos( $url, '{{' ) ) {
			return true;
		}
		$scheme       = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$skip_schemes = array( 'mailto', 'tel', 'javascript', 'data', 'sms', 'ftp', 'ftps', 'skype', 'blob' );
		if ( in_array( $scheme, $skip_schemes, true ) ) {
			return true;
		}
		if ( 0 === strpos( $url, 'data:' ) ) {
			return true;
		}
		if ( TSOLIIN_HTTP::is_attachment_permalink_url( $url ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if a URL matches the ignore list (domains or prefixes).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_ignored( $url ) {
		return TSOLIIN_HTTP::is_ignored_url( $url );
	}

	private function dedup( $items ) {
		$seen = array();
		$out  = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item['url'], $seen, true ) ) {
				$out[]  = $item;
				$seen[] = $item['url'];
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Content rendering
	// -------------------------------------------------------------------------

	/**
	 * Render Gutenberg blocks without firing all the_content hooks.
	 *
	 * @param string $raw Raw post_content.
	 * @return string
	 */
	private function render_content( $raw ) {
		$raw  = (string) $raw;
		$html = do_blocks( $raw );
		if ( '' === trim( $html ) && '' !== trim( $raw ) ) {
			$html = $raw;
		}
		if ( false !== strpos( $html, '[' ) && function_exists( 'do_shortcode' ) ) {
			$expanded = do_shortcode( $html );
			if ( is_string( $expanded ) && '' !== trim( $expanded ) ) {
				$html = $expanded;
			}
		}
		return $html;
	}

	/**
	 * Images referenced by Classic Editor [gallery] shortcodes (ids are stored, not <img> URLs).
	 * Only explicit ids=/include= are scanned (see get_gallery_shortcode_attachment_ids).
	 * Build marker: tsoliin-gallery-shortcode-scan-v3.
	 *
	 * @param string $content Raw post_content.
	 * @param int    $post_id Post ID (API compatibility; bare galleries no longer expand children).
	 * @return array[]
	 */
	private function extract_gallery_shortcode_images( $content, $post_id = 0 ) {
		$content = (string) $content;
		$post_id = absint( $post_id );
		if ( '' === $content || false === stripos( $content, '[gallery' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/\[gallery\b([^\]]*)\]/i', $content, $matches ) ) {
			return array();
		}

		$items = array();
		$seen  = array();
		foreach ( $matches[1] as $attr_string ) {
			$atts = shortcode_parse_atts( 'gallery ' . trim( (string) $attr_string ) );
			if ( ! is_array( $atts ) ) {
				$atts = array();
			}
			$size = 'thumbnail';
			if ( ! empty( $atts['size'] ) ) {
				$size = sanitize_key( (string) $atts['size'] );
			}
			foreach ( $this->get_gallery_shortcode_attachment_ids( $atts, $post_id ) as $attachment_id ) {
				$url = $this->attachment_image_url_for_size( $attachment_id, $size );
				if ( '' === $url || isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ] = true;
				$items[]      = array(
					'url'    => $url,
					'anchor' => $this->attachment_label( $attachment_id ),
					'type'   => 'image',
				);
			}
		}
		return $items;
	}

	/**
	 * Attachment IDs listed in a [gallery] shortcode attribute set.
	 *
	 * Bare [gallery] (no ids/include) intentionally returns no IDs. WordPress would show all
	 * child attachments, but that pulls every image uploaded while editing the post — even
	 * when the shortcode was removed or the files never appear in the editor HTML.
	 *
	 * @param array $atts    Parsed shortcode attributes.
	 * @param int   $post_id Unused (kept for callers); bare galleries do not expand by parent.
	 * @return int[]
	 */
	private function get_gallery_shortcode_attachment_ids( array $atts, $post_id = 0 ) {
		unset( $post_id );
		$ids = array();
		if ( ! empty( $atts['ids'] ) ) {
			$ids = array_map( 'absint', preg_split( '/\s*,\s*/', (string) $atts['ids'] ) );
		} elseif ( ! empty( $atts['include'] ) ) {
			$ids = array_map( 'absint', preg_split( '/\s*,\s*/', (string) $atts['include'] ) );
		}
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $size          Image size slug.
	 * @return string
	 */
	private function attachment_image_url_for_size( $attachment_id, $size = 'thumbnail' ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'wp_get_attachment_image_src' ) ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$url = $this->clean_url( (string) $src[0] );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
		$file_url = wp_get_attachment_url( $attachment_id );
		return is_string( $file_url ) ? $this->clean_url( $file_url ) : '';
	}

	/**
	 * Whether an attachment ID is referenced by a [gallery] shortcode in post content.
	 *
	 * @param string $content       Raw post_content.
	 * @param int    $attachment_id Attachment post ID.
	 * @param int    $post_id       Post ID.
	 * @return bool
	 */
	private function attachment_id_in_classic_gallery_shortcodes( $content, $attachment_id, $post_id = 0 ) {
		$content       = (string) $content;
		$attachment_id = absint( $attachment_id );
		$post_id       = absint( $post_id );
		if ( '' === $content || $attachment_id <= 0 || false === stripos( $content, '[gallery' ) ) {
			return false;
		}
		if ( ! preg_match_all( '/\[gallery\b([^\]]*)\]/i', $content, $matches ) ) {
			return false;
		}
		foreach ( $matches[1] as $attr_string ) {
			$atts = shortcode_parse_atts( 'gallery ' . trim( (string) $attr_string ) );
			if ( ! is_array( $atts ) ) {
				$atts = array();
			}
			$ids = $this->get_gallery_shortcode_attachment_ids( $atts, $post_id );
			if ( in_array( $attachment_id, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a media URL belongs to a Classic Editor [gallery] shortcode in the post.
	 *
	 * @param string $content Raw post_content.
	 * @param string $url     Image URL.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	private function url_in_classic_gallery_shortcode( $content, $url, $post_id = 0 ) {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return false;
		}
		$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $url );
		if ( $attachment_id <= 0 ) {
			return false;
		}
		return $this->attachment_id_in_classic_gallery_shortcodes( $content, $attachment_id, $post_id );
	}

	/**
	 * Opening [gallery …] shortcode that references this media URL (for edit preview).
	 *
	 * @param string $content Raw post_content.
	 * @param string $url     Image URL.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function extract_gallery_shortcode_snippet_for_url( $content, $url, $post_id = 0 ) {
		$content       = (string) $content;
		$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $url );
		$post_id       = absint( $post_id );
		if ( '' === $content || $attachment_id <= 0 || false === stripos( $content, '[gallery' ) ) {
			return '';
		}
		if ( ! preg_match_all( '/\[gallery\b([^\]]*)\]/i', $content, $matches ) ) {
			return '';
		}
		foreach ( $matches[0] as $i => $full ) {
			$atts = shortcode_parse_atts( 'gallery ' . trim( (string) $matches[1][ $i ] ) );
			if ( ! is_array( $atts ) ) {
				$atts = array();
			}
			$ids = $this->get_gallery_shortcode_attachment_ids( $atts, $post_id );
			if ( in_array( $attachment_id, $ids, true ) ) {
				return (string) $full;
			}
		}
		return '';
	}

	/**
	 * Rewrite [gallery ids|include] when replacing one media URL with another attachment URL.
	 *
	 * @param string $content Raw post_content.
	 * @param string $old_url Stored image URL.
	 * @param string $new_url Replacement image URL.
	 * @param int    $post_id Post ID.
	 * @return string|null Updated content or null when unchanged / not applicable.
	 */
	private function replace_url_in_classic_gallery_shortcodes( $content, $old_url, $new_url, $post_id = 0 ) {
		$content = (string) $content;
		$old_id  = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $old_url );
		$new_id  = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $new_url );
		if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
			return null;
		}
		if ( ! $this->attachment_id_in_classic_gallery_shortcodes( $content, $old_id, absint( $post_id ) ) ) {
			return null;
		}
		return $this->rewrite_attachment_id_in_classic_gallery_shortcodes( $content, $old_id, $new_id, absint( $post_id ) );
	}

	/**
	 * Remove an attachment from Classic Editor [gallery] shortcodes (unlink).
	 *
	 * @param string $content       Raw post_content.
	 * @param string $url           Image URL.
	 * @param int    $post_id       Post ID.
	 * @return string|null Updated content or null when unchanged / not applicable.
	 */
	private function unlink_url_from_classic_gallery_shortcodes( $content, $url, $post_id = 0 ) {
		$content       = (string) $content;
		$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $url );
		if ( $attachment_id <= 0 ) {
			return null;
		}
		if ( ! $this->attachment_id_in_classic_gallery_shortcodes( $content, $attachment_id, absint( $post_id ) ) ) {
			return null;
		}
		return $this->rewrite_attachment_id_in_classic_gallery_shortcodes( $content, $attachment_id, 0, absint( $post_id ) );
	}

	/**
	 * Replace or remove an attachment ID inside [gallery ids="…"] / include="…" shortcodes.
	 *
	 * @param string $content       Raw post_content.
	 * @param int    $old_id        Attachment ID to find.
	 * @param int    $new_id        Replacement ID, or 0 to remove.
	 * @param int    $post_id       Post ID (unused for ids/include rewrite; reserved).
	 * @return string|null
	 */
	private function rewrite_attachment_id_in_classic_gallery_shortcodes( $content, $old_id, $new_id, $post_id = 0 ) {
		$content = (string) $content;
		$old_id  = absint( $old_id );
		$new_id  = absint( $new_id );
		if ( '' === $content || $old_id <= 0 || false === stripos( $content, '[gallery' ) ) {
			return null;
		}
		if ( ! preg_match_all( '/\[gallery\b([^\]]*)\]/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$changed = false;
		// Process from the end so offsets stay valid while rewriting.
		for ( $i = count( $matches[0] ) - 1; $i >= 0; $i-- ) {
			$full_match  = (string) $matches[0][ $i ][0];
			$full_offset = (int) $matches[0][ $i ][1];
			$attr_string = (string) $matches[1][ $i ][0];
			$atts        = shortcode_parse_atts( 'gallery ' . trim( $attr_string ) );
			if ( ! is_array( $atts ) ) {
				$atts = array();
			}

			$attr_key = '';
			if ( ! empty( $atts['ids'] ) ) {
				$attr_key = 'ids';
			} elseif ( ! empty( $atts['include'] ) ) {
				$attr_key = 'include';
			}
			if ( '' === $attr_key ) {
				continue;
			}

			$raw_ids = preg_split( '/\s*,\s*/', (string) $atts[ $attr_key ] );
			if ( ! is_array( $raw_ids ) ) {
				continue;
			}
			$next_ids = array();
			$hit      = false;
			foreach ( $raw_ids as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id <= 0 ) {
					continue;
				}
				if ( $id === $old_id ) {
					$hit = true;
					if ( $new_id > 0 ) {
						$next_ids[] = $new_id;
					}
					continue;
				}
				$next_ids[] = $id;
			}
			if ( ! $hit ) {
				continue;
			}

			if ( empty( $next_ids ) ) {
				$replacement = '';
			} else {
				$new_list    = implode( ',', $next_ids );
				$replacement = preg_replace(
					'/\b' . preg_quote( $attr_key, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]]+)/i',
					$attr_key . '="' . $new_list . '"',
					$full_match,
					1
				);
				if ( ! is_string( $replacement ) || $replacement === $full_match ) {
					// Fallback: rebuild a minimal shortcode preserving other attributes.
					$parts = array( 'gallery' );
					foreach ( $atts as $k => $v ) {
						if ( ! is_string( $k ) || ! is_scalar( $v ) ) {
							continue;
						}
						if ( $k === $attr_key ) {
							$parts[] = $attr_key . '="' . $new_list . '"';
							continue;
						}
						$parts[] = $k . '="' . esc_attr( (string) $v ) . '"';
					}
					$replacement = '[' . implode( ' ', $parts ) . ']';
				}
			}

			$content = substr_replace( $content, $replacement, $full_offset, strlen( $full_match ) );
			$changed = true;
		}

		return $changed ? $content : null;
	}

	// -------------------------------------------------------------------------
	// Scan
	// -------------------------------------------------------------------------

	/**
	 * Scan a single post and upsert all found links into the DB.
	 * Force-all: when true, scans ALL types (link, image, iframe) regardless of settings.
	 * Used by save_post hook so manual URL changes in the editor are always detected.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $force_all Force scan all types (default false = respect settings).
	 * @return int Number of items found.
	 */
	public function scan_post( $post_id, $force_all = false ) {
		$post_id = absint( $post_id );
		clean_post_cache( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			$this->db->delete_links_for_post( $post_id );
			return 0;
		}

		$html  = $this->render_content( $post->post_content );
		$items = array();
		$seen  = array();

		// a href links (rendered + raw post_content).
		$links = $this->extract_links( $html );
		foreach ( $this->extract_links( $post->post_content ) as $item ) {
			$this->push_scan_item( $items, $seen, $item, $post_id );
		}
		if ( empty( $links ) && false !== strpos( $post->post_content, 'href=' ) ) {
			$links = $this->regex_links( $post->post_content );
		}
		foreach ( $links as $item ) {
			$this->push_scan_item( $items, $seen, $item, $post_id );
		}

		// Bare http(s) URLs in post_content (no <a> tag).
		if ( $force_all || $this->opt( 'scan_plain_urls', true ) ) {
			foreach ( $this->extract_plain_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// Gutenberg block attrs (url/link/href in JSON).
		if ( $force_all || $this->opt( 'scan_block_attrs', true ) ) {
			foreach ( $this->extract_block_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// img src (rendered + raw post_content + classic [gallery] shortcodes).
		if ( $force_all || $this->opt( 'scan_images' ) ) {
			foreach ( $this->extract_images( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
			foreach ( $this->extract_images( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
			foreach ( $this->extract_gallery_shortcode_images( $post->post_content, $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// srcset, picture, video, audio, embed, object.
		if ( $force_all || $this->opt( 'scan_srcset', true ) ) {
			foreach ( $this->extract_responsive_media_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// iframe src.
		if ( $force_all || $this->opt( 'scan_iframes' ) ) {
			foreach ( $this->extract_iframes( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// Page-builder data-* link attributes.
		if ( $force_all || $this->opt( 'scan_data_attrs', true ) ) {
			foreach ( $this->extract_data_attr_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
			foreach ( $this->extract_data_attr_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// Meta fields (ACF, custom fields).
		if ( $force_all || $this->opt( 'scan_meta' ) ) {
			foreach ( $this->scan_meta( $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		// WooCommerce product fields (opt-in).
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() && TSOLIIN_WooCommerce::is_product( $post_id ) ) {
			foreach ( TSOLIIN_WooCommerce::collect_product_items( $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		if ( ( $force_all || $this->opt( 'scan_meta' ) ) && class_exists( 'TSOLIIN_Acf', false ) ) {
			foreach ( TSOLIIN_Acf::collect_post_id_items( $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		if ( ( $force_all || $this->opt( 'scan_meta' ) ) && class_exists( 'TSOLIIN_Elementor', false ) ) {
			foreach ( TSOLIIN_Elementor::collect_post_items( $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item, $post_id );
			}
		}

		$items = $this->enrich_scan_item_anchors( $items, $post->post_content, $post_id, $html );
		$items = $this->finalize_scan_items( $items, $post_id );
		$items = $this->reclassify_hyperlink_items( $items, $post->post_content, $post_id, $html );
		$items = $this->reclassify_image_items( $items, $post->post_content, $post_id, $html );
		$items = $this->reclassify_plain_text_items( $items, $post->post_content, $post_id );

		$this->remove_stale( $post_id, $items );

		foreach ( $items as $item ) {
			if ( $this->is_ignored( $item['url'] ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? (string) $item['type'] : 'link';
			$sk   = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
			if ( '' !== $sk && $this->is_dedicated_source_key( $sk ) ) {
				$this->db->upsert_link( $post_id, $item['url'], $item['anchor'], $type, $sk );
				continue;
			}
			if ( in_array( $type, array( 'link', 'image', 'iframe' ), true )
				&& ! $this->is_url_editable_in_post( $post_id, $item['url'], $type ) ) {
				continue;
			}
			$this->db->upsert_link( $post_id, $item['url'], $item['anchor'], $type );
		}
		return count( $items );
	}

	/**
	 * Scan a batch of posts.
	 *
	 * @param int  $page       1-based page.
	 * @param int  $per_page   Batch size.
	 * @param bool $posts_only When true, skip comments/menus/terms/FSE/widgets (handled by the BG worker phases).
	 * @return array { scanned: int, found: int, done: bool }
	 */
	public function scan_batch( $page = 1, $per_page = TSOLIIN_BATCH_SIZE, $posts_only = false ) {
		$ids   = $this->get_post_ids( $page, $per_page );
		$total = $this->get_total_posts();
		$done  = empty( $ids ) || ( $page * $per_page >= $total ) || ( count( $ids ) < $per_page );

		$found = 0;
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$found += $this->scan_post( $id );
			}
			if ( ! $posts_only ) {
				if ( $this->opt( 'scan_comments' ) ) {
					$found = $this->add_scan_batch_found( $found, $this->scan_comments_batch( TSOLIIN_BATCH_SIZE * 5 ) );
				}
				if ( $this->opt( 'scan_menus', true ) ) {
					$found = $this->add_scan_batch_found( $found, $this->scan_menus_batch( TSOLIIN_BATCH_SIZE * 5 ) );
				}
				if ( $this->opt( 'scan_terms', true ) ) {
					$found = $this->add_scan_batch_found( $found, $this->scan_terms_batch( TSOLIIN_BATCH_SIZE * 5 ) );
				}
				if ( $this->opt( 'scan_fse', true ) ) {
					$found = $this->add_scan_batch_found( $found, $this->scan_fse_batch( TSOLIIN_BATCH_SIZE * 2 ) );
				}
				if ( class_exists( 'TSOLIIN_Sources' ) ) {
					$found += TSOLIIN_Sources::scan_registered_batch( $this, TSOLIIN_BATCH_SIZE * 2 );
				}
			}
		}

		if ( ! $posts_only ) {
			if ( $done && $this->is_scan_widgets_enabled() ) {
				$found = $this->add_scan_batch_found( $found, $this->scan_all_widgets() );
			} elseif ( ! empty( $ids ) && $this->is_scan_widgets_enabled() ) {
				$found = $this->add_scan_batch_found( $found, $this->scan_widgets_batch( TSOLIIN_BATCH_SIZE * 3 ) );
			}
			if ( $done && $this->opt( 'scan_meta' ) && class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_plugin_active() ) {
				$found = $this->add_scan_batch_found( $found, $this->scan_acf_options() );
			}
		}
		return array( 'scanned' => count( $ids ), 'found' => $found, 'done' => $done );
	}

	/**
	 * Remove DB rows for URLs no longer found by the latest post scan.
	 *
	 * @param int     $post_id Post ID.
	 * @param array[] $items   Items from the current scan (url, anchor, type).
	 */
	private function remove_stale( $post_id, array $items ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$table   = $this->db->get_table();

		$active      = array();
		$active_keys = array();
		foreach ( $items as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->is_ignored( $url ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? (string) $item['type'] : 'link';
			if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) ) {
				continue;
			}
			$sk = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
			// Match upsert eligibility: do not keep rows for URLs found only in rendered HTML
			// (or other non-editable places) — those produced false "Go to edit" targets.
			if ( '' !== $sk && $this->is_dedicated_source_key( $sk ) ) {
				$active_keys[ $sk ] = true;
				$active[ $this->scan_url_key( $url, $post_id ) ] = true;
				continue;
			}
			if ( in_array( $type, array( 'link', 'image', 'iframe' ), true )
				&& ! $this->is_url_editable_in_post( $post_id, $url, $type ) ) {
				continue;
			}
			$active[ $this->scan_url_key( $url, $post_id ) ] = true;
			if ( '' !== $sk ) {
				$active_keys[ $sk ] = true;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, link_url, link_type, source_key FROM {$table} WHERE post_id = %d AND link_type NOT IN ('comment', 'menu', 'widget', 'term', 'template', 'wp_block')",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $existing ) ) {
			return;
		}
		$keepers = array();
		foreach ( $existing as $row ) {
			$type = isset( $row->link_type ) ? (string) $row->link_type : 'link';
			if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) ) {
				continue;
			}
			$sk = isset( $row->source_key ) ? (string) $row->source_key : '';
			if ( $this->is_dedicated_source_key( $sk ) ) {
				if ( isset( $active_keys[ $sk ] ) ) {
					continue;
				}
				$this->db->delete_link( (int) $row->id );
				continue;
			}
			$key = $this->scan_url_key( (string) $row->link_url, $post_id );
			if ( ! isset( $active[ $key ] ) ) {
				$this->db->delete_link( (int) $row->id );
				continue;
			}
			if ( ! isset( $keepers[ $key ] ) ) {
				$keepers[ $key ] = $row;
				continue;
			}
			$keeper    = $keepers[ $key ];
			$preferred = $this->prefer_scan_url( (string) $keeper->link_url, (string) $row->link_url );
			if ( $preferred !== (string) $keeper->link_url ) {
				$this->normalize_stored_link_url( (int) $keeper->id, $preferred );
				$keeper->link_url = $preferred;
				$keepers[ $key ]  = $keeper;
			}
			$this->db->delete_link( (int) $row->id );
		}
	}

	/**
	 * Update a stored link URL without resetting HTTP check state.
	 *
	 * @param int    $link_id Link row ID.
	 * @param string $url     Canonical URL.
	 */
	private function normalize_stored_link_url( $link_id, $url ) {
		global $wpdb;
		$link_id = absint( $link_id );
		$url     = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( $link_id <= 0 || '' === $url ) {
			return;
		}
		$table = $this->db->get_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->update(
			$table,
			array( 'link_url' => $url ),
			array( 'id' => $link_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	// -------------------------------------------------------------------------
	// Meta scan
	// -------------------------------------------------------------------------

	/**
	 * Scan post meta for URLs (ACF, custom fields).
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public function scan_meta( $post_id ) {
		$all_meta = get_post_meta( absint( $post_id ) );
		if ( empty( $all_meta ) ) {
			return array();
		}

		$out = array();
		foreach ( $all_meta as $key => $values ) {
			if ( ! $this->should_include_meta_key( (string) $key, (array) $values ) ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				$this->extract_meta_value( maybe_unserialize( $v ), (string) $key, $out );
			}
		}
		return $this->dedup( $out );
	}

	/** @param mixed $val */
	private function extract_meta_value( $val, $key, &$out ) {
		if ( is_string( $val ) ) {
			$has_markup_url = ( false !== strpos( $val, 'href=' ) )
				|| ( false !== strpos( $val, 'src=' ) )
				|| ( false !== stripos( $val, 'data-url' ) )
				|| ( false !== stripos( $val, 'data-href' ) )
				|| ( false !== stripos( $val, 'data-src' ) );
			if ( $has_markup_url ) {
				$found = $this->extract_links( $val );
				foreach ( $this->extract_images( $val ) as $img ) {
					$found[] = $img;
				}
				foreach ( $this->extract_iframes( $val ) as $frame ) {
					$found[] = $frame;
				}
				foreach ( $this->extract_data_attr_urls( $val ) as $data_item ) {
					$found[] = $data_item;
				}
				if ( empty( $found ) ) {
					$found = $this->regex_links( $val );
				}
				foreach ( $found as $item ) {
					$item['anchor'] = ! empty( $item['anchor'] ) ? $item['anchor'] : sanitize_text_field( $key );
					$out[]          = $item;
				}
			} elseif ( $this->looks_like_link_url( $val ) ) {
				$out[] = array( 'url' => $this->clean_url( trim( $val ) ), 'anchor' => sanitize_text_field( $key ), 'type' => 'link' );
			}
			if ( $this->opt( 'scan_meta_plain', true ) && is_string( $val ) ) {
				foreach ( $this->extract_plain_urls( $val ) as $plain ) {
					$plain['anchor'] = sanitize_text_field( $key );
					$out[]           = $plain;
				}
			}
		} elseif ( is_array( $val ) ) {
			if ( isset( $val['url'] ) && is_string( $val['url'] ) && $this->looks_like_link_url( $val['url'] ) ) {
				$anchor = ! empty( $val['title'] ) ? sanitize_text_field( (string) $val['title'] ) : sanitize_text_field( $key );
				$out[]  = array(
					'url'    => $this->clean_url( trim( (string) $val['url'] ) ),
					'anchor' => $anchor,
					'type'   => 'link',
				);
			} else {
				foreach ( $val as $sub ) {
					$this->extract_meta_value( $sub, $key, $out );
				}
			}
		} elseif ( is_object( $val ) ) {
			foreach ( get_object_vars( $val ) as $sub ) {
				$this->extract_meta_value( $sub, $key, $out );
			}
		}
	}

	/**
	 * Meta keys excluded from scan/replace (defaults plus user list from Settings).
	 *
	 * @return string[]
	 */
	private function get_meta_exclude_keys() {
		$s    = get_option( 'tsoliin_settings', array() );
		$user = array();
		if ( isset( $s['meta_exclude_keys'] ) && is_array( $s['meta_exclude_keys'] ) ) {
			foreach ( $s['meta_exclude_keys'] as $key ) {
				$key = sanitize_text_field( (string) $key );
				if ( '' !== $key ) {
					$user[] = $key;
				}
			}
		}
		return array_values( array_unique( array_merge( $this->default_excluded_meta(), $user ) ) );
	}

	/**
	 * Whether a meta key should be scanned or edited.
	 *
	 * @param string $key    Meta key.
	 * @param array  $values Raw meta values.
	 * @return bool
	 */
	private function should_include_meta_key( $key, array $values ) {
		$key = (string) $key;
		if ( in_array( $key, $this->get_meta_exclude_keys(), true ) ) {
			return false;
		}
		if ( isset( $key[0] ) && '_' === $key[0] ) {
			foreach ( $values as $v ) {
				$val = maybe_unserialize( $v );
				if ( $this->meta_value_might_contain_links( $val ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Quick check whether a meta value may hold links (for private keys).
	 *
	 * @param mixed $val Meta value.
	 * @return bool
	 */
	private function meta_value_might_contain_links( $val ) {
		if ( is_string( $val ) ) {
			return false !== strpos( $val, 'href=' )
				|| false !== strpos( $val, 'src=' )
				|| false !== stripos( $val, 'data-url' )
				|| false !== stripos( $val, 'data-href' )
				|| false !== stripos( $val, 'data-src' )
				|| $this->looks_like_link_url( $val )
				|| ( $this->opt( 'scan_meta_plain', true ) && false !== preg_match( '#https?://#i', $val ) )
				|| ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::value_might_contain_dynamic_tags( $val ) );
		}
		if ( is_array( $val ) ) {
			if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::value_might_contain_dynamic_tags( $val ) ) {
				return true;
			}
			if ( isset( $val['url'] ) && is_string( $val['url'] ) && $this->looks_like_link_url( $val['url'] ) ) {
				return true;
			}
			foreach ( $val as $sub ) {
				if ( $this->meta_value_might_contain_links( $sub ) ) {
					return true;
				}
			}
		}
		if ( is_object( $val ) ) {
			foreach ( get_object_vars( $val ) as $sub ) {
				if ( $this->meta_value_might_contain_links( $sub ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a string looks like a stored URL (absolute or site-relative).
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function looks_like_link_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || $this->skip_url( $url ) ) {
			return false;
		}
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return true;
		}
		return (bool) preg_match( '#^(?:https?://|/|\./|\.\./)#i', $url );
	}

	/** @return string[] */
	private function default_excluded_meta() {
		$keys = array( '_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_old_slug', '_wp_old_date', '_wp_attachment_metadata', '_wp_attached_file', '_thumbnail_id', '_wp_page_template', '_yoast_wpseo_content_score', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_yoast_wpseo_title', '_yoast_wpseo_linkdex', '_rank_math_seo_score', '_rank_math_focus_keyword', '_rank_math_internal_links', '_rank_math_internal_linking_processed', '_wpseo_internal_linking' );
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) ) {
			$keys = array_merge( $keys, TSOLIIN_WooCommerce::dedicated_meta_keys() );
		}
		return $keys;
	}

	// -------------------------------------------------------------------------
	// Comments scan
	// -------------------------------------------------------------------------

	/**
	 * Scan approved comments for links (cursor over comment_ID, independent of post scan pages).
	 *
	 * @param int $per_page Max comments per call.
	 * @return int Items found (0 = reached end of queue; cursor reset for next full cycle).
	 */
	public function scan_comments_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_comments' ) ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'comments' ) ) {
			return self::SCAN_LOCK_BUSY;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_comment_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_content, comment_author_url FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_ID > %d AND ( comment_content LIKE %s OR comment_content LIKE %s OR comment_content LIKE %s OR comment_author_url != '' ) ORDER BY comment_ID ASC LIMIT %d",
				$after_id,
				'%href=%',
				'%http://%',
				'%https://%',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $comments ) ) {
			update_option( 'tsoliin_comment_scan_after_id', 0, false );
			$this->release_source_scan_lock( 'comments' );
			return 0;
		}

		// scan_comment() re-fetches each row via get_comment(); prime the comment
		// cache in one query so that lookup hits cache instead of re-querying
		// {$wpdb->comments} once per row (was showing up as a slow per-row
		// "WP_Comment::get_instance()" query in profiling).
		if ( function_exists( '_prime_comment_caches' ) ) {
			_prime_comment_caches( wp_list_pluck( $comments, 'comment_ID' ), false );
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $comments as $comment ) {
			$cid = absint( $comment->comment_ID );
			if ( $cid ) {
				$max_id = max( $max_id, $cid );
			}
			$found += $this->scan_comment( $cid );
		}

		update_option( 'tsoliin_comment_scan_after_id', $max_id, false );
		$this->release_source_scan_lock( 'comments' );
		return $found > 0 ? $found : self::SCAN_BATCH_PROGRESS;
	}

	// -------------------------------------------------------------------------
	// Navigation menus scan
	// -------------------------------------------------------------------------

	/**
	 * Scan nav menu items for custom/external URLs (cursor over nav_menu_item posts).
	 *
	 * @param int $per_page Max menu items per call.
	 * @return int Items found (0 = reached end of queue; cursor reset for next full cycle).
	 */
	public function scan_menus_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_menus', true ) ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'menus' ) ) {
			return self::SCAN_LOCK_BUSY;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_menu_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_menu_scan_after_id', 0, false );
			$this->release_source_scan_lock( 'menus' );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$item_id = absint( $row->ID );
			if ( $item_id ) {
				$max_id = max( $max_id, $item_id );
			}
			if ( $this->scan_nav_menu_item( $item_id ) > 0 ) {
				$found++;
			}
		}

		update_option( 'tsoliin_menu_scan_after_id', $max_id, false );
		$this->release_source_scan_lock( 'menus' );
		return $found > 0 ? $found : self::SCAN_BATCH_PROGRESS;
	}

	/**
	 * Scan one navigation menu item and sync its inspector row(s).
	 *
	 * @param int $item_id nav_menu_item post ID.
	 * @return int Inspector row ID, or 0 when removed / not found.
	 */
	public function scan_nav_menu_item( $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id || ! $this->opt( 'scan_menus', true ) ) {
			if ( $item_id ) {
				$this->db->delete_menu_sources_not_in( $item_id, array() );
			}
			return 0;
		}

		$post = get_post( $item_id );
		if ( ! $post || 'nav_menu_item' !== $post->post_type || 'publish' !== $post->post_status ) {
			$this->db->delete_menu_sources_not_in( $item_id, array() );
			return 0;
		}

		$item = wp_setup_nav_menu_item( $post );
		if ( ! $item || empty( $item->url ) ) {
			$this->db->delete_menu_sources_not_in( $item_id, array() );
			return 0;
		}

		$url = $this->clean_url( trim( (string) $item->url ) );
		if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
			$this->db->delete_menu_sources_not_in( $item_id, array() );
			return 0;
		}

		$post_id = absint( $item->object_id );
		if ( 'post_type' !== $item->type || ! $post_id ) {
			$post_id = $item_id;
		}

		$anchor = sanitize_text_field( (string) $item->title );
		if ( '' === $anchor ) {
			/* translators: %d: navigation menu item ID */
			$anchor = sprintf( __( 'Menu item #%d', 'tso-link-inspector' ), $item_id );
		}

		$sk           = $this->db->sanitize_source_key( 'mi-' . $item_id );
		$allowed_keys = array( $sk );
		$row_id       = $this->db->upsert_link( $post_id, $url, $anchor, 'menu', $sk );
		$this->db->delete_menu_sources_not_in( $item_id, $allowed_keys, $url );

		return is_int( $row_id ) && $row_id > 0 ? $row_id : 0;
	}

	/**
	 * Re-read a menu item from WordPress and refresh its inspector row.
	 *
	 * @param object $link DB row.
	 * @return int Updated row ID, or 0 if removed.
	 */
	public function rescan_menu_link( $link ) {
		if ( ! $link || empty( $link->source_key ) ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		if ( ! preg_match( '/^mi-(\d+)/', (string) $link->source_key, $matches ) ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		$row_id = $this->scan_nav_menu_item( absint( $matches[1] ) );
		if ( $row_id > 0 ) {
			return $row_id;
		}
		$row = $this->find_link_row_after_rescan( $link );
		return $row ? (int) $row->id : 0;
	}

	/**
	 * Scan one approved comment for links.
	 *
	 * @param int $comment_id Comment ID.
	 * @return int Items found.
	 */
	public function scan_comment( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 ) {
			return 0;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment || '1' !== (string) $comment->comment_approved ) {
			$this->db->delete_comment_sources_not_in( 0, $comment_id, array() );
			return 0;
		}

		$cid          = $comment_id;
		$pid          = absint( $comment->comment_post_ID );
		$allowed_keys = array();
		$found        = 0;

		foreach ( $this->extract_comment_links( (string) $comment->comment_content ) as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				continue;
			}
			/* translators: %d: comment ID */
			$anchor         = $item['anchor'] ?: sprintf( __( 'Comment #%d', 'tso-link-inspector' ), $cid );
			$sk             = $this->db->sanitize_source_key( 'c-' . $cid . '-l-' . md5( $url ) );
			$allowed_keys[] = $sk;
			$this->db->upsert_link( $pid, $url, $anchor, 'comment', $sk );
			$found++;
		}

		$author_url = trim( (string) $comment->comment_author_url );
		if ( $author_url && ! $this->skip_url( $author_url ) && ! $this->is_ignored( $author_url ) ) {
			$clean = $this->clean_url( $author_url );
			/* translators: %d: comment ID */
			$anchor         = sprintf( __( 'Comment author #%d', 'tso-link-inspector' ), $cid );
			$sk             = $this->db->sanitize_source_key( 'c-' . $cid . '-author' );
			$allowed_keys[] = $sk;
			$this->db->upsert_link( $pid, $clean, $anchor, 'comment', $sk );
			$found++;
		}

		$this->db->delete_comment_sources_not_in( $pid, $cid, $allowed_keys );
		return $found;
	}

	/**
	 * Re-read a comment from WordPress and refresh its inspector row.
	 *
	 * @param object $link DB row.
	 * @return int Updated row ID, or 0 if removed.
	 */
	public function rescan_comment_link( $link ) {
		if ( ! $link ) {
			return 0;
		}
		$comment_id = TSOLIIN_Support::get_comment_id_from_link_row( $link );
		if ( $comment_id <= 0 ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		$this->scan_comment( $comment_id );
		$row = $this->find_link_row_after_rescan( $link );
		return $row ? (int) $row->id : 0;
	}

	/**
	 * Scan one taxonomy term description for links.
	 *
	 * @param int $term_id Term ID.
	 * @return int Items found.
	 */
	public function scan_term( $term_id ) {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 ) {
			return 0;
		}
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			$this->db->delete_sources_not_in( 'term', 't-' . $term_id . '-', array(), 0 );
			return 0;
		}

		$desc = isset( $term->description ) ? (string) $term->description : '';
		if ( '' === trim( $desc ) ) {
			$this->db->delete_sources_not_in( 'term', 't-' . $term_id . '-', array(), 0 );
			return 0;
		}

		$taxonomy = sanitize_key( (string) $term->taxonomy );
		$name     = sanitize_text_field( (string) $term->name );
		$tax_obj  = get_taxonomy( $taxonomy );
		$tax_lbl  = ( $tax_obj && ! empty( $tax_obj->labels->singular_name ) )
			? (string) $tax_obj->labels->singular_name
			: $taxonomy;
		/* translators: 1: taxonomy label, 2: term name */
		$default_anchor = sprintf( __( '%1$s: %2$s', 'tso-link-inspector' ), $tax_lbl, $name );
		$prefix         = 't-' . $term_id . '-';
		$allowed        = array();
		$found          = 0;

		foreach ( $this->extract_all_url_items( $desc, $desc ) as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				continue;
			}
			$sk        = $this->db->sanitize_source_key( $prefix . md5( $url ) );
			$allowed[] = $sk;
			$anchor    = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : $default_anchor;
			$this->db->upsert_link( 0, $url, $anchor, 'term', $sk );
			$found++;
		}

		$this->db->delete_sources_not_in( 'term', $prefix, $allowed, 0 );
		return $found;
	}

	/**
	 * Re-read a term from WordPress and refresh its inspector row.
	 *
	 * @param object $link DB row.
	 * @return int Updated row ID, or 0 if removed.
	 */
	public function rescan_term_link( $link ) {
		if ( ! $link || empty( $link->source_key ) ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		if ( ! preg_match( '/^t-(\d+)-/', (string) $link->source_key, $matches ) ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		$this->scan_term( absint( $matches[1] ) );
		$row = $this->find_link_row_after_rescan( $link );
		return $row ? (int) $row->id : 0;
	}

	/**
	 * Re-read an FSE template or reusable block and refresh its inspector row.
	 *
	 * @param object $link DB row.
	 * @return int Updated row ID, or 0 if removed.
	 */
	public function rescan_storage_post_link( $link ) {
		if ( ! $link ) {
			return 0;
		}
		$post_id   = (int) $link->post_id;
		$link_type = isset( $link->link_type ) ? sanitize_key( (string) $link->link_type ) : '';
		if ( ! $post_id || ! in_array( $link_type, array( 'template', 'wp_block' ), true ) ) {
			return ! empty( $link->id ) ? (int) $link->id : 0;
		}
		$this->scan_storage_post( $post_id, $link_type );
		$row = $this->find_link_row_after_rescan( $link );
		return $row ? (int) $row->id : 0;
	}

	/**
	 * Resolve the DB row for a link after re-reading its WordPress source.
	 *
	 * @param object $original    Row before rescan.
	 * @param int    $hint_row_id Scanner hint (e.g. menu upsert ID).
	 * @return object|null
	 */
	public function find_link_row_after_rescan( $original, $hint_row_id = 0 ) {
		if ( ! $original ) {
			return null;
		}
		$hint_row_id = absint( $hint_row_id );
		if ( $hint_row_id > 0 ) {
			$row = $this->db->get_link( $hint_row_id );
			if ( $row ) {
				return $row;
			}
		}
		$orig_id = ! empty( $original->id ) ? (int) $original->id : 0;
		if ( $orig_id > 0 ) {
			$row = $this->db->get_link( $orig_id );
			if ( $row ) {
				return $row;
			}
		}

		$type    = isset( $original->link_type ) ? (string) $original->link_type : '';
		$post_id = (int) ( $original->post_id ?? 0 );
		$old_url = isset( $original->link_url ) ? (string) $original->link_url : '';
		$sk      = isset( $original->source_key ) ? (string) $original->source_key : '';

		if ( '' !== $old_url ) {
			$row = $this->db->get_link_by_post_url( $post_id, $old_url, $type, $sk );
			if ( $row ) {
				return $row;
			}
		}

		if ( 'menu' === $type && '' !== $sk ) {
			$row = $this->db->get_link_by_source_key( $sk, 'menu', $post_id );
			if ( $row ) {
				return $row;
			}
		}

		if ( '' !== $sk && class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			$row = $this->db->get_link_by_source_key( $sk, $type, $post_id );
			if ( $row ) {
				return $row;
			}
			// Legacy download keys hashed the URL; resolve by product prefix + URL after key format change.
			if ( $post_id > 0 ) {
				return $this->resolve_row_after_prefix_rescan( $type, 'wc-' . $post_id . '-', $post_id, $old_url, $original );
			}
		}

		if ( '' !== $sk && class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_acf_source_key( $sk ) ) {
			$row = $this->db->get_link_by_source_key( $sk, $type, $post_id );
			if ( $row ) {
				return $row;
			}
			$row = $this->db->get_link_by_source_key( $sk, 'acf', 0 );
			if ( $row ) {
				return $row;
			}
		}

		if ( '' !== $sk && class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			$row = $this->db->get_link_by_source_key( $sk, $type, $post_id );
			if ( $row ) {
				return $row;
			}
		}

		if ( preg_match( '/^(c-\d+-)/', $sk, $matches ) ) {
			return $this->resolve_row_after_prefix_rescan( 'comment', $matches[1], $post_id, $old_url, $original );
		}
		if ( preg_match( '/^(t-\d+-)/', $sk, $matches ) ) {
			return $this->resolve_row_after_prefix_rescan( 'term', $matches[1], 0, $old_url, $original );
		}
		if ( preg_match( '/^(st-\d+-)/', $sk, $matches ) ) {
			return $this->resolve_row_after_prefix_rescan( $type, $matches[1], $post_id, $old_url, $original );
		}
		if ( 0 === strpos( $sk, 'wg-' ) ) {
			$resolved = $this->resolve_widget_from_source_key( $sk );
			if ( $resolved ) {
				$prefix = $this->db->sanitize_source_key( 'wg-' . $resolved['sidebar_id'] . '-' . $resolved['widget_id'] . '-' );
				return $this->resolve_row_after_prefix_rescan( 'widget', $prefix, 0, $old_url, $original );
			}
		}

		if ( $post_id > 0 && in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) ) {
			return $this->resolve_post_content_row_after_rescan( $original );
		}

		return null;
	}

	/**
	 * Whether the stored URL still appears in its WordPress source.
	 *
	 * @param object $link DB row.
	 * @return bool
	 */
	public function is_url_present_in_source( $link ) {
		if ( ! $link || empty( $link->link_url ) ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'plain' === $type ) {
			return ! empty( $link->post_id )
				&& $this->is_url_editable_in_post( (int) $link->post_id, (string) $link->link_url, 'plain' );
		}
		return $this->is_url_editable_in_source( $link );
	}

	/**
	 * Re-read WordPress source storage and return the current DB row for a link.
	 *
	 * @param object $link DB row.
	 * @return object|null Updated row, or null if removed from the source.
	 */
	public function resync_link_from_source( $link ) {
		if ( ! $link ) {
			return null;
		}
		$original  = $link;
		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$post_id   = (int) $link->post_id;
		$hint      = 0;

		switch ( true ) {
			case $post_id > 0 && in_array( $link_type, array( 'link', 'image', 'iframe', 'plain' ), true ):
				$this->scan_post( $post_id, false );
				break;
			case 'widget' === $link_type:
				$hint = $this->rescan_widget_link( $link );
				break;
			case 'menu' === $link_type:
				$hint = $this->rescan_menu_link( $link );
				break;
			case 'comment' === $link_type:
				$hint = $this->rescan_comment_link( $link );
				break;
			case 'term' === $link_type:
				$hint = $this->rescan_term_link( $link );
				break;
			case in_array( $link_type, array( 'template', 'wp_block' ), true ):
				$hint = $this->resync_storage_post_link( $link );
				break;
			case 'acf' === $link_type:
				$this->scan_acf_options();
				break;
		}

		return $this->find_link_row_after_rescan( $original, $hint );
	}

	/**
	 * Pick the best row after a prefix-based rescan (comment, term, widget, FSE).
	 *
	 * @param string $link_type Link type.
	 * @param string $prefix    Source key prefix.
	 * @param int    $post_id   Post ID filter (0 = none).
	 * @param string $old_url   URL before edit.
	 * @param object $original  Original row.
	 * @return object|null
	 */
	private function resolve_row_after_prefix_rescan( $link_type, $prefix, $post_id, $old_url, $original ) {
		$filter_post = in_array( $link_type, array( 'term', 'widget' ), true ) ? 0 : ( $post_id > 0 ? $post_id : null );
		$rows        = $this->db->get_links_by_source_prefix( $link_type, $prefix, $filter_post );
		if ( empty( $rows ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $old_url ) ) {
				return $row;
			}
		}
		if ( 1 === count( $rows ) ) {
			return $rows[0];
		}

		$source_ok = array();
		foreach ( $rows as $row ) {
			if ( $this->is_url_editable_in_source( $row ) ) {
				$source_ok[] = $row;
			}
		}
		if ( 1 === count( $source_ok ) ) {
			return $source_ok[0];
		}

		if ( ! $this->is_url_editable_in_source( $original ) ) {
			$changed = array();
			foreach ( $source_ok as $row ) {
				if ( ! TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $old_url ) ) {
					$changed[] = $row;
				}
			}
			if ( 1 === count( $changed ) ) {
				return $changed[0];
			}
			$old_anchor = isset( $original->anchor_text ) ? (string) $original->anchor_text : '';
			if ( '' !== $old_anchor ) {
				foreach ( $source_ok as $row ) {
					if ( (string) $row->anchor_text === $old_anchor ) {
						return $row;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Pick the best post-content row after the URL changed in the editor.
	 *
	 * @param object $original Original row.
	 * @return object|null
	 */
	private function resolve_post_content_row_after_rescan( $original ) {
		$post_id = (int) $original->post_id;
		$type    = (string) $original->link_type;
		$old_url = (string) $original->link_url;
		$rows    = $this->db->get_links_by_post_and_type( $post_id, $type, '' );
		if ( empty( $rows ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $old_url ) ) {
				return $row;
			}
		}
		if ( $this->is_url_editable_in_post( $post_id, $old_url, $type ) ) {
			return null;
		}

		$candidates = array();
		foreach ( $rows as $row ) {
			if ( $this->is_url_editable_in_post( $post_id, (string) $row->link_url, $type ) ) {
				$candidates[] = $row;
			}
		}
		$old_anchor = isset( $original->anchor_text ) ? (string) $original->anchor_text : '';
		if ( '' !== $old_anchor ) {
			foreach ( $candidates as $row ) {
				if ( (string) $row->anchor_text === $old_anchor ) {
					return $row;
				}
			}
		}
		$changed = array();
		foreach ( $candidates as $row ) {
			if ( ! TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $old_url ) ) {
				$changed[] = $row;
			}
		}
		if ( 1 === count( $changed ) ) {
			return $changed[0];
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Post content manipulation
	// -------------------------------------------------------------------------

	/**
	 * URL strings that may appear in post/comment HTML for a stored link URL.
	 *
	 * Longest variants are tried first so a trailing-slash mismatch does not fall through
	 * to a bare "/" and corrupt closing tags via a whole-content str_replace.
	 *
	 * @param string $old_url Stored link URL.
	 * @param int    $post_id Post ID for relative resolution.
	 * @return string[]
	 */
	private function build_url_replace_candidates( $old_url, $post_id = 0 ) {
		$old_url  = (string) $old_url;
		$post_id  = absint( $post_id );
		$decoded  = urldecode( $old_url );
		$candidates = array_unique( array_filter( array_merge(
			$this->url_content_variants( $old_url, $post_id ),
			array(
				$decoded,
				rawurldecode( $old_url ),
				esc_url_raw( $old_url ),
				esc_url_raw( $decoded ),
				html_entity_decode( $old_url, ENT_QUOTES, 'UTF-8' ),
				str_replace( '&', '&amp;', $old_url ),
				str_replace( '&', '&amp;', $decoded ),
				str_replace( '&amp;', '&', $old_url ),
				untrailingslashit( $old_url ),
				trailingslashit( untrailingslashit( $old_url ) ),
			),
			$this->json_slash_url_variants( $old_url ),
			$this->json_slash_url_variants( $decoded ),
			$this->percent_placeholder_url_variants( $old_url ),
			$this->percent_placeholder_url_variants( $decoded )
		) ) );

		usort(
			$candidates,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);

		return array_values( $candidates );
	}

	/**
	 * URL variants for replace/preview, including exact spellings found in post HTML.
	 *
	 * @param string $old_url Stored URL.
	 * @param int    $post_id Post ID.
	 * @param string $content Raw post content.
	 * @return string[]
	 */
	private function build_url_replace_candidates_for_content( $old_url, $post_id, $content ) {
		$candidates = $this->build_url_replace_candidates( $old_url, $post_id );
		if ( '' !== trim( (string) $content ) ) {
			$candidates = array_merge(
				$candidates,
				$this->get_content_url_spellings_for_stored( (string) $content, (string) $old_url, $post_id )
			);
		}
		$candidates = array_unique( array_filter( $candidates ) );
		usort(
			$candidates,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		return array_values( $candidates );
	}

	/**
	 * WordPress KSES may replace "%" in stored href values with "{sha256}" placeholders.
	 *
	 * @return string
	 */
	private function wp_kses_url_percent_placeholder_hash() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		return hash_hmac( 'sha256', 'url', wp_salt( 'auth' ) );
	}

	/**
	 * Toggle between "%" and "{hash}" percent-encoding placeholders.
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	private function percent_placeholder_url_variants( $url ) {
		$url      = (string) $url;
		$variants = array();
		if ( preg_match_all( '/\{[a-f0-9]{64}\}/i', $url, $matches ) ) {
			$decoded = $url;
			foreach ( array_unique( $matches[0] ) as $token ) {
				$decoded = str_replace( $token, '%', $decoded );
			}
			$variants[] = $decoded;
		}
		$hash = $this->wp_kses_url_percent_placeholder_hash();
		if ( '' !== $hash && false !== strpos( $url, '%' ) ) {
			$variants[] = str_replace( '%', '{' . $hash . '}', $url );
		}
		if ( '' !== $hash && false !== strpos( $url, '{' . $hash . '}' ) ) {
			$variants[] = str_replace( '{' . $hash . '}', '%', $url );
		}
		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * Normalize a URL for fuzzy equivalence checks (placeholders, case, entities).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url_for_matching( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		$url = str_replace( '&amp;', '&', $url );
		$url = preg_replace( '/\{[a-f0-9]{64}\}/i', '%', $url );
		$hash = $this->wp_kses_url_percent_placeholder_hash();
		if ( '' !== $hash ) {
			$url = str_replace( '{' . $hash . '}', '%', $url );
		}
		return strtolower( $url );
	}

	/**
	 * Whether an attribute value in post content refers to the stored URL.
	 *
	 * @param string $stored     URL from the DB.
	 * @param string $attr_value href/src spelling in content.
	 * @param int    $post_id    Post ID.
	 * @return bool
	 */
	private function url_attribute_matches_stored( $stored, $attr_value, $post_id = 0 ) {
		$stored     = (string) $stored;
		$attr_value = (string) $attr_value;
		if ( '' === $stored || '' === $attr_value ) {
			return false;
		}
		if ( $this->urls_are_same_resource( $stored, $attr_value, $post_id ) ) {
			return true;
		}
		foreach ( $this->build_url_replace_candidates( $stored, $post_id ) as $variant ) {
			if ( 0 === strcasecmp( (string) $variant, $attr_value ) ) {
				return true;
			}
		}
		$norm_stored = $this->normalize_url_for_matching( $stored );
		$norm_attr   = $this->normalize_url_for_matching( $attr_value );
		return $this->stored_url_looks_truncated_in_attr( $norm_stored, $norm_attr );
	}

	/**
	 * Truncated DB href: content may continue the same path token, not a new segment.
	 *
	 * `/software-download/` must not match `/software-download/windows8`.
	 *
	 * @param string $norm_stored Normalized stored URL.
	 * @param string $norm_attr   Normalized attribute value.
	 * @return bool
	 */
	private function stored_url_looks_truncated_in_attr( $norm_stored, $norm_attr ) {
		$min = 32;
		if ( strlen( $norm_stored ) < $min || 0 !== strpos( $norm_attr, $norm_stored ) ) {
			return false;
		}
		if ( $norm_stored === $norm_attr ) {
			return true;
		}
		if ( '/' === substr( $norm_stored, -1 ) ) {
			return false;
		}
		$rest = substr( $norm_attr, strlen( $norm_stored ) );
		if ( '' === $rest ) {
			return true;
		}
		return ! in_array( $rest[0], array( '/', '?', '#' ), true );
	}

	/**
	 * Strip #fragment from a URL for matching.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_without_fragment( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$hash = strpos( $url, '#' );
		if ( false === $hash ) {
			return $url;
		}
		return substr( $url, 0, $hash );
	}

	/**
	 * Attribute names scanned when locating editable URLs in HTML.
	 *
	 * @return string[]
	 */
	private function get_editable_url_attributes() {
		return array( 'href', 'src', 'data-url', 'data-href', 'data-link', 'data-button-url', 'data-bg-url', 'data-src' );
	}

	/**
	 * Load post HTML into DOMDocument (shared helper).
	 *
	 * @param string $html HTML fragment.
	 * @return DOMDocument|null
	 */
	private function load_dom_for_html( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return null;
		}
		$wrapped = '<html><head><meta charset="UTF-8"></head><body>' . (string) $html . '</body></html>';
		$dom     = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		if ( ! $dom->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOWARNING ) ) {
			libxml_clear_errors();
			return null;
		}
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Exact href/src spellings in raw content that match a stored (possibly truncated) URL.
	 *
	 * @param string $content    post_content.
	 * @param string $stored_url URL from DB.
	 * @param int    $post_id    Post ID.
	 * @return string[]
	 */
	private function get_content_url_spellings_for_stored( $content, $stored_url, $post_id = 0 ) {
		$content    = (string) $content;
		$stored_url = (string) $stored_url;
		if ( '' === $content || '' === $stored_url ) {
			return array();
		}

		$spellings = array();
		$dom       = $this->load_dom_for_html( $content );
		if ( $dom && $dom->documentElement ) {
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( $body ) {
				$walk = function ( DOMNode $node ) use ( &$walk, &$spellings, $stored_url, $post_id ) {
					if ( XML_ELEMENT_NODE === $node->nodeType && $node instanceof DOMElement ) {
						foreach ( $this->get_editable_url_attributes() as $attr ) {
							if ( ! $node->hasAttribute( $attr ) ) {
								continue;
							}
							$val = trim( (string) $node->getAttribute( $attr ) );
							if ( '' !== $val && $this->url_attribute_matches_stored( $stored_url, $val, $post_id ) ) {
								$spellings[] = $val;
							}
						}
						if ( $node->hasAttribute( 'srcset' ) ) {
							foreach ( preg_split( '/\s*,\s*/', (string) $node->getAttribute( 'srcset' ) ) as $part ) {
								$chunks = preg_split( '/\s+/', trim( (string) $part ), 2 );
								$val    = isset( $chunks[0] ) ? trim( (string) $chunks[0] ) : '';
								if ( '' !== $val && $this->url_attribute_matches_stored( $stored_url, $val, $post_id ) ) {
									$spellings[] = $val;
								}
							}
						}
					}
					if ( $node->hasChildNodes() ) {
						foreach ( $node->childNodes as $child ) {
							$walk( $child );
						}
					}
				};
				$walk( $body );
			}
		}

		foreach ( $this->build_url_replace_candidates( $stored_url, $post_id ) as $variant ) {
			if ( '' !== $variant && TSOLIIN_HTTP::content_contains_complete_url( $content, (string) $variant ) ) {
				$spellings[] = (string) $variant;
			}
		}

		$spellings = array_values( array_unique( array_filter( $spellings ) ) );
		$expanded  = array();
		foreach ( $spellings as $spelling ) {
			$expanded[] = $spelling;
			if ( false !== strpos( $spelling, '&' ) && false === stripos( $spelling, '&amp;' ) ) {
				$expanded[] = str_replace( '&', '&amp;', $spelling );
			}
			if ( false !== stripos( $spelling, '&amp;' ) ) {
				$expanded[] = str_replace( '&amp;', '&', $spelling );
			}
		}
		$spellings = array_values( array_unique( array_filter( $expanded ) ) );
		usort(
			$spellings,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		return $spellings;
	}

	/**
	 * Extract the opening HTML tag around an attribute value from raw content.
	 *
	 * @param string $content    Raw HTML.
	 * @param string $attr       Attribute name.
	 * @param string $attr_value Exact attribute value.
	 * @return string
	 */
	private function extract_tag_snippet_for_attr_value( $content, $attr, $attr_value ) {
		$content    = (string) $content;
		$attr       = (string) $attr;
		$attr_value = (string) $attr_value;
		if ( '' === $content || '' === $attr || '' === $attr_value ) {
			return '';
		}
		foreach ( array( '"', "'" ) as $quote ) {
			$needle = $attr . '=' . $quote . $attr_value . $quote;
			$pos    = stripos( $content, $needle );
			if ( false === $pos ) {
				continue;
			}
			$start = strrpos( substr( $content, 0, $pos ), '<' );
			$end   = strpos( $content, '>', $pos );
			if ( false !== $start && false !== $end ) {
				return substr( $content, $start, $end - $start + 1 );
			}
		}
		return '';
	}

	/**
	 * Gutenberg block JSON often stores URLs with escaped slashes.
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	private function json_slash_url_variants( $url ) {
		$url = (string) $url;
		if ( '' === $url || false === strpos( $url, '/' ) ) {
			return array();
		}
		return array_unique(
			array_filter(
				array(
					str_replace( '/', '\/', $url ),
					str_replace( '/', '\\/', $url ),
				)
			)
		);
	}

	/**
	 * Whether a URL exists in editable post storage (content or custom fields).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @return bool
	 */
	public function is_url_editable_in_post( $post_id, $url, $link_type = 'link' ) {
		$post_id = absint( $post_id );
		$url     = (string) $url;
		if ( $post_id <= 0 || '' === $url ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return false;
		}
		if ( $this->url_in_post_body_content( $post->post_content, $url, $link_type, $post_id ) ) {
			return true;
		}
		return $this->locate_url_in_post_meta( $post_id, $url )['found'];
	}

	/**
	 * Whether a URL appears in post_content (HTML, gallery shortcode, or media blocks) — not meta.
	 *
	 * Used for editor deep-link focus: meta-only URLs cannot be scrolled to in the content editor.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @return bool
	 */
	public function is_url_in_post_body( $post_id, $url, $link_type = 'link' ) {
		$post_id = absint( $post_id );
		$url     = (string) $url;
		if ( $post_id <= 0 || '' === $url ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return false;
		}
		return $this->url_in_post_body_content( $post->post_content, $url, $link_type, $post_id );
	}

	/**
	 * Whether a URL is visible plain text in post_content (Classic Editor Visual can highlight it).
	 *
	 * @param string $content Raw post_content.
	 * @param string $url     Target URL.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	public function url_is_visible_plain_text_in_post_content( $content, $url, $post_id = 0 ) {
		return $this->url_is_plain_text_only_in_content( (string) $content, (string) $url, absint( $post_id ) );
	}

	/**
	 * Exact [gallery] shortcode substring in post_content for editor focus (Classic Editor).
	 *
	 * @param string $content       Raw post_content.
	 * @param int    $attachment_id Attachment post ID.
	 * @param int    $post_id       Parent post ID.
	 * @return string Full shortcode when the attachment is listed in ids/include.
	 */
	public function get_classic_gallery_focus_needle( $content, $attachment_id, $post_id = 0 ) {
		$context = $this->get_classic_gallery_focus_context( $content, $attachment_id, $post_id );
		return isset( $context['needle'] ) ? (string) $context['needle'] : '';
	}

	/**
	 * Shortcode needle and attachment IDs for one Classic Editor gallery block.
	 *
	 * @param string $content       Raw post_content.
	 * @param int    $attachment_id Attachment post ID.
	 * @param int    $post_id       Parent post ID.
	 * @return array{needle:string,ids:int[],index:int}
	 */
	public function get_classic_gallery_focus_context( $content, $attachment_id, $post_id = 0 ) {
		$content       = (string) $content;
		$attachment_id = absint( $attachment_id );
		$post_id       = absint( $post_id );
		$empty         = array(
			'needle' => '',
			'ids'    => array(),
			'index'  => -1,
		);
		if ( '' === $content || $attachment_id <= 0 ) {
			return $empty;
		}
		if ( false !== stripos( $content, '[gallery' ) ) {
			if ( preg_match_all( '/\[gallery\b[^\]]*\]/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$gallery_index = 0;
				foreach ( $matches[0] as $match ) {
					$shortcode = (string) $match[0];
					$attr_string = '';
					if ( preg_match( '/\[gallery\b([^\]]*)\]/i', $shortcode, $parts ) ) {
						$attr_string = (string) $parts[1];
					}
					$atts = shortcode_parse_atts( 'gallery ' . trim( $attr_string ) );
					if ( ! is_array( $atts ) ) {
						$atts = array();
					}
					$ids = $this->get_gallery_shortcode_attachment_ids( $atts, $post_id );
					if ( in_array( $attachment_id, $ids, true ) ) {
						return array(
							'needle' => $shortcode,
							'ids'    => $ids,
							'index'  => $gallery_index,
						);
					}
					++$gallery_index;
				}
			}
		}
		$html_context = $this->get_classic_html_gallery_focus_context( $content, $attachment_id );
		if ( ! empty( $html_context['needle'] ) ) {
			return $html_context;
		}
		return $empty;
	}

	/**
	 * Focus context for Classic Editor galleries saved as HTML (no [gallery] shortcode).
	 *
	 * @param string $content       Raw post_content.
	 * @param int    $attachment_id Attachment post ID.
	 * @return array{needle:string,ids:int[],index:int}
	 */
	private function get_classic_html_gallery_focus_context( $content, $attachment_id ) {
		$empty = array(
			'needle' => '',
			'ids'    => array(),
			'index'  => -1,
		);
		$content       = (string) $content;
		$attachment_id = absint( $attachment_id );
		if ( '' === $content || $attachment_id <= 0 || false === stripos( $content, 'gallery' ) ) {
			return $empty;
		}
		$token = 'wp-image-' . $attachment_id;
		if ( false === stripos( $content, $token ) ) {
			return $empty;
		}
		if ( ! preg_match_all( '#<div[^>]*class="[^"]*\bgallery\b[^"]*"[^>]*>#i', $content, $divs, PREG_OFFSET_CAPTURE ) ) {
			return $empty;
		}
		$gallery_index = 0;
		foreach ( $divs[0] as $div_match ) {
			$start = (int) $div_match[1];
			$chunk = substr( $content, $start, 15000 );
			if ( false === stripos( $chunk, $token ) ) {
				++$gallery_index;
				continue;
			}
			$ids = array();
			if ( preg_match_all( '/\bwp-image-(\d+)\b/i', $chunk, $id_matches ) ) {
				$ids = array_values( array_unique( array_map( 'absint', $id_matches[1] ) ) );
			}
			if ( empty( $ids ) ) {
				$ids = array( $attachment_id );
			}
			$needle = $token;
			if ( preg_match( '#<img[^>]*\bclass="[^"]*\b' . preg_quote( $token, '#' ) . '\b[^"]*"[^>]*>#i', $chunk, $img_match ) ) {
				$needle = (string) $img_match[0];
			}
			return array(
				'needle' => $needle,
				'ids'    => $ids,
				'index'  => $gallery_index,
			);
		}
		return $empty;
	}

	/**
	 * @param string $content   Raw post_content.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @param int    $post_id   Post ID.
	 * @return bool
	 */
	private function url_in_post_body_content( $content, $url, $link_type, $post_id ) {
		$content = (string) $content;
		$url     = (string) $url;
		if ( '' === $content || '' === $url ) {
			return false;
		}
		// Images must be real markup (img/gallery/block), not a bare URL substring elsewhere in the post
		// (that caused ghost rows + Unlink failures when no <img> existed).
		if ( 'image' === (string) $link_type ) {
			if ( '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, 'src' ) ) {
				return true;
			}
			if ( $this->url_in_classic_gallery_shortcode( $content, $url, $post_id ) ) {
				return true;
			}
			return $this->url_in_gutenberg_media_block( $content, $url, $post_id );
		}
		if ( $this->url_located_in_string( $content, $url, $post_id ) ) {
			return true;
		}
		return $this->url_in_gutenberg_media_block( $content, $url, $post_id );
	}

	/**
	 * Whether the post contains markup Unlink can remove (img / a / iframe / gallery ids).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @return bool
	 */
	public function post_has_unlinkable_markup( $post_id, $url, $link_type = 'link' ) {
		$post_id   = absint( $post_id );
		$url       = (string) $url;
		$link_type = sanitize_key( (string) $link_type );
		if ( $post_id <= 0 || '' === $url ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return false;
		}
		$content = (string) $post->post_content;
		if ( 'image' === $link_type ) {
			if ( '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, 'src' ) ) {
				return true;
			}
			$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( $url );
			if ( $attachment_id > 0 && preg_match(
				'#<img\b[^>]*\bclass=(["\'])[^"\']*\bwp-image-' . preg_quote( (string) $attachment_id, '#' ) . '\b[^"\']*\1#i',
				$content
			) ) {
				return true;
			}
			return $this->url_in_classic_gallery_shortcode( $content, $url, $post_id )
				|| $this->url_in_gutenberg_media_block( $content, $url, $post_id );
		}
		if ( 'iframe' === $link_type ) {
			return '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, 'src' );
		}
		return '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, 'href' )
			|| '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, 'src' );
	}

	/**
	 * Whether this DB row can be changed with the inline editor.
	 *
	 * @param object $link Link row.
	 * @return bool
	 */
	public function is_url_editable_in_source( $link ) {
		if ( ! $link || empty( $link->link_url ) ) {
			return false;
		}
		$cache_key = ! empty( $link->id )
			? 'id:' . absint( $link->id )
			: 'row:' . md5(
				( isset( $link->link_type ) ? (string) $link->link_type : '' ) . '|'
				. (string) $link->link_url . '|'
				. ( isset( $link->source_key ) ? (string) $link->source_key : '' ) . '|'
				. absint( $link->post_id ?? 0 )
			);
		if ( array_key_exists( $cache_key, $this->editable_source_cache ) ) {
			return $this->editable_source_cache[ $cache_key ];
		}

		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'plain' === $type ) {
			$this->editable_source_cache[ $cache_key ] = false;
			return false;
		}
		$url = (string) $link->link_url;
		$sk  = isset( $link->source_key ) ? (string) $link->source_key : '';

		if ( 'comment' === $type ) {
			$comment_id = $this->comment_id_from_source_key( $sk, (int) $link->post_id );
			if ( $comment_id <= 0 ) {
				$this->editable_source_cache[ $cache_key ] = false;
				return false;
			}
			$comment = get_comment( $comment_id );
			$result  = (bool) ( $comment && $this->url_located_in_string( (string) $comment->comment_content, $url, (int) $link->post_id ) );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( 'widget' === $type ) {
			$content = $this->get_widget_content_by_source_key( $sk );
			$result  = ( '' !== $content && $this->url_located_in_string( $content, $url, 0 ) );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( 'menu' === $type && preg_match( '/^mi-(\d+)/', $sk, $m ) ) {
			$item_url = get_post_meta( absint( $m[1] ), '_menu_item_url', true );
			$result   = is_string( $item_url ) && '' !== $item_url && $this->urls_match_for_edit( $item_url, $url );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( 'term' === $type && preg_match( '/^t-(\d+)-/', $sk, $m ) ) {
			$term   = get_term( absint( $m[1] ) );
			$result = (bool) ( $term && ! is_wp_error( $term ) && $this->url_located_in_string( (string) $term->description, $url, 0 ) );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) && ! empty( $link->post_id ) ) {
			$result = TSOLIIN_WooCommerce::product_has_url( (int) $link->post_id, $url );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_acf_source_key( $sk ) ) {
			$result = TSOLIIN_Acf::source_has_url( $link );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			$result = TSOLIIN_Elementor::source_has_url( $link );
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		if ( ! empty( $link->post_id ) ) {
			$result = $this->is_url_editable_in_post( (int) $link->post_id, $url, $type );
			// Content may already show the redirect destination (e.g. after a manual edit or Apply).
			if ( ! $result && ! empty( $link->redirect_url ) ) {
				$redir = trim( (string) $link->redirect_url );
				if ( '' !== $redir && $redir !== $url ) {
					$result = $this->is_url_editable_in_post( (int) $link->post_id, $redir, $type );
				}
			}
			$this->editable_source_cache[ $cache_key ] = $result;
			return $result;
		}
		$this->editable_source_cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * @param string $stored Stored value.
	 * @param string $needle URL to find.
	 * @return bool
	 */
	private function urls_match_for_edit( $stored, $needle ) {
		$stored = $this->clean_url( (string) $stored );
		$needle = $this->clean_url( (string) $needle );
		if ( '' === $stored || '' === $needle ) {
			return false;
		}
		if ( $stored === $needle ) {
			return true;
		}
		foreach ( $this->build_url_replace_candidates( $needle, 0 ) as $variant ) {
			if ( $stored === $variant ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $content HTML or block markup.
	 * @param string $url     Stored URL.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	private function url_located_in_string( $content, $url, $post_id = 0 ) {
		$content = (string) $content;
		$url     = (string) $url;
		if ( '' === $content || '' === $url ) {
			return false;
		}
		foreach ( array( 'href', 'src' ) as $attr ) {
			if ( '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, $attr ) ) {
				return true;
			}
		}
		$data_attrs = array( 'data-url', 'data-href', 'data-link', 'data-button-url', 'data-bg-url', 'data-src' );
		foreach ( $data_attrs as $attr ) {
			if ( '' !== $this->extract_matching_tag_snippet( $content, $url, $post_id, $attr ) ) {
				return true;
			}
		}
		return '' !== $this->extract_url_context_snippet( $content, $url, $post_id );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $url     Stored URL.
	 * @return array{ found: bool, field_key: string, snippet: string }
	 */
	private function locate_url_in_post_meta( $post_id, $url ) {
		$post_id = absint( $post_id );
		foreach ( $this->get_scannable_meta_entries( $post_id ) as $entry ) {
			$located = $this->locate_url_in_meta_value( $entry['value'], $url, $post_id );
			if ( ! $located['found'] ) {
				continue;
			}
			return array(
				'found'     => true,
				'field_key' => (string) $entry['key'],
				'snippet'   => $located['snippet'],
			);
		}
		return array(
			'found'     => false,
			'field_key' => '',
			'snippet'   => '',
		);
	}

	/**
	 * @param mixed  $value   Meta value.
	 * @param string $url     Stored URL.
	 * @param int    $post_id Post ID.
	 * @return array{ found: bool, snippet: string }
	 */
	private function locate_url_in_meta_value( $value, $url, $post_id ) {
		if ( is_string( $value ) ) {
			$snippet = $this->extract_url_context_snippet( $value, $url, $post_id );
			if ( '' !== $snippet || $this->url_located_in_string( $value, $url, $post_id ) ) {
				if ( '' === $snippet ) {
					$snippet = substr( $value, 0, 240 );
				}
				return array(
					'found'   => true,
					'snippet' => $snippet,
				);
			}
			return array(
				'found'   => false,
				'snippet' => '',
			);
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) && is_string( $value['url'] ) && $this->urls_match_for_edit( (string) $value['url'], $url ) ) {
				$title = ! empty( $value['title'] ) ? (string) $value['title'] : (string) $value['url'];
				return array(
					'found'   => true,
					'snippet' => substr( $title, 0, 240 ),
				);
			}
			foreach ( $value as $sub ) {
				$located = $this->locate_url_in_meta_value( $sub, $url, $post_id );
				if ( $located['found'] ) {
					return $located;
				}
			}
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $sub ) {
				$located = $this->locate_url_in_meta_value( $sub, $url, $post_id );
				if ( $located['found'] ) {
					return $located;
				}
			}
		}
		return array(
			'found'   => false,
			'snippet' => '',
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<int, array{ key: string, value: mixed }>
	 */
	private function get_scannable_meta_entries( $post_id ) {
		$all_meta = get_post_meta( absint( $post_id ) );
		if ( empty( $all_meta ) ) {
			return array();
		}
		$entries = array();
		foreach ( $all_meta as $key => $values ) {
			if ( ! $this->should_include_meta_key( (string) $key, (array) $values ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$entries[] = array(
					'key'   => (string) $key,
					'value' => maybe_unserialize( $value ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Replace a URL only inside href/src attributes (never a raw str_replace on HTML).
	 *
	 * @param string $content HTML content.
	 * @param string $old_url Stored link URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID.
	 * @return string|null Updated content, or null when no attribute matched.
	 */
	private function replace_url_in_html_attributes( $content, $old_url, $new_url, $post_id = 0 ) {
		$new_url    = $this->clean_url( (string) $new_url );
		$candidates = $this->build_url_replace_candidates_for_content( $old_url, $post_id, $content );
		$changed    = false;

		foreach ( $candidates as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$quoted      = preg_quote( (string) $v, '#' );
			$escaped_new = esc_attr( $new_url );
			$patterns    = array(
				'#(href=(["\']))' . $quoted . '(\2)#i',
				'#(src=(["\']))' . $quoted . '(\2)#i',
				'#(data-(?:url|href|src|link|button-url|bg-url)=(["\']))' . $quoted . '(\2)#i',
			);

			foreach ( $patterns as $pattern ) {
				$count = 0;
				$next  = preg_replace_callback(
					$pattern,
					static function ( $m ) use ( $escaped_new ) {
						return $m[1] . $escaped_new . $m[3];
					},
					$content,
					-1,
					$count
				);
				if ( $count > 0 && is_string( $next ) && $next !== $content ) {
					$content = $next;
					$changed = true;
				}
			}

			$srcset_pattern = '#(\ssrcset=(["\']))((?:(?!\2).)+)(\2)#is';
			$count          = 0;
			$next           = preg_replace_callback(
				$srcset_pattern,
				static function ( $m ) use ( $v, $escaped_new ) {
					if ( ! TSOLIIN_HTTP::content_contains_complete_url( $m[3], (string) $v ) ) {
						return $m[0];
					}
					$new_srcset = TSOLIIN_HTTP::replace_complete_url_occurrences( $m[3], (string) $v, $escaped_new, -1 );
					if ( $new_srcset === $m[3] ) {
						return $m[0];
					}
					return $m[1] . $new_srcset . $m[4];
				},
				$content,
				-1,
				$count
			);
			if ( $count > 0 && is_string( $next ) && $next !== $content ) {
				$content = $next;
				$changed = true;
			}
		}

		return $changed ? $content : null;
	}

	/**
	 * Replace a bare URL string in text (comments often store links without <a> tags).
	 *
	 * @param string $content HTML or plain text.
	 * @param string $old_url Stored link URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID for variant resolution.
	 * @return string|null Updated content, or null when no match.
	 */
	private function replace_plain_url_in_text( $content, $old_url, $new_url, $post_id = 0 ) {
		$content = (string) $content;
		$new_url = $this->clean_url( (string) $new_url );
		// Allow empty $new_url when clearing a meta-only / plain URL (unlink).
		if ( '' === $content ) {
			return null;
		}

		foreach ( $this->build_url_replace_candidates_for_content( $old_url, $post_id, $content ) as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$result = $this->replace_variant_safely( $content, $variant, $new_url );
			if ( $result['changed'] ) {
				return $result['content'];
			}
		}

		return null;
	}

	/**
	 * Whether a replace candidate is a site-relative path (not protocol-relative).
	 *
	 * @param string $variant URL variant.
	 * @return bool
	 */
	private function is_relative_path_variant( $variant ) {
		return is_string( $variant )
			&& strlen( $variant ) > 1
			&& '/' === $variant[0]
			&& '/' !== $variant[1];
	}

	/**
	 * Replace one URL variant without patching a relative path inside an absolute URL.
	 *
	 * @param string $content Content or snippet.
	 * @param string $variant Stored URL variant.
	 * @param string $new_url Replacement URL.
	 * @param int    $limit   Max replacements (1 for snippets).
	 * @return array{ changed: bool, content: string }
	 */
	private function replace_variant_safely( $content, $variant, $new_url, $limit = 1 ) {
		$content = (string) $content;
		$variant = (string) $variant;
		$new_url = (string) $new_url;
		if ( '' === $variant || false === strpos( $content, $variant ) ) {
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		if ( $this->is_relative_path_variant( $variant ) ) {
			$quoted  = preg_quote( $variant, '#' );
			$escaped = esc_attr( $new_url );
			$patterns = array(
				'#(href=(["\']))' . $quoted . '(\2)#i',
				'#(src=(["\']))' . $quoted . '(\2)#i',
				'#"(url|link|href|src)"\s*:\s*"' . preg_quote( $variant, '#' ) . '"#i',
			);
			foreach ( $patterns as $index => $pattern ) {
				$count = 0;
				if ( 2 === $index ) {
					$next = preg_replace( $pattern, '"$1":"' . $escaped . '"', $content, $limit, $count );
				} else {
					$next = preg_replace( $pattern, '$1' . $escaped . '$3', $content, $limit, $count );
				}
				if ( $count > 0 && is_string( $next ) ) {
					return array(
						'changed' => true,
						'content' => $next,
					);
				}
			}
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		$next = TSOLIIN_HTTP::replace_complete_url_occurrences( $content, $variant, $new_url, $limit );
		return array(
			'changed' => ( $next !== $content ),
			'content' => $next,
		);
	}

	/**
	 * Replace a URL in post content.
	 * Tries multiple URL encoding variants to handle accented chars, {}, etc.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url Old URL as stored in DB.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	public function replace_url_in_post( $post_id, $old_url, $new_url ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content     = (string) $post->post_content;
		$new_content = $this->replace_url_in_html_attributes(
			$content,
			(string) $old_url,
			(string) $new_url,
			$post_id
		);
		if ( null === $new_content ) {
			$new_content = $this->replace_plain_url_in_text( $content, (string) $old_url, (string) $new_url, $post_id );
		}
		if ( null === $new_content ) {
			$new_content = $this->replace_url_in_parsed_blocks( $content, (string) $old_url, (string) $new_url, $post_id );
		}
		if ( null === $new_content ) {
			$new_content = $this->replace_url_in_classic_gallery_shortcodes( $content, (string) $old_url, (string) $new_url, $post_id );
		}
		if ( null !== $new_content ) {
			$this->maybe_create_post_revision( $post_id );
			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			return true;
		}
		if ( $this->replace_url_in_post_meta( $post_id, (string) $old_url, (string) $new_url ) ) {
			$this->purge_cache( $post_id );
			return true;
		}
		// Target already present (content was updated manually or via a previous apply).
		if ( $this->is_url_editable_in_post( $post_id, (string) $new_url, 'link' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Replace a URL inside post meta values (ACF, custom fields).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url Stored URL.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	public function replace_url_in_post_meta( $post_id, $old_url, $new_url ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		$changed_any = false;
		$grouped     = array();
		foreach ( $this->get_scannable_meta_entries( $post_id ) as $entry ) {
			$key = (string) $entry['key'];
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $entry['value'];
		}
		foreach ( $grouped as $key => $values ) {
			$updated     = array();
			$key_changed = false;
			foreach ( $values as $value ) {
				$new_value = $this->replace_urls_in_meta_value( $value, (string) $old_url, (string) $new_url, $post_id );
				if ( null !== $new_value ) {
					$updated[]   = $new_value;
					$key_changed = true;
				} else {
					$updated[] = $value;
				}
			}
			if ( ! $key_changed ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $updated as $row ) {
				add_post_meta( $post_id, $key, $row );
			}
			$changed_any = true;
		}
		return $changed_any;
	}

	/**
	 * @param mixed  $val     Meta value.
	 * @param string $old_url Stored URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID.
	 * @return mixed|null Updated value or null when unchanged.
	 */
	private function replace_urls_in_meta_value( $val, $old_url, $new_url, $post_id ) {
		if ( is_string( $val ) ) {
			$next = $this->replace_url_in_html_attributes( $val, $old_url, $new_url, $post_id );
			if ( null === $next ) {
				$next = $this->replace_plain_url_in_text( $val, $old_url, $new_url, $post_id );
			}
			return ( null !== $next && $next !== $val ) ? $next : null;
		}
		if ( is_array( $val ) ) {
			$changed = false;
			foreach ( $val as $k => $sub ) {
				$next = $this->replace_urls_in_meta_value( $sub, $old_url, $new_url, $post_id );
				if ( null !== $next ) {
					$val[ $k ] = $next;
					$changed   = true;
				}
			}
			return $changed ? $val : null;
		}
		if ( is_object( $val ) ) {
			$changed = false;
			foreach ( get_object_vars( $val ) as $k => $sub ) {
				$next = $this->replace_urls_in_meta_value( $sub, $old_url, $new_url, $post_id );
				if ( null !== $next ) {
					$val->$k = $next;
					$changed = true;
				}
			}
			return $changed ? $val : null;
		}
		return null;
	}

	/**
	 * Preview the HTML tag snippet that would change when replacing a URL.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $old_url   Stored URL.
	 * @param string $new_url   Proposed URL.
	 * @param string $link_type link|image|iframe.
	 * @return array{found:bool,before:string,after:string}
	 */
	public function preview_url_change_in_post( $post_id, $old_url, $new_url, $link_type = 'link' ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return array(
				'found'  => false,
				'before' => '',
				'after'  => '',
			);
		}

		$content      = $post->post_content;
		$attr         = in_array( (string) $link_type, array( 'image', 'iframe' ), true ) ? 'src' : 'href';
		$matched_attr = $attr;
		$before       = $this->extract_matching_tag_snippet( $content, (string) $old_url, $post_id, $attr, $matched_attr );
		if ( '' !== $before ) {
			$attr = $matched_attr;
		} elseif ( 'href' === $attr ) {
			$before = $this->extract_matching_tag_snippet( $content, (string) $old_url, $post_id, 'src', $matched_attr );
			if ( '' !== $before ) {
				$attr = $matched_attr;
			}
		} else {
			$before = $this->extract_matching_tag_snippet( $content, (string) $old_url, $post_id, 'href', $matched_attr );
			if ( '' !== $before ) {
				$attr = $matched_attr;
			}
		}
		if ( '' === $before ) {
			foreach ( array( 'data-url', 'data-href', 'data-link', 'data-button-url', 'data-bg-url', 'data-src' ) as $data_attr ) {
				$before = $this->extract_matching_tag_snippet( $content, (string) $old_url, $post_id, $data_attr, $matched_attr );
				if ( '' !== $before ) {
					$attr = $matched_attr;
					break;
				}
			}
		}
		if ( '' === $before ) {
			$before = $this->extract_url_context_snippet( $content, (string) $old_url, $post_id );
		}
		if ( '' === $before ) {
			$meta = $this->locate_url_in_post_meta( $post_id, (string) $old_url );
			if ( ! empty( $meta['found'] ) ) {
				$snippet = (string) $meta['snippet'];
				$field   = isset( $meta['field_key'] ) ? (string) $meta['field_key'] : '';
				if ( '' !== $field ) {
					/* translators: %s: post meta field key */
					$before = sprintf( __( 'Meta field “%s”: ', 'tso-link-inspector' ), $field ) . $snippet;
				} else {
					$before = $snippet;
				}
			}
		}
		if ( '' === $before && 'image' === (string) $link_type ) {
			$before = $this->extract_gallery_shortcode_snippet_for_url( $content, (string) $old_url, $post_id );
		}
		if ( '' === $before ) {
			return array(
				'found'  => false,
				'before' => '',
				'after'  => '',
			);
		}

		$after = $this->replace_url_in_tag_snippet( $before, (string) $old_url, (string) $new_url, $post_id, $attr );
		if ( $after === $before ) {
			// Tag attr may have been found via a different attribute than $attr, or the
			// snippet is plain text / meta — always try a text-level replace.
			$text_after = $this->replace_url_in_text_snippet( $before, (string) $old_url, (string) $new_url, $post_id );
			if ( $text_after !== $before ) {
				$after = $text_after;
			}
		}
		return array(
			'found'  => true,
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * @param string $content HTML.
	 * @param string $old_url Stored URL.
	 * @param int    $post_id Post ID.
	 * @param string $attr    Preferred attribute (href|src|data-*).
	 * @param string $matched_attr Out: attribute that actually matched (optional).
	 * @return string
	 */
	private function extract_matching_tag_snippet( $content, $old_url, $post_id, $attr, &$matched_attr = null ) {
		$allowed = $this->get_editable_url_attributes();
		if ( ! in_array( $attr, $allowed, true ) ) {
			$attr = 'href';
		}
		$content      = (string) $content;
		$matched_attr = $attr;

		foreach ( $this->get_content_url_spellings_for_stored( $content, (string) $old_url, $post_id ) as $spelling ) {
			$snippet = $this->extract_tag_snippet_for_attr_value( $content, $attr, $spelling );
			if ( '' !== $snippet ) {
				$matched_attr = $attr;
				return $snippet;
			}
			foreach ( $allowed as $fallback_attr ) {
				if ( $fallback_attr === $attr ) {
					continue;
				}
				$snippet = $this->extract_tag_snippet_for_attr_value( $content, $fallback_attr, $spelling );
				if ( '' !== $snippet ) {
					$matched_attr = $fallback_attr;
					return $snippet;
				}
			}
		}

		foreach ( $this->build_url_replace_candidates_for_content( $old_url, $post_id, $content ) as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$pattern = '#(<[^>\s]+[^>]*\s' . preg_quote( $attr, '#' ) . '=(["\'])' . preg_quote( (string) $variant, '#' ) . '\2[^>]*)>#i';
			if ( preg_match( $pattern, $content, $matches ) ) {
				$matched_attr = $attr;
				return (string) $matches[1];
			}
			foreach ( $allowed as $fallback_attr ) {
				if ( $fallback_attr === $attr ) {
					continue;
				}
				$pattern = '#(<[^>\s]+[^>]*\s' . preg_quote( $fallback_attr, '#' ) . '=(["\'])' . preg_quote( (string) $variant, '#' ) . '\2[^>]*)>#i';
				if ( preg_match( $pattern, $content, $matches ) ) {
					$matched_attr = $fallback_attr;
					return (string) $matches[1];
				}
			}
		}
		return '';
	}

	/**
	 * @param string $snippet Tag snippet.
	 * @param string $old_url Stored URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID.
	 * @param string $attr    href|src.
	 * @return string
	 */
	private function replace_url_in_tag_snippet( $snippet, $old_url, $new_url, $post_id, $attr ) {
		$allowed = $this->get_editable_url_attributes();
		if ( ! in_array( $attr, $allowed, true ) ) {
			$attr = 'href';
		}
		$new_url = $this->clean_url( (string) $new_url );
		$snippet = (string) $snippet;

		if ( preg_match( '#\s' . preg_quote( $attr, '#' ) . '=(["\'])(.*?)\1#is', $snippet, $match ) ) {
			$actual = html_entity_decode( (string) $match[2], ENT_QUOTES, 'UTF-8' );
			if ( $this->url_attribute_matches_stored( (string) $old_url, $actual, $post_id ) ) {
				$pattern = '#(' . preg_quote( $attr, '#' ) . '=(["\']))' . preg_quote( (string) $match[2], '#' ) . '(\2)#i';
				$count   = 0;
				$next    = preg_replace( $pattern, '$1' . esc_attr( $new_url ) . '$3', $snippet, 1, $count );
				if ( $count > 0 && is_string( $next ) ) {
					return $next;
				}
			}
		}

		foreach ( $this->build_url_replace_candidates( $old_url, $post_id ) as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$pattern = '#(' . preg_quote( $attr, '#' ) . '=(["\']))' . preg_quote( (string) $variant, '#' ) . '(\2)#i';
			$count   = 0;
			$next    = preg_replace( $pattern, '$1' . esc_attr( $new_url ) . '$3', $snippet, 1, $count );
			if ( $count > 0 && is_string( $next ) ) {
				return $next;
			}
		}
		return $snippet;
	}

	/**
	 * Short raw-content excerpt around a stored URL (block JSON, plain text, etc.).
	 *
	 * @param string $content Post content.
	 * @param string $old_url Stored URL.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function extract_url_context_snippet( $content, $old_url, $post_id ) {
		$content = (string) $content;
		foreach ( $this->build_url_replace_candidates_for_content( $old_url, $post_id, $content ) as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$pos = TSOLIIN_HTTP::find_complete_url_offset( $content, (string) $variant );
			if ( false === $pos ) {
				continue;
			}
			$start = max( 0, $pos - 80 );
			$end   = min( strlen( $content ), $pos + strlen( (string) $variant ) + 80 );
			return substr( $content, $start, $end - $start );
		}
		return '';
	}

	/**
	 * Replace URL variants inside a short text snippet.
	 *
	 * @param string $snippet Text excerpt.
	 * @param string $old_url Stored URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function replace_url_in_text_snippet( $snippet, $old_url, $new_url, $post_id ) {
		$snippet = (string) $snippet;
		$new_url = $this->clean_url( (string) $new_url );
		foreach ( $this->build_url_replace_candidates( $old_url, $post_id ) as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			$result = $this->replace_variant_safely( $snippet, $variant, $new_url, 1 );
			if ( $result['changed'] ) {
				return $result['content'];
			}
		}
		return $snippet;
	}

	/**
	 * Replace URL values inside parsed Gutenberg block attrs.
	 *
	 * @param string $raw     post_content.
	 * @param string $old_url Stored URL.
	 * @param string $new_url New URL.
	 * @param int    $post_id Post ID.
	 * @return string|null
	 */
	private function replace_url_in_parsed_blocks( $raw, $old_url, $new_url, $post_id = 0 ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return null;
		}
		$blocks = parse_blocks( $raw );
		if ( empty( $blocks ) ) {
			return null;
		}
		$new_url    = $this->clean_url( (string) $new_url );
		$candidates = $this->build_url_replace_candidates_for_content( $old_url, $post_id, $raw );
		$changed    = false;
		$blocks     = $this->replace_url_in_blocks_recursive( $blocks, $candidates, $new_url, $changed );
		if ( ! $changed ) {
			return null;
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array[] $blocks     Parsed blocks.
	 * @param string[] $candidates URL variants to replace.
	 * @param string  $new_url    Replacement URL.
	 * @param bool    $changed    Set true when a value changes.
	 * @return array[]
	 */
	private function replace_url_in_blocks_recursive( array $blocks, array $candidates, $new_url, &$changed ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$next = $this->replace_url_values_in_array( $block['attrs'], $candidates, $new_url, $changed );
				if ( $next !== $block['attrs'] ) {
					$block['attrs'] = $next;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_url_in_blocks_recursive( $block['innerBlocks'], $candidates, $new_url, $changed );
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * @param array    $data       Block attrs or nested array.
	 * @param string[] $candidates URL variants.
	 * @param string   $new_url    Replacement URL.
	 * @param bool     $changed    Set true when a value changes.
	 * @param int      $depth      Recursion guard.
	 * @return array
	 */
	private function replace_url_values_in_array( array $data, array $candidates, $new_url, &$changed, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $data;
		}
		$url_keys = array( 'url', 'link', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl', 'src' );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->replace_url_values_in_array( $value, $candidates, $new_url, $changed, $depth + 1 );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key_lower = strtolower( (string) $key );
			$looks_url = in_array( $key_lower, $url_keys, true )
				|| (bool) preg_match( '/(?:url|link|href)$/i', $key_lower );
			if ( ! $looks_url ) {
				continue;
			}
			foreach ( $candidates as $variant ) {
				if ( '' === $variant ) {
					continue;
				}
				if ( 0 === strcasecmp( (string) $value, (string) $variant ) ) {
					$data[ $key ] = $new_url;
					$changed      = true;
					break;
				}
			}
		}
		return $data;
	}

	/**
	 * Store a post revision before content edits when enabled in settings.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function maybe_create_post_revision( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! empty( $this->revision_saved_for_posts[ $post_id ] ) ) {
			return false;
		}
		if ( ! TSOLIIN_Support::is_create_revision_enabled() ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return false;
		}
		if ( ! wp_revisions_enabled( $post ) ) {
			return false;
		}
		$revision_id = wp_save_post_revision( $post );
		if ( ! $revision_id ) {
			return false;
		}
		$this->revision_saved_for_posts[ $post_id ] = true;
		return true;
	}

	/**
	 * Whether a pre-edit revision was stored for this post in the current request.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function did_create_revision_for_post( $post_id ) {
		$post_id = absint( $post_id );
		return $post_id > 0 && ! empty( $this->revision_saved_for_posts[ $post_id ] );
	}

	/**
	 * Replace alt text on an <img> matched by src URL.
	 *
	 * @param int         $post_id  Post ID.
	 * @param string      $old_url  Original src URL stored in the DB.
	 * @param string      $new_alt  New alt text (may be empty).
	 * @param string|null $new_src  Updated src URL after a URL change (optional).
	 * @return bool
	 */
	public function replace_alt_in_post( $post_id, $old_url, $new_alt, $new_src = null ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content     = (string) $post->post_content;
		$match_src   = null !== $new_src ? (string) $new_src : (string) $old_url;
		$new_content = $this->replace_alt_on_img_src(
			$content,
			(string) $old_url,
			(string) $new_alt,
			$post_id,
			$match_src
		);

		$content_saved = false;
		if ( null !== $new_content && $new_content !== $content ) {
			$this->maybe_create_post_revision( $post_id );

			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			$content_saved = true;
			$content       = $new_content;
		}

		if ( ! $content_saved ) {
			$block_content = $this->replace_alt_in_parsed_blocks(
				$content,
				(string) $old_url,
				(string) $new_alt,
				$post_id,
				$match_src
			);
			if ( null !== $block_content && $block_content !== $content ) {
				$this->maybe_create_post_revision( $post_id );

				$r = $this->update_post_content( $post_id, $block_content );
				if ( ! $r ) {
					return false;
				}
				$this->purge_cache( $post_id );
				$content_saved = true;
			}
		}

		$meta_saved = $this->sync_attachment_image_alt_meta( $match_src, (string) $old_url, (string) $new_alt );

		return $content_saved || $meta_saved;
	}

	/**
	 * Store alt text on the media-library attachment when the URL maps to one.
	 *
	 * @param string $primary_url  Preferred image URL.
	 * @param string $fallback_url Secondary URL variant.
	 * @param string $new_alt      Alt text.
	 * @return bool
	 */
	private function sync_attachment_image_alt_meta( $primary_url, $fallback_url, $new_alt ) {
		$attachment_id = 0;
		foreach ( array( (string) $primary_url, (string) $fallback_url ) as $url ) {
			if ( '' === $url ) {
				continue;
			}
			$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( $url );
			if ( $attachment_id > 0 ) {
				break;
			}
		}
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$new_alt = sanitize_text_field( (string) $new_alt );
		$current = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		// update_post_meta() returns false when the value is unchanged — treat as success.
		if ( (string) $current === (string) $new_alt ) {
			return true;
		}
		return false !== update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_alt );
	}

	/**
	 * Replace alt text inside Gutenberg block attrs (gallery/image JSON).
	 *
	 * @param string $raw        post_content.
	 * @param string $old_url    Stored URL.
	 * @param string $new_alt    New alt text.
	 * @param int    $post_id    Post ID.
	 * @param string $match_src  Src URL to match after a URL change.
	 * @return string|null
	 */
	private function replace_alt_in_parsed_blocks( $raw, $old_url, $new_alt, $post_id = 0, $match_src = '' ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return null;
		}
		$blocks = parse_blocks( $raw );
		if ( empty( $blocks ) ) {
			return null;
		}

		$new_alt     = sanitize_text_field( (string) $new_alt );
		$candidates  = array();
		$stored_urls = array_values( array_unique( array_filter( array( (string) $match_src, (string) $old_url ) ) ) );
		foreach ( $stored_urls as $stored ) {
			$candidates = array_merge(
				$candidates,
				$this->build_url_replace_candidates_for_content( $stored, $post_id, $raw )
			);
		}
		$candidates = array_values( array_unique( array_filter( $candidates ) ) );
		if ( empty( $candidates ) ) {
			return null;
		}

		$changed = false;
		$blocks  = $this->replace_alt_in_blocks_recursive( $blocks, $candidates, $new_alt, $changed );
		if ( ! $changed ) {
			return null;
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array[]  $blocks     Parsed blocks.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $new_alt    Replacement alt text.
	 * @param bool     $changed    Set true when a value changes.
	 * @return array[]
	 */
	private function replace_alt_in_blocks_recursive( array $blocks, array $candidates, $new_alt, &$changed ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$next = $this->replace_alt_in_block_attrs( $block['attrs'], $candidates, $new_alt, $changed );
				if ( $next !== $block['attrs'] ) {
					$block['attrs'] = $next;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_alt_in_blocks_recursive( $block['innerBlocks'], $candidates, $new_alt, $changed );
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * @param array    $attrs      Block attrs.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $new_alt    Replacement alt text.
	 * @param bool     $changed    Set true when a value changes.
	 * @return array
	 */
	private function replace_alt_in_block_attrs( array $attrs, array $candidates, $new_alt, &$changed ) {
		return $this->replace_alt_in_array( $attrs, $candidates, $new_alt, $changed );
	}

	/**
	 * @param array    $data       Block attrs or nested array.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $new_alt    Replacement alt text.
	 * @param bool     $changed    Set true when a value changes.
	 * @param int      $depth      Recursion guard.
	 * @return array
	 */
	private function replace_alt_in_array( array $data, array $candidates, $new_alt, &$changed, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->replace_alt_in_array( $value, $candidates, $new_alt, $changed, $depth + 1 );
			}
		}

		if ( $this->array_is_image_alt_owner( $data ) && $this->array_tree_contains_url_candidate( $data, $candidates ) ) {
			if ( ! isset( $data['alt'] ) || (string) $data['alt'] !== (string) $new_alt ) {
				$data['alt'] = $new_alt;
				$changed     = true;
			}
		}

		return $data;
	}

	/**
	 * Whether an associative array represents a Gutenberg image object that owns alt text.
	 *
	 * @param array $data Block attrs fragment.
	 * @return bool
	 */
	private function array_is_image_alt_owner( array $data ) {
		if ( ! empty( $data['id'] ) && is_numeric( $data['id'] ) ) {
			return true;
		}
		if ( array_key_exists( 'alt', $data ) ) {
			return true;
		}
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			return true;
		}
		if ( ! empty( $data['url'] ) && is_string( $data['url'] ) && $this->url_looks_like_image_file( $data['url'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param array    $data       Nested block attrs.
	 * @param string[] $candidates URL variants to match.
	 * @param int      $depth      Recursion guard.
	 * @return bool
	 */
	private function array_tree_contains_url_candidate( array $data, array $candidates, $depth = 0 ) {
		if ( $depth > 12 ) {
			return false;
		}
		$url_keys = array( 'url', 'link', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl', 'src' );
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$key_lower = strtolower( (string) $key );
				$looks_url = in_array( $key_lower, $url_keys, true )
					|| (bool) preg_match( '/(?:url|link|href)$/i', $key_lower );
				if ( $looks_url && $this->url_value_matches_candidates( $value, $candidates ) ) {
					return true;
				}
			} elseif ( is_array( $value ) && $this->array_tree_contains_url_candidate( $value, $candidates, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string   $value      URL from block attrs.
	 * @param string[] $candidates URL variants to match.
	 * @return bool
	 */
	private function url_value_matches_candidates( $value, array $candidates ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		foreach ( $candidates as $variant ) {
			if ( '' === $variant ) {
				continue;
			}
			if ( 0 === strcasecmp( $value, (string) $variant ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace or add alt="" on the first matching <img src="…"> tag.
	 *
	 * @param string $content   Post HTML.
	 * @param string $old_url   Original src URL stored in the DB.
	 * @param string $new_alt   New alt text.
	 * @param int    $post_id   Post ID.
	 * @param string $match_src Src URL to match after a URL change.
	 * @return string|null
	 */
	private function replace_alt_on_img_src( $content, $old_url, $new_alt, $post_id, $match_src ) {
		$new_alt     = sanitize_text_field( (string) $new_alt );
		$content     = (string) $content;
		$escaped_alt = esc_attr( $new_alt );
		$stored_urls = array_values( array_unique( array_filter( array( (string) $match_src, (string) $old_url ) ) ) );
		$candidates  = array();

		foreach ( $stored_urls as $stored ) {
			$candidates = array_merge(
				$candidates,
				$this->build_url_replace_candidates_for_content( $stored, $post_id, $content )
			);
		}
		$candidates = array_values( array_unique( array_filter( $candidates ) ) );

		foreach ( $candidates as $spelling ) {
			$snippet = $this->extract_tag_snippet_for_attr_value( $content, 'src', $spelling );
			if ( '' === $snippet || false === stripos( $snippet, '<img' ) ) {
				continue;
			}
			$updated = $this->replace_alt_in_img_tag_snippet( $snippet, $escaped_alt );
			if ( $updated === $snippet ) {
				continue;
			}
			$pos = strpos( $content, $snippet );
			if ( false === $pos ) {
				$pos = stripos( $content, $snippet );
				if ( false === $pos ) {
					continue;
				}
			}
			return substr_replace( $content, $updated, $pos, strlen( $snippet ) );
		}

		$attachment_id = 0;
		foreach ( $stored_urls as $stored ) {
			$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( $stored );
			if ( $attachment_id > 0 ) {
				break;
			}
		}
		if ( $attachment_id > 0 && preg_match(
			'#<img\b[^>]*\bclass=(["\'])[^"\']*\bwp-image-' . preg_quote( (string) $attachment_id, '#' ) . '\b[^"\']*\1[^>]*/?>#is',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			$snippet = (string) $matches[0][0];
			$pos     = (int) $matches[0][1];
			$updated = $this->replace_alt_in_img_tag_snippet( $snippet, $escaped_alt );
			if ( $updated !== $snippet ) {
				return substr_replace( $content, $updated, $pos, strlen( $snippet ) );
			}
		}

		return null;
	}

	/**
	 * Set or replace alt="" on a single <img> tag snippet.
	 *
	 * @param string $snippet     Full <img …> tag.
	 * @param string $escaped_alt Escaped alt attribute value.
	 * @return string
	 */
	private function replace_alt_in_img_tag_snippet( $snippet, $escaped_alt ) {
		$snippet = (string) $snippet;
		if ( preg_match( '#\salt=(["\']).*?\1#is', $snippet ) ) {
			$next = preg_replace( '#\salt=(["\']).*?\1#is', ' alt="' . $escaped_alt . '"', $snippet, 1 );
			return is_string( $next ) ? $next : $snippet;
		}
		if ( preg_match( '#/\s*>$#', $snippet ) ) {
			$next = preg_replace( '#/\s*>$#', ' alt="' . $escaped_alt . '" />', $snippet, 1 );
			return is_string( $next ) ? $next : $snippet;
		}
		$next = preg_replace( '#>$#', ' alt="' . $escaped_alt . '">', $snippet, 1 );
		return is_string( $next ) ? $next : $snippet;
	}

	/**
	 * Whether a candidate URL is equivalent to the stored link URL for editing.
	 *
	 * Used by Edit/Apply to skip no-op saves. Must not treat a shorter path as
	 * the same URL (e.g. /software-download/ vs /software-download/windows8).
	 *
	 * @param string $stored_url    URL as stored in the database.
	 * @param string $candidate_url URL submitted in the edit modal or Suggest Apply.
	 * @param int    $post_id       Post ID for relative-path resolution.
	 * @return bool
	 */
	/**
	 * Whether two URLs are equivalent for Edit link “no changes” detection.
	 *
	 * Collapses www / trailing-slash / case noise on the same spelling style.
	 * Does NOT treat absolute↔relative form swaps or #fragment changes as no-ops
	 * (those are intentional edits the modal must save).
	 *
	 * @param string $stored_url    URL as stored in the database.
	 * @param string $candidate_url URL submitted in the edit modal or Suggest Apply.
	 * @param int    $post_id       Post ID for relative-path resolution.
	 * @return bool
	 */
	public function urls_equivalent_for_stored_link( $stored_url, $candidate_url, $post_id = 0 ) {
		$stored_url    = trim( (string) $stored_url );
		$candidate_url = trim( (string) $candidate_url );
		if ( '' === $stored_url || '' === $candidate_url ) {
			return false;
		}
		if ( 0 === strcasecmp( $stored_url, $candidate_url ) ) {
			return true;
		}

		$stored_rel = $this->url_is_site_relative_path( $stored_url );
		$cand_rel   = $this->url_is_site_relative_path( $candidate_url );
		if ( $stored_rel !== $cand_rel ) {
			return false;
		}

		$stored_frag = (string) ( wp_parse_url( $stored_url, PHP_URL_FRAGMENT ) ?: '' );
		$cand_frag   = (string) ( wp_parse_url( $candidate_url, PHP_URL_FRAGMENT ) ?: '' );
		if ( $stored_frag !== $cand_frag ) {
			return false;
		}

		return $this->urls_are_same_resource( $stored_url, $candidate_url, $post_id );
	}

	/**
	 * Whether a URL is a site-relative path (/, ./, ../) rather than absolute/protocol-relative.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_is_site_relative_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $url ) || 0 === strpos( $url, '//' ) ) {
			return false;
		}
		return (bool) preg_match( '#^(?:/|\./|\.\./)#', $url );
	}

	/**
	 * Same resource (scheme/host/path/query), ignoring www, trailing slash, case, and #fragment.
	 *
	 * @param string $a       First URL.
	 * @param string $b       Second URL.
	 * @param int    $post_id Post ID for relative-path resolution.
	 * @return bool
	 */
	private function urls_are_same_resource( $a, $b, $post_id = 0 ) {
		$a = (string) $a;
		$b = (string) $b;
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( 0 === strcasecmp( $a, $b ) ) {
			return true;
		}
		$a_abs = self::resolve_to_absolute_url( $a, $post_id );
		$b_abs = self::resolve_to_absolute_url( $b, $post_id );
		if (
			'' !== $a_abs
			&& '' !== $b_abs
			&& class_exists( 'TSOLIIN_HTTP' )
		) {
			if ( TSOLIIN_HTTP::is_http_same_resource_bar_www( $a_abs, $b_abs ) ) {
				return true;
			}
			$a_strip = TSOLIIN_HTTP::strip_noise_query_params_from_url( $a_abs );
			$b_strip = TSOLIIN_HTTP::strip_noise_query_params_from_url( $b_abs );
			if ( TSOLIIN_HTTP::is_http_same_resource_bar_www( $a_strip, $b_strip ) ) {
				return true;
			}
		}
		if ( '' !== $a_abs && '' !== $b_abs ) {
			$a_nof = $this->url_without_fragment( $a_abs );
			$b_nof = $this->url_without_fragment( $b_abs );
			if (
				'' !== $a_nof
				&& '' !== $b_nof
				&& class_exists( 'TSOLIIN_HTTP' )
			) {
				if ( TSOLIIN_HTTP::is_http_same_resource_bar_www( $a_nof, $b_nof ) ) {
					return true;
				}
				$a_strip = TSOLIIN_HTTP::strip_noise_query_params_from_url( $a_nof );
				$b_strip = TSOLIIN_HTTP::strip_noise_query_params_from_url( $b_nof );
				if ( TSOLIIN_HTTP::is_http_same_resource_bar_www( $a_strip, $b_strip ) ) {
					return true;
				}
			}
		}
		$norm_a = $this->normalize_url_for_matching( $a );
		$norm_b = $this->normalize_url_for_matching( $b );
		if ( $norm_a === $norm_b ) {
			return true;
		}
		$norm_a_nof = $this->normalize_url_for_matching( $this->url_without_fragment( $a ) );
		$norm_b_nof = $this->normalize_url_for_matching( $this->url_without_fragment( $b ) );
		return ( '' !== $norm_a_nof && $norm_a_nof === $norm_b_nof );
	}

	/**
	 * Replace anchor text inside an <a href="…"> tag in post content.
	 *
	 * Classic Editor: HTML anchors in post_content.
	 * Block editor: block innerHTML plus attrs JSON (button, navigation-link, etc.).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $url        href URL as stored in the DB.
	 * @param string $new_anchor New visible link text.
	 * @return bool
	 */
	public function replace_anchor_in_post( $post_id, $url, $new_anchor ) {
		$post_id    = absint( $post_id );
		$post       = get_post( $post_id );
		$new_anchor = sanitize_text_field( (string) $new_anchor );
		// Empty link text is allowed (clears visible text; quality filters can flag it).
		if ( ! $post ) {
			return false;
		}

		$content     = (string) $post->post_content;
		$new_content = $this->replace_anchor_in_html_content( $content, (string) $url, $new_anchor, $post_id );
		if ( null !== $new_content && $new_content !== $content ) {
			$this->maybe_create_post_revision( $post_id );
			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			return true;
		}

		$new_content = $this->replace_anchor_in_parsed_blocks( $content, (string) $url, $new_anchor, $post_id );
		if ( null !== $new_content && $new_content !== $content ) {
			$this->maybe_create_post_revision( $post_id );
			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			return true;
		}

		if ( $this->replace_anchor_in_post_meta( $post_id, (string) $url, $new_anchor ) ) {
			$this->purge_cache( $post_id );
			return true;
		}

		return false;
	}

	/**
	 * Replace link text inside HTML (classic editor or block innerHTML).
	 *
	 * @param string $content    Raw HTML.
	 * @param string $url        Stored href URL.
	 * @param string $new_anchor New visible text.
	 * @param int    $post_id    Post ID.
	 * @return string|null Updated HTML or null when no anchor matched.
	 */
	private function replace_anchor_in_html_content( $content, $url, $new_anchor, $post_id ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return null;
		}

		$next = $this->replace_first_matching_anchor_in_html( $content, $url, $new_anchor, $post_id );
		if ( null !== $next ) {
			return $next;
		}

		$candidates = array();
		foreach ( $this->get_content_url_spellings_for_stored( $content, $url, $post_id ) as $spelling ) {
			$candidates[] = $spelling;
		}
		foreach ( $this->build_url_replace_candidates_for_content( $url, $post_id, $content ) as $spelling ) {
			$candidates[] = $spelling;
		}
		$candidates = array_values( array_unique( array_filter( $candidates ) ) );

		foreach ( $candidates as $spelling ) {
			$snippet = $this->extract_anchor_tag_snippet_for_href( $content, $spelling );
			if ( '' === $snippet ) {
				continue;
			}
			$updated = $this->replace_anchor_text_in_tag_snippet( $snippet, $new_anchor );
			if ( $updated === $snippet ) {
				continue;
			}
			$pos = strpos( $content, $snippet );
			if ( false === $pos ) {
				$pos = stripos( $content, $snippet );
				if ( false === $pos ) {
					continue;
				}
			}
			return substr_replace( $content, $updated, $pos, strlen( $snippet ) );
		}

		return null;
	}

	/**
	 * Replace the first <a href="…"> whose href matches the stored URL.
	 *
	 * @param string $content    HTML.
	 * @param string $stored_url Stored URL.
	 * @param string $new_anchor New link text.
	 * @param int    $post_id    Post ID.
	 * @return string|null
	 */
	private function replace_first_matching_anchor_in_html( $content, $stored_url, $new_anchor, $post_id ) {
		$content = (string) $content;
		if ( ! preg_match_all(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']*)\1[^>]*>(.*?)</a>#is',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return null;
		}

		foreach ( $matches[2] as $i => $href_part ) {
			$href = (string) $href_part[0];
			if ( ! $this->url_attribute_matches_stored( (string) $stored_url, $href, $post_id ) ) {
				continue;
			}
			$snippet = (string) $matches[0][ $i ][0];
			$pos     = (int) $matches[0][ $i ][1];
			$updated = $this->replace_anchor_text_in_tag_snippet( $snippet, $new_anchor );
			if ( $updated === $snippet ) {
				continue;
			}
			return substr_replace( $content, $updated, $pos, strlen( $snippet ) );
		}

		return null;
	}

	/**
	 * Update link labels inside Gutenberg block attrs / innerHTML.
	 *
	 * @param string $raw        post_content.
	 * @param string $stored_url Stored URL.
	 * @param string $new_anchor New visible text.
	 * @param int    $post_id    Post ID.
	 * @return string|null
	 */
	private function replace_anchor_in_parsed_blocks( $raw, $stored_url, $new_anchor, $post_id = 0 ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return null;
		}
		$blocks = parse_blocks( $raw );
		if ( empty( $blocks ) ) {
			return null;
		}
		$candidates = $this->build_url_replace_candidates_for_content( (string) $stored_url, $post_id, $raw );
		if ( empty( $candidates ) ) {
			return null;
		}
		$changed = false;
		$blocks  = $this->replace_anchor_in_blocks_recursive( $blocks, (string) $stored_url, $candidates, $new_anchor, $post_id, $changed );
		if ( ! $changed ) {
			return null;
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array[]  $blocks     Parsed blocks.
	 * @param string   $stored_url Stored URL.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $new_anchor New link text.
	 * @param int      $post_id    Post ID.
	 * @param bool     $changed    Set true when modified.
	 * @return array[]
	 */
	private function replace_anchor_in_blocks_recursive( array $blocks, $stored_url, array $candidates, $new_anchor, $post_id, &$changed ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$next = $this->replace_anchor_in_html_content( $block['innerHTML'], (string) $stored_url, $new_anchor, $post_id );
				if ( null !== $next && $next !== $block['innerHTML'] ) {
					$block['innerHTML'] = $next;
					$changed            = true;
				}
			}
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $idx => $part ) {
					if ( ! is_string( $part ) || '' === $part ) {
						continue;
					}
					$next = $this->replace_anchor_in_html_content( $part, (string) $stored_url, $new_anchor, $post_id );
					if ( null !== $next && $next !== $part ) {
						$block['innerContent'][ $idx ] = $next;
						$changed                       = true;
					}
				}
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$next = $this->replace_anchor_labels_in_array( $block['attrs'], $candidates, $new_anchor, $changed );
				if ( $next !== $block['attrs'] ) {
					$block['attrs'] = $next;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_anchor_in_blocks_recursive(
					$block['innerBlocks'],
					$stored_url,
					$candidates,
					$new_anchor,
					$post_id,
					$changed
				);
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * @param array    $data       Block attrs or nested array.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $new_anchor Replacement link text.
	 * @param bool     $changed    Set true when a value changes.
	 * @param int      $depth      Recursion guard.
	 * @return array
	 */
	private function replace_anchor_labels_in_array( array $data, array $candidates, $new_anchor, &$changed, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->replace_anchor_labels_in_array( $value, $candidates, $new_anchor, $changed, $depth + 1 );
			}
		}

		if ( $this->array_has_anchor_label_fields( $data ) && $this->array_tree_contains_url_candidate( $data, $candidates ) ) {
			foreach ( array( 'text', 'title', 'linktitle', 'label', 'content' ) as $label_key ) {
				if ( array_key_exists( $label_key, $data ) && is_string( $data[ $label_key ] ) ) {
					$data[ $label_key ] = sanitize_text_field( (string) $new_anchor );
					$changed            = true;
				}
			}
		}

		return $data;
	}

	/**
	 * Whether an associative array owns visible link text fields in block attrs.
	 *
	 * @param array $data Block attrs fragment.
	 * @return bool
	 */
	private function array_has_anchor_label_fields( array $data ) {
		foreach ( array( 'text', 'title', 'linktitle', 'label', 'content' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace anchor text inside scannable post meta values.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $stored_url Stored URL.
	 * @param string $new_anchor New link text.
	 * @return bool
	 */
	public function replace_anchor_in_post_meta( $post_id, $stored_url, $new_anchor ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		$changed_any = false;
		$grouped     = array();
		foreach ( $this->get_scannable_meta_entries( $post_id ) as $entry ) {
			$key = (string) $entry['key'];
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $entry['value'];
		}
		foreach ( $grouped as $key => $values ) {
			$updated     = array();
			$key_changed = false;
			foreach ( $values as $value ) {
				$new_value = $this->replace_anchor_in_meta_value( $value, (string) $stored_url, $new_anchor, $post_id );
				if ( null !== $new_value ) {
					$updated[]   = $new_value;
					$key_changed = true;
				} else {
					$updated[] = $value;
				}
			}
			if ( ! $key_changed ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $updated as $row ) {
				add_post_meta( $post_id, $key, $row );
			}
			$changed_any = true;
		}
		return $changed_any;
	}

	/**
	 * @param mixed  $val        Meta value.
	 * @param string $stored_url Stored URL.
	 * @param string $new_anchor New link text.
	 * @param int    $post_id    Post ID.
	 * @return mixed|null Updated value or null when unchanged.
	 */
	private function replace_anchor_in_meta_value( $val, $stored_url, $new_anchor, $post_id ) {
		if ( is_string( $val ) ) {
			$next = $this->replace_anchor_in_html_content( $val, $stored_url, $new_anchor, $post_id );
			return ( null !== $next && $next !== $val ) ? $next : null;
		}
		if ( is_array( $val ) ) {
			$candidates = $this->build_url_replace_candidates( (string) $stored_url, $post_id );
			if ( isset( $val['url'] ) && is_string( $val['url'] )
				&& $this->url_value_matches_candidates( (string) $val['url'], $candidates ) ) {
				$changed = false;
				foreach ( array( 'text', 'title', 'linktitle', 'label', 'content' ) as $label_key ) {
					if ( array_key_exists( $label_key, $val ) && is_string( $val[ $label_key ] ) ) {
						$val[ $label_key ] = sanitize_text_field( (string) $new_anchor );
						$changed           = true;
					}
				}
				return $changed ? $val : null;
			}
			$changed = false;
			foreach ( $val as $k => $sub ) {
				$next = $this->replace_anchor_in_meta_value( $sub, $stored_url, $new_anchor, $post_id );
				if ( null !== $next ) {
					$val[ $k ] = $next;
					$changed   = true;
				}
			}
			return $changed ? $val : null;
		}
		if ( is_object( $val ) ) {
			$changed = false;
			foreach ( get_object_vars( $val ) as $k => $sub ) {
				$next = $this->replace_anchor_in_meta_value( $sub, $stored_url, $new_anchor, $post_id );
				if ( null !== $next ) {
					$val->$k = $next;
					$changed = true;
				}
			}
			return $changed ? $val : null;
		}
		return null;
	}

	/**
	 * Full <a href="…">…</a> snippet matching an href spelling in post content.
	 *
	 * @param string $content       Raw post HTML.
	 * @param string $href_spelling Exact href attribute value.
	 * @return string
	 */
	private function extract_anchor_tag_snippet_for_href( $content, $href_spelling ) {
		$content = (string) $content;
		$open    = $this->extract_tag_snippet_for_attr_value( $content, 'href', (string) $href_spelling );
		if ( '' === $open || ! preg_match( '#^<a\b#i', $open ) ) {
			return '';
		}
		$pos = strpos( $content, $open );
		if ( false === $pos ) {
			$pos = stripos( $content, $open );
			if ( false === $pos ) {
				return '';
			}
		}
		$after_open = $pos + strlen( $open );
		$close_pos  = stripos( $content, '</a>', $after_open );
		if ( false === $close_pos ) {
			return '';
		}
		return substr( $content, $pos, $close_pos + 4 - $pos );
	}

	/**
	 * Replace visible text inside a single anchor tag snippet.
	 *
	 * @param string $snippet    Full <a>…</a> HTML.
	 * @param string $new_anchor New link text.
	 * @return string
	 */
	private function replace_anchor_text_in_tag_snippet( $snippet, $new_anchor ) {
		$snippet = (string) $snippet;
		if ( ! preg_match( '#^(<a\b[^>]*>)(.*?)(</a>)$#is', $snippet, $matches ) ) {
			return $snippet;
		}
		return $matches[1] . esc_html( (string) $new_anchor ) . $matches[3];
	}

	/**
	 * Remove an anchor tag, keeping inner text; for images/iframes remove the element.
	 *
	 * Classic Editor: HTML in post_content.
	 * Block editor: block innerHTML plus attrs JSON when no rendered tag exists.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $url       URL to unlink.
	 * @param string $link_type link|image|iframe.
	 * @return bool
	 */
	public function unlink_in_post( $post_id, $url, $link_type = 'link' ) {
		$post_id   = absint( $post_id );
		$post      = get_post( $post_id );
		$link_type = sanitize_key( (string) $link_type );
		if ( ! $post ) {
			return false;
		}

		$content     = (string) $post->post_content;
		$new_content = $this->unlink_in_html_content( $content, (string) $url, $link_type, $post_id );
		if ( null !== $new_content && $new_content !== $content ) {
			$this->maybe_create_post_revision( $post_id );
			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			return true;
		}

		$new_content = $this->unlink_in_parsed_blocks( $content, (string) $url, $link_type, $post_id );
		if ( null !== $new_content && $new_content !== $content ) {
			$this->maybe_create_post_revision( $post_id );
			$r = $this->update_post_content( $post_id, $new_content );
			if ( ! $r ) {
				return false;
			}
			$this->purge_cache( $post_id );
			return true;
		}

		return false;
	}

	/**
	 * Unlink a DB row from its post, using redirect_url when the stored URL is already gone.
	 *
	 * @param object $link DB link row.
	 * @return bool
	 */
	public function unlink_link_row_in_post( $link ) {
		if ( ! $link || empty( $link->post_id ) || empty( $link->link_url ) ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$url  = (string) $link->link_url;
		if ( $this->unlink_in_post( (int) $link->post_id, $url, $type ) ) {
			return true;
		}
		if ( ! empty( $link->redirect_url ) ) {
			$redir = trim( (string) $link->redirect_url );
			if ( '' !== $redir && $redir !== $url ) {
				// Only when that destination appears once in the post.
				if ( 1 === $this->count_href_matches_in_post( (int) $link->post_id, $redir ) ) {
					if ( $this->unlink_in_post( (int) $link->post_id, $redir, $type ) ) {
						return true;
					}
				}
			}
		}
		// URL only in post meta (e.g. social/SEO image) — clear it from meta.
		if ( $this->replace_url_in_post_meta( (int) $link->post_id, $url, '' ) ) {
			$this->purge_cache( (int) $link->post_id );
			return true;
		}
		return false;
	}

	/**
	 * Whether an inspector row is a ghost: not removable markup and not in scannable meta.
	 * Safe to drop from the list without changing the post.
	 *
	 * @param object $link DB link row.
	 * @return bool
	 */
	public function is_orphan_post_link_row( $link ) {
		if ( ! $link || empty( $link->post_id ) || empty( $link->link_url ) ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( $this->is_dedicated_source_key( $sk ) ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) ) {
			return false;
		}
		$url     = (string) $link->link_url;
		$post_id = (int) $link->post_id;
		if ( $this->post_has_unlinkable_markup( $post_id, $url, $type ) ) {
			return false;
		}
		if ( $this->is_url_in_post_body( $post_id, $url, $type ) ) {
			return false;
		}
		if ( ! empty( $this->locate_url_in_post_meta( $post_id, $url )['found'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Count &lt;a href&gt; (and similar) attributes in a post that match a URL.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     URL to match.
	 * @return int
	 */
	public function count_href_matches_in_post( $post_id, $url ) {
		$post_id = absint( $post_id );
		$url     = (string) $url;
		if ( $post_id <= 0 || '' === $url ) {
			return 0;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! is_string( $post->post_content ) || '' === $post->post_content ) {
			return 0;
		}
		$content = (string) $post->post_content;
		$count   = 0;
		if ( preg_match_all(
			'#<(?:a|img|iframe|source|video|audio)\b[^>]*\b(?:href|src|data-url|data-href|data-src|data-link)\s*=\s*(["\'])([^"\']*)\1#is',
			$content,
			$matches
		) ) {
			foreach ( $matches[2] as $attr_val ) {
				if ( $this->url_attribute_matches_stored( $url, (string) $attr_val, $post_id ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Unlink inside raw HTML (classic editor or block innerHTML).
	 *
	 * @param string $content   HTML fragment.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @param int    $post_id   Post ID.
	 * @return string|null
	 */
	private function unlink_in_html_content( $content, $url, $link_type, $post_id ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return null;
		}

		if ( 'image' === $link_type ) {
			return $this->unlink_image_in_html_content( $content, $url, $post_id );
		}
		if ( 'iframe' === $link_type ) {
			return $this->unlink_iframe_in_html_content( $content, $url, $post_id );
		}
		return $this->unlink_link_in_html_content( $content, $url, $post_id );
	}

	/**
	 * @param string $content   HTML.
	 * @param string $url       Stored href URL.
	 * @param int    $post_id   Post ID.
	 * @return string|null
	 */
	private function unlink_link_in_html_content( $content, $url, $post_id ) {
		$content = (string) $content;
		if ( ! preg_match_all(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']*)\1[^>]*>(.*?)</a>#is',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return null;
		}

		foreach ( $matches[2] as $i => $href_part ) {
			$href = (string) $href_part[0];
			if ( ! $this->url_attribute_matches_stored( (string) $url, $href, $post_id ) ) {
				continue;
			}
			$snippet = (string) $matches[0][ $i ][0];
			$inner   = (string) $matches[3][ $i ][0];
			$pos     = (int) $matches[0][ $i ][1];
			return substr_replace( $content, $inner, $pos, strlen( $snippet ) );
		}

		$candidates = $this->build_url_replace_candidates_for_content( (string) $url, $post_id, $content );
		foreach ( $candidates as $spelling ) {
			$snippet = $this->extract_anchor_tag_snippet_for_href( $content, $spelling );
			if ( '' === $snippet || ! preg_match( '#^(<a\b[^>]*>)(.*?)(</a>)$#is', $snippet, $parts ) ) {
				continue;
			}
			$pos = strpos( $content, $snippet );
			if ( false === $pos ) {
				$pos = stripos( $content, $snippet );
				if ( false === $pos ) {
					continue;
				}
			}
			return substr_replace( $content, $parts[2], $pos, strlen( $snippet ) );
		}

		return null;
	}

	/**
	 * @param string $content HTML.
	 * @param string $url     Stored src URL.
	 * @param int    $post_id Post ID.
	 * @return string|null
	 */
	private function unlink_image_in_html_content( $content, $url, $post_id ) {
		$content     = (string) $content;
		$stored_urls = array_values( array_unique( array_filter( array( (string) $url ) ) ) );
		$candidates  = array();
		foreach ( $stored_urls as $stored ) {
			$candidates = array_merge(
				$candidates,
				$this->build_url_replace_candidates_for_content( $stored, $post_id, $content )
			);
		}
		$candidates = array_values( array_unique( array_filter( $candidates ) ) );

		foreach ( $candidates as $spelling ) {
			$snippet = $this->extract_tag_snippet_for_attr_value( $content, 'src', $spelling );
			if ( '' === $snippet || false === stripos( $snippet, '<img' ) ) {
				continue;
			}
			$pos = strpos( $content, $snippet );
			if ( false === $pos ) {
				$pos = stripos( $content, $snippet );
				if ( false === $pos ) {
					continue;
				}
			}
			return substr_replace( $content, '', $pos, strlen( $snippet ) );
		}

		$attachment_id = TSOLIIN_Support::resolve_attachment_id_from_url( (string) $url );
		if ( $attachment_id > 0 && preg_match(
			'#<img\b[^>]*\bclass=(["\'])[^"\']*\bwp-image-' . preg_quote( (string) $attachment_id, '#' ) . '\b[^"\']*\1[^>]*/?>#is',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			$snippet = (string) $matches[0][0];
			$pos     = (int) $matches[0][1];
			return substr_replace( $content, '', $pos, strlen( $snippet ) );
		}

		$gallery_content = $this->unlink_url_from_classic_gallery_shortcodes( $content, (string) $url, $post_id );
		if ( null !== $gallery_content && $gallery_content !== $content ) {
			return $gallery_content;
		}

		return null;
	}

	/**
	 * @param string $content HTML.
	 * @param string $url     Stored src URL.
	 * @param int    $post_id Post ID.
	 * @return string|null
	 */
	private function unlink_iframe_in_html_content( $content, $url, $post_id ) {
		$content = (string) $content;
		if ( ! preg_match_all(
			'#<iframe\b[^>]*\bsrc\s*=\s*(["\'])([^"\']*)\1[^>]*>.*?</iframe>#is',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return null;
		}

		foreach ( $matches[2] as $i => $src_part ) {
			$src = (string) $src_part[0];
			if ( ! $this->url_attribute_matches_stored( (string) $url, $src, $post_id ) ) {
				continue;
			}
			$snippet = (string) $matches[0][ $i ][0];
			$pos     = (int) $matches[0][ $i ][1];
			return substr_replace( $content, '', $pos, strlen( $snippet ) );
		}

		$candidates = $this->build_url_replace_candidates_for_content( (string) $url, $post_id, $content );
		foreach ( $candidates as $spelling ) {
			$snippet = $this->extract_tag_snippet_for_attr_value( $content, 'src', $spelling );
			if ( '' === $snippet || false === stripos( $snippet, '<iframe' ) ) {
				continue;
			}
			$close_pos = stripos( $content, '</iframe>', strpos( $content, $snippet ) );
			if ( false === $close_pos ) {
				continue;
			}
			$end     = $close_pos + 9;
			$pos     = strpos( $content, $snippet );
			if ( false === $pos ) {
				$pos = stripos( $content, $snippet );
				if ( false === $pos ) {
					continue;
				}
			}
			$full_snippet = substr( $content, $pos, $end - $pos );
			return substr_replace( $content, '', $pos, strlen( $full_snippet ) );
		}

		return null;
	}

	/**
	 * Unlink inside Gutenberg block attrs / innerHTML.
	 *
	 * @param string $raw       post_content.
	 * @param string $url       Stored URL.
	 * @param string $link_type link|image|iframe.
	 * @param int    $post_id   Post ID.
	 * @return string|null
	 */
	private function unlink_in_parsed_blocks( $raw, $url, $link_type, $post_id = 0 ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return null;
		}
		$blocks = parse_blocks( $raw );
		if ( empty( $blocks ) ) {
			return null;
		}
		$candidates = $this->build_url_replace_candidates_for_content( (string) $url, $post_id, $raw );
		if ( empty( $candidates ) ) {
			return null;
		}
		$changed = false;
		$blocks  = $this->unlink_in_blocks_recursive( $blocks, (string) $url, $candidates, $link_type, $post_id, $changed );
		if ( ! $changed ) {
			return null;
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array[]  $blocks     Parsed blocks.
	 * @param string   $url        Stored URL.
	 * @param string[] $candidates URL variants to match.
	 * @param string   $link_type  link|image|iframe.
	 * @param int      $post_id    Post ID.
	 * @param bool     $changed    Set true when modified.
	 * @return array[]
	 */
	private function unlink_in_blocks_recursive( array $blocks, $url, array $candidates, $link_type, $post_id, &$changed ) {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$result[] = $block;
				continue;
			}
			if ( 'image' === $link_type && $this->block_matches_media_unlink( $block, $candidates ) ) {
				$changed = true;
				continue;
			}
			if ( 'iframe' === $link_type && $this->block_matches_media_unlink( $block, $candidates ) ) {
				$changed = true;
				continue;
			}
			if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$next = $this->unlink_in_html_content( $block['innerHTML'], (string) $url, $link_type, $post_id );
				if ( null !== $next && $next !== $block['innerHTML'] ) {
					$block['innerHTML'] = $next;
					$changed            = true;
				}
			}
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $idx => $part ) {
					if ( ! is_string( $part ) || '' === $part ) {
						continue;
					}
					$next = $this->unlink_in_html_content( $part, (string) $url, $link_type, $post_id );
					if ( null !== $next && $next !== $part ) {
						$block['innerContent'][ $idx ] = $next;
						$changed                       = true;
					}
				}
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				if ( 'link' === $link_type ) {
					$next = $this->clear_link_urls_in_array( $block['attrs'], $candidates, $changed );
					if ( $next !== $block['attrs'] ) {
						$block['attrs'] = $next;
					}
				} elseif ( 'image' === $link_type ) {
					$next = $this->remove_matching_images_from_block_attrs( $block['attrs'], $candidates, $changed );
					if ( $next !== $block['attrs'] ) {
						$block['attrs'] = $next;
					}
				} elseif ( 'iframe' === $link_type ) {
					$next = $this->clear_link_urls_in_array( $block['attrs'], $candidates, $changed );
					if ( $next !== $block['attrs'] ) {
						$block['attrs'] = $next;
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->unlink_in_blocks_recursive(
					$block['innerBlocks'],
					$url,
					$candidates,
					$link_type,
					$post_id,
					$changed
				);
			}
			$result[] = $block;
		}
		return $result;
	}

	/**
	 * Whether a parsed block should be dropped when unlinking an image or iframe URL.
	 *
	 * @param array    $block      Parsed block.
	 * @param string[] $candidates URL variants.
	 * @return bool
	 */
	private function block_matches_media_unlink( array $block, array $candidates ) {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( 'core/gallery' === $name ) {
			return false;
		}
		if ( empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return false;
		}
		if ( ! $this->array_tree_contains_url_candidate( $block['attrs'], $candidates ) ) {
			return false;
		}
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$media_blocks = array(
			'core/image',
			'core/gallery',
			'core/cover',
			'core/media-text',
			'core/video',
			'core/audio',
			'core/file',
			'core/embed',
			'core/html',
		);
		if ( in_array( $name, $media_blocks, true ) ) {
			return true;
		}
		return ( '' !== $name && ( false !== strpos( $name, 'image' ) || false !== strpos( $name, 'gallery' ) || false !== strpos( $name, 'embed' ) ) );
	}

	/**
	 * Clear href/url fields in block attrs while keeping visible link text.
	 *
	 * @param array    $data       Block attrs or nested array.
	 * @param string[] $candidates URL variants to match.
	 * @param bool     $changed    Set true when a value changes.
	 * @param int      $depth      Recursion guard.
	 * @return array
	 */
	private function clear_link_urls_in_array( array $data, array $candidates, &$changed, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $data;
		}
		$url_keys = array( 'url', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl' );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( 'link' === strtolower( (string) $key ) ) {
					$next = $this->clear_link_urls_in_array( $value, $candidates, $changed, $depth + 1 );
					if ( $next !== $value ) {
						$data[ $key ] = $next;
					}
					continue;
				}
				$data[ $key ] = $this->clear_link_urls_in_array( $value, $candidates, $changed, $depth + 1 );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key_lower = strtolower( (string) $key );
			$looks_url = in_array( $key_lower, $url_keys, true )
				|| (bool) preg_match( '/(?:url|link|href)$/i', $key_lower );
			if ( $looks_url && $this->url_value_matches_candidates( $value, $candidates ) ) {
				$data[ $key ] = '';
				$changed      = true;
			}
		}
		return $data;
	}

	/**
	 * Remove gallery image entries whose URL tree matches the stored URL.
	 *
	 * @param array    $attrs      Block attrs.
	 * @param string[] $candidates URL variants.
	 * @param bool     $changed    Set true when modified.
	 * @return array
	 */
	private function remove_matching_images_from_block_attrs( array $attrs, array $candidates, &$changed ) {
		if ( ! empty( $attrs['images'] ) && is_array( $attrs['images'] ) ) {
			$next = array();
			foreach ( $attrs['images'] as $image ) {
				if ( is_array( $image ) && $this->array_tree_contains_url_candidate( $image, $candidates ) ) {
					$changed = true;
					continue;
				}
				$next[] = $image;
			}
			if ( count( $next ) !== count( $attrs['images'] ) ) {
				$attrs['images'] = array_values( $next );
			}
		}
		return $attrs;
	}

	/**
	 * Remove a link from a comment (content or author URL).
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $url        URL to remove.
	 * @return bool
	 */
	public function unlink_in_comment( $comment_id, $url ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return false;
		}
		$comment    = get_comment( $comment_id );
		if ( ! $comment ) {
			return false;
		}
		$url = (string) $url;

		$content = (string) $comment->comment_content;
		// Match href values the same way as post content / replace helpers (encoding, entities, trailing slash).
		$href_variants = array_unique( array_filter( array(
			$url,
			urldecode( $url ),
			rawurldecode( $url ),
			str_replace( '&', '&amp;', $url ),
			str_replace( '&', '&amp;', urldecode( $url ) ),
			html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ),
			rtrim( $url, '/' ),
			rtrim( urldecode( $url ), '/' ),
			rtrim( rawurldecode( $url ), '/' ),
		) ) );

		$content_changed = false;
		foreach ( $href_variants as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$new = preg_replace( '#<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>(.*?)</a>#is', '$1', $content );
			if ( null !== $new && $new !== $content ) {
				$content = $new;
				$content_changed = true;
				break;
			}
		}

		// Plain-text URL in comment body (no <a> tag) — remove the URL string.
		if ( ! $content_changed ) {
			foreach ( $href_variants as $v ) {
				if ( '' === $v || false === strpos( $content, $v ) ) {
					continue;
				}
				$new = str_replace( $v, '', $content );
				if ( $new !== $content ) {
					$content = trim( preg_replace( '/\s{2,}/', ' ', $new ) );
					$content_changed = true;
					break;
				}
			}
		}

		$author         = trim( (string) $comment->comment_author_url );
		$author_matches = ( '' !== $author && $this->comment_author_url_matches_row_url( $author, $url ) );

		if ( ! $content_changed && ! $author_matches ) {
			return false;
		}

		$args = array( 'comment_ID' => $comment_id );
		if ( $content_changed ) {
			$args['comment_content'] = $content;
		}
		if ( $author_matches ) {
			$args['comment_author_url'] = '';
		}

		return false !== wp_update_comment( $args );
	}

	/**
	 * Whether a comment author URL is the same resource as the URL stored in the inspector row.
	 *
	 * Strict equality misses common cases (trailing slash, http vs https, encoding), leaving the
	 * author website set so the next comment scan re-inserts the link.
	 *
	 * @param string $author_url URL from comment_author_url.
	 * @param string $target_url URL from the link inspector row.
	 * @return bool
	 */
	public function comment_author_url_matches_row_url( $author_url, $target_url ) {
		$author_url = trim( (string) $author_url );
		$target_url = trim( (string) $target_url );
		if ( '' === $author_url || '' === $target_url ) {
			return false;
		}
		if ( TSOLIIN_HTTP::urls_equivalent_for_verify_lock( $author_url, $target_url ) ) {
			return true;
		}
		// Same host + path + query ignoring scheme (http/https) for plain site URLs.
		$strip_scheme = static function ( $u ) {
			$p = wp_parse_url( $u );
			if ( empty( $p['host'] ) ) {
				return '';
			}
			$host = strtolower( (string) $p['host'] );
			$path = isset( $p['path'] ) ? $p['path'] : '/';
			$path = '/' === $path ? '/' : rtrim( $path, '/' );
			$query = isset( $p['query'] ) ? '?' . $p['query'] : '';
			$port  = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
			return $host . $port . $path . $query;
		};
		$a = $strip_scheme( $author_url );
		$b = $strip_scheme( $target_url );
		return '' !== $a && $a === $b;
	}

	// -------------------------------------------------------------------------
	// Cache purge
	// -------------------------------------------------------------------------

	/** @param int $post_id */
	public function purge_cache( $post_id ) {
		$post_id = absint( $post_id );
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache public API.
		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			w3tc_pgcache_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'cache_enabler_clear_page_cache_by_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler public API.
		do_action( 'cloudflare_purge_by_url', get_permalink( $post_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cloudflare (or compat) public API.
		do_action( 'tsoliin_after_post_update', $post_id );
	}

	/**
	 * Replace a URL inside comment content (href in <a> tags).
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $old_url    Old URL.
	 * @param string $new_url    New URL.
	 * @return bool
	 */
	public function replace_url_in_comment_content( $comment_id, $old_url, $new_url ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return false;
		}
		$comment    = get_comment( $comment_id );
		if ( ! $comment ) {
			return false;
		}

		$post_id  = (int) $comment->comment_post_ID;
		$content  = (string) $comment->comment_content;
		$new_text = $this->replace_url_in_html_attributes(
			$content,
			(string) $old_url,
			(string) $new_url,
			$post_id
		);
		if ( null === $new_text ) {
			$new_text = $this->replace_plain_url_in_text( $content, (string) $old_url, (string) $new_url, $post_id );
		}
		if ( null === $new_text || $new_text === $content ) {
			return false;
		}

		return false !== wp_update_comment( array(
			'comment_ID'      => $comment_id,
			'comment_content' => $new_text,
		) );
	}

	/**
	 * Replace anchor text for a matching href inside comment content.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $url        href URL as stored in the DB.
	 * @param string $new_anchor New visible link text.
	 * @return bool
	 */
	public function replace_anchor_in_comment_content( $comment_id, $url, $new_anchor ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return false;
		}
		$comment    = get_comment( $comment_id );
		$new_anchor = sanitize_text_field( (string) $new_anchor );
		if ( ! $comment || '' === $new_anchor ) {
			return false;
		}

		$url  = (string) $url;
		$text = (string) $comment->comment_content;
		$candidates = array_unique( array_filter( array(
			$url,
			urldecode( $url ),
			rawurldecode( $url ),
			str_replace( '&', '&amp;', $url ),
			html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ),
		) ) );

		foreach ( $candidates as $c ) {
			if ( '' === $c ) {
				continue;
			}
			$pattern  = '#(<a\s[^>]*href=["\']' . preg_quote( $c, '#' ) . '["\'][^>]*>)(.*?)(</a>)#is';
			$new_text = preg_replace( $pattern, '$1' . esc_html( $new_anchor ) . '$3', $text, 1, $count );
			if ( $count > 0 && is_string( $new_text ) && $new_text !== $text ) {
				return false !== wp_update_comment( array(
					'comment_ID'      => $comment_id,
					'comment_content' => $new_text,
				) );
			}
		}
		return false;
	}

	/**
	 * Keep post_modified when the preserve-dates setting is on (wp_insert_post_data callback).
	 *
	 * @param array $data    Sanitized post data.
	 * @param array $postarr Raw post array passed to wp_insert_post().
	 * @return array
	 */
	public function filter_preserve_post_modified( $data, $postarr ) {
		if ( ! is_array( $this->preserve_modified_context ) ) {
			return $data;
		}
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id !== (int) $this->preserve_modified_context['ID'] ) {
			return $data;
		}
		$data['post_modified']     = $this->preserve_modified_context['post_modified'];
		$data['post_modified_gmt'] = $this->preserve_modified_context['post_modified_gmt'];
		return $data;
	}

	/**
	 * Update post_content via wp_update_post (revisions, cache, KSES).
	 * Optionally keeps post_modified when tsoliin_preserve_dates is enabled.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_content New post_content.
	 * @return bool
	 */
	private function update_post_content( $post_id, $new_content ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$s              = get_option( 'tsoliin_settings', array() );
		$preserve_dates = ! empty( $s['preserve_dates'] );
		// Only skip WP's automatic post_updated revision when we already stored a pre-edit revision.
		$suppress_auto_revision = ! empty( $this->revision_saved_for_posts[ $post_id ] );

		if ( $preserve_dates ) {
			$this->preserve_modified_context = array(
				'ID'                => $post_id,
				'post_modified'     => (string) $post->post_modified,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			);
			add_filter( 'wp_insert_post_data', array( $this, 'filter_preserve_post_modified' ), 10, 2 );
		}
		if ( $suppress_auto_revision ) {
			remove_action( 'post_updated', 'wp_save_post_revision' );
		}

		try {
			$r = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				),
				true
			);
		} finally {
			if ( $suppress_auto_revision ) {
				add_action( 'post_updated', 'wp_save_post_revision' );
			}
			if ( $preserve_dates ) {
				remove_filter( 'wp_insert_post_data', array( $this, 'filter_preserve_post_modified' ), 10 );
				$this->preserve_modified_context = null;
			}
		}

		if ( is_wp_error( $r ) || ! $r ) {
			return false;
		}
		clean_post_cache( $post_id );
		return true;
	}

	// -------------------------------------------------------------------------
	// Phase 2: widgets, taxonomies, FSE, third-party sources
	// -------------------------------------------------------------------------

	/**
	 * Extract all link-like items from HTML/text (anchors, plain URLs, blocks, data-*).
	 *
	 * @param string $content Raw HTML or text.
	 * @param string $raw     Optional raw content for block/plain scans.
	 * @return array[]
	 */
	private function extract_all_url_items( $content, $raw = '' ) {
		$content = (string) $content;
		$raw     = '' !== (string) $raw ? (string) $raw : $content;
		$html    = $this->render_content( $content );
		$items   = array();
		$seen    = array();

		foreach ( $this->extract_links( $html ) as $item ) {
			$this->push_scan_item( $items, $seen, $item );
		}
		if ( empty( $items ) && false !== stripos( $raw, 'href=' ) ) {
			foreach ( $this->regex_links( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_images' ) ) {
			foreach ( $this->extract_images( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
			if ( false !== stripos( $raw, 'src=' ) ) {
				foreach ( $this->extract_images( $raw ) as $item ) {
					$this->push_scan_item( $items, $seen, $item );
				}
			}
		}
		if ( $this->opt( 'scan_iframes' ) ) {
			foreach ( $this->extract_iframes( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_srcset', true ) ) {
			foreach ( $this->extract_responsive_media_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
			foreach ( $this->extract_responsive_media_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_plain_urls', true ) ) {
			foreach ( $this->extract_plain_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_block_attrs', true ) ) {
			foreach ( $this->extract_block_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_data_attrs', true ) ) {
			foreach ( $this->extract_data_attr_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
			foreach ( $this->extract_data_attr_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		return $items;
	}

	/**
	 * Upsert a link from an external/third-party scan item.
	 *
	 * @param array  $item      post_id, url, anchor, link_type, source_key.
	 * @param string $source_id Registered source ID (optional prefix validation).
	 * @return bool
	 */
	public function upsert_external_item( array $item, $source_id = '' ) {
		$url = isset( $item['url'] ) ? $this->clean_url( (string) $item['url'] ) : '';
		if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
			return false;
		}
		$post_id    = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
		$anchor     = isset( $item['anchor'] ) ? sanitize_text_field( (string) $item['anchor'] ) : '';
		$link_type  = isset( $item['link_type'] ) ? sanitize_key( (string) $item['link_type'] ) : 'link';
		$source_key = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
		if ( '' === $source_key && '' !== (string) $source_id ) {
			$source_key = sanitize_key( (string) $source_id ) . '-' . md5( $url );
		}
		$allowed_types = array( 'link', 'image', 'iframe', 'widget', 'term', 'template', 'wp_block' );
		if ( ! in_array( $link_type, $allowed_types, true ) ) {
			$link_type = 'link';
		}
		return false !== $this->db->upsert_link( $post_id, $url, $anchor, $link_type, $source_key );
	}

	/**
	 * Scan a template / reusable block post (FSE).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $link_type template|wp_block.
	 * @return int
	 */
	private function scan_storage_post( $post_id, $link_type ) {
		$post_id   = absint( $post_id );
		$link_type = sanitize_key( (string) $link_type );
		if ( ! $post_id || ! in_array( $link_type, array( 'template', 'wp_block' ), true ) ) {
			return 0;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 0;
		}

		$prefix       = 'st-' . $post_id . '-';
		$allowed_keys = array();
		$found        = 0;

		foreach ( $this->extract_all_url_items( $post->post_content, $post->post_content ) as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				continue;
			}
			if ( ! $this->is_url_editable_in_post( $post_id, $url, $link_type ) ) {
				continue;
			}
			$sk             = $this->db->sanitize_source_key( $prefix . md5( $url ) );
			$allowed_keys[] = $sk;
			$anchor         = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : (string) $post->post_title;
			$this->db->upsert_link( $post_id, $url, $anchor, $link_type, $sk );
			$found++;
		}

		$this->db->delete_sources_not_in( $link_type, $prefix, $allowed_keys, $post_id );
		return $found;
	}

	/**
	 * Scan ACF Options pages into link_type=acf rows (post_id 0).
	 *
	 * @return int Links found (0 = none or end; SCAN_LOCK_BUSY when locked).
	 */
	public function scan_acf_options() {
		if ( ! $this->opt( 'scan_meta' ) || ! class_exists( 'TSOLIIN_Acf', false ) || ! TSOLIIN_Acf::is_plugin_active() ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'acfopt' ) ) {
			return self::SCAN_LOCK_BUSY;
		}

		$allowed = array();
		$found   = 0;
		foreach ( TSOLIIN_Acf::collect_options_items() as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				continue;
			}
			$sk = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
			if ( '' === $sk ) {
				continue;
			}
			$sk        = $this->db->sanitize_source_key( $sk );
			$allowed[] = $sk;
			$anchor    = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : __( 'ACF Options', 'tso-link-inspector' );
			$this->db->upsert_link( 0, $url, $anchor, 'acf', $sk );
			$found++;
		}

		$this->cleanup_stale_acf_option_links( $allowed );
		$this->release_source_scan_lock( 'acfopt' );
		return $found > 0 ? $found : 0;
	}

	/**
	 * Drop Options-page rows whose source_key was not in the latest scan.
	 *
	 * @param string[] $allowed_keys Valid source keys.
	 * @return void
	 */
	private function cleanup_stale_acf_option_links( array $allowed_keys ) {
		global $wpdb;
		$table = $this->db->get_table();
		$keep  = array();
		foreach ( $allowed_keys as $key ) {
			$key = $this->db->sanitize_source_key( (string) $key );
			if ( '' !== $key ) {
				$keep[ $key ] = true;
			}
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_key FROM {$table} WHERE link_type = %s AND post_id = 0",
				'acf'
			)
		);
		// phpcs:enable
		if ( empty( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$sk = isset( $row->source_key ) ? (string) $row->source_key : '';
			if ( isset( $keep[ $sk ] ) ) {
				continue;
			}
			$this->db->delete_link( (int) $row->id );
		}
	}

	/**
	 * Scan every registered widget and drop rows for deleted instances.
	 *
	 * @return int Links found.
	 */
	public function scan_all_widgets() {
		if ( ! $this->is_scan_widgets_enabled() ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'widgets' ) ) {
			return self::SCAN_LOCK_BUSY;
		}
		$found = 0;
		foreach ( $this->get_registered_widget_instances() as $instance ) {
			$found += $this->scan_widget_instance( $instance['sidebar_id'], $instance['widget_id'] );
		}
		$this->cleanup_stale_widget_links();
		update_option( 'tsoliin_widget_scan_after_index', 0, false );
		$this->release_source_scan_lock( 'widgets' );
		return $found;
	}

	/**
	 * Re-scan the widget that owns a DB row; delete the row if the widget or URL is gone.
	 *
	 * @param object $link DB row.
	 * @return int Updated row ID, or 0 if removed.
	 */
	public function rescan_widget_link( $link ) {
		if ( ! $link || empty( $link->id ) ) {
			return 0;
		}
		$original = $link;
		$link_id  = (int) $link->id;
		$sk       = isset( $link->source_key ) ? (string) $link->source_key : '';
		$resolved = $this->resolve_widget_from_source_key( $sk );
		if ( $resolved ) {
			$this->scan_widget_instance( $resolved['sidebar_id'], $resolved['widget_id'] );
		}
		$row = $this->find_link_row_after_rescan( $original );
		if ( $row ) {
			return (int) $row->id;
		}
		$link = $this->db->get_link( $link_id );
		if ( $link && $this->is_stale_widget_link_row( $link ) ) {
			$this->db->delete_link( $link_id );
		}
		return 0;
	}

	/**
	 * Whether a stored widget row no longer matches a registered instance or its URL.
	 *
	 * @param object $row DB row (id, source_key, link_url).
	 * @return bool
	 */
	private function is_stale_widget_link_row( $row ) {
		if ( ! $row ) {
			return false;
		}
		$sk       = isset( $row->source_key ) ? (string) $row->source_key : '';
		$resolved = $this->resolve_widget_from_source_key( $sk );
		if ( ! $resolved ) {
			return true;
		}
		$url = isset( $row->link_url ) ? (string) $row->link_url : '';
		if ( '' === $url ) {
			return true;
		}
		return ! $this->widget_instance_contains_url( $resolved['widget_id'], $url );
	}

	/**
	 * Delete widget rows whose widget instance or URL is no longer present.
	 *
	 * @return int Rows deleted.
	 */
	public function cleanup_stale_widget_links() {
		global $wpdb;
		$table = $this->db->get_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$rows = $wpdb->get_results(
			"SELECT id, source_key, link_url FROM {$table} WHERE link_type = 'widget'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $rows ) ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			if ( ! $this->is_stale_widget_link_row( $row ) ) {
				continue;
			}
			$this->db->delete_link( (int) $row->id );
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * Delete widget rows whose source_key no longer maps to a registered widget.
	 *
	 * @return int Rows deleted.
	 */
	public function cleanup_orphan_widget_links() {
		return $this->cleanup_stale_widget_links();
	}

	/**
	 * @return array<int, array{sidebar_id:string,widget_id:string}>
	 */
	private function get_registered_widget_instances() {
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return array();
		}
		/** This filter is documented in wp-includes/widgets.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sidebars = apply_filters( 'sidebars_widgets', $sidebars );

		$instances = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( ! is_string( $widget_id ) || '' === $widget_id ) {
					continue;
				}
				$instances[] = array(
					'sidebar_id' => (string) $sidebar_id,
					'widget_id'  => (string) $widget_id,
				);
			}
		}
		return $instances;
	}

	/**
	 * Scan one widget instance and prune stale rows for that instance.
	 *
	 * @param string $sidebar_id Sidebar ID.
	 * @param string $widget_id  Widget instance ID.
	 * @return int Links found.
	 */
	private function scan_widget_instance( $sidebar_id, $widget_id ) {
		$sidebar_id = (string) $sidebar_id;
		$widget_id  = (string) $widget_id;
		if ( '' === $sidebar_id || '' === $widget_id ) {
			return 0;
		}

		$content = $this->get_widget_instance_content( $widget_id );
		$prefix  = $this->db->sanitize_source_key( 'wg-' . $sidebar_id . '-' . $widget_id . '-' );
		$allowed = array();
		$found   = 0;

		if ( '' !== $content ) {
			/* translators: 1: sidebar ID, 2: widget ID */
			$default_anchor = sprintf( __( 'Widget %1$s / %2$s', 'tso-link-inspector' ), $sidebar_id, $widget_id );
			foreach ( $this->extract_all_url_items( $content, $content ) as $item ) {
				$url = isset( $item['url'] ) ? (string) $item['url'] : '';
				if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
					continue;
				}
				$sk        = $this->db->sanitize_source_key( $prefix . md5( $url ) );
				$allowed[] = $sk;
				$anchor    = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : $default_anchor;
				$this->db->upsert_link( 0, $url, $anchor, 'widget', $sk );
				$found++;
			}
		}

		$this->db->delete_sources_not_in( 'widget', $prefix, $allowed, 0 );
		return $found;
	}

	/**
	 * Whether a URL is still present in a widget instance.
	 *
	 * @param string $widget_id Widget instance ID.
	 * @param string $url       Stored URL.
	 * @return bool
	 */
	private function widget_instance_contains_url( $widget_id, $url ) {
		$content = $this->get_widget_instance_content( (string) $widget_id );
		if ( '' === $content || '' === trim( (string) $url ) ) {
			return false;
		}
		$target_key = $this->scan_url_key( (string) $url, 0 );
		foreach ( $this->extract_all_url_items( $content, $content ) as $item ) {
			$candidate = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $candidate ) {
				continue;
			}
			if ( $this->scan_url_key( $candidate, 0 ) === $target_key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan widget sidebars (classic + block widgets).
	 *
	 * @param int $per_page Max widget instances per call.
	 * @return int
	 */
	public function scan_widgets_batch( $per_page = 30 ) {
		if ( ! $this->is_scan_widgets_enabled() ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'widgets' ) ) {
			return self::SCAN_LOCK_BUSY;
		}

		$flat = $this->get_registered_widget_instances();
		if ( empty( $flat ) ) {
			update_option( 'tsoliin_widget_scan_after_index', 0, false );
			$this->cleanup_stale_widget_links();
			$this->release_source_scan_lock( 'widgets' );
			return 0;
		}

		$per_page    = max( 1, absint( $per_page ) );
		$after_index = absint( get_option( 'tsoliin_widget_scan_after_index', 0 ) );
		$slice       = array_slice( $flat, $after_index, $per_page );
		if ( empty( $slice ) ) {
			update_option( 'tsoliin_widget_scan_after_index', 0, false );
			$this->cleanup_stale_widget_links();
			$this->release_source_scan_lock( 'widgets' );
			return 0;
		}

		$found = 0;
		foreach ( $slice as $instance ) {
			$found += $this->scan_widget_instance( $instance['sidebar_id'], $instance['widget_id'] );
		}

		$next_index = $after_index + count( $slice );
		$cycle_done = ( $next_index >= count( $flat ) );
		if ( $cycle_done ) {
			update_option( 'tsoliin_widget_scan_after_index', 0, false );
			$this->cleanup_stale_widget_links();
		} else {
			update_option( 'tsoliin_widget_scan_after_index', $next_index, false );
		}
		$this->release_source_scan_lock( 'widgets' );
		if ( $found > 0 ) {
			return $found;
		}
		return $cycle_done ? 0 : self::SCAN_BATCH_PROGRESS;
	}

	/**
	 * Comment ID from a stored source_key (c-123-…).
	 *
	 * @param string $source_key DB source_key.
	 * @param int    $post_id    Fallback post ID (unused).
	 * @return int
	 */
	private function comment_id_from_source_key( $source_key, $post_id = 0 ) {
		unset( $post_id );
		if ( preg_match( '/^c-(\d+)/', (string) $source_key, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Widget instance content from source_key.
	 *
	 * @param string $source_key DB source_key.
	 * @return string
	 */
	private function get_widget_content_by_source_key( $source_key ) {
		$resolved = $this->resolve_widget_from_source_key( $source_key );
		if ( ! $resolved ) {
			return '';
		}
		return $this->get_widget_instance_content( $resolved['widget_id'] );
	}

	/**
	 * Locate a widget instance from a stored source_key (wg-{sidebar}-{widget}-{hash}).
	 *
	 * @param string $source_key DB source_key.
	 * @return array{sidebar_id:string,widget_id:string}|null
	 */
	public function resolve_widget_from_source_key( $source_key ) {
		$source_key = (string) $source_key;
		if ( 0 !== strpos( $source_key, 'wg-' ) ) {
			return null;
		}
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return null;
		}
		/** This filter is documented in wp-includes/widgets.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sidebars = apply_filters( 'sidebars_widgets', $sidebars );

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				$prefix = $this->db->sanitize_source_key( 'wg-' . $sidebar_id . '-' . $widget_id . '-' );
				if ( 0 === strpos( $source_key, $prefix ) ) {
					return array(
						'sidebar_id' => (string) $sidebar_id,
						'widget_id'  => (string) $widget_id,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Replace a URL inside a widget instance.
	 *
	 * @param string $source_key Widget source_key.
	 * @param string $old_url    Stored URL.
	 * @param string $new_url    New URL.
	 * @return bool
	 */
	public function replace_url_in_widget( $source_key, $old_url, $new_url ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$resolved = $this->resolve_widget_from_source_key( $source_key );
		if ( ! $resolved ) {
			return false;
		}
		$content = $this->get_widget_instance_content( $resolved['widget_id'] );
		if ( '' === $content ) {
			return false;
		}
		$new_content = $this->replace_url_in_html_attributes( $content, (string) $old_url, (string) $new_url, 0 );
		if ( null === $new_content ) {
			$new_content = $this->replace_plain_url_in_text( $content, (string) $old_url, (string) $new_url, 0 );
		}
		if ( null === $new_content || $new_content === $content ) {
			return false;
		}
		return $this->save_widget_instance_content( $resolved['widget_id'], $new_content );
	}

	/**
	 * Replace anchor text inside a widget instance.
	 *
	 * @param string $source_key Widget source_key.
	 * @param string $url        href URL to match.
	 * @param string $new_anchor New visible text.
	 * @return bool
	 */
	public function replace_anchor_in_widget( $source_key, $url, $new_anchor ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$resolved = $this->resolve_widget_from_source_key( $source_key );
		if ( ! $resolved ) {
			return false;
		}
		$content = $this->get_widget_instance_content( $resolved['widget_id'] );
		if ( '' === $content ) {
			return false;
		}
		$new_anchor = sanitize_text_field( (string) $new_anchor );
		if ( '' === $new_anchor ) {
			return false;
		}
		$variants = array_unique( array_filter( array_merge(
			$this->build_url_replace_candidates( (string) $url, 0 ),
			array( urldecode( (string) $url ), str_replace( '&', '&amp;', (string) $url ) )
		) ) );
		foreach ( $variants as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$pattern     = '#(<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>)(.*?)(</a>)#is';
			$new_content = preg_replace( $pattern, '$1' . esc_html( $new_anchor ) . '$3', $content, 1, $count );
			if ( $count > 0 && is_string( $new_content ) && $new_content !== $content ) {
				return $this->save_widget_instance_content( $resolved['widget_id'], $new_content );
			}
		}
		return false;
	}

	/**
	 * Remove a link from widget HTML content.
	 *
	 * @param string $source_key Widget source_key.
	 * @param string $url        URL to remove.
	 * @return bool
	 */
	public function unlink_in_widget( $source_key, $url ) {
		return $this->unlink_url_in_widget_html( $source_key, $url );
	}

	/**
	 * @param string $source_key Widget source_key.
	 * @param string $url        URL.
	 * @return bool
	 */
	private function unlink_url_in_widget_html( $source_key, $url ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$resolved = $this->resolve_widget_from_source_key( $source_key );
		if ( ! $resolved ) {
			return false;
		}
		$content = $this->get_widget_instance_content( $resolved['widget_id'] );
		if ( '' === $content ) {
			return false;
		}
		$variants = $this->build_url_replace_candidates( (string) $url, 0 );
		$changed  = false;
		foreach ( $variants as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$pattern = '#<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>(.*?)</a>#is';
			$next    = preg_replace( $pattern, '$1', $content, 1, $count );
			if ( $count > 0 && is_string( $next ) && $next !== $content ) {
				$content = $next;
				$changed = true;
				break;
			}
		}
		return $changed && $this->save_widget_instance_content( $resolved['widget_id'], $content );
	}

	/**
	 * @param string $widget_id Widget instance ID.
	 * @param string $content   New HTML/text content for the widget field.
	 * @return bool
	 */
	private function save_widget_instance_content( $widget_id, $content ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			return false;
		}
		$type   = (string) $m[1];
		$number = absint( $m[2] );
		$option = get_option( 'widget_' . $type );
		if ( ! is_array( $option ) || empty( $option[ $number ] ) || ! is_array( $option[ $number ] ) ) {
			return false;
		}
		if ( 'block' === $type || 'custom_html' === $type ) {
			$option[ $number ]['content'] = (string) $content;
		} elseif ( 'text' === $type ) {
			$option[ $number ]['text'] = (string) $content;
		} else {
			$updated = false;
			foreach ( $option[ $number ] as $key => $val ) {
				if ( is_string( $val ) && ( false !== stripos( $val, 'http' ) || false !== stripos( $val, 'href=' ) ) ) {
					$option[ $number ][ $key ] = (string) $content;
					$updated                   = true;
					break;
				}
			}
			if ( ! $updated ) {
				return false;
			}
		}
		return update_option( 'widget_' . $type, $option );
	}

	/**
	 * @param string $source_key mi-{item_id} source key.
	 * @return int Menu item post ID or 0.
	 */
	private function menu_item_id_from_source_key( $source_key ) {
		if ( preg_match( '/^mi-(\d+)/', (string) $source_key, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Replace a custom menu item URL.
	 *
	 * @param string $source_key Menu source_key.
	 * @param string $old_url    Stored URL.
	 * @param string $new_url    New URL.
	 * @return bool
	 */
	public function replace_url_in_menu_item( $source_key, $old_url, $new_url ) {
		$item_id = $this->menu_item_id_from_source_key( $source_key );
		if ( ! $item_id ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $item_id ) && ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$stored = (string) get_post_meta( $item_id, '_menu_item_url', true );
		if ( '' === $stored && get_post( $item_id ) ) {
			$item = wp_setup_nav_menu_item( get_post( $item_id ) );
			$stored = ( $item && ! empty( $item->url ) ) ? (string) $item->url : '';
		}
		if ( '' === $stored || ! $this->comment_author_url_matches_row_url( $stored, (string) $old_url ) ) {
			return false;
		}
		update_post_meta( $item_id, '_menu_item_url', $this->clean_url( (string) $new_url ) );
		return true;
	}

	/**
	 * Update menu item label (anchor).
	 *
	 * @param string $source_key Menu source_key.
	 * @param string $new_anchor New label.
	 * @return bool
	 */
	public function replace_anchor_in_menu_item( $source_key, $new_anchor ) {
		$item_id = $this->menu_item_id_from_source_key( $source_key );
		$new_anchor = sanitize_text_field( (string) $new_anchor );
		if ( ! $item_id || '' === $new_anchor ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $item_id ) && ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$menu_post = get_post( $item_id );
		if ( ! $menu_post ) {
			return false;
		}
		$s              = get_option( 'tsoliin_settings', array() );
		$preserve_dates = ! empty( $s['preserve_dates'] );
		if ( $preserve_dates ) {
			$this->preserve_modified_context = array(
				'ID'                => $item_id,
				'post_modified'     => (string) $menu_post->post_modified,
				'post_modified_gmt' => (string) $menu_post->post_modified_gmt,
			);
			add_filter( 'wp_insert_post_data', array( $this, 'filter_preserve_post_modified' ), 10, 2 );
		}
		try {
			$updated = wp_update_post(
				array(
					'ID'         => $item_id,
					'post_title' => $new_anchor,
				),
				true
			);
		} finally {
			if ( $preserve_dates ) {
				remove_filter( 'wp_insert_post_data', array( $this, 'filter_preserve_post_modified' ), 10 );
				$this->preserve_modified_context = null;
			}
		}
		return ! is_wp_error( $updated ) && $updated;
	}

	/**
	 * Remove a custom menu item URL (keeps the menu entry).
	 *
	 * @param string $source_key Menu source_key.
	 * @param string $url        Stored URL.
	 * @return bool
	 */
	public function unlink_in_menu_item( $source_key, $url ) {
		return $this->replace_url_in_menu_item( $source_key, $url, '#' );
	}

	/**
	 * @param string $source_key t-{term_id}-… source key.
	 * @return int Term ID or 0.
	 */
	private function term_id_from_source_key( $source_key ) {
		if ( preg_match( '/^t-(\d+)-/', (string) $source_key, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Replace a URL in a taxonomy term description.
	 *
	 * @param string $source_key Term source_key.
	 * @param string $old_url    Stored URL.
	 * @param string $new_url    New URL.
	 * @return bool
	 */
	public function replace_url_in_term( $source_key, $old_url, $new_url ) {
		$term_id = $this->term_id_from_source_key( $source_key );
		if ( $term_id <= 0 || ! current_user_can( 'edit_term', $term_id ) ) {
			return false;
		}
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$desc = isset( $term->description ) ? (string) $term->description : '';
		if ( '' === $desc ) {
			return false;
		}
		$new_desc = $this->replace_url_in_html_attributes( $desc, (string) $old_url, (string) $new_url, 0 );
		if ( null === $new_desc ) {
			$new_desc = $this->replace_plain_url_in_text( $desc, (string) $old_url, (string) $new_url, 0 );
		}
		if ( null === $new_desc || $new_desc === $desc ) {
			return false;
		}
		return ! is_wp_error( wp_update_term( $term_id, $term->taxonomy, array( 'description' => $new_desc ) ) );
	}

	/**
	 * Replace anchor text in a term description.
	 *
	 * @param string $source_key Term source_key.
	 * @param string $url        href URL.
	 * @param string $new_anchor New text.
	 * @return bool
	 */
	public function replace_anchor_in_term( $source_key, $url, $new_anchor ) {
		$term_id = $this->term_id_from_source_key( $source_key );
		if ( $term_id <= 0 || ! current_user_can( 'edit_term', $term_id ) ) {
			return false;
		}
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$new_anchor = sanitize_text_field( (string) $new_anchor );
		if ( '' === $new_anchor ) {
			return false;
		}
		$desc = isset( $term->description ) ? (string) $term->description : '';
		foreach ( $this->build_url_replace_candidates( (string) $url, 0 ) as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$pattern  = '#(<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>)(.*?)(</a>)#is';
			$new_desc = preg_replace( $pattern, '$1' . esc_html( $new_anchor ) . '$3', $desc, 1, $count );
			if ( $count > 0 && is_string( $new_desc ) && $new_desc !== $desc ) {
				return ! is_wp_error( wp_update_term( $term_id, $term->taxonomy, array( 'description' => $new_desc ) ) );
			}
		}
		return false;
	}

	/**
	 * Remove a link from a term description.
	 *
	 * @param string $source_key Term source_key.
	 * @param string $url        URL to remove.
	 * @return bool
	 */
	public function unlink_in_term( $source_key, $url ) {
		$term_id = $this->term_id_from_source_key( $source_key );
		if ( $term_id <= 0 || ! current_user_can( 'edit_term', $term_id ) ) {
			return false;
		}
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$desc    = isset( $term->description ) ? (string) $term->description : '';
		$changed = false;
		foreach ( $this->build_url_replace_candidates( (string) $url, 0 ) as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$pattern  = '#<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>(.*?)</a>#is';
			$new_desc = preg_replace( $pattern, '$1', $desc, 1, $count );
			if ( $count > 0 && is_string( $new_desc ) && $new_desc !== $desc ) {
				$desc    = $new_desc;
				$changed = true;
				break;
			}
		}
		if ( ! $changed ) {
			return false;
		}
		return ! is_wp_error( wp_update_term( $term_id, $term->taxonomy, array( 'description' => $desc ) ) );
	}

	/**
	 * @param string $widget_id Widget instance ID (e.g. text-2, block-3).
	 * @return string
	 */
	private function get_widget_instance_content( $widget_id ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			return '';
		}
		$type    = (string) $m[1];
		$number  = absint( $m[2] );
		$option  = get_option( 'widget_' . $type );
		if ( ! is_array( $option ) || empty( $option[ $number ] ) || ! is_array( $option[ $number ] ) ) {
			return '';
		}
		$inst = $option[ $number ];
		if ( 'block' === $type && ! empty( $inst['content'] ) ) {
			return (string) $inst['content'];
		}
		if ( 'text' === $type && ! empty( $inst['text'] ) ) {
			return (string) $inst['text'];
		}
		if ( 'custom_html' === $type && ! empty( $inst['content'] ) ) {
			return (string) $inst['content'];
		}
		// Media Image / Gallery widgets often store attachment IDs.
		if ( isset( $inst['attachment_id'] ) && absint( $inst['attachment_id'] ) > 0 ) {
			$url = wp_get_attachment_url( absint( $inst['attachment_id'] ) );
			if ( is_string( $url ) && '' !== $url ) {
				return '<img src="' . esc_url( $url ) . '" />';
			}
		}
		if ( ! empty( $inst['url'] ) && is_string( $inst['url'] ) && $this->looks_like_link_url( $inst['url'] ) ) {
			return (string) $inst['url'];
		}
		if ( ! empty( $inst['link'] ) && is_string( $inst['link'] ) && $this->looks_like_link_url( $inst['link'] ) ) {
			return (string) $inst['link'];
		}
		$chunks = array();
		foreach ( $inst as $val ) {
			if ( is_string( $val ) && (
				false !== stripos( $val, 'http' )
				|| false !== stripos( $val, 'href=' )
				|| false !== stripos( $val, 'src=' )
				|| preg_match( '#(?:^|["\'\s])/(?!/)#', $val )
			) ) {
				$chunks[] = $val;
			}
		}
		return ! empty( $chunks ) ? implode( "\n", $chunks ) : '';
	}

	/**
	 * Scan taxonomy term descriptions for links.
	 *
	 * @param int $per_page Max terms per call.
	 * @return int
	 */
	public function scan_terms_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_terms', true ) ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'terms' ) ) {
			return self::SCAN_LOCK_BUSY;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_term_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, tt.description FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.term_id > %d AND tt.description != ''
				AND (
					tt.description LIKE %s
					OR tt.description LIKE %s
					OR tt.description LIKE %s
					OR tt.description LIKE %s
				)
				ORDER BY t.term_id ASC LIMIT %d",
				$after_id,
				'%href=%',
				'%src=%',
				'%http://%',
				'%https://%',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_term_scan_after_id', 0, false );
			$this->release_source_scan_lock( 'terms' );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$term_id = absint( $row->term_id );
			if ( $term_id ) {
				$max_id = max( $max_id, $term_id );
			}
			$found += $this->scan_term( $term_id );
		}

		update_option( 'tsoliin_term_scan_after_id', $max_id, false );
		$this->release_source_scan_lock( 'terms' );
		return $found > 0 ? $found : self::SCAN_BATCH_PROGRESS;
	}

	/**
	 * Scan FSE templates, template parts, reusable blocks, and Navigation menus.
	 *
	 * @param int $per_page Max posts per call.
	 * @return int
	 */
	public function scan_fse_batch( $per_page = 20 ) {
		if ( ! $this->opt( 'scan_fse', true ) ) {
			return 0;
		}
		if ( ! $this->acquire_source_scan_lock( 'fse' ) ) {
			return self::SCAN_LOCK_BUSY;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_fse_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND ID > %d AND post_type IN ( %s, %s, %s, %s )
				ORDER BY ID ASC LIMIT %d",
				$after_id,
				'wp_template',
				'wp_template_part',
				'wp_block',
				'wp_navigation',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_fse_scan_after_id', 0, false );
			$this->release_source_scan_lock( 'fse' );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$post_id = absint( $row->ID );
			if ( $post_id ) {
				$max_id = max( $max_id, $post_id );
			}
			$ptype     = (string) $row->post_type;
			$link_type = ( 'wp_block' === $ptype ) ? 'wp_block' : 'template';
			$found    += $this->scan_storage_post( $post_id, $link_type );
		}

		update_option( 'tsoliin_fse_scan_after_id', $max_id, false );
		$this->release_source_scan_lock( 'fse' );
		return $found > 0 ? $found : self::SCAN_BATCH_PROGRESS;
	}

}
