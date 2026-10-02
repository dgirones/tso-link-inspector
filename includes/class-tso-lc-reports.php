<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Export reports (CSV, printable PDF/HTML).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Reports
 */
class TSOLIIN_Reports {

	/**
	 * CSV column headers (translatable).
	 *
	 * @return string[]
	 */
	public static function get_csv_headers() {
		return array(
			__( 'URL', 'tso-link-inspector' ),
			__( 'Anchor text', 'tso-link-inspector' ),
			__( 'Type', 'tso-link-inspector' ),
			__( 'Source post', 'tso-link-inspector' ),
			__( 'Source status', 'tso-link-inspector' ),
			__( 'Status', 'tso-link-inspector' ),
			__( 'Code', 'tso-link-inspector' ),
			__( 'Redirect URL', 'tso-link-inspector' ),
			__( 'Redirect chain', 'tso-link-inspector' ),
			__( 'Target status', 'tso-link-inspector' ),
			__( 'Manual lock', 'tso-link-inspector' ),
			__( 'Last checked', 'tso-link-inspector' ),
			__( 'Date found', 'tso-link-inspector' ),
		);
	}

	/**
	 * Human-readable export status for a link row.
	 *
	 * @param object $item Link row.
	 * @return string
	 */
	public static function get_export_status_label( $item ) {
		if ( ! empty( $item->user_verified ) ) {
			return __( 'Manual lock', 'tso-link-inspector' );
		}
		if ( ! empty( $item->is_broken ) ) {
			return __( 'Broken', 'tso-link-inspector' );
		}
		$code  = isset( $item->status_code ) ? (int) $item->status_code : 0;
		$redir = isset( $item->redirect_url ) ? trim( (string) $item->redirect_url ) : '';
		if ( ( $code >= 301 && $code < 400 ) || '' !== $redir ) {
			return __( 'Redirect', 'tso-link-inspector' );
		}
		if ( 200 === $code ) {
			return __( 'OK', 'tso-link-inspector' );
		}
		if ( 0 === $code && empty( $item->last_checked ) ) {
			return __( 'Unchecked', 'tso-link-inspector' );
		}
		return TSOLIIN_HTTP::status_label( $code, (string) $item->link_url );
	}

