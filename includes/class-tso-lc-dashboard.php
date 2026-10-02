<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * WordPress dashboard widget for Link Inspector.
 *
 * @package TSOLIIN_Link_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard summary widget.
 */
class TSOLIIN_Dashboard {

	/**
	 * Database service instance.
	 *
	 * @var TSOLIIN_DB
	 */
	private $db;

	/**
	 * Set up the class dependencies.
	 *
	 * @param TSOLIIN_DB $db Database handler.
	 */
	public function __construct( TSOLIIN_DB $db ) {
		$this->db = $db;
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget for administrators.
	 */
	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'tsoliin_dashboard_summary',
			__( 'TSO Link Inspector', 'tso-link-inspector' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render widget body.
	 */
	public function render_widget() {
		// The widget can load before any Link Inspector screen has repaired a missing table.
		$this->db->ensure_table_exists();
		$stats     = $this->db->get_stats();
		$last_scan = (string) get_option( 'tsoliin_last_full_scan', '' );
		$base      = admin_url( 'tools.php?page=tso-link-inspector' );
		$date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="tsoliin-dashboard-widget">';
		echo '<ul class="tsoliin-dashboard-widget__stats">';
		echo '<li><strong>' . esc_html( TSOLIIN_Support::format_display_number( $stats['broken'] ) ) . '</strong> ';
		echo '<a href="' . esc_url( add_query_arg( 'filter', 'broken', $base ) ) . '">' . esc_html__( 'Broken links', 'tso-link-inspector' ) . '</a></li>';
		echo '<li><strong>' . esc_html( TSOLIIN_Support::format_display_number( $stats['unchecked'] ) ) . '</strong> ';
		echo '<a href="' . esc_url( add_query_arg( 'filter', 'unchecked', $base ) ) . '">' . esc_html__( 'Unchecked links', 'tso-link-inspector' ) . '</a></li>';
		echo '<li><strong>' . esc_html( TSOLIIN_Support::format_display_number( $stats['total'] ) ) . '</strong> ';
		echo esc_html__( 'Total saved links', 'tso-link-inspector' ) . '</li>';
		echo '</ul>';

		if ( '' !== $last_scan ) {
			echo '<p class="description">';
			echo esc_html__( 'Last scan:', 'tso-link-inspector' ) . ' ';
			echo esc_html( wp_date( $date_fmt, strtotime( $last_scan ) ) );
			echo '</p>';
		} else {
			echo '<p class="description"><em>' . esc_html__( 'No scan has been run yet.', 'tso-link-inspector' ) . '</em></p>';
		}

		echo '<p class="tsoliin-dashboard-widget__actions">';
		echo '<a class="button button-primary" href="' . esc_url( $base ) . '">' . esc_html__( 'Open Link Inspector', 'tso-link-inspector' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( add_query_arg( 'view', 'posts', $base ) ) . '">' . esc_html__( 'Posts with issues', 'tso-link-inspector' ) . '</a>';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_scan_enabled() ) {
			echo ' <a class="button button-secondary" href="' . esc_url( add_query_arg( 'view', 'products', $base ) ) . '">' . esc_html__( 'Products with issues', 'tso-link-inspector' ) . '</a>';
		}
		echo '</p>';
		echo '</div>';
	}
}
