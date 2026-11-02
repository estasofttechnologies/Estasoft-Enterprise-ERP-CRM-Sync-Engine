<?php
/**
 * Rate counters in a persistent object cache (Redis, Memcached).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Used only when `wp_using_ext_object_cache()` is true. A short lock made with `wp_cache_add`, which is atomic in
 * the persistent backends, makes compare-and-set safe between workers.
 */
final class ObjectCacheRateStore implements RateStore {

	/**
	 * Cache group.
	 */
	private const GROUP = 'estasoft_sync_rate';

	/**
	 * Read a value.
	 *
	 * @param string $key Counter name.
	 */
	public function get( string $key ): ?float {
		$value = wp_cache_get( $key, self::GROUP );
		return false === $value ? null : (float) $value;
	}

	/**
	 * Replace a value only if it is still what the caller read.
	 *
	 * @param string     $key      Counter name.
	 * @param float|null $expected Value read earlier.
	 * @param float      $new      New value.
	 */
	public function compareAndSet( string $key, ?float $expected, float $new ): bool {
		$lock = $key . ':lock';
		if ( ! wp_cache_add( $lock, 1, self::GROUP, 2 ) ) {
			return false;
		}
		try {
			$current = $this->get( $key );
			$same    = ( null === $current && null === $expected ) || ( null !== $current && null !== $expected && abs( $current - $expected ) < 0.0000005 );
			if ( ! $same ) {
				return false;
			}
			return (bool) wp_cache_set( $key, sprintf( '%.6F', $new ), self::GROUP, 3600 );
		} finally {
			wp_cache_delete( $lock, self::GROUP );
		}
	}
}