	/**
	 * Format redirect chain for CSV/plain text.
	 *
	 * @param object $item Link row.
	 * @return string
	 */
	public static function format_redirect_chain( $item ) {
		$chain = TSOLIIN_DB::decode_redirect_chain( isset( $item->redirect_chain ) ? (string) $item->redirect_chain : '' );
		if ( empty( $chain ) ) {
			return '';
		}
		$parts = array();
		foreach ( $chain as $hop ) {
			$parts[] = ( isset( $hop['code'] ) ? (int) $hop['code'] : 0 ) . ' → ' . ( isset( $hop['url'] ) ? (string) $hop['url'] : '' );
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Format a datetime column for export.
	 *
	 * @param string|null $mysql UTC datetime from DB.
	 * @return string
	 */
	public static function format_export_datetime( $mysql ) {
		if ( empty( $mysql ) ) {
			return '';
		}
		$ts = strtotime( (string) $mysql );
		if ( ! $ts ) {
			return (string) $mysql;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Build one CSV row from a link object.
	 *
	 * @param object $item Link row.
	 * @return string[]
	 */
	public static function build_csv_row( $item ) {
		return array(
			(string) $item->link_url,
			(string) $item->anchor_text,
			isset( $item->link_type ) ? (string) $item->link_type : 'link',
			isset( $item->post_title ) ? (string) $item->post_title : '',
			isset( $item->post_status ) ? (string) $item->post_status : '',
			self::get_export_status_label( $item ),
			(string) (int) $item->status_code,
			(string) $item->redirect_url,
			self::format_redirect_chain( $item ),
			TSOLIIN_Quality::get_target_post_status_label( $item ),
			! empty( $item->user_verified ) ? __( 'Yes', 'tso-link-inspector' ) : __( 'No', 'tso-link-inspector' ),
			self::format_export_datetime( $item->last_checked ),
			self::format_export_datetime( $item->date_found ),
		);
	}

	/**
	 * Stream CSV export to output.
	 *
	 * @param TSOLIIN_DB $db   Database handler.
	 * @param array      $args get_links() arguments.
	 * @return void
	 */
	public static function stream_csv( TSOLIIN_DB $db, array $args ) {
		$filter   = isset( $args['filter'] ) ? sanitize_key( (string) $args['filter'] ) : 'all';
		$result   = $db->get_links(
			array_merge(
				$args,
				array(
					'per_page' => 99999,
					'paged'    => 1,
				)
			)
		);
		$filename = 'tso-link-inspector-' . $filter . '-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		// BOM for Excel.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, self::get_csv_headers(), ';' );

		foreach ( $result['items'] as $item ) {
			fputcsv( $out, self::build_csv_row( $item ), ';' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Maximum rows in the HTML/PDF report (same default as CSV export).
	 *
	 * @return int
	 */
	public static function get_report_export_limit() {
		/**
		 * Filter how many links are included in the printable HTML report.
		 *
		 * @param int $limit Max rows (default 99999, same as CSV).
		 */
		return max( 1, (int) apply_filters( 'tsoliin_pdf_export_limit', 99999 ) );
	}

	/**
	 * Links included in the PDF/HTML report.
	 *
	 * @param TSOLIIN_DB $db    Database handler.
	 * @param array      $args  Export query args.
	 * @param int|null   $limit Row limit (null = use get_report_export_limit()).
	 * @return array{items:object[],total:int}
	 */
	public static function get_pdf_links( TSOLIIN_DB $db, array $args, $limit = null ) {
		$limit  = null === $limit ? self::get_report_export_limit() : max( 1, absint( $limit ) );
		$filter = isset( $args['filter'] ) ? sanitize_key( (string) $args['filter'] ) : 'all';

		if ( 'all' === $filter ) {
			$result = $db->get_links(
				array_merge(
					$args,
					array(
						'filter'   => 'broken',
						'per_page' => $limit,
						'paged'    => 1,
						'orderby'  => 'date_found',
						'order'    => 'DESC',
					)
				)
			);
			return array(
				'items' => $result['items'],
				'total' => (int) $result['total'],
			);
		}

		$result = $db->get_links(
			array_merge(
				$args,
				array(
					'per_page' => $limit,
					'paged'    => 1,
				)
			)
		);
		return array(
			'items' => $result['items'],
			'total' => (int) $result['total'],
		);
	}

	/**
	 * Filter label for report title.
	 *
	 * @param string $filter Filter key.
	 * @return string
	 */
	public static function get_filter_label( $filter ) {
		$labels = array(
			'all'                => __( 'All links', 'tso-link-inspector' ),
			'broken'             => __( 'Broken links', 'tso-link-inspector' ),
			'redirect'           => __( 'Redirects', 'tso-link-inspector' ),
			'ok'                 => __( 'OK links', 'tso-link-inspector' ),
			'unchecked'          => __( 'Unchecked links', 'tso-link-inspector' ),
			'http_insecure'      => __( 'HTTP insecure links', 'tso-link-inspector' ),
			'manual_locked'      => __( 'Manual locks', 'tso-link-inspector' ),
			'empty_anchor'       => __( 'Empty anchor text', 'tso-link-inspector' ),
			'generic_anchor'     => __( 'Generic anchor text', 'tso-link-inspector' ),
			'unpublished_target' => __( 'Unpublished targets', 'tso-link-inspector' ),
		);
		$filter = sanitize_key( (string) $filter );
		return isset( $labels[ $filter ] ) ? $labels[ $filter ] : $labels['all'];
	}

	/**
	 * Site logo URL for reports (custom logo or site icon).
	 *
	 * @return string
	 */
	public static function get_site_logo_url() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		$icon = get_site_icon_url( 128 );
		return is_string( $icon ) ? $icon : '';
	}

	/**
	 * Output printable HTML report (save as PDF via browser print).
	 *
	 * @param TSOLIIN_DB $db   Database handler.
	 * @param array      $args Export query args.
	 * @return void
	 */
	public static function stream_pdf_html( TSOLIIN_DB $db, array $args ) {
		$filter = isset( $args['filter'] ) ? sanitize_key( (string) $args['filter'] ) : 'all';
		$scope  = isset( $args['scope'] ) ? (string) $args['scope'] : 'all';
		$stats  = ! empty( $args['post_id'] )
			? $db->get_stats_for_post( (int) $args['post_id'], $scope )
			: $db->get_stats( $scope );
		$export = self::get_pdf_links( $db, $args );
		$items  = $export['items'];
		$total  = $export['total'];
		$logo   = self::get_site_logo_url();
		$site   = get_bloginfo( 'name' );
		$date   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$title  = self::get_filter_label( $filter );

		if ( 'all' === $filter ) {
			$section_title = __( 'Top broken links', 'tso-link-inspector' );
		} else {
			/* translators: %s: filter label */
			$section_title = sprintf( __( 'Sample: %s', 'tso-link-inspector' ), $title );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="tso-link-inspector-report-' . gmdate( 'Y-m-d' ) . '.html"' );

		wp_enqueue_style( 'tsoliin-report', TSOLIIN_PLUGIN_URL . 'assets/css/report.css', array(), TSOLIIN_VERSION );
		wp_enqueue_script( 'tsoliin-report', TSOLIIN_PLUGIN_URL . 'assets/js/report.js', array(), TSOLIIN_VERSION, true );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Link report', 'tso-link-inspector' ) ); ?></title>
		<?php wp_print_styles( array( 'tsoliin-report' ) ); ?>
</head>
<body class="tsoliin-report">
	<div class="tsoliin-report-actions">
		<button type="button" id="tsoliin-report-print" class="tsoliin-report-print"><?php esc_html_e( 'Print / Save as PDF', 'tso-link-inspector' ); ?></button>
	</div>

	<header class="tsoliin-report-header">
		<?php if ( '' !== $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site ); ?>">
		<?php endif; ?>
		<div>
			<h1><?php echo esc_html( $site ); ?></h1>
			<p><?php echo esc_html( __( 'Link report', 'tso-link-inspector' ) . ' — ' . $date ); ?></p>
			<p><?php echo esc_html( $title ); ?></p>
		</div>
	</header>

	<div class="tsoliin-stats-grid">
		<div class="tsoliin-stat-card"><strong><?php echo esc_html( (string) (int) $stats['total'] ); ?></strong><span><?php esc_html_e( 'Total', 'tso-link-inspector' ); ?></span></div>
		<div class="tsoliin-stat-card tsoliin-stat-card--broken"><strong><?php echo esc_html( (string) (int) $stats['broken'] ); ?></strong><span><?php esc_html_e( 'Broken', 'tso-link-inspector' ); ?></span></div>
		<div class="tsoliin-stat-card"><strong><?php echo esc_html( (string) (int) $stats['redirect'] ); ?></strong><span><?php esc_html_e( 'Redirect', 'tso-link-inspector' ); ?></span></div>
		<div class="tsoliin-stat-card"><strong><?php echo esc_html( (string) (int) $stats['ok'] ); ?></strong><span><?php esc_html_e( 'OK', 'tso-link-inspector' ); ?></span></div>
		<div class="tsoliin-stat-card"><strong><?php echo esc_html( (string) (int) $stats['unchecked'] ); ?></strong><span><?php esc_html_e( 'Unchecked', 'tso-link-inspector' ); ?></span></div>
		<div class="tsoliin-stat-card"><strong><?php echo esc_html( (string) (int) ( $stats['http_insecure'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'HTTP insecure', 'tso-link-inspector' ); ?></span></div>
	</div>

	<h2><?php echo esc_html( $section_title ); ?></h2>
		<?php if ( empty( $items ) ) : ?>
		<p><?php esc_html_e( 'No links match this report.', 'tso-link-inspector' ); ?></p>
	<?php else : ?>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'tso-link-inspector' ); ?></th>
					<th><?php esc_html_e( 'Anchor', 'tso-link-inspector' ); ?></th>
					<th><?php esc_html_e( 'Source', 'tso-link-inspector' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tso-link-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) $item->link_url ); ?>"><?php echo esc_html( (string) $item->link_url ); ?></a></td>
						<td><?php echo esc_html( (string) $item->anchor_text ); ?></td>
						<td><?php echo esc_html( isset( $item->post_title ) ? (string) $item->post_title : '' ); ?></td>
						<td><?php echo esc_html( self::get_export_status_label( $item ) . ' (' . (int) $item->status_code . ')' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total > count( $items ) ) : ?>
			<p><em>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: shown count, 2: total matching links */
					__( 'Showing %1$d of %2$d links. Export CSV for the full list or raise tsoliin_pdf_export_limit.', 'tso-link-inspector' ),
					count( $items ),
					$total
				)
			);
			?>
			</em></p>
		<?php elseif ( count( $items ) > 500 ) : ?>
			<p><em><?php esc_html_e( 'Large report: printing may take a while in the browser.', 'tso-link-inspector' ); ?></em></p>
		<?php endif; ?>
	<?php endif; ?>

	<footer class="tsoliin-report-footer">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: plugin name */
				__( 'Generated by %s', 'tso-link-inspector' ),
				'TSO Link Inspector'
			)
		);
		?>
	</footer>
		<?php wp_print_scripts( array( 'tsoliin-report' ) ); ?>
</body>
</html>
		<?php
		exit;
	}
}
