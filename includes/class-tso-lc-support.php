<?php
/**
 * Support / donation helpers (TSO brand).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Support
 */
class TSOLIIN_Support {

	/**
	 * Request-scoped cache: normalized image URL => attachment post ID (0 when unknown).
	 *
	 * @var array<string,int>
	 */
	private static $attachment_id_by_url = array();

	/**
	 * Request-scoped map of relative uploads path => attachment post ID.
	 * Built once via a single query (filtered on the indexed meta_key column)
	 * instead of one unindexed meta_value lookup per URL.
	 *
	 * @var array<string,int>|null
	 */
	private static $attached_file_map = null;

	/**
	 * Request-scoped cache for can_inline_edit_link() per link row.
	 *
	 * @var array<string,bool>
	 */
	private static $inline_edit_link_cache = array();

	/** @var array<string,bool> Request-scoped should_focus_link_in_post_content() results. */
	private static $focus_in_post_content_cache = array();

	/**
	 * Ko-fi donation URL shown in the admin UI.
	 *
	 * @return string
	 */
	public static function get_kofi_donate_url() {
		$default = 'https://ko-fi.com/deadko_cat';

		/**
		 * Filter the Ko-fi donation URL for TSO Link Inspector.
		 *
		 * @param string $url Donation page URL.
		 */
		return (string) apply_filters( 'tsoliin_kofi_donate_url', $default );
	}

