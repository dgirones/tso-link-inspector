<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Link quality helpers (anchor text, unpublished targets).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Quality
 */
class TSOLIIN_Quality {

	/**
	 * Lowercase generic anchor phrases (EN / ES / CA).
	 *
	 * @return string[]
	 */
	public static function get_generic_anchor_phrases() {
		// Exact match after trim + lowercase. Prefer multi-word phrases; avoid
		// ambiguous singles such as "web", "entrar", or "visitar".
		$phrases = array(
			// English.
			'click here',
			'click this link',
			'click me',
			'click the link',
			'read more',
			'here',
			'link',
			'more',
			'continue',
			'go',
			'download',
			'this link',
			'website',
			'more info',
			'more information',
			'learn more',
			'see more',
			'view more',
			'find out more',
			'read on',
			'continue reading',
			'more details',
			'full story',
			'full article',
			'tap here',
			'follow this link',
			'download here',
			'download now',
			// Spanish (accented + unaccented).
			'aquí',
			'aqui',
			'pincha aquí',
			'pincha aqui',
			'haz clic aquí',
			'haz clic aqui',
			'haz click aquí',
			'haz click aqui',
			'clic aquí',
			'clic aqui',
			'pulsa aquí',
			'pulsa aqui',
			'pulse aquí',
			'pulse aqui',
			'leer más',
			'leer mas',
			'enlace',
			'más información',
			'mas informacion',
			'más info',
			'mas info',
			'continuar',
			'descargar',
			'ver más',
			'ver mas',
			'ver más información',
			'ver mas informacion',
			'saber más',
			'saber mas',
			'conoce más',
			'conoce mas',
			'más detalles',
			'mas detalles',
			'sigue leyendo',
			'continuar leyendo',
			'este enlace',
			'descarga aquí',
			'descarga aqui',
			'descargar aquí',
			'descargar aqui',
			// Catalan (accented + unaccented).
			'clica aquí',
			'clica aqui',
			'fes clic aquí',
			'fes clic aqui',
			'més informació',
			'mes informacio',
			'més info',
			'mes info',
			'enllaç',
			'enllac',
			'llegeix més',
			'llegeix mes',
			'veure més',
			'veure mes',
			'saber-ne més',
			'saber-ne mes',
			'saberne més',
			'saberne mes',
			'més detalls',
			'mes detalls',
			'continua llegint',
			'aquest enllaç',
			'aquest enllac',
			'descarregar',
			'descarrega aquí',
			'descarrega aqui',
		);

		/**
		 * Filter generic anchor phrases used by quality filters and exports.
		 *
		 * @param string[] $phrases Lowercase phrases.
		 */
		$phrases = apply_filters( 'tsoliin_generic_anchor_phrases', $phrases );

		return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $phrases ) ) ) );
	}

	/**
	 * Whether anchor text is empty for quality filtering.
	 *
	 * Plain pasted URLs (YouTube, etc.) are excluded — they have no separate anchor by design.
	 *
	 * @param string $anchor   Anchor text.
	 * @param string $link_url Link URL (optional, for URL-only exceptions).
	 * @return bool
	 */
	public static function is_empty_anchor( $anchor, $link_url = '' ) {
		if ( '' !== trim( wp_strip_all_tags( (string) $anchor ) ) ) {
			return false;
		}
		return ! self::is_url_only_expected_link( $link_url );
	}

	/**
	 * Links where an empty anchor is normal (pasted URL / oEmbed), not a quality issue.
	 *
	 * @param string $url Link URL.
	 * @return bool
	 */
	public static function is_url_only_expected_link( $url ) {
		$url = strtolower( trim( (string) $url ) );
		if ( '' === $url ) {
			return false;
		}
		$markers = array(
			'youtu.be/',
			'youtube.com/',
			'youtube-nocookie.com/',
			'vimeo.com/',
			'dai.ly/',
		);
		foreach ( $markers as $marker ) {
			if ( false !== strpos( $url, $marker ) ) {
				return true;
			}
		}

		/**
		 * Filter hosts/patterns where an empty anchor is expected (plain pasted URLs).
		 *
		 * @param bool   $expected Default false.
		 * @param string $url      Link URL.
		 */
		return (bool) apply_filters( 'tsoliin_is_url_only_expected_link', false, $url );
	}

	/**
	 * SQL fragment excluding URL-only expected links from empty-anchor counts/filters.
	 *
	 * LIKE wildcards must be written as %% so $wpdb->prepare() does not treat %d in
	 * dai.ly (and similar host fragments) as integer placeholders. After prepare, %% becomes %.
	 *
	 * @param string $url_column Column name (e.g. l.link_url or link_url).
	 * @return string AND clauses.
	 */
	public static function build_empty_anchor_url_exclusion_sql( $url_column = 'link_url' ) {
		$col = preg_replace( '/[^a-zA-Z0-9_.]/', '', (string) $url_column );
		if ( '' === $col ) {
			$col = 'link_url';
		}
		return "{$col} NOT LIKE '%%youtu.be/%%' AND {$col} NOT LIKE '%%youtube.com/%%' AND {$col} NOT LIKE '%%youtube-nocookie.com/%%' AND {$col} NOT LIKE '%%vimeo.com/%%' AND {$col} NOT LIKE '%%dai.ly/%%'";
	}

	/**
	 * SQL fragment for empty anchor filter (aligned with is_empty_anchor()).
	 *
	 * @return string
	 */
	public static function build_empty_anchor_sql_where() {
		$excl = self::build_empty_anchor_url_exclusion_sql( 'l.link_url' );
		return " AND TRIM(COALESCE(l.anchor_text, '')) = '' AND l.user_verified = 0 AND {$excl}";
	}

	/**
	 * SQL CASE expression for counting empty anchors in aggregate queries.
	 *
	 * @return string
	 */
	public static function build_empty_anchor_count_expr() {
		$excl = self::build_empty_anchor_url_exclusion_sql( 'link_url' );
		return "SUM(CASE WHEN TRIM(COALESCE(anchor_text, '')) = '' AND user_verified=0 AND {$excl} THEN 1 ELSE 0 END)";
	}

	/**
	 * Whether anchor text matches a known generic phrase.
	 *
	 * @param string $anchor Anchor text.
	 * @return bool
	 */
	public static function is_generic_anchor( $anchor ) {
		$norm = strtolower( trim( wp_strip_all_tags( (string) $anchor ) ) );
		if ( '' === $norm ) {
			return false;
		}
		return in_array( $norm, self::get_generic_anchor_phrases(), true );
	}

	/**
	 * SQL fragment for generic anchor filter.
	 *
	 * @return array{where:string,params:array}
	 */
	public static function build_generic_anchor_sql() {
		$phrases = self::get_generic_anchor_phrases();
		if ( empty( $phrases ) ) {
			return array(
				'where'  => ' AND 1=0',
				'params' => array(),
			);
		}

		$parts  = array();
		$params = array();
		foreach ( $phrases as $phrase ) {
			$parts[]  = 'LOWER(TRIM(l.anchor_text)) = %s';
			$params[] = $phrase;
		}

		return array(
			'where'  => ' AND (' . implode( ' OR ', $parts ) . ') AND l.user_verified = 0',
			'params' => $params,
		);
	}

	/**
	 * SQL CASE expression for counting generic anchors in aggregate queries.
	 *
	 * @return array{expr:string,params:array}
	 */
	public static function build_generic_anchor_count_expr() {
		$phrases = self::get_generic_anchor_phrases();
		if ( empty( $phrases ) ) {
			return array(
				'expr'   => '0',
				'params' => array(),
			);
		}

		$parts  = array();
		$params = array();
		foreach ( $phrases as $phrase ) {
			$parts[]  = 'LOWER(TRIM(anchor_text)) = %s';
			$params[] = $phrase;
		}

		return array(
			'expr'   => 'SUM(CASE WHEN user_verified=0 AND (' . implode( ' OR ', $parts ) . ') THEN 1 ELSE 0 END)',
			'params' => $params,
		);
	}

	/**
	 * Whether a resolved target post is not publicly published.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return bool True when the target should appear in the unpublished filter.
	 */
	public static function target_is_unpublished_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post );
		}
		if ( ! $post ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $post ) ) {
			return false;
		}

		if ( 'attachment' === $post->post_type ) {
			return self::attachment_is_unpublished_target( $post );
		}

		return in_array( $post->post_status, array( 'draft', 'pending', 'private', 'future', 'trash' ), true );
	}

	/**
	 * Whether a media attachment should appear in the unpublished-target filter.
	 *
	 * @param WP_Post $post Attachment post.
	 * @return bool
	 */
	private static function attachment_is_unpublished_target( WP_Post $post ) {
		if ( 'trash' === $post->post_status ) {
			return true;
		}

		$file_url = wp_get_attachment_url( $post->ID );
		if ( ! is_string( $file_url ) || '' === $file_url ) {
			return true;
		}

		if ( ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) ) {
			return in_array( $post->post_status, array( 'draft', 'pending', 'private', 'future' ), true );
		}

		$parent_id = (int) $post->post_parent;
		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $parent ) ) {
			return false;
		}

		return in_array( $parent->post_status, array( 'draft', 'pending', 'private', 'future', 'trash' ), true );
	}

	/**
	 * Resolve an internal URL to the target post ID (attachments via attachment_id query).
	 *
	 * @param string $url Absolute URL.
	 * @return int
	 */
	public static function resolve_internal_target_post_id( $url ) {
		static $cache = array();

		$url = trim( (string) $url );
		if ( '' === $url ) {
			return 0;
		}

		$cache_key = md5( $url );
		if ( array_key_exists( $cache_key, $cache ) ) {
			return (int) $cache[ $cache_key ];
		}

		$attachment_id = TSOLIIN_HTTP::parse_attachment_id_from_url( $url );
		if ( $attachment_id > 0 ) {
			$post = get_post( $attachment_id );
			if ( $post && 'attachment' === $post->post_type ) {
				$cache[ $cache_key ] = $attachment_id;
				return $attachment_id;
			}
		}

		$post_id             = TSOLIIN_HTTP::internal_url_to_post_id( $url );
		$cache[ $cache_key ] = $post_id > 0 ? $post_id : 0;
		return (int) $cache[ $cache_key ];
	}

	/**
	 * Whether ?attachment_id=… points to a non-public post.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	private static function attachment_id_points_to_unpublished( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$att           = get_post( $attachment_id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return false;
		}
		if ( 'trash' === $att->post_status ) {
			return true;
		}
		if ( ! wp_get_attachment_url( $attachment_id ) ) {
			return true;
		}
		if ( in_array( $att->post_status, array( 'draft', 'pending', 'private', 'future' ), true ) ) {
			return true;
		}

		$parent_id = (int) $att->post_parent;
		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return false;
		}

		return in_array( $parent->post_status, array( 'draft', 'pending', 'private', 'future', 'trash' ), true );
	}

	/**
	 * Whether an internal link points to a non-published post.
	 *
	 * @param object|array $link Link row.
	 * @return bool
	 */
	public static function points_to_unpublished( $link ) {
		$link = (object) $link;
		if ( ! empty( $link->user_verified ) ) {
			return false;
		}

		$post_id  = isset( $link->post_id ) ? (int) $link->post_id : 0;
		$link_url = isset( $link->link_url ) ? (string) $link->link_url : '';
		if ( '' === $link_url || ! TSOLIIN_HTTP::is_internal_link_url( $link_url, $post_id ) ) {
			return false;
		}

		$abs = TSOLIIN_Scanner::resolve_to_absolute_url( $link_url, $post_id );
		if ( '' === $abs ) {
			return false;
		}

		$attachment_id = TSOLIIN_HTTP::parse_attachment_id_from_url( $abs );
		if ( $attachment_id > 0 ) {
			return self::attachment_id_points_to_unpublished( $attachment_id );
		}

		$target_id = self::resolve_internal_target_post_id( $abs );
		if ( ! $target_id ) {
			return false;
		}

		$target = get_post( $target_id );
		if ( ! $target ) {
			return false;
		}

		return self::target_is_unpublished_post( $target );
	}

	/**
	 * Resolved target post status for exports (internal links only).
	 *
	 * @param object $link Link row.
	 * @return string
	 */
	public static function get_target_post_status_label( $link ) {
		$post_id  = isset( $link->post_id ) ? (int) $link->post_id : 0;
		$link_url = isset( $link->link_url ) ? (string) $link->link_url : '';
		if ( ! TSOLIIN_HTTP::is_internal_link_url( $link_url, $post_id ) ) {
			return '';
		}
		$abs = TSOLIIN_Scanner::resolve_to_absolute_url( $link_url, $post_id );
		if ( '' === $abs ) {
			return '';
		}
		$target_id = self::resolve_internal_target_post_id( $abs );
		if ( ! $target_id ) {
			return '';
		}
		$target = get_post( $target_id );
		if ( ! $target ) {
			return '';
		}
		if ( 'attachment' === $target->post_type && wp_get_attachment_url( $target->ID ) ) {
			if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $target ) ) {
				return 'publish';
			}
			$parent_id = (int) $target->post_parent;
			if ( $parent_id > 0 ) {
				$parent = get_post( $parent_id );
				if ( $parent && ( function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $parent ) : 'publish' === $parent->post_status ) ) {
					return 'publish';
				}
			} elseif ( in_array( $target->post_status, array( 'inherit', 'publish' ), true ) ) {
				return 'publish';
			}
		}
		return (string) $target->post_status;
	}
}
