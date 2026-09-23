<?php
/**
 * Database handler.
 *
 * Table: {prefix}tso_link_inspector
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TSOLIIN_Quality', false ) ) {
	require_once __DIR__ . '/class-tso-lc-quality.php';
}

/**
 * Class TSOLIIN_DB
 */
class TSOLIIN_DB {

	/** @var string Full table name. */
	private $table;

	/** @var string Full history table name. */
	private $history_table;

	/** Max URL-change history rows kept (oldest pruned automatically). */
	const HISTORY_MAX_ROWS = 500;

	/** @var bool|null Cached result of table_exists() for this request. */
	private $table_exists_cache = null;

	/** @var array<string, bool> Per-request cache for db_table_exists_exact(). */
	private $db_table_exists_exact_cache = array();

	/** @var bool Whether upgrade_schema() already ran this request. */
	private $schema_upgraded = false;

	/** @var bool Whether create_history_table() already ran this request. */
	private $history_table_ensured = false;

	/** @var array<string, array<string, int>> Request cache for get_stats() / get_stats_for_post(). */
	private static $stats_cache = array();

	/** @var array<int, int> Request cache for get_pending_check_count() keyed by post_id (0 = all). */
	private static $pending_check_count_cache = array();

	/** @var array<string, array<string, int>> Request cache for get_cron_queue_counts(). */
	private static $cron_queue_counts_cache = array();

	/** @var array<int, string[]> Request cache for get_broken_link_urls_for_post(). */
	private static $broken_urls_by_post_cache = array();

	/** @var int|null Request guard for maybe_cleanup_transparent_redirects(); null = not tried yet. */
	private static $transparent_rd_cleanup_attempted = null;

	public function __construct() {
		global $wpdb;
		$this->table         = $wpdb->prefix . 'tso_link_inspector';
		$this->history_table = $wpdb->prefix . 'tso_link_inspector_history';
	}

	/** @return string */
	public function get_table() {
		return $this->table;
	}