	/**
	 * Locale-aware number for plain-text UI (no HTML &nbsp; entities).
	 *
	 * @param int $number Raw count.
	 * @return string
	 */
	public static function format_display_number( $number ) {
		$formatted = number_format_i18n( (int) $number );
		return html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Donate button label (translated via plugin text domain).
	 *
	 * @return string
	 */
	public static function get_donate_label() {
		return __( '☕ Support this plugin', 'tso-link-inspector' );
	}

	/**
	 * Donate link label for the Plugins screen row meta.
	 *
	 * @return string
	 */
	public static function get_donate_link_label() {
		return __( 'Donate', 'tso-link-inspector' );
	}

	/**
	 * Echo the donate link markup for admin screens.
	 */
	public static function render_donate_button() {
		?>
		<a class="tsoliin-donate-btn"
		   href="<?php echo esc_url( self::get_kofi_donate_url() ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( self::get_donate_label() ); ?>
		</a>
		<?php
	}

	/**
	 * Echo the day / night / auto theme toggle for admin screens.
	 *
	 * @return void
	 */
	public static function render_theme_toggle() {
		?>
		<button type="button" id="tsoliin-theme-toggle" class="tsoliin-theme-btn" aria-pressed="mixed" title="<?php echo esc_attr__( 'Auto mode', 'tso-link-inspector' ); ?>">
			<span class="tsoliin-theme-icon" aria-hidden="true">🌓</span>
			<span class="tsoliin-theme-label"><?php echo esc_html__( 'Auto mode', 'tso-link-inspector' ); ?></span>
		</button>
		<?php
	}

	/**
	 * WordPress site timezone string (Settings → General).
	 *
	 * @return string
	 */
	public static function theme_timezone_string() {
		if ( function_exists( 'wp_timezone_string' ) ) {
			return (string) wp_timezone_string();
		}
		return (string) get_option( 'timezone_string', '' );
	}

	/**
	 * Approximate lat/lng for sunrise/sunset from the site timezone.
	 *
	 * @return array{lat: float, lng: float}
	 */
	public static function theme_coords() {
		$tz = self::theme_timezone_string();

		$map = array(
			'Europe/Madrid'                  => array( 40.42, -3.70 ),
			'Europe/Andorra'                 => array( 42.51, 1.52 ),
			'Atlantic/Canary'                => array( 28.29, -16.63 ),
			'Europe/London'                  => array( 51.51, -0.13 ),
			'Europe/Paris'                   => array( 48.86, 2.35 ),
			'Europe/Berlin'                  => array( 52.52, 13.41 ),
			'Europe/Rome'                    => array( 41.90, 12.50 ),
			'Europe/Lisbon'                  => array( 38.72, -9.14 ),
			'Europe/Brussels'                => array( 50.85, 4.35 ),
			'Europe/Amsterdam'               => array( 52.37, 4.90 ),
			'Europe/Zurich'                  => array( 47.37, 8.54 ),
			'Europe/Vienna'                  => array( 48.21, 16.37 ),
			'America/Mexico_City'            => array( 19.43, -99.13 ),
			'America/New_York'               => array( 40.71, -74.01 ),
			'America/Chicago'                => array( 41.88, -87.63 ),
			'America/Denver'                 => array( 39.74, -104.99 ),
			'America/Los_Angeles'            => array( 34.05, -118.24 ),
			'America/Argentina/Buenos_Aires' => array( -34.60, -58.38 ),
			'America/Sao_Paulo'              => array( -23.55, -46.63 ),
			'America/Bogota'                 => array( 4.71, -74.07 ),
			'America/Lima'                   => array( -12.05, -77.04 ),
			'America/Santiago'               => array( -33.45, -70.67 ),
			'America/Caracas'                => array( 10.48, -66.90 ),
			'America/Guayaquil'              => array( -2.17, -79.92 ),
			'America/Panama'                 => array( 8.98, -79.52 ),
			'America/Costa_Rica'             => array( 9.93, -84.08 ),
			'America/Guatemala'              => array( 14.63, -90.51 ),
			'America/Havana'                 => array( 23.11, -82.37 ),
			'America/Puerto_Rico'            => array( 18.47, -66.11 ),
			'America/Santo_Domingo'          => array( 18.49, -69.93 ),
			'Asia/Tokyo'                     => array( 35.68, 139.69 ),
			'Australia/Sydney'               => array( -33.87, 151.21 ),
			'UTC'                            => array( 0.0, 0.0 ),
		);

		if ( isset( $map[ $tz ] ) ) {
			return array(
				'lat' => (float) $map[ $tz ][0],
				'lng' => (float) $map[ $tz ][1],
			);
		}

		if ( 0 === strpos( $tz, 'Europe/' ) ) {
			return array( 'lat' => 41.39, 'lng' => 2.17 );
		}
		if ( 0 === strpos( $tz, 'America/' ) ) {
			return array( 'lat' => 19.43, 'lng' => -99.13 );
		}
		if ( 0 === strpos( $tz, 'Atlantic/' ) ) {
			return array( 'lat' => 28.29, 'lng' => -16.63 );
		}
		if ( 0 === strpos( $tz, 'Asia/' ) ) {
			return array( 'lat' => 35.68, 'lng' => 139.69 );
		}
		if ( 0 === strpos( $tz, 'Australia/' ) || 0 === strpos( $tz, 'Pacific/' ) ) {
			return array( 'lat' => -33.87, 'lng' => 151.21 );
		}

		// Default: Iberian Peninsula.
		return array( 'lat' => 41.39, 'lng' => 2.17 );
	}

	/**
	 * Whether it is currently daytime for the site coords (sunrise–sunset).
	 *
	 * Used for the initial HTML data-theme before JS runs.
	 *
	 * @return bool
	 */
	public static function theme_is_daytime_now() {
		$coords = self::theme_coords();
		$lat    = (float) $coords['lat'];
		$lng    = (float) $coords['lng'];
		$hour   = (int) current_time( 'G' );
		if ( ! function_exists( 'date_sun_info' ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$info = date_sun_info( time(), $lat, $lng );
		if ( ! is_array( $info ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$rise = isset( $info['sunrise'] ) ? $info['sunrise'] : false;
		$set  = isset( $info['sunset'] ) ? $info['sunset'] : false;
		// Polar day/night: sunrise/sunset may be true/false instead of timestamps.
		if ( true === $rise ) {
			return true;
		}
		if ( false === $rise && false === $set ) {
			return false;
		}
		if ( ! is_int( $rise ) || ! is_int( $set ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$now = time();
		return ( $now >= $rise && $now < $set );
	}

	/**
	 * Whether the optional “Convert to /path” row and bulk actions are enabled.
	 *
	 * @return bool
	 */
	public static function is_relative_url_tool_enabled() {
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s['relative_url_tool'] );
	}

	/**
	 * Whether Convert to /path may run for this row (setting + URL + same sources as Edit link).
	 *
	 * Post content/meta, images, iframes, and custom menu URLs only — not comments, widgets, or terms.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function can_offer_convert_to_relative( $link ) {
		if ( ! self::is_relative_url_tool_enabled() || ! $link || empty( $link->link_url ) ) {
			return false;
		}
		if ( ! class_exists( 'TSOLIIN_HTTP', false ) || ! TSOLIIN_HTTP::can_convert_to_relative_url( (string) $link->link_url ) ) {
			return false;
		}
		$type       = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$can_inline = ( 'comment' === $type ) ? true : self::can_inline_edit_link( $link );
		$can_edit   = $can_inline && (
			! in_array( $type, array( 'comment', 'widget', 'menu', 'term', 'acf' ), true )
			|| ( 'menu' === $type && self::is_custom_menu_url_row( $link ) )
		);
		return (bool) $can_edit;
	}

	/**
	 * Whether to save a WordPress revision before in-post link edits.
	 *
	 * @return bool
	 */
	public static function is_create_revision_enabled() {
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s['create_revision'] );
	}

	/**
	 * Default rows per page on the main link list.
	 *
	 * @return int
	 */
	public static function get_list_per_page_default() {
		return 20;
	}

	/**
	 * Minimum rows per page (list table).
	 *
	 * @return int
	 */
	public static function get_list_per_page_min() {
		return 10;
	}

	/**
	 * Maximum rows per page (list table); filterable for hosts with more headroom.
	 *
	 * @return int
	 */
	public static function get_list_per_page_max() {
		/**
		 * Filter the maximum links per page on the main inspector list.
		 *
		 * @param int $max Default 500.
		 */
		return max( 50, (int) apply_filters( 'tsoliin_max_per_page', 500 ) );
	}

	/**
	 * Clamp a per-page value to safe bounds.
	 *
	 * @param int $value Requested rows per page.
	 * @return int
	 */
	public static function cap_list_per_page( $value ) {
		$value = absint( $value );
		if ( $value < self::get_list_per_page_min() ) {
			return self::get_list_per_page_min();
		}
		if ( $value > self::get_list_per_page_max() ) {
			return self::get_list_per_page_max();
		}
		return $value;
	}

	/**
	 * Current user's capped links-per-page preference.
	 *
	 * @return int
	 */
	public static function get_user_list_per_page() {
		$user_id  = get_current_user_id();
		$per_page = $user_id > 0 ? (int) get_user_option( 'tsoliin_per_page', $user_id ) : 0;
		if ( $per_page <= 0 ) {
			$per_page = self::get_list_per_page_default();
		}
		return self::cap_list_per_page( $per_page );
	}

	/**
	 * Whether the plugin can edit this link in-place (modal), without opening another admin screen.
	 *
	 * @param object|null $link Link row.
	 * @return bool
	 */
	public static function can_inline_edit_link( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		if ( class_exists( 'TSOLIIN_Acf', false ) && ( TSOLIIN_Acf::is_acf_source_key( $sk ) || 'acf' === (string) $link->link_type ) ) {
			return false;
		}
		if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			return false;
		}
		$cache_key = self::link_row_cache_key( $link );
		if ( '' !== $cache_key && array_key_exists( $cache_key, self::$inline_edit_link_cache ) ) {
			return self::$inline_edit_link_cache[ $cache_key ];
		}
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		$result  = (bool) ( $scanner && $scanner->is_url_editable_in_source( $link ) );
		if ( '' !== $cache_key ) {
			self::$inline_edit_link_cache[ $cache_key ] = $result;
		}
		return $result;
	}

	/**
	 * Whether the list table should offer Go to edit for a WooCommerce product field.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_woocommerce_admin_edit_action( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( ! class_exists( 'TSOLIIN_WooCommerce', false ) || ! TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		return TSOLIIN_WooCommerce::is_product( (int) $link->post_id );
	}

	/**
	 * Whether the list table should offer the inline Edit link modal action.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_edit_link_row_action( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$type = (string) $link->link_type;
		if ( in_array( $type, array( 'comment', 'widget', 'term', 'acf' ), true ) ) {
			return false;
		}
		if ( 'menu' === $type ) {
			return self::is_custom_menu_url_row( $link ) && self::can_inline_edit_link( $link );
		}
		return self::can_inline_edit_link( $link );
	}

	/**
	 * Whether the list table should offer Go to edit widget.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_widget_admin_edit_action( $link ) {
		if ( ! $link || 'widget' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== self::get_widgets_admin_edit_url( $sk );
	}

	/**
	 * Whether the list table should offer Go to edit comment.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_comment_admin_edit_action( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		return '' !== self::get_comment_admin_edit_url( self::get_comment_id_from_link_row( $link ) );
	}

	/**
	 * WordPress comment ID stored on a link inspector row.
	 *
	 * @param object|null $link DB link row.
	 * @return int
	 */
	public static function get_comment_id_from_link_row( $link ) {
		if ( ! $link ) {
			return 0;
		}
		if ( ! empty( $link->source_key ) && preg_match( '/^c-(\d+)-/', (string) $link->source_key, $m ) ) {
			return absint( $m[1] );
		}
		if ( preg_match( '/#(\d+)/', (string) $link->anchor_text, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Whether the edit-link modal may change anchor/alt text for this row.
	 *
	 * Comment rows are URL-only; edit visible text in WordPress.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function can_edit_link_anchor_in_modal( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$type = (string) $link->link_type;
		if ( in_array( $type, array( 'iframe', 'comment' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether stored anchor text is an internal inspector label, not visible comment link text.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_comment_internal_anchor_label( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( '' !== $sk && preg_match( '/-author$/', $sk ) ) {
			return true;
		}
		$anchor = trim( (string) ( $link->anchor_text ?? '' ) );
		if ( '' === $anchor ) {
			return true;
		}
		$comment_id = self::get_comment_id_from_link_row( $link );
		if ( $comment_id > 0 ) {
			$placeholders = array(
				/* translators: %d: comment ID */
				sprintf( __( 'Comment #%d', 'tso-link-inspector' ), $comment_id ),
				/* translators: %d: comment ID */
				sprintf( __( 'Comment author #%d', 'tso-link-inspector' ), $comment_id ),
			);
			if ( in_array( $anchor, $placeholders, true ) ) {
				return true;
			}
		}
		return (bool) preg_match(
			'/^(?:Comment|Comentari|Comentario|Autor comentario)(?:\s+author|\s+autor)?\s*#\d+$/iu',
			$anchor
		);
	}

	/**
	 * Whether this row is the commenter's website URL (comment_author_url), not body content.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_comment_author_url_row( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== $sk && preg_match( '/-author$/', $sk );
	}

	/**
	 * Whether Unlink may modify the source (not only delete the inspector row).
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function can_unlink_link( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		if ( 'comment' === (string) $link->link_type ) {
			return true;
		}
		if ( self::can_inline_edit_link( $link ) ) {
			return true;
		}
		// Ghost / stale post rows: allow Unlink to drop them from the list.
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		return (bool) ( $scanner && $scanner->is_orphan_post_link_row( $link ) );
	}

	/**
	 * Whether the current user may change this link in WordPress content.
	 *
	 * Uses object capabilities (edit_post, edit_comment, edit_term, edit_theme_options)
	 * rather than manage_options alone.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function current_user_can_mutate_link( $link ) {
		if ( ! $link ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';

		if ( 'comment' === $type ) {
			$cid = self::get_comment_id_from_link_row( $link );
			return $cid > 0 && current_user_can( 'edit_comment', $cid );
		}

		if ( 'widget' === $type ) {
			return current_user_can( 'edit_theme_options' );
		}

		if ( 'term' === $type ) {
			$term_id = 0;
			if ( ! empty( $link->source_key ) && preg_match( '/^t-(\d+)-/', (string) $link->source_key, $m ) ) {
				$term_id = absint( $m[1] );
			}
			return $term_id > 0 && current_user_can( 'edit_term', $term_id );
		}

		if ( 'menu' === $type ) {
			$item_id = 0;
			if ( ! empty( $link->source_key ) && preg_match( '/^mi-(\d+)/', (string) $link->source_key, $m ) ) {
				$item_id = absint( $m[1] );
			}
			if ( $item_id > 0 && current_user_can( 'edit_post', $item_id ) ) {
				return true;
			}
			return current_user_can( 'edit_theme_options' );
		}

		$post_id = isset( $link->post_id ) ? absint( $link->post_id ) : 0;
		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Public front-end URL to view the post (or jump to a comment for comment rows).
	 *
	 * @param object|null $link DB link row.
	 * @return string
	 */
	/**
	 * The already-fetched post for this ID, from the scanner's request-scoped
	 * cache, falling back to the bare ID. Pass the return value straight into
	 * get_edit_post_link() / get_permalink() — both accept int|WP_Post, and
	 * giving them the object skips their own internal get_post() lookup,
	 * which is otherwise a fresh query every time on hosts where that isn't
	 * cached across calls.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|int
	 */
	public static function get_cached_post_for_edit_link( $post_id ) {
		$post_id = absint( $post_id );
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		if ( $scanner ) {
			$post = $scanner->get_cached_post( $post_id );
			if ( $post ) {
				return $post;
			}
		}
		return $post_id;
	}

	public static function get_post_frontend_view_url_for_link( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return '';
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'comment' === $type ) {
			$comment_id = self::get_comment_id_from_link_row( $link );
			if ( $comment_id > 0 ) {
				$comment = get_comment( $comment_id );
				if ( $comment ) {
					$comment_link = get_comment_link( $comment );
					if ( is_string( $comment_link ) && '' !== $comment_link ) {
						return $comment_link;
					}
				}
			}
		}
		$permalink = get_permalink( self::get_cached_post_for_edit_link( (int) $link->post_id ) );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		if ( self::should_focus_link_in_post_content( $link ) && ! empty( $link->id ) ) {
			return add_query_arg( 'tsoliin_link', absint( $link->id ), $permalink );
		}
		return $permalink;
	}

	/**
	 * Whether a row points to editable post content (link, image, iframe, etc.).
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function should_focus_link_in_post_content( $link ) {
		if ( ! $link ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain', 'template', 'wp_block' ), true ) ) {
			return false;
		}
		if ( empty( $link->post_id ) || empty( $link->link_url ) ) {
			return false;
		}
		// This is called up to 3x per link row (title cell, view-URL builder,
		// edit-URL builder) with the same result each time; cache it so the
		// underlying is_url_in_post_body() content scan only runs once per row.
		$cache_key = self::link_row_cache_key( $link );
		if ( '' !== $cache_key && array_key_exists( $cache_key, self::$focus_in_post_content_cache ) ) {
			return self::$focus_in_post_content_cache[ $cache_key ];
		}
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		if ( ! $scanner ) {
			return true;
		}
		// Only deep-link when the URL is in post_content (not meta-only / stale orphan rows).
		$result = $scanner->is_url_in_post_body( (int) $link->post_id, (string) $link->link_url, $type );
		if ( '' !== $cache_key ) {
			self::$focus_in_post_content_cache[ $cache_key ] = $result;
		}
		return $result;
	}

	/**
	 * Admin edit screen URL for a post-stored link, with optional deep-link to highlight it.
	 *
	 * @param object|null $link DB link row.
	 * @return string
	 */
	public static function get_post_admin_edit_url_for_link( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return '';
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain', 'template', 'wp_block' ), true ) ) {
			return '';
		}
		$edit = get_edit_post_link( self::get_cached_post_for_edit_link( (int) $link->post_id ) );
		if ( ! is_string( $edit ) || '' === $edit ) {
			return '';
		}
		if ( ! empty( $link->id ) && self::should_focus_link_in_post_content( $link ) ) {
			return add_query_arg( 'tsoliin_link', absint( $link->id ), $edit );
		}
		return $edit;
	}

	/**
	 * HTML attributes to match when focusing a link row on the front end.
	 *
	 * @param string $link_type link|image|iframe|….
	 * @return string[]
	 */
	public static function get_focus_attributes_for_link_type( $link_type ) {
		$link_type = sanitize_key( (string) $link_type );
		if ( 'image' === $link_type || 'iframe' === $link_type ) {
			return array( 'src' );
		}
		if ( 'link' === $link_type ) {
			return array( 'href' );
		}
		if ( 'plain' === $link_type ) {
			return array();
		}
		return array( 'href', 'src' );
	}

	/**
	 * Whether a post opens in the block editor (respects Classic Editor plugin).
	 *
	 * @param WP_Post|null $post Post object.
	 * @return bool
	 */
	public static function post_uses_block_editor( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		$editors = apply_filters( 'classic_editor_enabled_editors_for_post', array( 'classic', 'block' ), $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Classic Editor plugin public API.
		if ( is_array( $editors ) && ! in_array( 'block', $editors, true ) ) {
			return false;
		}
		if ( function_exists( 'use_block_editor_for_post' ) ) {
			return (bool) use_block_editor_for_post( $post );
		}
		return false;
	}

	/**
	 * Localized data for editor/front-end link focus scripts.
	 *
	 * @param object $link    DB link row.
	 * @param int    $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_focus_link_localize_data( $link, $post_id ) {
		$post_id   = absint( $post_id );
		$link_type = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
		$url       = ( $link && isset( $link->link_url ) ) ? (string) $link->link_url : '';
		$variants  = TSOLIIN_Scanner::get_href_match_variants( $url, $post_id );

		$in_post_content = false;
		$meta_key_hint   = '';
		$post            = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post instanceof WP_Post ) {
			foreach ( $variants as $variant ) {
				if ( '' !== $variant && false !== stripos( $post->post_content, $variant ) ) {
					$in_post_content = true;
					break;
				}
			}
			if ( ! $in_post_content && 'plain' === $link_type && ! empty( $link->anchor_text ) ) {
				$anchor = sanitize_text_field( (string) $link->anchor_text );
				if ( '' !== $anchor && ! preg_match( '/^(comment|comentari|widget|menu)\b/i', $anchor ) ) {
					$meta_key_hint = $anchor;
				}
			}
		}

		$extra = array();
		foreach ( $variants as $variant ) {
			$encoded = htmlspecialchars( (string) $variant, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			if ( $encoded !== $variant ) {
				$extra[] = $encoded;
			}
			if ( false !== strpos( $variant, '&' ) ) {
				$extra[] = str_replace( '&', '&amp;', $variant );
			}
		}

		$youtube_id = self::extract_youtube_video_id( $url );
		if ( '' !== $youtube_id ) {
			foreach (
				array(
					$youtube_id,
					'youtu.be/' . $youtube_id,
					'youtube.com/embed/' . $youtube_id,
					'youtube-nocookie.com/embed/' . $youtube_id,
					'youtube.com/watch?v=' . $youtube_id,
				) as $yt_variant
			) {
				$variants[] = $yt_variant;
			}
		}

		$parsed_focus = wp_parse_url( $url );
		if ( is_array( $parsed_focus ) && ! empty( $parsed_focus['query'] ) && ! empty( $parsed_focus['host'] ) ) {
			$scheme = isset( $parsed_focus['scheme'] ) ? (string) $parsed_focus['scheme'] : 'https';
			$path   = isset( $parsed_focus['path'] ) ? (string) $parsed_focus['path'] : '';
			$variants[] = $scheme . '://' . $parsed_focus['host'] . $path;
		}

		$content_needle = '';
		if ( $post instanceof WP_Post ) {
			$content_needle = self::find_url_needle_in_post_content( $post->post_content, $variants );
			if ( '' === $content_needle ) {
				$content_needle = self::find_wp_embed_needle_in_post_content( $post->post_content, $variants );
			}
		}

		$attachment_id = self::resolve_attachment_id_from_url( $url );

		$classic_gallery = false;
		$gallery_ids       = array();
		$gallery_index     = -1;
		if ( $post instanceof WP_Post && 'image' === $link_type && $attachment_id > 0 ) {
			$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
			if ( $scanner && method_exists( $scanner, 'get_classic_gallery_focus_context' ) ) {
				$gallery_context = $scanner->get_classic_gallery_focus_context( $post->post_content, $attachment_id, $post_id );
				$gallery_needle  = isset( $gallery_context['needle'] ) ? (string) $gallery_context['needle'] : '';
				$gallery_ids     = isset( $gallery_context['ids'] ) && is_array( $gallery_context['ids'] )
					? array_values( array_map( 'absint', $gallery_context['ids'] ) )
					: array();
				$gallery_index   = isset( $gallery_context['index'] ) ? (int) $gallery_context['index'] : -1;
				if ( '' !== $gallery_needle ) {
					$classic_gallery = true;
					$in_post_content = true;
					$content_needle  = $gallery_needle;
				}
			}
		}

		$is_block_editor = self::post_uses_block_editor( $post );

		$prefer_text_mode = false;
		if ( 'plain' === $link_type && $post instanceof WP_Post && $in_post_content ) {
			$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
			if ( $scanner && method_exists( $scanner, 'url_is_visible_plain_text_in_post_content' ) ) {
				// Text tab only when the URL is not visible plain text (e.g. shortcode attribute only).
				$prefer_text_mode = ! $scanner->url_is_visible_plain_text_in_post_content( $post->post_content, $url, $post_id );
			}
		} elseif ( 'link' === $link_type && $post instanceof WP_Post && $in_post_content && '' !== $youtube_id ) {
			// Classic: youtu.be on its own line / [embed] — Visual shows an iframe, not the stored href.
			// Gutenberg embed blocks stay in Visual (core/embed); Text mode is a fallback in JS.
			if ( ! $is_block_editor && ! self::url_in_html_href_attribute( $post->post_content, $variants ) ) {
				$prefer_text_mode = true;
			}
		}

		return array(
			'variants'        => array_values( array_unique( array_filter( array_merge( $variants, $extra ) ) ) ),
			'attrs'           => self::get_focus_attributes_for_link_type( $link_type ),
			'linkType'        => $link_type,
			'inPostContent'   => $in_post_content ? 1 : 0,
			'metaKeyHint'     => $meta_key_hint,
			'contentNeedle'   => $content_needle,
			'attachmentId'    => $attachment_id,
			'fileName'        => self::file_name_from_url( $url ),
			'isBlockEditor'   => $is_block_editor ? 1 : 0,
			'classicGallery'  => $classic_gallery ? 1 : 0,
			'galleryIds'      => $gallery_ids,
			'galleryIndex'    => $gallery_index,
			'preferTextMode'  => $prefer_text_mode ? 1 : 0,
			'youtubeVideoId'  => $youtube_id,
		);
	}

	/**
	 * Attachment post ID for a media-library image URL (full size or -WxH variant).
	 *
	 * Prefer cheap lookups; avoid calling attachment_url_to_postid() twice per URL.
	 *
	 * @param string $url Image URL.
	 * @return int
	 */
	public static function resolve_attachment_id_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return 0;
		}

		$cache_key = self::attachment_url_cache_key( $url );
		if ( array_key_exists( $cache_key, self::$attachment_id_by_url ) ) {
			return (int) self::$attachment_id_by_url[ $cache_key ];
		}

		$id = TSOLIIN_HTTP::parse_attachment_id_from_url( $url );
		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			self::$attachment_id_by_url[ $cache_key ] = $id;
			return $id;
		}

		// Strip -150x150 / -scaled before any DB lookup (one query, not two).
		$normalized = preg_replace( '/-(?:\d+x\d+|scaled|rotated)(?=\.(?:jpe?g|png|gif|webp|avif|bmp|ico))/i', '', $url );
		if ( ! is_string( $normalized ) || '' === $normalized ) {
			$normalized = $url;
		}
		$norm_key = self::attachment_url_cache_key( $normalized );
		if ( $normalized !== $url && array_key_exists( $norm_key, self::$attachment_id_by_url ) ) {
			$id = (int) self::$attachment_id_by_url[ $norm_key ];
			self::$attachment_id_by_url[ $cache_key ] = $id;
			return $id;
		}

		$id = self::attachment_id_from_uploads_relative_path( $normalized );
		if ( $id <= 0 && '' === self::uploads_relative_file_from_url( $normalized ) && function_exists( 'attachment_url_to_postid' ) ) {
			// Only when the URL is not under uploads (filtered/CDN maps) — path lookup already covers local media.
			$id = (int) attachment_url_to_postid( $normalized );
		}
		// Jetpack galleries often link to the attachment page (/slug/) instead of the file URL.
		if ( $id <= 0 ) {
			$page_id = TSOLIIN_HTTP::internal_url_to_post_id( $url );
			if ( $page_id > 0 && 'attachment' === get_post_type( $page_id ) ) {
				$id = $page_id;
			}
		}

		self::$attachment_id_by_url[ $cache_key ] = $id;
		if ( $normalized !== $url ) {
			self::$attachment_id_by_url[ $norm_key ] = $id;
		}
		return $id;
	}

	/**
	 * Relative `_wp_attached_file` path for an uploads URL, or empty.
	 *
	 * @param string $url Media URL.
	 * @return string
	 */
	private static function uploads_relative_file_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['baseurl'] ) ) {
			return '';
		}
		$baseurl = untrailingslashit( (string) $uploads['baseurl'] );
		$path    = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$base_path = wp_parse_url( $baseurl, PHP_URL_PATH );
		$base_path = is_string( $base_path ) ? untrailingslashit( $base_path ) : '';
		$rel       = '';
		if ( '' !== $base_path && 0 === strpos( $path, $base_path . '/' ) ) {
			$rel = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		} elseif ( preg_match( '#/(?:wp-content/)?uploads/(.+)$#i', $path, $m ) ) {
			$rel = (string) $m[1];
		}
		return '' !== $rel ? rawurldecode( $rel ) : '';
	}

	/**
	 * Resolve attachment ID via `_wp_attached_file` relative path.
	 *
	 * `meta_value` has no index in core, so a per-URL `WHERE meta_value = %s`
	 * lookup forces MySQL to scan every `_wp_attached_file` row on each call —
	 * slow on sites with large media libraries, and the scanner calls this
	 * once per unique image URL. Instead, load the whole key => post_id map
	 * once per request (filtered on the indexed meta_key column, so this one
	 * query stays fast even on large sites) and do plain array lookups after.
	 *
	 * @param string $url Absolute or site-relative media URL.
	 * @return int
	 */
	private static function attachment_id_from_uploads_relative_path( $url ) {
		$rel = self::uploads_relative_file_from_url( $url );
		if ( '' === $rel ) {
			return 0;
		}

		$map = self::get_attached_file_map();
		if ( ! isset( $map[ $rel ] ) ) {
			return 0;
		}

		$post_id = (int) $map[ $rel ];
		return ( $post_id > 0 && 'attachment' === get_post_type( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * Transient key for the persisted `_wp_attached_file` map (survives across
	 * requests, since a scan runs as many separate AJAX batches — each its own
	 * PHP process, so the static in-memory cache alone doesn't help there).
	 */
	const ATTACHED_FILE_MAP_TRANSIENT = 'tso_link_inspector_attached_file_map';

	/**
	 * Lazily build and cache the full `_wp_attached_file` => post_id map.
	 *
	 * Three layers: static (fastest, this request only), transient (survives
	 * across the many AJAX requests a single scan makes), then the DB query
	 * itself as the final fallback. Invalidated via {@see invalidate_attached_file_map_cache()}
	 * whenever `_wp_attached_file` meta changes or an attachment is deleted.
	 *
	 * @return array<string,int>
	 */
	private static function get_attached_file_map() {
		if ( null !== self::$attached_file_map ) {
			return self::$attached_file_map;
		}

		$cached = get_transient( self::ATTACHED_FILE_MAP_TRANSIENT );
		if ( is_array( $cached ) ) {
			self::$attached_file_map = $cached;
			return self::$attached_file_map;
		}

		global $wpdb;
		self::$attached_file_map = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$path = (string) $row->meta_value;
				if ( '' !== $path ) {
					self::$attached_file_map[ $path ] = (int) $row->post_id;
				}
			}
		}

		// 12h safety-net expiry in case an invalidation hook is ever missed;
		// normal invalidation is immediate via the hooks below.
		set_transient( self::ATTACHED_FILE_MAP_TRANSIENT, self::$attached_file_map, 12 * HOUR_IN_SECONDS );

		return self::$attached_file_map;
	}

	/**
	 * Clear the `_wp_attached_file` map cache (static + transient).
	 * Hooked to attachment meta/delete events so the map never goes stale.
	 *
	 * @return void
	 */
	public static function invalidate_attached_file_map_cache() {
		self::$attached_file_map = null;
		delete_transient( self::ATTACHED_FILE_MAP_TRANSIENT );
	}

	/**
	 * Stable cache key for a media URL (ignore query string, normalize case).
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function attachment_url_cache_key( $url ) {
		$url = trim( (string) $url );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			return strtolower( $path );
		}
		return strtolower( strtok( $url, '?' ) );
	}

	/**
	 * Cache key for a link inspector DB row.
	 *
	 * @param object $link Link row.
	 * @return string
	 */
	private static function link_row_cache_key( $link ) {
		if ( ! empty( $link->id ) ) {
			return 'id:' . absint( $link->id );
		}
		$url = isset( $link->link_url ) ? (string) $link->link_url : '';
		$sk  = isset( $link->source_key ) ? (string) $link->source_key : '';
		$type = isset( $link->link_type ) ? (string) $link->link_type : '';
		if ( '' === $url && '' === $sk ) {
			return '';
		}
		return 'row:' . md5( $type . '|' . $url . '|' . $sk . '|' . absint( $link->post_id ?? 0 ) );
	}

	/**
	 * Basename from a URL path (for DOM matching in the editor).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function file_name_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$name = basename( untrailingslashit( $path ) );
		if ( ! is_string( $name ) || '' === $name ) {
			return '';
		}
		// Path segments without a file extension (e.g. …/shortcodes/) are not media filenames
		// and must not be used as editor focus needles (they match unrelated body text).
		if ( false === strpos( $name, '.' ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * Exact URL substring as stored in post_content (preserves case and encoding).
	 *
	 * @param string   $content  Raw post content.
	 * @param string[] $variants URL variants.
	 * @return string
	 */
	public static function find_url_needle_in_post_content( $content, $variants ) {
		$content = (string) $content;
		foreach ( (array) $variants as $variant ) {
			$variant = (string) $variant;
			if ( '' === $variant ) {
				continue;
			}
			$pos = TSOLIIN_HTTP::find_complete_url_offset( $content, $variant );
			if ( false !== $pos ) {
				return substr( $content, $pos, strlen( $variant ) );
			}
		}
		return '';
	}

	/**
	 * Eleven-character YouTube video ID from youtu.be / watch / embed / shorts URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function extract_youtube_video_id( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		if ( preg_match( '/(^|\.)youtu\.be$/', $host ) && '' !== $path ) {
			$slug = strtok( $path, '/' );
			return self::sanitize_youtube_video_id( $slug );
		}

		if ( ! preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|m\.youtube\.com)$/', $host ) ) {
			return '';
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			if ( ! empty( $query['v'] ) ) {
				return self::sanitize_youtube_video_id( (string) $query['v'] );
			}
		}

		if ( preg_match( '#^(?:embed|shorts|v|live)/([^/?]+)#', $path, $matches ) ) {
			return self::sanitize_youtube_video_id( $matches[1] );
		}

		return '';
	}

	/**
	 * @param string $id Raw candidate ID.
	 * @return string
	 */
	private static function sanitize_youtube_video_id( $id ) {
		$id = trim( (string) $id );
		return preg_match( '/^[a-zA-Z0-9_-]{11}$/', $id ) ? $id : '';
	}

	/**
	 * Whether a URL appears in an HTML anchor href (not bare text or [embed]).
	 *
	 * @param string   $content  Raw post_content.
	 * @param string[] $variants URL variants.
	 * @return bool
	 */
	public static function url_in_html_href_attribute( $content, $variants ) {
		$content = (string) $content;
		foreach ( (array) $variants as $variant ) {
			$variant = (string) $variant;
			if ( '' === $variant ) {
				continue;
			}
			$quoted = preg_quote( $variant, '#' );
			if ( preg_match( '#<a\b[^>]*\bhref=(["\'])' . $quoted . '\1#i', $content ) ) {
				return true;
			}
			$encoded = htmlspecialchars( $variant, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			if ( $encoded !== $variant && preg_match( '#<a\b[^>]*\bhref=(["\'])' . preg_quote( $encoded, '#' ) . '\1#i', $content ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classic Editor [embed]…[/embed] shortcode containing one of the URL variants.
	 *
	 * @param string   $content  Raw post_content.
	 * @param string[] $variants URL variants.
	 * @return string Full shortcode or empty string.
	 */
	public static function find_wp_embed_needle_in_post_content( $content, $variants ) {
		$content = (string) $content;
		if ( '' === $content || false === stripos( $content, '[embed' ) ) {
			return '';
		}
		if ( ! preg_match_all( '/\[embed\b[^\]]*\].*?\[\/embed\]/is', $content, $matches ) ) {
			return '';
		}
		foreach ( $matches[0] as $shortcode ) {
			$shortcode = (string) $shortcode;
			foreach ( (array) $variants as $variant ) {
				$variant = (string) $variant;
				if ( '' !== $variant && false !== stripos( $shortcode, $variant ) ) {
					return $shortcode;
				}
			}
		}
		return '';
	}

	/**
	 * Admin URL to edit a non-post link source (menu, widget, term).
	 *
	 * @param object $link DB link row.
	 * @return string
	 */
	public static function get_link_source_edit_url( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return '';
		}
		$type = (string) $link->link_type;
		$sk   = isset( $link->source_key ) ? (string) $link->source_key : '';

		if ( 'menu' === $type ) {
			return self::get_menus_admin_edit_url( $sk );
		}
		if ( 'widget' === $type ) {
			return self::get_widgets_admin_edit_url( $sk );
		}
		if ( 'term' === $type && preg_match( '/^t-(\d+)-/', $sk, $m ) ) {
			$term = get_term( absint( $m[1] ) );
			if ( $term && ! is_wp_error( $term ) ) {
				$edit = get_edit_term_link( $term, $term->taxonomy );
				return is_string( $edit ) ? $edit : '';
			}
		}
		if ( 'comment' === $type ) {
			$comment_id = self::get_comment_id_from_link_row( $link );
			return self::get_comment_admin_edit_url( $comment_id );
		}
		if ( in_array( $type, array( 'template', 'wp_block' ), true ) && ! empty( $link->post_id ) ) {
			return self::get_post_admin_edit_url_for_link( $link );
		}
		return '';
	}

	/**
	 * Admin screen URL for widget editing (classic, block theme, or Site Editor).
	 *
	 * @param string $source_key Optional wg-{sidebar}-{widget}-… source key.
	 * @return string
	 */
	public static function get_widgets_admin_edit_url( $source_key = '' ) {
		$widget_id  = '';
		$sidebar_id = '';
		$scanner    = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		if ( $scanner && '' !== (string) $source_key ) {
			$resolved = $scanner->resolve_widget_from_source_key( (string) $source_key );
			if ( is_array( $resolved ) ) {
				$widget_id  = isset( $resolved['widget_id'] ) ? (string) $resolved['widget_id'] : '';
				$sidebar_id = isset( $resolved['sidebar_id'] ) ? (string) $resolved['sidebar_id'] : '';
			}
		}

		if ( ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) || ( ! current_theme_supports( 'widgets' ) && current_user_can( 'edit_theme_options' ) ) ) {
			$args = array( 'path' => '/wp-admin/widgets' );
			if ( '' !== $widget_id ) {
				$args['tsoliin_widget'] = $widget_id;
			}
			if ( '' !== $sidebar_id ) {
				$args['tsoliin_sidebar'] = $sidebar_id;
			}
			return add_query_arg( $args, admin_url( 'site-editor.php' ) );
		}

		if ( current_theme_supports( 'widgets' ) ) {
			$args = array();
			if ( '' !== $widget_id ) {
				$args['tsoliin_widget'] = $widget_id;
			}
			if ( '' !== $sidebar_id ) {
				$args['tsoliin_sidebar'] = $sidebar_id;
			}
			$url = '' !== $args ? add_query_arg( $args, admin_url( 'widgets.php' ) ) : admin_url( 'widgets.php' );
			if ( '' !== $widget_id ) {
				$url .= '#widget-' . rawurlencode( $widget_id );
			}
			return $url;
		}

		return admin_url( 'site-editor.php' );
	}

	/**
	 * Whether wp-admin/nav-menus.php is usable (block themes call wp_die on that screen).
	 *
	 * @return bool
	 */
	public static function classic_nav_menus_admin_available() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}
		return (bool) current_theme_supports( 'menus' );
	}

	/**
	 * Site Editor URL for block-theme navigation (wp_navigation).
	 *
	 * @return string
	 */
	public static function get_site_editor_navigation_admin_url() {
		// WP 6.5+ path routing (`p`); core redirects legacy `path=/navigation`.
		return add_query_arg( 'p', '/navigation', admin_url( 'site-editor.php' ) );
	}

	/**
	 * Admin screen URL for navigation menu editing.
	 *
	 * @param string $source_key Optional mi-{menu_item_id} source key.
	 * @return string
	 */
	public static function get_menus_admin_edit_url( $source_key = '' ) {
		if ( ! self::classic_nav_menus_admin_available() ) {
			return self::get_site_editor_navigation_admin_url();
		}

		$menu_id = 0;
		$item_id = 0;
		if ( preg_match( '/^mi-(\d+)/', (string) $source_key, $m ) ) {
			$item_id = absint( $m[1] );
			if ( $item_id > 0 ) {
				$terms = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
				if ( ! empty( $terms[0] ) ) {
					$menu_id = (int) $terms[0];
				}
			}
		}

		$url = admin_url( 'nav-menus.php' );
		if ( $menu_id > 0 ) {
			$url = add_query_arg(
				array(
					'action' => 'edit',
					'menu'   => $menu_id,
				),
				$url
			);
			if ( $item_id > 0 ) {
				$url .= '#menu-item-' . $item_id;
			}
		}
		return $url;
	}

	/**
	 * Whether the list table should show Go to edit for a menu row.
	 *
	 * Always when a menus / Site Editor URL exists. Custom URLs on block themes
	 * still use Edit link to change _menu_item_url; Go to edit opens Navigation.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_menu_admin_go_to_edit_action( $link ) {
		if ( ! $link || 'menu' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== self::get_menus_admin_edit_url( $sk );
	}

	/**
	 * Unified “Go to edit” row action: open the right admin screen for this source.
	 *
	 * Edit link / Unlink stay separate and only appear when the plugin can write the source.
	 *
	 * @param object|null $link DB link row.
	 * @return array{url:string,title:string,label:string}|null
	 */
	public static function get_go_to_edit_row_action( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return null;
		}

		$type  = (string) $link->link_type;
		$label = __( 'Go to edit', 'tso-link-inspector' );
		$sk    = isset( $link->source_key ) ? (string) $link->source_key : '';

		if ( self::shows_comment_admin_edit_action( $link ) ) {
			$url = self::get_comment_admin_edit_url( self::get_comment_id_from_link_row( $link ) );
			if ( '' === $url ) {
				return null;
			}
			return array(
				'url'   => $url,
				'title' => __( 'Open this comment in WordPress to edit the URL or text.', 'tso-link-inspector' ),
				'label' => $label,
			);
		}

		if ( self::shows_widget_admin_edit_action( $link ) ) {
			$url = self::get_widgets_admin_edit_url( $sk );
			if ( '' === $url ) {
				return null;
			}
			return array(
				'url'   => $url,
				'title' => __( 'Open the widget editor for this sidebar widget.', 'tso-link-inspector' ),
				'label' => $label,
			);
		}

		if ( self::shows_woocommerce_admin_edit_action( $link ) ) {
			$url = self::get_post_admin_edit_url_for_link( $link );
			if ( '' === $url ) {
				return null;
			}
			return array(
				'url'   => $url,
				'title' => __( 'Open the WooCommerce product editor. Change external URL, downloads, or images there.', 'tso-link-inspector' ),
				'label' => $label,
			);
		}

		if ( 'menu' === $type && self::shows_menu_admin_go_to_edit_action( $link ) ) {
			$url = self::get_menus_admin_edit_url( $sk );
			if ( '' === $url ) {
				return null;
			}
			if ( self::classic_nav_menus_admin_available() ) {
				$title = self::is_custom_menu_url_row( $link )
					? __( 'Open Appearance → Menus at this item. You can also use Edit link here for custom URLs.', 'tso-link-inspector' )
					: __( 'Open Appearance → Menus at this item. The URL comes from the linked page/post — edit that content to change it.', 'tso-link-inspector' );
			} else {
				$title = self::is_custom_menu_url_row( $link )
					? __( 'Open Site Editor → Navigation. To change a custom menu URL, use Edit link here.', 'tso-link-inspector' )
					: __( 'Open Site Editor → Navigation. This item\'s URL comes from the linked page/post.', 'tso-link-inspector' );
			}
			return array(
				'url'   => $url,
				'title' => $title,
				'label' => $label,
			);
		}

		if ( 'acf' === $type ) {
			$url = self::get_acf_options_admin_edit_url( $sk );
			if ( '' === $url ) {
				return null;
			}
			return array(
				'url'   => $url,
				'title' => __( 'Open ACF Options to edit this field. The inspector does not change Options page values inline.', 'tso-link-inspector' ),
				'label' => $label,
			);
		}

		if ( 'term' === $type ) {
			$url = self::get_link_source_edit_url( $link );
			if ( '' === $url ) {
				return null;
			}
			return array(
				'url'   => $url,
				'title' => __( 'Open the taxonomy term editor for this description link.', 'tso-link-inspector' ),
				'label' => $label,
			);
		}

		if ( in_array( $type, array( 'link', 'image', 'iframe', 'plain', 'template', 'wp_block' ), true ) && ! empty( $link->post_id ) ) {
			if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
				return null;
			}
			$url = self::get_post_admin_edit_url_for_link( $link );
			if ( '' === $url ) {
				return null;
			}
			$focus = self::should_focus_link_in_post_content( $link );
			if ( $focus ) {
				$title = ( 'image' === $type || 'iframe' === $type )
					? __( 'Open the editor and scroll to this image or embed.', 'tso-link-inspector' )
					: __( 'Open the editor and scroll to this link.', 'tso-link-inspector' );
			} elseif ( class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_acf_source_key( $sk ) ) {
				$title = __( 'Open the post editor. This URL comes from an ACF field stored as an ID (image, file, gallery, page link, or relationship).', 'tso-link-inspector' );
			} elseif ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
				$title = __( 'Open the post or template editor. This URL comes from an Elementor or ACF dynamic tag — change the field there.', 'tso-link-inspector' );
			} else {
				$title = __( 'Open the post editor. This URL may be in a custom field or no longer in the content — use Edit link if available, or Delete + Scan if the row is stale.', 'tso-link-inspector' );
			}
			return array(
				'url'   => $url,
				'title' => $title,
				'label' => $label,
			);
		}

		return null;
	}

	/**
	 * Whether a menu inspector row stores a custom URL in _menu_item_url (editable here).
	 *
	 * Post-type / taxonomy menu items derive their URL from the linked object and must be
	 * changed in that object's editor — not via _menu_item_url.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_custom_menu_url_row( $link ) {
		if ( ! $link || empty( $link->link_type ) || 'menu' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( ! preg_match( '/^mi-(\d+)/', $sk, $m ) ) {
			return false;
		}
		$item_id = absint( $m[1] );
		if ( $item_id <= 0 ) {
			return false;
		}
		$stored = get_post_meta( $item_id, '_menu_item_url', true );
		return is_string( $stored ) && '' !== trim( $stored );
	}

	/**
	 * Admin URL for ACF Options pages.
	 *
	 * @param string $source_key Optional row source_key.
	 * @return string
	 */
	public static function get_acf_options_admin_edit_url( $source_key = '' ) {
		if ( ! class_exists( 'TSOLIIN_Acf', false ) ) {
			return '';
		}
		return TSOLIIN_Acf::get_options_admin_edit_url( (string) $source_key );
	}

	/**
	 * Admin URL to edit a single comment (author URL or body links).
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	public static function get_comment_admin_edit_url( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return '';
		}
		return admin_url( 'comment.php?action=editcomment&c=' . $comment_id );
	}

	/**
	 * Status column HTML for a link row (list table + AJAX).
	 *
	 * @param object           $item Link row.
	 * @param TSOLIIN_HTTP|null $http HTTP helper.
	 * @return string
	 */
	public static function render_link_status_html( $item, $http = null ) {
		$orig     = isset( $item->link_url ) ? (string) $item->link_url : '';
		$rurl     = isset( $item->redirect_url ) ? (string) $item->redirect_url : '';
		$code     = isset( $item->status_code ) ? (int) $item->status_code : 0;
		$verified = ! empty( $item->user_verified );
		$chain    = TSOLIIN_DB::decode_redirect_chain( isset( $item->redirect_chain ) ? (string) $item->redirect_chain : '' );

		if ( $http && '' !== $rurl && $http->is_transparent_redirect( $orig, $rurl ) ) {
			$code  = 200;
			$rurl  = '';
			$chain = array();
		}

		$class = TSOLIIN_HTTP::status_class( $code, isset( $item->is_broken ) ? (int) $item->is_broken : 0, $orig );
		$label = TSOLIIN_HTTP::status_label( $code, $orig );
		$html  = '';
		if ( $verified ) {
			$html .= '<span class="tsoliin-verified-badge" title="' . esc_attr__( 'Marked OK by you. Re-checks keep this unless the URL fails. Edit the URL in the post to clear.', 'tso-link-inspector' ) . '">&#128274; </span>';
		}
		$html .= '<span class="tsoliin-status ' . esc_attr( $class ) . '">';
		if ( $code > 0 ) {
			$html .= esc_html( (string) $code ) . ' ';
		}
		$html .= esc_html( $label ) . '</span>';

		if ( '' !== $rurl && rtrim( $orig, '/' ) !== rtrim( $rurl, '/' ) ) {
			$rdisp = strlen( $rurl ) > 40 ? substr( $rurl, 0, 37 ) . '...' : $rurl;
			$html .= '<br><small><a href="' . esc_url( $rurl ) . '" title="' . esc_attr( $rurl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $rdisp ) . '</a></small>';
		}

		if ( count( $chain ) > 1 ) {
			/* translators: %d: number of redirect hops */
			$toggle_label = sprintf( __( '%d hops', 'tso-link-inspector' ), count( $chain ) );
			$html        .= '<br><button type="button" class="button-link tsoliin-toggle-chain" aria-expanded="false">' . esc_html( $toggle_label ) . '</button>';
			$html        .= '<ol class="tsoliin-redirect-chain" hidden>';
			foreach ( $chain as $hop ) {
				$hop_url  = isset( $hop['url'] ) ? (string) $hop['url'] : '';
				$hop_code = isset( $hop['code'] ) ? (int) $hop['code'] : 0;
				if ( '' === $hop_url ) {
					continue;
				}
				$html .= '<li><code>' . esc_html( (string) $hop_code ) . '</code> ';
				$html .= '<a href="' . esc_url( $hop_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $hop_url ) . '</a></li>';
			}
			$html .= '</ol>';
		}

		return $html;
	}
}
