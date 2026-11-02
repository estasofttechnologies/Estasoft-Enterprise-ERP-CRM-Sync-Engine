<?php
/**
 * Where the rate limiter keeps its counters.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * A single number per key with compare-and-set, enough for a token bucket that several workers share.
 */
interface RateStore {

	/**
	 * Read a value.
	 *
	 * @param string $key Counter name.
	 * @return float|null Null when it was never set.
	 */
	public function get( string $key ): ?float;

	/**
	 * Replace a value only if it is still what the caller read.
	 *
	 * @param string     $key      Counter name.
	 * @param float|null $expected The value the caller read (null when it did not exist).
	 * @param float      $new      The new value.
	 * @return bool True when the value was replaced; false when another worker changed it first.
	 */
	public function compareAndSet( string $key, ?float $expected, float $new ): bool;
}
