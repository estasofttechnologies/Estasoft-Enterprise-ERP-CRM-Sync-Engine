<?php
/**
 * A worker run.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Queue\Worker;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class WorkerTest extends CoreIntegrationTestCase {

	/**
	 * A connection with a high request rate, so the limiter does not get in the way.
	 *
	 * @param array<string,mixed> $settings Extra settings.
	 */
	private function fastConnection( array $settings = array() ): int {
		return $this->h->connection( array_merge( array( 'requests_per_second' => 50 ), $settings ) );
	}

	public function test_a_run_sends_everything_that_is_waiting_and_reports_it(): void {
		$conn = $this->fastConnection();
		$ids  = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$ids[] = $this->h->item( $conn, (string) $i );
		}
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Net001 );
		$this->h->connector->script[] = null;
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'X' ) );

		$report = $this->h->worker->run();

		$this->assertSame( 5, $report->claimed );
		$this->assertSame( 3, $report->synced );
		$this->assertSame( 1, $report->retrying );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 'idle', $report->stoppedBecause );
		$this->assertSame( array( 'claimed', 'synced', 'retrying', 'failed', 'held', 'skipped', 'seconds', 'stopped_because' ), array_keys( $report->toArray() ) );
		$this->assertSame( 'retrying', $this->h->queue->findRow( $ids[0] )['state'] );
		$this->assertSame( 'synced', $this->h->queue->findRow( $ids[1] )['state'] );
		$this->assertSame( 'failed', $this->h->queue->findRow( $ids[2] )['state'] );
	}

	public function test_steps_of_one_order_go_out_in_order_within_one_run(): void {
		$conn    = $this->fastConnection();
		$created = $this->h->item( $conn, '10490', 'created' );
		$paid    = $this->h->item( $conn, '10490', 'paid' );
		$updated = $this->h->item( $conn, '10490', 'updated' );
		$other   = $this->h->item( $conn, '10491', 'created' );
		$this->h->worker->run();
		$this->assertSame( array( $created, $other, $paid, $updated ), $this->h->builder->built, 'created before paid before updated, whatever else is mixed in' );
		foreach ( array( $created, $paid, $updated, $other ) as $id ) {
			$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
		}
	}

	public function test_one_failing_step_holds_back_the_later_steps_of_that_order_only(): void {
		$conn    = $this->fastConnection();
		$created = $this->h->item( $conn, '10490', 'created' );
		$paid    = $this->h->item( $conn, '10490', 'paid' );
		$other   = $this->h->item( $conn, '10491', 'created' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'X' ) );
		$report = $this->h->worker->run();
		$this->assertSame( 'failed', $this->h->queue->findRow( $created )['state'] );
		$this->assertSame( 'queued', $this->h->queue->findRow( $paid )['state'], 'waits for a person' );
		$this->assertTrue( $this->h->queue->findRow( $paid )['waiting_for_earlier'] );
		$this->assertSame( 'synced', $this->h->queue->findRow( $other )['state'] );
		$this->assertSame( 1, $report->synced );
	}

	public function test_the_run_stops_after_twenty_seconds_of_work_and_gives_back_what_it_took(): void {
		$conn = $this->fastConnection( array( 'concurrency' => 2 ) );
		$ids  = array();
		for ( $i = 1; $i <= 6; ++$i ) {
			$ids[] = $this->h->item( $conn, (string) $i );
		}
		$this->h->connector->sendSeconds = 8;

		$report = $this->h->worker->run( 20, 2 );

		$this->assertSame( 'time', $report->stoppedBecause );
		$this->assertSame( 3, $report->synced, 'three sends of 8 seconds: the fourth would start after 20' );
		$this->assertGreaterThanOrEqual( 24.0, $report->seconds );
		$states = array_map( fn( int $id ): string => $this->h->queue->findRow( $id )['state'], $ids );
		$this->assertSame( array( 'synced', 'synced', 'synced', 'queued', 'queued', 'queued' ), $states );
		$this->assertSame( 0, $this->h->queue->findRow( $ids[3] )['attempt'], 'the item that was claimed but not sent costs no try' );
		$this->assertSame( 'queued', $this->h->row( $ids[3] )['state'] );
		$this->assertNull( $this->h->row( $ids[3] )['locked_by'] );
	}

	public function test_a_caller_cannot_ask_for_more_than_twenty_seconds(): void {
		$conn = $this->fastConnection();
		for ( $i = 1; $i <= 6; ++$i ) {
			$this->h->item( $conn, (string) $i );
		}
		$this->h->connector->sendSeconds = 8;
		$report = $this->h->worker->run( 600, 2 );
		$this->assertSame( 'time', $report->stoppedBecause );
		$this->assertSame( 3, $report->synced );
	}

	public function test_requests_are_spread_out_to_the_connections_rate(): void {
		$conn = $this->h->connection( array( 'requests_per_second' => 2, 'concurrency' => 5 ) );
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->h->item( $conn, (string) $i );
		}
		$this->h->settings->update( array( 'global_concurrency' => 10 ) );
		$this->h->worker->run( 20, 10 );
		$this->assertCount( 3, $this->h->sleeps, 'the first goes at once, the next three wait' );
		foreach ( $this->h->sleeps as $wait ) {
			$this->assertEqualsWithDelta( 0.5, $wait, 0.001 );
		}
	}

	public function test_a_run_that_would_have_to_wait_past_its_limit_stops_instead(): void {
		$conn = $this->h->connection( array( 'requests_per_second' => 1, 'concurrency' => 5 ) );
		$ids  = array();
		for ( $i = 1; $i <= 30; ++$i ) {
			$ids[] = $this->h->item( $conn, (string) $i );
		}
		$this->h->settings->update( array( 'global_concurrency' => 10 ) );
		$report = $this->h->worker->run( 20, 10 );
		$this->assertSame( 'time', $report->stoppedBecause );
		$this->assertLessThanOrEqual( 21, $report->synced );
		$this->assertGreaterThan( 15, $report->synced );
		$this->assertSame( 0, $this->h->queue->countSyncing(), 'nothing is left locked' );
	}

	public function test_items_at_once_are_limited_per_connection_and_overall(): void {
		$seen = array();
		$spy  = function () use ( &$seen ) {
			$seen[] = $this->h->queue->countSyncing();
			return null;
		};

		// One item at once on a connection.
		$conn = $this->fastConnection( array( 'concurrency' => 1 ) );
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->h->item( $conn, (string) $i );
			$this->h->connector->script[] = $spy;
		}
		$this->h->worker->run( 20, 10 );
		$this->assertSame( array( 1, 1, 1, 1 ), $seen );

		// Two connections of two at once, but only one at once overall.
		$seen = array();
		$this->h->reset();
		$a = $this->fastConnection( array( 'concurrency' => 2 ) );
		$b = $this->fastConnection( array( 'concurrency' => 2 ) );
		foreach ( array( $a, $b ) as $c ) {
			for ( $i = 1; $i <= 2; ++$i ) {
				$this->h->item( $c, $c . '-' . $i );
				$this->h->connector->script[] = $spy;
			}
		}
		$this->h->settings->update( array( 'global_concurrency' => 1 ) );
		$this->h->worker->run( 20, 10 );
		$this->assertSame( array( 1, 1, 1, 1 ), $seen );

		// Allowed two overall.
		$seen = array();
		$this->h->reset();
		$a = $this->fastConnection( array( 'concurrency' => 2 ) );
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->h->item( $a, 'x' . $i );
			$this->h->connector->script[] = $spy;
		}
		$this->h->settings->update( array( 'global_concurrency' => 2 ) );
		$this->h->worker->run( 20, 10 );
		$this->assertSame( array( 2, 1, 2, 1 ), $seen, 'two are taken at a time' );
	}

	public function test_a_connection_that_already_has_its_items_in_flight_gets_no_more(): void {
		$conn = $this->fastConnection( array( 'concurrency' => 1 ) );
		$busy = $this->h->item( $conn, '1' );
		$this->h->item( $conn, '2' );
		$this->h->queue->claim( 1, 60, 'another-worker' );
		$this->assertSame( 'syncing', $this->h->queue->findRow( $busy )['state'] );
		$report = $this->h->worker->run();
		$this->assertSame( 0, $report->claimed, 'another worker already holds the one allowed slot' );
	}

	public function test_a_paused_connection_is_left_alone_and_a_finished_timed_pause_is_ended_first(): void {
		$paused = $this->fastConnection();
		$timed  = $this->fastConnection();
		$p      = $this->h->item( $paused, '1' );
		$t      = $this->h->item( $timed, '2' );
		$this->h->pause->pause( $paused );
		$this->h->pause->pause( $timed, 10 );

		$this->assertSame( 0, $this->h->worker->run()->claimed );
		$this->h->clock->advance( 601 );
		$report = $this->h->worker->run();
		$this->assertSame( 1, $report->synced );
		$this->assertSame( 'synced', $this->h->queue->findRow( $t )['state'] );
		$this->assertSame( 'queued', $this->h->queue->findRow( $p )['state'] );
	}

	public function test_five_retryable_failures_in_a_row_pause_the_connection_mid_run(): void {
		$conn = $this->fastConnection( array( 'concurrency' => 2 ) );
		$ids  = array();
		for ( $i = 1; $i <= 8; ++$i ) {
			$ids[] = $this->h->item( $conn, (string) $i );
			$this->h->connector->script[] = new ConnectorError( ErrorCode::Net001 );
		}
		$paused = $this->listen( 'estasoft_sync_connection_paused', 2 );

		$report = $this->h->worker->run( 20, 2 );

		$this->assertSame( 5, $report->retrying );
		$this->assertSame( 1, $report->held, 'the sixth was already claimed and went back untouched' );
		$this->assertSame( 'paused_circuit', $this->h->connections->findPublic( $conn )['status'] );
		$this->assertSame( array( array( $conn, 'circuit' ) ), $paused->getArrayCopy() );
		foreach ( array_slice( $ids, 5 ) as $id ) {
			$row = $this->h->queue->findRow( $id );
			$this->assertSame( 'queued', $row['state'] );
			$this->assertSame( 0, $row['attempt'] );
		}
		$this->assertSame( 5, $this->h->connector->count( 'send' ), 'no sixth request went to a system that is not answering' );
	}

	public function test_a_run_ends_a_circuit_pause_whose_probe_is_due_and_then_sends(): void {
		$conn = $this->fastConnection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->connections->tripCircuit( $conn, '2026-11-02 12:02:00' );
		$this->assertSame( 0, $this->h->worker->run()->claimed, 'the probe is not due yet' );
		$this->h->clock->advance( 121 );
		$report = $this->h->worker->run();
		$this->assertSame( 1, $report->synced );
		$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
		$this->assertSame( 1, $this->h->connector->count( 'ping' ) );
	}

	public function test_a_run_takes_back_dead_locks_before_claiming(): void {
		$conn = $this->fastConnection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->queue->claim( 1, 60, 'dead-worker' );
		$this->h->clock->advance( 61 );
		$report = $this->h->worker->run();
		$this->assertSame( 1, $report->synced );
		$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
		$this->assertSame( 1, $this->h->queue->findRow( $id )['attempt'], 'the dead worker\'s try was not counted' );
		$this->assertContains( 'SYNC-LOCK-001', array_column( $this->h->logs->timeline( $id ), 'code' ) );
	}

	public function test_one_item_that_crashes_its_connector_does_not_stop_the_others(): void {
		$conn = $this->fastConnection();
		$a    = $this->h->item( $conn, '1' );
		$b    = $this->h->item( $conn, '2' );
		$c    = $this->h->item( $conn, '3' );
		$this->h->connector->script[] = null;
		$this->h->connector->script[] = new \RuntimeException( 'kaboom' );
		$report = $this->h->worker->run();
		$this->assertSame( 2, $report->synced );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 'synced', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'failed', $this->h->queue->findRow( $b )['state'] );
		$this->assertSame( 'synced', $this->h->queue->findRow( $c )['state'] );
	}

	public function test_a_refused_sign_in_stops_the_connection_and_the_run_moves_on(): void {
		$bad  = $this->fastConnection();
		$good = $this->fastConnection();
		$a    = $this->h->item( $bad, '1' );
		$b    = $this->h->item( $good, '2' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Auth001, array(), 401 );
		$report = $this->h->worker->run();
		$this->assertSame( 'queued', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'synced', $this->h->queue->findRow( $b )['state'] );
		$this->assertSame( 'paused', $this->h->connections->findPublic( $bad )['status'] );
		$this->assertSame( 'active', $this->h->connections->findPublic( $good )['status'] );
		$this->assertSame( 1, $report->held );
	}

	public function test_nothing_to_do_is_a_quick_idle_run(): void {
		$report = $this->h->worker->run();
		$this->assertSame( 0, $report->claimed );
		$this->assertSame( 'idle', $report->stoppedBecause );
	}

	public function test_the_maximum_is_documented_as_twenty_seconds(): void {
		$this->assertSame( 20, Worker::MAX_SECONDS );
		$this->assertSame( 60, Worker::LOCK_SECONDS );
	}

	public function test_a_disconnected_connection_is_ignored(): void {
		$conn = $this->fastConnection();
		$this->h->item( $conn, '1' );
		$this->h->connections->setStatus( $conn, ConnectionStatus::Disconnected );
		$this->assertSame( 0, $this->h->worker->run()->claimed );
	}

	public function test_a_worker_gives_up_quietly_while_another_holds_the_claim_lock(): void {
		$conn = $this->fastConnection();
		$id   = $this->h->item( $conn, '1' );
		$host = (string) ( getenv( 'WP_DB_HOST' ) ?: 'localhost' );
		$other = new \mysqli( $host, (string) ( getenv( 'WP_DB_USER' ) ?: 'root' ), (string) ( getenv( 'WP_DB_PASSWORD' ) ?: '' ), (string) ( getenv( 'WP_DB_NAME' ) ?: 'estasoft_test' ) );
		$name  = $this->h->queue->claimLockName();
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('$name', 0)" )->fetch_row()[0] );
		try {
			$report = $this->h->worker->run();
			$this->assertSame( 0, $report->claimed, 'another worker is filling the slots' );
			$this->assertSame( 'idle', $report->stoppedBecause );
			$this->assertSame( 'queued', $this->h->queue->findRow( $id )['state'], 'the item waits for the next run' );
		} finally {
			$other->query( "SELECT RELEASE_LOCK('$name')" );
			$other->close();
		}
		$this->assertSame( 1, $this->h->worker->run()->claimed, 'the next run goes through' );
	}

	public function test_a_run_writes_results_with_its_own_lock_token(): void {
		$conn = $this->fastConnection();
		$id   = $this->h->item( $conn, '1' );
		$seen = null;
		$this->h->connector->script[] = function () use ( $id, &$seen ) {
			$seen = $this->h->row( $id )['locked_by'];
			return null;
		};
		$this->h->worker->run();
		$this->assertNotEmpty( $seen );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', (string) $seen, 'a UUID per run is the token' );
		$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
	}

	public function test_a_run_that_runs_out_of_time_gives_the_rest_back_with_its_token(): void {
		$conn = $this->fastConnection( array( 'concurrency' => 2 ) );
		$this->h->item( $conn, '1' );
		$b = $this->h->item( $conn, '2' );
		$this->h->connector->sendSeconds = 21;
		$report = $this->h->worker->run();
		$this->assertSame( 'time', $report->stoppedBecause );
		$this->assertSame( 'queued', $this->h->queue->findRow( $b )['state'] );
		$this->assertNull( $this->h->row( $b )['locked_by'] );
	}
}
