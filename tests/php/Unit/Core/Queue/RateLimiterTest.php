<?php
/**
 * Token bucket.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use Estasoft\SyncEngine\Core\Queue\RateLimiter;
use Estasoft\SyncEngine\Tests\Support\CoreArrayRateStore;
use Estasoft\SyncEngine\Tests\Support\CoreFakeClock;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	private CoreFakeClock $clock;

	private CoreArrayRateStore $store;

	private RateLimiter $limiter;

	protected function setUp(): void {
		$this->clock   = new CoreFakeClock();
		$this->store   = new CoreArrayRateStore();
		$this->limiter = new RateLimiter( $this->store, $this->clock );
	}

	public function test_the_first_request_goes_at_once_and_the_next_waits_one_interval(): void {
		$this->assertEqualsWithDelta( 0.0, $this->limiter->acquire( 1, 4.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.25, $this->limiter->acquire( 1, 4.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.50, $this->limiter->acquire( 1, 4.0 ), 0.0001 );
	}

	public function test_slots_are_spaced_at_the_configured_rate(): void {
		$waits = array();
		for ( $i = 0; $i < 8; ++$i ) {
			$waits[] = $this->limiter->acquire( 1, 4.0 );
		}
		$this->assertEqualsWithDelta( 1.75, end( $waits ), 0.0001, 'eight requests at 4 per second need 1.75 seconds' );
	}

	public function test_waiting_it_out_frees_the_slot(): void {
		$this->limiter->acquire( 1, 1.0 );
		$this->assertEqualsWithDelta( 1.0, $this->limiter->acquire( 1, 1.0 ), 0.0001 );
		$this->clock->advance( 10 );
		$this->assertEqualsWithDelta( 0.0, $this->limiter->acquire( 1, 1.0 ), 0.0001 );
	}

	public function test_connections_have_separate_limits(): void {
		$this->limiter->acquire( 1, 1.0 );
		$this->assertEqualsWithDelta( 0.0, $this->limiter->acquire( 2, 1.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, $this->limiter->acquire( 1, 1.0 ), 0.0001 );
	}

	public function test_a_global_limit_slows_all_connections_together(): void {
		$this->assertEqualsWithDelta( 0.0, $this->limiter->acquire( 1, 50.0, 2.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.5, $this->limiter->acquire( 2, 50.0, 2.0 ), 0.0001, 'global cap of 2 per second' );
		$this->assertEqualsWithDelta( 1.0, $this->limiter->acquire( 3, 50.0, 2.0 ), 0.0001 );
	}

	public function test_collisions_are_retried_and_in_the_end_the_caller_still_waits(): void {
		$this->store->failures = 3;
		$this->assertEqualsWithDelta( 0.0, $this->limiter->acquire( 1, 4.0 ), 0.0001, 'succeeds after a few collisions' );

		$this->store->failures = -1; // Refuse everything.
		$this->assertEqualsWithDelta( 0.25, $this->limiter->acquire( 1, 4.0 ), 0.0001, 'never sends without a limit' );
	}
}