	/** @return string */
	public function get_history_table() {
		return $this->history_table;
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Create / upgrade the table via dbDelta.
	 */
	public function create_table() {
		global $wpdb;

		$this->maybe_migrate_legacy_table();

		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$this->table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			link_url varchar(2083) NOT NULL DEFAULT '',
			anchor_text text NOT NULL,
			status_code smallint(6) NOT NULL DEFAULT 0,
			redirect_url varchar(2083) NOT NULL DEFAULT '',
			redirect_chain text NULL,
			is_broken tinyint(1) NOT NULL DEFAULT 0,
			user_verified tinyint(1) NOT NULL DEFAULT 0,
			verify_baseline_link varchar(2083) NOT NULL DEFAULT '',
			verify_baseline_redirect varchar(2083) NOT NULL DEFAULT '',
			link_type varchar(10) NOT NULL DEFAULT 'link',
			source_key varchar(64) NOT NULL DEFAULT '',
			last_checked datetime DEFAULT NULL,
			consecutive_failures smallint(6) NOT NULL DEFAULT 0,
			date_found datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY is_broken (is_broken),
			KEY status_code (status_code),
			KEY last_checked (last_checked),
			KEY post_source (post_id, source_key)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta is the WP standard for schema changes.
		dbDelta( $sql );
		$this->upgrade_schema();
		$this->create_history_table();
	}

	/**
	 * Create the URL-change history table (capped log for the History settings tab).
	 *
	 * @return void
	 */
	public function create_history_table() {
		global $wpdb;

		$this->maybe_migrate_legacy_table();

		if ( $this->history_table_ensured ) {
			return;
		}
		$this->history_table_ensured = true;

		/*
		 * dbDelta can re-run CREATE TABLE when DESCRIBE does not match (common with
		 * DEFAULT CURRENT_TIMESTAMP). If the table already exists, skip — do not log
		 * "Table ... already exists" on admin_init / version bump.
		 */
		if ( $this->db_table_exists_exact( $this->history_table ) ) {
			return;
		}

		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$this->history_table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			old_url varchar(2083) NOT NULL DEFAULT '',
			new_url varchar(2083) NOT NULL DEFAULT '',
			change_type varchar(32) NOT NULL DEFAULT 'edit',
			user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY link_id (link_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$suppress = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta is the WP standard for schema changes.
		dbDelta( $sql );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Rename leftover table onto canonical, or drop leftover when canonical already has data.
	 *
	 * DDL names come from $wpdb->prefix + fixed suffixes (not user input).
	 *
	 * @param string $legacy_raw    Unquoted leftover table name.
	 * @param string $canonical_raw Unquoted current table name.
	 * @return bool True when the leftover no longer exists.
	 */
	private function migrate_or_drop_legacy_table( $legacy_raw, $canonical_raw ) {
		global $wpdb;

		$legacy_raw    = (string) $legacy_raw;
		$canonical_raw = (string) $canonical_raw;
		if ( '' === $legacy_raw || $legacy_raw === $canonical_raw ) {
			return true;
		}

		if ( ! $this->db_table_exists_exact( $legacy_raw ) ) {
			return true;
		}

		$canonical = esc_sql( $canonical_raw );
		$legacy    = esc_sql( $legacy_raw );
		$gone      = false;

		/*
		 * SHOW TABLES uses esc_like() so "_" is literal. Single phpcs:disable
		 * for the whole method (no enable before returns).
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $this->db_table_exists_exact( $canonical_raw ) ) {
			$wpdb->query( "RENAME TABLE `{$legacy}` TO `{$canonical}`" );
			$this->remember_table_exists( $legacy_raw, false );
			$this->remember_table_exists( $canonical_raw, true );
			if ( $canonical_raw === $this->table ) {
				$this->table_exists_cache = true;
			}
			$gone = true;
		} else {
			$legacy_rows    = (int) $wpdb->get_var( "SELECT COUNT(1) FROM `{$legacy}`" );
			$canonical_rows = (int) $wpdb->get_var( "SELECT COUNT(1) FROM `{$canonical}`" );

			if ( 0 === $legacy_rows || ( $canonical_rows > 0 && $canonical_rows >= $legacy_rows ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$legacy}`" );
				$this->remember_table_exists( $legacy_raw, false );
				$gone = true;
			} else {
				$tmp     = esc_sql( $canonical_raw . '_mig_tmp' );
				$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" );
				$renamed = $wpdb->query( "RENAME TABLE `{$canonical}` TO `{$tmp}`, `{$legacy}` TO `{$canonical}`" );
				if ( false !== $renamed ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" );
					$this->remember_table_exists( $legacy_raw, false );
					$this->remember_table_exists( $canonical_raw, true );
					if ( $canonical_raw === $this->table ) {
						$this->table_exists_cache = true;
					}
					$gone = true;
				}
			}
		}
		// phpcs:enable

		if ( $gone ) {
			return true;
		}

		$this->remember_table_exists( $legacy_raw, null );
		return ! $this->db_table_exists_exact( $legacy_raw );
	}

	/**
	 * Legacy table names matching {prefix}pc_tso_link_inspector(+_history).
	 *
	 * @return string[]
	 */
	private function legacy_pc_table_names() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;

		$pattern = $wpdb->esc_like( $wpdb->prefix . 'pc_tso_link_inspector' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		if ( ! is_array( $found ) || empty( $found ) ) {
			$cache = array();
			return $cache;
		}

		$expected = array(
			$wpdb->prefix . 'pc_tso_link_inspector',
			$wpdb->prefix . 'pc_tso_link_inspector_history',
		);

		$names = array();
		foreach ( $found as $table_name ) {
			$table_name = (string) $table_name;
			if ( in_array( $table_name, $expected, true ) ) {
				$names[] = $table_name;
			}
		}
		$cache = $names;
		return $cache;
	}

	/**
	 * Rename or drop leftover {prefix}pc_tso_link_inspector and
	 * {prefix}pc_tso_link_inspector_history when safe.
	 *
	 * Older 2.3.x used `$wpdb->prefix . 'pc_tso_link_inspector'` (accidental `pc_`
	 * from `{prefix}` + `tso_`) and derived history as `{that}_history`. After both
	 * leftovers are gone, skip further checks until they reappear or the plugin
	 * version bumps (which clears `tsoliin_legacy_pc_table_cleared`).
	 *
	 * @param string $phase Request pass: `early` (schema setup) or `late` (after other admin hooks).
	 * @return void
	 */
	private function maybe_migrate_legacy_table( $phase = 'early' ) {
		static $done = array();
		if ( ! empty( $done[ $phase ] ) ) {
			return;
		}
		$done[ $phase ] = true;

		if ( '1' === (string) get_option( 'tsoliin_legacy_pc_table_cleared', '' ) ) {
			// Early pass: trust the flag; late pass re-checks once per request (tables may reappear).
			if ( 'early' === $phase ) {
				return;
			}
			if ( empty( $this->legacy_pc_table_names() ) ) {
				return;
			}
			delete_option( 'tsoliin_legacy_pc_table_cleared' );
		}

		global $wpdb;

		$legacy_main    = $wpdb->prefix . 'pc_tso_link_inspector';
		$legacy_history = $wpdb->prefix . 'pc_tso_link_inspector_history';

		$main_gone    = $this->migrate_or_drop_legacy_table( $legacy_main, $this->table );
		$history_gone = $this->migrate_or_drop_legacy_table( $legacy_history, $this->history_table );
		// Legacy history may arrive oversized; enforce the 500-row cap immediately.
		$this->prune_url_change_history();

		if ( $main_gone && $history_gone ) {
			update_option( 'tsoliin_legacy_pc_table_cleared', '1', true );
		}
	}

	/**
	 * Late admin pass: drop legacy pc_ tables recreated after early schema setup.
	 *
	 * @return void
	 */
	public function late_cleanup_legacy_pc_tables() {
		$this->maybe_migrate_legacy_table( 'late' );
	}

	/**
	 * Whether a table name exists (exact match; "_" is not a LIKE wildcard).
	 *
	 * @param string $table_name Full table name.
	 * @return bool
	 */
	private function db_table_exists_exact( $table_name ) {
		global $wpdb;
		$table_name = (string) $table_name;
		if ( '' === $table_name ) {
			return false;
		}
		if ( array_key_exists( $table_name, $this->db_table_exists_exact_cache ) ) {
			return $this->db_table_exists_exact_cache[ $table_name ];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		$exists = ( $found === $table_name );
		$this->db_table_exists_exact_cache[ $table_name ] = $exists;
		return $exists;
	}

	/**
	 * Store or forget a cached SHOW TABLES result after DDL.
	 *
	 * @param string    $table_name Full table name.
	 * @param bool|null $exists     True/false to cache; null to invalidate.
	 * @return void
	 */
	private function remember_table_exists( $table_name, $exists ) {
		$table_name = (string) $table_name;
		if ( '' === $table_name ) {
			return;
		}
		if ( null === $exists ) {
			unset( $this->db_table_exists_exact_cache[ $table_name ] );
			return;
		}
		$this->db_table_exists_exact_cache[ $table_name ] = (bool) $exists;
	}

	/**
	 * Add columns introduced after initial releases (idempotent).
	 *
	 * @return void
	 */
	public function upgrade_schema() {
		if ( $this->schema_upgraded || ! $this->table_exists() ) {
			return;
		}
		$this->schema_upgraded = true;
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE 'redirect_chain'" );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$this->table}` ADD COLUMN redirect_chain text NULL AFTER redirect_url" );
		}
		$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE 'consecutive_failures'" );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$this->table}` ADD COLUMN consecutive_failures smallint(6) NOT NULL DEFAULT 0 AFTER last_checked" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Decode stored redirect chain JSON.
	 *
	 * @param string $json Stored JSON.
	 * @return array<int, array{code:int,url:string}>
	 */
	public static function decode_redirect_chain( $json ) {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$chain = array();
		foreach ( $decoded as $hop ) {
			if ( ! is_array( $hop ) ) {
				continue;
			}
			$url = isset( $hop['url'] ) ? trim( (string) $hop['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$chain[] = array(
				'code' => isset( $hop['code'] ) ? (int) $hop['code'] : 0,
				'url'  => $url,
			);
		}
		return $chain;
	}

	/**
	 * Encode redirect hops for storage.
	 *
	 * @param array<int, array{code:int,url:string}> $chain Redirect hops.
	 * @return string
	 */
	public static function encode_redirect_chain( $chain ) {
		if ( ! is_array( $chain ) || empty( $chain ) ) {
			return '';
		}
		$safe = array();
		foreach ( $chain as $hop ) {
			if ( ! is_array( $hop ) ) {
				continue;
			}
			$url = isset( $hop['url'] ) ? trim( (string) $hop['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$safe[] = array(
				'code' => isset( $hop['code'] ) ? (int) $hop['code'] : 0,
				'url'  => $url,
			);
		}
		return empty( $safe ) ? '' : (string) wp_json_encode( $safe );
	}

	/**
	 * Ensure the table exists (at most one SHOW TABLES query per request).
	 */
	public function ensure_table_exists() {
		$this->maybe_migrate_legacy_table();
		if ( $this->table_exists() ) {
			$this->upgrade_schema();
			$this->ensure_history_table();
			return;
		}
		$this->create_table();
		$this->table_exists_cache = true;
	}

	/**
	 * Ensure the URL-change history table exists (dbDelta at most once per request).
	 *
	 * @return void
	 */
	private function ensure_history_table() {
		$this->create_history_table();
	}

	/**
	 * Whether the inspector table exists (one query per request, cached).
	 *
	 * @return bool
	 */
	private function table_exists() {
		if ( null !== $this->table_exists_cache ) {
			return $this->table_exists_cache;
		}
		$this->table_exists_cache = $this->db_table_exists_exact( $this->table );
		return $this->table_exists_cache;
	}

	/**
	 * Drop plugin tables (canonical + leftover pc_ names). Used by uninstall.
	 */
	public function drop_table() {
		global $wpdb;

		$tables = array(
			$this->table,
			$this->history_table,
			$wpdb->prefix . 'pc_tso_link_inspector',
			$wpdb->prefix . 'pc_tso_link_inspector_history',
		);
		$tables = array_unique( array_filter( $tables ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( $tables as $table_name ) {
			$quoted = esc_sql( $table_name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$quoted}`" );
		}
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	/**
	 * Insert or update a link record.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $link_url    URL.
	 * @param string $anchor      Anchor text.
	 * @param string $link_type   link|image|iframe|comment|menu.
	 * @param string $source_key  Empty for post content; for comments e.g. c-123-author or c-123-l-<md5>.
	 * @return int|false
	 */
	/**
	 * Canonical set of link_type values stored in the DB (also used to validate
	 * the list-table "Type" filter).
	 *
	 * @return string[]
	 */
	public static function allowed_link_types() {
		return array( 'link', 'image', 'iframe', 'plain', 'comment', 'menu', 'widget', 'term', 'template', 'wp_block', 'acf' );
	}

	public function upsert_link( $post_id, $link_url, $anchor, $link_type = 'link', $source_key = '' ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$link_url   = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link_url ) );
		$anchor     = sanitize_text_field( (string) $anchor );
		$types      = self::allowed_link_types();
		$link_type  = in_array( $link_type, $types, true ) ? $link_type : 'link';
		$source_key = $this->sanitize_source_key( (string) $source_key );

		$external_types = array( 'widget', 'term', 'acf' );
		if ( ! $post_id ) {
			if ( ! in_array( $link_type, $external_types, true ) || '' === $source_key ) {
				return false;
			}
		} elseif ( '' === $link_url ) {
			return false;
		}

		if ( $post_id && '' === $link_url ) {
			return false;
		}

		// Stable source_key (menus, WooCommerce fields): match by key and update URL in place.
		if ( '' !== $source_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$by_key = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, anchor_text, link_url FROM {$this->table} WHERE post_id = %d AND source_key = %s LIMIT 1",
					$post_id,
					$source_key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $by_key ) {
				$anchor_to_store = $anchor;
				if ( '' === $anchor && '' !== trim( (string) $by_key->anchor_text ) ) {
					$anchor_to_store = (string) $by_key->anchor_text;
				}
				$url_changed = (string) $by_key->link_url !== $link_url;
				if ( $url_changed ) {
					// Same reset as update_link_url(): new URL needs a fresh HTTP check.
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$this->table,
						array(
							'link_url'                 => $link_url,
							'anchor_text'              => $anchor_to_store,
							'link_type'                => $link_type,
							'status_code'              => 0,
							'redirect_url'             => '',
							'is_broken'                => 0,
							'last_checked'             => null,
							'consecutive_failures'     => 0,
							'user_verified'            => 0,
							'verify_baseline_link'     => '',
							'verify_baseline_redirect' => '',
						),
						array( 'id' => absint( $by_key->id ) ),
						array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ),
						array( '%d' )
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					self::clear_stats_cache();
					return absint( $by_key->id );
				}
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table,
					array(
						'anchor_text' => $anchor_to_store,
						'link_type'   => $link_type,
					),
					array( 'id' => absint( $by_key->id ) ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return absint( $by_key->id );
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$this->table} WHERE post_id = %d AND link_url = %s AND source_key = %s LIMIT 1",
				$post_id,
				$link_url,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $existing ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT anchor_text FROM {$this->table} WHERE id = %d LIMIT 1",
					absint( $existing )
				)
			);
			$anchor_to_store = $anchor;
			if ( '' === $anchor && $row && '' !== trim( (string) $row->anchor_text ) ) {
				$anchor_to_store = (string) $row->anchor_text;
			}
			$wpdb->update(
				$this->table,
				array( 'anchor_text' => $anchor_to_store, 'link_type' => $link_type ),
				array( 'id' => absint( $existing ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return absint( $existing );
		}

		$alias_id = $this->find_link_id_by_url_alias( $post_id, $link_url, $source_key );
		if ( $alias_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT anchor_text, link_url FROM {$this->table} WHERE id = %d LIMIT 1",
					absint( $alias_id )
				)
			);
			$anchor_to_store = $anchor;
			if ( '' === $anchor && $row && '' !== trim( (string) $row->anchor_text ) ) {
				$anchor_to_store = (string) $row->anchor_text;
			}
			$url_changed = $row && (string) $row->link_url !== $link_url;
			if ( $url_changed ) {
				$wpdb->update(
					$this->table,
					array(
						'link_url'                 => $link_url,
						'anchor_text'              => $anchor_to_store,
						'link_type'                => $link_type,
						'status_code'              => 0,
						'redirect_url'             => '',
						'is_broken'                => 0,
						'last_checked'             => null,
						'consecutive_failures'     => 0,
						'user_verified'            => 0,
						'verify_baseline_link'     => '',
						'verify_baseline_redirect' => '',
					),
					array( 'id' => absint( $alias_id ) ),
					array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->update(
					$this->table,
					array(
						'link_url'    => $link_url,
						'anchor_text' => $anchor_to_store,
						'link_type'   => $link_type,
					),
					array( 'id' => absint( $alias_id ) ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $url_changed ) {
				self::clear_stats_cache();
			}
			return absint( $alias_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->table,
			array(
				'post_id'      => $post_id,
				'link_url'     => $link_url,
				'anchor_text'  => $anchor,
				'status_code'  => 0,
				'redirect_url' => '',
				'is_broken'    => 0,
				'link_type'    => $link_type,
				'source_key'   => $source_key,
				'last_checked' => null,
				'date_found'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $wpdb->insert_id ) {
			self::clear_stats_cache();
			return absint( $wpdb->insert_id );
		}
		return false;
	}

	/**
	 * Find an existing row for the same logical URL (e.g. Play Store id variants).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $link_url   Incoming URL.
	 * @param string $source_key Source key.
	 * @return int Row id or 0.
	 */
	private function find_link_id_by_url_alias( $post_id, $link_url, $source_key ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$source_key = $this->sanitize_source_key( (string) $source_key );
		if ( ! $post_id ) {
			return 0;
		}

		$play_id = TSOLIIN_HTTP::parse_play_store_app_id( $link_url );
		if ( '' !== $play_id ) {
			$like    = '%' . $wpdb->esc_like( 'id=' . $play_id ) . '%';
			$play_host_like = '%play.google.com%';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$this->table} WHERE post_id = %d AND source_key = %s AND link_url LIKE %s AND link_url LIKE %s LIMIT 1",
					$post_id,
					$source_key,
					$play_host_like,
					$like
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $found ? absint( $found ) : 0;
		}

		return 0;
	}

	/**
	 * @param string $key Raw source key.
	 * @return string
	 */
	public function sanitize_source_key( $key ) {
		$key = strtolower( preg_replace( '/[^a-z0-9\-]/', '', (string) $key ) );
		return substr( $key, 0, 64 );
	}

	/**
	 * Drop menu link rows whose source_key for this menu item is no longer present.
	 *
	 * @param int      $menu_item_id Nav menu item post ID.
	 * @param string[] $allowed_keys Non-empty keys still valid (e.g. mi-42).
	 * @param string   $keep_link_url Current menu URL to keep for this item (drops stale URLs).
	 * @return void
	 */
	public function delete_menu_sources_not_in( $menu_item_id, array $allowed_keys, $keep_link_url = '' ) {
		global $wpdb;
		$item_id = absint( $menu_item_id );
		if ( ! $item_id ) {
			return;
		}
		$source_key = $this->sanitize_source_key( 'mi-' . $item_id );
		$allowed    = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && $k === $source_key ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );

		if ( empty( $allowed ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE link_type = %s AND source_key = %s",
					'menu',
					$source_key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			self::clear_stats_cache();
			return;
		}

		$keep_link_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $keep_link_url ) );
		if ( '' !== $keep_link_url ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE link_type = %s AND source_key = %s AND link_url != %s",
					'menu',
					$source_key,
					$keep_link_url
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			self::clear_stats_cache();
		}
	}

	/**
	 * Remove inspector rows for a comment (after delete/trash purge).
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function delete_links_for_comment( $comment_id ) {
		global $wpdb;
		$cid = absint( $comment_id );
		if ( ! $cid ) {
			return;
		}
		$like = 'c-' . $cid . '-%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s",
				'comment',
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		self::clear_stats_cache();
	}

	/**
	 * Drop comment link rows whose source_key for this comment is no longer present (href/author removed).
	 *
	 * @param int      $post_id       Post ID the comment belongs to.
	 * @param int      $comment_id    Comment ID.
	 * @param string[] $allowed_keys  Non-empty keys still valid (e.g. c-9-author, c-9-l-abc…).
	 * @return void
	 */
	public function delete_comment_sources_not_in( $post_id, $comment_id, array $allowed_keys ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$cid     = absint( $comment_id );
		if ( ! $post_id || ! $cid ) {
			return;
		}
		$prefix = 'c-' . $cid . '-';
		$allowed = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && 0 === strpos( $k, $prefix ) ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );

		if ( empty( $allowed ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s",
					$post_id,
					'comment',
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			self::clear_stats_cache();
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$sql          = "DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
		$params       = array_merge(
			array( $post_id, 'comment', $wpdb->esc_like( $prefix ) . '%' ),
			$allowed
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic table; NOT IN placeholders built from sanitized keys.
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		self::clear_stats_cache();
	}

	/**
	 * Drop rows for a source prefix when keys are no longer present (widgets, terms, templates, etc.).
	 *
	 * @param string   $link_type    link_type value.
	 * @param string   $prefix       source_key prefix (e.g. t-42-, wg-sidebar-1-text-2-).
	 * @param string[] $allowed_keys Valid keys for this source.
	 * @param int      $post_id      Optional post scope (0 = any).
	 * @return void
	 */
	public function delete_sources_not_in( $link_type, $prefix, array $allowed_keys, $post_id = 0 ) {
		global $wpdb;
		$link_type = sanitize_key( (string) $link_type );
		$prefix    = $this->sanitize_source_key( (string) $prefix );
		$post_id   = absint( $post_id );
		if ( '' === $link_type || '' === $prefix ) {
			return;
		}

		$allowed = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && 0 === strpos( $k, $prefix ) ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );
		$like    = $wpdb->esc_like( $prefix ) . '%';

		if ( empty( $allowed ) ) {
			if ( $post_id ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s",
						$post_id,
						$link_type,
						$like
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s",
						$link_type,
						$like
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			self::clear_stats_cache();
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		if ( $post_id ) {
			$sql    = "DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
			$params = array_merge( array( $post_id, $link_type, $like ), $allowed );
		} else {
			$sql    = "DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
			$params = array_merge( array( $link_type, $like ), $allowed );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		self::clear_stats_cache();
	}

	/**
	 * Update HTTP check result for a link.
	 *
	 * @param int          $link_id        Link ID.
	 * @param int          $status_code    HTTP status.
	 * @param string       $redirect_url   Final redirect destination.
	 * @param int|bool     $is_broken      Broken flag.
	 * @param string|array $redirect_chain JSON string or hop array.
	 */
	public function update_check_result( $link_id, $status_code, $redirect_url, $is_broken, $redirect_chain = '' ) {
		global $wpdb;

		$link_id      = absint( $link_id );
		$redirect_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $redirect_url ) );
		$is_broken    = $is_broken ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_verified, link_url, verify_baseline_link, verify_baseline_redirect, is_broken, consecutive_failures FROM {$this->table} WHERE id = %d LIMIT 1",
				$link_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $row ) {
			return;
		}

		$normalized = TSOLIIN_HTTP::normalize_stored_check_result(
			(string) $row->link_url,
			$status_code,
			$redirect_url,
			$is_broken
		);
		$status_code  = (int) $normalized['status_code'];
		$redirect_url = (string) $normalized['redirect_url'];
		$is_broken    = (int) $normalized['is_broken'];
		$chain_json   = is_array( $redirect_chain )
			? self::encode_redirect_chain( $redirect_chain )
			: trim( (string) $redirect_chain );
		if ( '' === $redirect_url ) {
			$chain_json = '';
		}

		$verified = (int) $row->user_verified;
		$clear_lock = false;
		$prev_failures = isset( $row->consecutive_failures ) ? (int) $row->consecutive_failures : 0;
		$failures      = $is_broken ? $prev_failures + 1 : 0;

		// user_verified freezes display until the stored link URL or redirect outcome changes,
		// or until the link is actually broken (always overrides).
		if ( $verified && ! $is_broken ) {
			$baseline_link  = isset( $row->verify_baseline_link ) ? (string) $row->verify_baseline_link : '';
			$baseline_redir = isset( $row->verify_baseline_redirect ) ? (string) $row->verify_baseline_redirect : '';

			// Rows verified before baseline columns existed: backfill baselines, then evaluate changes.
			if ( '' === $baseline_link && '' === $baseline_redir ) {
				$baseline_link  = (string) $row->link_url;
				$baseline_redir = trim( (string) $row->redirect_url );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table,
					array(
						'verify_baseline_link'     => $baseline_link,
						'verify_baseline_redirect' => $baseline_redir,
						'last_checked'             => current_time( 'mysql', true ),
					),
					array( 'id' => $link_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}

			$link_changed = ! TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $baseline_link );
			$http             = new TSOLIIN_HTTP();
			$redirect_changed = ! $http->redirect_outcomes_match_for_verify( (string) $row->link_url, $baseline_redir, $redirect_url );

			if ( ! $link_changed && ! $redirect_changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table,
					array(
						'last_checked'          => current_time( 'mysql', true ),
						'consecutive_failures'  => 0,
					),
					array( 'id' => $link_id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
				self::clear_stats_cache();
				return;
			}

			$clear_lock = true;
		} elseif ( $verified && $is_broken ) {
			$clear_lock = true;
		}

		$data = array(
			'status_code'          => intval( $status_code ),
			'redirect_url'         => $redirect_url,
			'redirect_chain'       => $chain_json,
			'is_broken'            => $is_broken,
			'last_checked'         => current_time( 'mysql', true ),
			'consecutive_failures' => in_array( (int) $status_code, array( -8, -9 ), true ) ? $prev_failures : $failures,
		);
		$format = array( '%d', '%s', '%s', '%d', '%s', '%d' );

		if ( $clear_lock ) {
			$data['user_verified']            = 0;
			$data['verify_baseline_link']    = '';
			$data['verify_baseline_redirect'] = '';
			$format[]                         = '%d';
			$format[]                         = '%s';
			$format[]                         = '%s';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table, $data, array( 'id' => $link_id ), $format, array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
	}

	/**
	 * Update the link_url for a row and reset its check state.
	 *
	 * @param int    $link_id     Link row ID.
	 * @param string $new_url     New URL.
	 * @param string $change_type History label: edit|suggest|relative|https|bulk_relative|bulk_https.
	 */
	public function update_link_url( $link_id, $new_url, $change_type = 'edit' ) {
		global $wpdb;
		$link_id     = absint( $link_id );
		$new_url     = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $new_url ) );
		$change_type = sanitize_key( (string) $change_type );
		if ( '' === $change_type ) {
			$change_type = 'edit';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT link_url, post_id FROM {$this->table} WHERE id = %d",
				$link_id
			)
		);
		$wpdb->update(
			$this->table,
			array(
				'link_url'                   => $new_url,
				'status_code'                => 0,
				'redirect_url'               => '',
				'is_broken'                  => 0,
				'last_checked'               => null,
				'consecutive_failures'       => 0,
				'user_verified'              => 0, // New URL: clear user decision, needs fresh check.
				'verify_baseline_link'       => '',
				'verify_baseline_redirect'   => '',
			),
			array( 'id' => $link_id ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();

		if ( $old_row && (string) $old_row->link_url !== $new_url ) {
			$this->log_url_change(
				$link_id,
				(int) $old_row->post_id,
				(string) $old_row->link_url,
				$new_url,
				$change_type
			);
		}
	}

	/**
	 * How many history rows to delete when over the cap.
	 *
	 * @param int $count Current row count.
	 * @param int $limit Max rows to keep.
	 * @return int
	 */
	public static function history_overflow_count( $count, $limit = self::HISTORY_MAX_ROWS ) {
		$count = max( 0, (int) $count );
		$limit = max( 1, (int) $limit );
		return max( 0, $count - $limit );
	}

	/**
	 * Record a URL change and prune oldest rows past HISTORY_MAX_ROWS.
	 *
	 * @param int    $link_id     Link ID.
	 * @param int    $post_id     Post ID.
	 * @param string $old_url     Previous URL.
	 * @param string $new_url     New URL.
	 * @param string $change_type Change type key.
	 * @return void
	 */
	public function log_url_change( $link_id, $post_id, $old_url, $new_url, $change_type = 'edit' ) {
		global $wpdb;
		$this->ensure_history_table();
		$change_type = sanitize_key( (string) $change_type );
		if ( '' === $change_type ) {
			$change_type = 'edit';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$this->history_table,
			array(
				'link_id'     => absint( $link_id ),
				'post_id'     => absint( $post_id ),
				'old_url'     => (string) $old_url,
				'new_url'     => (string) $new_url,
				'change_type' => $change_type,
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false !== $inserted ) {
			$this->prune_url_change_history();
		}
	}

	/**
	 * Delete oldest history rows when over the configured maximum.
	 *
	 * @param int $limit Max rows to keep.
	 * @return int Row count remaining after pruning (avoids a second COUNT(*)
	 *             call from callers that need the up-to-date total, such as
	 *             the settings History tab).
	 */
	public function prune_url_change_history( $limit = self::HISTORY_MAX_ROWS ) {
		global $wpdb;
		$this->ensure_history_table();
		$limit = max( 1, (int) $limit );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->history_table}" );
		$extra = self::history_overflow_count( $count, $limit );
		$deleted = 0;
		if ( $extra > 0 ) {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->history_table} ORDER BY created_at ASC, id ASC LIMIT %d",
					$extra
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return max( 0, $count - max( 0, $deleted ) );
	}

	/**
	 * @return int
	 */
	public function count_url_change_history() {
		global $wpdb;
		$this->ensure_history_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->history_table}" );
	}

	/**
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public function get_url_change_history( $limit = 100 ) {
		global $wpdb;
		$this->ensure_history_table();
		$limit = max( 1, min( self::HISTORY_MAX_ROWS, absint( $limit ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->history_table} ORDER BY created_at DESC, id DESC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return int Rows deleted.
	 */
	public function clear_url_change_history() {
		global $wpdb;
		$this->ensure_history_table();
		// Prefer DELETE over TRUNCATE so shared hosts without DROP privilege still work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$wpdb->query( "DELETE FROM {$this->history_table}" );
		return 1;
	}

	/**
	 * Update stored anchor text without resetting HTTP check state.
	 *
	 * @param int    $link_id Link row ID.
	 * @param string $anchor  New anchor text.
	 * @return void
	 */
	public function update_link_anchor_text( $link_id, $anchor ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array( 'anchor_text' => sanitize_text_field( (string) $anchor ) ),
			array( 'id' => absint( $link_id ) ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
	}

	/**
	 * Mark a link as not broken (manually validated).
	 */
	public function mark_as_not_broken( $link_id ) {
		global $wpdb;
		$link_id = absint( $link_id );
		$link    = $this->get_link( $link_id );
		if ( ! $link ) {
			return false;
		}
		$baseline_link = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->link_url ) );
		$redir_col     = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->redirect_url ) );
		// After the first "not broken" save, redirect_url is cleared for display; keep the stored baseline on re-mark.
		if ( '' !== $redir_col ) {
			$baseline_redir = $redir_col;
		} elseif ( ! empty( $link->user_verified ) && isset( $link->verify_baseline_redirect ) ) {
			$baseline_redir = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->verify_baseline_redirect ) );
		} else {
			$baseline_redir = '';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array(
				'is_broken'                  => 0,
				'status_code'                => 200,
				'last_checked'               => current_time( 'mysql', true ),
				'redirect_url'               => '',
				'redirect_chain'             => '',
				'consecutive_failures'       => 0,
				'user_verified'              => 1,
				'verify_baseline_link'       => $baseline_link,
				'verify_baseline_redirect'   => $baseline_redir,
			),
			array( 'id' => $link_id ),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
		return true;
	}

	/**
	 * Delete a single link row.
	 *
	 * @return bool True when a row was deleted.
	 */
	public function delete_link( $link_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $this->table, array( 'id' => absint( $link_id ) ), array( '%d' ) );
		if ( $deleted ) {
			self::clear_stats_cache();
		}
		return (bool) $deleted;
	}

	/**
	 * Delete all link rows for a post.
	 */
	public function delete_links_for_post( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'post_id' => absint( $post_id ) ), array( '%d' ) );
		self::clear_stats_cache();
	}

	/**
	 * Reset last_checked to NULL so links are queued for HTTP check again.
	 *
	 * @param int $post_id When > 0, only links for that post; otherwise the whole table.
	 */
	public function reset_for_recheck( $post_id = 0 ) {
		global $wpdb;
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET last_checked = NULL WHERE post_id = %d", $post_id ) );
		} else {
			// Reset ALL links, including manually verified rows, so a full check can
			// detect real regressions when previously "Not broken" links change state.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( "UPDATE {$this->table} SET last_checked = NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
	}

	/**
	 * Reset all last_checked to NULL (forces full re-check).
	 *
	 * @deprecated 1.9.8 Use reset_for_recheck() instead.
	 */
	public function reset_all_for_recheck() {
		$this->reset_for_recheck( 0 );
	}

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Get a single link row.
	 *
	 * @param int $link_id Row ID.
	 * @return object|null
	 */
	public function get_link( $link_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				absint( $link_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get a link row by exact post URL, type, and source key.
	 *
	 * @param int    $post_id    Post ID (0 for widget/term).
	 * @param string $link_url   Stored URL.
	 * @param string $link_type  Link type.
	 * @param string $source_key Source key.
	 * @return object|null
	 */
	public function get_link_by_post_url( $post_id, $link_url, $link_type, $source_key = '' ) {
		global $wpdb;
		$post_id    = absint( $post_id );
		$link_url   = trim( (string) $link_url );
		$link_type  = sanitize_key( (string) $link_type );
		$source_key = $this->sanitize_source_key( (string) $source_key );
		if ( '' === $link_url ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE post_id = %d AND link_url = %s AND link_type = %s AND source_key = %s LIMIT 1",
				$post_id,
				$link_url,
				$link_type,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Get a link row by source key.
	 *
	 * @param string   $source_key Source key.
	 * @param string   $link_type  Link type.
	 * @param int|null $post_id    Optional post ID filter.
	 * @return object|null
	 */
	public function get_link_by_source_key( $source_key, $link_type, $post_id = null ) {
		global $wpdb;
		$source_key = $this->sanitize_source_key( (string) $source_key );
		$link_type  = sanitize_key( (string) $link_type );
		if ( '' === $source_key ) {
			return null;
		}
		if ( null !== $post_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE source_key = %s AND link_type = %s AND post_id = %d LIMIT 1",
					$source_key,
					$link_type,
					absint( $post_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $row ? $row : null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE source_key = %s AND link_type = %s LIMIT 1",
				$source_key,
				$link_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Get link rows for a post and type (post content uses empty source_key).
	 *
	 * @param int         $post_id    Post ID.
	 * @param string      $link_type  Link type.
	 * @param string|null $source_key Source key filter; null = any.
	 * @return object[]
	 */
	public function get_links_by_post_and_type( $post_id, $link_type, $source_key = '' ) {
		global $wpdb;
		$post_id   = absint( $post_id );
		$link_type = sanitize_key( (string) $link_type );
		if ( null === $source_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE post_id = %d AND link_type = %s ORDER BY id ASC",
					$post_id,
					$link_type
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return is_array( $rows ) ? $rows : array();
		}
		$source_key = $this->sanitize_source_key( (string) $source_key );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key = %s ORDER BY id ASC",
				$post_id,
				$link_type,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get link rows whose source_key starts with a prefix.
	 *
	 * @param string   $link_type Link type.
	 * @param string   $prefix    Source key prefix.
	 * @param int|null $post_id   Optional post ID filter.
	 * @return object[]
	 */
	public function get_links_by_source_prefix( $link_type, $prefix, $post_id = null ) {
		global $wpdb;
		$link_type = sanitize_key( (string) $link_type );
		$prefix    = $this->sanitize_source_key( (string) $prefix );
		if ( '' === $prefix ) {
			return array();
		}
		$like = $wpdb->esc_like( $prefix ) . '%';
		if ( null !== $post_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s AND post_id = %d ORDER BY id ASC",
					$link_type,
					$like,
					absint( $post_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return is_array( $rows ) ? $rows : array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s ORDER BY id ASC",
				$link_type,
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get paginated links with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	public function get_links( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'filter'           => 'all',
			'quality_filter'   => '',
			'link_type_filter' => '',
			'scope'            => 'all',
			'per_page'       => 20,
			'paged'          => 1,
			'orderby'        => 'date_found',
			'order'          => 'DESC',
			'search'         => '',
			'post_id'        => 0,
		);
		$args = wp_parse_args( $args, $defaults );
		$args['scope'] = $this->sanitize_link_scope( $args['scope'] );
		$args          = $this->normalize_link_query_filters( $args );

		if ( 'unpublished_target' === $args['quality_filter'] ) {
			return $this->get_links_unpublished_target( $args );
		}

		return $this->query_links_paginated( $args );
	}

	/**
	 * Split legacy quality values stored in filter into quality_filter.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function normalize_link_query_filters( array $args ) {
		$quality_keys = array( 'empty_anchor', 'generic_anchor', 'unpublished_target' );
		$filter       = sanitize_key( (string) $args['filter'] );
		$quality      = sanitize_key( (string) $args['quality_filter'] );

		if ( in_array( $filter, $quality_keys, true ) ) {
			if ( '' === $quality ) {
				$args['quality_filter'] = $filter;
			}
			$args['filter'] = 'all';
		} elseif ( in_array( $quality, $quality_keys, true ) ) {
			$args['quality_filter'] = $quality;
		} else {
			$args['quality_filter'] = '';
		}

		$status_allowed = array( 'all', 'broken', 'redirect', 'ok', 'unchecked', 'http_insecure', 'manual_locked' );
		if ( ! in_array( sanitize_key( (string) $args['filter'] ), $status_allowed, true ) ) {
			$args['filter'] = 'all';
		}

		$link_type = sanitize_key( (string) $args['link_type_filter'] );
		if ( '' !== $link_type && in_array( $link_type, self::allowed_link_types(), true ) ) {
			$args['link_type_filter'] = $link_type;
		} else {
			$args['link_type_filter'] = '';
		}

		return $args;
	}

	/**
	 * Shared WHERE/ORDER BY parts for admin link list queries.
	 *
	 * @param array $args Query arguments.
	 * @return array{where:string,params:array,orderby:string,order:string,posts_join:string}
	 */
	private function build_links_list_query_parts( array $args ) {
		global $wpdb;

		$scope = $this->sanitize_link_scope( $args['scope'] );

		$allowed_orderby = array( 'id', 'post_id', 'link_url', 'status_code', 'is_broken', 'last_checked', 'date_found', 'link_type' );
		if ( ! in_array( $args['orderby'], $allowed_orderby, true ) ) {
			$args['orderby'] = 'date_found';
		}
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$orderby = esc_sql( $args['orderby'] );

		$where  = 'WHERE 1=1';
		$params = array();

		switch ( $args['filter'] ) {
			case 'broken':
				// Only after a completed HTTP check (not stale codes while queued for recheck).
				$where .= ' AND l.last_checked IS NOT NULL AND l.is_broken = 1 AND l.user_verified = 0';
				break;
			case 'redirect':
				$where .= ' AND ' . self::sql_redirect_match( 'l.' );
				break;
			case 'ok':
				$where   .= ' AND l.last_checked IS NOT NULL AND l.is_broken = 0 AND l.status_code = 200 AND l.link_url NOT LIKE %s AND l.user_verified = 0';
				$params[] = 'http://%';
				break;
			case 'unchecked':
				$where .= ' AND l.last_checked IS NULL AND l.user_verified = 0';
				break;
			case 'http_insecure':
				// Confirmed working (or at least checked) HTTP URLs — not the pending recheck queue.
				$where   .= ' AND l.last_checked IS NOT NULL AND l.link_url LIKE %s AND l.is_broken = 0 AND l.user_verified = 0';
				$params[] = 'http://%';
				break;
			case 'manual_locked':
				$where .= ' AND l.user_verified = 1';
				break;
		}

		switch ( sanitize_key( (string) $args['quality_filter'] ) ) {
			case 'empty_anchor':
				$where .= TSOLIIN_Quality::build_empty_anchor_sql_where();
				break;
			case 'generic_anchor':
				$generic_sql = TSOLIIN_Quality::build_generic_anchor_sql();
				$where      .= $generic_sql['where'];
				$params      = array_merge( $params, $generic_sql['params'] );
				break;
		}

		$scope_sql = $this->build_link_scope_sql( $scope );
		if ( '' !== $scope_sql['where'] ) {
			$where .= $scope_sql['where'];
			$params  = array_merge( $params, $scope_sql['params'] );
		}

		if ( ! empty( $args['post_id'] ) ) {
			$where    .= ' AND l.post_id = %d';
			$params[]  = absint( $args['post_id'] );
		}

		$link_type_filter = isset( $args['link_type_filter'] ) ? sanitize_key( (string) $args['link_type_filter'] ) : '';
		if ( '' !== $link_type_filter && in_array( $link_type_filter, self::allowed_link_types(), true ) ) {
			$where   .= ' AND l.link_type = %s';
			$params[] = $link_type_filter;
		}

		if ( '' !== $args['search'] ) {
			$term     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where   .= ' AND l.link_url LIKE %s';
			$params[] = $term;
		}

		return array(
			'where'      => $where,
			'params'     => $params,
			'orderby'    => $orderby,
			'order'      => $order,
			'posts_join' => " LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id ",
		);
	}

	/**
	 * Paginated links query (core SQL).
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	private function query_links_paginated( $args ) {
		global $wpdb;

		$query_parts = $this->build_links_list_query_parts( $args );
		$where       = $query_parts['where'];
		$params      = $query_parts['params'];
		$orderby     = $query_parts['orderby'];
		$order       = $query_parts['order'];
		$posts_join  = $query_parts['posts_join'];

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$this->table} l{$posts_join}{$where}";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // $count_sql built with known table name and whitelisted orderby.
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $count_sql ); // $count_sql built with known table name, no user input.
		}

		$items_sql    = "SELECT l.*, p.post_title, p.post_status FROM {$this->table} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id $where ORDER BY l.$orderby $order LIMIT %d OFFSET %d";
		$items_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $items_params ) ); // $items_sql built from known table + whitelisted orderby/filter.

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Internal links pointing to draft/private/trash posts (batched scan + pagination).
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	private function get_links_unpublished_target( $args ) {
		if ( 'external' === $this->sanitize_link_scope( $args['scope'] ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$scan_args                    = $args;
		$scan_args['quality_filter']  = '';
		$scan_args['scope']           = 'internal';

		$per_page = max( 1, absint( $args['per_page'] ) );
		$paged    = max( 1, absint( $args['paged'] ) );
		$offset   = ( $paged - 1 ) * $per_page;

		return $this->scan_unpublished_target_matches( $scan_args, $offset, $per_page, false );
	}

	/**
	 * Scan internal link candidates in batches; match unpublished targets in PHP.
	 *
	 * @param array $args         List query args (scope should be internal).
	 * @param int   $list_offset  Match offset for pagination.
	 * @param int   $list_per_page Matches to return (0 = count only).
	 * @param bool  $count_only   When true, skip collecting row objects.
	 * @return array{items:array,total:int}
	 */
	private function scan_unpublished_target_matches( array $args, $list_offset, $list_per_page, $count_only = false ) {
		global $wpdb;

		$query_parts = $this->build_links_list_query_parts( $args );
		$where       = $query_parts['where'] . ' AND l.user_verified = 0';
		$params      = $query_parts['params'];
		$orderby     = $query_parts['orderby'];
		$order       = $query_parts['order'];
		$posts_join  = $query_parts['posts_join'];

		$batch_size  = 250;
		$cursor      = 0;
		$match_index = 0;
		$items       = array();
		$list_offset = max( 0, (int) $list_offset );
		$list_per_page = max( 0, (int) $list_per_page );

		while ( true ) {
			$items_sql = "SELECT l.*, p.post_title, p.post_status FROM {$this->table} l{$posts_join}{$where} ORDER BY l.{$orderby} {$order} LIMIT %d OFFSET %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$batch = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $batch_size, $cursor ) ) ) );
			if ( empty( $batch ) ) {
				break;
			}

			$cursor += count( $batch );
			$this->prime_unpublished_target_posts( $batch );

			foreach ( $batch as $link ) {
				if ( ! TSOLIIN_Quality::points_to_unpublished( $link ) ) {
					continue;
				}
				if ( ! $this->link_matches_filter( $link, $args['filter'] ) ) {
					continue;
				}

				if ( ! $count_only && $match_index >= $list_offset && count( $items ) < $list_per_page ) {
					$items[] = $link;
				}
				$match_index++;
			}
		}

		if ( empty( $args['post_id'] ) && '' === (string) $args['search'] && 'all' === sanitize_key( (string) $args['filter'] ) ) {
			set_transient( 'tsoliin_unpub_cnt_v3_all', $match_index, 15 * MINUTE_IN_SECONDS );
		}

		return array(
			'items' => $count_only ? array() : $items,
			'total' => $match_index,
		);
	}

	/**
	 * Prime wp_posts cache for attachment targets in a candidate batch.
	 *
	 * @param array $batch Link rows from SQL.
	 * @return void
	 */
	private function prime_unpublished_target_posts( array $batch ) {
		$post_ids = array();
		foreach ( $batch as $link ) {
			$link = (object) $link;
			$url  = isset( $link->link_url ) ? (string) $link->link_url : '';
			if ( '' === $url || false === strpos( $url, 'attachment_id=' ) ) {
				continue;
			}
			$post_id = isset( $link->post_id ) ? (int) $link->post_id : 0;
			$abs     = TSOLIIN_Scanner::resolve_to_absolute_url( $url, $post_id );
			if ( '' === $abs ) {
				continue;
			}
			$attachment_id = TSOLIIN_HTTP::parse_attachment_id_from_url( $abs );
			if ( $attachment_id > 0 ) {
				$post_ids[] = $attachment_id;
			}
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return;
		}

		_prime_post_caches( $post_ids, false, false );
	}

	/**
	 * Count internal links pointing to non-published posts.
	 *
	 * @param int $post_id Optional source post scope.
	 * @return int
	 */
	public function count_unpublished_targets( $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			$cached = get_transient( 'tsoliin_unpub_cnt_v3_all' );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$result = $this->scan_unpublished_target_matches(
			array(
				'filter'         => 'all',
				'quality_filter' => '',
				'scope'          => 'internal',
				'search'         => '',
				'orderby'        => 'id',
				'order'          => 'ASC',
				'post_id'        => $post_id,
			),
			0,
			0,
			true
		);
		$count = (int) $result['total'];
		if ( ! $post_id ) {
			set_transient( 'tsoliin_unpub_cnt_v3_all', $count, 15 * MINUTE_IN_SECONDS );
		}
		return $count;
	}

	/**
	 * Sanitize internal/external scope key.
	 *
	 * @param string $scope Raw scope.
	 * @return string
	 */
	public function sanitize_link_scope( $scope ) {
		$scope = sanitize_key( (string) $scope );
		return in_array( $scope, array( 'all', 'internal', 'external' ), true ) ? $scope : 'all';
	}

	/**
	 * Sanitize scope from a raw request value (GET/POST).
	 *
	 * @param mixed $raw Unslashed scope string from request input.
	 * @return string
	 */
	public function sanitize_scope_input( $raw ) {
		return $this->sanitize_link_scope( sanitize_key( (string) $raw ) );
	}

	/**
	 * Build SQL fragment for internal vs external link scope.
	 *
	 * @param string $scope  Scope key.
	 * @param string $column SQL column (l.link_url for joins, link_url for unaliased stats).
	 * @return array{where:string,params:array}
	 */
	private function build_link_scope_sql( $scope, $column = 'l.link_url' ) {
		$scope = $this->sanitize_link_scope( $scope );
		if ( 'all' === $scope ) {
			return array( 'where' => '', 'params' => array() );
		}

		$internal = TSOLIIN_HTTP::build_internal_link_scope_sql( $column );
		if ( 'internal' === $scope ) {
			return array(
				'where'  => ' AND ' . $internal['sql'],
				'params' => $internal['params'],
			);
		}

		$external = TSOLIIN_HTTP::build_external_link_scope_sql( $column );
		return array(
			'where'  => ' AND ' . $external['sql'],
			'params' => $external['params'],
		);
	}

	/**
	 * Whether a link row matches an internal/external scope.
	 *
	 * Single-row helper for AJAX after-edit payloads. List queries use
	 * build_link_scope_sql() so they never load the full table to classify.
	 *
	 * @param object|array $link  Link row.
	 * @param string       $scope Scope key.
	 * @return bool
	 */
	public function link_matches_scope( $link, $scope ) {
		$scope = $this->sanitize_link_scope( $scope );
		if ( 'all' === $scope ) {
			return true;
		}
		$link     = (object) $link;
		$post_id  = isset( $link->post_id ) ? (int) $link->post_id : 0;
		$link_url = isset( $link->link_url ) ? (string) $link->link_url : '';
		$internal = TSOLIIN_HTTP::is_internal_link_url( $link_url, $post_id );
		return ( 'internal' === $scope ) ? $internal : ! $internal;
	}

	/**
	 * Posts grouped by link issue counts (for the posts summary view).
	 *
	 * @param array $args Query args.
	 * @return array{items:array,total:int}
	 */
	public function get_posts_link_summary( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'          => 20,
			'paged'             => 1,
			'post_type'         => '',
			'exclude_post_type' => '',
		);
		$args     = wp_parse_args( $args, $defaults );
		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;
		$post_type = sanitize_key( (string) $args['post_type'] );
		$exclude_post_type = sanitize_key( (string) $args['exclude_post_type'] );

		$having    = 'HAVING broken > 0 OR redirect_count > 0 OR unchecked_count > 0';
		$type_sql  = '';
		$type_args = array();
		if ( '' !== $post_type && post_type_exists( $post_type ) ) {
			$type_sql    = ' AND p.post_type = %s';
			$type_args[] = $post_type;
		} elseif ( '' !== $exclude_post_type && post_type_exists( $exclude_post_type ) ) {
			$type_sql    = ' AND p.post_type != %s';
			$type_args[] = $exclude_post_type;
		}
		$base_from = "FROM {$this->table} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE l.post_id > 0 AND p.post_status != 'trash'{$type_sql} GROUP BY l.post_id, p.post_title";
		$select_agg = "SELECT l.post_id, p.post_title,
			COUNT(*) AS total_links,
			SUM(CASE WHEN l.last_checked IS NOT NULL AND l.is_broken = 1 AND l.user_verified = 0 THEN 1 ELSE 0 END) AS broken,
			SUM(CASE WHEN " . self::sql_redirect_match( 'l.' ) . " THEN 1 ELSE 0 END) AS redirect_count,
			SUM(CASE WHEN l.last_checked IS NULL AND l.user_verified = 0 THEN 1 ELSE 0 END) AS unchecked_count";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_inner = "SELECT COUNT(*) FROM (
			{$select_agg}
			{$base_from}
			{$having}
		) AS tsoliin_post_summary";
		if ( ! empty( $type_args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate; post_type via prepare().
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_inner, ...$type_args ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static aggregate subquery; table from prefix.
			$total = (int) $wpdb->get_var( $count_inner );
		}

		$items_sql = "{$select_agg}
			{$base_from}
			{$having}
			ORDER BY broken DESC, redirect_count DESC, total_links DESC
			LIMIT %d OFFSET %d";
		$prepare_args = array_merge( $type_args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static aggregate; LIMIT/OFFSET/post_type via prepare().
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$prepare_args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Get the first N unchecked links (last_checked IS NULL).
	 *
	 * Checked rows are updated in place, so the next call returns the next IDs without an SQL offset.
	 *
	 * @param int $limit    Batch size.
	 * @param int $post_id  When > 0, only links from this post.
	 */
	public function get_links_batch_for_check( $limit = 5, $post_id = 0 ) {
		global $wpdb;
		$limit   = max( 1, absint( $limit ) );
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE last_checked IS NULL AND post_id = %d ORDER BY id ASC LIMIT %d",
					$post_id,
					$limit
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE last_checked IS NULL ORDER BY id ASC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get links for the hourly cron: unchecked first, then stale broken, then stale OK.
	 *
	 * @param int $limit             Max rows.
	 * @param int $ok_stale_days     Minimum days since last check for OK / non-broken links.
	 * @param int $broken_stale_days Minimum days since last check for broken links (priority queue).
	 * @return object[]
	 */
	public function get_links_for_cron_check( $limit = 10, $ok_stale_days = 30, $broken_stale_days = 7 ) {
		global $wpdb;
		$limit           = max( 1, absint( $limit ) );
		$ok_stale_days   = max( 1, absint( $ok_stale_days ) );
		$broken_stale_days = max( 1, absint( $broken_stale_days ) );
		$ok_threshold    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $ok_stale_days . ' days' ) );
		$broken_threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $broken_stale_days . ' days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix; date thresholds via placeholders.
				"SELECT * FROM {$this->table} WHERE last_checked IS NULL
					OR ( is_broken = 1 AND user_verified = 0 AND last_checked < %s )
					OR ( ( is_broken = 0 OR user_verified = 1 ) AND last_checked IS NOT NULL AND last_checked < %s )
				ORDER BY
					CASE
						WHEN last_checked IS NULL THEN 0
						WHEN is_broken = 1 AND user_verified = 0 THEN 1
						ELSE 2
					END ASC,
					last_checked ASC,
					id ASC
				LIMIT %d",
				$broken_threshold,
				$ok_threshold,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count links eligible for the cron queue (same rules as get_links_for_cron_check).
	 *
	 * @param int $ok_stale_days     OK / general recheck interval.
	 * @param int $broken_stale_days Broken-link recheck interval.
	 * @return array{ unchecked: int, broken_stale: int, ok_stale: int, total: int }
	 */
	public function get_cron_queue_counts( $ok_stale_days = 30, $broken_stale_days = 7 ) {
		global $wpdb;
		$ok_stale_days     = max( 1, absint( $ok_stale_days ) );
		$broken_stale_days = max( 1, absint( $broken_stale_days ) );
		$cache_key         = $ok_stale_days . ':' . $broken_stale_days;
		if ( isset( self::$cron_queue_counts_cache[ $cache_key ] ) ) {
			return self::$cron_queue_counts_cache[ $cache_key ];
		}
		$ok_threshold     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $ok_stale_days . ' days' ) );
		$broken_threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $broken_stale_days . ' days' ) );

		// Reuse pending-check cache (same COUNT as unchecked).
		$unchecked = $this->get_pending_check_count( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$broken_stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE is_broken = 1 AND user_verified = 0 AND last_checked IS NOT NULL AND last_checked < %s",
				$broken_threshold
			)
		);
		$ok_stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE ( is_broken = 0 OR user_verified = 1 ) AND last_checked IS NOT NULL AND last_checked < %s",
				$ok_threshold
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Buckets are mutually exclusive (NULL vs stale broken vs stale OK).
		$total = $unchecked + $broken_stale + $ok_stale;

		$result = array(
			'unchecked'     => $unchecked,
			'broken_stale'  => $broken_stale,
			'ok_stale'      => $ok_stale,
			'total'         => $total,
		);
		self::$cron_queue_counts_cache[ $cache_key ] = $result;
		return $result;
	}

	/** @return int Rows with last_checked IS NULL (includes manually verified). */
	public function get_pending_check_count( $post_id = 0 ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( isset( self::$pending_check_count_cache[ $post_id ] ) ) {
			return self::$pending_check_count_cache[ $post_id ];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL AND post_id = %d",
					$post_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$pending_check_count_cache[ $post_id ] = $count;
		return $count;
	}

	/** @return int */
	public function get_unchecked_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL AND user_verified = 0" );
	}

	/**
	 * Whether a stored link still uses plain HTTP in the post (HTTP insecure tab takes priority over Redirect).
	 *
	 * @param string   $link_url      Stored link URL.
	 * @param int|bool $is_broken     Broken flag.
	 * @param int|bool $user_verified Manual lock flag.
	 * @return bool
	 */
	public static function row_is_http_insecure( $link_url, $is_broken = 0, $user_verified = 0 ) {
		return 0 === (int) $user_verified
			&& 0 === (int) $is_broken
			&& 0 === strpos( (string) $link_url, 'http://' );
	}

	/**
	 * SQL predicate for the Redirect tab/stats.
	 *
	 * Mutually exclusive with Broken, OK, Unchecked, HTTP insecure, and Manual locks:
	 * checked HTTPS (or non-http) redirects that are not broken.
	 *
	 * @param string $col_prefix Optional column prefix, e.g. `l.`.
	 * @return string
	 */
	public static function sql_redirect_match( $col_prefix = '' ) {
		$prefix = '';
		if ( '' !== (string) $col_prefix && preg_match( '/^[a-z_]+\.$/', (string) $col_prefix ) ) {
			$prefix = (string) $col_prefix;
		}

		return "( {$prefix}status_code IN (301,302,303,307,308) OR ( {$prefix}redirect_url IS NOT NULL AND {$prefix}redirect_url != '' ) )"
			. " AND {$prefix}last_checked IS NOT NULL"
			. " AND {$prefix}is_broken = 0"
			. " AND {$prefix}user_verified = 0"
			. " AND {$prefix}link_url NOT LIKE 'http://%'";
	}

	/**
	 * Whether a link row should appear under the Redirect tab.
	 *
	 * @param object|array $link Link row.
	 * @return bool
	 */
	public function row_counts_as_redirect_tab( $link ) {
		$link          = (object) $link;
		$status_code   = isset( $link->status_code ) ? (int) $link->status_code : 0;
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$redirect_url  = isset( $link->redirect_url ) ? (string) $link->redirect_url : '';
		$last_checked  = isset( $link->last_checked ) ? $link->last_checked : null;

		if ( null === $last_checked || '' === $last_checked ) {
			return false;
		}
		if ( 0 !== $user_verified || 1 === $is_broken ) {
			return false;
		}
		if ( self::row_is_http_insecure( $link_url, $is_broken, $user_verified ) ) {
			return false;
		}

		$has_redirect = in_array( $status_code, array( 301, 302, 303, 307, 308 ), true )
			|| '' !== $redirect_url;
		if ( ! $has_redirect ) {
			return false;
		}

		if ( '' !== $redirect_url && '' !== $link_url ) {
			static $redirect_http = null;
			if ( null === $redirect_http ) {
				$redirect_http = new TSOLIIN_HTTP();
			}
			if ( $redirect_http->is_transparent_redirect( $link_url, $redirect_url ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a link row belongs to a list-table filter tab.
	 *
	 * Mirrors the SQL conditions in get_links() / get_stats().
	 *
	 * @param object|array $link   Link row.
	 * @param string       $filter Filter key.
	 * @return bool
	 */
	public function link_matches_filter( $link, $filter ) {
		$filter = sanitize_key( (string) $filter );
		if ( 'all' === $filter || '' === $filter ) {
			return true;
		}

		$link          = (object) $link;
		$status_code   = isset( $link->status_code ) ? (int) $link->status_code : 0;
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$redirect_url  = isset( $link->redirect_url ) ? (string) $link->redirect_url : '';
		$last_checked  = isset( $link->last_checked ) ? $link->last_checked : null;

		switch ( $filter ) {
			case 'broken':
				return null !== $last_checked && '' !== (string) $last_checked && 1 === $is_broken && 0 === $user_verified;
			case 'redirect':
				return $this->row_counts_as_redirect_tab( $link );
			case 'ok':
				return null !== $last_checked
					&& '' !== (string) $last_checked
					&& 0 === $is_broken
					&& 200 === $status_code
					&& 0 === $user_verified
					&& 0 !== strpos( $link_url, 'http://' );
			case 'unchecked':
				return ( null === $last_checked || '' === (string) $last_checked ) && 0 === $user_verified;
			case 'http_insecure':
				return null !== $last_checked
					&& '' !== (string) $last_checked
					&& 0 === strpos( $link_url, 'http://' )
					&& 0 === $is_broken
					&& 0 === $user_verified;
			case 'manual_locked':
				return 1 === $user_verified;
		}

		return true;
	}

	/**
	 * Whether a link row matches an optional quality filter tab.
	 *
	 * @param object|array $link    Link row.
	 * @param string       $quality Quality filter key.
	 * @return bool
	 */
	public function link_matches_quality_filter( $link, $quality ) {
		$quality = sanitize_key( (string) $quality );
		if ( '' === $quality || 'all' === $quality ) {
			return true;
		}

		$link          = (object) $link;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$anchor_text   = isset( $link->anchor_text ) ? (string) $link->anchor_text : '';

		switch ( $quality ) {
			case 'empty_anchor':
				return 0 === $user_verified && TSOLIIN_Quality::is_empty_anchor( $anchor_text, $link_url );
			case 'generic_anchor':
				return 0 === $user_verified && TSOLIIN_Quality::is_generic_anchor( $anchor_text );
			case 'unpublished_target':
				return TSOLIIN_Quality::points_to_unpublished( $link );
		}

		return true;
	}

	/**
	 * Clear cached aggregate stats and broken URL lists (call after writes that change counts).
	 */
	public static function clear_stats_cache() {
		self::$stats_cache                 = array();
		self::$pending_check_count_cache   = array();
		self::$cron_queue_counts_cache     = array();
		self::$broken_urls_by_post_cache = array();
		delete_transient( 'tsoliin_unpub_cnt_all' );
		delete_transient( 'tsoliin_unpub_cnt_v2_all' );
		delete_transient( 'tsoliin_unpub_cnt_v3_all' );
	}

	/**
	 * Broken link_url values for a post (for frontend rel="nofollow"; cached per request).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function get_broken_link_urls_for_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}
		if ( array_key_exists( $post_id, self::$broken_urls_by_post_cache ) ) {
			return self::$broken_urls_by_post_cache[ $post_id ];
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$urls = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT link_url FROM {$this->table}
				WHERE post_id = %d
				  AND last_checked IS NOT NULL
				  AND is_broken = 1
				  AND user_verified = 0",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$broken_urls_by_post_cache[ $post_id ] = is_array( $urls ) ? array_map( 'strval', $urls ) : array();
		return self::$broken_urls_by_post_cache[ $post_id ];
	}

	/**
	 * Aggregate dashboard counts, optionally scoped to internal/external and one post.
	 *
	 * Status buckets are mutually exclusive (except quality filters, which are orthogonal):
	 * - unchecked:      last_checked IS NULL, not manual lock
	 * - broken:         checked + is_broken, not lock
	 * - redirect:       checked + redirect, not broken, not http://, not lock
	 * - http_insecure:  checked + http:// + not broken, not lock (includes HTTP redirects)
	 * - ok:             checked + HTTP 200 + not http:// + not broken, not lock
	 * - manual_locked:  user_verified = 1
	 * Sum of those ≈ total (residual = other checked codes, e.g. 403 without is_broken).
	 *
	 * @param string $scope   all|internal|external.
	 * @param int    $post_id 0 = whole site.
	 * @return array<string,int>
	 */
	private function query_aggregate_stats( $scope = 'all', $post_id = 0 ) {
		$scope   = $this->sanitize_link_scope( $scope );
		$post_id = absint( $post_id );
		$cache_key = ( $post_id ? 'post_' . $post_id : 'site' ) . '_' . $scope;
		if ( isset( self::$stats_cache[ $cache_key ] ) ) {
			return self::$stats_cache[ $cache_key ];
		}

		global $wpdb;
		$generic = TSOLIIN_Quality::build_generic_anchor_count_expr();
		$where   = ' WHERE 1=1';
		$params  = array_merge( array( 'http://%', 'http://%' ), $generic['params'] );

		if ( $post_id > 0 ) {
			$where   .= ' AND post_id = %d';
			$params[] = $post_id;
		}

		$scope_sql = $this->build_link_scope_sql( $scope, 'link_url' );
		if ( '' !== $scope_sql['where'] ) {
			$where  .= $scope_sql['where'];
			$params  = array_merge( $params, $scope_sql['params'] );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static SQL fragments; values passed via $wpdb->prepare().
		$sql = "SELECT COUNT(*) AS total,"
			. " SUM(CASE WHEN last_checked IS NOT NULL AND is_broken=1 AND user_verified=0 THEN 1 ELSE 0 END) AS broken,"
			. " SUM(CASE WHEN " . self::sql_redirect_match() . " THEN 1 ELSE 0 END) AS redirect,"
			. " SUM(CASE WHEN last_checked IS NOT NULL AND is_broken=0 AND status_code=200 AND link_url NOT LIKE %s AND user_verified=0 THEN 1 ELSE 0 END) AS ok,"
			. " SUM(CASE WHEN last_checked IS NULL AND user_verified=0 THEN 1 ELSE 0 END) AS unchecked,"
			. " SUM(CASE WHEN last_checked IS NOT NULL AND link_url LIKE %s AND is_broken=0 AND user_verified=0 THEN 1 ELSE 0 END) AS http_insecure,"
			. " SUM(CASE WHEN user_verified=1 THEN 1 ELSE 0 END) AS manual_locked,"
			. " " . TSOLIIN_Quality::build_empty_anchor_count_expr() . " AS empty_anchor,"
			. " {$generic['expr']} AS generic_anchor"
			. " FROM {$this->table}{$where}";
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql,
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$defaults = array(
			'total'               => 0,
			'broken'              => 0,
			'redirect'            => 0,
			'ok'                  => 0,
			'unchecked'           => 0,
			'http_insecure'       => 0,
			'manual_locked'       => 0,
			'empty_anchor'        => 0,
			'generic_anchor'      => 0,
			'unpublished_target'  => 0,
		);
		$stats = $row ? array_map( 'absint', $row ) : $defaults;
		if ( 'external' === $scope ) {
			$stats['unpublished_target'] = 0;
		} elseif ( $post_id > 0 ) {
			$stats['unpublished_target'] = $this->count_unpublished_targets( $post_id );
		} elseif ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$stats['unpublished_target'] = $this->count_unpublished_targets( 0 );
		}
		self::$stats_cache[ $cache_key ] = $stats;
		return $stats;
	}

	/**
	 * Site-wide dashboard counts.
	 *
	 * @param string $scope Optional internal/external scope (SQL, not PHP row scan).
	 * @return array
	 */
	public function get_stats( $scope = 'all' ) {
		return $this->query_aggregate_stats( $scope, 0 );
	}

	/** @return int */
	public function get_scanned_post_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$this->table}" );
	}

	/**
	 * DB self-test: insert + delete a dummy row.
	 */
	public function self_test() {
		global $wpdb;
		$result = array( 'table_exists' => false, 'insert_ok' => false, 'error' => '' );
		if ( ! $this->table_exists() ) {
			$result['error'] = 'Table missing: ' . $this->table;
			return $result;
		}
		$result['table_exists'] = true;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ins = $wpdb->insert(
			$this->table,
			array( 'post_id' => 0, 'link_url' => 'https://tsoliin-test.invalid', 'anchor_text' => '__tsoliin_test__', 'status_code' => 0, 'redirect_url' => '', 'is_broken' => 0, 'link_type' => 'link', 'source_key' => '', 'last_checked' => null, 'date_found' => current_time( 'mysql', true ) ),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $ins ) {
			$result['error'] = $wpdb->last_error ?: 'Insert failed';
			return $result;
		}
		$result['insert_ok'] = true;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'anchor_text' => '__tsoliin_test__' ), array( '%s' ) );
		return $result;
	}

	/**
	 * Count links that need checking (alias for cron queue total).
	 *
	 * @param int $stale_days OK-link recheck interval (legacy param name).
	 * @return int
	 */
	public function count_stale_links( $stale_days = 7 ) {
		$settings = class_exists( 'TSOLIIN_Schedule' ) ? TSOLIIN_Schedule::get_settings() : array();
		$ok_days  = isset( $settings['recheck_days'] ) ? (int) $settings['recheck_days'] : absint( $stale_days );
		$broken   = isset( $settings['broken_recheck_days'] ) ? (int) $settings['broken_recheck_days'] : 7;
		return (int) $this->get_cron_queue_counts( $ok_days, $broken )['total'];
	}

	public function cleanup_misclassified_plain_image_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$this->table}
			SET link_type = 'image'
			WHERE link_type = 'plain'
			  AND link_url REGEXP '\\.(jpe?g|png|gif|webp|avif|svg|bmp|ico)([?#]|$)'
			  AND link_url REGEXP '/(wp-content/)?uploads/'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove WordPress attachment page URLs (/attachment/slug, ?attachment_id=) from stored rows.
	 *
	 * @return int Rows deleted.
	 */
	public function cleanup_attachment_permalink_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url, post_id FROM {$this->table}
			WHERE link_url LIKE '%/attachment/%'
			   OR link_url LIKE '%attachment_id=%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $rows as $row ) {
			if ( ! TSOLIIN_HTTP::is_attachment_permalink_url( (string) $row->link_url, (int) $row->post_id ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $wpdb->delete( $this->table, array( 'id' => (int) $row->id ), array( '%d' ) ) ) {
				++$deleted;
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $deleted;
	}

	/**
	 * Clean up trivial trailing-slash redirect_url records from old scans.
	 * Called by maybe_upgrade_db() on version bump.
	 */
	public function cleanup_trivial_redirects() {
		global $wpdb;
		// Find records where redirect_url = link_url + "/" only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$this->table}
			SET status_code = 200, redirect_url = '', is_broken = 0
			WHERE redirect_url != ''
			  AND ( CONCAT(link_url, '/') = redirect_url
			     OR CONCAT(RTRIM(REPLACE(link_url, '/', '')), '/') = RTRIM(REPLACE(redirect_url, '/', '')) )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get stats scoped to a single post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $scope   Optional internal/external scope (SQL).
	 * @return array
	 */
	public function get_stats_for_post( $post_id, $scope = 'all' ) {
		return $this->query_aggregate_stats( $scope, absint( $post_id ) );
	}

	/**
	 * Clean up trivial query-string-only redirect records (e.g. ?ucbcb=1 tracking params).
	 * Called on version upgrade. Updates status_code to 200 and clears redirect_url
	 * when the redirect destination is the original URL with only a query string appended.
	 */
	public function cleanup_querystring_redirects() {
		global $wpdb;
		// Find rows where redirect_url starts with link_url + "?" (same URL, query added).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"UPDATE {$this->table}
			SET status_code = 200, redirect_url = '', is_broken = 0
			WHERE redirect_url != ''
			  AND LOCATE( CONCAT(link_url, '?'), redirect_url ) = 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Try to acquire a short-lived transient lock (atomic INSERT on timeout row).
	 *
	 * @param string $key Lock key without _transient_ prefix.
	 * @param int    $ttl Seconds until the lock expires.
	 * @return bool True when acquired.
	 */
	public function acquire_transient_lock( $key, $ttl = 45 ) {
		global $wpdb;

		$key = sanitize_key( (string) $key );
		if ( '' === $key || $ttl <= 0 ) {
			return false;
		}

		$timeout_name   = '_transient_timeout_' . $key;
		$transient_name = '_transient_' . $key;
		$now            = time();
		$expire         = $now + (int) $ttl;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$timeout_name,
				$now
			)
		);
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
				$timeout_name,
				$expire
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// INSERT IGNORE: 1 = lock acquired, 0 = already held (not a DB error).
		if ( ! $inserted ) {
			return false;
		}

		update_option( $transient_name, '1', false );
		wp_cache_delete( $key, 'transient' );
		wp_cache_delete( 'timeout_' . $key, 'transient' );
		return true;
	}

	/**
	 * Release a transient lock acquired via acquire_transient_lock().
	 *
	 * @param string $key Lock key without _transient_ prefix.
	 * @return void
	 */
	public function release_transient_lock( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return;
		}
		delete_transient( $key );
	}

	/**
	 * Throttled pass: normalize transparent / false-positive redirect rows in the DB.
	 *
	 * @return int Rows updated (0 when skipped via transient).
	 */
	public function maybe_cleanup_transparent_redirects() {
		if ( null !== self::$transparent_rd_cleanup_attempted ) {
			return self::$transparent_rd_cleanup_attempted;
		}

		if ( ! $this->acquire_transient_lock( 'tsoliin_transparent_rd_cleanup', 30 ) ) {
			self::$transparent_rd_cleanup_attempted = 0;
			return 0;
		}

		self::$transparent_rd_cleanup_attempted = $this->cleanup_transparent_redirects();
		return self::$transparent_rd_cleanup_attempted;
	}

	/**
	 * Fix legacy rows stored as -1 (ignore list) when the URL is not on the ignore list.
	 *
	 * @param bool $run_http When true, re-check safe URLs (cron/upgrade only).
	 * @param int  $limit    Max HTTP re-checks per call.
	 * @return int Rows updated.
	 */
	public function cleanup_mislabeled_skip_rows( $run_http = false, $limit = 20 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url, post_id FROM {$this->table} WHERE status_code = -1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$http    = new TSOLIIN_HTTP();
		$updated = 0;
		$checked = 0;
		$limit   = max( 1, absint( $limit ) );

		foreach ( $rows as $row ) {
			$url = (string) $row->link_url;
			if ( TSOLIIN_HTTP::is_ignored_url( $url ) ) {
				continue;
			}

			if ( TSOLIIN_HTTP::hostname_has_no_dns( $url ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$changed = $wpdb->update(
					$this->table,
					array(
						'status_code'  => -2,
						'redirect_url' => '',
						'is_broken'    => 1,
					),
					array( 'id' => (int) $row->id ),
					array( '%d', '%s', '%d' ),
					array( '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( false !== $changed ) {
					$updated++;
				}
				continue;
			}

			if ( ! TSOLIIN_HTTP::is_safe_remote_url( $url ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$changed = $wpdb->update(
					$this->table,
					array(
						'status_code'  => -7,
						'redirect_url' => '',
						'is_broken'    => 0,
					),
					array( 'id' => (int) $row->id ),
					array( '%d', '%s', '%d' ),
					array( '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( false !== $changed ) {
					$updated++;
				}
				continue;
			}

			if ( ! $run_http || $checked >= $limit ) {
				continue;
			}

			$r = $http->check( $url, (int) $row->post_id );
			$this->update_check_result( (int) $row->id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
			$updated++;
			$checked++;
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Normalize rows whose redirect is transparent (YouTube youtu.be→watch, trailing slash, CDN, etc.).
	 *
	 * @return int Rows updated.
	 */
	public function cleanup_transparent_redirects() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url, redirect_url FROM {$this->table} WHERE redirect_url != ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$http    = new TSOLIIN_HTTP();
		$updated = 0;
		foreach ( $rows as $row ) {
			$orig  = (string) $row->link_url;
			$redir = (string) $row->redirect_url;
			if ( '' === $orig || '' === $redir || ! $http->is_transparent_redirect( $orig, $redir ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->update(
				$this->table,
				array(
					'status_code'  => 200,
					'redirect_url' => '',
					'is_broken'    => 0,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $changed ) {
				$updated++;
			}
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Reclassify logout/action URLs that were previously checked as 403 bot-blocks.
	 *
	 * @return int Rows updated.
	 */
	public function cleanup_action_url_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url FROM {$this->table}
			WHERE link_url LIKE '%wp-login.php%' OR link_url LIKE '%action=logout%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			if ( ! TSOLIIN_HTTP::is_action_url( (string) $row->link_url ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->update(
				$this->table,
				array(
					'status_code'  => -6,
					'redirect_url' => '',
					'is_broken'    => 0,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $changed ) {
				$updated++;
			}
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Reclassify NXDOMAIN / empty-DNS rows that were stored as -7 (SSRF block).
	 *
	 * @return int Rows updated.
	 */
	public function cleanup_blocked_dns_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url FROM {$this->table} WHERE status_code = -7"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			$url = (string) $row->link_url;
			if ( ! TSOLIIN_HTTP::hostname_has_no_dns( $url ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->update(
				$this->table,
				array(
					'status_code'  => -2,
					'redirect_url' => '',
					'is_broken'    => 1,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $changed ) {
				$updated++;
			}
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Count hard-broken links (broken and without redirect destination).
	 *
	 * @return int
	 */
	public function count_hard_broken_links() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}
			WHERE last_checked IS NOT NULL
			  AND is_broken = 1
			  AND user_verified = 0
			  AND (redirect_url = '' OR redirect_url IS NULL)
			  AND status_code NOT BETWEEN 300 AND 399"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get hard-broken links (broken and without redirect destination).
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function get_hard_broken_links( $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, absint( $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.post_id, l.link_url, l.anchor_text, l.status_code, l.last_checked, p.post_title
				FROM {$this->table} l
				LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE l.last_checked IS NOT NULL
				  AND l.is_broken = 1
				  AND l.user_verified = 0
				  AND (l.redirect_url = '' OR l.redirect_url IS NULL)
				  AND l.status_code NOT BETWEEN 300 AND 399
				ORDER BY l.last_checked DESC, l.id DESC
				LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
