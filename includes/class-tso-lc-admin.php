<?php
/**
 * Admin pages, menus, and AJAX handlers.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Admin
 */
class TSOLIIN_Admin {

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_Scanner */
	private $scanner;

	/** @var TSOLIIN_HTTP */
	private $http;

	/** @var TSOLIIN_Cron */
	private $cron;

	/** @var string */
	private $page_hook = '';

	/** @var string */
	private $settings_page_hook = '';

	/** @var array<string,mixed>|null Request-cached background check progress. */
	private $bg_progress_cache = null;

	/** @var array<string,mixed>|null Request-cached background scan progress. */
	private $bg_scan_progress_cache = null;

	public function __construct( TSOLIIN_DB $db, TSOLIIN_Scanner $scanner, TSOLIIN_HTTP $http, TSOLIIN_Cron $cron ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->http    = $http;
		$this->cron    = $cron;

		add_action( 'admin_menu',             array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_page_title',         array( $this, 'filter_settings_admin_page_title' ) );
		add_filter( 'plugin_row_meta',          array( $this, 'filter_plugin_row_meta' ), 10, 2 );
		add_filter( 'set_screen_option_tsoliin_per_page', array( $this, 'filter_screen_option_per_page' ), 10, 3 );

		add_action( 'wp_ajax_tsoliin_scan_batch',    array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_tsoliin_recheck',       array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_tsoliin_update_link',   array( $this, 'ajax_update_link' ) );
		add_action( 'wp_ajax_tsoliin_unlink',        array( $this, 'ajax_unlink' ) );
		add_action( 'wp_ajax_tsoliin_delete_link',   array( $this, 'ajax_delete_link' ) );
		add_action( 'wp_ajax_tsoliin_not_broken',    array( $this, 'ajax_not_broken' ) );
		add_action( 'wp_ajax_tsoliin_bulk_action',   array( $this, 'ajax_bulk_action' ) );
		add_action( 'wp_ajax_tsoliin_start_bg_check',  array( $this, 'ajax_start_bg_check' ) );
		add_action( 'wp_ajax_tsoliin_bg_check_tick',   array( $this, 'ajax_bg_check_tick' ) );
		add_action( 'wp_ajax_tsoliin_stop_bg_check',   array( $this, 'ajax_stop_bg_check' ) );
		add_action( 'wp_ajax_tsoliin_start_bg_scan',   array( $this, 'ajax_start_bg_scan' ) );
		add_action( 'wp_ajax_tsoliin_bg_scan_tick',    array( $this, 'ajax_bg_scan_tick' ) );
		add_action( 'wp_ajax_tsoliin_bg_keep_alive',   array( $this, 'ajax_bg_keep_alive' ) );
		add_action( 'wp_ajax_tsoliin_stop_bg_scan',    array( $this, 'ajax_stop_bg_scan' ) );
		add_action( 'wp_ajax_tsoliin_discard_bg_jobs', array( $this, 'ajax_discard_bg_jobs' ) );
		add_action( 'wp_ajax_tsoliin_check_progress',  array( $this, 'ajax_check_progress' ) );
		add_action( 'wp_ajax_tsoliin_get_stats',       array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_tsoliin_smart_suggest',   array( $this, 'ajax_smart_suggest' ) );
		add_action( 'wp_ajax_tsoliin_diagnose',        array( $this, 'ajax_diagnose' ) );
		add_action( 'admin_post_tsoliin_export_csv',   array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_tsoliin_export_pdf',   array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_tsoliin_reset_all',    array( $this, 'handle_reset_all' ) );
		add_action( 'wp_ajax_tsoliin_add_ignore',      array( $this, 'ajax_add_ignore' ) );
		add_action( 'wp_ajax_tsoliin_dismiss_onboarding', array( $this, 'ajax_dismiss_onboarding' ) );
		add_action( 'wp_ajax_tsoliin_make_relative',     array( $this, 'ajax_make_relative' ) );
		add_action( 'wp_ajax_tsoliin_upgrade_https',     array( $this, 'ajax_upgrade_https' ) );
		add_action( 'wp_ajax_tsoliin_link_preview',      array( $this, 'ajax_link_preview' ) );
		add_action( 'wp_ajax_tsoliin_search_list',       array( $this, 'ajax_search_list' ) );
	}

	/**
	 * Background check progress for this admin request (single read).
	 *
	 * @return array{ running: bool, checked: int, total: int, pct: int, post_id: int, pending?: int }
	 */
	private function get_cached_bg_progress() {
		if ( null === $this->bg_progress_cache ) {
			$this->bg_progress_cache = $this->cron->get_bg_progress();
		}
		return $this->bg_progress_cache;
	}

	/**
	 * Background scan progress for this admin request (single read).
	 *
	 * @return array{ running: bool, scanned: int, total: int, pct: int, complete: bool, resumable: bool, error: string, done: bool }
	 */
	private function get_cached_bg_scan_progress() {
		if ( null === $this->bg_scan_progress_cache ) {
			$this->bg_scan_progress_cache = $this->cron->get_bg_scan_progress();
		}
		return $this->bg_scan_progress_cache;
	}

	/**
	 * Inline boot script: apply saved theme before first paint (no jQuery).
	 *
	 * Auto mode uses the same sunrise/sunset logic as the admin UI (site coords),
	 * not a fixed 07:00–20:00 window (that caused a night→day flash near dusk).
	 *
	 * @return string
	 */
	private function get_theme_boot_script() {
		$coords = TSOLIIN_Support::theme_coords();
		$lat    = wp_json_encode( (float) $coords['lat'] );
		$lng    = wp_json_encode( (float) $coords['lng'] );

		return '(function () {
	var KEY = "tsoliin_ui_theme";
	var LAT = ' . $lat . ';
	var LNG = ' . $lng . ';
	var DAY_MS = 86400000;
	var J1970 = 2440588;
	var J2000 = 2451545;
	var RAD = Math.PI / 180;
	var E = RAD * 23.4397;
	function readPref() {
		var p = "";
		try { p = localStorage.getItem(KEY) || ""; } catch (e) { p = ""; }
		if (p === "day" || p === "night" || p === "auto") {
			return p;
		}
		return "auto";
	}
	function toJulian(date) { return date.valueOf() / DAY_MS - 0.5 + J1970; }
	function fromJulian(j) { return new Date((j + 0.5 - J1970) * DAY_MS); }
	function toDays(date) { return toJulian(date) - J2000; }
	function rightAscension(l, b) {
		return Math.atan2(Math.sin(l) * Math.cos(E) - Math.tan(b) * Math.sin(E), Math.cos(l));
	}
	function declination(l, b) {
		return Math.asin(Math.sin(b) * Math.cos(E) + Math.cos(b) * Math.sin(E) * Math.sin(l));
	}
	function solarMeanAnomaly(d) { return RAD * (357.5291 + 0.98560028 * d); }
	function eclipticLongitude(M) {
		var C = RAD * (1.9148 * Math.sin(M) + 0.02 * Math.sin(2 * M) + 0.0003 * Math.sin(3 * M));
		return M + C + RAD * 102.9372 + Math.PI;
	}
	function sunCoords(d) {
		var M = solarMeanAnomaly(d);
		var L = eclipticLongitude(M);
		return { dec: declination(L, 0), ra: rightAscension(L, 0) };
	}
	function julianCycle(d, lw) { return Math.round(d - 0.0009 - lw / (2 * Math.PI)); }
	function approxTransit(Ht, lw, n) { return 0.0009 + (Ht + lw) / (2 * Math.PI) + n; }
	function solarTransitJ(ds, M, L) {
		return J2000 + ds + 0.0053 * Math.sin(M) - 0.0069 * Math.sin(2 * L);
	}
	function hourAngle(h, phi, d) {
		return Math.acos((Math.sin(h) - Math.sin(phi) * Math.sin(d)) / (Math.cos(phi) * Math.cos(d)));
	}
	function getSetJ(h, lw, phi, dec, n, M, L) {
		var w = hourAngle(h, phi, dec);
		var a = approxTransit(w, lw, n);
		return solarTransitJ(a, M, L);
	}
	function sunTimes(date, lat, lng) {
		try {
			var lw = RAD * -lng;
			var phi = RAD * lat;
			var d = toDays(date);
			var n = julianCycle(d, lw);
			var ds = approxTransit(0, lw, n);
			var M = solarMeanAnomaly(ds);
			var L = eclipticLongitude(M);
			var dec = sunCoords(ds).dec;
			var Jnoon = solarTransitJ(ds, M, L);
			var Jset = getSetJ(-0.833 * RAD, lw, phi, dec, n, M, L);
			var Jrise = Jnoon - (Jset - Jnoon);
			var sunrise = fromJulian(Jrise);
			var sunset = fromJulian(Jset);
			if (isNaN(sunrise.getTime()) || isNaN(sunset.getTime())) {
				return null;
			}
			return { sunrise: sunrise, sunset: sunset };
		} catch (e) {
			return null;
		}
	}
	function fromSolar() {
		var now = new Date();
		var times = sunTimes(now, LAT, LNG);
		if (!times) {
			var h = now.getHours();
			return (h >= 7 && h < 20) ? "day" : "night";
		}
		return (now >= times.sunrise && now < times.sunset) ? "day" : "night";
	}
	function resolve(pref) {
		if (pref === "day" || pref === "night") {
			return pref;
		}
		return fromSolar();
	}
	function apply(root) {
		var pref = readPref();
		var theme = resolve(pref);
		try {
			document.documentElement.setAttribute("data-tsoliin-theme", theme);
			document.documentElement.setAttribute("data-tsoliin-theme-pref", pref);
		} catch (e1) { /* ignore */ }
		if (document.body) {
			document.body.setAttribute("data-tsoliin-theme", theme);
		}
		var wraps = [];
		if (root && root.nodeType === 1 && root.classList && root.classList.contains("tsoliin-wrap")) {
			wraps.push(root);
		}
		var scope = root && root.querySelectorAll ? root : document;
		if (scope && scope.querySelectorAll) {
			var found = scope.querySelectorAll(".tsoliin-wrap");
			for (var i = 0; i < found.length; i++) {
				wraps.push(found[i]);
			}
		}
		for (var j = 0; j < wraps.length; j++) {
			wraps[j].setAttribute("data-theme", theme);
			wraps[j].setAttribute("data-theme-pref", pref);
		}
	}
	apply(document);
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", function () { apply(document); });
	}
	if (typeof MutationObserver !== "undefined") {
		var mo = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var nodes = mutations[i].addedNodes;
				for (var j = 0; j < nodes.length; j++) {
					var n = nodes[j];
					if (!n || n.nodeType !== 1) {
						continue;
					}
					if (n.classList && n.classList.contains("tsoliin-wrap")) {
						apply(n);
					} else if (n.querySelector && n.querySelector(".tsoliin-wrap")) {
						apply(n);
					}
				}
			}
		});
		mo.observe(document.documentElement, { childList: true, subtree: true });
		document.addEventListener("DOMContentLoaded", function () {
			mo.disconnect();
			apply(document);
		});
	}
})();';
	}

	// =========================================================================
	// MENU
	// =========================================================================

	public function register_menu() {
		$this->page_hook = add_management_page(
			__( 'TSO Link Inspector', 'tso-link-inspector' ),
			__( 'TSO Link Inspector', 'tso-link-inspector' ),
			'manage_options',
			'tso-link-inspector',
			array( $this, 'render_main_page' )
		);
		$this->settings_page_hook = add_submenu_page(
			'tools.php',
			__( 'TSO Link Inspector - Settings', 'tso-link-inspector' ),
			__( 'Settings', 'tso-link-inspector' ),
			'manage_options',
			'tso-link-inspector-settings',
			array( $this, 'render_settings_page' )
		);
		// Keep Settings accessible by URL but hidden from the Tools submenu.
		remove_submenu_page( 'tools.php', 'tso-link-inspector-settings' );

		if ( $this->page_hook ) {
			add_action( 'load-' . $this->page_hook, array( $this, 'prepare_main_screen' ) );
			add_action( 'admin_head-' . $this->page_hook, array( $this, 'print_color_scheme_meta' ) );
		}

		if ( $this->settings_page_hook ) {
			add_action( 'load-' . $this->settings_page_hook, array( $this, 'prepare_settings_screen' ) );
			add_action( 'admin_head-' . $this->settings_page_hook, array( $this, 'print_color_scheme_meta' ) );
		}
	}

	/**
	 * Tell the browser itself (not just our CSS) which color scheme this page
	 * uses, via <meta name="color-scheme">. Support for this is what makes the
	 * browser paint its own default canvas background (before any stylesheet
	 * loads) in the matching color instead of white — the remaining source of
	 * the F5 white flash in night mode that wp_add_inline_style() cannot reach,
	 * since that only affects the CSS cascade, not the browser's pre-CSS paint.
	 */
	public function print_color_scheme_meta() {
		$scheme = TSOLIIN_Support::theme_is_daytime_now() ? 'light' : 'dark';
		echo '<meta name="color-scheme" content="' . esc_attr( $scheme ) . '">' . "\n";
	}

	/**
	 * Screen options for the main link list (rows per page).
	 */
	public function prepare_main_screen() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Links per page', 'tso-link-inspector' ),
				'default' => TSOLIIN_Support::get_list_per_page_default(),
				'option'  => 'tsoliin_per_page',
			)
		);
	}

	/**
	 * Cap per-page screen option before it is saved (prevents unusably high values).
	 *
	 * @param mixed  $status Screen option save status.
	 * @param string $option Option name.
	 * @param mixed  $value  Requested value.
	 * @return int|false
	 */
	public function filter_screen_option_per_page( $status, $option, $value ) {
		if ( 'tsoliin_per_page' !== $option ) {
			return $status;
		}
		return TSOLIIN_Support::cap_list_per_page( $value );
	}

	/**
	 * Whether the current request is the plugin settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		global $pagenow;
		if ( ! is_admin() || 'tools.php' !== $pagenow ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'tso-link-inspector-settings' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Ensure admin header receives a string title (avoids strip_tags(null) on hidden submenu pages).
	 *
	 * @param string|null $page_title Title from core.
	 * @return string
	 */
	public function filter_settings_admin_page_title( $page_title ) {
		if ( ! $this->is_settings_screen() ) {
			return is_string( $page_title ) ? $page_title : '';
		}
		return __( 'TSO Link Inspector - Settings', 'tso-link-inspector' );
	}

	/**
	 * Add a Donate link on the Plugins screen.
	 *
	 * @param string[] $links Plugin row links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public function filter_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( TSOLIIN_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( TSOLIIN_Support::get_kofi_donate_url() ),
			esc_html( TSOLIIN_Support::get_donate_link_label() )
		);
		return $links;
	}

	/**
	 * Set globals before admin-header.php runs on the hidden settings page.
	 */
	public function prepare_settings_screen() {
		global $title, $parent_file, $submenu_file;
		$title        = __( 'TSO Link Inspector - Settings', 'tso-link-inspector' );
		$parent_file  = 'tools.php';
		$submenu_file = 'tso-link-inspector';
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	public function enqueue_assets( $hook ) {
		$our_pages      = array( $this->page_hook, 'tools_page_tso-link-inspector-settings', 'admin_page_tso-link-inspector-settings' );
		$is_plugin_page = in_array( $hook, $our_pages, true );

		if ( current_user_can( 'manage_options' ) ) {
			$this->enqueue_bg_worker( $is_plugin_page );
		}

		if ( ! $is_plugin_page ) {
			return;
		}
		$admin_css = TSOLIIN_PLUGIN_DIR . 'assets/css/admin.css';
		$admin_js  = TSOLIIN_PLUGIN_DIR . 'assets/js/admin.js';
		$css_ver   = is_readable( $admin_css ) ? (string) filemtime( $admin_css ) : TSOLIIN_VERSION;
		$js_ver    = is_readable( $admin_js ) ? (string) filemtime( $admin_js ) : TSOLIIN_VERSION;
		wp_enqueue_style( 'tsoliin-admin', TSOLIIN_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );

		// Paint the WP admin chrome dark immediately, via inline CSS attached to
		// the stylesheet above, using the same server-side day/night guess as the
		// .tsoliin-wrap itself. WordPress prints admin_print_styles BEFORE
		// admin_print_scripts, so this applies at CSS-parse time — before the
		// theme-boot script below even runs — closing the white flash on a hard
		// refresh (F5) in night mode. The boot script still runs right after and
		// corrects this for the one case PHP cannot know: an explicit day/night
		// override saved in localStorage (see the html[data-tsoliin-theme="day"]
		// rule in admin.css that undoes this if the resolved theme is "day").
		if ( ! TSOLIIN_Support::theme_is_daytime_now() ) {
			wp_add_inline_style(
				'tsoliin-admin',
				'html,body.tools_page_tso-link-inspector,body.tools_page_tso-link-inspector-settings,body.admin_page_tso-link-inspector-settings,' .
				'body.tools_page_tso-link-inspector #wpcontent,body.tools_page_tso-link-inspector #wpbody,body.tools_page_tso-link-inspector #wpbody-content,' .
				'body.tools_page_tso-link-inspector-settings #wpcontent,body.tools_page_tso-link-inspector-settings #wpbody,body.tools_page_tso-link-inspector-settings #wpbody-content,' .
				'body.admin_page_tso-link-inspector-settings #wpcontent,body.admin_page_tso-link-inspector-settings #wpbody,body.admin_page_tso-link-inspector-settings #wpbody-content,' .
				// The left admin menu column (its own DOM branch, a sibling of #wpcontent,
				// not a descendant) is the "white bar in the left margin" reported by the
				// user: its background comes from the site's chosen admin color scheme,
				// not from this plugin, so it was never covered by the rule above.
				'body.tools_page_tso-link-inspector #adminmenumain,body.tools_page_tso-link-inspector #adminmenuback,body.tools_page_tso-link-inspector #adminmenuwrap,' .
				'body.tools_page_tso-link-inspector-settings #adminmenumain,body.tools_page_tso-link-inspector-settings #adminmenuback,body.tools_page_tso-link-inspector-settings #adminmenuwrap,' .
				'body.admin_page_tso-link-inspector-settings #adminmenumain,body.admin_page_tso-link-inspector-settings #adminmenuback,body.admin_page_tso-link-inspector-settings #adminmenuwrap' .
				'{background:#12141c !important;}'
			);
		}

		// Apply saved day/night theme before first paint (avoids light flash on navigation).
		wp_register_script( 'tsoliin-theme-boot', false, array(), TSOLIIN_VERSION, false );
		wp_enqueue_script( 'tsoliin-theme-boot' );
		wp_add_inline_script( 'tsoliin-theme-boot', $this->get_theme_boot_script() );
		$scroll_head_js  = TSOLIIN_PLUGIN_DIR . 'assets/js/list-scroll-head.js';
		$scroll_js       = TSOLIIN_PLUGIN_DIR . 'assets/js/list-scroll.js';
		$scroll_head_ver = is_readable( $scroll_head_js ) ? (string) filemtime( $scroll_head_js ) : TSOLIIN_VERSION;
		$scroll_ver      = is_readable( $scroll_js ) ? (string) filemtime( $scroll_js ) : TSOLIIN_VERSION;
		wp_enqueue_script( 'tsoliin-list-scroll-head', TSOLIIN_PLUGIN_URL . 'assets/js/list-scroll-head.js', array(), $scroll_head_ver, false );
		wp_enqueue_script( 'tsoliin-list-scroll', TSOLIIN_PLUGIN_URL . 'assets/js/list-scroll.js', array(), $scroll_ver, true );
		wp_enqueue_script( 'tsoliin-admin', TSOLIIN_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );

		$bg      = $this->get_cached_bg_progress();
		$bg_scan = $this->get_cached_bg_scan_progress();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_view_raw = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'links';
		$list_view     = in_array( $list_view_raw, array( 'links', 'posts', 'products' ), true ) ? $list_view_raw : 'links';
		if ( $view_post_id > 0 ) {
			$list_view = 'links';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		if ( in_array( $list_filter, $this->get_allowed_quality_filters(), true ) ) {
			$list_filter = 'all';
		} elseif ( ! in_array( $list_filter, $this->get_allowed_status_filters(), true ) ) {
			$list_filter = 'all';
		}
		$list_quality = $this->get_list_quality_filter_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_scope = $this->get_list_scope_from_request();

		$user_id      = get_current_user_id();
		$theme_coords = TSOLIIN_Support::theme_coords();

		wp_localize_script( 'tsoliin-admin', 'tsoliinData', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'tsoliin_action' ),
			'timezone'   => TSOLIIN_Support::theme_timezone_string(),
			'lat'        => $theme_coords['lat'],
			'lng'        => $theme_coords['lng'],
			'viewPostId' => $view_post_id,
			'listView'   => $list_view,
			'listFilter'        => $list_filter,
			'listQualityFilter' => $list_quality,
			'listScope'         => $list_scope,
			'listOrderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_found', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'listOrder'   => isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'settingsUrl' => admin_url( 'tools.php?page=tso-link-inspector-settings' ),
			'helpUrl'     => admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' ),
			'historyUrl'  => admin_url( 'tools.php?page=tso-link-inspector-settings&tab=history' ),
			'onboardingDismissed' => (int) (bool) get_user_meta( $user_id, 'tsoliin_onboarding_dismissed', true ),
			'relativeUrlTool'     => TSOLIIN_Support::is_relative_url_tool_enabled() ? 1 : 0,
			'createRevision'      => TSOLIIN_Support::is_create_revision_enabled() ? 1 : 0,
			'brokenFilterUrl' => admin_url( 'tools.php?page=tso-link-inspector&filter=broken' ),
			'filterTabs' => array(
				/* translators: %s: number of links */
				'all'           => __( 'All (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of broken links */
				'broken'        => __( 'Broken (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of redirected links */
				'redirect'      => __( 'Redirect (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of OK links */
				'ok'            => __( 'OK (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of unchecked links */
				'unchecked'     => __( 'Unchecked (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of HTTP insecure links */
				'http_insecure' => __( 'HTTP insecure (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of manually locked links */
				'manual_locked' => __( 'Manual locks (%s)', 'tso-link-inspector' ),
			),
			'qualityFilterTabs' => array(
				/* translators: %s: number of links with empty anchor text */
				'empty_anchor'       => __( 'Empty anchor (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of links with generic anchor text */
				'generic_anchor'     => __( 'Generic anchor (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of links to unpublished posts */
				'unpublished_target' => __( 'Unpublished target (%s)', 'tso-link-inspector' ),
			),
			'totalPosts' => $this->scanner->get_total_posts(),
			'batchSize'  => TSOLIIN_BATCH_SIZE,
			'bgRunning'  => $bg['running'] ? 1 : 0,
			'bgChecked'  => $bg['checked'],
			'bgTotal'    => $bg['total'],
			'bgPct'      => $bg['pct'],
			'bgPostId'   => isset( $bg['post_id'] ) ? absint( $bg['post_id'] ) : 0,
			'bgPending'  => isset( $bg['pending'] ) ? absint( $bg['pending'] ) : 0,
			'bgComplete' => ! empty( $bg['complete'] ) ? 1 : 0,
			'scanRunning' => $bg_scan['running'] ? 1 : 0,
			'scanScanned' => $bg_scan['scanned'],
			'scanTotal'   => $bg_scan['total'],
			'scanPct'     => $bg_scan['pct'],
			'scanResumable' => ! empty( $bg_scan['resumable'] ) ? 1 : 0,
			'scanError'   => isset( $bg_scan['error'] ) ? (string) $bg_scan['error'] : '',
			// Request-cached COUNT; same key as get_bg_progress() when not filtering by post.
			'pendingCheck' => (int) $this->db->get_pending_check_count( absint( $view_post_id ) ),
			'checkPaused'  => (
				$this->cron->is_bg_check_paused()
				&& (int) $this->db->get_pending_check_count( absint( $view_post_id ) ) > 0
			) ? 1 : 0,
			'checkAutoResume' => (
				! $this->cron->is_bg_scan_blocking_check()
				&& ! get_option( 'tsoliin_bg_check_user_stopped' )
				&& (int) $bg['pct'] > 0
				&& (int) $bg['pct'] < 100
				&& $this->db->get_pending_check_count( absint( isset( $bg['post_id'] ) ? $bg['post_id'] : 0 ) ) > 0
			) ? 1 : 0,
			'refreshInterval' => 8000, // ms between stat card auto-refreshes
			'i18n' => array(
				'scanning'      => __( 'Scanning...', 'tso-link-inspector' ),
				'scanDone'      => __( 'Scan completed!', 'tso-link-inspector' ),
				'checking'      => __( 'Checking...', 'tso-link-inspector' ),
				'checkDone'     => __( 'Check completed!', 'tso-link-inspector' ),
				'checkCompleteWaiting' => __( 'Check completed. Waiting for other actions to finish…', 'tso-link-inspector' ),
				'checkStarted'  => __( 'Check started. You can continue browsing.', 'tso-link-inspector' ),
				'checkResumed'  => __( 'Resuming check from where it left off. You can continue browsing.', 'tso-link-inspector' ),
				'stopped'       => __( 'Stopped', 'tso-link-inspector' ),
				'sessionExpired'=> __( 'Your admin session expired. Reload this page to keep monitoring the background task.', 'tso-link-inspector' ),
				'checkPaused'   => __( 'Check paused. Click Continue check.', 'tso-link-inspector' ),
				'scanNow'       => __( 'Scan now', 'tso-link-inspector' ),
				'continueScan'  => __( 'Continue scan', 'tso-link-inspector' ),
				'restartScan'   => __( 'Restart scan', 'tso-link-inspector' ),
				'discardScan'   => __( 'Discard scan', 'tso-link-inspector' ),
				'discardCheck'  => __( 'Discard check', 'tso-link-inspector' ),
				'discardAll'    => __( 'Discard all paused', 'tso-link-inspector' ),
				'scanStarted'   => __( 'Scan started. You can continue browsing.', 'tso-link-inspector' ),
				'scanResumed'   => __( 'Resuming scan from where it left off. You can continue browsing.', 'tso-link-inspector' ),
				'confirmDiscardScan' => __( 'Discard this paused scan? Links already found stay in the list; only scan progress is cleared. You can run Scan now again later.', 'tso-link-inspector' ),
				'confirmDiscardCheck' => __( 'Discard this paused check? HTTP results already saved are kept; the progress bar is cleared. Unchecked links remain for a future Check now.', 'tso-link-inspector' ),
				'confirmDiscardAll' => __( 'Discard all paused scan and check tasks? Found links and saved HTTP results are kept; progress bars are cleared.', 'tso-link-inspector' ),
				'confirmRestartScan' => __( 'Restart scan from the beginning? Already-found links stay in the list; posts will be read again from the first item.', 'tso-link-inspector' ),
				'checkNow'      => __( 'Check now', 'tso-link-inspector' ),
				'checkThisPost' => __( 'Check this post', 'tso-link-inspector' ),
				'continueCheck' => __( 'Continue check', 'tso-link-inspector' ),
				'continueThisPost' => __( 'Continue this post', 'tso-link-inspector' ),
				'restartCheck'  => __( 'Restart from zero', 'tso-link-inspector' ),
				'stop'          => __( 'Stop', 'tso-link-inspector' ),
				'stopScan'      => __( 'Stop scan', 'tso-link-inspector' ),
				'scanStopped'   => __( 'Scan stopped.', 'tso-link-inspector' ),
				'scanThenCheck' => __( 'Scan complete. Starting HTTP check…', 'tso-link-inspector' ),
				'confirmFullCheck' => __( 'Check now will send an HTTP request to every link saved in the database. On large sites this can take a long time. Continue?', 'tso-link-inspector' ),
				'confirmResumeCheck' => __( 'There are unchecked links left. Continue from where the last check stopped?', 'tso-link-inspector' ),
				'confirmRestartCheck' => __( 'Restart from zero? Every link will be rechecked from scratch, including ones already checked in this run.', 'tso-link-inspector' ),
				'confirmScanWhileCheck' => __( 'A background check is still running. Start a new scan anyway?', 'tso-link-inspector' ),
				'confirmCheckWhileScan' => __( 'A scan is still running. Start checking anyway?', 'tso-link-inspector' ),
				'recheck'       => __( 'Recheck', 'tso-link-inspector' ),
				'saving'        => __( 'Saving...', 'tso-link-inspector' ),
				'urlSaved'      => __( 'URL updated successfully.', 'tso-link-inspector' ),
				'urlUpdated'    => __( 'URL updated:', 'tso-link-inspector' ),
				'notBrokenDone' => __( 'Marked as OK and moved to Manual locks. It leaves that list only if the URL or redirect changes, or a check finds it broken.', 'tso-link-inspector' ),
				'confirmNotBroken' => __( 'Mark this link as OK?', 'tso-link-inspector' ) . "\n\n"
					. __( '• It moves to Manual locks and leaves the Broken/Redirect lists.', 'tso-link-inspector' ) . "\n"
					. __( '• Background checks (Check now / cron) still run.', 'tso-link-inspector' ) . "\n"
					. __( '• It returns to the normal lists only if the URL or redirect changes, or a check finds it broken again.', 'tso-link-inspector' ),
				'confirmNotBrokenBulk' => __( 'Mark the selected links as OK?', 'tso-link-inspector' ) . "\n\n"
					. __( 'They move to Manual locks and leave the Broken/Redirect lists. Background checks still run. They return to the normal lists only if the URL or redirect changes, or a check finds them broken again.', 'tso-link-inspector' ),
				'diagnosi'      => __( 'Diagnostics', 'tso-link-inspector' ),
				'diagChecking'  => __( 'Running diagnostics...', 'tso-link-inspector' ),
				'diagResult'    => __( 'Diagnostics result:', 'tso-link-inspector' ),
				'smartChecking' => __( 'Looking for alternatives...', 'tso-link-inspector' ),
				'smartSuggest'  => __( 'Suggested URL', 'tso-link-inspector' ),
				'noSuggestions'    => __( 'No working alternative was found for this link.', 'tso-link-inspector' ),
				'menuSuggestNote'  => __( 'This menu item URL comes from the linked page/post. On block themes use Site Editor → Navigation, or edit that page directly.', 'tso-link-inspector' ),
				'wooSuggestNote'   => __( 'WooCommerce product field URLs cannot be updated from suggestions. Change them in the product editor using Go to edit.', 'tso-link-inspector' ),
				'detectedRedirect' => __( 'Redirect destination already detected', 'tso-link-inspector' ),
				'applyUrl'      => __( 'Apply', 'tso-link-inspector' ),
				'applyAnyway'   => __( 'Apply anyway', 'tso-link-inspector' ),
				'applyAnywayIgnore' => __( 'Apply and ignore domain', 'tso-link-inspector' ),
				'confirmApplyAnyway' => __( 'This server cannot confirm the URL (geo-block, bot wall, or timeout). Save it anyway?', 'tso-link-inspector' ),
				'confirmApplyAnywayIgnore' => __( 'Save this URL and add its domain to the ignore list? Future scans will skip this host.', 'tso-link-inspector' ),
				'staleRowGone'  => __( 'This list row is already gone (the link was updated or removed from the content).', 'tso-link-inspector' ),
				'itemsChecked'  => __( 'links rechecked.', 'tso-link-inspector' ),
				'itemsUnlinked' => __( 'links unlinked.', 'tso-link-inspector' ),
				'itemsSkipped'  => __( 'rows skipped (menu/widget/term).', 'tso-link-inspector' ),
				'itemsSkippedHttps' => __( 'links skipped (HTTPS not verified or not editable).', 'tso-link-inspector' ),
				'itemsFailed'   => __( 'requests failed.', 'tso-link-inspector' ),
				'unlinking'     => __( 'Unlinking…', 'tso-link-inspector' ),
				'confirmUnlink' => __( 'Are you sure? The text will remain but the link will be removed.', 'tso-link-inspector' ),
				'confirmUnlinkBulk' => __( 'Unlink the selected items? Anchor text stays; the link markup is removed from the source when possible.', 'tso-link-inspector' ),
				'confirmDelete' => __( 'Delete this record from the list. The post will not be changed. Continue?', 'tso-link-inspector' ),
				'noItemsSelected' => __( 'Select at least one link first.', 'tso-link-inspector' ),
				'bulkBusy'      => __( 'A bulk action is already running. Wait for it to finish.', 'tso-link-inspector' ),
				'error'         => __( 'An error occurred.', 'tso-link-inspector' ),
				'scanFailed'    => __( 'Scan failed.', 'tso-link-inspector' ),
				'save'          => __( 'Save URL', 'tso-link-inspector' ),
				'cancel'        => __( 'Cancel', 'tso-link-inspector' ),
				'notBroken'     => __( 'Not broken', 'tso-link-inspector' ),
				'rechecking'    => __( 'Rechecking…', 'tso-link-inspector' ),
				'urlRequired'   => __( 'Please enter a valid URL.', 'tso-link-inspector' ),
				'noChanges'     => __( 'No changes to save.', 'tso-link-inspector' ),
				'editLink'      => __( 'Edit link', 'tso-link-inspector' ),
				'linkText'      => __( 'Link text:', 'tso-link-inspector' ),
				'commentLabel'  => __( 'Link text in comment (read-only):', 'tso-link-inspector' ),
				'commentLabelNote' => __( 'Only the URL can be changed here. To edit the visible link text, open the comment in WordPress.', 'tso-link-inspector' ),
				'altText'       => __( 'Alt text:', 'tso-link-inspector' ),
				'anchorWarning' => __( 'URL updated, but link text could not be changed in the post. Edit the post manually or leave the link text field unchanged next time.', 'tso-link-inspector' ),
				'unlink'        => __( 'Unlink', 'tso-link-inspector' ),
				'closePanel'    => __( 'Close', 'tso-link-inspector' ),
				'actionUrlWarn' => __( 'This link ends your WordPress session. Open it anyway?', 'tso-link-inspector' ),
				'addIgnore'     => __( 'Ignore domain', 'tso-link-inspector' ),
				/* translators: %s: domain or URL pattern added to the ignore list */
				'confirmAddIgnore' => __( 'Add %s to the ignore list? This link will be skipped during scans and HTTP checks. You can edit the list in Settings.', 'tso-link-inspector' ),
				'ignoreAdded'   => __( 'Added to ignore list. This link is now skipped.', 'tso-link-inspector' ),
				'ignoreAlready' => __( 'This domain or URL is already on the ignore list.', 'tso-link-inspector' ),
				'ignoreFailed'  => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ),
				'onboardingDismiss' => __( 'Dismiss', 'tso-link-inspector' ),
				'onboardingHelp'    => __( 'Read help', 'tso-link-inspector' ),
				'makeRelative'      => __( 'Convert to /path', 'tso-link-inspector' ),
				'confirmMakeRelative' => __( 'Remove the site domain from this link? It will be saved as a path starting with / (e.g. /contact/). Only for links on this site.', 'tso-link-inspector' ),
				'confirmMakeRelativeBulk' => __( 'Remove the site domain from the selected same-site links and save them as /path URLs?', 'tso-link-inspector' ),
				'upgradeHttps'          => __( 'Upgrade to HTTPS', 'tso-link-inspector' ),
				'confirmUpgradeHttps'   => __( 'Upgrade this URL to HTTPS? The plugin will only apply it if HTTPS is reachable.', 'tso-link-inspector' ),
				'confirmUpgradeHttpsBulk' => __( 'Upgrade the selected http:// links to https:// where the server confirms HTTPS works?', 'tso-link-inspector' )
					. "\n\n" . __( 'Links without a verified HTTPS response are skipped.', 'tso-link-inspector' ),
				'httpsUpgraded'         => __( 'Link upgraded to HTTPS.', 'tso-link-inspector' ),
				'upgradeHttpsFailed'    => __( 'HTTPS could not be verified for this URL.', 'tso-link-inspector' ),
				'relativeDone'      => __( 'Link saved as /path (domain removed).', 'tso-link-inspector' ),
				'relativeDisabled'  => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ),
				'convertingRelative'=> __( 'Converting to /path…', 'tso-link-inspector' ),
				'upgradingHttps'    => __( 'Upgrading to HTTPS…', 'tso-link-inspector' ),
				'markingNotBroken'  => __( 'Marking as OK…', 'tso-link-inspector' ),
				'itemsMarkedOk'     => __( 'marked as OK.', 'tso-link-inspector' ),
				'deleting'          => __( 'Deleting…', 'tso-link-inspector' ),
				'itemsDeleted'      => __( 'records deleted.', 'tso-link-inspector' ),
				'itemsConverted'    => __( 'links converted to /path.', 'tso-link-inspector' ),
				'itemsUpgradedHttps'=> __( 'links upgraded to HTTPS.', 'tso-link-inspector' ),
				'confirmDeleteBulk' => __( 'Delete the selected records from the list? The posts will not be changed.', 'tso-link-inspector' ),
				'previewLoading'    => __( 'Loading preview...', 'tso-link-inspector' ),
				'previewNotFound'   => __( 'No matching HTML tag found in the post for this URL.', 'tso-link-inspector' ),
				'revisionModalNote' => __( 'A WordPress revision will be saved when you save, so you can restore the previous post content from the post editor (Revisions panel).', 'tso-link-inspector' ),
				'revisionSaved'     => __( 'A post revision was saved. Open the article in the editor to view or restore it under Revisions.', 'tso-link-inspector' ),
				'searching'         => __( 'Searching...', 'tso-link-inspector' ),
				'searchBtn'         => __( 'Search', 'tso-link-inspector' ),
				'themeDay'          => __( 'Day mode', 'tso-link-inspector' ),
				'themeNight'        => __( 'Night mode', 'tso-link-inspector' ),
				'themeAuto'         => __( 'Auto mode', 'tso-link-inspector' ),
				'themeAutoHint'     => __( 'Follows sunrise and sunset (changes with the seasons)', 'tso-link-inspector' ),
			),
		) );
	}

	/**
	 * Drive scan/check from any wp-admin screen, including this plugin.
	 *
	 * @param bool $is_plugin_page True on Link Inspector screens (admin.js also ticks).
	 */
	private function enqueue_bg_worker( $is_plugin_page = false ) {
		$js  = TSOLIIN_PLUGIN_DIR . 'assets/js/bg-worker.js';
		$ver = is_readable( $js ) ? (string) filemtime( $js ) : TSOLIIN_VERSION;
		wp_enqueue_script( 'tsoliin-bg-worker', TSOLIIN_PLUGIN_URL . 'assets/js/bg-worker.js', array( 'jquery' ), $ver, true );
		wp_localize_script(
			'tsoliin-bg-worker',
			'tsoliinBgWorker',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'tsoliin_action' ),
				'active'     => ( get_option( 'tsoliin_bg_scan_running' ) || get_option( 'tsoliin_bg_check_running' ) ) ? 1 : 0,
				'hasUiTicks' => $is_plugin_page ? 1 : 0,
			)
		);
	}

	// =========================================================================
	// MAIN PAGE
	// =========================================================================

	public function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$view_post    = $view_post_id ? get_post( $view_post_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'links';
		$posts_view     = ( 'posts' === $list_view && ! $view_post_id );
		$products_view  = ( 'products' === $list_view && ! $view_post_id && class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() );
		$summary_view   = $posts_view || $products_view;

		$table = null;
		if ( ! $summary_view ) {
			$table = new TSOLIIN_List_Table( $this->db, $this->http );
			$table->prepare_items();
		}

		// Detect if we are viewing a single post's links.
		$list_scope = $this->get_scope_from_request();
		$stats      = $view_post_id
			? $this->db->get_stats_for_post( $view_post_id, $list_scope )
			: $this->db->get_stats( $list_scope );
		$bg       = $this->get_cached_bg_progress();
		$bg_scan  = $this->get_cached_bg_scan_progress();
		$last_scan  = (string) get_option( 'tsoliin_last_full_scan', '' );
		$last_check = (string) get_option( 'tsoliin_last_check_batch', '' );
		$total_posts    = $this->scanner->get_total_posts();
		$scanned_stored = (int) get_option( 'tsoliin_total_posts_scanned', 0 );
		$scanned_posts  = $scanned_stored > 0 ? $scanned_stored : $this->db->get_scanned_post_count();
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="wrap tsoliin-wrap" data-theme="' . esc_attr( TSOLIIN_Support::theme_is_daytime_now() ? 'day' : 'night' ) . '" data-theme-pref="auto">';

		echo '<div class="tsoliin-page-head">';
		echo '<h1 class="wp-heading-inline">';
		echo '<span class="dashicons dashicons-admin-links tsoliin-title-icon"></span> ';
		if ( $view_post ) {
			$this->render_plugin_title_with_version( false );
		} elseif ( $products_view ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
			echo esc_html__( 'Products with issues', 'tso-link-inspector' );
		} elseif ( $posts_view ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
			echo esc_html__( 'Posts with issues', 'tso-link-inspector' );
		} else {
			$this->render_plugin_title_with_version( false );
		}
		echo '</h1>';
		echo '<div class="tsoliin-page-head__actions">';
		echo '<div id="tsoliin-screen-meta-slot" class="tsoliin-screen-meta-slot"></div>';
		TSOLIIN_Support::render_theme_toggle();
		TSOLIIN_Support::render_donate_button();
		echo '</div>';
		echo '</div>';
		$this->print_screen_meta_reposition_script();
		echo '<hr class="wp-header-end">';

		echo '<div class="tsoliin-hero">';

		// Stats cards.
		echo '<div class="tsoliin-stats">';
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['total'] ), __( 'Total', 'tso-link-inspector' ), 'total', '', 'all', $view_post_id );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['broken'] ), __( 'Broken', 'tso-link-inspector' ), 'broken', '', 'broken', $view_post_id );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['redirect'] ), __( 'Redirect', 'tso-link-inspector' ), 'redirect', '', 'redirect', $view_post_id );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['ok'] ), __( 'OK', 'tso-link-inspector' ), 'ok', '', 'ok', $view_post_id );
		$this->stat_card(
			TSOLIIN_Support::format_display_number( $stats['unchecked'] ),
			__( 'Unchecked', 'tso-link-inspector' ),
			'unchecked',
			__( 'Links found but not checked by HTTP yet. Cron or Check now will verify them.', 'tso-link-inspector' ),
			'unchecked',
			$view_post_id
		);
		$http_insecure_count = isset( $stats['http_insecure'] ) ? $stats['http_insecure'] : 0;
		if ( $http_insecure_count > 0 ) {
			$this->stat_card(
				TSOLIIN_Support::format_display_number( $http_insecure_count ),
				__( 'HTTP insecure', 'tso-link-inspector' ),
				'http-insecure',
				__( 'Active links using HTTP. Redirecting HTTP links are listed here first; after HTTPS they move to Redirect.', 'tso-link-inspector' ),
				'http_insecure',
				$view_post_id
			);
		}
		$this->stat_card( $scanned_posts . ' / ' . $total_posts, __( 'Scanned', 'tso-link-inspector' ), 'posts' );
		echo '</div>';

		$last_check_count = (int) get_option( 'tsoliin_last_check_count', 0 );
		$schedule         = TSOLIIN_Schedule::get_settings();
		$queue            = TSOLIIN_Schedule::get_queue_stats( $this->db );
		$pending_count    = (int) $queue['pending'];
		$this->render_hero_status_chips(
			$last_scan,
			$last_check,
			$last_check_count,
			$pending_count,
			$queue,
			$schedule,
			$date_fmt
		);

		// Toolbar.
		$pending_check      = (int) $this->db->get_pending_check_count( absint( $view_post_id ) );
		$check_paused       = $this->cron->is_bg_check_paused() && $pending_check > 0;
		$btn_check_disabled = ( $this->cron->is_bg_scan_blocking_check() && ! $bg['running'] ) ? ' disabled' : '';
		$show_restart       = ( ! $bg['running'] && $check_paused );
		$show_scan_restart  = ( ! $bg_scan['running'] && ! empty( $bg_scan['resumable'] ) );
		$show_discard_scan  = ( ! $bg_scan['running'] && ( ! empty( $bg_scan['resumable'] ) || '' !== $bg_scan['error'] ) );
		$show_discard_check = ( ! $bg['running'] && $check_paused );
		$show_discard_all   = $show_discard_scan && $show_discard_check;
		if ( $bg_scan['running'] ) {
			$btn_scan_label = __( 'Scan now', 'tso-link-inspector' );
		} elseif ( ! empty( $bg_scan['resumable'] ) ) {
			$btn_scan_label = __( 'Continue scan', 'tso-link-inspector' );
		} else {
			$btn_scan_label = __( 'Scan now', 'tso-link-inspector' );
		}
		if ( $bg['running'] ) {
			$btn_check_label = __( 'Check now', 'tso-link-inspector' );
		} elseif ( $check_paused ) {
			$btn_check_label = $view_post_id
				? __( 'Continue this post', 'tso-link-inspector' )
				: __( 'Continue check', 'tso-link-inspector' );
		} elseif ( $view_post_id ) {
			$btn_check_label = __( 'Check this post', 'tso-link-inspector' );
		} else {
			$btn_check_label = __( 'Check now', 'tso-link-inspector' );
		}
		$check_prog_pct     = $bg['pct'];
		$check_prog_display = ( $bg['running'] || ( $check_paused && $check_prog_pct > 0 && $check_prog_pct < 100 ) ) ? 'block' : 'none';
		$scan_prog_display  = ( $bg_scan['running'] || '' !== $bg_scan['error'] || ! empty( $bg_scan['resumable'] ) ) ? 'block' : 'none';
		$scan_prog_pct      = $bg_scan['pct'];
		$stop_check_style   = $bg['running'] ? '' : ' style="display:none;"';
		$stop_scan_style    = $bg_scan['running'] ? '' : ' style="display:none;"';
		$start_scan_style   = $bg_scan['running'] ? ' style="display:none;"' : '';
		$start_check_style  = $bg['running'] ? ' style="display:none;"' : '';
		$restart_style      = $show_restart ? '' : ' style="display:none;"';
		$restart_scan_style = $show_scan_restart ? '' : ' style="display:none;"';
		$discard_scan_style = $show_discard_scan ? '' : ' style="display:none;"';
		$discard_check_style = $show_discard_check ? '' : ' style="display:none;"';
		$discard_all_style  = $show_discard_all ? '' : ' style="display:none;"';
		$scan_bar_style     = '';
		if ( '' !== $bg_scan['error'] && ! $bg_scan['running'] ) {
			$scan_bar_style = 'background:#cc1818';
			$scan_prog_pct  = 100;
		}
		$scan_btn_title     = __( 'Reads your content and adds links to this list. It does not test whether URLs work yet.', 'tso-link-inspector' );
		$check_btn_title    = $view_post_id
			? __( 'Tests each saved URL in this post over HTTP. Use Stop to cancel.', 'tso-link-inspector' )
			: __( 'Tests every saved URL on the site over HTTP (can take a while). Use Stop to cancel. After editing one post, open its link list or use Recheck on a row.', 'tso-link-inspector' );

		echo '<div class="tsoliin-toolbar">';
		echo '<div class="tsoliin-toolbar__primary">';
		echo '<button type="button" id="tsoliin-start-scan" class="button button-primary" title="' . esc_attr( $scan_btn_title ) . '"' . $start_scan_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-search"></span> ';
		echo esc_html( $btn_scan_label );
		echo '</button>';
		echo '<button type="button" id="tsoliin-stop-scan" class="button button-secondary"' . $stop_scan_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-no-alt"></span> ';
		echo esc_html__( 'Stop scan', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-restart-scan" class="button button-secondary"' . $restart_scan_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-update"></span> ';
		echo esc_html__( 'Restart scan', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-discard-scan" class="button button-link tsoliin-discard-btn"' . $discard_scan_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__( 'Discard scan', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-start-check" class="button tsoliin-btn-check" title="' . esc_attr( $check_btn_title ) . '"' . $btn_check_disabled . $start_check_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-yes-alt"></span> ';
		echo esc_html( $btn_check_label );
		echo '</button>';
		echo '<button type="button" id="tsoliin-restart-check" class="button button-secondary"' . $restart_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-update"></span> ';
		echo esc_html__( 'Restart from zero', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-discard-check" class="button button-link tsoliin-discard-btn"' . $discard_check_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__( 'Discard check', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-discard-all" class="button button-link tsoliin-discard-btn tsoliin-discard-btn--all"' . $discard_all_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__( 'Discard all paused', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-stop-check" class="button button-secondary"' . $stop_check_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-no-alt"></span> ';
		echo esc_html__( 'Stop', 'tso-link-inspector' );
		echo '</button>';
		echo '</div>';
		echo '<div class="tsoliin-toolbar__secondary">';
		echo '<button type="button" id="tsoliin-diagnose" class="button button-secondary" aria-expanded="false" aria-controls="tsoliin-diagnose-panel" title="' . esc_attr__( 'Health check: database, sample scan, background scan/check status, WP-Cron, and coming-soon gate.', 'tso-link-inspector' ) . '">';
		echo '<span class="dashicons dashicons-info"></span> ';
		echo esc_html__( 'Diagnostics', 'tso-link-inspector' );
		echo '</button>';
		// Export CSV button.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_filter  = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		if ( in_array( $export_filter, $this->get_allowed_quality_filters(), true ) ) {
			$export_filter = 'all';
		} elseif ( ! in_array( $export_filter, $this->get_allowed_status_filters(), true ) ) {
			$export_filter = 'all';
		}
		$export_quality = isset( $_REQUEST['quality_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['quality_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $export_quality, $this->get_allowed_quality_filters(), true ) ) {
			$export_quality = '';
		}
		// Legacy URLs stored quality in filter=.
		if ( '' === $export_quality && in_array( sanitize_key( wp_unslash( (string) ( $_REQUEST['filter'] ?? '' ) ) ), $this->get_allowed_quality_filters(), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$export_quality = sanitize_key( wp_unslash( (string) $_REQUEST['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$export_scope = $this->get_scope_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$export_fields = array(
			'filter' => $export_filter,
		);
		if ( '' !== $export_quality ) {
			$export_fields['quality_filter'] = $export_quality;
		}
		if ( 'all' !== $export_scope ) {
			$export_fields['scope'] = $export_scope;
		}
		if ( $view_post_id ) {
			$export_fields['post_id'] = $view_post_id;
		}
		if ( '' !== $export_search ) {
			$export_fields['s'] = $export_search;
		}
		$this->render_export_form( 'tsoliin_export_csv', 'tsoliin-export-csv', __( 'Export CSV', 'tso-link-inspector' ), 'download', $export_fields, false );
		$this->render_export_form( 'tsoliin_export_pdf', 'tsoliin-export-pdf', __( 'Export report (PDF)', 'tso-link-inspector' ), 'media-document', $export_fields, true );
		echo '</div>';
		echo '<div id="tsoliin-scan-progress" class="tsoliin-progress" style="display:' . esc_attr( $scan_prog_display ) . ';" role="progressbar" aria-valuenow="' . esc_attr( (string) $scan_prog_pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		echo '<div class="tsoliin-progress__bar" style="width:' . esc_attr( (string) $scan_prog_pct ) . '%;' . esc_attr( $scan_bar_style ) . '"></div>';
		echo '<span class="tsoliin-progress__label">';
		if ( $bg_scan['running'] ) {
			/* translators: 1: scanned count, 2: total count */
			echo esc_html( $scan_prog_pct . '% - ' . sprintf( __( 'Scanning %1$d of %2$d...', 'tso-link-inspector' ), $bg_scan['scanned'], $bg_scan['total'] ) );
		} elseif ( '' !== $bg_scan['error'] ) {
			echo esc_html( $bg_scan['error'] );
		} elseif ( ! empty( $bg_scan['resumable'] ) ) {
			/* translators: 1: scanned count, 2: total count */
			echo esc_html( $scan_prog_pct . '% - ' . sprintf( __( 'Scan paused at %1$d of %2$d. Click Continue scan.', 'tso-link-inspector' ), $bg_scan['scanned'], $bg_scan['total'] ) );
		}
		echo '</span></div>';
		echo '<div id="tsoliin-check-progress" class="tsoliin-progress tsoliin-progress--check" style="display:' . esc_attr( $check_prog_display ) . ';" role="progressbar" aria-valuenow="' . esc_attr( (string) $check_prog_pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		echo '<div class="tsoliin-progress__bar" style="width:' . esc_attr( (string) $check_prog_pct ) . '%"></div>';
		echo '<span class="tsoliin-progress__label">';
		if ( $bg['running'] ) {
			echo esc_html( $check_prog_pct . '% - ' . __( 'Checking...', 'tso-link-inspector' ) );
		} elseif ( $pending_check > 0 && $check_prog_pct > 0 && $check_prog_pct < 100 ) {
			echo esc_html( sprintf(
				/* translators: 1: progress percent, 2: pending link count */
				__( '%1$d%% — %2$d links pending. Click Continue check.', 'tso-link-inspector' ),
				$check_prog_pct,
				$pending_check
			) );
		}
		echo '</span></div>';
		echo '</div>';
		echo '</div><!-- .tsoliin-hero -->';

		$this->render_scan_coverage_notices();
		$this->render_onboarding_banner();

		echo '<div id="tsoliin-diagnose-panel" class="tsoliin-diagnose-panel" style="display:none;"></div>';

		$scope_val  = $this->get_scope_from_request();
		$filter_val = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $filter_val, $this->get_allowed_quality_filters(), true ) ) {
			$filter_val = 'all';
		} elseif ( ! in_array( $filter_val, $this->get_allowed_status_filters(), true ) ) {
			$filter_val = 'all';
		}
		$list_ctx = $this->resolve_main_list_context(
			$view_post_id,
			$list_view,
			$filter_val,
			$scope_val,
			$this->get_list_quality_filter_from_request(),
			isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date_found', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		echo '<div id="tsoliin-scope-region">';
		$this->render_scope_region( $table, $list_ctx );
		echo '</div>';

		// Edit modal (always in DOM so AJAX scope navigation can open it).
		echo '<div id="tsoliin-modal" class="tsoliin-modal" style="display:none;" role="dialog" aria-modal="true">';
		echo '<div class="tsoliin-modal__overlay"></div>';
		echo '<div class="tsoliin-modal__content">';
		echo '<h2>' . esc_html__( 'Edit link', 'tso-link-inspector' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Current URL:', 'tso-link-inspector' ) . '</label><code id="tsoliin-modal-old-url"></code></p>';
		echo '<p><label for="tsoliin-new-url">' . esc_html__( 'New URL:', 'tso-link-inspector' ) . '</label>';
		echo '<input type="text" id="tsoliin-new-url" class="widefat" placeholder="https:// or /relative/path" /></p>';
		echo '<div id="tsoliin-modal-preview" class="tsoliin-modal-preview" style="display:none;" aria-live="polite">';
		echo '<p class="description">' . esc_html__( 'HTML preview (only the matched tag attribute will change):', 'tso-link-inspector' ) . '</p>';
		echo '<div class="tsoliin-preview-grid">';
		echo '<div><strong>' . esc_html__( 'Before', 'tso-link-inspector' ) . '</strong><code id="tsoliin-preview-before" class="tsoliin-preview-snippet"></code></div>';
		echo '<div><strong>' . esc_html__( 'After', 'tso-link-inspector' ) . '</strong><code id="tsoliin-preview-after" class="tsoliin-preview-snippet"></code></div>';
		echo '</div></div>';
		echo '<p id="tsoliin-modal-revision-note" class="description tsoliin-modal-revision-note" style="display:none;"></p>';
		echo '<p id="tsoliin-modal-anchor-row"><label for="tsoliin-new-anchor" id="tsoliin-modal-anchor-label">' . esc_html__( 'Link text:', 'tso-link-inspector' ) . '</label>';
		echo '<input type="text" id="tsoliin-new-anchor" class="widefat" />';
		echo '<span id="tsoliin-modal-anchor-note" class="description tsoliin-modal-anchor-note" style="display:none;"></span></p>';
		echo '<p class="tsoliin-apply-anyway-wrap"><label for="tsoliin-apply-anyway">';
		echo '<input type="checkbox" id="tsoliin-apply-anyway" value="1" /> ';
		echo esc_html__( 'Save even if HTTPS cannot be verified from this server (geo-block, bot wall, or timeout).', 'tso-link-inspector' );
		echo '</label></p>';
		echo '<p class="tsoliin-ignore-domain-wrap"><label for="tsoliin-ignore-domain">';
		echo '<input type="checkbox" id="tsoliin-ignore-domain" value="1" /> ';
		echo esc_html__( 'Also add the domain of the URL you are saving to the ignore list (skip future scans and HTTP checks). Not available for relative /path URLs.', 'tso-link-inspector' );
		echo '</label></p>';
		echo '<div class="tsoliin-modal__actions">';
		echo '<button type="button" id="tsoliin-modal-save" class="button button-primary">' . esc_html__( 'Save URL', 'tso-link-inspector' ) . '</button>';
		echo '<button type="button" id="tsoliin-modal-cancel" class="button">' . esc_html__( 'Cancel', 'tso-link-inspector' ) . '</button>';
		echo '<span class="tsoliin-modal__spinner spinner"></span>';
		echo '</div>';
		echo '<div id="tsoliin-modal-feedback" class="tsoliin-modal__feedback"></div>';
		echo '</div></div>';

		echo '</div>';
	}

	/**
	 * Render grouped post summary (broken / redirect counts per article).
	 */
	/**
	 * Summary table of posts or WooCommerce products with link issues.
	 *
	 * @param string $mode posts|products.
	 * @return void
	 */
	private function render_content_summary_view( $mode = 'posts' ) {
		$mode = ( 'products' === $mode ) ? 'products' : 'posts';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = TSOLIIN_Support::get_user_list_per_page();
		$query    = array(
			'per_page' => $per_page,
			'paged'    => $paged,
		);
		if ( 'products' === $mode ) {
			$query['post_type'] = 'product';
		} elseif ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() ) {
			// Keep products out of the Posts summary when the dedicated Products view is active.
			$query['exclude_post_type'] = 'product';
		}
		$result      = $this->db->get_posts_link_summary( $query );
		$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

		if ( 'products' === $mode ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce products sorted by broken links, then redirects. Click a title to review and fix links in that product.', 'tso-link-inspector' ) . '</p>';
			$col_label   = __( 'Product', 'tso-link-inspector' );
			$empty_label = __( 'No products with broken or redirected links.', 'tso-link-inspector' );
			$view_slug   = 'products';
		} else {
			echo '<p class="description">' . esc_html__( 'Articles sorted by broken links, then redirects. Click a title to review and fix links in that post.', 'tso-link-inspector' ) . '</p>';
			$col_label   = __( 'Post', 'tso-link-inspector' );
			$empty_label = __( 'No posts with broken or redirected links.', 'tso-link-inspector' );
			$view_slug   = 'posts';
		}

		echo '<table class="widefat striped tsoliin-posts-summary">';
		echo '<thead><tr>';
		echo '<th>' . esc_html( $col_label ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Broken', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Redirect', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Unchecked', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Total links', 'tso-link-inspector' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['items'] ) ) {
			echo '<tr><td colspan="5"><em>' . esc_html( $empty_label ) . '</em></td></tr>';
		} else {
			foreach ( $result['items'] as $row ) {
				$post_id = absint( $row->post_id );
				$url     = add_query_arg( 'post_id', $post_id, admin_url( 'tools.php?page=tso-link-inspector' ) );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '" class="tsoliin-post-scope-link"><strong>' . esc_html( (string) $row->post_title ) . '</strong></a></td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->broken ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->redirect_count ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->unchecked_count ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->total_links ) ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			$page_links = paginate_links(
				array(
					'base'      => add_query_arg(
						array(
							'page'  => 'tso-link-inspector',
							'view'  => $view_slug,
							'paged' => '%#%',
						),
						admin_url( 'tools.php' )
					),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $paged,
				)
			);
			if ( $page_links ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $page_links ) . '</div></div>';
			}
		}
	}

	/**
	 * Build a list-table URL for a status filter chip/card.
	 *
	 * @param string $filter_key  Allowed status filter or `all`.
	 * @param int    $post_id     Optional post scope.
	 * @return string
	 */
	private function get_stat_filter_url( $filter_key, $post_id = 0 ) {
		$args = array(
			'page' => 'tso-link-inspector',
		);
		if ( $post_id > 0 ) {
			$args['post_id'] = absint( $post_id );
		}
		if ( 'all' !== $filter_key && in_array( $filter_key, $this->get_allowed_status_filters(), true ) ) {
			$args['filter'] = $filter_key;
		}
		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Status chips under the hero stats (scan/check/cron summary).
	 *
	 * @param string               $last_scan          Last full scan timestamp.
	 * @param string               $last_check         Last cron check timestamp.
	 * @param int                  $last_check_count   Links checked in last batch.
	 * @param int                  $pending_count      Pending HTTP checks.
	 * @param array<string,mixed>  $queue              Queue stats from schedule helper.
	 * @param array<string,mixed>  $schedule           Schedule settings.
	 * @param string               $date_fmt           Site date/time format.
	 * @return void
	 */
	private function render_hero_status_chips( $last_scan, $last_check, $last_check_count, $pending_count, array $queue, array $schedule, $date_fmt ) {
		echo '<div class="tsoliin-hero-chips" role="list">';

		echo '<span class="tsoliin-chip" role="listitem">';
		echo '<span class="dashicons dashicons-search" aria-hidden="true"></span> ';
		if ( '' !== $last_scan ) {
			echo esc_html( sprintf(
				/* translators: %s: date */
				__( 'Last scan: %s', 'tso-link-inspector' ),
				wp_date( $date_fmt, strtotime( $last_scan ) )
			) );
		} else {
			echo esc_html__( 'No scan yet', 'tso-link-inspector' );
		}
		echo '</span>';

		if ( '' !== $last_check ) {
			$check_date = wp_date( $date_fmt, strtotime( $last_check ) );
			$check_info = $check_date;
			if ( $last_check_count > 0 ) {
				/* translators: %d: number of links */
				$check_info .= ' (' . sprintf( __( '%d links', 'tso-link-inspector' ), $last_check_count ) . ')';
			} else {
				$check_info .= ' ' . __( '(no pending links)', 'tso-link-inspector' );
			}
			$check_chip_title = sprintf(
				/* translators: %s: date and info */
				__( 'Last automatic check: %s', 'tso-link-inspector' ),
				$check_info
			);
			echo '<span class="tsoliin-chip" role="listitem" title="' . esc_attr( $check_chip_title ) . '">';
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ';
			echo esc_html( sprintf(
				/* translators: %s: date (chip label; full detail in title attribute) */
				__( 'Last check: %s', 'tso-link-inspector' ),
				$check_date
			) );
			echo '</span>';
		}

		$queue_chip = TSOLIIN_Schedule::get_queue_chip( $this->db, $queue, $schedule );
		if ( ! empty( $queue_chip['warn'] ) ) {
			echo '<span id="tsoliin-queue-chip" class="tsoliin-chip tsoliin-chip--warn" role="listitem" title="' . esc_attr( $queue_chip['title'] ) . '">';
			echo '<span class="dashicons dashicons-clock" aria-hidden="true"></span> ';
			echo esc_html( $queue_chip['label'] );
			echo '</span>';
		} else {
			echo '<span id="tsoliin-queue-chip" class="tsoliin-chip tsoliin-chip--ok" role="listitem" title="' . esc_attr( $queue_chip['title'] ) . '">';
			echo '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ';
			echo esc_html( $queue_chip['label'] );
			echo '</span>';
		}

		$next_scan  = wp_next_scheduled( TSOLIIN_Cron::HOOK_SCAN );
		$next_check = wp_next_scheduled( TSOLIIN_Cron::HOOK_CHECK );
		if ( $next_scan || $next_check ) {
			$cron_fmt = get_option( 'date_format' ) . ' H:i';
			if ( $next_scan ) {
				$scan_when = wp_date( $cron_fmt, $next_scan );
				echo '<span class="tsoliin-chip" role="listitem" title="' . esc_attr( sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic scan: %s', 'tso-link-inspector' ),
					$scan_when
				) ) . '">';
				echo '<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ';
				echo esc_html( sprintf(
					/* translators: %s: date and time */
					__( 'Next scan: %s', 'tso-link-inspector' ),
					$scan_when
				) );
				echo '</span>';
			}
			if ( $next_check ) {
				$check_when = wp_date( $cron_fmt, $next_check );
				echo '<span class="tsoliin-chip" role="listitem" title="' . esc_attr( sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic check: %s', 'tso-link-inspector' ),
					$check_when
				) ) . '">';
				echo '<span class="dashicons dashicons-backup" aria-hidden="true"></span> ';
				echo esc_html( sprintf(
					/* translators: %s: date and time */
					__( 'Next check: %s', 'tso-link-inspector' ),
					$check_when
				) );
				echo '</span>';
			}
		}

		echo '</div>';
	}

	/**
	 * Output a stat card.
	 *
	 * @param string $number      Display number.
	 * @param string $label       Label.
	 * @param string $modifier    BEM modifier slug.
	 * @param string $tooltip     Optional title attribute.
	 * @param string $filter_key  Optional status filter for link cards.
	 * @param int    $view_post_id Post scope for filter links.
	 */
	private function stat_card( $number, $label, $modifier, $tooltip = '', $filter_key = '', $view_post_id = 0 ) {
		$cls   = '' !== $modifier ? ' tsoliin-stat--' . esc_attr( $modifier ) : '';
		$title = '' !== $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '';
		$href  = ( '' !== $filter_key && 'posts' !== $filter_key ) ? $this->get_stat_filter_url( $filter_key, $view_post_id ) : '';
		if ( '' !== $href ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cls and $title are fully escaped above.
			echo '<a class="tsoliin-stat' . $cls . '" href="' . esc_url( $href ) . '"' . $title . '>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cls and $title are fully escaped above.
			echo '<div class="tsoliin-stat' . $cls . '"' . $title . '>';
		}
		echo '<span class="tsoliin-stat__number">' . esc_html( $number ) . '</span>';
		echo '<span class="tsoliin-stat__label">' . esc_html( $label ) . '</span>';
		echo ( '' !== $href ) ? '</a>' : '</div>';
	}

	/**
	 * Output the plugin title with the current version badge.
	 *
	 * @param bool $wrap_link When true, wrap the title in a link to the main page.
	 */
	private function render_plugin_title_with_version( $wrap_link = false ) {
		$title = __( 'TSO Link Inspector', 'tso-link-inspector' );
		if ( $wrap_link ) {
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		$this->echo_plugin_version_badge();
	}

	/**
	 * Output the current plugin version next to the page title.
	 */
	private function echo_plugin_version_badge() {
		echo ' <span class="tsoliin-version">' . esc_html( TSOLIIN_VERSION ) . '</span>';
	}

	/**
	 * Move WordPress' native Screen Options/Help links (and panel) next to our
	 * page head, synchronously, before the browser paints.
	 *
	 * Both #screen-meta-links and #screen-meta are already printed by
	 * wp-admin/admin-header.php earlier in the HTML stream by the time this
	 * runs, so a plain, blocking inline <script> right here (as opposed to
	 * moving them from a jQuery `$(document).ready()` handler, which only
	 * fires after the browser has already laid out and painted the page
	 * once) relocates them before first paint — no visible jump/reflow.
	 */
	private function print_screen_meta_reposition_script() {
		echo '<script>(function(){';
		echo 'var l=document.getElementById("screen-meta-links"),';
		echo 's=document.getElementById("tsoliin-screen-meta-slot");';
		echo 'if(l&&s&&l.parentNode!==s){s.appendChild(l);}';
		echo 'var m=document.getElementById("screen-meta"),';
		echo 'h=document.querySelector(".tsoliin-wrap .tsoliin-page-head");';
		echo 'if(m&&h&&!m.dataset.tsoliinRepositioned){';
		echo 'h.parentNode.insertBefore(m,h.nextSibling);';
		echo 'm.dataset.tsoliinRepositioned="1";';
		echo '}';
		echo '})();</script>';
	}

	/**
	 * First-visit onboarding banner (once per user; X hides it for the current page).
	 */
	private function render_onboarding_banner() {
		if ( get_user_meta( get_current_user_id(), 'tsoliin_onboarding_dismissed', true ) ) {
			return;
		}

		$help_url     = admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' );
		$history_url  = admin_url( 'tools.php?page=tso-link-inspector-settings&tab=history' );
		$broken_url   = admin_url( 'tools.php?page=tso-link-inspector&filter=broken' );
		$settings_url = admin_url( 'tools.php?page=tso-link-inspector-settings' );

		echo '<div id="tsoliin-onboarding" class="notice notice-info tsoliin-onboarding">';
		echo '<p><strong>' . esc_html__( 'Getting started', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ol class="tsoliin-onboarding__steps">';
		echo '<li><strong>' . esc_html__( 'Scan now', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'Reads your content and adds links to this list. It does not test whether URLs work yet.', 'tso-link-inspector' );
		echo '</li><li><strong>' . esc_html__( 'Check now', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'sends HTTP requests to every saved URL (runs in the background; you can close this tab).', 'tso-link-inspector' );
		echo '</li><li><strong>' . esc_html__( 'Review Broken', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'open the Broken filter and fix links with Edit link, Suggestion, or Not broken.', 'tso-link-inspector' );
		echo '</li></ol>';
		echo '<p class="tsoliin-onboarding__links">';
		echo '<a href="' . esc_url( $broken_url ) . '" class="button button-secondary">' . esc_html__( 'Open Broken links', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( $help_url ) . '" class="button button-secondary">' . esc_html__( 'Read help', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( $history_url ) . '" class="button button-secondary">' . esc_html__( 'History', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( $settings_url ) . '" class="button button-secondary">' . esc_html__( 'Settings', 'tso-link-inspector' ) . '</a>';
		echo '</p>';
		echo '<button type="button" class="notice-dismiss tsoliin-onboarding-dismiss" aria-label="' . esc_attr__( 'Dismiss', 'tso-link-inspector' ) . '"></button>';
		echo '</div>';

		$this->mark_onboarding_seen();
	}

	/**
	 * Remember that this user has seen the onboarding banner.
	 */
	private function mark_onboarding_seen() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, 'tsoliin_onboarding_dismissed', 1 );
		}
	}

	/**
	 * Notices for coming-soon intercepts and unchecked builder post types.
	 *
	 * @return void
	 */
	private function render_scan_coverage_notices() {
		$settings_url = admin_url( 'tools.php?page=tso-link-inspector-settings#tsoliin-post-types-row' );

		$missing = $this->scanner->get_unchecked_builder_post_types();
		if ( ! empty( $missing ) ) {
			$labels = array();
			foreach ( $missing as $row ) {
				$slug     = isset( $row['slug'] ) ? (string) $row['slug'] : '';
				$label    = isset( $row['label'] ) ? (string) $row['label'] : $slug;
				$labels[] = $label . ' (' . $slug . ')';
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Headers, footers, and popups in these builder types are not scanned because they are unchecked in Settings:', 'tso-link-inspector' );
			echo ' <strong>' . esc_html( implode( ', ', $labels ) ) . '</strong>. ';
			echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Settings → Content types', 'tso-link-inspector' ) . '</a>';
			echo '</p></div>';
		}

		if ( $this->http->is_site_gated_cached() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'A coming-soon or site-gate plugin appears to be active on the front end (this is not WordPress core maintenance mode). Internal HTML links are not marked OK or broken (status: Unverifiable) until the site is publicly reachable. External URLs and media files are still checked. Scan now still finds links in post content and ACF fields; only HTTP status checks for same-site HTML pages are skipped.', 'tso-link-inspector' );
			echo '</p></div>';
		}
	}

	/**
	 * Card-style section navigation (shared markup).
	 *
	 * @param array<int, array{url:string,label:string,icon?:string,active?:bool}> $tabs Tab definitions.
	 * @param string                                                              $aria_label Accessible nav label.
	 */
	private function render_section_nav_tabs( array $tabs, $aria_label ) {
		echo '<nav class="nav-tab-wrapper tsoliin-section-tabs" aria-label="' . esc_attr( $aria_label ) . '">';
		foreach ( $tabs as $tab ) {
			$url       = isset( $tab['url'] ) ? (string) $tab['url'] : '';
			$label     = isset( $tab['label'] ) ? (string) $tab['label'] : '';
			$icon      = isset( $tab['icon'] ) ? (string) $tab['icon'] : '';
			$is_active = ! empty( $tab['active'] );
			$active    = $is_active ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>';
			if ( '' !== $icon ) {
				echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			}
			echo esc_html( $label );
			echo '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Main screen section links (Settings, History, Help, summary views).
	 *
	 * @param bool $posts_view    Posts-with-issues view active.
	 * @param bool $products_view Products-with-issues view active.
	 */
	private function render_main_section_nav( $posts_view, $products_view ) {
		$tabs = array(
			array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector-settings' ),
				'label'  => __( 'Settings', 'tso-link-inspector' ),
				'icon'   => 'dashicons-admin-generic',
				'active' => false,
			),
			array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector-settings&tab=history' ),
				'label'  => __( 'History', 'tso-link-inspector' ),
				'icon'   => 'dashicons-backup',
				'active' => false,
			),
			array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' ),
				'label'  => __( 'Help', 'tso-link-inspector' ),
				'icon'   => 'dashicons-editor-help',
				'active' => false,
			),
			array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector&view=posts' ),
				'label'  => __( 'Posts with issues', 'tso-link-inspector' ),
				'icon'   => 'dashicons-warning',
				'active' => $posts_view,
			),
		);
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() ) {
			$tabs[] = array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector&view=products' ),
				'label'  => __( 'Products with issues', 'tso-link-inspector' ),
				'icon'   => 'dashicons-cart',
				'active' => $products_view,
			);
		}
		if ( $posts_view || $products_view ) {
			$tabs[] = array(
				'url'    => admin_url( 'tools.php?page=tso-link-inspector' ),
				'label'  => __( 'All links', 'tso-link-inspector' ),
				'icon'   => 'dashicons-admin-links',
				'active' => false,
			);
		}
		$this->render_section_nav_tabs( $tabs, __( 'Plugin sections', 'tso-link-inspector' ) );
	}

	/**
	 * Settings / History / Help tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	private function render_settings_nav_tabs( $active_tab ) {
		$base = admin_url( 'tools.php?page=tso-link-inspector-settings' );
		$defs = array(
			'settings' => array(
				'label' => __( 'Settings', 'tso-link-inspector' ),
				'icon'  => 'dashicons-admin-generic',
			),
			'history'  => array(
				'label' => __( 'History', 'tso-link-inspector' ),
				'icon'  => 'dashicons-backup',
			),
			'help'     => array(
				'label' => __( 'Help', 'tso-link-inspector' ),
				'icon'  => 'dashicons-editor-help',
			),
		);
		$tabs = array();
		foreach ( $defs as $slug => $def ) {
			$tabs[] = array(
				'url'    => ( 'settings' === $slug ) ? $base : add_query_arg( 'tab', $slug, $base ),
				'label'  => $def['label'],
				'icon'   => $def['icon'],
				'active' => $active_tab === $slug,
			);
		}
		$this->render_section_nav_tabs( $tabs, __( 'Settings sections', 'tso-link-inspector' ) );
	}

	/**
	 * Labels for URL change_type history keys.
	 *
	 * @return array<string, string>
	 */
	private function get_history_change_type_labels() {
		return array(
			'edit'           => __( 'Edit', 'tso-link-inspector' ),
			'suggest'        => __( 'Suggest', 'tso-link-inspector' ),
			'relative'       => __( 'Relative', 'tso-link-inspector' ),
			'https'          => __( 'HTTPS', 'tso-link-inspector' ),
			'bulk_relative'  => __( 'Bulk relative', 'tso-link-inspector' ),
			'bulk_https'     => __( 'Bulk HTTPS', 'tso-link-inspector' ),
		);
	}

	/**
	 * History tab: recent URL changes from Edit / Suggest / relative / HTTPS.
	 */
	private function render_settings_history_tab() {
		$max_rows = (int) TSOLIIN_DB::HISTORY_MAX_ROWS;
		$count    = (int) $this->db->prune_url_change_history( $max_rows );
		$rows     = $this->db->get_url_change_history( $max_rows );
		$labels   = $this->get_history_change_type_labels();
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="tsoliin-history-panel">';
		echo '<p>' . esc_html__( 'This list stores recent URL changes from Edit link, Suggest apply, Convert to /path, and Upgrade to HTTPS (including bulk actions).', 'tso-link-inspector' ) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: maximum number of history rows kept */
				__( 'Up to %d records are kept. When the limit is reached, the oldest entries are removed automatically.', 'tso-link-inspector' ),
				$max_rows
			)
		) . '</p>';
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: current history row count, 2: maximum rows */
				__( 'Records: %1$d / %2$d', 'tso-link-inspector' ),
				$count,
				$max_rows
			)
		) . '</strong></p>';

		$clear_confirm = __( 'Delete all history records?', 'tso-link-inspector' ) . "\n\n"
			. __( 'This only clears the URL change log. Your posts and the link list are not changed.', 'tso-link-inspector' );
		echo '<form method="post" action="" class="tsoliin-clear-history-form" data-tsoliin-confirm="' . esc_attr( $clear_confirm ) . '">';
		wp_nonce_field( 'tsoliin_clear_history', 'tsoliin_clear_history' );
		echo '<p><button type="submit" name="tsoliin_clear_history_submit" value="1" class="button button-secondary"' . ( $count < 1 ? ' disabled' : '' ) . '>' . esc_html__( 'Delete all history records', 'tso-link-inspector' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled attr is static.
		echo '</form>';

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No URL changes recorded yet.', 'tso-link-inspector' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<div class="tsoliin-history-table-wrap">';
		echo '<table class="widefat striped tsoliin-history-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Date', 'tso-link-inspector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Old URL', 'tso-link-inspector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'New URL', 'tso-link-inspector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type', 'tso-link-inspector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'User', 'tso-link-inspector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Post', 'tso-link-inspector' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$created_raw = isset( $row->created_at ) ? (string) $row->created_at : '';
			$created_ts  = $created_raw ? strtotime( $created_raw . ' UTC' ) : false;
			$date_label  = ( false !== $created_ts ) ? wp_date( $date_fmt, $created_ts ) : $created_raw;
			$old_url     = isset( $row->old_url ) ? (string) $row->old_url : '';
			$new_url     = isset( $row->new_url ) ? (string) $row->new_url : '';
			$type_key    = isset( $row->change_type ) ? sanitize_key( (string) $row->change_type ) : 'edit';
			$type_label  = isset( $labels[ $type_key ] ) ? $labels[ $type_key ] : $type_key;
			$user_id     = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
			$post_id     = isset( $row->post_id ) ? absint( $row->post_id ) : 0;

			$user_label = '—';
			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$user_label = $user->display_name;
				}
			}

			$post_label = '—';
			if ( $post_id > 0 ) {
				$title = get_the_title( $post_id );
				if ( '' === $title ) {
					/* translators: %d: post ID */
					$title = sprintf( __( 'Post #%d', 'tso-link-inspector' ), $post_id );
				}
				$edit_link = get_edit_post_link( $post_id );
				if ( $edit_link ) {
					$post_label = '<a href="' . esc_url( $edit_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
				} else {
					$post_label = esc_html( $title );
				}
			}

			echo '<tr>';
			echo '<td>' . esc_html( $date_label ) . '</td>';
			echo '<td><code>' . esc_html( $old_url ) . '</code></td>';
			echo '<td><code>' . esc_html( $new_url ) . '</code></td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . $post_label . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Help tab content (FAQ).
	 */
	private function render_settings_help_tab() {
		$settings_url = admin_url( 'tools.php?page=tso-link-inspector-settings' );
		$list_url     = admin_url( 'tools.php?page=tso-link-inspector' );
		echo '<div class="tsoliin-help-panel">';

		echo '<h2>' . esc_html__( 'How it works', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Scan vs Check', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Scan finds links in posts, comments, menus, widgets, custom fields, and other enabled sources, then saves them in the database. Check sends HTTP requests to test whether each URL works. Run Scan after editing content; run Check when you want fresh status codes.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Automatic checks (cron)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'A full Scan runs once per day. HTTP checks run hourly in configurable batches via WP-Cron. When a stored URL no longer appears in WordPress, the cron re-reads that source once before checking (or removes the row if the link is gone). Priority order: never-checked links first, then broken links past the broken recheck interval, then OK links past the OK recheck interval. The main dashboard shows the check queue, throughput, and next scheduled run. Adjust batch size and intervals in Settings.', 'tso-link-inspector' );
		echo ' ' . esc_html__( 'WP-Cron only runs when your site receives visits. On low-traffic or cached sites, schedule a server cron job to call wp-cron.php every hour for reliable automatic checks.', 'tso-link-inspector' );
		echo '</dd>';

		echo '<dt>' . esc_html__( 'Posts with issues', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Open Posts with issues from the main screen to see articles that contain broken, redirected, or unchecked links. Click a post to filter the list to that post only.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( add_query_arg( 'view', 'posts', $list_url ) ) . '">' . esc_html__( 'Open posts view', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_plugin_active() ) {
			echo '<dt>' . esc_html__( 'WooCommerce products', 'tso-link-inspector' ) . '</dt>';
			echo '<dd>';
			echo esc_html__( 'Enable WooCommerce scanning in Settings to check external product URLs, downloadable files, and gallery images. Then use Products with issues on the main screen.', 'tso-link-inspector' );
			echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open settings', 'tso-link-inspector' ) . '</a>';
			echo '</dd>';
		}

		echo '</dl>';

		echo '<h2>' . esc_html__( 'List filters', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Status filter tabs', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Broken: HTTP errors and timeouts. Redirect: non-transparent redirects. OK: working links. Unchecked: found but not tested yet. HTTP insecure: active http:// links. Manual locks: links you marked Not broken.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Quality filters', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Optional second row: Empty anchor (missing text), Generic anchor (non-descriptive phrases such as “click here”), Unpublished target (internal links to draft, private, pending, or trashed posts). Combine with status filters and Internal/External scope.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Internal and external links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Scope tabs filter by destination: internal (same site or relative paths) vs external (other domains). Useful on large sites with many outbound links.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';

		echo '<h2>' . esc_html__( 'Row and bulk actions', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Editing and fixing links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Go to edit opens the right WordPress screen for this source (post, product, menu, widget, comment, or term), and scrolls to the link when it is in the post content. Edit link changes the URL in the stored source when the plugin can write it. Unlink removes the link or image from the source when possible. Recheck re-reads the source and runs one HTTP test. Not broken locks the link as OK. Suggestion offers HTTPS upgrades when available, or explains when you must edit in WordPress instead. Delete only removes the row from this list.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Bulk actions', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Select rows and apply Recheck selected, Upgrade selected to HTTPS, Unlink selected, Mark as OK, Convert selected to /path (when enabled in Settings), or Delete from list. Delete only removes the database row, not the link in your content.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'View post at link', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'The external-link icon opens the front end and highlights the matching link in post content, or scrolls to a comment. Works for links stored in post content and approved comments.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Not broken (Manual locks)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Marks a link as OK and moves it to the Manual locks tab. Background checks still run. The link returns to Broken or Redirect only if the URL or redirect outcome changes, or if a check finds it broken again.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';

		echo '<h2>' . esc_html__( 'Settings and maintenance', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Custom fields (ACF / Meta)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Enable ACF / Meta custom fields in Settings to find URLs in fields added by plugins like ACF, PODS, or CPT UI. When ACF is active, Image / File / Gallery (attachment IDs), Page Link, Post Object, Relationship, and Taxonomy fields are resolved to real URLs (including repeater, group, flexible, and clone), and ACF Options pages are scanned too. Elementor dynamic tags and [acf] shortcodes inside builder JSON (including __dynamic__ URL fields) are resolved to the real destination. Plain-text URLs inside meta also require Additional link sources → Plain-text URLs inside custom fields. Common SEO/admin keys are excluded by default; private _ keys are skipped unless they look like they contain URLs. Add extra keys to exclude to speed up scans.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( $settings_url ) . '#tsoliin-meta-exclude-row">' . esc_html__( 'Open meta settings', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';

		echo '<dt>' . esc_html__( 'Builder templates (Elementor / Porto)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Links in headers, footers, and popups are stored in builder post types (for example Elementor Library or Porto Builder). Enable those types under Settings → Content types, then run Scan now. They are never turned on automatically.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Coming soon / maintenance', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'If a coming-soon plugin serves the same HTML for every internal page, HTTP checks cannot tell working pages from missing ones. Internal HTML URLs are marked Unverifiable (coming soon / maintenance) until the site is live. External links and uploaded files are still tested. Scan now still extracts links from posts and ACF fields behind the gate; removing the coming-soon page is only required if you need OK/Broken status on internal HTML URLs.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Broken links email notifications', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Choose immediate alerts, confirmed alerts (two consecutive failed checks), or periodic summaries every 7, 15, or 30 days. Only hard-broken links (no redirect destination) are included. Summary emails are skipped when there are none.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Relative URLs and Convert to /path', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Edit link accepts site-relative paths such as /page/, ./file.html, or ../other/. They are stored as written and checked against your site. The edit modal shows an HTML preview of the matched tag before you save. Enable post revisions in Settings to keep a restore point. Optional: enable “Convert to /path” to bulk-replace absolute same-site URLs with /path automatically.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Ignore list', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Domains or URL prefixes on the ignore list are skipped during Scan and Check (useful for sites that block bots). You can add entries in Settings or use Ignore domain on any row.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( $settings_url ) . '#tsoliin-ignore-list">' . esc_html__( 'Open ignore list', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';

		echo '<dt>' . esc_html__( 'nofollow on broken links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'When enabled in Settings, adds rel="nofollow" to broken links in post content on the front end so search engines are less likely to follow them. Does not affect comments or custom fields.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Export CSV and PDF', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Export buttons on the main screen respect the active status filter, quality filter, scope, search, and post view. PDF reports are limited to a subset of rows; use CSV for the full filtered list.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Maintenance in Settings empties the link database, History, and scan/check progress but does not edit posts, comments, or other content. Plugin Settings are kept. Run Scan now, then Check now, to rebuild the list.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';
		echo '</div>';
	}

	/**
	 * Label for post type checkboxes (core types use plugin translations, not WP admin locale).
	 *
	 * @param object $post_type_object Post type object.
	 * @return string
	 */
	private function post_type_checkbox_label( $post_type_object ) {
		$slug = isset( $post_type_object->name ) ? (string) $post_type_object->name : '';
		$known = array(
			'post'              => __( 'Post', 'tso-link-inspector' ),
			'page'              => __( 'Page', 'tso-link-inspector' ),
			'attachment'        => __( 'Media', 'tso-link-inspector' ),
			'elementor_library' => __( 'Elementor Library', 'tso-link-inspector' ),
			'porto_builder'     => __( 'Porto Builder', 'tso-link-inspector' ),
		);
		$label = isset( $known[ $slug ] ) ? $known[ $slug ] : (string) $post_type_object->labels->singular_name;
		return $label . ' (' . $slug . ')';
	}

	// =========================================================================
	// SETTINGS PAGE
	// =========================================================================

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		if ( isset( $_POST['tsoliin_settings_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on next line.
			$nonce = sanitize_text_field( wp_unslash( $_POST['tsoliin_settings_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( wp_verify_nonce( $nonce, 'tsoliin_save_settings' ) ) {
				$this->save_settings();
				// Reload plugin translations immediately so language change applies on this request.
				if ( function_exists( 'tsoliin_link_inspector' ) ) {
					$plugin = tsoliin_link_inspector();
					if ( is_object( $plugin ) && method_exists( $plugin, 'load_textdomain' ) ) {
						$plugin->load_textdomain();
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'tso-link-inspector' ) . '</p></div>';
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice after POST redirect, not a destructive action.
		if ( isset( $_GET['tsoliin_notice'] ) && 'reset' === sanitize_key( wp_unslash( $_GET['tsoliin_notice'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Records deleted.', 'tso-link-inspector' ) . '</p></div>';
		}

		if ( isset( $_POST['tsoliin_clear_history_submit'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
			$clear_nonce = isset( $_POST['tsoliin_clear_history'] ) ? sanitize_text_field( wp_unslash( $_POST['tsoliin_clear_history'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( wp_verify_nonce( $clear_nonce, 'tsoliin_clear_history' ) ) {
				$this->db->clear_url_change_history();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'History records deleted.', 'tso-link-inspector' ) . '</p></div>';
			}
		}


		$s           = get_option( 'tsoliin_settings', array() );
		$timeout     = isset( $s['timeout'] ) ? absint( $s['timeout'] ) : 15;
		$scan_meta   = ! empty( $s['scan_meta'] );
		$scan_images = ! empty( $s['scan_images'] );
		$scan_iframes= ! empty( $s['scan_iframes'] );
		$scan_comments=! empty( $s['scan_comments'] );
		$scan_plain_urls   = array_key_exists( 'scan_plain_urls', $s ) ? ! empty( $s['scan_plain_urls'] ) : true;
		$scan_block_attrs  = array_key_exists( 'scan_block_attrs', $s ) ? ! empty( $s['scan_block_attrs'] ) : true;
		$scan_menus        = array_key_exists( 'scan_menus', $s ) ? ! empty( $s['scan_menus'] ) : true;
		$scan_srcset       = array_key_exists( 'scan_srcset', $s ) ? ! empty( $s['scan_srcset'] ) : true;
		$scan_data_attrs   = array_key_exists( 'scan_data_attrs', $s ) ? ! empty( $s['scan_data_attrs'] ) : true;
		$schedule            = TSOLIIN_Schedule::get_settings();
		$recheck_days        = (int) $schedule['recheck_days'];
		$broken_recheck_days = (int) $schedule['broken_recheck_days'];
		$cron_check_batch    = (int) $schedule['cron_check_batch'];
		$allowed_email_modes = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
		$broken_email_mode   = isset( $s['broken_email_mode'] ) ? sanitize_key( (string) $s['broken_email_mode'] ) : 'none';
		if ( ! in_array( $broken_email_mode, $allowed_email_modes, true ) ) {
			$broken_email_mode = 'none';
		}
		$broken_email_to         = isset( $s['broken_email_to'] ) ? sanitize_email( (string) $s['broken_email_to'] ) : '';
		$default_notify_email_to = sanitize_email( (string) get_option( 'admin_email' ) );
		// Do NOT use sanitize_key() — it lowercases 'es_ES' to 'es_es', breaking the dropdown.
		$allowed_display_langs = array( '', 'ca', 'es_ES', 'en' );
		$language = ( isset( $s['language'] ) && in_array( $s['language'], $allowed_display_langs, true ) ) ? (string) $s['language'] : '';
		$meta_keys   = isset( $s['meta_exclude_keys'] ) && is_array( $s['meta_exclude_keys'] )
			? implode( "\n", array_map( 'sanitize_text_field', $s['meta_exclude_keys'] ) )
			: '';
		$post_types  = isset( $s['post_types'] ) && is_array( $s['post_types'] )
			? array_map( 'sanitize_key', $s['post_types'] )
			: array( 'post', 'page' );
		$all_pts     = $this->scanner->get_settings_post_type_objects();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $settings_tab, array( 'settings', 'history', 'help' ), true ) ) {
			$settings_tab = 'settings';
		}

		echo '<div class="wrap tsoliin-wrap" data-theme="' . esc_attr( TSOLIIN_Support::theme_is_daytime_now() ? 'day' : 'night' ) . '" data-theme-pref="auto">';
		echo '<div class="tsoliin-page-head">';
		echo '<h1>';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="tsoliin-back-link">';
		echo '<span class="dashicons dashicons-arrow-left-alt"></span> ';
		echo esc_html__( 'TSO Link Inspector', 'tso-link-inspector' );
		echo '</a>';
		$this->echo_plugin_version_badge();
		echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
		echo esc_html__( 'Settings', 'tso-link-inspector' );
		echo '</h1>';
		echo '<div class="tsoliin-page-head__actions">';
		TSOLIIN_Support::render_theme_toggle();
		TSOLIIN_Support::render_donate_button();
		echo '</div>';
		echo '</div>';
		$this->print_screen_meta_reposition_script();

		$this->render_settings_nav_tabs( $settings_tab );

		$this->render_scan_coverage_notices();


		if ( 'history' === $settings_tab ) {
			$this->render_settings_history_tab();
			echo '</div>';
			return;
		}

		if ( 'help' === $settings_tab ) {
			$this->render_settings_help_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="">';
		wp_nonce_field( 'tsoliin_save_settings', 'tsoliin_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Post types.
		echo '<tr id="tsoliin-post-types-row"><th scope="row">' . esc_html__( 'Content types', 'tso-link-inspector' ) . '</th><td>';
		foreach ( $all_pts as $pt ) {
			$checked = checked( in_array( $pt->name, $post_types, true ), true, false );
			echo '<label style="display:block;margin-bottom:5px;">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked comes from checked() which is safe.
			echo '<input type="checkbox" name="tsoliin_post_types[]" value="' . esc_attr( $pt->name ) . '" ' . $checked . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked from checked() is safe
			echo esc_html( $this->post_type_checkbox_label( $pt ) );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Headers, footers, and popups often live in builder types such as Elementor Library or Porto Builder. Enable those types if you want links in templates scanned. They are not turned on automatically.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Scan images.
		echo '<tr><th scope="row">' . esc_html__( 'HTML Images', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_images" value="1" ' . checked( $scan_images, true, false ) . ' /> ';
		echo esc_html__( 'Scan img src tags (broken images)', 'tso-link-inspector' ) . '</label></td></tr>';

		// Scan iframes.
		echo '<tr><th scope="row">' . esc_html__( 'Embedded videos (iframes)', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_iframes" value="1" ' . checked( $scan_iframes, true, false ) . ' /> ';
		echo esc_html__( 'Scan iframes (YouTube, Vimeo, Google Maps)', 'tso-link-inspector' ) . '</label></td></tr>';

		// Scan comments.
		echo '<tr><th scope="row">' . esc_html__( 'Comments', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_comments" value="1" ' . checked( $scan_comments, true, false ) . ' /> ';
		echo esc_html__( 'Scan approved comments', 'tso-link-inspector' ) . '</label></td></tr>';

		// Extended content scanning (phase 1).
		echo '<tr><th scope="row">' . esc_html__( 'Extended scanning', 'tso-link-inspector' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_plain_urls" value="1" ' . checked( $scan_plain_urls, true, false ) . ' /> ';
		echo esc_html__( 'Plain-text URLs in post content (without <a> tags). Checked for broken links; edit the post manually to change or remove them.', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_block_attrs" value="1" ' . checked( $scan_block_attrs, true, false ) . ' /> ';
		echo esc_html__( 'Gutenberg block attributes (parse_blocks JSON)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_menus" value="1" ' . checked( $scan_menus, true, false ) . ' /> ';
		echo esc_html__( 'Navigation menus (custom menu links)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_srcset" value="1" ' . checked( $scan_srcset, true, false ) . ' /> ';
		echo esc_html__( 'Responsive media (srcset, picture, video, audio, embed)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_data_attrs" value="1" ' . checked( $scan_data_attrs, true, false ) . ' /> ';
		echo esc_html__( 'Page builder data-* link attributes', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'These sources are enabled by default. Uncheck any you want to skip during scans.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Additional link sources.
		$scan_widgets    = array_key_exists( 'scan_widgets', $s ) ? ! empty( $s['scan_widgets'] ) : true;
		$scan_terms      = array_key_exists( 'scan_terms', $s ) ? ! empty( $s['scan_terms'] ) : true;
		$scan_fse        = array_key_exists( 'scan_fse', $s ) ? ! empty( $s['scan_fse'] ) : true;
		$scan_meta_plain = array_key_exists( 'scan_meta_plain', $s ) ? ! empty( $s['scan_meta_plain'] ) : true;
		echo '<tr><th scope="row">' . esc_html__( 'Additional link sources', 'tso-link-inspector' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_widgets" value="1" ' . checked( $scan_widgets, true, false ) . ' /> ';
		echo esc_html__( 'Widget areas (Text, Custom HTML, block widgets, Media Image, and other widgets that store URL strings)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_terms" value="1" ' . checked( $scan_terms, true, false ) . ' /> ';
		echo esc_html__( 'Taxonomy term descriptions (categories, tags, and custom taxonomies)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_fse" value="1" ' . checked( $scan_fse, true, false ) . ' /> ';
		echo esc_html__( 'Site Editor: templates, template parts, reusable blocks, and Navigation (block themes)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_meta_plain" value="1" ' . checked( $scan_meta_plain, true, false ) . ' /> ';
		echo esc_html__( 'Plain-text URLs inside custom fields (requires ACF/Meta scan above)', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Enabled by default. Uncheck any source you want to skip. Theme templates that exist only as theme files (never customized in the Site Editor) are not in the database and cannot be scanned. Classic Appearance → Menus items are controlled under Extended scanning above, not here.', 'tso-link-inspector' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Developers can register more sources with tsoliin_register_link_source(); those run whenever a scan runs, independent of these checkboxes.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// WooCommerce (opt-in).
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_plugin_active() ) {
			$scan_woocommerce = ! empty( $s['scan_woocommerce'] );
			echo '<tr><th scope="row">' . esc_html__( 'WooCommerce', 'tso-link-inspector' ) . '</th><td>';
			echo '<label><input type="checkbox" name="tsoliin_scan_woocommerce" value="1" ' . checked( $scan_woocommerce, true, false ) . ' /> ';
			echo esc_html__( 'Scan WooCommerce products (external URL, downloadable files, featured image, gallery)', 'tso-link-inspector' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Off by default. When enabled, products are included in Scan now and a Products with issues view appears on the main screen. Edit those fields in the product editor (Go to edit).', 'tso-link-inspector' ) . '</p>';
			echo '</td></tr>';
		}

		// Scan meta.
		echo '<tr><th scope="row">' . esc_html__( 'ACF / Meta custom fields', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" id="tsoliin_scan_meta" name="tsoliin_scan_meta" value="1" ' . checked( $scan_meta, true, false ) . ' /> ';
		echo esc_html__( 'Scan custom fields (ACF, PODS, CPT UI...)', 'tso-link-inspector' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Find links in fields added by plugins like ACF. When ACF is active, this also resolves image/file/gallery IDs and page/post relationship fields to URLs (including repeater, group, flexible, and clone fields), and scans ACF Options pages. Elementor dynamic tags and ACF tags inside builder JSON are resolved to real URLs. It may slow down scanning.', 'tso-link-inspector' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Common SEO/admin keys (e.g. selected Yoast and Rank Math keys) are excluded by default. Private meta keys starting with _ are skipped unless they look like they contain URLs. Add extra keys below, one per line.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Meta exclude keys.
		echo '<tr id="tsoliin-meta-exclude-row"><th scope="row">' . esc_html__( 'Meta keys to exclude', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_meta_exclude_keys" rows="4" class="tsoliin-meta-keys code">' . esc_textarea( $meta_keys ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One key per line.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Timeout.
		echo '<tr><th scope="row"><label for="tsoliin_timeout">' . esc_html__( 'Timeout (seconds)', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="number" id="tsoliin_timeout" name="tsoliin_timeout" value="' . esc_attr( (string) $timeout ) . '" min="5" max="60" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Recommended: 15 s.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Automatic check schedule.
		echo '<tr><th scope="row">' . esc_html__( 'Automatic HTTP checks', 'tso-link-inspector' ) . '</th><td>';
		echo '<p><label for="tsoliin_recheck_days">' . esc_html__( 'Recheck OK links every (days)', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_recheck_days" name="tsoliin_recheck_days" value="' . esc_attr( (string) $recheck_days ) . '" min="1" max="365" class="small-text" />';
		echo '<span class="description"> ' . esc_html__( 'Working links are rechecked after this many days since their last HTTP test. Default: 7.', 'tso-link-inspector' ) . '</span></p>';
		echo '<p><label for="tsoliin_broken_recheck_days">' . esc_html__( 'Recheck broken links every (days)', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_broken_recheck_days" name="tsoliin_broken_recheck_days" value="' . esc_attr( (string) $broken_recheck_days ) . '" min="1" max="90" class="small-text" />';
		echo '<span class="description"> ' . esc_html__( 'Broken links (not manually locked) are rechecked sooner. Default: 7.', 'tso-link-inspector' ) . '</span></p>';
		echo '<p><label for="tsoliin_cron_check_batch">' . esc_html__( 'Links per hourly cron run', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_cron_check_batch" name="tsoliin_cron_check_batch" value="' . esc_attr( (string) $cron_check_batch ) . '" min="5" max="100" class="small-text" />';
		echo '<span class="description"> ';
		printf(
			/* translators: %d: estimated checks per day */
			esc_html__( 'How many links WP-Cron tests each hour (5–100). At the current value, about %d checks per day.', 'tso-link-inspector' ),
			absint( $cron_check_batch ) * 24
		);
		echo '</span></p></td></tr>';

		// Broken links email notifications.
		echo '<tr><th scope="row"><label for="tsoliin_broken_email_mode">' . esc_html__( 'Broken links email notifications', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<select id="tsoliin_broken_email_mode" name="tsoliin_broken_email_mode">';
		$email_modes = array(
			'none'      => __( 'Disabled', 'tso-link-inspector' ),
			'immediate' => __( 'Send immediately when a broken link is detected', 'tso-link-inspector' ),
			'confirmed' => __( 'Send after two consecutive failed checks (fewer false alarms)', 'tso-link-inspector' ),
			'digest_7'  => __( 'Summary email every 7 days', 'tso-link-inspector' ),
			'digest_15' => __( 'Summary email every 15 days', 'tso-link-inspector' ),
			'digest_30' => __( 'Summary email every 30 days', 'tso-link-inspector' ),
		);
		foreach ( $email_modes as $email_mode_key => $email_mode_label ) {
			echo '<option value="' . esc_attr( $email_mode_key ) . '" ' . selected( $broken_email_mode, $email_mode_key, false ) . '>' . esc_html( $email_mode_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">';
		echo esc_html__( 'Only links that are fully broken are included (no redirect destination). Summary emails are skipped when there are no broken links.', 'tso-link-inspector' );
		echo '</p></td></tr>';

		// Broken links recipient email.
		echo '<tr><th scope="row"><label for="tsoliin_broken_email_to">' . esc_html__( 'Recipient email', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="email" id="tsoliin_broken_email_to" name="tsoliin_broken_email_to" value="' . esc_attr( $broken_email_to ) . '" placeholder="' . esc_attr( $default_notify_email_to ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Address used for broken link notifications. Leave empty to use the WordPress admin email.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Preserve post dates.
		$preserve_dates  = ! empty( $s['preserve_dates'] );
		$nofollow_broken = ! empty( $s['nofollow_broken'] );
		$relative_url_tool = ! empty( $s['relative_url_tool'] );
		$create_revision   = ! empty( $s['create_revision'] );

		echo '<tr><th scope="row">' . esc_html__( 'Post modified date', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_preserve_dates" value="1" ' . checked( $preserve_dates, true, false ) . ' /> ';
		echo esc_html__( 'Do not update modified date when editing a link', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'If enabled, editing or unlinking a link in post content will not change the post modified date. Meta-only and menu URL changes are unaffected either way.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Broken links and search engines', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_nofollow_broken" value="1" ' . checked( $nofollow_broken, true, false ) . ' /> ';
		echo esc_html__( 'Automatically add rel="nofollow" to broken links', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Prevents search engines from following broken links on your site. It only affects post content (not comments or custom fields).', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Convert to /path', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_relative_url_tool" value="1" ' . checked( $relative_url_tool, true, false ) . ' /> ';
		echo esc_html__( 'Show “Convert to /path” in the link list', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, adds a row and bulk action to replace same-site URLs like https://yoursite.com/contact/ with /contact/ in post content or scanned meta (and custom menu URLs). Comments, widgets, and terms are skipped. Disabled by default.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Post revisions', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_create_revision" value="1" ' . checked( $create_revision, true, false ) . ' /> ';
		echo esc_html__( 'Create a revision before editing a link in post content', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, saves a WordPress revision before Edit link, Convert to /path, Upgrade to HTTPS, or Unlink changes post content (once per post per request). Requires revisions enabled for that post type. Disabled by default.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Language.
		$langs = array( '' => __( 'Automatic', 'tso-link-inspector' ), 'ca' => __( 'Catalan', 'tso-link-inspector' ), 'es_ES' => __( 'Spanish', 'tso-link-inspector' ), 'en' => __( 'English', 'tso-link-inspector' ) );
		echo '<tr><th scope="row"><label for="tsoliin_language">' . esc_html__( 'Plugin language', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<select id="tsoliin_language" name="tsoliin_language">';
		foreach ( $langs as $code => $lname ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $language, $code, false ) . '>' . esc_html( $lname ) . '</option>';
		}
		echo '</select></td></tr>';

		// Ignore list.
		$ignore_list = isset( $s['ignore_list'] ) && is_array( $s['ignore_list'] ) ? implode( "\n", $s['ignore_list'] ) : '';
		echo '<tr id="tsoliin-ignore-list"><th scope="row">' . esc_html__( 'Ignore list', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_ignore_list" rows="5" class="tsoliin-meta-keys code">' . esc_textarea( $ignore_list ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One domain or URL per line. Example: amazon.com or https://example.com/page. These links will be skipped during scan and check.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'tso-link-inspector' ) );
		echo '</form>';

		// ── Maintenance.
		echo '<h2>' . esc_html__( 'Maintenance', 'tso-link-inspector' ) . '</h2>';
		echo '<div class="tsoliin-maintenance-box notice notice-warning inline">';
		echo '<p><strong>' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'This action only clears data stored by Link Inspector. Your website content is not edited.', 'tso-link-inspector' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'What is deleted:', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ul class="tsoliin-maintenance-list">';
		echo '<li>' . esc_html__( 'Every row in the link list (URLs found during scans, anchor text, link type).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'HTTP results: status codes, broken/OK flags, redirect destinations, and last-checked dates.', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Manual locks (links you marked Not broken).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'URL change History log (Edit / Suggest / Convert to /path / HTTPS).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Scan and check progress (last scan/check dates, batch cursors, any running or paused background job).', 'tso-link-inspector' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'What is kept:', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ul class="tsoliin-maintenance-list">';
		echo '<li>' . esc_html__( 'Posts, pages, comments, menus, widgets, and all other site content (no links are removed or changed).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Plugin Settings on this page (ignore list, email alerts, scan options, language).', 'tso-link-inspector' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'After deleting:', 'tso-link-inspector' ) . '</strong> ';
		echo esc_html__( 'open the main dashboard, click Scan now to rebuild the list from your content, then Check now to test URLs again.', 'tso-link-inspector' );
		echo '</p>';
		echo '</div>';
		$reset_confirm = __( 'Delete all plugin records?', 'tso-link-inspector' ) . "\n\n"
			. __( 'This empties the link database, History, and scan/check progress. Posts and Settings are not changed. You will need to run Scan now and Check now again.', 'tso-link-inspector' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tsoliin-reset-form" data-tsoliin-confirm="' . esc_attr( $reset_confirm ) . '">';
		wp_nonce_field( 'tsoliin_reset_all' );
		echo '<input type="hidden" name="action" value="tsoliin_reset_all" />';
		echo '<p><button type="submit" class="button button-secondary" id="tsoliin-reset-all">' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Save settings from POST.
	 */
	private function save_settings() {
		$current_settings = get_option( 'tsoliin_settings', array() );
		$current_mode     = isset( $current_settings['broken_email_mode'] ) ? sanitize_key( (string) $current_settings['broken_email_mode'] ) : 'none';
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in render_settings() before calling this method.
		$post_types = array();
		if ( isset( $_POST['tsoliin_post_types'] ) && is_array( $_POST['tsoliin_post_types'] ) ) {
			foreach ( (array) wp_unslash( $_POST['tsoliin_post_types'] ) as $pt ) {
				$clean = sanitize_key( (string) $pt );
				if ( post_type_exists( $clean ) ) {
					$post_types[] = $clean;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}
		$timeout      = max( 5, min( 60, absint( isset( $_POST['tsoliin_timeout'] ) ? $_POST['tsoliin_timeout'] : 15 ) ) );
		$recheck_days        = max( 1, min( 365, absint( isset( $_POST['tsoliin_recheck_days'] ) ? $_POST['tsoliin_recheck_days'] : 7 ) ) );
		$broken_recheck_days = max( 1, min( 90, absint( isset( $_POST['tsoliin_broken_recheck_days'] ) ? $_POST['tsoliin_broken_recheck_days'] : 7 ) ) );
		$cron_check_batch    = max( 5, min( 100, absint( isset( $_POST['tsoliin_cron_check_batch'] ) ? $_POST['tsoliin_cron_check_batch'] : 20 ) ) );
		$scan_meta    = ! empty( $_POST['tsoliin_scan_meta'] );
		$scan_images  = ! empty( $_POST['tsoliin_scan_images'] );
		$scan_iframes = ! empty( $_POST['tsoliin_scan_iframes'] );
		$scan_comments= ! empty( $_POST['tsoliin_scan_comments'] );
		$scan_plain_urls   = ! empty( $_POST['tsoliin_scan_plain_urls'] );
		$scan_block_attrs  = ! empty( $_POST['tsoliin_scan_block_attrs'] );
		$scan_menus        = ! empty( $_POST['tsoliin_scan_menus'] );
		$scan_srcset       = ! empty( $_POST['tsoliin_scan_srcset'] );
		$scan_data_attrs   = ! empty( $_POST['tsoliin_scan_data_attrs'] );
		$scan_widgets      = ! empty( $_POST['tsoliin_scan_widgets'] );
		$scan_terms        = ! empty( $_POST['tsoliin_scan_terms'] );
		$scan_fse          = ! empty( $_POST['tsoliin_scan_fse'] );
		$scan_meta_plain   = ! empty( $_POST['tsoliin_scan_meta_plain'] );
		$scan_woocommerce  = ! empty( $_POST['tsoliin_scan_woocommerce'] );
		if ( ! class_exists( 'TSOLIIN_WooCommerce', false ) || ! TSOLIIN_WooCommerce::is_plugin_active() ) {
			$scan_woocommerce = ! empty( $current_settings['scan_woocommerce'] );
		}
		$allowed_email_modes = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
		$broken_email_mode   = 'none';
		if ( isset( $_POST['tsoliin_broken_email_mode'] ) ) {
			$email_mode_raw = sanitize_key( wp_unslash( $_POST['tsoliin_broken_email_mode'] ) );
			if ( in_array( $email_mode_raw, $allowed_email_modes, true ) ) {
				$broken_email_mode = $email_mode_raw;
			}
		}
		$broken_email_to = '';
		if ( isset( $_POST['tsoliin_broken_email_to'] ) ) {
			$broken_email_to = sanitize_email( wp_unslash( $_POST['tsoliin_broken_email_to'] ) );
		}

		$meta_keys = array();
		if ( isset( $_POST['tsoliin_meta_exclude_keys'] ) ) {
			$raw = sanitize_textarea_field( wp_unslash( $_POST['tsoliin_meta_exclude_keys'] ) );
			foreach ( explode( "\n", $raw ) as $k ) {
				$k = sanitize_text_field( trim( $k ) );
				if ( '' !== $k ) {
					$meta_keys[] = $k;
				}
			}
		}

		$preserve_dates    = ! empty( $_POST['tsoliin_preserve_dates'] );
		$nofollow_broken   = ! empty( $_POST['tsoliin_nofollow_broken'] );
		$relative_url_tool = ! empty( $_POST['tsoliin_relative_url_tool'] );
		$create_revision   = ! empty( $_POST['tsoliin_create_revision'] );

		// Use strict whitelist for language — do NOT use sanitize_key() which would
		// lowercase 'es_ES' to 'es_es' and break .mo file lookup.
		$allowed_languages = array( '', 'ca', 'es_ES', 'en' );
		$language = '';
		if ( isset( $_POST['tsoliin_language'] ) ) {
			$lang_raw = sanitize_text_field( wp_unslash( $_POST['tsoliin_language'] ) );
			if ( in_array( $lang_raw, $allowed_languages, true ) ) {
				$language = $lang_raw;
			}
		}

		// Parse ignore list from POST.
		$ignore_list_save = array();
		if ( isset( $_POST['tsoliin_ignore_list'] ) ) {
			$raw_ig = sanitize_textarea_field( wp_unslash( $_POST['tsoliin_ignore_list'] ) );
			foreach ( explode( "\n", $raw_ig ) as $entry ) {
				$entry = sanitize_text_field( trim( $entry ) );
				if ( '' !== $entry ) {
					$ignore_list_save[] = strtolower( $entry );
				}
			}
		}

		update_option( 'tsoliin_settings', array(
			'post_types'        => $post_types,
			'timeout'           => $timeout,
			'recheck_days'        => $recheck_days,
			'broken_recheck_days' => $broken_recheck_days,
			'cron_check_batch'    => $cron_check_batch,
			'scan_meta'         => $scan_meta,
			'scan_images'       => $scan_images,
			'scan_iframes'      => $scan_iframes,
			'scan_comments'     => $scan_comments,
			'scan_plain_urls'   => $scan_plain_urls,
			'scan_block_attrs'  => $scan_block_attrs,
			'scan_menus'        => $scan_menus,
			'scan_srcset'       => $scan_srcset,
			'scan_data_attrs'   => $scan_data_attrs,
			'scan_widgets'      => $scan_widgets,
			'scan_terms'        => $scan_terms,
			'scan_fse'          => $scan_fse,
			'scan_meta_plain'   => $scan_meta_plain,
			'scan_woocommerce'  => $scan_woocommerce,
			'broken_email_mode' => $broken_email_mode,
			'broken_email_to'   => $broken_email_to,
			'meta_exclude_keys' => $meta_keys,
			'language'          => $language,
			'preserve_dates'    => $preserve_dates,
			'nofollow_broken'   => $nofollow_broken,
			'relative_url_tool' => $relative_url_tool,
			'create_revision'   => $create_revision,
			'ignore_list'       => $ignore_list_save,
		), true );

		if ( $current_mode !== $broken_email_mode ) {
			delete_option( 'tsoliin_broken_digest_last_sent' );
		}
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * Build status column HTML (matches list table output for live AJAX updates).
	 *
	 * @param int    $code          HTTP status code.
	 * @param int    $is_broken     1 if broken.
	 * @param string $link_url      Checked URL.
	 * @param string $redirect_url  Redirect destination if any.
	 * @param bool   $user_verified Manual lock flag.
	 * @return string
	 */
	private static function format_status_column_html( $code, $is_broken, $link_url, $redirect_url = '', $user_verified = false ) {
		$code          = (int) $code;
		$is_broken     = (int) $is_broken;
		$link_url      = (string) $link_url;
		$redirect_url  = (string) $redirect_url;
		$user_verified = (bool) $user_verified;
		$class         = TSOLIIN_HTTP::status_class( $code, $is_broken, $link_url );
		$label         = TSOLIIN_HTTP::status_label( $code, $link_url );
		$html          = '';
		if ( $user_verified ) {
			$html .= '<span class="tsoliin-verified-badge" title="' . esc_attr__( 'Manual lock: marked OK by you. Background checks still run. Cleared if the URL or redirect changes, or if a check finds it broken.', 'tso-link-inspector' ) . '">&#128274; </span>';
		}
		$html .= '<span class="tsoliin-status ' . esc_attr( $class ) . '">';
		if ( $code > 0 ) {
			$html .= esc_html( (string) $code ) . ' ';
		}
		$html .= esc_html( $label ) . '</span>';
		if ( '' !== $redirect_url && rtrim( $link_url, '/' ) !== rtrim( $redirect_url, '/' ) ) {
			$rdisp = strlen( $redirect_url ) > 40 ? substr( $redirect_url, 0, 37 ) . '...' : $redirect_url;
			$html .= '<br><small><a href="' . esc_url( $redirect_url ) . '" title="' . esc_attr( $redirect_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $rdisp ) . '</a></small>';
		}
		return $html;
	}

	private function clear_scan_progress_options() {
		$this->cron->reset_bg_scan_cursors();
		// Fully abandon jobs (stop_* leaves resume state for a manual pause).
		$this->cron->discard_bg_scan();
		$this->cron->discard_bg_check();
		delete_option( 'tsoliin_bg_scan_total' );
		delete_option( 'tsoliin_bg_scan_running' );
		delete_option( 'tsoliin_total_posts_scanned' );
		delete_option( 'tsoliin_last_full_scan' );
		delete_option( 'tsoliin_last_check_batch' );
		delete_option( 'tsoliin_last_check_count' );
		delete_option( 'tsoliin_immediate_broken_queue' );
		delete_option( 'tsoliin_bg_check_empty_retries' );
		delete_option( 'tsoliin_bg_check_user_stopped' );
		delete_transient( 'tsoliin_bg_scan_step_lock' );
		delete_transient( 'tsoliin_bg_check_step_lock' );
		delete_transient( 'tsoliin_bg_lifecycle_start_lock' );
		delete_transient( 'tsoliin_immediate_queue_lock' );
	}

	/**
	 * Whether a link row cannot be edited or unlinked from this plugin.
	 *
	 * @param object $link DB link row.
	 * @return bool
	 */
	private function is_non_editable_source( $link ) {
		return '' !== $this->get_non_editable_source_message( $link );
	}

	/**
	 * User-facing message when Edit/Unlink is blocked for external sources.
	 *
	 * @param object $link DB link row.
	 * @return string Empty when the row is editable here.
	 */
	private function get_non_editable_source_message( $link ) {
		if ( TSOLIIN_Support::can_inline_edit_link( $link ) ) {
			return '';
		}
		$type = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
		$sk   = ( $link && isset( $link->source_key ) ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_Elementor', false ) && TSOLIIN_Elementor::is_elementor_source_key( $sk ) ) {
			return __( 'This URL comes from an Elementor or ACF dynamic tag. Use Go to edit to open the post or template and change the field there.', 'tso-link-inspector' );
		}
		if ( ! empty( $link->post_id ) && in_array( $type, array( 'link', 'image', 'iframe', 'template', 'wp_block' ), true ) ) {
			return __( 'This URL is not stored in editable post content (it may already have been changed in the editor). Use Recheck or Scan to refresh the list, Delete to remove only this inspector record, or open the post and edit the link there.', 'tso-link-inspector' );
		}
		switch ( $type ) {
			case 'plain':
				return __( 'This URL is plain text in the content, not an HTML link. Edit the post manually, or use Delete to remove this record from the list.', 'tso-link-inspector' );
			case 'menu':
				return __( 'This menu link could not be located. On block themes use Edit link for custom URLs, or Site Editor → Navigation for block menus.', 'tso-link-inspector' );
			case 'widget':
				return __( 'This widget link could not be located. Edit it under Appearance > Widgets or the Site Editor.', 'tso-link-inspector' );
			case 'acf':
				return __( 'This ACF Options URL cannot be edited inline. Use Go to edit to open the Options page.', 'tso-link-inspector' );
			case 'term':
				return __( 'This term description link could not be located. Edit it in the taxonomy editor.', 'tso-link-inspector' );
			default:
				if ( $link && empty( $link->post_id ) && 'comment' !== $type ) {
					return __( 'This link must be edited at its original source.', 'tso-link-inspector' );
				}
				return '';
		}
	}

	private function check_nonce_and_cap() {
		check_ajax_referer( 'tsoliin_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tso-link-inspector' ) ), 403 );
		}
	}

	/**
	 * Success payload when the inspector row is already gone (editor save / scan removed it).
	 *
	 * @param string $message Optional user message.
	 * @return void
	 */
	private function send_stale_row_success( $message = '' ) {
		if ( '' === $message ) {
			$message = __( 'This list row is already gone (the link was updated or removed from the content).', 'tso-link-inspector' );
		}
		wp_send_json_success(
			array(
				'gone'           => true,
				'removed'        => true,
				'stale'          => true,
				'matches_filter' => false,
				'message'        => $message,
			)
		);
	}

	/**
	 * Require object-level caps to change WordPress content for this link row.
	 *
	 * @param object|null $link DB link row.
	 * @return void
	 */
	private function require_link_mutation_cap( $link ) {
		if ( ! TSOLIIN_Support::current_user_can_mutate_link( $link ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tso-link-inspector' ) ), 403 );
		}
	}

	/**
	 * Normalize main-page list/summary context from request parameters.
	 *
	 * @param int    $view_post_id   Optional post scope.
	 * @param string $list_view      links|posts|products.
	 * @param string $filter_val     Status filter.
	 * @param string $scope_val      Internal/external scope.
	 * @param string $quality_filter Quality filter.
	 * @param int    $paged          Current page.
	 * @param string $orderby        List orderby column.
	 * @param string $order          ASC|DESC.
	 * @param string $search         Search term.
	 * @return array<string,mixed>
	 */
	private function resolve_main_list_context( $view_post_id, $list_view, $filter_val, $scope_val, $quality_filter, $paged, $orderby, $order, $search ) {
		$view_post_id = absint( $view_post_id );
		$list_view    = sanitize_key( (string) $list_view );
		if ( ! in_array( $list_view, array( 'links', 'posts', 'products' ), true ) ) {
			$list_view = 'links';
		}
		if ( $view_post_id > 0 ) {
			$list_view = 'links';
		}
		$posts_view    = ( 'posts' === $list_view && ! $view_post_id );
		$products_view = ( 'products' === $list_view && ! $view_post_id && class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() );
		if ( 'products' === $list_view && ! $products_view ) {
			$list_view = 'links';
		}
		$summary_view = $posts_view || $products_view;

		return array(
			'view_post_id'   => $view_post_id,
			'view_post'      => $view_post_id ? get_post( $view_post_id ) : null,
			'list_view'      => $list_view,
			'posts_view'     => $posts_view,
			'products_view'  => $products_view,
			'summary_view'   => $summary_view,
			'filter_val'     => $filter_val,
			'scope_val'      => $scope_val,
			'quality_filter' => $quality_filter,
			'paged'          => max( 1, absint( $paged ) ),
			'orderby'        => $orderby,
			'order'          => $order,
			'search'         => $search,
		);
	}

	/**
	 * Section nav, post action bar, summary tables, or the links list form.
	 *
	 * @param TSOLIIN_List_Table|null $table    Prepared list table (links view only).
	 * @param array<string,mixed>     $list_ctx Context from resolve_main_list_context().
	 * @return void
	 */
	private function render_scope_region( $table, array $list_ctx ) {
		$view_post_id  = (int) $list_ctx['view_post_id'];
		$view_post     = $list_ctx['view_post'];
		$posts_view    = ! empty( $list_ctx['posts_view'] );
		$products_view = ! empty( $list_ctx['products_view'] );

		if ( ! $view_post ) {
			$this->render_main_section_nav( $posts_view, $products_view );
		}

		if ( $view_post ) {
			echo '<div class="tsoliin-action-bar">';
			echo '<div class="tsoliin-action-bar__left">';
			echo '<a href="' . esc_url( (string) get_edit_post_link( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'Edit post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( (string) get_permalink( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'View post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="button button-secondary tsoliin-post-scope-link tsoliin-post-scope-link--back">&#8592; ' . esc_html__( 'Back', 'tso-link-inspector' ) . '</a>';
			echo '</div>';
			echo '</div>';
		}

		if ( $posts_view ) {
			$_REQUEST['paged'] = max( 1, (int) $list_ctx['paged'] );
			$this->render_content_summary_view( 'posts' );
			return;
		}
		if ( $products_view ) {
			$_REQUEST['paged'] = max( 1, (int) $list_ctx['paged'] );
			$this->render_content_summary_view( 'products' );
			return;
		}

		$this->render_list_form_region( $table, $list_ctx );
	}

	/**
	 * Links list GET form + table only (filters, sort, pagination AJAX).
	 *
	 * @param TSOLIIN_List_Table|null $table    Prepared list table.
	 * @param array<string,mixed>     $list_ctx Context from resolve_main_list_context().
	 * @return void
	 */
	private function render_list_form_region( $table, array $list_ctx ) {
		$view_post_id = (int) $list_ctx['view_post_id'];
		echo '<form id="tsoliin-list-form" method="get">';
		echo '<div id="tsoliin-list-table-region" class="tsoliin-list-table-region">';
		if ( $table instanceof TSOLIIN_List_Table ) {
			$this->render_list_table_region( $table, $view_post_id, (string) $list_ctx['filter_val'], (string) $list_ctx['scope_val'] );
		}
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Page title markup (after the dashicon) for AJAX scope navigation.
	 *
	 * @param array<string,mixed> $list_ctx Context from resolve_main_list_context().
	 * @return string
	 */
	private function get_main_page_title_inner_html( array $list_ctx ) {
		$view_post     = $list_ctx['view_post'];
		$posts_view    = ! empty( $list_ctx['posts_view'] );
		$products_view = ! empty( $list_ctx['products_view'] );

		ob_start();
		if ( $view_post ) {
			$this->render_plugin_title_with_version( false );
		} elseif ( $products_view ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
			echo esc_html__( 'Products with issues', 'tso-link-inspector' );
		} elseif ( $posts_view ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
			echo esc_html__( 'Posts with issues', 'tso-link-inspector' );
		} else {
			$this->render_plugin_title_with_version( false );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Toolbar check-button label for the current post scope.
	 *
	 * @param int $view_post_id Optional post filter.
	 * @return string
	 */
	private function get_check_button_label_for_scope( $view_post_id ) {
		$view_post_id  = absint( $view_post_id );
		$bg            = $this->get_cached_bg_progress();
		$pending_check = (int) $this->db->get_pending_check_count( $view_post_id );
		$check_paused  = $this->cron->is_bg_check_paused() && $pending_check > 0;

		if ( $bg['running'] ) {
			return __( 'Check now', 'tso-link-inspector' );
		}
		if ( $check_paused ) {
			return $view_post_id
				? __( 'Continue this post', 'tso-link-inspector' )
				: __( 'Continue check', 'tso-link-inspector' );
		}
		if ( $view_post_id ) {
			return __( 'Check this post', 'tso-link-inspector' );
		}
		return __( 'Check now', 'tso-link-inspector' );
	}

	/**
	 * Hidden filters + list table markup (main page and live search AJAX).
	 *
	 * @param TSOLIIN_List_Table $table        Prepared list table.
	 * @param int                $view_post_id Optional post filter.
	 * @param string             $filter_val   Active status filter.
	 * @param string             $scope_val    Internal/external scope.
	 * @return void
	 */
	private function render_list_table_region( TSOLIIN_List_Table $table, $view_post_id, $filter_val, $scope_val ) {
		echo '<input type="hidden" name="page" value="tso-link-inspector" />';
		if ( $view_post_id ) {
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $view_post_id ) . '" />';
		}
		if ( 'all' !== $filter_val ) {
			echo '<input type="hidden" name="filter" value="' . esc_attr( $filter_val ) . '" />';
		}
		$quality_val = $this->get_list_quality_filter_from_request();
		if ( '' !== $quality_val ) {
			echo '<input type="hidden" name="quality_filter" value="' . esc_attr( $quality_val ) . '" />';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only hidden-field mirror of the active list filter.
		$type_val = isset( $_REQUEST['link_type_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['link_type_filter'] ) ) : '';
		if ( ! in_array( $type_val, TSOLIIN_DB::allowed_link_types(), true ) ) {
			$type_val = '';
		}
		if ( '' !== $type_val ) {
			echo '<input type="hidden" name="link_type_filter" value="' . esc_attr( $type_val ) . '" />';
		}
		if ( 'all' !== $scope_val ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( $scope_val ) . '" />';
		}
		$table->display();
	}

	/**
	 * Live search and AJAX list navigation: return refreshed list table HTML.
	 *
	 * @return void
	 */
	public function ajax_search_list() {
		$this->check_nonce_and_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
		if ( in_array( $filter, $this->get_allowed_quality_filters(), true ) ) {
			$filter = 'all';
		} elseif ( ! in_array( $filter, $this->get_allowed_status_filters(), true ) ) {
			$filter = 'all';
		}
		$quality = isset( $_POST['quality_filter'] ) ? sanitize_key( wp_unslash( $_POST['quality_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $quality, $this->get_allowed_quality_filters(), true ) ) {
			$quality = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link_type = isset( $_POST['link_type_filter'] ) ? sanitize_key( wp_unslash( $_POST['link_type_filter'] ) ) : '';
		if ( ! in_array( $link_type, TSOLIIN_DB::allowed_link_types(), true ) ) {
			$link_type = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope = isset( $_POST['scope'] ) ? $this->db->sanitize_scope_input( wp_unslash( $_POST['scope'] ) ) : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$list_view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'links';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$paged   = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$orderby = isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'date_found';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order   = isset( $_POST['order'] ) ? sanitize_key( wp_unslash( $_POST['order'] ) ) : 'DESC';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$region  = isset( $_POST['region'] ) ? sanitize_key( wp_unslash( $_POST['region'] ) ) : 'list';
		if ( ! in_array( $region, array( 'list', 'scope' ), true ) ) {
			$region = 'list';
		}

		$_REQUEST['s']                = $search;
		$_REQUEST['filter']           = $filter;
		$_REQUEST['quality_filter']   = $quality;
		$_REQUEST['link_type_filter'] = $link_type;
		$_REQUEST['scope']            = $scope;
		$_REQUEST['paged']          = $paged;
		$_REQUEST['orderby']        = $orderby;
		$_REQUEST['order']          = $order;
		unset( $_REQUEST['post_id'], $_REQUEST['view'] );
		if ( $post_id ) {
			$_REQUEST['post_id'] = $post_id;
		} elseif ( in_array( $list_view, array( 'posts', 'products' ), true ) ) {
			$_REQUEST['view'] = $list_view;
		}

		$list_ctx = $this->resolve_main_list_context(
			$post_id,
			$list_view,
			$filter,
			$scope,
			$quality,
			$paged,
			$orderby,
			$order,
			$search
		);

		$table = null;
		if ( empty( $list_ctx['summary_view'] ) ) {
			$table = new TSOLIIN_List_Table( $this->db, $this->http );
			$table->prepare_items();
		} elseif ( 'list' === $region ) {
			$region = 'scope';
		}

		ob_start();
		if ( 'scope' === $region ) {
			$this->render_scope_region( $table, $list_ctx );
		} else {
			$this->render_list_form_region( $table, $list_ctx );
		}
		wp_send_json_success(
			array(
				'html'             => ob_get_clean(),
				'region'           => $region,
				'total'            => ( $table instanceof TSOLIIN_List_Table ) ? (int) $table->get_pagination_arg( 'total_items' ) : 0,
				'view_post_id'     => (int) $list_ctx['view_post_id'],
				'list_view'        => (string) $list_ctx['list_view'],
				'summary_view'     => ! empty( $list_ctx['summary_view'] ),
				'page_title_html'  => $this->get_main_page_title_inner_html( $list_ctx ),
				'check_btn_label'  => $this->get_check_button_label_for_scope( (int) $list_ctx['view_post_id'] ),
			)
		);
	}

	/**
	 * Allowed list-table filter keys.
	 *
	 * @return string[]
	 */
	private function get_allowed_status_filters() {
		return array(
			'all',
			'broken',
			'redirect',
			'ok',
			'unchecked',
			'http_insecure',
			'manual_locked',
		);
	}

	/**
	 * Allowed optional quality filter keys.
	 *
	 * @return string[]
	 */
	private function get_allowed_quality_filters() {
		return array(
			'empty_anchor',
			'generic_anchor',
			'unpublished_target',
		);
	}

	/**
	 * Active internal/external scope from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_scope_from_request() {
		if ( isset( $_POST['list_scope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->db->sanitize_scope_input( wp_unslash( $_POST['list_scope'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		return $this->get_scope_from_request();
	}

	/**
	 * Active internal/external scope from admin GET/REQUEST (list filters).
	 *
	 * @return string
	 */
	private function get_scope_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['scope'] ) ) {
			return 'all';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $this->db->sanitize_scope_input( wp_unslash( $_REQUEST['scope'] ) );
	}

	/**
	 * Sanitize a list-table filter key.
	 *
	 * @param string $filter Raw filter.
	 * @return string
	 */
	private function sanitize_list_filter( $filter ) {
		$filter = sanitize_key( (string) $filter );
		if ( in_array( $filter, $this->get_allowed_quality_filters(), true ) ) {
			return 'all';
		}
		return in_array( $filter, $this->get_allowed_status_filters(), true ) ? $filter : 'all';
	}

	/**
	 * Sanitize optional quality filter key.
	 *
	 * @param string $quality Raw quality filter.
	 * @return string Empty when none/invalid.
	 */
	private function sanitize_list_quality_filter( $quality ) {
		$quality = sanitize_key( (string) $quality );
		return in_array( $quality, $this->get_allowed_quality_filters(), true ) ? $quality : '';
	}

	/**
	 * Active optional quality filter from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_quality_filter_from_request() {
		if ( isset( $_POST['list_quality_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->sanitize_list_quality_filter( wp_unslash( $_POST['list_quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['quality_filter'] ) ) {
			return $this->sanitize_list_quality_filter( wp_unslash( $_POST['quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['quality_filter'] ) ) {
			return $this->sanitize_list_quality_filter( wp_unslash( $_GET['quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// Legacy URLs: quality stored in filter=.
		$legacy = '';
		if ( isset( $_POST['filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$legacy = sanitize_key( wp_unslash( $_POST['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_GET['filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$legacy = sanitize_key( wp_unslash( $_GET['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( in_array( $legacy, $this->get_allowed_quality_filters(), true ) ) {
			return $legacy;
		}
		return '';
	}

	/**
	 * Active list filter from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_filter_from_request() {
		if ( isset( $_POST['list_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->sanitize_list_filter( wp_unslash( $_POST['list_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['filter'] ) ) {
			return $this->sanitize_list_filter( wp_unslash( $_GET['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return 'all';
	}

	/**
	 * Add matches_filter for the current list tab to an AJAX payload.
	 *
	 * @param array       $payload Response data.
	 * @param object|null $link    Link row after the mutation.
	 * @return array
	 */
	private function append_filter_match( array $payload, $link ) {
		$filter  = $this->get_list_filter_from_request();
		$quality = $this->get_list_quality_filter_from_request();
		$scope   = $this->get_list_scope_from_request();
		$match   = true;
		if ( $link ) {
			if ( 'all' !== $filter ) {
				$match = $this->db->link_matches_filter( $link, $filter );
			}
			if ( $match && '' !== $quality ) {
				$match = $this->db->link_matches_quality_filter( $link, $quality );
			}
			if ( $match && 'all' !== $scope ) {
				$match = $this->db->link_matches_scope( $link, $scope );
			}
		}
		$payload['matches_filter'] = $match;
		return $this->append_http_fix_promotion( $payload, $link, $filter );
	}

	/**
	 * When HTTPS is saved from the HTTP insecure tab, note that a remaining redirect moves to Redirect.
	 *
	 * @param array       $payload Response data.
	 * @param object|null $link    Link row after the mutation.
	 * @param string      $filter  Active list filter.
	 * @return array
	 */
	private function append_http_fix_promotion( array $payload, $link, $filter ) {
		if ( ! $link || 'http_insecure' !== sanitize_key( (string) $filter ) ) {
			return $payload;
		}

		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;

		if ( TSOLIIN_DB::row_is_http_insecure( $link_url, $is_broken, $user_verified ) ) {
			return $payload;
		}

		if ( ! $this->db->row_counts_as_redirect_tab( $link ) ) {
			return $payload;
		}

		$payload['promoted_to_filter']       = 'redirect';
		$payload['filter_promotion_message'] = __( 'HTTPS saved. This link now appears under Redirect.', 'tso-link-inspector' );

		return $payload;
	}

	/**
	 * Build AJAX status fields from a stored link row (after DB normalization).
	 *
	 * @param object      $link             Link row from TSOLIIN_DB.
	 * @param string|null $url_for_label    Optional URL for status label/class.
	 * @param bool        $use_current_time When true, last_checked uses now (after HTTP check).
	 * @return array<string, mixed>
	 */
	private function status_payload_from_link( $link, $url_for_label = null, $use_current_time = false ) {
		if ( ! $link ) {
			return array();
		}
		$url      = null !== $url_for_label ? (string) $url_for_label : (string) $link->link_url;
		$code     = (int) $link->status_code;
		$broken   = (int) $link->is_broken;
		$redir    = (string) $link->redirect_url;
		$verified = ! empty( $link->user_verified );
		$checked  = $use_current_time
			? wp_date( 'd/m/Y H:i' )
			: ( $link->last_checked
				? wp_date( 'd/m/Y H:i', strtotime( (string) $link->last_checked ) )
				: '' );
		return array(
			'status_code'  => $code,
			'is_broken'    => $broken,
			'redirect_url' => $redir,
			'label'        => TSOLIIN_HTTP::status_label( $code, $url ),
			'css_class'    => TSOLIIN_HTTP::status_class( $code, $broken, $url ),
			'status_html'  => TSOLIIN_Support::render_link_status_html( $link, $this->http ),
			'last_checked' => $checked,
		);
	}

	public function ajax_scan_batch() {
		$this->check_nonce_and_cap();
		$page     = isset( $_POST['page_num'] ) ? absint( $_POST['page_num'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result   = $this->scanner->scan_batch( $page, TSOLIIN_BATCH_SIZE );
		$total    = $this->scanner->get_total_posts();
		$progress = $total > 0 ? min( 100, (int) round( ( min( $page * TSOLIIN_BATCH_SIZE, $total ) / $total ) * 100 ) ) : 100;

		if ( $result['done'] ) {
			update_option( 'tsoliin_last_full_scan', current_time( 'mysql', true ), false );
			update_option( 'tsoliin_total_posts_scanned', $total, false );
			$progress = 100;
		}
		wp_send_json_success( array(
			'done'      => $result['done'],
			'scanned'   => $result['scanned'],
			'found'     => $result['found'],
			'progress'  => $progress,
			'next_page' => $page + 1,
			/* translators: 1: scanned, 2: total */
			'message'   => $result['done'] ? __( 'Scan completed!', 'tso-link-inspector' ) : sprintf( __( 'Scanning %1$d of %2$d...', 'tso-link-inspector' ), min( $page * TSOLIIN_BATCH_SIZE, $total ), $total ),
		) );
	}

	public function ajax_recheck() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$link             = $this->resync_link_before_recheck( $link );
		if ( ! $link ) {
			$this->db->delete_link( $link_id );
			wp_send_json_success(
				array(
					'removed'        => true,
					'matches_filter' => false,
					'message'        => __( 'Removed from list — link no longer found in content.', 'tso-link-inspector' ),
				)
			);
		}
		$link_id = (int) $link->id;
		// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
		$r = $this->http->check( $link->link_url, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );
		$payload = $this->append_filter_match(
			$this->status_payload_from_link( $updated, (string) $updated->link_url, true ),
			$updated
		);
		if ( $updated ) {
			$payload['new_url'] = (string) $updated->link_url;
			$payload['link_id'] = (int) $updated->id;
		}
		wp_send_json_success( $payload );
	}

	/**
	 * Re-read WordPress source storage before an HTTP recheck.
	 *
	 * @param object $link DB row.
	 * @return object|null Updated row, or null if the link was removed from the source.
	 */
	private function resync_link_before_recheck( $link ) {
		return $this->scanner->resync_link_from_source( $link );
	}

	public function ajax_update_link() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_url_raw    = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_anchor_raw = isset( $_POST['new_anchor'] ) ? wp_unslash( $_POST['new_anchor'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link           = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$this->require_link_mutation_cap( $link );
		$apply_anyway_raw  = isset( $_POST['apply_anyway'] ) ? sanitize_text_field( wp_unslash( $_POST['apply_anyway'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ignore_domain_raw = isset( $_POST['ignore_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['ignore_domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$apply_anyway      = in_array( $apply_anyway_raw, array( '1', 'true', 'yes' ), true );
		$ignore_domain     = in_array( $ignore_domain_raw, array( '1', 'true', 'yes' ), true );
		if ( $ignore_domain ) {
			$apply_anyway = true;
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'widget' === $link_type ) {
			wp_send_json_error(
				array(
					'message' => __( 'Edit widget links in Appearance > Widgets using Go to edit.', 'tso-link-inspector' ),
				)
			);
		}
		if ( 'menu' === $link_type ) {
			if ( ! TSOLIIN_Support::is_custom_menu_url_row( $link ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'This menu item URL comes from the linked page/post. Edit that content, or open the classic menu editor via Go to edit.', 'tso-link-inspector' ),
					)
				);
			}
			// Custom menu URLs (_menu_item_url) are updated below via replace_link_url_in_source().
		} else {
			$sk_early = isset( $link->source_key ) ? (string) $link->source_key : '';
			if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk_early ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Edit WooCommerce product URLs in the product editor using Go to edit.', 'tso-link-inspector' ),
					)
				);
			}
			if ( 'term' === $link_type ) {
				wp_send_json_error(
					array(
						'message' => __( 'Edit term description links in the taxonomy editor using Go to edit.', 'tso-link-inspector' ),
					)
				);
			}
		}

		$new_url = TSOLIIN_HTTP::sanitize_editable_link_url( $new_url_raw, (int) $link->post_id );
		if ( ! $link_id || false === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'tso-link-inspector' ) ) );
		}

		$new_anchor     = null !== $new_anchor_raw ? sanitize_text_field( $new_anchor_raw ) : null;
		$url_changed    = $new_url !== (string) $link->link_url;
		if ( $url_changed && $this->scanner->urls_equivalent_for_stored_link( (string) $link->link_url, $new_url, (int) $link->post_id ) ) {
			$url_changed = false;
		}
		$anchor_changed = null !== $new_anchor && $new_anchor !== (string) $link->anchor_text;
		if ( ! $url_changed && $anchor_changed && ! TSOLIIN_Support::can_edit_link_anchor_in_modal( $link ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Link text cannot be changed here for this comment. Edit the comment in WordPress to change visible text.', 'tso-link-inspector' ),
				)
			);
		}
		if ( $anchor_changed && ! TSOLIIN_Support::can_edit_link_anchor_in_modal( $link ) ) {
			$anchor_changed = false;
			$new_anchor     = null;
		}
		if ( ! $url_changed && ! $anchor_changed ) {
			wp_send_json_error( array( 'message' => __( 'No changes to save.', 'tso-link-inspector' ) ) );
		}

		if ( $url_changed && ! $apply_anyway && $this->rejects_unverified_https_upgrade( $link, $new_url ) ) {
			wp_send_json_error(
				array(
					'unverified' => true,
					'message'    => __( 'HTTPS could not be verified from the server. Tick “Save even if this server cannot confirm the URL” to apply it anyway, or mark the link as OK manually.', 'tso-link-inspector' ),
				)
			);
		}

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			// Stale inspector row: content already has the target URL (manual edit or prior apply).
			$already_has_new = $url_changed
				&& ! empty( $link->post_id )
				&& in_array( $link_type, array( 'link', 'image', 'iframe', 'template', 'wp_block' ), true )
				&& $this->scanner->is_url_editable_in_post( (int) $link->post_id, $new_url, $link_type );
			if ( ! $already_has_new ) {
				wp_send_json_error( array( 'message' => $blocked ) );
			}
		}

		$url_done    = true;
		$anchor_done = true;
		$warning     = '';

		if ( $url_changed ) {
			$url_done = $this->replace_link_url_in_source( $link, $new_url );
		}

		if ( $anchor_changed ) {
			if ( 'image' === $link_type ) {
				$match_src   = ( $url_changed && $url_done ) ? $new_url : null;
				$anchor_done = $this->scanner->replace_alt_in_post(
					(int) $link->post_id,
					(string) $link->link_url,
					$new_anchor,
					$match_src
				);
			} elseif ( in_array( $link_type, array( 'link', 'comment', 'widget', 'menu', 'term' ), true ) ) {
				$href_for_anchor = ( $url_changed && $url_done ) ? $new_url : (string) $link->link_url;
				if ( 'comment' === $link_type ) {
					$anchor_done = $this->update_anchor_in_comment( $link, $href_for_anchor, $new_anchor );
				} elseif ( 'widget' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_widget( (string) $link->source_key, $href_for_anchor, $new_anchor );
				} elseif ( 'menu' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_menu_item( (string) $link->source_key, $new_anchor );
				} elseif ( 'term' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_term( (string) $link->source_key, $href_for_anchor, $new_anchor );
				} else {
					$anchor_done = $this->scanner->replace_anchor_in_post( (int) $link->post_id, $href_for_anchor, $new_anchor );
				}
			}
		}

		if ( $url_changed && ! $url_done ) {
			$already_in_post = ! empty( $link->post_id )
				&& in_array( $link_type, array( 'link', 'image', 'iframe', 'template', 'wp_block', 'plain' ), true )
				&& $this->scanner->is_url_editable_in_post( (int) $link->post_id, $new_url, $link_type );
			if ( $already_in_post ) {
				$url_done = true;
				$warning  = __( 'The post already contains this URL. The list row was updated.', 'tso-link-inspector' );
			} elseif ( $anchor_changed && $anchor_done ) {
				$warning = __( 'Link text updated, but the URL could not be changed in the post. Edit the post manually or leave the URL field unchanged next time.', 'tso-link-inspector' );
			} elseif ( 'comment' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in this comment. Edit the comment manually or check encoding (e.g. trailing slash).', 'tso-link-inspector' ) ) );
			} elseif ( 'image' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original image URL not found in post. Check whether the image was edited manually.', 'tso-link-inspector' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in post. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
			}
		}

		if ( $anchor_changed && ! $anchor_done ) {
			if ( $url_changed && $url_done ) {
				$warning = __( 'URL updated, but link text could not be changed in the post. Edit the post manually or leave the link text field unchanged next time.', 'tso-link-inspector' );
			} elseif ( 'image' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original image URL not found in post. Check whether the image was edited manually.', 'tso-link-inspector' ) ) );
			} elseif ( 'comment' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in this comment. Edit the comment manually or check encoding (e.g. trailing slash).', 'tso-link-inspector' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in post. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
			}
		}

		$response = array();
		if ( $url_changed && $url_done ) {
			$change_type = isset( $_POST['change_type'] ) ? sanitize_key( wp_unslash( $_POST['change_type'] ) ) : 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_ajax_referer above.
			if ( ! in_array( $change_type, array( 'edit', 'suggest' ), true ) ) {
				$change_type = 'edit';
			}
			$this->db->update_link_url( $link_id, $new_url, $change_type );
			$r = $this->http->check( $new_url, (int) $link->post_id );
			$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
			$response = array( 'new_url' => $new_url );
			if ( $ignore_domain ) {
				$pattern = TSOLIIN_HTTP::suggest_ignore_pattern_from_url( $new_url );
				if ( '' !== $pattern ) {
					TSOLIIN_HTTP::add_ignore_pattern( $pattern );
					$response['ignored_pattern'] = $pattern;
				}
			}
		}
		if ( $anchor_changed && $anchor_done ) {
			$this->db->update_link_anchor_text( $link_id, $new_anchor );
			$response['new_anchor'] = $new_anchor;
		}
		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}
		if ( $this->scanner->did_create_revision_for_post( (int) $link->post_id ) ) {
			$response['revision_created'] = 1;
		}

		$updated = $this->db->get_link( $link_id );
		if ( $url_changed ) {
			$response = array_merge( $response, $this->status_payload_from_link( $updated, $new_url, true ) );
		}
		wp_send_json_success( $this->append_filter_match( $response, $updated ) );
	}

	/**
	 * Update anchor text inside a comment body (not author URL rows).
	 *
	 * @param object $link   DB link row.
	 * @param string $url    href URL to match.
	 * @param string $anchor New anchor text.
	 * @return bool
	 */
	private function update_anchor_in_comment( $link, $url, $anchor ) {
		$cid = $this->get_comment_id_from_link( $link );
		if ( $cid && get_comment( $cid ) ) {
			return $this->scanner->replace_anchor_in_comment_content( $cid, $url, $anchor );
		}

		$cids = $this->find_comment_ids_for_link( $link, 5 );
		foreach ( (array) $cids as $cid ) {
			if ( $this->scanner->replace_anchor_in_comment_content( absint( $cid ), $url, $anchor ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find approved comments on a post that contain or own a link URL.
	 *
	 * Uses the same URL equivalence rules as unlink/update (trailing slash, scheme, encoding).
	 *
	 * @param object $link  DB link row.
	 * @param int    $limit Max comment IDs to return.
	 * @return int[]
	 */
	private function find_comment_ids_for_link( $link, $limit = 10 ) {
		global $wpdb;
		$post_id = absint( $link->post_id );
		$url     = (string) $link->link_url;
		if ( ! $post_id || '' === $url ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND ( comment_content LIKE %s OR comment_author_url != '' ) LIMIT %d",
				$post_id,
				'%' . $wpdb->esc_like( $url ) . '%',
				max( 10, absint( $limit ) * 5 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$matched = array();
		foreach ( (array) $cids as $cid ) {
			$comment = get_comment( absint( $cid ) );
			if ( ! $comment ) {
				continue;
			}
			$author_url = trim( (string) $comment->comment_author_url );
			if ( '' !== $author_url && $this->scanner->comment_author_url_matches_row_url( $author_url, $url ) ) {
				$matched[] = (int) $comment->comment_ID;
				continue;
			}
			$content = (string) $comment->comment_content;
			if ( TSOLIIN_HTTP::content_contains_complete_url( $content, $url )
				|| TSOLIIN_HTTP::content_contains_complete_url( $content, str_replace( '&', '&amp;', $url ) )
				|| TSOLIIN_HTTP::content_contains_complete_url( $content, urldecode( $url ) ) ) {
				$matched[] = (int) $comment->comment_ID;
			}
		}

		return array_slice( array_values( array_unique( $matched ) ), 0, absint( $limit ) );
	}

	/**
	 * Verified https:// target for bulk upgrade (empty when not server-confirmed).
	 *
	 * @param object|null $link DB link row.
	 * @return string
	 */
	private function get_verified_https_upgrade_for_link( $link ) {
		if ( ! $link || empty( $link->link_url ) ) {
			return '';
		}
		if ( ! TSOLIIN_HTTP::is_plain_http_url( (string) $link->link_url ) ) {
			return '';
		}
		if ( $this->is_non_editable_source( $link ) ) {
			return '';
		}
		return $this->http->get_verified_https_upgrade_url( (string) $link->link_url, (int) $link->post_id );
	}

	/**
	 * Whether a http→https URL change must be blocked (not server-verified).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url Proposed URL.
	 * @return bool
	 */
	private function rejects_unverified_https_upgrade( $link, $new_url ) {
		if ( ! $link || ! TSOLIIN_HTTP::is_plain_http_url( (string) $link->link_url ) ) {
			return false;
		}
		$new_url = (string) $new_url;
		if ( ! preg_match( '#\Ahttps://#i', $new_url ) ) {
			return false;
		}
		$verified = $this->http->get_verified_https_upgrade_url( (string) $link->link_url, (int) $link->post_id );
		return '' === $verified || $verified !== $new_url;
	}

	/**
	 * Resolve WordPress comment ID from a link inspector row.
	 *
	 * @param object $link DB link row.
	 * @return int
	 */
	private function get_comment_id_from_link( $link ) {
		return TSOLIIN_Support::get_comment_id_from_link_row( $link );
	}

	/**
	 * Replace a stored URL in the link's source (post, comment, widget, menu, or term).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private function replace_link_url_in_source( $link, $new_url ) {
		if ( ! $link ) {
			return false;
		}
		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$old_url   = (string) $link->link_url;
		$new_url   = (string) $new_url;

		if ( 'comment' === $link_type ) {
			return $this->update_url_in_comment( $link, $new_url );
		}
		if ( 'widget' === $link_type ) {
			return $this->scanner->replace_url_in_widget( (string) $link->source_key, $old_url, $new_url );
		}
		if ( 'menu' === $link_type ) {
			return $this->scanner->replace_url_in_menu_item( (string) $link->source_key, $old_url, $new_url );
		}
		if ( 'term' === $link_type ) {
			return $this->scanner->replace_url_in_term( (string) $link->source_key, $old_url, $new_url );
		}

		$done = $this->scanner->replace_url_in_post( (int) $link->post_id, $old_url, $new_url );
		if ( $done ) {
			return true;
		}
		// Content may already use the redirect destination instead of the stored URL.
		if ( ! empty( $link->redirect_url ) ) {
			$redir = trim( (string) $link->redirect_url );
			if ( '' !== $redir && $redir !== $old_url && $redir !== $new_url ) {
				// Only rewrite when that destination appears once (avoid changing other intentional links).
				if ( 1 === $this->scanner->count_href_matches_in_post( (int) $link->post_id, $redir ) ) {
					return $this->scanner->replace_url_in_post( (int) $link->post_id, $redir, $new_url );
				}
			}
		}
		return false;
	}

	/**
	 * Update a URL that belongs to a comment (author URL or href in content).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private function update_url_in_comment( $link, $new_url ) {
		$cid     = $this->get_comment_id_from_link( $link );
		$comment = $cid ? get_comment( $cid ) : null;
		if ( $comment ) {
			if ( ! current_user_can( 'edit_comment', $cid ) ) {
				return false;
			}
			$old_url    = (string) $link->link_url;
			$author_url = trim( (string) $comment->comment_author_url );

			if ( $this->scanner->comment_author_url_matches_row_url( $author_url, $old_url ) ) {
				return false !== wp_update_comment( array(
					'comment_ID'         => $cid,
					'comment_author_url' => $new_url,
				) );
			}

			return $this->scanner->replace_url_in_comment_content( $cid, $old_url, $new_url );
		}

		// Fallback: search all comments on this post for the URL.
		$cids = $this->find_comment_ids_for_link( $link, 5 );
		if ( empty( $cids ) ) {
			return false;
		}
		$done = false;
		foreach ( $cids as $cid ) {
			$cid = absint( $cid );
			$c   = get_comment( $cid );
			if ( ! $c || ! current_user_can( 'edit_comment', $cid ) ) {
				continue;
			}
			if ( $this->scanner->comment_author_url_matches_row_url( trim( (string) $c->comment_author_url ), (string) $link->link_url ) ) {
				wp_update_comment( array( 'comment_ID' => absint( $cid ), 'comment_author_url' => $new_url ) );
				$done = true;
			} elseif ( $this->scanner->replace_url_in_comment_content( absint( $cid ), (string) $link->link_url, $new_url ) ) {
				$done = true;
			}
		}
		return $done;
	}

	public function ajax_unlink() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';

		if ( ! TSOLIIN_Support::can_unlink_link( $link ) ) {
			// Ghost row: detected earlier but no longer in the post — drop from the list.
			if ( $this->scanner->is_orphan_post_link_row( $link ) ) {
				$this->db->delete_link( $link_id );
				wp_send_json_success(
					array(
						'message' => __( 'This URL is not in the post content (stale list entry). Removed from the list only.', 'tso-link-inspector' ),
						'stale'   => true,
					)
				);
			}
			$blocked = $this->get_non_editable_source_message( $link );
			if ( '' === $blocked ) {
				$blocked = __( 'Cannot unlink this item.', 'tso-link-inspector' );
			}
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		if ( ! $this->scanner->is_orphan_post_link_row( $link ) ) {
			$this->require_link_mutation_cap( $link );
		}

		if ( 'comment' === $type ) {
			$done = $this->unlink_comment( $link );
		} elseif ( 'widget' === $type ) {
			$done = $this->scanner->unlink_in_widget( (string) $link->source_key, (string) $link->link_url );
		} elseif ( 'menu' === $type ) {
			$done = $this->scanner->unlink_in_menu_item( (string) $link->source_key, (string) $link->link_url );
		} elseif ( 'term' === $type ) {
			$done = $this->scanner->unlink_in_term( (string) $link->source_key, (string) $link->link_url );
		} else {
			$done = $this->scanner->unlink_link_row_in_post( $link );
		}

		if ( ! $done ) {
			// Still listed but nothing left to remove from the source — clear the ghost row.
			if ( in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true )
				&& $this->scanner->is_orphan_post_link_row( $link ) ) {
				$this->db->delete_link( $link_id );
				wp_send_json_success(
					array(
						'message' => __( 'This URL is not in the post content (stale list entry). Removed from the list only.', 'tso-link-inspector' ),
						'stale'   => true,
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => __( 'Could not remove this URL from the source. Open Go to edit and remove it manually, or use Delete to remove only this list row.', 'tso-link-inspector' ),
				)
			);
		}
		$this->db->delete_link( $link_id );
		wp_send_json_success( array( 'message' => __( 'Link tag removed.', 'tso-link-inspector' ) ) );
	}

	/** @param object $link */
	private function unlink_comment( $link ) {
		$cid = $this->get_comment_id_from_link( $link );
		if ( $cid ) {
			return $this->scanner->unlink_in_comment( $cid, $link->link_url );
		}
		$cids = $this->find_comment_ids_for_link( $link, 10 );
		if ( empty( $cids ) ) {
			return false;
		}
		$done = false;
		foreach ( $cids as $cid ) {
			if ( $this->scanner->unlink_in_comment( absint( $cid ), $link->link_url ) ) {
				$done = true;
			}
		}
		return $done;
	}

	public function ajax_delete_link() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'tso-link-inspector' ) ) );
		}
		if ( ! $this->db->delete_link( $link_id ) ) {
			$this->send_stale_row_success();
		}
		wp_send_json_success( array( 'message' => __( 'Record deleted.', 'tso-link-inspector' ) ) );
	}

	public function ajax_add_ignore() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pattern = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}

		if ( '' === $pattern ) {
			$pattern = TSOLIIN_HTTP::suggest_ignore_pattern_from_url( (string) $link->link_url );
		}
		if ( '' === $pattern ) {
			wp_send_json_error( array( 'message' => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ) ) );
		}

		$result = TSOLIIN_HTTP::add_ignore_pattern( $pattern );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ) ) );
		}
		if ( 'exists' === $result ) {
			wp_send_json_error( array( 'message' => __( 'This domain or URL is already on the ignore list.', 'tso-link-inspector' ) ) );
		}

		$r = $this->http->check( (string) $link->link_url, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );

		/* translators: %s: domain or URL prefix added to the ignore list */
		$message = sprintf( __( 'Added %s to the ignore list. This link is now skipped.', 'tso-link-inspector' ), $pattern );

		wp_send_json_success( $this->append_filter_match( array(
			'message'     => $message,
			'pattern'     => $pattern,
			'status_code' => (int) $r['status_code'],
			'css_class'   => TSOLIIN_HTTP::status_class( (int) $r['status_code'], (int) $r['is_broken'], (string) $link->link_url ),
			'label'       => TSOLIIN_HTTP::status_label( (int) $r['status_code'], (string) $link->link_url ),
			'is_broken'   => (int) $r['is_broken'],
			'status_html' => TSOLIIN_Support::render_link_status_html( $updated, $this->http ),
		), $updated ) );
	}

	public function ajax_dismiss_onboarding() {
		$this->check_nonce_and_cap();
		$this->mark_onboarding_seen();
		wp_send_json_success();
	}

	public function ajax_make_relative() {
		$this->check_nonce_and_cap();
		if ( ! TSOLIIN_Support::is_relative_url_tool_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ) ) );
		}
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$this->require_link_mutation_cap( $link );

		if ( ! TSOLIIN_Support::can_offer_convert_to_relative( $link ) ) {
			$blocked = $this->get_non_editable_source_message( $link );
			wp_send_json_error(
				array(
					'message' => '' !== $blocked
						? $blocked
						: __( 'This link cannot be converted to /path.', 'tso-link-inspector' ),
				)
			);
		}

		$relative = TSOLIIN_HTTP::absolute_internal_to_relative( (string) $link->link_url );
		if ( false === $relative || $relative === (string) $link->link_url ) {
			wp_send_json_error( array( 'message' => __( 'This link cannot be converted to /path.', 'tso-link-inspector' ) ) );
		}

		$done = $this->replace_link_url_in_source( $link, $relative );

		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'Original URL not found in the source. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
		}

		$this->db->update_link_url( $link_id, $relative, 'relative' );
		$r = $this->http->check( $relative, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );

		$response = array_merge(
			array( 'new_url' => $relative, 'message' => __( 'Link saved as /path (domain removed).', 'tso-link-inspector' ) ),
			$this->status_payload_from_link( $updated, $relative, true )
		);
		wp_send_json_success( $this->append_filter_match( $response, $updated ) );
	}

	/**
	 * Upgrade one http:// URL to verified https:// (same rules as bulk upgrade).
	 */
	public function ajax_upgrade_https() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$this->require_link_mutation_cap( $link );

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		$https_url = $this->get_verified_https_upgrade_for_link( $link );
		if ( '' === $https_url ) {
			wp_send_json_error( array( 'message' => __( 'HTTPS could not be verified for this URL.', 'tso-link-inspector' ) ) );
		}

		$done = $this->replace_link_url_in_source( $link, $https_url );
		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'Original URL not found in the source. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
		}

		$this->db->update_link_url( $link_id, $https_url, 'https' );
		$r = $this->http->check( $https_url, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );

		$response = array_merge(
			array( 'new_url' => $https_url, 'message' => __( 'Link upgraded to HTTPS.', 'tso-link-inspector' ) ),
			$this->status_payload_from_link( $updated, $https_url, true )
		);
		wp_send_json_success( $this->append_filter_match( $response, $updated ) );
	}

	public function ajax_not_broken() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'tso-link-inspector' ) ) );
		}
		$link = $this->db->get_link( $link_id );
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$this->db->mark_as_not_broken( $link_id );
		$updated = $this->db->get_link( $link_id );
		wp_send_json_success( $this->append_filter_match( array(
			'message'     => __( 'Marked as OK and moved to Manual locks. It leaves that list only if the URL or redirect changes, or a check finds it broken.', 'tso-link-inspector' ),
			'css_class'   => 'tsoliin-status--ok',
			'label'       => __( 'OK (manual)', 'tso-link-inspector' ),
			'status_code' => 200,
			'status_html' => TSOLIIN_Support::render_link_status_html( $updated, $this->http ),
		), $updated ) );
	}

	public function ajax_bulk_action() {
		$this->check_nonce_and_cap();
		$action   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link_ids = array();
		if ( isset( $_POST['link_ids'] ) && is_array( $_POST['link_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( array_map( 'absint', wp_unslash( (array) $_POST['link_ids'] ) ) as $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$link_ids[] = $id;
			}
		}
		$link_ids = array_filter( $link_ids );
		if ( empty( $link_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No items selected.', 'tso-link-inspector' ) ) );
		}

		if ( 'recheck' === $action ) {
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link     = $this->db->get_link( $link_id );
			$row_data = array( 'link_id' => $link_id );
			if ( $link ) {
				$link             = $this->resync_link_before_recheck( $link );
				if ( $link ) {
					$link_id = (int) $link->id;
				}
			}
			if ( ! $link ) {
				$this->db->delete_link( $link_id );
				wp_send_json_success(
					array(
						'done'           => ( $index + 1 ) >= $total,
						'processed'      => $index + 1,
						'total'          => $total,
						'pct'            => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
						'next_index'     => $index + 1,
						'row'            => array(
							'link_id'        => $link_id,
							'removed'        => true,
							'matches_filter' => false,
						),
						/* translators: 1: current, 2: total */
						'message'        => sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
					)
				);
			}
			if ( $link ) {
				// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
				$r = $this->http->check( $link->link_url, (int) $link->post_id );
				$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
				$updated  = $this->db->get_link( $link_id );
				$row_data = array_merge(
					array( 'link_id' => $link_id ),
					$this->status_payload_from_link( $updated, (string) $link->link_url, true )
				);
				$row_data = $this->append_filter_match( $row_data, $updated );
				if ( $updated ) {
					$row_data['new_url'] = (string) $updated->link_url;
				}
			}
			wp_send_json_success( array(
				'done'       => ( $index + 1 ) >= $total,
				'processed'  => $index + 1,
				'total'      => $total,
				'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
				'next_index' => $index + 1,
				'row'        => $row_data,
				/* translators: 1: current, 2: total */
				'message'    => sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
			) );
		} elseif ( 'unlink' === $action ) {
			// Bulk unlink: process one at a time like recheck.
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link    = $this->db->get_link( $link_id );
			$type    = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
			$ok      = false;
			$skipped = false;
			if ( $link ) {
				if ( ! TSOLIIN_Support::current_user_can_mutate_link( $link ) && ! $this->scanner->is_orphan_post_link_row( $link ) ) {
					$ok      = false;
					$skipped = true;
				} elseif ( ! TSOLIIN_Support::can_unlink_link( $link ) ) {
					if ( $this->scanner->is_orphan_post_link_row( $link ) ) {
						$this->db->delete_link( $link_id );
						$ok = true;
					} else {
						$ok      = false;
						$skipped = true;
					}
				} elseif ( 'comment' === $type ) {
					$ok = $this->unlink_comment( $link );
				} elseif ( 'widget' === $type ) {
					$ok = $this->scanner->unlink_in_widget( (string) $link->source_key, (string) $link->link_url );
				} elseif ( 'menu' === $type ) {
					$ok = $this->scanner->unlink_in_menu_item( (string) $link->source_key, (string) $link->link_url );
				} elseif ( 'term' === $type ) {
					$ok = $this->scanner->unlink_in_term( (string) $link->source_key, (string) $link->link_url );
				} else {
					$ok = $this->scanner->unlink_link_row_in_post( $link );
				}
				if ( $ok ) {
					$this->db->delete_link( $link_id );
				}
			} else {
				// Already removed by a post save / scan — treat as done so bulk does not error.
				$ok = true;
			}
			wp_send_json_success( array(
				'done'       => ( $index + 1 ) >= $total,
				'processed'  => $index + 1,
				'total'      => $total,
				'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
				'next_index' => $index + 1,
				'link_id'    => $link_id,
				'unlinked'   => $ok,
				'skipped'    => $skipped,
				/* translators: 1: current, 2: total */
				'message'    => sprintf( __( 'Unlinking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
			) );
		} elseif ( 'not_broken' === $action ) {
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$row_data = array( 'link_id' => $link_id );
			$marked   = false;
			if ( $this->db->mark_as_not_broken( $link_id ) ) {
				$updated = $this->db->get_link( $link_id );
				if ( $updated ) {
					$marked   = true;
					$row_data = array_merge(
						array( 'link_id' => $link_id ),
						$this->append_filter_match(
							array(
								'status_html' => TSOLIIN_Support::render_link_status_html( $updated, $this->http ),
								'status_code' => 200,
								'css_class'   => 'tsoliin-status--ok',
								'label'       => __( 'OK (manual)', 'tso-link-inspector' ),
								'is_broken'   => 0,
							),
							$updated
						)
					);
				}
			}
			wp_send_json_success(
				array(
					'done'       => ( $index + 1 ) >= $total,
					'processed'  => $index + 1,
					'total'      => $total,
					'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
					'next_index' => $index + 1,
					'link_id'    => $link_id,
					'marked'     => $marked,
					'row'        => $row_data,
					/* translators: 1: current, 2: total */
					'message'    => sprintf( __( 'Marking as OK %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
				)
			);
		} elseif ( 'make_relative' === $action ) {
			if ( ! TSOLIIN_Support::is_relative_url_tool_enabled() ) {
				wp_send_json_error( array( 'message' => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ) ) );
			}
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link      = $this->db->get_link( $link_id );
			$converted = false;
			$skipped   = false;
			$failed    = false;
			$row_data  = array( 'link_id' => $link_id );
			if ( $link ) {
				if ( ! TSOLIIN_Support::current_user_can_mutate_link( $link ) || ! TSOLIIN_Support::can_offer_convert_to_relative( $link ) ) {
					$skipped = true;
				} else {
					$relative = TSOLIIN_HTTP::absolute_internal_to_relative( (string) $link->link_url );
					if ( false === $relative || $relative === (string) $link->link_url ) {
						$skipped = true;
					} elseif ( $this->replace_link_url_in_source( $link, $relative ) ) {
						$this->db->update_link_url( $link_id, $relative, 'bulk_relative' );
						$r = $this->http->check( $relative, (int) $link->post_id );
						$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
						$updated   = $this->db->get_link( $link_id );
						$row_data  = array_merge(
							array( 'link_id' => $link_id, 'new_url' => $relative ),
							$this->status_payload_from_link( $updated, $relative, true )
						);
						$row_data  = $this->append_filter_match( $row_data, $updated );
						$converted = true;
					} else {
						$failed = true;
					}
				}
			} else {
				$failed = true;
			}
			wp_send_json_success(
				array(
					'done'       => ( $index + 1 ) >= $total,
					'processed'  => $index + 1,
					'total'      => $total,
					'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
					'next_index' => $index + 1,
					'link_id'    => $link_id,
					'converted'  => $converted,
					'skipped'    => $skipped,
					'failed'     => $failed,
					'row'        => $row_data,
					/* translators: 1: current, 2: total */
					'message'    => sprintf( __( 'Converting %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
				)
			);
		} elseif ( 'upgrade_https' === $action ) {
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link      = $this->db->get_link( $link_id );
			$converted = false;
			$skipped   = false;
			$failed    = false;
			$row_data  = array( 'link_id' => $link_id );
			if ( $link ) {
				if ( ! TSOLIIN_Support::current_user_can_mutate_link( $link ) ) {
					$skipped = true;
				} else {
					$https_url = $this->get_verified_https_upgrade_for_link( $link );
					if ( '' === $https_url ) {
						$skipped = true;
					} elseif ( $this->replace_link_url_in_source( $link, $https_url ) ) {
						$this->db->update_link_url( $link_id, $https_url, 'bulk_https' );
						$r = $this->http->check( $https_url, (int) $link->post_id );
						$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
						$updated   = $this->db->get_link( $link_id );
						$row_data  = array_merge(
							array( 'link_id' => $link_id, 'new_url' => $https_url ),
							$this->status_payload_from_link( $updated, $https_url, true )
						);
						$row_data  = $this->append_filter_match( $row_data, $updated );
						$converted = true;
					} else {
						$failed = true;
					}
				}
			} else {
				$failed = true;
			}
			wp_send_json_success(
				array(
					'done'       => ( $index + 1 ) >= $total,
					'processed'  => $index + 1,
					'total'      => $total,
					'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
					'next_index' => $index + 1,
					'link_id'    => $link_id,
					'converted'  => $converted,
					'skipped'    => $skipped,
					'failed'     => $failed,
					'row'        => $row_data,
					/* translators: 1: current, 2: total */
					'message'    => sprintf( __( 'Upgrading to HTTPS %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
				)
			);
		} elseif ( 'delete' === $action ) {
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$exists  = (bool) $this->db->get_link( $link_id );
			$deleted = false;
			if ( ! $exists ) {
				// Already removed by a concurrent scan/edit — treat as done.
				$deleted = true;
			} else {
				$deleted = (bool) $this->db->delete_link( $link_id );
			}
			wp_send_json_success(
				array(
					'done'       => ( $index + 1 ) >= $total,
					'processed'  => $index + 1,
					'total'      => $total,
					'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
					'next_index' => $index + 1,
					'link_id'    => $link_id,
					'deleted'    => $deleted,
					/* translators: 1: current, 2: total */
					'message'    => sprintf( __( 'Deleting %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown bulk action.', 'tso-link-inspector' ) ) );
		}
	}

	public function ajax_start_bg_check() {
		$this->check_nonce_and_cap();
		$resume  = ! isset( $_POST['resume'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scan    = $this->cron->get_bg_scan_progress();
		if ( $this->cron->is_bg_scan_blocking_check() ) {
			wp_send_json_error(
				array(
					'message' => __( 'A scan is still in progress. Wait for it to finish (or stop it) before checking links.', 'tso-link-inspector' ),
				)
			);
		}
		$pending_before = $this->db->get_pending_check_count( $post_id );
		if ( ! $this->cron->start_bg_check( $resume, $post_id, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Another background task is changing state. Try again in a moment.', 'tso-link-inspector' ) ) );
		}
		$bg = $this->cron->get_bg_progress();
		$resumed = $resume && $pending_before > 0;
		if ( $post_id > 0 ) {
			$message = __( 'Checking links in this post. You can continue browsing.', 'tso-link-inspector' );
		} elseif ( $resumed ) {
			$message = __( 'Resuming check from where it left off. You can continue browsing.', 'tso-link-inspector' );
		} else {
			$message = __( 'Check started. You can continue browsing.', 'tso-link-inspector' );
		}
		wp_send_json_success( array(
			'running' => ! empty( $bg['running'] ),
			'checked' => $bg['checked'],
			'total'   => $bg['total'],
			'pct'     => $bg['pct'],
			'post_id' => $bg['post_id'],
			'complete'=> ! empty( $bg['complete'] ),
			'pending' => isset( $bg['pending'] ) ? (int) $bg['pending'] : $this->db->get_pending_check_count( $post_id ),
			'resumed' => $resumed ? 1 : 0,
			'message' => $message,
		) );
	}

	public function ajax_bg_check_tick() {
		$this->check_nonce_and_cap();
		$status = 'idle';
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			$status = $this->cron->run_bg_step( null, false );
		}
		$payload         = $this->format_check_progress_payload( $this->cron->get_bg_progress() );
		$payload['busy'] = ( 'busy' === $status );
		wp_send_json_success( $payload );
	}

	/**
	 * Progress fields for the admin check bar.
	 *
	 * @param array $bg get_bg_progress() result.
	 * @return array<string,mixed>
	 */
	private function format_check_progress_payload( $bg ) {
		$bg   = is_array( $bg ) ? $bg : array();
		$done = empty( $bg['running'] ) && ! empty( $bg['complete'] ) && (int) ( isset( $bg['pending'] ) ? $bg['pending'] : 0 ) <= 0;
		if ( ! empty( $bg['running'] ) ) {
			/* translators: 1: checked count, 2: total count */
			$message = sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), (int) $bg['checked'], (int) $bg['total'] );
		} elseif ( $done ) {
			$message = __( 'Check completed!', 'tso-link-inspector' );
		} else {
			$message = __( 'Stopped', 'tso-link-inspector' );
		}
		$bg['done']    = $done;
		$bg['message'] = $message;
		return $bg;
	}

	public function ajax_stop_bg_check() {
		$this->check_nonce_and_cap();
		$this->cron->stop_bg_check();
		// Scope pending to the list view the admin is looking at (may differ from bg post_id).
		$view_post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$bg           = $this->cron->get_bg_progress();
		wp_send_json_success(
			array(
				'running' => false,
				'pending' => $this->db->get_pending_check_count( $view_post_id ),
				'pct'     => isset( $bg['pct'] ) ? (int) $bg['pct'] : 0,
			)
		);
	}

	public function ajax_start_bg_scan() {
		$this->check_nonce_and_cap();
		$resume = ! isset( $_POST['resume'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			$this->cron->stop_bg_check();
		}
		$resumable_before = $this->cron->is_bg_scan_resumable();
		if ( ! $this->cron->start_bg_scan( $resume, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Another background task is changing state. Try again in a moment.', 'tso-link-inspector' ) ) );
		}
		$scan = $this->cron->get_bg_scan_progress();
		if ( $resume && $resumable_before ) {
			$message = __( 'Resuming scan from where it left off. You can continue browsing.', 'tso-link-inspector' );
		} else {
			$message = __( 'Scan started. You can continue browsing.', 'tso-link-inspector' );
		}
		wp_send_json_success(
			array(
				'running'   => true,
				'scanned'   => $scan['scanned'],
				'total'     => $scan['total'],
				'pct'       => $scan['pct'],
				'resumable' => ! empty( $scan['resumable'] ) ? 1 : 0,
				'resumed'   => ( $resume && $resumable_before ) ? 1 : 0,
				'message'   => $message,
			)
		);
	}

	public function ajax_bg_scan_tick() {
		$this->check_nonce_and_cap();
		$status = 'idle';
		if ( get_option( 'tsoliin_bg_scan_running' ) ) {
			$status = $this->cron->run_bg_scan_step( null, false );
		}
		$scan         = $this->format_scan_progress_payload( $this->cron->get_bg_scan_progress() );
		$scan['busy'] = ( 'busy' === $status );
		wp_send_json_success( $scan );
	}

	/**
	 * Continue a running scan or check from any admin screen (not the plugin UI ticks).
	 */
	public function ajax_bg_keep_alive() {
		$this->check_nonce_and_cap();
		$status = 'idle';
		if ( get_option( 'tsoliin_bg_scan_running' ) ) {
			$status = $this->cron->run_bg_scan_step( null, false );
		} elseif ( get_option( 'tsoliin_bg_check_running' ) ) {
			$status = $this->cron->run_bg_step( null, false );
		}
		wp_send_json_success(
			array(
				'scan_running'  => (bool) get_option( 'tsoliin_bg_scan_running' ),
				'check_running' => (bool) get_option( 'tsoliin_bg_check_running' ),
				'busy'          => ( 'busy' === $status ),
			)
		);
	}

	/**
	 * Progress fields for the admin scan bar.
	 *
	 * @param array $scan get_bg_scan_progress() result.
	 * @return array<string,mixed>
	 */
	private function format_scan_progress_payload( $scan ) {
		$scan = is_array( $scan ) ? $scan : array();
		if ( ! empty( $scan['running'] ) ) {
			$phase   = isset( $scan['phase'] ) ? (string) $scan['phase'] : 'posts';
			$total   = (int) $scan['total'];
			$scanned = (int) $scan['scanned'];
			if ( $total > 0 && $scanned >= $total && 'posts' !== $phase && 'done' !== $phase ) {
				$message = __( 'Posts scanned. Finishing comments, menus and other sources…', 'tso-link-inspector' );
			} else {
				/* translators: 1: scanned count, 2: total count */
				$message = sprintf( __( 'Scanning %1$d of %2$d...', 'tso-link-inspector' ), $scanned, $total );
			}
		} elseif ( ! empty( $scan['done'] ) ) {
			$message = __( 'Scan completed!', 'tso-link-inspector' );
		} elseif ( ! empty( $scan['error'] ) ) {
			$message = (string) $scan['error'];
		} elseif ( ! empty( $scan['resumable'] ) ) {
			/* translators: 1: scanned count, 2: total count */
			$message = sprintf( __( 'Scan paused at %1$d of %2$d. Click Continue scan.', 'tso-link-inspector' ), (int) $scan['scanned'], (int) $scan['total'] );
		} else {
			$message = __( 'Stopped', 'tso-link-inspector' );
		}
		$scan['message'] = $message;
		return $scan;
	}

	public function ajax_stop_bg_scan() {
		$this->check_nonce_and_cap();
		$this->cron->stop_bg_scan();
		$scan = $this->cron->get_bg_scan_progress();
		wp_send_json_success(
			array(
				'running'   => false,
				'scanned'   => $scan['scanned'],
				'total'     => $scan['total'],
				'pct'       => $scan['pct'],
				'resumable' => ! empty( $scan['resumable'] ) ? 1 : 0,
			)
		);
	}

	/**
	 * Discard paused scan/check sessions without deleting links or HTTP results.
	 *
	 * @return void
	 */
	public function ajax_discard_bg_jobs() {
		$this->check_nonce_and_cap();
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $scope, array( 'scan', 'check', 'all' ), true ) ) {
			$scope = 'all';
		}
		if ( 'scan' === $scope || 'all' === $scope ) {
			$this->cron->discard_bg_scan();
		}
		if ( 'check' === $scope || 'all' === $scope ) {
			$this->cron->discard_bg_check();
		}
		$view_post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scan         = $this->format_scan_progress_payload( $this->cron->get_bg_scan_progress() );
		$bg           = $this->cron->get_bg_progress();
		wp_send_json_success(
			array(
				'scope'           => $scope,
				'scan'            => $scan,
				'running'         => false,
				'pending'         => $this->db->get_pending_check_count( $view_post_id ),
				'pct'             => isset( $bg['pct'] ) ? (int) $bg['pct'] : 0,
				'check_btn_label' => $this->get_check_button_label_for_scope( $view_post_id ),
			)
		);
	}

	public function ajax_check_progress() {
		$this->check_nonce_and_cap();
		$view_post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nudge        = isset( $_POST['nudge'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['nudge'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$check_session = isset( $_POST['check_session_active'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['check_session_active'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stored_post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
		$scan         = $this->cron->get_bg_scan_progress();
		if ( $nudge
			&& $check_session
			&& ! $this->cron->is_bg_scan_blocking_check()
			&& ! get_option( 'tsoliin_bg_check_running' )
			&& ! get_option( 'tsoliin_bg_check_user_stopped' )
			&& $this->db->get_pending_check_count( $stored_post_id ) > 0 ) {
			$this->cron->start_bg_check( true, $stored_post_id );
		}
		// Progress poll is UI-only. Scan/check ticks, keep-alive, and cron advance the jobs.
		$bg           = $this->cron->get_bg_progress();
		$post_id      = isset( $bg['post_id'] ) ? absint( $bg['post_id'] ) : 0;
		$pending_view = $this->db->get_pending_check_count( $view_post_id );
		// While a check runs, hero TOTAL must match the progress denominator (check scope, all links).
		// Otherwise list Internal/External or post filters make cards disagree with "X of Y".
		if ( ! empty( $bg['running'] ) || ( ! empty( $bg['complete'] ) && (int) $bg['pending'] > 0 ) ) {
			$stats_post_id = $post_id;
			$stats_scope   = 'all';
		} else {
			$stats_post_id = $view_post_id;
			$stats_scope   = isset( $_POST['scope'] ) ? $this->db->sanitize_scope_input( wp_unslash( $_POST['scope'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$stats = $stats_post_id
			? $this->db->get_stats_for_post( $stats_post_id, $stats_scope )
			: $this->db->get_stats( $stats_scope );
		$display = array();
		foreach ( $stats as $key => $value ) {
			$display[ $key ] = TSOLIIN_Support::format_display_number( (int) $value );
		}
		$done = ! $bg['running'] && ! empty( $bg['complete'] ) && (int) $bg['pending'] <= 0;
		if ( $bg['running'] ) {
			/* translators: 1: checked count, 2: total count */
			$message = sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $bg['checked'], $bg['total'] );
		} elseif ( $done ) {
			$message = __( 'Check completed!', 'tso-link-inspector' );
		} else {
			$message = __( 'Stopped', 'tso-link-inspector' );
		}

		if ( $scan['running'] ) {
			$phase = isset( $scan['phase'] ) ? (string) $scan['phase'] : 'posts';
			if ( (int) $scan['total'] > 0 && (int) $scan['scanned'] >= (int) $scan['total'] && 'posts' !== $phase && 'done' !== $phase ) {
				$scan_message = __( 'Posts scanned. Finishing comments, menus and other sources…', 'tso-link-inspector' );
			} else {
				/* translators: 1: scanned count, 2: total count */
				$scan_message = sprintf( __( 'Scanning %1$d of %2$d...', 'tso-link-inspector' ), $scan['scanned'], $scan['total'] );
			}
		} elseif ( ! empty( $scan['done'] ) ) {
			$scan_message = __( 'Scan completed!', 'tso-link-inspector' );
		} elseif ( '' !== $scan['error'] ) {
			$scan_message = $scan['error'];
		} elseif ( ! empty( $scan['resumable'] ) ) {
			/* translators: 1: scanned count, 2: total count */
			$scan_message = sprintf( __( 'Scan paused at %1$d of %2$d. Click Continue scan.', 'tso-link-inspector' ), $scan['scanned'], $scan['total'] );
		} else {
			$scan_message = __( 'Stopped', 'tso-link-inspector' );
		}

		$schedule   = TSOLIIN_Schedule::get_settings();
		$queue      = TSOLIIN_Schedule::get_queue_stats( $this->db );
		$queue_chip = TSOLIIN_Schedule::get_queue_chip( $this->db, $queue, $schedule );

		wp_send_json_success(
			array(
				'running' => $bg['running'],
				'checked' => $bg['checked'],
				'total'   => $bg['total'],
				'pct'     => $bg['pct'],
				'post_id' => $post_id,
				'pending' => $pending_view,
				'bg_pending' => isset( $bg['pending'] ) ? absint( $bg['pending'] ) : 0,
				'complete'=> ! empty( $bg['complete'] ),
				'check_paused' => (
					$this->cron->is_bg_check_paused()
					&& $pending_view > 0
				) ? 1 : 0,
				'broken'  => $stats['broken'],
				'stats'   => $stats,
				'display' => $display,
				'done'    => $done,
				'message' => $message,
				'queue'   => $queue_chip,
				'scan'    => array(
					'running'   => $scan['running'],
					'scanned'   => $scan['scanned'],
					'total'     => $scan['total'],
					'pct'       => $scan['pct'],
					'done'      => ! empty( $scan['done'] ),
					'resumable' => ! empty( $scan['resumable'] ),
					'error'     => $scan['error'],
					'message'   => $scan_message,
				),
			)
		);
	}

	/**
	 * Return dashboard filter tab + stat card counts (optionally scoped to one post).
	 */
	public function ajax_get_stats() {
		$this->check_nonce_and_cap();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope   = isset( $_POST['scope'] ) ? $this->db->sanitize_scope_input( wp_unslash( $_POST['scope'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$stats   = $post_id ? $this->db->get_stats_for_post( $post_id, $scope ) : $this->db->get_stats( $scope );
		$display = array();
		foreach ( $stats as $key => $value ) {
			$display[ $key ] = TSOLIIN_Support::format_display_number( (int) $value );
		}
		wp_send_json_success(
			array(
				'stats'   => $stats,
				'display' => $display,
			)
		);
	}

	public function ajax_smart_suggest() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'menu' === $link_type && ! TSOLIIN_Support::is_custom_menu_url_row( $link ) ) {
			// Post-type / taxonomy menu items: URL comes from the linked object.
			wp_send_json_success(
				array(
					'link_id'     => $link_id,
					'original'    => $link->link_url,
					'suggestions' => array(),
					'count'       => 0,
					'note'        => __( 'This menu item URL comes from the linked page/post. Change that content, or open Go to edit for the classic menu screen.', 'tso-link-inspector' ),
					'menu_only'   => true,
				)
			);
		}
		$sk_suggest = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk_suggest ) ) {
			wp_send_json_success(
				array(
					'link_id'     => $link_id,
					'original'    => $link->link_url,
					'suggestions' => array(),
					'count'       => 0,
					'note'        => __( 'WooCommerce product field URLs cannot be updated from suggestions. Change them in the product editor using Go to edit.', 'tso-link-inspector' ),
					'woo_only'    => true,
				)
			);
		}

		$suggestions  = array();
		$seen_urls    = array( (string) $link->link_url );
		$bot_blocked  = false;
		$unverified_remote = false;
		$link_broken  = ! empty( $link->is_broken );

		// If the DB already has a known redirect_url, offer it as first (instant) suggestion — except when
		// applying it would pin a “rolling release” download to one file version (handled as transparent redirect).
		$orig_abs = TSOLIIN_Scanner::resolve_to_absolute_url( (string) $link->link_url, (int) $link->post_id );
		$r_orig   = $this->http->check( $orig_abs, (int) $link->post_id );
		if ( ! empty( $link->redirect_url ) ) {
			$rurl = (string) $link->redirect_url;
			$skip_redirect_suggest = $this->http->is_transparent_redirect( (string) $link->link_url, $rurl )
				|| TSOLIIN_HTTP::is_chrome_webstore_unavailable_url( $rurl )
				|| (
					TSOLIIN_HTTP::is_plain_http_url( $orig_abs )
					&& TSOLIIN_HTTP::is_plain_http_url( $rurl )
					&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $rurl )
				)
				|| (
					! empty( $link->is_broken )
					&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $rurl )
				);
			if ( ! $skip_redirect_suggest ) {
				$r_dest       = $this->http->check( $rurl, (int) $link->post_id );
				$sc           = (int) $r_dest['status_code'];
				$display_code = $sc;
				$reason       = __( 'Destination detected (re-checked now)', 'tso-link-inspector' );
				$dest_unverified = TSOLIIN_HTTP::is_unverified_remote_status( $sc )
					&& (
						TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $rurl )
						|| $this->http->is_meaningful_redirect_target( $orig_abs, $rurl )
					);
				if ( TSOLIIN_HTTP::is_bot_block_status( $sc ) || TSOLIIN_HTTP::is_unverified_remote_status( $sc ) ) {
					$bot_blocked = TSOLIIN_HTTP::is_bot_block_status( $sc );
					$unverified_remote = true;
					$stored_code = (int) $link->status_code;
					if ( in_array( $stored_code, array( 301, 302, 303, 307, 308 ), true ) ) {
						$display_code = $stored_code;
						$reason       = __( 'Redirect destination already detected by scan (re-check blocked)', 'tso-link-inspector' );
					}
				}
				$suggestions[] = array(
					'url'         => $rurl,
					'status_code' => $display_code,
					'label'       => TSOLIIN_HTTP::status_label( $display_code, $rurl ),
					'reason'      => $reason,
					'confidence'  => 'high',
					'unverified'  => $dest_unverified && ! TSOLIIN_HTTP::suggestion_fixes_broken_link( $r_orig, $r_dest, (int) $link->status_code ),
					'actionable'  => TSOLIIN_HTTP::suggestion_fixes_broken_link( $r_orig, $r_dest, (int) $link->status_code )
						|| $dest_unverified
						|| (
							! $link_broken
							&& in_array( (int) $link->status_code, array( 301, 302, 303, 307, 308 ), true )
							&& $this->http->is_meaningful_redirect_target( $orig_abs, $rurl )
						),
				);
				$seen_urls[] = $rurl;
			}
		}

		// Run smart suggest to find additional alternatives.
		foreach ( $this->http->smart_suggest( $link->link_url, (int) $link->post_id ) as $s ) {
			if ( ! in_array( $s['url'], $seen_urls, true ) ) {
				if ( ! empty( $s['status_code'] ) && TSOLIIN_HTTP::is_bot_block_status( (int) $s['status_code'] ) ) {
					$bot_blocked = true;
				}
				if ( ! empty( $s['status_code'] ) && TSOLIIN_HTTP::is_unverified_remote_status( (int) $s['status_code'] ) ) {
					$unverified_remote = true;
				}
				$suggestions[] = $s;
				$seen_urls[]   = $s['url'];
			}
		}

		$safe_suggestions = array();
		foreach ( $suggestions as $suggestion ) {
			$safe_url = TSOLIIN_HTTP::sanitize_external_http_url( isset( $suggestion['url'] ) ? $suggestion['url'] : '' );
			if ( false === $safe_url ) {
				continue;
			}
			$r_live = $this->http->check( $safe_url, (int) $link->post_id );
			if ( TSOLIIN_HTTP::is_bot_block_status( (int) $r_live['status_code'] ) ) {
				$bot_blocked = true;
			}
			if ( TSOLIIN_HTTP::is_unverified_remote_status( (int) $r_live['status_code'] ) ) {
				$unverified_remote = true;
			}
			$suggestion['url']         = $safe_url;
			$suggestion['status_code'] = (int) $r_live['status_code'];
			$suggestion['label']       = TSOLIIN_HTTP::status_label( (int) $r_live['status_code'], $safe_url );
			$suggestion['reason']      = sanitize_text_field( isset( $suggestion['reason'] ) ? (string) $suggestion['reason'] : '' );
			$meaningful                = TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $safe_url )
				|| $this->http->is_meaningful_redirect_target( $orig_abs, $safe_url );
			$live_ok                   = TSOLIIN_HTTP::suggestion_fixes_broken_link( $r_orig, $r_live, (int) $link->status_code );
			$unverified                = ! $live_ok
				&& TSOLIIN_HTTP::is_unverified_remote_status( (int) $r_live['status_code'] )
				&& $meaningful;
			$suggestion['unverified']  = $unverified;
			$suggestion['actionable']  = $live_ok || $unverified;
			$safe_suggestions[]        = $suggestion;
		}

		$safe_suggestions = array_values(
			array_filter(
				$safe_suggestions,
				function ( $row ) use ( $orig_abs, $link_broken, $link ) {
					if ( $link_broken ) {
						if ( empty( $row['actionable'] ) ) {
							return false;
						}
						$target = isset( $row['url'] ) ? (string) $row['url'] : '';
						if (
							'' !== $target
							&& TSOLIIN_HTTP::is_resource_not_found_status( (int) $link->status_code )
							&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $target )
						) {
							return false;
						}
						return true;
					}
					if ( ! empty( $row['actionable'] ) ) {
						return true;
					}
					$target = isset( $row['url'] ) ? (string) $row['url'] : '';
					if ( '' === $target ) {
						return false;
					}
					if ( ! empty( $row['unverified'] ) && (
						TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $target )
						|| $this->http->is_meaningful_redirect_target( $orig_abs, $target )
					) ) {
						return true;
					}
					if ( TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $target ) && TSOLIIN_HTTP::is_plain_http_url( $orig_abs ) ) {
						return true;
					}
					// Keep redirect targets the scanner already found (e.g. http legacy host → https canonical).
					return $this->http->is_meaningful_redirect_target( $orig_abs, $target );
				}
			)
		);

		$has_actionable = ! empty(
			array_filter(
				$safe_suggestions,
				static function ( $row ) {
					return ! empty( $row['actionable'] );
				}
			)
		);
		$has_unverified = ! empty(
			array_filter(
				$safe_suggestions,
				static function ( $row ) {
					return ! empty( $row['unverified'] );
				}
			)
		);

		$note = '';
		if ( empty( $safe_suggestions ) ) {
			if ( $bot_blocked || $unverified_remote ) {
				$note = __( 'This server cannot confirm the destination (geo-block, bot wall, or timeout). It may work in a browser. Use Edit link and tick “Save even if this server cannot confirm the URL”, or mark the link as OK.', 'tso-link-inspector' );
			} elseif ( $link_broken ) {
				$note = __( 'No alternative URL could fix this broken link. The destination may be permanently gone — update or remove the link manually.', 'tso-link-inspector' );
			} elseif ( TSOLIIN_HTTP::is_plain_http_url( $orig_abs ) ) {
				$note = __( 'No HTTPS upgrade or other useful alternative was found. The site may only offer HTTP, so there is nothing helpful to apply—changing www alone would not fix the insecure HTTP link.', 'tso-link-inspector' );
			}
		} elseif ( $has_unverified ) {
			$note = __( 'This server cannot confirm the suggested URL (geo-block, bot wall, or timeout). Use Apply anyway to save it, or Apply and ignore domain to skip future checks for this host.', 'tso-link-inspector' );
		} elseif ( $bot_blocked && $has_actionable ) {
			$note = __( 'Social networks often block server checks. The suggested HTTPS URL should work in a browser — open it to confirm before applying.', 'tso-link-inspector' );
		} elseif ( $bot_blocked && ! $has_actionable ) {
			$note = __( 'The destination blocks automated checks (403/401/429). It may work in a browser, but your server cannot confirm it — verify manually before editing the link.', 'tso-link-inspector' );
		}

		wp_send_json_success( array(
			'link_id'     => $link_id,
			'original'    => $link->link_url,
			'suggestions' => $safe_suggestions,
			'count'       => count( $safe_suggestions ),
			'note'        => $note,
		) );
	}

	public function ajax_link_preview() {
		$this->check_nonce_and_cap();
		$link_id     = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_url_raw = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$link        = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			$this->send_stale_row_success();
		}
		$this->require_link_mutation_cap( $link );

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		$new_url = TSOLIIN_HTTP::sanitize_editable_link_url( $new_url_raw, (int) $link->post_id );
		if ( false === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid URL.', 'tso-link-inspector' ) ) );
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$preview   = $this->scanner->preview_url_change_in_post(
			(int) $link->post_id,
			(string) $link->link_url,
			$new_url,
			$link_type
		);
		wp_send_json_success( $preview );
	}

	/**
	 * POST form for CSV/PDF export (no nonce in GET/query string).
	 *
	 * @param string               $action        admin-post action.
	 * @param string               $button_id     Button element id.
	 * @param string               $label         Button label (already translated).
	 * @param string               $dashicon      Dashicons suffix without prefix.
	 * @param array<string,mixed>  $fields        Hidden field name => value.
	 * @param bool                 $target_blank  Open in a new tab (PDF HTML).
	 * @return void
	 */
	private function render_export_form( $action, $button_id, $label, $dashicon, array $fields, $target_blank ) {
		$action    = sanitize_key( (string) $action );
		$button_id = sanitize_html_class( (string) $button_id );
		$dashicon  = sanitize_html_class( (string) $dashicon );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tsoliin-export-form"';
		if ( $target_blank ) {
			echo ' target="_blank" rel="noopener noreferrer"';
		}
		echo '>';
		wp_nonce_field( 'tsoliin_action', 'tsoliin_export_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<button type="submit" class="button button-secondary" id="' . esc_attr( $button_id ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $dashicon ) . '"></span> ';
		echo esc_html( $label );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * Shared export POST: capability, nonce, then stream.
	 *
	 * @param string $format csv|pdf.
	 * @return void
	 */
	private function stream_export_from_post( $format ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}
		check_admin_referer( 'tsoliin_action', 'tsoliin_export_nonce' );

		$filter  = isset( $_POST['filter'] ) ? $this->sanitize_list_filter( wp_unslash( $_POST['filter'] ) ) : 'all';
		$quality = isset( $_POST['quality_filter'] ) ? $this->sanitize_list_quality_filter( wp_unslash( $_POST['quality_filter'] ) ) : '';
		$scope   = isset( $_POST['scope'] ) ? $this->db->sanitize_scope_input( wp_unslash( $_POST['scope'] ) ) : 'all';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$search  = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';

		$args = array(
			'filter'         => $filter,
			'quality_filter' => $quality,
			'scope'          => $scope,
			'search'         => $search,
			'post_id'        => $post_id,
		);
		if ( 'pdf' === $format ) {
			TSOLIIN_Reports::stream_pdf_html( $this->db, $args );
		} else {
			TSOLIIN_Reports::stream_csv( $this->db, $args );
		}
	}

	public function handle_export_csv() {
		$this->stream_export_from_post( 'csv' );
	}

	public function handle_export_pdf() {
		$this->stream_export_from_post( 'pdf' );
	}

	/**
	 * Empty the inspector table and scan/check progress (Settings POST).
	 *
	 * @return void
	 */
	public function handle_reset_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}
		check_admin_referer( 'tsoliin_reset_all' );
		$this->truncate_plugin_records();
		$redirect = add_query_arg(
			array(
				'page'            => 'tso-link-inspector-settings',
				'tsoliin_notice'  => 'reset',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Truncate the plugin table and clear scan/check progress options.
	 *
	 * @return void
	 */
	private function truncate_plugin_records() {
		global $wpdb;
		$table = $this->db->get_table();
		if ( is_string( $table ) && '' !== $table && $table === $this->db->get_table() ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' ); // Table name validated against get_table() on line above.
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
		$this->db->clear_url_change_history();
		$this->clear_scan_progress_options();
		TSOLIIN_DB::clear_stats_cache();
	}

	public function ajax_diagnose() {
		$this->check_nonce_and_cap();
		wp_send_json_success( array( 'lines' => $this->get_diagnostic_lines() ) );
	}

	/**
	 * Build diagnostics output lines (DB, sample scan, background jobs, cron).
	 *
	 * @return string[]
	 */
	private function get_diagnostic_lines() {
		$info  = array();
		$test  = $this->db->self_test();
		$info[] = ( $test['table_exists'] ? 'OK' : 'ERR' ) . ' ' . __( 'DB table:', 'tso-link-inspector' ) . ' ' . $this->db->get_table();
		$info[] = ( $test['insert_ok'] ? 'OK' : 'ERR' ) . ' ' . __( 'INSERT test', 'tso-link-inspector' ) . ( $test['error'] ? ': ' . $test['error'] : '' );
		$pts    = $this->scanner->get_post_types();
		$info[] = 'OK ' . __( 'Post types:', 'tso-link-inspector' ) . ' ' . implode( ', ', $pts );
		$total  = $this->scanner->get_total_posts();
		$info[] = 'OK ' . __( 'Published posts:', 'tso-link-inspector' ) . ' ' . $total;
		$ids    = $this->scanner->get_post_ids( 1, 1 );
		if ( ! empty( $ids ) ) {
			$post   = get_post( $ids[0] );
			$info[] = 'OK ' . __( 'First post:', 'tso-link-inspector' ) . ' ' . $ids[0] . ' "' . esc_html( (string) $post->post_title ) . '"';
			$html   = do_blocks( $post->post_content );
			$links  = $this->scanner->extract_links( $html );
			$info[] = 'OK ' . __( 'Links in first post:', 'tso-link-inspector' ) . ' ' . count( $links );
			$n      = $this->scanner->scan_post( $ids[0] );
			$info[] = 'OK scan_post(): ' . $n;
		}
		$stats  = $this->db->get_stats();
		$info[] = 'OK ' . __( 'DB records:', 'tso-link-inspector' ) . ' ' . $stats['total'];

		$info = array_merge( $info, $this->get_diagnostic_background_lines() );

		return $info;
	}

	/**
	 * Diagnostics for background scan/check, WP-Cron, and site gate.
	 *
	 * @return string[]
	 */
	private function get_diagnostic_background_lines() {
		$lines = array();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$lines[] = 'WARN ' . __( 'DISABLE_WP_CRON is true — schedule a system cron to call wp-cron.php, or click Continue scan/check here to enable the page fallback.', 'tso-link-inspector' );
		}

		$settings = get_option( 'tsoliin_settings', array() );
		$meta_on  = ! empty( $settings['scan_meta'] );
		$acf_on   = class_exists( 'TSOLIIN_Acf', false ) && TSOLIIN_Acf::is_plugin_active();
		$lines[] = ( $meta_on ? 'OK' : 'INFO' ) . ' ' . __( 'Custom fields scan:', 'tso-link-inspector' ) . ' ' . ( $meta_on ? __( 'enabled', 'tso-link-inspector' ) : __( 'disabled', 'tso-link-inspector' ) );
		$lines[] = ( $acf_on ? 'OK' : 'INFO' ) . ' ' . __( 'ACF plugin:', 'tso-link-inspector' ) . ' ' . ( $acf_on ? __( 'active', 'tso-link-inspector' ) : __( 'not active', 'tso-link-inspector' ) );

		if ( $this->http->is_site_gated_cached() ) {
			$lines[] = 'WARN ' . __( 'Coming-soon gate active — internal HTML links are Unverifiable; Scan now and ACF extraction still work from the database.', 'tso-link-inspector' );
		}

		$scan = $this->cron->get_bg_scan_progress();
		$lines = array_merge( $lines, $this->format_diagnostic_scan_lines( $scan ) );

		$check = $this->cron->get_bg_progress();
		$lines = array_merge( $lines, $this->format_diagnostic_check_lines( $check ) );

		$last_scan = (string) get_option( 'tsoliin_last_full_scan', '' );
		if ( '' !== $last_scan ) {
			$lines[] = 'OK ' . __( 'Last full scan:', 'tso-link-inspector' ) . ' ' . $last_scan;
		}
		$last_check = (string) get_option( 'tsoliin_last_check_batch', '' );
		if ( '' !== $last_check ) {
			$lines[] = 'OK ' . __( 'Last check batch:', 'tso-link-inspector' ) . ' ' . $last_check;
		}

		return $lines;
	}

	/**
	 * @param array{ running: bool, scanned: int, total: int, pct: int, complete: bool, resumable: bool, error: string, done: bool } $scan Scan progress.
	 * @return string[]
	 */
	private function format_diagnostic_scan_lines( array $scan ) {
		$lines   = array();
		$scanned = (int) $scan['scanned'];
		$total   = (int) $scan['total'];
		$pct     = (int) $scan['pct'];
		$error   = isset( $scan['error'] ) ? trim( (string) $scan['error'] ) : '';

		if ( ! empty( $scan['running'] ) ) {
			$lines[] = sprintf(
				'OK %s %d/%d (%d%%)',
				__( 'Background scan:', 'tso-link-inspector' ),
				$scanned,
				$total,
				$pct
			);
		} elseif ( '' !== $error ) {
			$lines[] = 'ERR ' . __( 'Background scan stopped:', 'tso-link-inspector' ) . ' ' . $error;
			if ( $scanned > 0 && $scanned < $total ) {
				$lines[] = sprintf(
					'WARN %s %d/%d (%d%%)',
					__( 'Scan progress saved at', 'tso-link-inspector' ),
					$scanned,
					$total,
					$pct
				);
			}
		} elseif ( ! empty( $scan['resumable'] ) ) {
			$lines[] = sprintf(
				'WARN %s %d/%d (%d%%) — %s',
				__( 'Background scan paused at', 'tso-link-inspector' ),
				$scanned,
				$total,
				$pct,
				__( 'click Continue scan', 'tso-link-inspector' )
			);
		} elseif ( ! empty( $scan['done'] ) ) {
			$lines[] = 'OK ' . __( 'Background scan: completed', 'tso-link-inspector' );
		} else {
			$lines[] = 'OK ' . __( 'Background scan: idle', 'tso-link-inspector' );
		}

		$lines = array_merge( $lines, $this->format_diagnostic_cron_lines(
			__( 'scan', 'tso-link-inspector' ),
			TSOLIIN_Cron::HOOK_BG_SCAN_STEP,
			'tsoliin_bg_scan_started',
			! empty( $scan['running'] )
		) );

		return $lines;
	}

	/**
	 * @param array{ running: bool, checked: int, total: int, pct: int, post_id: int, pending: int } $check Check progress.
	 * @return string[]
	 */
	private function format_diagnostic_check_lines( array $check ) {
		$lines   = array();
		$checked = (int) $check['checked'];
		$total   = (int) $check['total'];
		$pct     = (int) $check['pct'];
		$pending = (int) $check['pending'];

		if ( ! empty( $check['running'] ) ) {
			$lines[] = sprintf(
				'OK %s %d/%d (%d%%)',
				__( 'Background check:', 'tso-link-inspector' ),
				$checked,
				$total,
				$pct
			);
		} elseif ( $pending > 0 && $pct < 100 ) {
			$lines[] = sprintf(
				'WARN %s %d/%d (%d%%), %d %s — %s',
				__( 'Background check paused at', 'tso-link-inspector' ),
				$checked,
				$total,
				$pct,
				$pending,
				__( 'pending', 'tso-link-inspector' ),
				__( 'click Continue check', 'tso-link-inspector' )
			);
		} elseif ( $total > 0 && $pending <= 0 && empty( $check['running'] ) ) {
			$lines[] = 'OK ' . __( 'Background check: completed', 'tso-link-inspector' );
		} else {
			$lines[] = 'OK ' . __( 'Background check: idle', 'tso-link-inspector' ) . ( $pending > 0 ? ' (' . $pending . ' ' . __( 'unchecked', 'tso-link-inspector' ) . ')' : '' );
		}

		$lines = array_merge( $lines, $this->format_diagnostic_cron_lines(
			__( 'check', 'tso-link-inspector' ),
			TSOLIIN_Cron::HOOK_BG_STEP,
			'tsoliin_bg_check_started',
			! empty( $check['running'] )
		) );

		return $lines;
	}

	/**
	 * @param string $job_label Short label (scan / check).
	 * @param string $hook      WP-Cron hook name.
	 * @param string $started_option Option key for last heartbeat timestamp.
	 * @param bool   $job_running Whether the job flag is set.
	 * @return string[]
	 */
	private function format_diagnostic_cron_lines( $job_label, $hook, $started_option, $job_running ) {
		$lines = array();
		$next  = wp_next_scheduled( $hook );
		if ( $next && $next < time() - 15 ) {
			$lines[] = 'WARN ' . sprintf(
				/* translators: 1: scan or check, 2: local datetime */
				__( 'Background %1$s cron is overdue since %2$s — click Continue scan/check here or inspect WP-Cron.', 'tso-link-inspector' ),
				$job_label,
				wp_date( 'Y-m-d H:i:s', $next )
			);
		} elseif ( $next ) {
			$lines[] = 'OK ' . sprintf(
				/* translators: 1: scan or check, 2: local datetime */
				__( 'Next background %1$s cron: %2$s', 'tso-link-inspector' ),
				$job_label,
				wp_date( 'Y-m-d H:i:s', $next )
			);
		} elseif ( $job_running ) {
			$lines[] = 'WARN ' . sprintf(
				/* translators: %s: scan or check */
				__( 'Background %s is marked running but no WP-Cron step is scheduled — click Continue scan/check here to enable the page fallback.', 'tso-link-inspector' ),
				$job_label
			);
		}

		if ( $job_running ) {
			$started = (string) get_option( $started_option, '' );
			if ( '' !== $started ) {
				$age = time() - (int) strtotime( $started );
				if ( $age > 300 ) {
					$lines[] = 'WARN ' . sprintf(
						/* translators: 1: scan or check, 2: minutes since last batch */
						__( 'Background %1$s heartbeat: %2$d min since last batch (may look stuck if WP-Cron is delayed).', 'tso-link-inspector' ),
						$job_label,
						(int) round( $age / 60 )
					);
				}
			}
		}

		return $lines;
	}
}
