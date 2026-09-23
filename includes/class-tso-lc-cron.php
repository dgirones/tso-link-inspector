<?php
/**
 * WP-Cron handler.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Cron
 */
class TSOLIIN_Cron {

	const HOOK_SCAN         = 'tsoliin_cron_scan';
	const HOOK_CHECK        = 'tsoliin_cron_check';
	const HOOK_BG_STEP      = 'tsoliin_bg_check_step';
	const HOOK_BG_SCAN_STEP = 'tsoliin_bg_scan_step';
	const BG_BATCH            = 25;
	const BG_POLL_BATCH       = 25;
	const BG_TICK_TIME_BUDGET = 12;
	const BG_CRON_TIME_BUDGET = 40;
	const BG_STEP_LOCK_TTL    = 50;
	const BG_RECOVERY_DELAY   = 90;
	/** Minimum pause (seconds) after any scan/check step; the pause is at least as long as the step worked (≤ 50% duty). */
	const BG_MIN_REST = 5;
	/** A unit (post, source batch, link) whose worker died this many times is skipped instead of retried forever. */
	const INFLIGHT_MAX_TRIES = 2;
	/** Empty-batch retries before a check run is finished instead of rescheduled forever. */
	const EMPTY_BATCH_MAX_RETRIES = 10;
	/** Cap on per-run resync keys kept in memory/options. */
	const RESYNC_KEYS_MAX = 5000;
	/** Option: timestamp until which the scan rests. */
	const OPT_SCAN_REST_UNTIL = 'tsoliin_bg_scan_rest_until';
	/** Option: timestamp until which the check rests. */
	const OPT_CHECK_REST_UNTIL = 'tsoliin_bg_check_rest_until';
	/** Option prefix: in-progress unit per kind. */
	const OPT_INFLIGHT_PREFIX = 'tsoliin_bg_inflight_';
	/** Option: log of skipped units. */
	const OPT_SKIPPED = 'tsoliin_bg_skipped';
	/** Option: sources already resynced in the current check run. */
	const OPT_RESYNCED = 'tsoliin_bg_check_resynced';
	/** Option: position inside the current post page. */
	const OPT_SCAN_PAGE_POS = 'tsoliin_bg_scan_page_pos';

	const OPT_IMMEDIATE_QUEUE     = 'tsoliin_immediate_broken_queue';
	const OPT_EMPTY_BATCH_RETRIES = 'tsoliin_bg_check_empty_retries';
	const OPT_USER_STOPPED_CHECK  = 'tsoliin_bg_check_user_stopped';

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_Scanner */
	private $scanner;

	/** @var TSOLIIN_HTTP */
	private $http;

	public function __construct( TSOLIIN_DB $db, TSOLIIN_Scanner $scanner, TSOLIIN_HTTP $http ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->http    = $http;

		add_action( self::HOOK_SCAN,         array( $this, 'run_scan' ) );
		add_action( self::HOOK_CHECK,        array( $this, 'run_check_batch' ) );
		add_action( self::HOOK_BG_STEP,      array( $this, 'run_bg_step' ) );
		add_action( self::HOOK_BG_SCAN_STEP, array( $this, 'run_bg_scan_step' ) );
		add_action( 'admin_init',             array( $this, 'maybe_run_overdue_bg_workers' ), 30 );
		add_filter( 'heartbeat_received',      array( $this, 'heartbeat_drive_bg_jobs' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Schedule management
	// -------------------------------------------------------------------------

	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_SCAN ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK_SCAN );
		}
		if ( ! wp_next_scheduled( self::HOOK_CHECK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK_CHECK );
		}
	}

