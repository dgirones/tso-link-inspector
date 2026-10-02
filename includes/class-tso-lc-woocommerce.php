<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Optional WooCommerce product link sources.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_WooCommerce
 */
class TSOLIIN_WooCommerce {

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_plugin_active() {
		if ( class_exists( 'WooCommerce', false ) ) {
			return true;
		}
		if ( defined( 'WC_PLUGIN_FILE' ) ) {
			return true;
		}
		return function_exists( 'WC' );
	}

	/**
	 * Whether the opt-in WooCommerce scan setting is enabled.
	 *
	 * @return bool
	 */
	public static function is_scan_enabled() {
		if ( ! self::is_plugin_active() ) {
			return false;
		}
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s['scan_woocommerce'] );
	}

	/**
	 * Whether a post is a WooCommerce product.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_product( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::is_plugin_active() ) {
			return false;
		}
		return 'product' === get_post_type( $post_id );
	}

	/**
	 * Whether a stored source_key belongs to the WooCommerce module.
	 *
	 * @param string $source_key DB source_key.
	 * @return bool
	 */
	public static function is_woocommerce_source_key( $source_key ) {
		return 0 === strpos( (string) $source_key, 'wc-' );
	}

	/**
	 * Meta keys handled by this module (excluded from generic meta scan).
	 *
	 * @return string[]
	 */
	public static function dedicated_meta_keys() {
		return array(
			'_product_url',
			'_downloadable_files',
			'_product_image_gallery',
		);
	}

	/**
	 * Collect WooCommerce-specific links for a product (and its variations).
	 *
	 * @param int $product_id Product post ID.
	 * @return array[] Items: url, anchor, type, source_key.
	 */
	public static function collect_product_items( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! self::is_scan_enabled() || ! self::is_product( $product_id ) ) {
			return array();
		}

		$items = self::collect_fields_for_post( $product_id, $product_id, '' );

		$variation_ids = self::get_variation_ids( $product_id );
		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $variation_id ) {
				$variation_id = absint( $variation_id );
				if ( $variation_id <= 0 ) {
					continue;
				}
				$items = array_merge(
					$items,
					self::collect_fields_for_post( $variation_id, $product_id, 'v' . $variation_id . '-' )
				);
			}
		}

		return $items;
	}

	/**
	 * Variation IDs for a product (paged; never loads unbounded rows in one query).
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private static function get_variation_ids( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		$ids      = array();
		$page     = 1;
		$per_page = 200;
		do {
			$batch = get_posts(
				array(
					'post_parent'            => $product_id,
					'post_type'              => 'product_variation',
					'post_status'            => array( 'publish', 'private' ),
					'posts_per_page'         => $per_page,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}
			foreach ( $batch as $vid ) {
				$vid = absint( $vid );
				if ( $vid > 0 ) {
					$ids[] = $vid;
				}
			}
			++$page;
			$batch_count = count( $batch );
		} while ( $per_page === $batch_count );

		return $ids;
	}

	/**
	 * Collect product/variation field URLs, stored against the parent product ID.
	 *
	 * @param int    $source_post_id Post that owns the meta (product or variation).
	 * @param int    $store_post_id  Inspector post_id (always the parent product).
	 * @param string $key_prefix     Extra source_key segment (e.g. v123-).
	 * @return array[]
	 */
	private static function collect_fields_for_post( $source_post_id, $store_post_id, $key_prefix = '' ) {
		$source_post_id = absint( $source_post_id );
		$store_post_id  = absint( $store_post_id );
		$key_prefix     = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $key_prefix ) );
		$items          = array();

		$external = get_post_meta( $source_post_id, '_product_url', true );
		if ( is_string( $external ) ) {
			$external = trim( $external );
			if ( self::looks_like_url( $external ) ) {
				$items[] = array(
					'url'        => $external,
					'anchor'     => __( 'WooCommerce: External product URL', 'tso-link-inspector' ),
					'type'       => 'link',
					'source_key' => 'wc-' . $store_post_id . '-' . $key_prefix . 'purl',
				);
			}
		}

		$files = get_post_meta( $source_post_id, '_downloadable_files', true );
		if ( is_array( $files ) ) {
			foreach ( $files as $file_key => $file ) {
				if ( ! is_array( $file ) || empty( $file['file'] ) || ! is_string( $file['file'] ) ) {
					continue;
				}
				$file_url = trim( (string) $file['file'] );
				if ( ! self::looks_like_url( $file_url ) ) {
					continue;
				}
				$name = ! empty( $file['name'] ) ? sanitize_text_field( (string) $file['name'] ) : '';
				if ( '' !== $name ) {
					/* translators: %s: download file name. */
					$anchor = sprintf( __( 'WooCommerce download: %s', 'tso-link-inspector' ), $name );
				} else {
					$anchor = __( 'WooCommerce: Downloadable file', 'tso-link-inspector' );
				}
				// Stable key from Woo's file hash (not the URL), so URL edits update the same row.
				$file_id = sanitize_key( (string) $file_key );
				if ( '' === $file_id ) {
					$file_id = substr( md5( $file_url ), 0, 12 );
				}
				$items[] = array(
					'url'        => $file_url,
					'anchor'     => $anchor,
					'type'       => 'link',
					'source_key' => 'wc-' . $store_post_id . '-' . $key_prefix . 'dl-' . $file_id,
				);
			}
		}

		$thumb_id = (int) get_post_thumbnail_id( $source_post_id );
		if ( $thumb_id > 0 ) {
			$file_url = wp_get_attachment_url( $thumb_id );
			if ( is_string( $file_url ) && '' !== $file_url ) {
				$items[] = array(
					'url'        => $file_url,
					'anchor'     => __( 'WooCommerce: Featured image', 'tso-link-inspector' ),
					'type'       => 'image',
					'source_key' => 'wc-' . $store_post_id . '-' . $key_prefix . 'feat',
				);
			}
		}

		foreach ( self::gallery_attachment_ids( $source_post_id ) as $attachment_id ) {
			if ( $attachment_id <= 0 || $attachment_id === $thumb_id ) {
				continue;
			}
			$file_url = wp_get_attachment_url( $attachment_id );
			if ( ! is_string( $file_url ) || '' === $file_url ) {
				continue;
			}
			$items[] = array(
				'url'        => $file_url,
				'anchor'     => __( 'WooCommerce: Gallery image', 'tso-link-inspector' ),
				'type'       => 'image',
				'source_key' => 'wc-' . $store_post_id . '-' . $key_prefix . 'gal-' . $attachment_id,
			);
		}

		return $items;
	}

	/**
	 * Gallery attachment IDs from product meta (string or array).
	 *
	 * @param int $post_id Product or variation ID.
	 * @return int[]
	 */
	private static function gallery_attachment_ids( $post_id ) {
		$gallery = get_post_meta( absint( $post_id ), '_product_image_gallery', true );
		if ( is_array( $gallery ) ) {
			return array_values( array_filter( array_map( 'absint', $gallery ) ) );
		}
		if ( is_string( $gallery ) && '' !== $gallery ) {
			return array_values( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) );
		}
		return array();
	}

	/**
	 * Whether a URL is still present in WooCommerce product fields.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        Stored URL.
	 * @return bool
	 */
	public static function product_has_url( $product_id, $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! self::is_product( $product_id ) ) {
			return false;
		}
		return self::raw_meta_has_url( $product_id, $url );
	}

	/**
	 * Raw meta presence (product + variations).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        URL.
	 * @return bool
	 */
	private static function raw_meta_has_url( $product_id, $url ) {
		$post_ids      = array( absint( $product_id ) );
		$variation_ids = self::get_variation_ids( $product_id );
		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $vid ) {
				$post_ids[] = absint( $vid );
			}
		}

		foreach ( $post_ids as $pid ) {
			if ( $pid <= 0 ) {
				continue;
			}
			$external = get_post_meta( $pid, '_product_url', true );
			if ( is_string( $external ) && self::urls_loosely_equal( $external, $url ) ) {
				return true;
			}
			$files = get_post_meta( $pid, '_downloadable_files', true );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_array( $file ) && ! empty( $file['file'] ) && self::urls_loosely_equal( (string) $file['file'], $url ) ) {
						return true;
					}
				}
			}
			$thumb_id = (int) get_post_thumbnail_id( $pid );
			if ( $thumb_id > 0 ) {
				$file_url = wp_get_attachment_url( $thumb_id );
				if ( is_string( $file_url ) && self::urls_loosely_equal( $file_url, $url ) ) {
					return true;
				}
			}
			foreach ( self::gallery_attachment_ids( $pid ) as $attachment_id ) {
				$file_url = wp_get_attachment_url( $attachment_id );
				if ( is_string( $file_url ) && self::urls_loosely_equal( $file_url, $url ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Looks like URL.
	 *
	 * @param string $url Candidate.
	 * @return bool
	 */
	private static function looks_like_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $url ) || 0 === strpos( $url, '//' ) ) {
			return true;
		}
		return (bool) preg_match( '#^(?:/|\./|\.\./)#', $url );
	}

	/**
	 * URLs loosely equal.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private static function urls_loosely_equal( $a, $b ) {
		$a = strtolower( rtrim( trim( (string) $a ), '/' ) );
		$b = strtolower( rtrim( trim( (string) $b ), '/' ) );
		return '' !== $a && $a === $b;
	}
}
