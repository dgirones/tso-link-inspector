<?php
/**
 * Uninstall TSO Link Inspector.
 *
 * Called automatically by WordPress when the user deletes the plugin.
 * Removes ALL plugin data: database table, options, and scheduled cron events.
 *
 * @package TSOLIIN_Link_Inspector
 */

// Exit if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop custom database tables (canonical + legacy name) ────────────────
$tsoliin_tables = array(
	$wpdb->prefix . 'tso_link_inspector',
	$wpdb->prefix . 'tso_link_inspector_history',
	$wpdb->prefix . 'pc_tso_link_inspector', // leftover 2.3.x table name
	$wpdb->prefix . 'pc_tso_link_inspector_history', // leftover 2.3.x history name
);
foreach ( $tsoliin_tables as $tsoliin_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $tsoliin_table ) . '`' );
}

// ── Delete all plugin options ────────────────────────────────────────────
$tsoliin_options = array(
	'tsoliin_version',
	'tsoliin_legacy_pc_table_cleared',
	'tsoliin_settings',
	'tsoliin_last_full_scan',
	'tsoliin_last_check_batch',
	'tsoliin_last_check_count',
	'tsoliin_bg_check_running',
	'tsoliin_bg_check_complete',
	'tsoliin_bg_check_token',
	'tsoliin_bg_check_last_error',
	'tsoliin_bg_check_checked',
	'tsoliin_bg_check_total',
	'tsoliin_bg_check_started',
	'tsoliin_bg_check_post_id',
	'tsoliin_bg_scan_running',
	'tsoliin_bg_scan_token',
	'tsoliin_bg_scan_phase',
	'tsoliin_bg_scan_page',
	'tsoliin_bg_scan_total',
	'tsoliin_bg_scan_scanned',
	'tsoliin_bg_scan_started',
	'tsoliin_bg_scan_error',
	'tsoliin_bg_scan_complete',
	'tsoliin_total_posts_scanned',
	'tsoliin_comment_scan_after_id',
	'tsoliin_menu_scan_after_id',
	'tsoliin_widget_scan_after_index',
	'tsoliin_term_scan_after_id',
	'tsoliin_fse_scan_after_id',
	'tsoliin_broken_digest_last_sent',
	'tsoliin_immediate_broken_queue',
	'tsoliin_bg_check_empty_retries',
	'tsoliin_bg_check_user_stopped',
	'tsoliin_site_gate_state',
	'tsoliin_bg_scan_rest_until',
	'tsoliin_bg_check_rest_until',
	'tsoliin_bg_inflight_scan_post',
	'tsoliin_bg_inflight_scan_phase',
	'tsoliin_bg_inflight_check_link',
	'tsoliin_bg_skipped',
	'tsoliin_bg_check_resynced',
	'tsoliin_bg_scan_page_pos',
	'tsoliin_bg_scan_phase_ceiling',
);
foreach ( $tsoliin_options as $tsoliin_option_name ) {
	delete_option( $tsoliin_option_name );
}

$tsoliin_transients = array(
	'tsoliin_unpub_cnt_all',
	'tsoliin_unpub_cnt_v2_all',
	'tsoliin_unpub_cnt_v3_all',
	'tsoliin_transparent_rd_cleanup',
	'tsoliin_scan_lock_comments',
	'tsoliin_scan_lock_menus',
	'tsoliin_scan_lock_terms',
	'tsoliin_scan_lock_fse',
	'tsoliin_scan_lock_widgets',
	'tsoliin_scan_lock_acfopt',
	'tsoliin_immediate_queue_lock',
	'tsoliin_bg_scan_step_lock',
	'tsoliin_bg_check_step_lock',
	'tsoliin_bg_lifecycle_start_lock',
	'tsoliin_site_gate',
);
foreach ( $tsoliin_transients as $tsoliin_transient_name ) {
	delete_transient( $tsoliin_transient_name );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'tsoliin_onboarding_dismissed' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'tsoliin_per_page' ) );

// ── Clear all scheduled cron events ─────────────────────────────────────
$tsoliin_hooks = array(
	'tsoliin_cron_scan',
	'tsoliin_cron_check',
	'tsoliin_bg_check_step',
	'tsoliin_bg_scan_step',
);
foreach ( $tsoliin_hooks as $tsoliin_hook_name ) {
	wp_clear_scheduled_hook( $tsoliin_hook_name );
}
