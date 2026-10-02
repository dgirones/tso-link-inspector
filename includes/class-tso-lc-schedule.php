<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Automatic check schedule helpers (throughput, queue stats).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Schedule
 */
class TSOLIIN_Schedule {

	/**
	 * Cron-related settings with defaults.
	 *
	 * @return array{
	 *   recheck_days: int,
	 *   broken_recheck_days: int,
	 *   cron_check_batch: int
	 * }
	 */
	public static function get_settings() {
		$s = get_option( 'tsoliin_settings', array() );
		return array(
			'recheck_days'        => isset( $s['recheck_days'] ) ? max( 1, min( 365, absint( $s['recheck_days'] ) ) ) : 7,
			'broken_recheck_days' => isset( $s['broken_recheck_days'] ) ? max( 1, min( 90, absint( $s['broken_recheck_days'] ) ) ) : 7,
			'cron_check_batch'    => isset( $s['cron_check_batch'] ) ? max( 5, min( 100, absint( $s['cron_check_batch'] ) ) ) : 20,
		);
	}

	/**
	 * Checks per hour/day based on hourly WP-Cron and batch size.
	 *
	 * @return array{ per_hour: int, per_day: int }
	 */
	public static function get_throughput() {
		$batch    = self::get_settings()['cron_check_batch'];
		$per_hour = $batch;
		return array(
			'per_hour' => $per_hour,
			'per_day'  => $per_hour * 24,
		);
	}

	/**
	 * Queue + cycle estimates for the admin UI.
	 *
	 * @param TSOLIIN_DB $db Database handler.
	 * @return array<string, int|float>
	 */
	public static function get_queue_stats( TSOLIIN_DB $db ) {
		$settings = self::get_settings();
		$counts   = $db->get_cron_queue_counts( $settings['recheck_days'], $settings['broken_recheck_days'] );
		$pending  = (int) $counts['total'];
		$per_day  = (int) self::get_throughput()['per_day'];
		$est_days = ( $pending > 0 && $per_day > 0 ) ? ceil( $pending / $per_day ) : 0;

		return array_merge(
			$counts,
			array(
				'pending'        => $pending,
				'checks_per_day' => $per_day,
				'est_days'       => $est_days,
			)
		);
	}

	/**
	 * Human-readable queue chip label + tooltip for the dashboard hero.
	 *
	 * @param TSOLIIN_DB           $db       Database handler.
	 * @param array<string, int>   $queue    Output from get_queue_stats().
	 * @param array<string, mixed> $settings Schedule settings.
	 * @return array{ label: string, title: string, warn: bool }
	 */
	public static function get_queue_chip( TSOLIIN_DB $db, array $queue, array $settings ) {
		// Card "Unchecked" = never checked and not manually locked.
		$never_checked = (int) $db->get_unchecked_count();
		// Queue immediate work = last_checked IS NULL (may include manual locks after a full recheck reset).
		$immediate   = (int) $queue['unchecked'];
		$stale_count = (int) $queue['broken_stale'] + (int) $queue['ok_stale'];
		$total       = (int) $queue['pending'];

		$title = sprintf(
			/* translators: 1: never-checked count (matches Unchecked card), 2: broken stale count, 3: OK stale count, 4: OK recheck days, 5: broken recheck days, 6: checks per day, 7: estimated days to clear queue, 8: HTTP queue rows still unchecked including manual locks */
			__( 'Queue: %1$d never checked, %8$d awaiting HTTP (includes manual locks), %2$d broken (older than %5$d days), %3$d OK (older than %4$d days). Throughput: ~%6$d checks/day. Estimated time to clear queue: ~%7$d days.', 'tso-link-inspector' ),
			$never_checked,
			(int) $queue['broken_stale'],
			(int) $queue['ok_stale'],
			(int) $settings['recheck_days'],
			(int) $settings['broken_recheck_days'],
			(int) $queue['checks_per_day'],
			max( 1, (int) $queue['est_days'] ),
			$immediate
		);

		if ( $total <= 0 ) {
			return array(
				'label' => __( 'All up to date', 'tso-link-inspector' ),
				'title' => __( 'All links were checked recently.', 'tso-link-inspector' ),
				'warn'  => false,
			);
		}

		if ( $stale_count > 0 && $immediate > 0 ) {
			if ( $never_checked > 0 ) {
				$label = sprintf(
					/* translators: 1: unchecked count (matches Unchecked card), 2: scheduled recheck count */
					__( '%1$d unchecked · %2$d scheduled recheck', 'tso-link-inspector' ),
					$never_checked,
					$stale_count
				);
			} else {
				$label = sprintf(
					/* translators: 1: pending count (includes manual locks), 2: scheduled recheck count */
					__( '%1$d pending · %2$d scheduled recheck', 'tso-link-inspector' ),
					$immediate,
					$stale_count
				);
			}
		} elseif ( $stale_count > 0 ) {
			$label = sprintf(
				/* translators: %d: count */
				__( '%d scheduled recheck', 'tso-link-inspector' ),
				$stale_count
			);
		} elseif ( $never_checked > 0 ) {
			$label = sprintf(
				/* translators: %d: count matching the Unchecked dashboard card */
				__( '%d unchecked', 'tso-link-inspector' ),
				$never_checked
			);
		} else {
			$label = sprintf(
				/* translators: %d: rows awaiting HTTP including manual locks */
				__( '%d pending', 'tso-link-inspector' ),
				$immediate
			);
		}

		return array(
			'label' => $label,
			'title' => $title,
			'warn'  => true,
		);
	}
}
