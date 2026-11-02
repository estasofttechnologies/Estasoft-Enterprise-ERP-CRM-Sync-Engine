<?php
/**
 * Transients over the WordPress API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\Transients;

/**
 * `get_transient()`, `set_transient()` and `delete_transient()`. Names start with the plugin prefix so uninstall removes them.
 */
final class WpTransients implements Transients {

	/**
	 * Object cache group of the atomic counters.
	 */
	private const GROUP = 'estasoft_sync';

	/**
	 * Read a value.
	 *
	 * @param string $key Name.
	 * @return mixed False when absent or expired.
	 */
	public function get( string $key ): mixed {
		return get_transient( $key );
	}

	/**
	 * Write a value.
	 *
	 * @param string $key     Name.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $seconds ): void {
		set_transient( $key, $value, max( 1, $seconds ) );
	}

	/**
	 * Add one to a counter. With an external object cache the step is atomic (`wp_cache_incr`); otherwise it is a read and a write
	 * of a transient.
	 *
	 * @param string $key     Name.
	 * @param int    $seconds Lifetime in seconds.
	 * @return int The count after adding one.
	 */
	public function increment( string $key, int $seconds ): int {
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, max( 1, $seconds ) );
			$count = wp_cache_incr( $key, 1, self::GROUP );
			if ( false !== $count ) {
				return (int) $count;
			}
		}
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, max( 1, $seconds ) );
		return $count;
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Name.
	 */
	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