	public function unschedule() {
		foreach ( array( self::HOOK_SCAN, self::HOOK_CHECK, self::HOOK_BG_STEP, self::HOOK_BG_SCAN_STEP ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Periodic handlers
	// -------------------------------------------------------------------------

	/** Daily: resume or start the persistent background scan (never restart from page 1). */
	public function run_scan() {
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			return;
		}
		$this->start_bg_scan( true );
	}

	/**
	 * @param string $key     Settings key.
	 * @param bool   $default Default when unset.
	 * @return bool
	 */
	private function is_scan_setting_enabled( $key, $default = false ) {
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! is_array( $s ) || ! array_key_exists( $key, $s ) ) {
			return (bool) $default;
		}
		return ! empty( $s[ $key ] );
	}

	/** Hourly: check a batch of stale links. */
	public function run_check_batch() {
		if ( $this->is_bg_scan_blocking_check() ) {
			return;
		}
		// Keep an abandoned Scan→Check / Check now run going until the queue is empty.
		$this->maybe_resume_incomplete_bg_check();
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			return;
		}
		if ( get_option( self::OPT_USER_STOPPED_CHECK ) ) {
			$stopped_post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
			if ( $this->db->get_pending_check_count( $stopped_post_id ) > 0 ) {
				return;
			}
			delete_option( self::OPT_USER_STOPPED_CHECK );
		}

		$schedule   = TSOLIIN_Schedule::get_settings();
		$batch      = $schedule['cron_check_batch'];
		$links      = $this->db->get_links_for_cron_check( $batch, $schedule['recheck_days'], $schedule['broken_recheck_days'] );

		if ( empty( $links ) ) {
			// No links needed checking. Do NOT update last_check_batch timestamp
			// so the user can distinguish "cron ran but nothing to check" from "cron checked links".
			$this->maybe_send_digest_broken_email();
			return;
		}

		$checked        = 0;
		$newly_detected = array();
		$started_at = microtime( true );
		foreach ( $links as $link ) {
			if ( ( microtime( true ) - $started_at ) >= 45 ) {
				break;
			}
			try {
				$check = $this->check_link_row( $link );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( null === $check ) {
				continue;
			}
			$item = $this->build_new_hard_broken_item( $check['link'], $check['result'], $check['prev_failures'] );
			if ( ! empty( $item ) ) {
				$newly_detected[] = $item;
			}
			$checked++;
		}

		// Only update timestamp when links were actually checked.
		if ( $checked > 0 ) {
			update_option( 'tsoliin_last_check_batch', current_time( 'mysql', true ), false );
		}
		update_option( 'tsoliin_last_check_count', $checked, false );
		$this->send_immediate_broken_summary_email( $newly_detected );
		$this->maybe_send_digest_broken_email();
	}

	// -------------------------------------------------------------------------
	// Background scan (server-side, browser-independent)
	// -------------------------------------------------------------------------

	/**
	 * Start or resume a full content scan.
	 *
	 * @param bool $resume When true, continue from the stored page cursor when the last run did not finish.
	 * @param bool $spawn  When false, do not spawn WP-Cron (admin AJAX loop drives the batches).
	 */
	/**
	 * Take the start lock, stealing a stale one so an explicit Scan/Check is not blocked.
	 *
	 * @return bool
	 */
	private function acquire_lifecycle_lock() {
		if ( $this->db->acquire_transient_lock( 'tsoliin_bg_lifecycle_start_lock', 15 ) ) {
			return true;
		}
		$this->db->release_transient_lock( 'tsoliin_bg_lifecycle_start_lock' );
		return $this->db->acquire_transient_lock( 'tsoliin_bg_lifecycle_start_lock', 15 );
	}

	public function start_bg_scan( $resume = true, $spawn = true ) {
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			$this->stop_bg_check();
		}
		if ( ! $this->acquire_lifecycle_lock() ) {
			return false;
		}
		try {
			if ( get_option( 'tsoliin_bg_check_running' ) ) {
				$this->stop_bg_check();
			}
			$resume  = (bool) $resume;
			$spawn   = (bool) $spawn;
			$running = (int) get_option( 'tsoliin_bg_scan_running', 0 );

			if ( $running && $resume ) {
				if ( '' === (string) get_option( 'tsoliin_bg_scan_token', '' ) ) {
					update_option( 'tsoliin_bg_scan_token', wp_generate_uuid4(), false );
				}
				$this->schedule_bg_scan_step_if_needed( $spawn ? 0 : self::BG_RECOVERY_DELAY );
				if ( $spawn ) {
					spawn_cron();
				}
				return true;
			}

			if ( $running && ! $resume ) {
				$this->stop_bg_scan();
			}

			$this->db->release_transient_lock( 'tsoliin_bg_scan_step_lock' );

			$total    = $this->scanner->get_total_posts();
			$complete = (int) get_option( 'tsoliin_bg_scan_complete', 1 );
			$page     = max( 1, (int) get_option( 'tsoliin_bg_scan_page', 1 ) );
			$scanned  = (int) get_option( 'tsoliin_bg_scan_scanned', 0 );

			if ( $resume && ! $complete ) {
				// Keep stored post and extended-source cursors.
			} else {
				$this->reset_bg_scan_cursors();
				$page    = 1;
				$scanned = 0;
				update_option( 'tsoliin_bg_scan_phase', 'posts', false );
			}

			delete_option( 'tsoliin_bg_scan_error' );
			delete_option( self::OPT_SCAN_REST_UNTIL );
			update_option( 'tsoliin_bg_scan_running', 1, false );
			update_option( 'tsoliin_bg_scan_token', wp_generate_uuid4(), false );
			update_option( 'tsoliin_bg_scan_page', $page, false );
			update_option( 'tsoliin_bg_scan_total', (int) $total, false );
			update_option( 'tsoliin_bg_scan_scanned', (int) $scanned, false );
			update_option( 'tsoliin_bg_scan_complete', 0, false );
			update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );

			wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
			wp_schedule_single_event( time() + ( $spawn ? 0 : self::BG_RECOVERY_DELAY ), self::HOOK_BG_SCAN_STEP );
			if ( $spawn ) {
				spawn_cron();
			}
			return true;
		} finally {
			$this->db->release_transient_lock( 'tsoliin_bg_lifecycle_start_lock' );
		}
	}

	/**
	 * Stop a running background scan (keeps page/scanned for resume).
	 */
	public function stop_bg_scan() {
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 0, false );
		update_option( 'tsoliin_bg_scan_token', wp_generate_uuid4(), false );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		$this->db->release_transient_lock( 'tsoliin_bg_scan_step_lock' );
	}

	/**
	 * Abandon a paused scan without deleting links already found.
	 *
	 * @return void
	 */
	public function discard_bg_scan() {
		$this->stop_bg_scan();
		update_option( 'tsoliin_bg_scan_complete', 1, false );
		delete_option( 'tsoliin_bg_scan_error' );
		delete_option( 'tsoliin_bg_scan_page' );
		delete_option( 'tsoliin_bg_scan_scanned' );
		delete_option( 'tsoliin_bg_scan_started' );
		delete_option( 'tsoliin_bg_scan_phase' );
		delete_option( 'tsoliin_bg_scan_token' );
	}

	/**
	 * Whether a background check was paused and can be resumed or discarded.
	 *
	 * @return bool
	 */
	public function is_bg_check_paused() {
		if ( get_option( 'tsoliin_bg_check_running' ) ) {
			return false;
		}
		if ( get_option( self::OPT_USER_STOPPED_CHECK ) ) {
			return true;
		}
		$total = (int) get_option( 'tsoliin_bg_check_total', 0 );
		if ( $total <= 0 ) {
			return false;
		}
		return ! (bool) get_option( 'tsoliin_bg_check_complete', 0 );
	}

	/**
	 * Seconds of work allowed in one worker invocation.
	 *
	 * @param bool $spawn True for WP-Cron / spawn_cron; false for admin AJAX ticks.
	 * @return int
	 */
	private function get_worker_time_budget( $spawn ) {
		return $spawn ? self::BG_CRON_TIME_BUDGET : self::BG_TICK_TIME_BUDGET;
	}

	/**
	 * Give a worker at least $seconds to run without ever lowering the host limit.
	 *
	 * The old code forced max_execution_time to 60 s, overriding hosts that set a
	 * higher (or unlimited) value.
	 *
	 * @param float $seconds Minimum seconds needed.
	 */
	private function extend_time_limit( $seconds ) {
		if ( ! function_exists( 'set_time_limit' ) ) {
			return;
		}
		$current = (int) ini_get( 'max_execution_time' );
		if ( 0 === $current ) {
			return; // Already unlimited.
		}
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- Scan/check worker may need more than the default; never lowers the host value.
		@set_time_limit( max( $current, (int) ceil( $seconds ) ) );
	}

	/**
	 * Seconds left before the next scan/check step may run.
	 *
	 * @param string $option OPT_SCAN_REST_UNTIL or OPT_CHECK_REST_UNTIL.
	 * @return int
	 */
	private function rest_remaining( $option ) {
		return max( 0, (int) get_option( $option, 0 ) - time() );
	}

	/**
	 * Seconds until the background scan may run another step.
	 *
	 * @return int
	 */
	public function get_scan_rest_remaining() {
		return $this->rest_remaining( self::OPT_SCAN_REST_UNTIL );
	}

	/**
	 * Seconds until the background check may run another step.
	 *
	 * @return int
	 */
	public function get_check_rest_remaining() {
		return $this->rest_remaining( self::OPT_CHECK_REST_UNTIL );
	}

	/**
	 * Seconds to pause after a step: at least as long as the step worked.
	 *
	 * Caps every driver (WP-Cron, plugin screen, keep-alive, Heartbeat) at roughly
	 * half of one PHP worker, however many tabs are open.
	 *
	 * @param float $step_started microtime( true ) when the step took the lock.
	 * @return int
	 */
	private function rest_seconds( $step_started ) {
		return max( self::BG_MIN_REST, (int) ceil( microtime( true ) - (float) $step_started ) );
	}

	/**
	 * Store the pause after a step.
	 *
	 * @param string $option       Rest option key.
	 * @param float  $step_started microtime( true ) when the step took the lock.
	 */
	private function start_rest( $option, $step_started ) {
		update_option( $option, time() + $this->rest_seconds( $step_started ), false );
	}

	/**
	 * Mark a unit of work as in progress. A marker left behind means the previous
	 * worker died on this exact unit (PHP timeout / out of memory, which no
	 * try/catch can see). After INFLIGHT_MAX_TRIES deaths the unit is skipped, so
	 * one bad post, source or URL can never block a scan or check forever.
	 *
	 * @param string     $kind scan_post|scan_phase|check_link.
	 * @param string|int $unit Unit identifier.
	 * @return bool False when the unit must be skipped.
	 */
	private function begin_inflight( $kind, $unit ) {
		$option = self::OPT_INFLIGHT_PREFIX . sanitize_key( $kind );
		$unit   = (string) $unit;
		$prev   = get_option( $option, array() );
		$tries  = ( is_array( $prev ) && isset( $prev['unit'] ) && (string) $prev['unit'] === $unit )
			? absint( $prev['tries'] ?? 0 ) + 1
			: 1;
		if ( $tries > self::INFLIGHT_MAX_TRIES ) {
			delete_option( $option );
			$this->record_skipped( $kind, $unit, 'worker died ' . self::INFLIGHT_MAX_TRIES . ' times' );
			return false;
		}
		update_option(
			$option,
			array(
				'unit'  => $unit,
				'tries' => $tries,
			),
			false
		);
		return true;
	}

	/**
	 * Clear the in-progress marker after a unit finished.
	 *
	 * @param string $kind scan_post|scan_phase|check_link.
	 */
	private function end_inflight( $kind ) {
		delete_option( self::OPT_INFLIGHT_PREFIX . sanitize_key( $kind ) );
	}

	/** Drop all in-progress markers (fresh scan/check). */
	private function clear_inflight() {
		foreach ( array( 'scan_post', 'scan_phase', 'check_link' ) as $kind ) {
			$this->end_inflight( $kind );
		}
	}

	/**
	 * Keep a short log of skipped units for support/diagnostics (last 50).
	 *
	 * @param string $kind   Unit kind.
	 * @param string $unit   Unit identifier.
	 * @param string $reason Why it was skipped.
	 */
	private function record_skipped( $kind, $unit, $reason ) {
		$log   = get_option( self::OPT_SKIPPED, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'kind'   => sanitize_key( (string) $kind ),
			'unit'   => sanitize_text_field( (string) $unit ),
			'reason' => sanitize_text_field( (string) $reason ),
			'time'   => current_time( 'mysql', true ),
		);
		update_option( self::OPT_SKIPPED, array_slice( $log, -50 ), false );
	}

	/**
	 * Keep PHP working after the browser leaves the plugin screen.
	 */
	private function ignore_worker_abort() {
		if ( function_exists( 'ignore_user_abort' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Finish the in-flight batch after the admin leaves the screen.
			ignore_user_abort( true );
		}
	}

	/**
	 * Execute scan batches until the time budget is exhausted.
	 *
	 * @param int|null $max_batches      Cap on post pages this tick. Null uses the time budget only.
	 * @param bool     $spawn            When false, keep a recovery cron only (AJAX loop is driving).
	 * @param int|null $budget_override  Seconds of work; null uses the spawn/tick default.
	 * @return string idle|busy|ok
	 */
	public function run_bg_scan_step( $max_batches = null, $spawn = true, $budget_override = null ) {
		if ( ! get_option( 'tsoliin_bg_scan_running' ) ) {
			return 'idle';
		}
		$resting = $this->rest_remaining( self::OPT_SCAN_REST_UNTIL );
		if ( $resting > 0 ) {
			$this->schedule_bg_scan_step_if_needed( $resting );
			return 'busy';
		}
		if ( ! $this->db->acquire_transient_lock( 'tsoliin_bg_scan_step_lock', self::BG_STEP_LOCK_TTL ) ) {
			$this->schedule_bg_scan_step_if_needed( 2 );
			return 'busy';
		}
		$step_started = microtime( true );

		$this->ignore_worker_abort();
		$run_token = (string) get_option( 'tsoliin_bg_scan_token', '' );
		if ( 'done' === (string) get_option( 'tsoliin_bg_scan_phase', 'posts' ) && $this->is_bg_scan_run_active( $run_token ) ) {
			$this->finalize_bg_scan_completion( $run_token );
			$this->db->release_transient_lock( 'tsoliin_bg_scan_step_lock' );
			return 'ok';
		}
		unset( $max_batches ); // Kept for backward compatibility; the time budget bounds each step.
		$spawn  = (bool) $spawn;
		$budget = null !== $budget_override ? max( 1, (float) $budget_override ) : $this->get_worker_time_budget( $spawn );
		$this->schedule_bg_scan_recovery_event();

		try {
			$this->extend_time_limit( $budget + 20 );

			$start = microtime( true );
			$page  = max( 1, (int) get_option( 'tsoliin_bg_scan_page', 1 ) );
			$total = max( (int) get_option( 'tsoliin_bg_scan_total', 0 ), (int) $this->scanner->get_total_posts() );
			$phase = (string) get_option( 'tsoliin_bg_scan_phase', 'posts' );
			update_option( 'tsoliin_bg_scan_total', (int) $total, false );

			if ( 'posts' === $phase ) {
				while ( ( microtime( true ) - $start ) < $budget ) {
					if ( ! $this->is_bg_scan_run_active( $run_token ) ) {
						return 'idle';
					}
					$scan_done = $this->scan_posts_page_guarded( $page, $total, $start, $budget, $run_token );
					if ( ! $this->is_bg_scan_run_active( $run_token ) ) {
						return 'idle';
					}
					update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );
					if ( null === $scan_done ) {
						break; // Budget used up mid-page; position saved.
					}
					++$page;
					update_option( 'tsoliin_bg_scan_page', $page, false );

					$scanned = min( ( $page - 1 ) * TSOLIIN_BATCH_SIZE, $total );
					update_option( 'tsoliin_bg_scan_scanned', (int) $scanned, false );
					update_option( 'tsoliin_total_posts_scanned', (int) $scanned, false );
					update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );

					if ( $scan_done ) {
						update_option( 'tsoliin_bg_scan_phase', $this->get_first_bg_scan_extended_phase(), false );
						break;
					}
				}
			}

			if ( $this->is_bg_scan_run_active( $run_token )
				&& 'posts' !== (string) get_option( 'tsoliin_bg_scan_phase', 'posts' )
				&& ( microtime( true ) - $start ) < $budget ) {
				if ( $this->process_bg_scan_extended_phases( $start, $run_token, $budget ) ) {
					$this->finalize_bg_scan_completion( $run_token );
					return 'ok';
				}
			}

			if ( $this->is_bg_scan_run_active( $run_token ) ) {
				if ( $spawn ) {
					$this->reschedule_bg_scan_step( $this->rest_seconds( $step_started ) );
				} else {
					$this->schedule_bg_scan_recovery_event();
				}
			}
			return 'ok';
		} catch ( \Throwable $e ) {
			if ( $this->is_bg_scan_run_active( $run_token ) ) {
				$this->record_bg_scan_error( $e->getMessage() );
			}
			return 'idle';
		} finally {
			$this->start_rest( self::OPT_SCAN_REST_UNTIL, $step_started );
			$this->db->release_transient_lock( 'tsoliin_bg_scan_step_lock' );
		}
	}

	/**
	 * Scan one page of posts, post by post, with a saved position inside the page.
	 *
	 * Every call scans at least one post (guaranteed progress). A post whose worker
	 * died twice, or that throws, is skipped and logged instead of blocking the scan.
	 *
	 * @param int    $page      1-based page.
	 * @param int    $total     Total posts in scope.
	 * @param float  $start     Step start (microtime).
	 * @param float  $budget    Step budget (seconds).
	 * @param string $run_token Current scan generation.
	 * @return bool|null True = last page done, false = page done (more pages), null = stopped mid-page.
	 */
	private function scan_posts_page_guarded( $page, $total, $start, $budget, $run_token ) {
		$per  = TSOLIIN_BATCH_SIZE;
		$ids  = $this->scanner->get_post_ids( $page, $per );
		$done = empty( $ids ) || ( $page * $per >= $total ) || ( count( $ids ) < $per );
		$pos  = absint( get_option( self::OPT_SCAN_PAGE_POS, 0 ) );
		$n    = count( $ids );
		for ( $i = $pos; $i < $n; $i++ ) {
			if ( $i > $pos
				&& ( ( microtime( true ) - $start ) >= $budget || ! $this->is_bg_scan_run_active( $run_token ) ) ) {
				update_option( self::OPT_SCAN_PAGE_POS, $i, false );
				return null;
			}
			// Save the position first: if this post kills the worker, the next step
			// must start on this same post so the retry counter can reach its limit.
			update_option( self::OPT_SCAN_PAGE_POS, $i, false );
			$post_id = absint( $ids[ $i ] );
			if ( ! $this->begin_inflight( 'scan_post', $post_id ) ) {
				continue;
			}
			try {
				$this->scanner->scan_post( $post_id );
			} catch ( \Throwable $e ) {
				$this->record_skipped( 'scan_post', (string) $post_id, $e->getMessage() );
			}
			$this->end_inflight( 'scan_post' );
		}
		delete_option( self::OPT_SCAN_PAGE_POS );
		return $done;
	}

	/**
	 * Run enabled non-post sources until the time budget is exhausted.
	 *
	 * @param float  $started_at Worker start time.
	 * @param string $run_token  Current scan generation.
	 * @param float  $budget     Seconds allowed for this invocation.
	 * @return bool True when every enabled source completed a full cycle.
	 */
	private function process_bg_scan_extended_phases( $started_at, $run_token, $budget = 0 ) {
		$budget = $budget > 0 ? (float) $budget : (float) self::BG_CRON_TIME_BUDGET;
		$phases = $this->get_bg_scan_extended_phases();
		$phase  = (string) get_option( 'tsoliin_bg_scan_phase', $this->get_first_bg_scan_extended_phase() );
		$index  = array_search( $phase, array_keys( $phases ), true );
		$index  = false === $index ? 0 : (int) $index;
		$names  = array_keys( $phases );
		$cursor_options = array(
			'comments' => 'tsoliin_comment_scan_after_id',
			'menus'    => 'tsoliin_menu_scan_after_id',
			'terms'    => 'tsoliin_term_scan_after_id',
			'fse'      => 'tsoliin_fse_scan_after_id',
			'widgets'  => 'tsoliin_widget_scan_after_index',
		);

		while ( $index < count( $names ) && ( microtime( true ) - $started_at ) < $budget ) {
			if ( ! $this->is_bg_scan_run_active( $run_token ) ) {
				return false;
			}
			$name       = $names[ $index ];
			$method     = $phases[ $name ];
			$cursor_opt = isset( $cursor_options[ $name ] ) ? $cursor_options[ $name ] : '';
			$before     = '' !== $cursor_opt ? (string) get_option( $cursor_opt, 0 ) : '';
			$unit       = $name . '@' . $before;
			if ( ! $this->begin_inflight( 'scan_phase', $unit ) ) {
				// Worker died twice on this batch: skip the rest of this source.
				$result = 0;
			} else {
				try {
					if ( 'registered' === $name ) {
						if ( class_exists( 'TSOLIIN_Sources' ) ) {
							TSOLIIN_Sources::scan_registered_batch( $this->scanner, TSOLIIN_BATCH_SIZE * 2 );
						}
						$result = 0;
					} else {
						$result = call_user_func( array( $this->scanner, $method[0] ), $method[1] );
						if ( 'acf' === $name && TSOLIIN_Scanner::SCAN_LOCK_BUSY !== $result ) {
							$result = 0;
						}
						if ( 'widgets' === $name
							&& TSOLIIN_Scanner::SCAN_LOCK_BUSY !== $result
							&& 0 === (int) get_option( 'tsoliin_widget_scan_after_index', 0 ) ) {
							$result = 0;
						}
					}
				} catch ( \Throwable $e ) {
					$this->record_skipped( 'scan_phase', $unit, $e->getMessage() );
					$result = 0;
				}
				$this->end_inflight( 'scan_phase' );
			}
			update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );
			if ( TSOLIIN_Scanner::SCAN_LOCK_BUSY === $result ) {
				return false;
			}
			// A batch that reports work but did not move its cursor would repeat forever.
			if ( 0 !== $result && '' !== $cursor_opt && (string) get_option( $cursor_opt, 0 ) === $before ) {
				$this->record_skipped( 'scan_phase', $unit, 'cursor did not advance' );
				$result = 0;
			}
			if ( 0 !== $result ) {
				continue;
			}
			if ( '' !== $cursor_opt ) {
				update_option( $cursor_opt, 0, false );
			}
			++$index;
			update_option( 'tsoliin_bg_scan_phase', isset( $names[ $index ] ) ? $names[ $index ] : 'done', false );
		}

		return $index >= count( $names );
	}

	/**
	 * Enabled extended-source phases in execution order.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	private function get_bg_scan_extended_phases() {
		$phases = array();
		if ( $this->scanner->is_scan_comments_enabled() ) {
			$phases['comments'] = array( 'scan_comments_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_menus', true ) ) {
			$phases['menus'] = array( 'scan_menus_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_terms', true ) ) {
			$phases['terms'] = array( 'scan_terms_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_fse', true ) ) {
			$phases['fse'] = array( 'scan_fse_batch', max( 5, (int) floor( self::BG_BATCH / 2 ) ) );
		}
		if ( $this->scanner->is_scan_widgets_enabled() ) {
			$phases['widgets'] = array( 'scan_widgets_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_meta', false ) ) {
			$phases['acf'] = array( 'scan_acf_options', 1 );
		}
		$phases['registered'] = array( '', 0 );
		return $phases;
	}

	/** @return string */
	private function get_first_bg_scan_extended_phase() {
		$names = array_keys( $this->get_bg_scan_extended_phases() );
		return isset( $names[0] ) ? $names[0] : 'done';
	}

	/**
	 * Mark a background scan complete and persist summary options.
	 */
	private function finalize_bg_scan_completion( $run_token = '' ) {
		if ( '' !== $run_token && ! $this->is_bg_scan_run_active( $run_token ) ) {
			return;
		}
		$total = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
		}
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 1, false );
		update_option( 'tsoliin_bg_scan_phase', 'done', false );
		update_option( 'tsoliin_bg_scan_scanned', (int) $total, false );
		update_option( 'tsoliin_total_posts_scanned', (int) $total, false );
		update_option( 'tsoliin_last_full_scan', current_time( 'mysql', true ), false );
		delete_option( 'tsoliin_bg_scan_error' );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		TSOLIIN_DB::clear_stats_cache();
	}

	/**
	 * Store a scan failure message and stop the background run.
	 *
	 * @param string $message Error detail.
	 */
	private function record_bg_scan_error( $message ) {
		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			$message = __( 'Scan failed on the server. Use Continue scan to retry from the last saved position.', 'tso-link-inspector' );
		}
		update_option( 'tsoliin_bg_scan_error', $message, false );
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 0, false );
		update_option( 'tsoliin_bg_scan_token', wp_generate_uuid4(), false );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
	}

	/**
	 * Reset extended-source cursors before a fresh scan.
	 */
	public function reset_bg_scan_cursors() {
		delete_option( 'tsoliin_comment_scan_after_id' );
		delete_option( 'tsoliin_menu_scan_after_id' );
		delete_option( 'tsoliin_widget_scan_after_index' );
		delete_option( 'tsoliin_term_scan_after_id' );
		delete_option( 'tsoliin_fse_scan_after_id' );
		delete_option( self::OPT_SCAN_PAGE_POS );
		$this->end_inflight( 'scan_post' );
		$this->end_inflight( 'scan_phase' );
	}

	/**
	 * Whether a stopped scan can be resumed.
	 *
	 * @return bool
	 */
	public function is_bg_scan_resumable() {
		if ( (bool) get_option( 'tsoliin_bg_scan_running', 0 ) ) {
			return false;
		}
		if ( (int) get_option( 'tsoliin_bg_scan_complete', 1 ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether an in-progress or paused scan must finish before HTTP checks run.
	 *
	 * @return bool
	 */
	public function is_bg_scan_blocking_check() {
		if ( get_option( 'tsoliin_bg_scan_running' ) ) {
			return true;
		}
		return $this->is_bg_scan_resumable();
	}

	/**
	 * Schedule the next background scan cron step when none is pending.
	 *
	 * @return void
	 */
	private function schedule_bg_scan_step_if_needed( $delay = 0 ) {
		if ( ! wp_next_scheduled( self::HOOK_BG_SCAN_STEP ) ) {
			wp_schedule_single_event( time() + max( 0, absint( $delay ) ), self::HOOK_BG_SCAN_STEP );
		}
	}

	/** Schedule a survivor event before heavy work starts. */
	private function schedule_bg_scan_recovery_event() {
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		wp_schedule_single_event( time() + self::BG_RECOVERY_DELAY, self::HOOK_BG_SCAN_STEP );
	}

	/** @param int $delay Seconds before the next worker. */
	private function reschedule_bg_scan_step( $delay ) {
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		wp_schedule_single_event( time() + max( 0, absint( $delay ) ), self::HOOK_BG_SCAN_STEP );
		spawn_cron();
	}

	/** @param string $run_token Scan generation token. */
	private function is_bg_scan_run_active( $run_token ) {
		return get_option( 'tsoliin_bg_scan_running' )
			&& '' !== $run_token
			&& hash_equals( (string) get_option( 'tsoliin_bg_scan_token', '' ), (string) $run_token );
	}

	/**
	 * Schedule the next background check cron step when none is pending.
	 *
	 * @return void
	 */
	private function schedule_bg_check_step_if_needed( $delay = 0 ) {
		if ( ! wp_next_scheduled( self::HOOK_BG_STEP ) ) {
			wp_schedule_single_event( time() + max( 0, absint( $delay ) ), self::HOOK_BG_STEP );
		}
	}

	/** Schedule a survivor event before HTTP work starts. */
	private function schedule_bg_check_recovery_event() {
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
		wp_schedule_single_event( time() + self::BG_RECOVERY_DELAY, self::HOOK_BG_STEP );
	}

	/** @param int $delay Seconds before the next worker. */
	private function reschedule_bg_check_step( $delay ) {
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
		wp_schedule_single_event( time() + max( 0, absint( $delay ) ), self::HOOK_BG_STEP );
		spawn_cron();
	}

	/** @param string $run_token Check generation token. */
	private function is_bg_check_run_active( $run_token ) {
		return get_option( 'tsoliin_bg_check_running' )
			&& '' !== $run_token
			&& hash_equals( (string) get_option( 'tsoliin_bg_check_token', '' ), (string) $run_token );
	}

	/**
	 * Current background scan progress.
	 *
	 * @return array{ running: bool, scanned: int, total: int, pct: int, complete: bool, resumable: bool, error: string, done: bool }
	 */
	public function get_bg_scan_progress() {
		$running  = (bool) get_option( 'tsoliin_bg_scan_running', 0 );
		$total    = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		$scanned  = (int) get_option( 'tsoliin_bg_scan_scanned', 0 );
		$complete = (bool) get_option( 'tsoliin_bg_scan_complete', 1 );
		$started  = (string) get_option( 'tsoliin_bg_scan_started', '' );
		$error    = (string) get_option( 'tsoliin_bg_scan_error', '' );
		$phase    = (string) get_option( 'tsoliin_bg_scan_phase', 'posts' );

		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
		}

		if ( $running && '' !== $started && ( time() - (int) strtotime( $started ) ) > 20 ) {
			// Extra sources after 205/205 still need a worker; WP-Cron loopback often never fires.
			$this->schedule_bg_scan_step_if_needed( 0 );
			spawn_cron();
		}

		if ( $scanned > $total ) {
			$scanned = $total;
		}
		$pct = ( $total > 0 ) ? min( 100, (int) round( ( $scanned / $total ) * 100 ) ) : 0;
		if ( ! $complete && $pct >= 100 ) {
			$pct = 99;
		}

		$resumable = $this->is_bg_scan_resumable();
		// complete=1 after finalize; also treat total=0 as done so empty sites do not poll forever.
		$done = $complete && ! $running && ( ( $total > 0 && $scanned >= $total ) || 0 === $total );

		return array(
			'running'   => $running,
			'scanned'   => $scanned,
			'total'     => $total,
			'pct'       => $pct,
			'complete'  => $complete,
			'resumable' => $resumable,
			'error'     => $error,
			'phase'     => $phase,
			'done'      => $done,
		);
	}

	// -------------------------------------------------------------------------
	// Background check (server-side, browser-independent)
	// -------------------------------------------------------------------------

	/**
	 * Start background check.
	 *
	 * If $resume is true and there are unchecked links pending, resume from that point.
	 * Otherwise start a fresh full check.
	 *
	 * @param bool $resume  Whether to resume partial progress when possible.
	 * @param int  $post_id When > 0, only check links from this post.
	 * @param bool $spawn   When false, do not spawn WP-Cron (admin AJAX loop drives the batches).
	 */
	public function start_bg_check( $resume = true, $post_id = 0, $spawn = true ) {
		if ( ! $this->acquire_lifecycle_lock() ) {
			return false;
		}
		try {
			if ( $this->is_bg_scan_blocking_check() ) {
				return false;
			}
			$resume  = (bool) $resume;
			$spawn   = (bool) $spawn;
			$post_id = absint( $post_id );
			$running = (int) get_option( 'tsoliin_bg_check_running', 0 );

			if ( $running && $resume ) {
				$current_post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
				if ( $current_post_id === $post_id ) {
					if ( '' === (string) get_option( 'tsoliin_bg_check_token', '' ) ) {
						update_option( 'tsoliin_bg_check_token', wp_generate_uuid4(), false );
					}
					$this->schedule_bg_check_step_if_needed( $spawn ? 0 : self::BG_RECOVERY_DELAY );
					if ( $spawn ) {
						spawn_cron();
					}
					return true;
				}
				$this->stop_bg_check();
				$running = 0;
			}

			if ( $running && ! $resume ) {
				$this->stop_bg_check();
			}

			if ( $post_id > 0 ) {
				$total = (int) $this->db->get_stats_for_post( $post_id )['total'];
			} else {
				$total = (int) $this->db->get_stats()['total'];
			}

			$pending = $this->db->get_pending_check_count( $post_id );
			if ( $resume && $pending <= 0 ) {
				update_option( 'tsoliin_bg_check_post_id', $post_id, false );
				update_option( 'tsoliin_bg_check_total', (int) $total, false );
				update_option( 'tsoliin_bg_check_checked', (int) $total, false );
				update_option( 'tsoliin_bg_check_running', 1, false );
				update_option( 'tsoliin_bg_check_token', wp_generate_uuid4(), false );
				$this->finalize_bg_check_completion();
				return true;
			}
			if ( $resume ) {
				$checked = max( 0, $total - $pending );
			} else {
				$this->db->reset_for_recheck( $post_id );
				$checked = 0;
				if ( $post_id > 0 ) {
					$total = (int) $this->db->get_stats_for_post( $post_id )['total'];
				} else {
					$total = (int) $this->db->get_stats()['total'];
				}
			}

			update_option( 'tsoliin_bg_check_running', 1, false );
			update_option( 'tsoliin_bg_check_complete', 0, false );
			update_option( 'tsoliin_bg_check_token', wp_generate_uuid4(), false );
			update_option( 'tsoliin_bg_check_post_id', $post_id, false );
			update_option( 'tsoliin_bg_check_total', (int) $total, false );
			update_option( 'tsoliin_bg_check_checked', (int) $checked, false );
			update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
			delete_option( self::OPT_USER_STOPPED_CHECK );
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
			delete_option( self::OPT_CHECK_REST_UNTIL );
			delete_option( self::OPT_RESYNCED );
			$this->end_inflight( 'check_link' );
			$this->flush_immediate_broken_queue();

			$ts = wp_next_scheduled( self::HOOK_BG_STEP );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::HOOK_BG_STEP );
			}
			wp_schedule_single_event( time() + ( $spawn ? 0 : self::BG_RECOVERY_DELAY ), self::HOOK_BG_STEP );
			if ( $spawn ) {
				spawn_cron();
			}
			return true;
		} finally {
			$this->db->release_transient_lock( 'tsoliin_bg_lifecycle_start_lock' );
		}
	}

	/**
	 * Stop a running background check.
	 *
	 * Keeps post_id / total / checked so the resume UI can show accurate
	 * pending counts and progress after a manual stop.
	 */
	public function stop_bg_check() {
		update_option( 'tsoliin_bg_check_running', 0, false );
		update_option( 'tsoliin_bg_check_complete', 0, false );
		update_option( 'tsoliin_bg_check_token', wp_generate_uuid4(), false );
		update_option( self::OPT_USER_STOPPED_CHECK, 1, false );
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
		$this->db->release_transient_lock( 'tsoliin_bg_check_step_lock' );
	}

	/**
	 * Abandon a paused check without resetting saved HTTP results.
	 *
	 * @return void
	 */
	public function discard_bg_check() {
		$this->stop_bg_check();
		delete_option( self::OPT_USER_STOPPED_CHECK );
		delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		update_option( 'tsoliin_bg_check_complete', 1, false );
		delete_option( 'tsoliin_bg_check_total' );
		delete_option( 'tsoliin_bg_check_checked' );
		delete_option( 'tsoliin_bg_check_started' );
		delete_option( 'tsoliin_bg_check_post_id' );
		delete_option( 'tsoliin_bg_check_token' );
		delete_option( 'tsoliin_bg_check_last_error' );
	}

	/**
	 * Execute HTTP checks until the time budget is exhausted.
	 *
	 * @param int|null $batch_size       Links fetched per inner query. Null uses BG_BATCH.
	 * @param bool     $spawn            When false, keep a recovery cron only (AJAX loop is driving).
	 * @param int|null $budget_override  Seconds of work; null uses the spawn/tick default.
	 * @return string idle|busy|ok
	 */
	public function run_bg_step( $batch_size = null, $spawn = true, $budget_override = null ) {
		if ( ! get_option( 'tsoliin_bg_check_running' ) ) {
			return 'idle';
		}
		$resting = $this->rest_remaining( self::OPT_CHECK_REST_UNTIL );
		if ( $resting > 0 ) {
			$this->schedule_bg_check_step_if_needed( $resting );
			return 'busy';
		}
		if ( ! $this->db->acquire_transient_lock( 'tsoliin_bg_check_step_lock', self::BG_STEP_LOCK_TTL ) ) {
			$this->schedule_bg_check_step_if_needed( 2 );
			return 'busy';
		}
		$step_started = microtime( true );

		$this->ignore_worker_abort();
		$run_token = (string) get_option( 'tsoliin_bg_check_token', '' );
		$spawn     = (bool) $spawn;
		$budget    = null !== $budget_override ? max( 1, (float) $budget_override ) : $this->get_worker_time_budget( $spawn );
		$this->schedule_bg_check_recovery_event();

		try {
			$this->extend_time_limit( $budget + 20 );

			$this->http->begin_bulk_timeout( 8 );
			$batch_size = null === $batch_size ? self::BG_BATCH : max( 1, absint( $batch_size ) );
			$started_at = microtime( true );
			$post_id    = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
			$processed  = 0;

			while ( ( microtime( true ) - $started_at ) < $budget ) {
				if ( ! $this->is_bg_check_run_active( $run_token ) ) {
					return 'idle';
				}
				$links = $this->db->get_links_batch_for_check( $batch_size, $post_id );
				if ( empty( $links ) ) {
					if ( $this->maybe_reschedule_bg_check( $post_id, $run_token, $spawn ) ) {
						return 'ok';
					}
					$this->finalize_bg_check_completion( $run_token );
					return 'ok';
				}

				foreach ( $links as $link ) {
					if ( ! $this->is_bg_check_run_active( $run_token )
						|| ( microtime( true ) - $started_at ) >= $budget ) {
						break 2;
					}
					$check = $this->check_link_row( $link, $run_token );
					if ( null === $check ) {
						continue;
					}
					++$processed;
					$item = $this->build_new_hard_broken_item( $check['link'], $check['result'], $check['prev_failures'] );
					if ( ! empty( $item ) ) {
						$this->queue_immediate_broken_item( $item );
					}
				}

				if ( $processed > 0 ) {
					delete_option( self::OPT_EMPTY_BATCH_RETRIES );
				}
				$this->persist_bg_check_progress( $post_id );
			}

			if ( ! $this->is_bg_check_run_active( $run_token ) ) {
				return 'idle';
			}
			if ( $processed > 0 ) {
				delete_option( self::OPT_EMPTY_BATCH_RETRIES );
			}
			$this->persist_bg_check_progress( $post_id );

			$more = $this->db->get_links_batch_for_check( 1, $post_id );
			if ( ! empty( $more ) ) {
				if ( $spawn ) {
					$this->reschedule_bg_check_step( $this->rest_seconds( $step_started ) );
				} else {
					$this->schedule_bg_check_recovery_event();
				}
			} elseif ( ! $this->maybe_reschedule_bg_check( $post_id, $run_token, $spawn ) ) {
				$this->finalize_bg_check_completion( $run_token );
			}
			return 'ok';
		} catch ( \Throwable $e ) {
			if ( $this->is_bg_check_run_active( $run_token ) ) {
				update_option( 'tsoliin_bg_check_last_error', sanitize_text_field( $e->getMessage() ), false );
				if ( $spawn ) {
					$this->reschedule_bg_check_step( 5 );
				} else {
					$this->schedule_bg_check_recovery_event();
				}
			}
			return 'ok';
		} finally {
			$this->http->end_bulk_timeout();
			$this->start_rest( self::OPT_CHECK_REST_UNTIL, $step_started );
			$this->db->release_transient_lock( 'tsoliin_bg_check_step_lock' );
		}
	}

	/**
	 * Refresh checked/total counters for the running background check.
	 *
	 * @param int $post_id Scope (0 = site-wide).
	 */
	private function persist_bg_check_progress( $post_id ) {
		$post_id = absint( $post_id );
		$pending = $this->db->get_pending_check_count( $post_id );
		// Always follow the live row count so progress never drifts above/below the dashboard TOTAL.
		if ( $post_id > 0 ) {
			$total = (int) $this->db->get_stats_for_post( $post_id )['total'];
		} else {
			$total = (int) $this->db->get_stats()['total'];
		}
		update_option( 'tsoliin_bg_check_total', $total, false );
		update_option( 'tsoliin_bg_check_checked', max( 0, $total - $pending ), false );
		update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
	}

	/**
	 * When the batch query is empty but pending rows remain, reschedule instead of finalizing.
	 *
	 * Never abandons unchecked links: a full Check now / Scan→Check run must reach 100%.
	 *
	 * @param int $post_id Scope (0 = site-wide).
	 * @return bool True when a follow-up step was scheduled.
	 */
	private function maybe_reschedule_bg_check( $post_id, $run_token = '', $spawn = true ) {
		$post_id = absint( $post_id );
		if ( '' !== $run_token && ! $this->is_bg_check_run_active( $run_token ) ) {
			return false;
		}
		TSOLIIN_DB::clear_stats_cache();
		$pending = $this->db->get_pending_check_count( $post_id );
		if ( $pending <= 0 ) {
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
			return false;
		}

		$delay = 0;
		if ( empty( $this->db->get_links_batch_for_check( 1, $post_id ) ) ) {
			$retries = (int) get_option( self::OPT_EMPTY_BATCH_RETRIES, 0 ) + 1;
			if ( $retries > self::EMPTY_BATCH_MAX_RETRIES ) {
				// The count and the batch query disagree persistently: stop instead of looping forever.
				delete_option( self::OPT_EMPTY_BATCH_RETRIES );
				return false;
			}
			update_option( self::OPT_EMPTY_BATCH_RETRIES, $retries, false );
			// Keep trying with backoff — do not finalize while pending remains.
			$delay = min( 120, 5 * max( 1, $retries ) );
		} else {
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		}

		if ( $spawn ) {
			$this->reschedule_bg_check_step( $delay );
		} else {
			$this->schedule_bg_check_recovery_event();
		}
		update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
		return true;
	}

	/**
	 * Resume a full background check that stopped before the unchecked queue was empty.
	 * Skips runs the admin explicitly stopped.
	 *
	 * @return void
	 */
	private function maybe_resume_incomplete_bg_check() {
		if ( get_option( self::OPT_USER_STOPPED_CHECK ) ) {
			return;
		}
		if ( $this->is_bg_scan_blocking_check() ) {
			return;
		}

		$running = (int) get_option( 'tsoliin_bg_check_running', 0 );
		$total   = (int) get_option( 'tsoliin_bg_check_total', 0 );
		$checked = (int) get_option( 'tsoliin_bg_check_checked', 0 );
		$post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );

		TSOLIIN_DB::clear_stats_cache();
		$pending = $this->db->get_pending_check_count( $post_id );

		if ( $pending <= 0 ) {
			return;
		}

		if ( $running ) {
			$this->schedule_bg_check_step_if_needed();
			spawn_cron();
			return;
		}

		// Abandoned mid-run (cron missed steps / premature finalize): finish the queue.
		if ( $total > 0 && $checked > 0 && $checked < $total ) {
			$this->start_bg_check( true, $post_id );
		}
	}

	/**
	 * Nudge a due/overdue background scan or check when WP-Cron did not fire
	 * (DISABLE_WP_CRON, failed loopback, or a delayed single event).
	 *
	 * Never runs scan/check work inside the admin page request itself: a slow
	 * batch here blocked every wp-admin screen (and could hit the PHP time
	 * limit). It only (re)schedules the step and fires a non-blocking cron
	 * spawn; the admin-ajax keep-alive and Heartbeat do the actual work.
	 */
	public function maybe_run_overdue_bg_workers() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nudge = false;
		if ( get_option( 'tsoliin_bg_scan_running' )
			&& ( $this->is_cron_event_overdue( self::HOOK_BG_SCAN_STEP ) || $this->is_bg_heartbeat_stale( 'tsoliin_bg_scan_started', self::BG_RECOVERY_DELAY ) ) ) {
			$this->clear_hook_events( self::HOOK_BG_SCAN_STEP );
			$this->schedule_bg_scan_step_if_needed( 0 );
			$nudge = true;
		}
		if ( get_option( 'tsoliin_bg_check_running' )
			&& ( $this->is_cron_event_overdue( self::HOOK_BG_STEP ) || $this->is_bg_heartbeat_stale( 'tsoliin_bg_check_started', self::BG_RECOVERY_DELAY ) ) ) {
			$this->clear_hook_events( self::HOOK_BG_STEP );
			$this->schedule_bg_check_step_if_needed( 0 );
			$nudge = true;
		}
		if ( $nudge ) {
			spawn_cron();
		}
	}

	/**
	 * @param string $option_key Option holding a MySQL UTC datetime.
	 * @param int    $seconds    Stale after this many seconds.
	 * @return bool
	 */
	private function is_bg_heartbeat_stale( $option_key, $seconds = 8 ) {
		$started = (string) get_option( $option_key, '' );
		if ( '' === $started ) {
			return true;
		}
		$ts = strtotime( $started . ' UTC' );
		if ( false === $ts ) {
			$ts = strtotime( $started );
		}
		if ( false === $ts ) {
			return true;
		}
		return ( time() - (int) $ts ) >= max( 1, absint( $seconds ) );
	}

	/**
	 * Advance a running job from WordPress Heartbeat on any admin screen.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     Heartbeat payload from the browser.
	 * @return array
	 */
	public function heartbeat_drive_bg_jobs( $response, $data ) {
		if ( ! is_array( $data ) || empty( $data['tsoliin_bg'] ) || ! current_user_can( 'manage_options' ) ) {
			return $response;
		}
		if ( get_option( 'tsoliin_bg_scan_running' ) ) {
			$this->run_bg_scan_step( null, false, 4 );
		} elseif ( get_option( 'tsoliin_bg_check_running' ) ) {
			$this->run_bg_step( null, false, 4 );
		}
		if ( ! is_array( $response ) ) {
			$response = array();
		}
		$response['tsoliin_bg'] = array(
			'scan_running'  => (bool) get_option( 'tsoliin_bg_scan_running' ),
			'check_running' => (bool) get_option( 'tsoliin_bg_check_running' ),
		);
		return $response;
	}

	/**
	 * @param string $hook Cron hook.
	 * @return bool True when the next event is missing or already due.
	 */
	private function is_cron_event_overdue( $hook ) {
		$ts = wp_next_scheduled( $hook );
		return ( ! $ts || (int) $ts <= time() );
	}

	/**
	 * @param string $hook Cron hook.
	 */
	private function clear_hook_events( $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Advance a running background check from the admin poll.
	 *
	 * A future recovery cron event must not block this: WP-Cron loopback is
	 * often delayed, and the recovery delay is minutes, not seconds.
	 *
	 * @return bool True when a batch was attempted.
	 */
	public function ensure_bg_check_progress() {
		if ( ! get_option( 'tsoliin_bg_check_running' ) ) {
			return false;
		}

		$this->run_bg_step( null, false );
		return true;
	}

	/**
	 * Advance a running background scan from the admin poll.
	 *
	 * @return bool True when a scan step was attempted.
	 */
	public function ensure_bg_scan_progress() {
		if ( ! get_option( 'tsoliin_bg_scan_running' ) ) {
			return false;
		}

		$this->run_bg_scan_step( null, false );
		return true;
	}

	/**
	 * Send and clear the immediate broken-link email queue.
	 *
	 * @return void
	 */
	private function flush_immediate_broken_queue() {
		$queued = get_option( self::OPT_IMMEDIATE_QUEUE, array() );
		if ( ! is_array( $queued ) || empty( $queued ) ) {
			delete_option( self::OPT_IMMEDIATE_QUEUE );
			return;
		}
		if ( $this->send_immediate_broken_summary_email( $queued ) ) {
			delete_option( self::OPT_IMMEDIATE_QUEUE );
		}
	}

	/**
	 * Mark a background check run complete and run post-check maintenance.
	 *
	 * @return void
	 */
	private function finalize_bg_check_completion( $run_token = '' ) {
		if ( '' !== $run_token && ! $this->is_bg_check_run_active( $run_token ) ) {
			return;
		}
		$post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
		TSOLIIN_DB::clear_stats_cache();
		$pending = $this->db->get_pending_check_count( $post_id );
		if ( $pending > 0 && $this->maybe_reschedule_bg_check( $post_id, $run_token ) ) {
			// Unchecked links remain and a follow-up step is scheduled.
			update_option( 'tsoliin_bg_check_running', 1, false );
			return;
		}
		// Either nothing is pending, or the empty-batch retry cap was reached:
		// finish instead of rescheduling forever.
		delete_option( self::OPT_RESYNCED );

		$this->flush_immediate_broken_queue();
		delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		delete_option( self::OPT_USER_STOPPED_CHECK );
		update_option( 'tsoliin_bg_check_running', 0, false );
		update_option( 'tsoliin_bg_check_complete', 1, false );
		update_option( 'tsoliin_last_check_batch', current_time( 'mysql', true ), false );
		delete_option( 'tsoliin_bg_check_last_error' );
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
		$this->db->maybe_cleanup_transparent_redirects();
		$this->db->cleanup_action_url_rows();
		$this->db->cleanup_blocked_dns_rows();
		$this->db->cleanup_mislabeled_skip_rows( true, 15 );
		$this->maybe_send_digest_broken_email();
	}

	/**
	 * Get current background check progress.
	 *
	 * @return array{ running: bool, checked: int, total: int, pct: int, post_id: int, pending: int }
	 */
	public function get_bg_progress() {
		$running = (bool) get_option( 'tsoliin_bg_check_running', 0 );
		$complete = (bool) get_option( 'tsoliin_bg_check_complete', 0 );
		$checked = (int)  get_option( 'tsoliin_bg_check_checked', 0 );
		$total   = (int)  get_option( 'tsoliin_bg_check_total',   0 );
		$started = (string) get_option( 'tsoliin_bg_check_started', '' );
		$post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );

		// Auto-clear stale running flag (> 30 min without heartbeat).
		if ( $running && '' !== $started ) {
			if ( ( time() - (int) strtotime( $started ) ) > 1800 ) {
				TSOLIIN_DB::clear_stats_cache();
				$stale_pending = $this->db->get_pending_check_count( $post_id );
				if ( $stale_pending > 0 ) {
					// Work remains — reschedule without faking a successful batch heartbeat.
					$this->schedule_bg_check_step_if_needed();
					spawn_cron();
				} else {
					$this->finalize_bg_check_completion();
					$running  = false;
					$complete = true;
				}
			}
		}

		// Keep progress in sync with the live table (allow shrink after scan cleanup / dedupe).
		$pending = 0;
		if ( $total > 0 || $running || $complete ) {
			$pending = $this->db->get_pending_check_count( $post_id );
			if ( $post_id > 0 ) {
				$live_total = (int) $this->db->get_stats_for_post( $post_id )['total'];
			} else {
				$live_total = (int) $this->db->get_stats()['total'];
			}
			// Prefer live TOTAL; keep stored only when the table is empty but a paused run still has counters.
			if ( $live_total > 0 || $running ) {
				$total = $live_total;
			}
			$checked = max( 0, $total - $pending );
			if ( (int) get_option( 'tsoliin_bg_check_total', 0 ) !== $total ) {
				update_option( 'tsoliin_bg_check_total', $total, false );
			}
			if ( (int) get_option( 'tsoliin_bg_check_checked', 0 ) !== $checked ) {
				update_option( 'tsoliin_bg_check_checked', $checked, false );
			}
		}

		$pct = ( $total > 0 ) ? min( 100, (int) round( ( $checked / $total ) * 100 ) ) : 0;
		if ( $pending > 0 && $pct >= 100 ) {
			$pct = 99;
		}

		return array(
			'running' => $running,
			'checked' => $checked,
			'total'   => $total,
			'pct'     => $pct,
			'post_id' => $post_id,
			'pending' => $pending,
			'complete'=> $complete,
		);
	}

	/**
	 * Send a periodic digest with all current hard-broken links.
	 * Digest is skipped when there are no hard-broken links.
	 *
	 * @return void
	 */
	private function maybe_send_digest_broken_email() {
		$mode = $this->get_email_mode();
		if ( 0 !== strpos( $mode, 'digest_' ) ) {
			return;
		}

		$days = absint( str_replace( 'digest_', '', $mode ) );
		if ( ! in_array( $days, array( 7, 15, 30 ), true ) ) {
			return;
		}

		$last_sent = (string) get_option( 'tsoliin_broken_digest_last_sent', '' );
		if ( '' !== $last_sent ) {
			$elapsed = time() - (int) strtotime( $last_sent );
			if ( $elapsed < ( $days * DAY_IN_SECONDS ) ) {
				return;
			}
		}

		$total_broken = $this->db->count_hard_broken_links();
		if ( $total_broken <= 0 ) {
			return;
		}

		$rows = $this->db->get_hard_broken_links( 200 );
		if ( empty( $rows ) ) {
			return;
		}

		$to = $this->get_notification_email();
		if ( '' === $to ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: 1: site name, 2: count */
			__( '[%1$s] Broken links report (%2$d)', 'tso-link-inspector' ),
			$site_name,
			$total_broken
		);

		$intro = sprintf(
			/* translators: 1: count, 2: site name */
			__( 'There are currently %1$d broken links without redirection on %2$s.', 'tso-link-inspector' ),
			$total_broken,
			$site_name
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = TSOLIIN_Email::normalize_broken_item( $row );
		}

		$more = $total_broken > count( $rows ) ? $total_broken - count( $rows ) : 0;

		$sent = TSOLIIN_Email::send_broken_links_report( $to, $subject, $intro, $items, $more );
		if ( $sent ) {
			update_option( 'tsoliin_broken_digest_last_sent', current_time( 'mysql', true ), false );
		}
	}

	/**
	 * HTTP-check one row; resync from WordPress only when the stored URL is missing from its source.
	 *
	 * @param object $link      DB row.
	 * @param string $run_token Optional background-check generation token.
	 * @return array{ link: object, result: array, prev_failures: int }|null Null when the row was removed.
	 */
	private function check_link_row( $link, $run_token = '' ) {
		if ( '' !== $run_token && ! $this->is_bg_check_run_active( $run_token ) ) {
			return null;
		}
		if ( ! $link || empty( $link->id ) ) {
			return null;
		}
		$link_id = (int) $link->id;
		if ( ! $this->begin_inflight( 'check_link', $link_id ) ) {
			// The worker died twice on this link: store it as timed out so the queue moves on.
			$this->db->update_check_result( $link_id, -3, '', false );
			return null;
		}
		try {
			$link = $this->prepare_link_for_http_check( $link );
			if ( ! $link ) {
				$this->end_inflight( 'check_link' );
				return null;
			}
			$prev_failures = isset( $link->consecutive_failures ) ? (int) $link->consecutive_failures : 0;
			$r             = $this->http->check( $link->link_url, (int) $link->post_id );
		} catch ( \Throwable $e ) {
			$this->end_inflight( 'check_link' );
			$this->record_skipped( 'check_link', (string) $link_id, $e->getMessage() );
			$this->db->update_check_result( $link_id, -3, '', false );
			return null;
		}
		$this->end_inflight( 'check_link' );
		if ( '' !== $run_token && ! $this->is_bg_check_run_active( $run_token ) ) {
			return null;
		}
		$this->db->update_check_result(
			(int) $link->id,
			$r['status_code'],
			$r['redirect_url'],
			$r['is_broken'],
			isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : ''
		);
		return array(
			'link'          => $link,
			'result'        => $r,
			'prev_failures' => $prev_failures,
		);
	}

	/**
	 * Drop or refresh stale rows before cron HTTP checks.
	 *
	 * @param object $link DB row.
	 * @return object|null Row to check, or null if removed.
	 */
	private function prepare_link_for_http_check( $link ) {
		if ( ! $link || empty( $link->id ) ) {
			return null;
		}
		if ( $this->scanner->is_url_present_in_source( $link ) ) {
			return $link;
		}
		// Re-read each source at most once per check run. Re-scanning can insert a
		// fresh unchecked row for the same URL; without this limit a row the source
		// check does not recognise was resynced, deleted and re-inserted forever.
		if ( ! $this->claim_resync( $link ) ) {
			$this->db->delete_link( (int) $link->id );
			return null;
		}
		$synced = $this->scanner->resync_link_from_source( $link );
		if ( ! $synced ) {
			$this->db->delete_link( (int) $link->id );
			return null;
		}
		return $synced;
	}

	/**
	 * Allow one resync per source (post / comment / term / widget …) per check run.
	 *
	 * @param object $link DB row.
	 * @return bool True when this source was not resynced yet in the current run.
	 */
	private function claim_resync( $link ) {
		$type    = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$post_id = (int) $link->post_id;
		$sk      = isset( $link->source_key ) ? (string) $link->source_key : '';
		$key     = ( $post_id > 0 && in_array( $type, array( 'link', 'image', 'iframe', 'plain' ), true ) )
			? 'p' . $post_id
			: md5( $type . '|' . $post_id . '|' . $sk );
		$scope   = get_option( 'tsoliin_bg_check_running' )
			? 'run-' . (string) get_option( 'tsoliin_bg_check_token', '' )
			: 'cron-' . gmdate( 'YmdH' );

		$state = get_option( self::OPT_RESYNCED, array() );
		if ( ! is_array( $state ) || ! isset( $state['scope'] ) || $state['scope'] !== $scope ) {
			$state = array(
				'scope' => $scope,
				'keys'  => array(),
			);
		}
		if ( isset( $state['keys'][ $key ] ) || count( $state['keys'] ) >= self::RESYNC_KEYS_MAX ) {
			return false;
		}
		$state['keys'][ $key ] = 1;
		update_option( self::OPT_RESYNCED, $state, false );
		return true;
	}

	/**
	 * Return email mode from plugin settings.
	 *
	 * @return string
	 */
	private function get_email_mode() {
		$s            = get_option( 'tsoliin_settings', array() );
		$allowed_mode = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
		$mode         = isset( $s['broken_email_mode'] ) ? sanitize_key( (string) $s['broken_email_mode'] ) : 'none';
		return in_array( $mode, $allowed_mode, true ) ? $mode : 'none';
	}

	/**
	 * Hard-broken means broken with no redirect destination.
	 *
	 * @param bool   $is_broken    Broken flag.
	 * @param string $redirect_url Redirect destination.
	 * @param int    $status_code  HTTP code.
	 * @return bool
	 */
	private function is_hard_broken_status( $is_broken, $redirect_url, $status_code ) {
		if ( ! $is_broken ) {
			return false;
		}
		if ( '' !== trim( (string) $redirect_url ) ) {
			return false;
		}
		return ! ( $status_code >= 300 && $status_code < 400 );
	}

	/**
	 * Build a queue item when link changed to hard-broken.
	 *
	 * @param object $link          DB row before update.
	 * @param array  $r             HTTP check result.
	 * @param int    $prev_failures consecutive_failures before this check.
	 * @return array|null
	 */
	private function build_new_hard_broken_item( $link, $r, $prev_failures = 0 ) {
		$mode = $this->get_email_mode();
		if ( ! in_array( $mode, array( 'immediate', 'confirmed' ), true ) ) {
			return null;
		}
		$prev_failures = max( 0, (int) $prev_failures );
		$was_hard_broken = $this->is_hard_broken_status(
			! empty( $link->is_broken ),
			isset( $link->redirect_url ) ? (string) $link->redirect_url : '',
			isset( $link->status_code ) ? (int) $link->status_code : 0
		);
		$is_hard_broken = $this->is_hard_broken_status(
			! empty( $r['is_broken'] ),
			isset( $r['redirect_url'] ) ? (string) $r['redirect_url'] : '',
			isset( $r['status_code'] ) ? (int) $r['status_code'] : 0
		);
		if ( ! $is_hard_broken ) {
			return null;
		}

		$new_failures = $prev_failures + 1;
		if ( 'confirmed' === $mode ) {
			if ( $new_failures < 2 || $prev_failures >= 2 ) {
				return null;
			}
		} elseif ( $was_hard_broken ) {
			return null;
		}

		$post_id    = isset( $link->post_id ) ? absint( $link->post_id ) : 0;
		$post_title = isset( $link->post_title ) ? (string) $link->post_title : '';
		if ( '' === $post_title && $post_id > 0 ) {
			$post_title = (string) get_the_title( $post_id );
		}
		return array(
			'id'         => isset( $link->id ) ? absint( $link->id ) : 0,
			'link_url'   => isset( $link->link_url ) ? (string) $link->link_url : '',
			'status_code'=> isset( $r['status_code'] ) ? (int) $r['status_code'] : 0,
			'post_id'    => $post_id,
			'post_title' => $post_title,
		);
	}

	/**
	 * Queue one immediate item during multi-step background checks.
	 *
	 * @param array $item Queue item.
	 * @return void
	 */
	private function queue_immediate_broken_item( array $item ) {
		$lock_key = 'tsoliin_immediate_queue_lock';
		$attempts = 0;
		$locked   = false;
		while ( $attempts < 8 && ! $this->db->acquire_transient_lock( $lock_key, 10 ) ) {
			usleep( 25000 );
			$attempts++;
		}
		$locked = ( $attempts < 8 );
		if ( ! $locked ) {
			return;
		}

		$queue = get_option( self::OPT_IMMEDIATE_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$key = ! empty( $item['id'] ) ? 'id-' . absint( $item['id'] ) : md5( wp_json_encode( $item ) );
		$queue[ $key ] = $item;
		// Keep queue bounded.
		if ( count( $queue ) > 300 ) {
			$queue = array_slice( $queue, -300, null, true );
		}
		update_option( self::OPT_IMMEDIATE_QUEUE, $queue, false );
		$this->db->release_transient_lock( $lock_key );
	}

	/**
	 * Send one summary email for newly detected hard-broken links.
	 *
	 * @param array $items Queue items.
	 * @return bool True when sent, skipped (mode off / empty), or no recipient. False when wp_mail failed.
	 */
	private function send_immediate_broken_summary_email( array $items ) {
		$mode = $this->get_email_mode();
		if ( ! in_array( $mode, array( 'immediate', 'confirmed' ), true ) || empty( $items ) ) {
			return true;
		}
		$to = $this->get_notification_email();
		if ( '' === $to ) {
			return true;
		}

		$items          = array_values( $items );
		$items_count    = count( $items );
		$site_name      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject        = sprintf(
			/* translators: 1: site name, 2: count */
			__( '[%1$s] Broken links report (%2$d)', 'tso-link-inspector' ),
			$site_name,
			$items_count
		);

		$intro = ( 'confirmed' === $mode )
			? sprintf(
				/* translators: 1: number of confirmed broken links, 2: site name */
				_n(
					'%1$d link was confirmed broken after two failed checks on %2$s.',
					'%1$d links were confirmed broken after two failed checks on %2$s.',
					$items_count,
					'tso-link-inspector'
				),
				$items_count,
				$site_name
			)
			: sprintf(
				/* translators: 1: number of newly detected broken links, 2: site name */
				_n(
					'%1$d new broken link was detected on %2$s.',
					'%1$d new broken links were detected on %2$s.',
					$items_count,
					'tso-link-inspector'
				),
				$items_count,
				$site_name
			);

		$normalized = array();
		foreach ( $items as $item ) {
			$normalized[] = TSOLIIN_Email::normalize_broken_item( $item );
		}

		return TSOLIIN_Email::send_broken_links_report( $to, $subject, $intro, $normalized, 0 );
	}

	/**
	 * Return notification recipient email from settings.
	 *
	 * @return string
	 */
	private function get_notification_email() {
		$s     = get_option( 'tsoliin_settings', array() );
		$email = isset( $s['broken_email_to'] ) ? sanitize_email( (string) $s['broken_email_to'] ) : '';
		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		return $email;
	}
}
