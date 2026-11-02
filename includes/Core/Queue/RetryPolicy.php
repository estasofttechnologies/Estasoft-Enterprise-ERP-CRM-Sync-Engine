<?php
/**
 * When to try again.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Delays of 1 minute, 5 minutes, 1 hour and 24 hours (five tries in all), each plus 0 to 20 seconds of jitter. When the
 * system sent `Retry-After` and it is longer, it wins, capped at 24 hours. The schedule can be changed per
 * connection and by the filter `estasoft_sync_retry_schedule`.
 */
final class RetryPolicy {

	/**
	 * Most jitter in seconds.
	 */
	public const MAX_JITTER = 20;

	/**
	 * Longest wait asked for by the system, in seconds.
	 */
	public const MAX_RETRY_AFTER = 86400;

	/**
	 * Returns a whole number of seconds from 0 to MAX_JITTER.
	 *
	 * @var \Closure
	 */
	private \Closure $jitter;

	/**
	 * Set up the retry policy.
	 *
	 * @param Clock         $clock  Time source.
	 * @param \Closure|null $jitter Returns the jitter in seconds; random by default.
	 */
	public function __construct( private readonly Clock $clock, ?\Closure $jitter = null ) {
		$this->jitter = $jitter ?? static fn(): int => random_int( 0, self::MAX_JITTER );
	}

	/**
	 * The delays for a connection, after the filter.
	 *
	 * @param array<string,mixed> $settings Connection settings.
	 * @return array<int,int> Seconds.
	 */
	public function schedule( array $settings ): array {
		$delays = isset( $settings['retry_delays_seconds'] ) && is_array( $settings['retry_delays_seconds'] ) ? $settings['retry_delays_seconds'] : ConnectionSettings::DEFAULT_DELAYS;
		/**
		 * Filter the retry delays.
		 *
		 * @param array<int,int>      $delays   Seconds before each retry; the first failure uses the first entry.
		 * @param array<string,mixed> $settings Connection reliability settings.
		 */
		$filtered = apply_filters( 'estasoft_sync_retry_schedule', $delays, $settings );
		$clean    = array();
		foreach ( (array) $filtered as $seconds ) {
			if ( is_numeric( $seconds ) && (int) $seconds > 0 ) {
				$clean[] = (int) $seconds;
			}
		}
		return array() === $clean ? ConnectionSettings::DEFAULT_DELAYS : $clean;
	}

	/**
	 * Tries allowed in all: one more than the number of delays.
	 *
	 * @param array<string,mixed> $settings Connection settings.
	 */
	public function maxAttempts( array $settings ): int {
		return count( $this->schedule( $settings ) ) + 1;
	}

	/**
	 * Seconds to wait before the next try.
	 *
	 * @param int                 $triesMade  Tries made so far, counting the one that just failed (1 after the first failure).
	 * @param array<string,mixed> $settings   Connection settings (retry_delays_seconds, jitter, obey_retry_after).
	 * @param int|null            $retryAfter Seconds the system asked us to wait.
	 * @return int|null Null when no tries are left.
	 */
	public function delaySeconds( int $triesMade, array $settings, ?int $retryAfter = null ): ?int {
		$schedule = $this->schedule( $settings );
		$index    = $triesMade - 1;
		if ( $index < 0 || $index >= count( $schedule ) ) {
			return null;
		}
		$delay = $schedule[ $index ];
		if ( ! array_key_exists( 'jitter', $settings ) || $settings['jitter'] ) {
			$delay += max( 0, min( self::MAX_JITTER, (int) ( $this->jitter )() ) );
		}
		$obey = ! array_key_exists( 'obey_retry_after', $settings ) || $settings['obey_retry_after'];
		if ( $obey && null !== $retryAfter && $retryAfter > $delay ) {
			$delay = min( $retryAfter, self::MAX_RETRY_AFTER );
		}
		return $delay;
	}

	/**
	 * The time of the next try as a MySQL datetime (UTC).
	 *
	 * @param int                 $triesMade  Tries made so far.
	 * @param array<string,mixed> $settings   Connection settings.
	 * @param int|null            $retryAfter Seconds the system asked us to wait.
	 * @return string|null Null when no tries are left.
	 */
	public function nextAttemptAt( int $triesMade, array $settings, ?int $retryAfter = null ): ?string {
		$delay = $this->delaySeconds( $triesMade, $settings, $retryAfter );
		return null === $delay ? null : Time::sql( Time::plus( $this->clock->now(), $delay ) );
	}
}
