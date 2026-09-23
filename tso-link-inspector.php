<?php
/**
 * Plugin Name:       TSO Link Inspector
 * Description:       Find and fix broken links across your entire WordPress site without opening each post.
 * Version:           2.5.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Tu Soporte Online
 * Author URI:        https://www.tusoporteonline.es/blog
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tso-link-inspector
 * Domain Path:       /languages
 *
 * @package TSOLIIN_Link_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSOLIIN_VERSION',    '2.5.0' );
define( 'TSOLIIN_PLUGIN_FILE', __FILE__ );
define( 'TSOLIIN_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TSOLIIN_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TSOLIIN_TEXT_DOMAIN', 'tso-link-inspector' );
define( 'TSOLIIN_BATCH_SIZE',  20 );

/**
 * Whether the current request is a Link Inspector admin screen or plugin AJAX call.
 *
 * Used to scope heavy admin_init work to plugin pages only (not every wp-admin load).
 *
 * @return bool
 */
function tsoliin_is_plugin_admin_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing; no state change.
	if ( isset( $_REQUEST['action'] ) ) {
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		if ( 0 === strpos( $action, 'tsoliin_' ) ) {
			return true;
		}
	}
	if ( isset( $_GET['page'] ) ) {
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		if ( 0 === strpos( $page, 'tso-link-inspector' ) ) {
			return true;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	return false;
}

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class TSOLIIN_Link_Inspector {

	/** @var TSOLIIN_Link_Inspector|null */
	private static $instance = null;

	/** @var TSOLIIN_DB */
	public $db;

	/** @var TSOLIIN_Scanner */
	public $scanner;

	/** @var TSOLIIN_HTTP */
	public $http;

	/** @var TSOLIIN_Cron */
	public $cron;

	/** @var TSOLIIN_Admin|null */
	public $admin;

	/** @var array<string,string> */
	private $runtime_translations = array();

	/**
	 * Get singleton instance.
	 *
	 * @return TSOLIIN_Link_Inspector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_files();
		$this->init_objects();
		$this->init_hooks();
		// Plugins screen: always use the site locale, not the plugin language setting.
		$this->load_textdomain( 'metadata' );
	}

	/**
	 * Load required PHP files.
	 */
	private function load_files() {
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-quality.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-db.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-http.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-email.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-scanner.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-sources.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-woocommerce.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-acf.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-elementor.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-schedule.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-cron.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-support.php';
		if ( is_admin() ) {
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-reports.php';
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-list-table.php';
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-admin.php';
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-dashboard.php';
		}
	}

	/**
	 * Instantiate component objects.
	 */
	private function init_objects() {
		$this->db      = new TSOLIIN_DB();
		$this->http    = new TSOLIIN_HTTP();
		$this->scanner = new TSOLIIN_Scanner( $this->db );
		$this->cron    = new TSOLIIN_Cron( $this->db, $this->scanner, $this->http );
		if ( is_admin() ) {
			$this->admin = new TSOLIIN_Admin( $this->db, $this->scanner, $this->http, $this->cron );
			new TSOLIIN_Dashboard( $this->db );
		}
	}

	/**
	 * Register global hooks.
	 */
	private function init_hooks() {
		// plugin_locale filter fires before JIT translation loading — must be registered here.
		add_filter( 'plugin_locale', array( $this, 'force_plugin_locale' ), 10, 2 );
		add_filter( 'gettext', array( $this, 'runtime_gettext_fallback' ), 999, 3 );
		add_action( 'init',       array( $this, 'load_textdomain' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		add_action( 'admin_init', array( $this, 'late_cleanup_legacy_pc_tables' ), 999 );
		add_action( 'deleted_comment', array( $this, 'on_deleted_comment' ), 10, 2 );
		// Keep the cached _wp_attached_file => post_id map (TSOLIIN_Support::get_attached_file_map())
		// in sync: invalidate whenever an attachment's file path changes, or the attachment is removed.
		add_action( 'added_post_meta', array( $this, 'on_attachment_meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_attachment_meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_attachment_meta_changed' ), 10, 4 );
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ) );
		// Re-scan post automatically when saved in editor (not during plugin AJAX calls).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checking action name to guard hook registration, no data processed.
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'tsoliin_' ) ) ) {
			add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		}
		// Nofollow broken links on frontend (optional).
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! empty( $s['nofollow_broken'] ) ) {
			add_filter( 'the_content', array( $this, 'add_nofollow_to_broken' ), 20 );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_link_focus_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_link_focus_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_post_editor_focus' ) );
		add_filter( 'block_editor_settings_all', array( $this, 'filter_block_editor_focus_settings' ), 10, 2 );
	}

	/**
	 * Load plugin translations.
	 *
	 * @param string $context `metadata` = plugin row on Plugins screen (site locale only).
	 *                        `admin`    = plugin screens and AJAX (may use language setting).
	 */
	public function load_textdomain( $context = 'admin' ) {
		$language = $this->get_selected_language();

		if ( 'metadata' === $context || ! $this->should_use_plugin_language_override() ) {
			// Follow WordPress site/user locale (e.g. es_ES on a Spanish install).
			$language = '';
		}

		$this->runtime_translations = array();

		// Unload any auto-loaded translation first.
		unload_textdomain( TSOLIIN_TEXT_DOMAIN );

		if ( 'en' === $language ) {
			// English: no .mo file needed, strings use code default.
			return;
		}

		if ( '' !== $language ) {
			// Explicit plugin language selected in settings (plugin admin only).
			$this->load_locale_translations( $language );
			return;
		}

		// Automatic mode: try WP locale plus language-only fallback from bundled files.
		$auto_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$candidates  = array( (string) $auto_locale );
		if ( false !== strpos( (string) $auto_locale, '_' ) ) {
			$candidates[] = substr( (string) $auto_locale, 0, strpos( (string) $auto_locale, '_' ) );
		}
		if ( 0 === strpos( (string) $auto_locale, 'es' ) ) {
			$candidates[] = 'es_ES';
		}
		if ( 0 === strpos( (string) $auto_locale, 'ca' ) ) {
			$candidates[] = 'ca';
		}
		foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
			if ( $this->load_locale_translations( $candidate ) ) {
				break;
			}
		}
	}

	/**
	 * Whether the language chosen in plugin settings should override the site locale.
	 *
	 * @return bool
	 */
	private function should_use_plugin_language_override() {
		if ( ! is_admin() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing to load translations on plugin screens; not processing form submissions.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
			if ( 0 === strpos( $action, 'tsoliin_' ) ) {
				return true;
			}
		}
		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_key( wp_unslash( $_GET['page'] ) );
			if ( 0 === strpos( $page, 'tso-link-inspector' ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
	}

	/**
	 * Load translations for a specific locale from bundled files.
	 * Loads bundled .po/.mo files for the given locale.
	 *
	 * @param string $locale Locale code (e.g. es_ES, ca).
	 * @return bool True when at least one translation file was loaded.
	 */
	private function load_locale_translations( $locale ) {
		$locale = trim( (string) $locale );
		if ( '' === $locale ) {
			return false;
		}

		$po_candidates = array(
			TSOLIIN_PLUGIN_DIR . 'languages/' . TSOLIIN_TEXT_DOMAIN . '-' . $locale . '.po',
		);
		$mo_candidates = array(
			TSOLIIN_PLUGIN_DIR . 'languages/' . TSOLIIN_TEXT_DOMAIN . '-' . $locale . '.mo',
		);

		$loaded = false;
		foreach ( $po_candidates as $po_file ) {
			if ( file_exists( $po_file ) ) {
				$this->runtime_translations = $this->parse_po_translations( $po_file );
				$loaded                     = ! empty( $this->runtime_translations );
				break;
			}
		}
		foreach ( $mo_candidates as $mo_file ) {
			if ( file_exists( $mo_file ) ) {
				load_textdomain( TSOLIIN_TEXT_DOMAIN, $mo_file );
				$loaded = true;
				break;
			}
		}

		return $loaded;
	}

	/**
	 * Runtime translation fallback from .po map.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function runtime_gettext_fallback( $translation, $text, $domain ) {
		if ( TSOLIIN_TEXT_DOMAIN !== $domain ) {
			return $translation;
		}
		if ( isset( $this->runtime_translations[ $text ] ) && '' !== $this->runtime_translations[ $text ] ) {
			return $this->runtime_translations[ $text ];
		}
		return $translation;
	}

	/**
	 * Filter: force the locale used for this plugin.
	 * Called before any __() translation so locale is set correctly for JIT loading.
	 *
	 * @param string $locale Current locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function force_plugin_locale( $locale, $domain ) {
		if ( TSOLIIN_TEXT_DOMAIN !== $domain ) {
			return $locale;
		}
		if ( ! $this->should_use_plugin_language_override() ) {
			return $locale;
		}
		$language = $this->get_selected_language();

		if ( 'en' === $language ) {
			// Return a non-existent locale so no translation file is found.
			return 'en_US_no_translation';
		}
		if ( '' !== $language ) {
			return $language; // e.g. 'es_ES' or 'ca'
		}
		return $locale; // Automatic: use site locale.
	}

	/**
	 * Get selected plugin language from settings.
	 *
	 * @return string
	 */
	private function get_selected_language() {
		$settings      = get_option( 'tsoliin_settings', array() );
		$allowed_langs = array( '', 'ca', 'es_ES', 'en' );
		return ( isset( $settings['language'] ) && in_array( $settings['language'], $allowed_langs, true ) )
			? (string) $settings['language'] : '';
	}

	/**
	 * Parse gettext .po file into msgid => msgstr map.
	 *
	 * @param string $file_path PO file path.
	 * @return array<string,string>
	 */
	private function parse_po_translations( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}
		$lines = file( $file_path, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			return array();
		}

		$flush_pair = static function ( &$map, $id, $str ) {
			if ( '' !== $id && '' !== $str ) {
				$map[ $id ] = $str;
			}
		};

		$map        = array();
		$state      = '';
		$current_id = '';
		$current_tr = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				$flush_pair( $map, $current_id, $current_tr );
				$state      = '';
				$current_id = '';
				$current_tr = '';
				continue;
			}

			// Skip plural / context lines (not used by this plugin UI strings).
			if ( 0 === strpos( $line, 'msgid_plural' ) || 0 === strpos( $line, 'msgctxt' ) || 0 === strpos( $line, 'msgstr[' ) ) {
				continue;
			}

			if ( 0 === strpos( $line, 'msgid "' ) ) {
				$flush_pair( $map, $current_id, $current_tr );
				$state      = 'id';
				$current_id = stripcslashes( substr( $line, 7, -1 ) );
				$current_tr = '';
				continue;
			}
			if ( 0 === strpos( $line, 'msgstr "' ) ) {
				$state      = 'str';
				$current_tr = stripcslashes( substr( $line, 8, -1 ) );
				continue;
			}
			if ( '"' === substr( $line, 0, 1 ) && strlen( $line ) > 1 && '"' === substr( $line, -1 ) ) {
				$chunk = stripcslashes( substr( $line, 1, -1 ) );
				if ( 'id' === $state ) {
					$current_id .= $chunk;
				} elseif ( 'str' === $state ) {
					$current_tr .= $chunk;
				}
			}
		}
		$flush_pair( $map, $current_id, $current_tr );
		return $map;
	}

	
	/**
	 * Drop legacy pc_ tables if another admin hook recreated them this request.
	 */
	public function late_cleanup_legacy_pc_tables() {
		if ( ! tsoliin_is_plugin_admin_request() ) {
			// Cron self-healing is lightweight and must not depend on opening the plugin screen.
			$this->cron->schedule();
			return;
		}
		$this->db->late_cleanup_legacy_pc_tables();
	}

	/**
	 * Run DB upgrades when version changes.
	 */
	public function maybe_upgrade_db() {
		$installed = (string) get_option( 'tsoliin_version', '0' );
		if ( version_compare( $installed, TSOLIIN_VERSION, '<' ) ) {
			delete_option( 'tsoliin_legacy_pc_table_cleared' );
			$this->db->create_table();
			$this->db->cleanup_trivial_redirects();
			$this->db->cleanup_querystring_redirects();
			$this->db->cleanup_transparent_redirects();
			$this->db->cleanup_action_url_rows();
			$this->db->cleanup_blocked_dns_rows();
			$this->db->cleanup_mislabeled_skip_rows( true, 15 );
			$this->db->cleanup_misclassified_plain_image_rows();
			$this->db->cleanup_attachment_permalink_rows();
			$this->cron->schedule();
			$this->ensure_frequent_options_autoloaded();
			$this->ensure_progress_options_not_autoloaded();
			update_option( 'tsoliin_version', TSOLIIN_VERSION, true );
			return;
		}
		if ( ! tsoliin_is_plugin_admin_request() ) {
			return;
		}
		$this->db->ensure_table_exists();
		// Re-register cron hooks if they were removed externally (e.g. cache flush, cron plugin).
		$this->cron->schedule();
	}

	/**
	 * Force autoload=yes on options read on (almost) every request (admin_init,
	 * cron steps, AJAX) that were previously saved with autoload=no. Re-saving
	 * with the current code (which now passes autoload=true) only fixes new
	 * writes; sites upgrading from an older version still have the old
	 * autoload=no row until this runs once.
	 */
	private function ensure_frequent_options_autoloaded() {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( 'tsoliin_settings', true );
			return;
		}
		$settings = get_option( 'tsoliin_settings', null );
		if ( null !== $settings ) {
			update_option( 'tsoliin_settings', $settings, true );
		}
	}

	/**
	 * Force autoload=no in the DB for options that are written on every scan/check
	 * "tick" but never read on a normal page load.
	 *
	 * All the update_option() calls for these keys already pass $autoload = false,
	 * but that only takes effect going forward: a row created by an older version
	 * of this plugin (before that third argument existed here) can still be sitting
	 * in the DB with autoload='yes'. WordPress invalidates its whole cached
	 * "alloptions" set whenever an option's autoload membership actually changes,
	 * so as long as one of these rows is still marked autoload='yes', every single
	 * background-check tick that updates it forces a full options reload
	 * (wp_load_alloptions() querying wp_options again) — this is what showed up in
	 * Query Monitor as repeated wp_load_alloptions() calls "while checking links".
	 * Running this once, here, normalizes the DB so update_option()'s existing
	 * false stays a no-op afterwards.
	 */
	private function ensure_progress_options_not_autoloaded() {
		$keys = array(
			'tsoliin_last_check_batch',
			'tsoliin_last_full_scan',
			'tsoliin_total_posts_scanned',
			'tsoliin_bg_scan_token',
			'tsoliin_bg_scan_phase',
			'tsoliin_bg_scan_running',
			'tsoliin_bg_scan_page',
			'tsoliin_bg_scan_total',
			'tsoliin_bg_scan_scanned',
			'tsoliin_bg_scan_complete',
			'tsoliin_bg_scan_started',
			'tsoliin_bg_scan_error',
			'tsoliin_bg_check_token',
			'tsoliin_bg_check_post_id',
			'tsoliin_bg_check_total',
			'tsoliin_bg_check_checked',
			'tsoliin_bg_check_running',
			'tsoliin_bg_check_complete',
			'tsoliin_bg_check_started',
			'tsoliin_bg_check_last_error',
			TSOLIIN_Cron::OPT_IMMEDIATE_QUEUE,
			TSOLIIN_Cron::OPT_EMPTY_BATCH_RETRIES,
			TSOLIIN_Cron::OPT_USER_STOPPED_CHECK,
			'tsoliin_comment_scan_after_id',
			'tsoliin_menu_scan_after_id',
			'tsoliin_widget_scan_after_index',
			'tsoliin_term_scan_after_id',
			'tsoliin_fse_scan_after_id',
			'tsoliin_broken_digest_last_sent',
			'tsoliin_site_gate_state',
		);
		foreach ( $keys as $key ) {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $key, false );
				continue;
			}
			// WP < 6.6: only re-writing the option flips a mismatched autoload flag.
			$value = get_option( $key, null );
			if ( null !== $value ) {
				update_option( $key, $value, false );
			}
		}
	}

	/**
	 * Plugin activation.
	 */
	public function on_activate() {
		$this->db->ensure_table_exists();
		$this->cron->schedule();
		update_option( 'tsoliin_version', TSOLIIN_VERSION, true );
	}

	/**
	 * Plugin deactivation.
	 */
	public function on_deactivate() {
		$this->cron->unschedule();
	}

	/**
	 * When a post is saved in the editor, re-scan it so the DB reflects
	 * any manual URL changes the user made.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		// Skip auto-saves, revisions, trashed posts, and non-publish.
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		// Only scan post types we are configured to track.
		$post_types = $this->scanner->get_post_types();
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}
		// Re-scan: updates DB with current links in post_content (respects Settings toggles).
		$this->scanner->scan_post( $post_id, false );
	}

	/**
	 * Remove inspector rows tied to a deleted comment (source_key c-{id}-*).
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object (may be empty in some WP versions).
	 */
	public function on_deleted_comment( $comment_id, $comment = null ) {
		$this->db->delete_links_for_comment( (int) $comment_id );
	}

	/**
	 * Invalidate the cached `_wp_attached_file` map when that meta key changes
	 * (file replaced, regenerated, or moved by another plugin).
	 *
	 * @param int    $meta_id  Meta row ID (unused).
	 * @param int    $post_id  Post ID (unused).
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public function on_attachment_meta_changed( $meta_id, $post_id, $meta_key ) {
		if ( '_wp_attached_file' === $meta_key ) {
			TSOLIIN_Support::invalidate_attached_file_map_cache();
		}
	}

	/**
	 * Invalidate the cached `_wp_attached_file` map when an attachment is deleted.
	 *
	 * @return void
	 */
	public function on_attachment_deleted() {
		TSOLIIN_Support::invalidate_attached_file_map_cache();
	}

	/**
	 * Scroll to a link/image on the public post when opened from the inspector list.
	 *
	 * @return void
	 */
	public function enqueue_link_focus_assets() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public deep-link to visible content only.
		$link_id = isset( $_GET['tsoliin_link'] ) ? absint( wp_unslash( $_GET['tsoliin_link'] ) ) : 0;
		if ( $link_id <= 0 ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		$link    = $this->db->get_link( $link_id );
		if ( ! $link || (int) $link->post_id !== $post_id || ! TSOLIIN_Support::should_focus_link_in_post_content( $link ) ) {
			return;
		}
		$variants = TSOLIIN_Scanner::get_href_match_variants( (string) $link->link_url, $post_id );
		if ( empty( $variants ) ) {
			return;
		}
		wp_enqueue_style(
			'tsoliin-focus-link',
			TSOLIIN_PLUGIN_URL . 'assets/css/focus-link.css',
			array(),
			TSOLIIN_VERSION
		);
		wp_enqueue_script(
			'tsoliin-focus-link',
			TSOLIIN_PLUGIN_URL . 'assets/js/focus-link.js',
			array(),
			TSOLIIN_VERSION,
			true
		);
		wp_localize_script(
			'tsoliin-focus-link',
			'tsoliinFocusLink',
			TSOLIIN_Support::get_focus_link_localize_data( $link, $post_id )
		);
	}

	/**
	 * Resolve link focus request on post edit screens.
	 *
	 * Memoized for the request: block editor settings and enqueue both call this.
	 *
	 * @return array{link:object,post_id:int}|null
	 */
	private function get_editor_focus_request() {
		static $resolved = false;
		static $request  = null;
		if ( $resolved ) {
			return $request;
		}
		$resolved = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deep-link to editable content only.
		$link_id = isset( $_GET['tsoliin_link'] ) ? absint( wp_unslash( $_GET['tsoliin_link'] ) ) : 0;
		if ( $link_id <= 0 ) {
			return null;
		}

		global $post;
		$post_id = 0;
		if ( $post instanceof WP_Post ) {
			$post_id = (int) $post->ID;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin editor deep-link post ID.
		if ( $post_id <= 0 && isset( $_GET['post'] ) ) {
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( $post_id <= 0 ) {
			return null;
		}

		$link = $this->db->get_link( $link_id );
		if ( ! $link || (int) $link->post_id !== $post_id || ! TSOLIIN_Support::should_focus_link_in_post_content( $link ) ) {
			return null;
		}

		$variants = TSOLIIN_Scanner::get_href_match_variants( (string) $link->link_url, $post_id );
		if ( empty( $variants ) ) {
			return null;
		}

		$request = array(
			'link'    => $link,
			'post_id' => $post_id,
		);
		return $request;
	}

	/**
	 * Whether the current admin request is the block-based widgets editor.
	 *
	 * @return bool
	 */
	private function is_widgets_block_editor_screen() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && in_array( $screen->id, array( 'widgets', 'customize' ), true ) ) {
				return true;
			}
		}
		if ( wp_script_is( 'wp-edit-widgets', 'enqueued' ) || wp_script_is( 'wp-customize-widgets', 'enqueued' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( 'gutenberg-edit-widgets', 'gutenberg-edit-site' ), true );
	}

	/**
	 * Pass focus data into the block editor settings object.
	 *
	 * @param array                   $settings Block editor settings.
	 * @param WP_Block_Editor_Context $context  Editor context.
	 * @return array
	 */
	public function filter_block_editor_focus_settings( $settings, $context ) {
		if ( $context instanceof WP_Block_Editor_Context && isset( $context->name ) && 'core/edit-post' !== (string) $context->name ) {
			return $settings;
		}
		if ( $this->is_widgets_block_editor_screen() ) {
			return $settings;
		}
		$request = $this->get_editor_focus_request();
		if ( ! $request ) {
			return $settings;
		}
		$settings['tsoliinFocusLink'] = TSOLIIN_Support::get_focus_link_localize_data( $request['link'], $request['post_id'] );
		return $settings;
	}

	/**
	 * Enqueue editor focus assets on post edit screens (block + classic).
	 *
	 * @param string $hook Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue_admin_post_editor_focus( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$post_id = $this->resolve_admin_post_editor_id();
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && TSOLIIN_Support::post_uses_block_editor( $post ) ) {
				return;
			}
		}
		$this->enqueue_editor_link_focus_shared();
	}

	/**
	 * Post ID for the current post edit admin screen.
	 *
	 * @return int
	 */
	private function resolve_admin_post_editor_id() {
		global $post;
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen routing; no state change.
		if ( isset( $_GET['post'] ) ) {
			return absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return 0;
	}

	/**
	 * Highlight a link in the block editor when opened from the inspector list.
	 *
	 * @return void
	 */
	public function enqueue_editor_link_focus_assets() {
		if ( $this->is_widgets_block_editor_screen() ) {
			return;
		}
		$this->enqueue_editor_link_focus_shared();
	}

	/**
	 * Shared assets for editor deep-link focus (block or classic).
	 *
	 * @return void
	 */
	private function enqueue_editor_link_focus_shared() {
		if ( $this->is_widgets_block_editor_screen() ) {
			return;
		}
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$request = $this->get_editor_focus_request();
		if ( ! $request ) {
			return;
		}
		$enqueued = true;
		$link    = $request['link'];
		$post_id = $request['post_id'];
		wp_enqueue_style(
			'tsoliin-focus-link',
			TSOLIIN_PLUGIN_URL . 'assets/css/focus-link.css',
			array(),
			TSOLIIN_VERSION
		);
		$deps = array();
		$post = get_post( $post_id );
		$use_block_editor = TSOLIIN_Support::post_uses_block_editor( $post );
		if ( $use_block_editor ) {
			if ( wp_script_is( 'wp-data', 'registered' ) ) {
				$deps[] = 'wp-data';
			}
			if ( wp_script_is( 'wp-dom-ready', 'registered' ) ) {
				$deps[] = 'wp-dom-ready';
			}
			if ( wp_script_is( 'wp-blocks', 'registered' ) ) {
				$deps[] = 'wp-blocks';
			}
		}
		$focus_ver = TSOLIIN_VERSION . '.' . (string) filemtime( TSOLIIN_PLUGIN_DIR . 'assets/js/focus-editor.js' );
		wp_enqueue_script(
			'tsoliin-focus-editor',
			TSOLIIN_PLUGIN_URL . 'assets/js/focus-editor.js',
			$deps,
			$focus_ver,
			true
		);
		wp_localize_script(
			'tsoliin-focus-editor',
			'tsoliinFocusLink',
			TSOLIIN_Support::get_focus_link_localize_data( $link, $post_id )
		);
	}

	/**
	 * Add rel="nofollow" to broken links in post content (frontend only).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_nofollow_to_broken( $content ) {
		if ( is_admin() || wp_doing_ajax() || ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( false === stripos( $content, '<a' ) ) {
			return $content;
		}
		// Singular views and posts inside the main loop (e.g. blog home excerpts).
		if ( ! is_singular() && ! in_the_loop() ) {
			return $content;
		}
		$post_id = (int) get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}
		$broken_urls = $this->db->get_broken_link_urls_for_post( $post_id );
		if ( empty( $broken_urls ) ) {
			return $content;
		}
		// Add rel="nofollow" to each broken link.
		$matched = array();
		foreach ( $broken_urls as $url ) {
			foreach ( TSOLIIN_Scanner::get_href_match_variants( (string) $url, $post_id ) as $variant ) {
				if ( '' === $variant || isset( $matched[ $variant ] ) ) {
					continue;
				}
				$matched[ $variant ] = true;
				$escaped = preg_quote( $variant, '#' );
				$replaced = preg_replace_callback(
					'#<a(\s[^>]*href\s*=\s*["\']' . $escaped . '["\'][^>]*)>#i',
					static function ( $m ) {
						$attrs = $m[1];
						// Add nofollow to existing rel or create new one.
						if ( preg_match( '/\brel\s*=\s*["\'](.*?)["\']/i', $attrs, $rm ) ) {
							$rels = array_filter( array_map( 'trim', explode( ' ', $rm[1] ) ) );
							if ( ! in_array( 'nofollow', $rels, true ) ) {
								$rels[] = 'nofollow';
							}
							$attrs = preg_replace( '/\brel\s*=\s*["\'](.*?)["\']/i', 'rel="' . implode( ' ', $rels ) . '"', $attrs );
						} else {
							$attrs .= ' rel="nofollow"';
						}
						return '<a' . $attrs . '>';
					},
					$content
				);
				if ( is_string( $replaced ) ) {
					$content = $replaced;
				}
			}
		}
		return $content;
	}
}

/**
 * Return the main plugin instance.
 *
 * @return TSOLIIN_Link_Inspector
 */
function tsoliin_link_inspector() {
	return TSOLIIN_Link_Inspector::get_instance();
}

/**
 * Activation callback (must be registered at file scope, not on plugins_loaded).
 *
 * @return void
 */
function tsoliin_activate() {
	tsoliin_link_inspector()->on_activate();
}

/**
 * Deactivation callback (must be registered at file scope, not on plugins_loaded).
 *
 * @return void
 */
function tsoliin_deactivate() {
	tsoliin_link_inspector()->on_deactivate();
}

register_activation_hook( TSOLIIN_PLUGIN_FILE, 'tsoliin_activate' );
register_deactivation_hook( TSOLIIN_PLUGIN_FILE, 'tsoliin_deactivate' );

add_action( 'plugins_loaded', 'tsoliin_link_inspector' );
