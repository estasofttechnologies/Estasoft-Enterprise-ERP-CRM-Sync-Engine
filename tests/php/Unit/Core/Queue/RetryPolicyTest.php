<?php
/**
 * Retry delays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Queue\RetryPolicy;
use Estasoft\SyncEngine\Tests\Support\CoreFakeClock;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function settings( array $changes = array() ): array {
		return array_merge( ( new ConnectionSettings() )->defaults(), $changes );
	}

	public function test_delays_are_one_minute_five_minutes_one_hour_and_a_day_then_nothing(): void {
		$policy = new RetryPolicy( new CoreFakeClock(), static fn(): int => 0 );
		$this->assertSame( 60, $policy->delaySeconds( 1, $this->settings() ) );
		$this->assertSame( 300, $policy->delaySeconds( 2, $this->settings() ) );
		$this->assertSame( 3600, $policy->delaySeconds( 3, $this->settings() ) );
		$this->assertSame( 86400, $policy->delaySeconds( 4, $this->settings() ) );
		$this->assertNull( $policy->delaySeconds( 5, $this->settings() ), 'five tries in all' );
		$this->assertNull( $policy->delaySeconds( 0, $this->settings() ) );
		$this->assertSame( 5, $policy->maxAttempts( $this->settings() ) );
	}

	public function test_jitter_adds_zero_to_twenty_seconds_and_never_more(): void {
		$real = new RetryPolicy( new CoreFakeClock() );
		for ( $i = 0; $i < 200; ++$i ) {
			$delay = $real->delaySeconds( 1, $this->settings() );
			$this->assertGreaterThanOrEqual( 60, $delay );
			$this->assertLessThanOrEqual( 80, $delay );
		}
		$silly = new RetryPolicy( new CoreFakeClock(), static fn(): int => 999 );
		$this->assertSame( 80, $silly->delaySeconds( 1, $this->settings() ) );
		$negative = new RetryPolicy( new CoreFakeClock(), static fn(): int => -5 );
		$this->assertSame( 60, $negative->delaySeconds( 1, $this->settings() ) );
	}

	public function test_jitter_can_be_switched_off(): void {
		$policy = new RetryPolicy( new CoreFakeClock(), static fn(): int => 17 );
		$this->assertSame( 60, $policy->delaySeconds( 1, $this->settings( array( 'jitter' => false ) ) ) );
	}

	public function test_retry_after_wins_when_it_is_longer(): void {
		$policy = new RetryPolicy( new CoreFakeClock(), static fn(): int => 10 );
		$this->assertSame( 600, $policy->delaySeconds( 1, $this->settings(), 600 ) );
		$this->assertSame( 70, $policy->delaySeconds( 1, $this->settings(), 30 ), 'a shorter Retry-After does not shorten the schedule' );
		$this->assertSame( 70, $policy->delaySeconds( 1, $this->settings(), 70 ) );
	}

	public function test_retry_after_is_capped_at_24_hours(): void {
		$policy = new RetryPolicy( new CoreFakeClock(), static fn(): int => 0 );
		$this->assertSame( 86400, $policy->delaySeconds( 1, $this->settings(), 999999 ) );
	}

	public function test_retry_after_can_be_ignored_by_setting(): void {
		$policy = new RetryPolicy( new CoreFakeClock(), static fn(): int => 0 );
		$this->assertSame( 60, $policy->delaySeconds( 1, $this->settings( array( 'obey_retry_after' => false ) ), 600 ) );
	}

	public function test_custom_delays_set_the_number_of_tries(): void {
		$policy   = new RetryPolicy( new CoreFakeClock(), static fn(): int => 0 );
		$settings = $this->settings( array( 'retry_delays_seconds' => array( 10, 20 ) ) );
		$this->assertSame( 10, $policy->delaySeconds( 1, $settings ) );
		$this->assertSame( 20, $policy->delaySeconds( 2, $settings ) );
		$this->assertNull( $policy->delaySeconds( 3, $settings ) );
		$this->assertSame( 3, $policy->maxAttempts( $settings ) );
	}

	public function test_next_attempt_time_is_in_utc_from_the_clock(): void {
		$clock  = new CoreFakeClock( '2026-11-02 12:00:00' );
		$policy = new RetryPolicy( $clock, static fn(): int => 5 );
		$this->assertSame( '2026-11-02 12:01:05', $policy->nextAttemptAt( 1, $this->settings() ) );
		$this->assertNull( $policy->nextAttemptAt( 5, $this->settings() ) );
	}
}
