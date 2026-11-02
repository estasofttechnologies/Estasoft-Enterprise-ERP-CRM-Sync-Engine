<?php
/**
 * Token bucket: keeps requests to a system under its limit.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Each reservation takes the next free slot (the generic cell rate algorithm, a token bucket with one token): slots are
 * `1 / rate` seconds apart. The reservation is a compare-and-set on a shared counter, so any number of workers share
 * one limit. The caller waits as long as `acquire()` says. There is a limit per connection and an optional one for
 * all connections together.
 *
 * What is counted. The worker takes one slot for each ITEM it starts, so the setting "requests per second" is a limit
 * on item starts per second, not on HTTP calls. One item can make several calls to the system (look for an existing
 * record, send, read back), so the number of calls per second can be higher than the setting. A connector that has to
 * keep to a hard limit of the other system paces its own HTTP calls; the queue does not count them for it.
 */
final class RateLimiter {

	/**
	 * Attempts at the compare-and-set before giving up.
	 */
	private const RETRIES = 8;

	/**
	 * @param RateStore $store Shared counters.
	 * @param Clock     $clock Time source.
	 */
	public function __construct(
		private readonly RateStore $store,
		private readonly Clock $clock
	) {
	}

	/**
	 * Reserve a slot for one item start (see the class description).
	 *
	 * @param int   $connectionId     Connection.
	 * @param float $perSecond        Requests per second for this connection.
	 * @param float $globalPerSecond  Requests per second for all connections; 0 for no global limit.
	 * @return float Seconds to wait before sending; 0 when the request may go now.
	 */
	public function acquire( int $connectionId, float $perSecond, float $globalPerSecond = 0.0 ): float {
		$wait = $this->reserve( 'conn:' . $connectionId, $perSecond );
		if ( $globalPerSecond > 0.0 ) {
			$wait = max( $wait, $this->reserve( 'global', $globalPerSecond ) );
		}
		return $wait;
	}

	/**
	 * Reserve the next slot of one counter.
	 *
	 * @param string $key       Counter name.
	 * @param float  $perSecond Requests per second.
	 * @return float Seconds to wait.
	 */
	private function reserve( string $key, float $perSecond ): float {
		$interval = 1.0 / max( 0.01, $perSecond );
		for ( $i = 0; $i < self::RETRIES; ++$i ) {
			$now    = (float) $this->clock->now()->format( 'U.u' );
			$next   = $this->store->get( $key );
			$start  = max( $next ?? $now, $now );
			if ( $this->store->compareAndSet( $key, $next, $start + $interval ) ) {
				return max( 0.0, $start - $now );
			}
		}
		// Many workers collided: fall back to waiting one interval rather than sending without a limit.
		return $interval;
	}
}
