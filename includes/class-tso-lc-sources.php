<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Existing file names kept for backwards compatibility.
/**
 * Third-party link source registry (Phase 2).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.9.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Sources
 */
class TSOLIIN_Sources {

	/**
	 * Callable>.
	 *
	 * @var array<string,
	 */
	private static $sources = array();

	/**
	 * Register a link source collector callback.
	 *
	 * Callback signature: function ( TSOLIIN_Scanner $scanner, int $limit ): array
	 * Each item: post_id (int), url (string), anchor (string), link_type (string), source_key (string).
	 *
	 * @param string   $id       Unique source ID (a-z0-9-).
	 * @param callable $callback Collector.
	 * @return bool
	 */
	public static function register( $id, $callback ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id || ! is_callable( $callback ) ) {
			return false;
		}
		self::$sources[ $id ] = $callback;
		return true;
	}

	/**
	 * Get the registered.
	 *
	 * @return array<string, callable>
	 */
	public static function get_registered() {
		return self::$sources;
	}

	/**
	 * Invoke registered collectors during a scan batch.
	 *
	 * @param TSOLIIN_Scanner $scanner Scanner instance.
	 * @param int             $limit   Max items per collector.
	 * @return int Links upserted.
	 */
	public static function scan_registered_batch( TSOLIIN_Scanner $scanner, $limit = 20 ) {
		$limit = max( 1, absint( $limit ) );
		$found = 0;

		/**
		 * Fires before registered link sources are scanned.
		 *
		 * @param TSOLIIN_Scanner $scanner Scanner instance.
		 * @param int             $limit   Batch limit per source.
		 */
		do_action( 'tsoliin_before_scan_registered_sources', $scanner, $limit );

		foreach ( self::$sources as $source_id => $callback ) {
			$items = call_user_func( $callback, $scanner, $limit );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( $scanner->upsert_external_item( $item, (string) $source_id ) ) {
					++$found;
				}
			}
		}

		/**
		 * Fires after registered link sources were scanned.
		 *
		 * @param TSOLIIN_Scanner $scanner Scanner instance.
		 * @param int             $limit   Batch limit per source.
		 * @param int             $found   Links upserted this batch.
		 */
		do_action( 'tsoliin_after_scan_registered_sources', $scanner, $limit, $found );

		return $found;
	}
}

/**
 * Register a third-party link source (Phase 2 API).
 *
 * @param string   $id       Unique source ID.
 * @param callable $callback Collector returning link item arrays.
 * @return bool
 */
function tsoliin_register_link_source( $id, $callback ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Bootstrap helpers live next to the main class by design.
	return TSOLIIN_Sources::register( $id, $callback );
}
