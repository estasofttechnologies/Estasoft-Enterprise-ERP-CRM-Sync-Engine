<?php
/**
 * Keeps calls to one system under its documented limits, across requests and workers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Queue\RateStore;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The queue's rate limiter counts item starts. This counts HTTP calls: one connection gets one slot per `1 / rate` seconds, kept in
 * the engine's shared RateStore under its own key (`api:{id}`) so any number of workers share it. A wait longer than a few seconds is not
 * slept off inside a request: the call stops with SYNC-RATE-001 and the wait, and the queue tries again later. A documented daily cap
 * is counted per UTC day and stops with SYNC-RATE-001 until the next day instead of hammering the system.
 */
final class RequestPacer {

	/**
	 * Longest wait slept inside one request, in seconds.
	 */
	public const MAX_SLEEP = 5.0;

	/**
	 * Attempts at the compare-and-set before falling back to one interval.
	 */
	private const RETRIES = 8;

	/**
	 * Receives microseconds.
	 *
	 * @var \Closure
	 */
	private \Closure $sleep;

	/**
	 * Set up with what it depends on.
	 *
	 * @param RateStore     $store Shared counters.
	 * @param Clock         $clock Time source.
	 * @param \Closure|null $sleep Receives microseconds; defaults to usleep. Tests pass a recorder.
	 */
	public function __construct( private readonly RateStore $store, private readonly Clock $clock, ?\Closure $sleep = null ) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/**
	 * Wait for the next free slot of a connection, or stop when the wait is too long or the daily cap is used up.
	 *
	 * @param int            $connectionId Connection.
	 * @param RateLimitHints $limits       The system's limits.
	 * @param string         $system       System name for the message.
	 *
	 * @throws ApiException With SYNC-RATE-001 and the wait in seconds.
	 */
	public function wait( int $connectionId, RateLimitHints $limits, string $system = '' ): void {
		// The time slot is reserved first and the daily quota is counted only once the call is allowed; whichever stops the call gives
		// the other back, so a refused call costs neither a slot nor a part of the quota.
		$slot = $this->reserve( 'api:' . $connectionId, $limits->intervalSeconds() );
		if ( $slot['wait'] > self::MAX_SLEEP ) {
			$this->giveBack( 'api:' . $connectionId, $slot );
			$seconds = (int) ceil( $slot['wait'] );
			throw new ApiException(
				new ConnectorError(
					ErrorCode::Rate001,
					array(
						'system' => $system,
						'n'      => $seconds,
					),
					null,
					$seconds,
					null,
					'paced'
				)
			);
		}
		try {
			$this->countDay( $connectionId, $limits, $system );
		} catch ( ApiException $exception ) {
			$this->giveBack( 'api:' . $connectionId, $slot );
			throw $exception;
		}
		if ( $slot['wait'] > 0.0 ) {
			( $this->sleep )( (int) round( $slot['wait'] * 1000000 ) );
		}
	}

	/**
	 * Calls left today under the documented daily cap, or null when there is no cap.
	 *
	 * @param int            $connectionId Connection.
	 * @param RateLimitHints $limits       Limits.
	 */
	public function remainingToday( int $connectionId, RateLimitHints $limits ): ?int {
		if ( null === $limits->requestsPerDay ) {
			return null;
		}
		return max( 0, $limits->requestsPerDay - (int) ( $this->store->get( $this->dayKey( $connectionId ) ) ?? 0 ) );
	}

	/**
	 * Count one call against the daily cap, or stop when it is used up (nothing is counted then).
	 *
	 * @param int            $connectionId Connection.
	 * @param RateLimitHints $limits       Limits.
	 * @param string         $system       System name.
	 *
	 * @throws ApiException With SYNC-RATE-001 when the cap is used up.
	 */
	private function countDay( int $connectionId, RateLimitHints $limits, string $system ): void {
		if ( null === $limits->requestsPerDay ) {
			return;
		}
		$key = $this->dayKey( $connectionId );
		for ( $i = 0; $i < self::RETRIES; ++$i ) {
			$count = $this->store->get( $key );
			if ( (int) ( $count ?? 0 ) >= $limits->requestsPerDay ) {
				$now     = $this->clock->now();
				$seconds = max( 60, (int) $now->setTime( 0, 0, 0 )->modify( '+1 day' )->format( 'U' ) - (int) $now->format( 'U' ) );
				throw new ApiException(
					new ConnectorError(
						ErrorCode::Rate001,
						array(
							'system' => $system,
							'n'      => $seconds,
						),
						429,
						$seconds,
						null,
						'daily cap'
					)
				);
			}
			if ( $this->store->compareAndSet( $key, $count, (float) ( (int) ( $count ?? 0 ) + 1 ) ) ) {
				return;
			}
		}
	}

	/**
	 * The counter of the current UTC day.
	 *
	 * @param int $connectionId Connection.
	 */
	private function dayKey( int $connectionId ): string {
		return 'apiday:' . $connectionId . ':' . $this->clock->now()->format( 'Ymd' );
	}

	/**
	 * Reserve the next slot of a counter.
	 *
	 * @param string $key      Counter name.
	 * @param float  $interval Seconds between two slots.
	 * @return array{wait:float,previous:float|null,taken:float|null} Seconds to wait, and what the counter held before and after, to give the slot back.
	 */
	private function reserve( string $key, float $interval ): array {
		for ( $i = 0; $i < self::RETRIES; ++$i ) {
			$now   = (float) $this->clock->now()->format( 'U.u' );
			$next  = $this->store->get( $key );
			$start = max( $next ?? $now, $now );
			if ( $this->store->compareAndSet( $key, $next, $start + $interval ) ) {
				return array(
					'wait'     => max( 0.0, $start - $now ),
					'previous' => $next,
					'taken'    => $start + $interval,
				);
			}
		}
		return array(
			'wait'     => $interval,
			'previous' => null,
			'taken'    => null,
		);
	}

	/**
	 * Give a reserved slot back, as long as nobody reserved a later one meanwhile (then it stays used, which only delays a call).
	 *
	 * @param string                                                 $key  Counter name.
	 * @param array{wait:float,previous:float|null,taken:float|null} $slot What reserve() returned.
	 */
	private function giveBack( string $key, array $slot ): void {
		if ( null === $slot['taken'] ) {
			return;
		}
		$now = (float) $this->clock->now()->format( 'U.u' );
		$this->store->compareAndSet( $key, $slot['taken'], $slot['previous'] ?? $now );
	}
}
