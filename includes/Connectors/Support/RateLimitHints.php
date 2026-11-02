<?php
/**
 * Limits a system documents for its API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Requests per second and concurrency drive the pacing of every call; the per-minute and per-day figures are the vendor's documented
 * caps, used to stop with SYNC-RATE-001 and a wait instead of hammering a system that already said no.
 */
final class RateLimitHints {

	/**
	 * Set up with what it depends on.
	 *
	 * @param float    $requestsPerSecond Smallest sustained rate between two calls of one connection (1.0 = one call a second).
	 * @param int      $concurrency       Calls the system accepts at the same time (the engine sends one at a time per connection).
	 * @param int|null $requestsPerMinute Documented cap per minute, or null.
	 * @param int|null $requestsPerDay    Documented cap per day (UTC day), or null.
	 */
	public function __construct(
		public readonly float $requestsPerSecond = 1.0,
		public readonly int $concurrency = 1,
		public readonly ?int $requestsPerMinute = null,
		public readonly ?int $requestsPerDay = null,
	) {
	}

	/**
	 * The shape the connector contract returns from rateLimitHints().
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function toArray(): array {
		return array(
			'requests_per_second' => max( 0.01, $this->requestsPerSecond ),
			'concurrency'         => max( 1, $this->concurrency ),
		);
	}

	/**
	 * The seconds between two calls that keep every documented cap, whichever is tighter.
	 */
	public function intervalSeconds(): float {
		$interval = 1.0 / max( 0.01, $this->requestsPerSecond );
		if ( null !== $this->requestsPerMinute && $this->requestsPerMinute > 0 ) {
			$interval = max( $interval, 60.0 / $this->requestsPerMinute );
		}
		return $interval;
	}
}
