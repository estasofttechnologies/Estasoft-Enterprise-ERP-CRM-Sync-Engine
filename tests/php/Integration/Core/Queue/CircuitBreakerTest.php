<?php
/**
 * The circuit breaker.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class CircuitBreakerTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
	}

	/**
	 * The connection object (no secrets needed).
	 */
	private function connection(): \Estasoft\SyncEngine\Domain\Connection {
		return $this->h->connections->findWithoutSecrets( $this->conn );
	}

	public function test_four_failures_do_not_trip_it_and_the_fifth_does(): void {
		$paused = $this->listen( 'estasoft_sync_connection_paused', 2 );
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->assertFalse( $this->h->breaker->recordRetryableFailure( $this->connection() ), "failure $i" );
		}
		$this->assertSame( 'active', $this->h->connections->findPublic( $this->conn )['status'] );
		$this->assertTrue( $this->h->breaker->recordRetryableFailure( $this->connection() ) );

		$public = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'paused_circuit', $public['status'] );
		$this->assertSame( 'circuit', $public['paused_reason'] );
		$this->assertSame( '2026-11-02T12:02:00Z', $public['paused_until'], 'the next probe is in two minutes' );
		$this->assertSame( 5, $public['failures_in_a_row'] );
		$this->assertSame( array( array( $this->conn, 'circuit' ) ), $paused->getArrayCopy() );
		$latest = $this->h->logs->latest( 1 )[0];
		$this->assertSame( 'SYNC-PAUSE-001', $latest['code'] );
		$this->assertSame( 'error', $latest['level'] );
	}

	public function test_it_trips_only_once(): void {
		$paused = $this->listen( 'estasoft_sync_connection_paused', 2 );
		for ( $i = 1; $i <= 7; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$this->assertCount( 1, $paused );
	}

	public function test_rate_limit_answers_never_count(): void {
		for ( $i = 1; $i <= 20; ++$i ) {
			$this->assertFalse( $this->h->breaker->recordRetryableFailure( $this->connection(), false ) );
		}
		$public = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'active', $public['status'] );
		$this->assertSame( 0, $public['failures_in_a_row'] );
	}

	public function test_a_success_starts_the_count_again(): void {
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$this->h->breaker->recordSuccess( $this->connection(), 120 );
		$this->assertSame( 0, $this->h->connections->findPublic( $this->conn )['failures_in_a_row'] );
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->assertFalse( $this->h->breaker->recordRetryableFailure( $this->connection() ) );
		}
		$this->assertSame( 'active', $this->h->connections->findPublic( $this->conn )['status'] );
	}

	public function test_an_answer_from_the_system_even_a_refusal_starts_the_count_again(): void {
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$this->h->breaker->recordReachable( $this->connection() );
		$this->assertSame( 0, $this->h->connections->findPublic( $this->conn )['failures_in_a_row'] );
	}

	public function test_the_threshold_and_probe_interval_are_per_connection_settings(): void {
		$this->h->connections->update( $this->conn, array( 'settings' => array( 'breaker_threshold' => 3, 'probe_interval_minutes' => 10 ) ) );
		$this->h->breaker->recordRetryableFailure( $this->connection() );
		$this->h->breaker->recordRetryableFailure( $this->connection() );
		$this->assertTrue( $this->h->breaker->recordRetryableFailure( $this->connection() ) );
		$this->assertSame( '2026-11-02T12:10:00Z', $this->h->connections->findPublic( $this->conn )['paused_until'] );
	}

	public function test_a_probe_before_its_time_does_nothing_and_a_good_probe_resumes(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );

		$this->assertSame( 0, $this->h->breaker->probeDue() );
		$this->assertSame( 0, $this->h->connector->count( 'ping' ) );

		$this->h->clock->advance( 121 );
		$this->assertSame( 1, $this->h->breaker->probeDue() );
		$public = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'active', $public['status'] );
		$this->assertSame( 0, $public['failures_in_a_row'] );
		$this->assertNull( $public['paused_until'] );
		$this->assertSame( array( array( $this->conn, 'probe' ) ), $resumed->getArrayCopy() );
		$this->assertSame( 'info', $this->h->logs->latest( 1 )[0]['level'] );
	}

	public function test_a_failed_probe_keeps_it_paused_and_tries_again_later(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$this->h->connector->pingResult = false;
		$this->h->clock->advance( 121 );
		$this->assertSame( 0, $this->h->breaker->probeDue() );
		$public = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'paused_circuit', $public['status'] );
		$this->assertSame( '2026-11-02T12:04:01Z', $public['paused_until'], 'two more minutes from the failed probe' );

		$this->h->clock->advance( 60 );
		$this->assertSame( 0, $this->h->breaker->probeDue() );
		$this->assertSame( 1, $this->h->connector->count( 'ping' ), 'not probed again before its time' );

		$this->h->clock->advance( 61 );
		$this->h->connector->pingResult = true;
		$this->assertSame( 1, $this->h->breaker->probeDue() );
		$this->assertSame( 'active', $this->h->connections->findPublic( $this->conn )['status'] );
	}

	public function test_a_probe_that_throws_counts_as_a_failed_probe(): void {
		$this->h->connections->tripCircuit( $this->conn, '2026-11-02 12:00:00' );
		$this->h->connector->pingResult = false;
		$this->assertFalse( $this->h->breaker->probe( $this->conn ) );
		$this->assertSame( 'paused_circuit', $this->h->connections->findPublic( $this->conn )['status'] );
	}

	public function test_the_first_success_closes_the_circuit_without_waiting_for_the_probe(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->h->breaker->recordRetryableFailure( $this->connection() );
		}
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );
		$this->h->breaker->recordSuccess( $this->connection(), 90 );
		$this->assertSame( 'active', $this->h->connections->findPublic( $this->conn )['status'] );
		$this->assertSame( array( array( $this->conn, 'success' ) ), $resumed->getArrayCopy() );
	}

	public function test_a_pause_made_by_a_person_is_never_probed_away(): void {
		$this->h->pause->pause( $this->conn, 1 );
		$this->h->clock->advance( 600 );
		$this->assertSame( 0, $this->h->breaker->probeDue() );
		$this->assertSame( 0, $this->h->connector->count( 'ping' ) );
		$this->assertSame( ConnectionStatus::Paused->value, $this->h->connections->findPublic( $this->conn )['status'] );
	}

	public function test_probing_a_connection_that_is_not_in_circuit_pause_does_nothing(): void {
		$this->assertFalse( $this->h->breaker->probe( $this->conn ) );
		$this->assertSame( 0, $this->h->connector->count( 'ping' ) );
	}
}
