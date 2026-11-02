<?php
/**
 * REC-1 to REC-6, AS-18, AS-21.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconService;

final class ReconServiceTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
	}

	/**
	 * What the system holds for an order.
	 *
	 * @param int                 $orderId Order id.
	 * @param array<string,mixed> $extra   Values over the defaults.
	 */
	private function document( int $orderId, array $extra = array() ): void {
		$this->j->connector->documents[ 'WC-' . $orderId ] = new ExternalRef(
			'SO-' . $orderId,
			'S' . $orderId,
			null,
			array_merge(
				array(
					'total_minor'    => 48615,
					'refunded_minor' => 0,
					'currency'       => 'AED',
					'state'          => 'sale',
				),
				$extra
			)
		);
	}

	public function test_a_missing_order_is_queued_by_itself_and_a_differing_total_waits_for_a_person(): void {
		$this->j->reconService->saveSettings( array( 'auto_repair_missing' => true ) );
		$this->j->orders->add( 601 );
		$this->j->orders->add( 602 );
		$this->j->orders->add( 603 );
		$this->j->orders->add( 604, array( 'sync_state' => 'queued' ) );
		$this->document( 601 );
		$this->document( 602, array( 'total_minor' => 48620 ) );
		$finished = $this->listen( 'estasoft_sync_reconciliation_finished', 1 );

		$run = $this->j->reconService->run( 7 );

		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 3, $run['compared'], 'the queued order is not compared' );
		$this->assertSame( 1, $run['matched'] );
		$this->assertSame( 2, $run['differences'] );
		$this->assertSame( 1, $run['repaired'] );
		$this->assertCount( 1, $finished );
		$diffs = array_column( $this->h->recon->diffs(), null, 'type' );
		$this->assertSame( 'repaired', $diffs['missing_in_system']['status'] );
		$this->assertSame( 'open', $diffs['total_differs']['status'] );
		$this->assertSame( '486.20 AED', $diffs['total_differs']['system_value'] );
		$this->assertSame( '486.15 AED', $diffs['total_differs']['wc_value'] );
		$queued = $this->h->queue->query( new QueueQuery( 'queued', 'order' ) )->items;
		$this->assertCount( 1, $queued );
		$this->assertSame( '603', $queued[0]['entity_id'] );
		$this->assertSame( 'updated', $queued[0]['event'] );
		$info = array_filter( $this->h->logs->latest( 10 ), static fn( array $entry ): bool => 'SYNC-RECON-001' === $entry['code'] );
		$this->assertNotSame( array(), $info );

		$overview = $this->j->reconService->overview();
		$this->assertSame( $run['id'], $overview['last_run']['id'], 'the run is stored and read back' );
		$this->assertCount( 1, $overview['runs'] );
		$this->assertSame( 3, $overview['runs'][0]['compared'] );
		$this->assertSame( 7, $overview['runs'][0]['window_days'] );
		$this->assertCount( 2, $overview['diffs'] );
		$this->assertSame( $run['id'], $overview['diffs'][0]['run_id'] );
		$this->assertSame( array( 'id', 'run_id', 'order_id', 'order_label', 'type', 'wc_value', 'system_value', 'status', 'note', 'acted_by', 'acted_at' ), array_keys( $overview['diffs'][0] ) );
	}

	public function test_one_minor_unit_is_equal_by_the_currency_exponent_and_the_tolerance_is_editable(): void {
		$this->j->orders->add( 601, array( 'currency' => 'KWD', 'total_minor' => 12345 ) );
		$this->j->orders->add( 602, array( 'currency' => 'KWD', 'total_minor' => 12345 ) );
		$this->j->connector->documents['WC-601'] = new ExternalRef( '1', 'S1', null, array( 'total_minor' => 12346, 'currency' => 'KWD', 'state' => 'sale' ) );
		$this->j->connector->documents['WC-602'] = new ExternalRef( '2', 'S2', null, array( 'total_minor' => 12347, 'currency' => 'KWD', 'state' => 'sale' ) );

		$run = $this->j->reconService->run( 7 );
		$this->assertSame( 1, $run['matched'], '0.001 KWD apart is equal' );
		$this->assertSame( 1, $run['differences'], '0.002 KWD apart is not' );
		$diff = $this->h->recon->diffs()[0];
		$this->assertSame( '12.345 KWD', $diff['wc_value'], 'three decimals, no float error' );

		$this->j->reconService->saveSettings( array( 'tolerance_minor' => 5 ) );
		$again = $this->j->reconService->run( 7 );
		$this->assertSame( 2, $again['matched'] );
	}

	public function test_refund_and_cancelled_status_differences(): void {
		$this->j->orders->add( 601, array( 'refunded_minor' => 5000 ) );
		$this->j->orders->add( 602 );
		$this->document( 601 );
		$this->document( 602, array( 'state' => 'cancel' ) );

		$this->j->reconService->run( 7 );

		$types = array_column( $this->h->recon->diffs(), 'type' );
		sort( $types );
		$this->assertSame( array( 'refund_missing', 'status_differs' ), $types );
	}

	public function test_repair_wc_to_system_queues_the_order_and_logs_who(): void {
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7 );
		$diff = $this->h->recon->diffs()[0];

		$repaired = $this->j->reconService->repair( (int) $diff['id'], 'wc_to_system', 7 );

		$this->assertSame( 'repaired', $repaired['status'] );
		$this->assertSame( 'User 7', $repaired['acted_by'] );
		$this->assertSame( 1, $this->h->queue->countsByState( $this->connection, null )['queued'] );
		$this->expectException( JobException::class );
		$this->j->reconService->repair( (int) $diff['id'], 'wc_to_system', 7 );
	}

	public function test_a_missing_order_repair_forgets_the_old_link_so_it_is_sent_again(): void {
		$this->j->orders->add( 603 );
		$this->h->idMap->link( $this->connection, 'order', '603', 'SO-GONE', 'S603' );
		$this->h->state->save( $this->connection, 'order', '603', 'hash' );
		$this->j->reconService->run( 7 );

		$this->j->reconService->repair( (int) $this->h->recon->diffs()[0]['id'], 'wc_to_system', 1 );

		$this->assertNull( $this->h->idMap->get( $this->connection, 'order', '603' ) );
		$this->assertNull( $this->h->state->get( $this->connection, 'order', '603' ) );
	}

	public function test_system_to_wc_copies_only_a_cancellation(): void {
		$this->j->orders->add( 601 );
		$this->j->orders->add( 602 );
		$this->document( 601, array( 'state' => 'cancel' ) );
		$this->document( 602, array( 'total_minor' => 1 ) );
		$this->j->reconService->run( 7 );
		$byOrder = array_column( $this->h->recon->diffs(), null, 'order_id' );

		$this->j->reconService->repair( (int) $byOrder[601]['id'], 'system_to_wc', 1 );
		$this->assertSame( 'cancelled', $this->j->orders->orders[601]['status'] );

		$this->expectException( ValidationException::class );
		$this->j->reconService->repair( (int) $byOrder[602]['id'], 'system_to_wc', 1 );
	}

	public function test_a_repair_goes_to_the_connection_the_difference_was_found_in_not_the_first_one(): void {
		$second = $this->j->connect();
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7, $second );
		$diff = $this->h->recon->diffs()[0];

		$this->j->reconService->repair( (int) $diff['id'], 'wc_to_system', 7 );

		$this->assertSame( 1, $this->h->queue->countsByState( $second, null )['queued'], 'the order is sent to the system it was compared with' );
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, null )['queued'] );
	}

	public function test_an_automatic_repair_also_goes_to_the_connection_of_the_run(): void {
		$second = $this->j->connect();
		$this->j->reconService->saveSettings( array( 'auto_repair_missing' => true ) );
		$this->j->orders->add( 603 );

		$this->j->reconService->run( 7, $second );

		$this->assertSame( 1, $this->h->queue->countsByState( $second, null )['queued'] );
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, null )['queued'] );
	}

	public function test_a_repair_for_a_system_that_was_disconnected_since_is_refused(): void {
		$second = $this->j->connect();
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7, $second );
		$this->h->connections->delete( $second );

		try {
			$this->j->reconService->repair( (int) $this->h->recon->diffs()[0]['id'], 'wc_to_system', 7 );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, null )['queued'], 'it was not sent to some other system instead' );
	}

	public function test_a_difference_from_before_the_connection_was_recorded_uses_the_only_connected_system_or_asks_for_a_new_check(): void {
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7 );
		$id = (int) $this->h->recon->diffs()[0]['id'];
		$this->h->options->delete( ReconService::DIFF_CONNECTIONS );

		$second = $this->j->connect();
		try {
			$this->j->reconService->repair( $id, 'wc_to_system', 7 );
			$this->fail( 'with two systems it cannot be told which one' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		$this->h->connections->delete( $second );

		$this->assertSame( 'repaired', $this->j->reconService->repair( $id, 'wc_to_system', 7 )['status'] );
		$this->assertSame( 1, $this->h->queue->countsByState( $this->connection, null )['queued'] );
	}

	public function test_copying_a_cancellation_is_refused_when_the_order_is_no_longer_in_the_status_the_check_saw(): void {
		$this->j->orders->add( 601 );
		$this->document( 601, array( 'state' => 'cancel' ) );
		$this->j->reconService->run( 7 );
		$diff = $this->h->recon->diffs()[0];
		$this->assertSame( 'processing', $diff['wc_value'] );
		$this->j->orders->orders[601]['status'] = 'completed';

		try {
			$this->j->reconService->repair( (int) $diff['id'], 'system_to_wc', 1 );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
			$this->assertStringContainsString( 'completed', $e->getMessage() );
		}
		$this->assertSame( 'completed', $this->j->orders->orders[601]['status'], 'a completed order was not cancelled' );
		$this->assertSame( array(), $this->j->orders->statusChanges );
		$this->assertSame( 'open', $this->h->recon->diff( (int) $diff['id'] )['status'], 'the difference stays open' );

		// Back in the status the check saw, the copy goes through.
		$this->j->orders->orders[601]['status'] = 'processing';
		$this->j->reconService->repair( (int) $diff['id'], 'system_to_wc', 1 );
		$this->assertSame( 'cancelled', $this->j->orders->orders[601]['status'] );
	}

	public function test_no_manual_repair_while_a_shadow_window_is_open_on_the_connection(): void {
		$this->j->orders->add( 601 );
		$this->j->orders->add( 602 );
		$this->document( 601, array( 'state' => 'cancel' ) );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7 );
		$byOrder = array_column( $this->h->recon->diffs(), null, 'order_id' );
		$this->j->shadowService->start( $this->connection, 7 );

		foreach ( array( array( 602, 'wc_to_system' ), array( 601, 'system_to_wc' ) ) as list( $order, $direction ) ) {
			try {
				$this->j->reconService->repair( (int) $byOrder[ $order ]['id'], $direction, 1 );
				$this->fail( 'expected a refusal for ' . $direction );
			} catch ( JobException $e ) {
				$this->assertSame( 409, $e->httpStatus() );
				$this->assertStringContainsString( 'Shadow mode', $e->getMessage() );
			}
		}
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, 'live' )['queued'] );
		$this->assertSame( 'processing', $this->j->orders->orders[601]['status'] );

		$this->j->shadowService->end( 1 );
		$this->assertSame( 'repaired', $this->j->reconService->repair( (int) $byOrder[602]['id'], 'wc_to_system', 1 )['status'] );
	}

	public function test_automatic_repair_waits_while_a_shadow_window_is_open(): void {
		$this->j->reconService->saveSettings( array( 'auto_repair_missing' => true ) );
		$this->j->orders->add( 603 );
		$this->j->shadowService->start( $this->connection, 7 );

		$run = $this->j->reconService->run( 7 );

		$this->assertSame( 1, $run['differences'] );
		$this->assertSame( 0, $run['repaired'] );
		$this->assertSame( 'open', $this->h->recon->diffs()[0]['status'] );
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, null )['queued'], 'nothing was queued' );

		$this->j->shadowService->end( 1 );
		$again = $this->j->reconService->run( 7 );
		$this->assertSame( 1, $again['repaired'], 'once the window is closed the next run repairs it' );
	}

	public function test_a_shadow_window_on_another_connection_does_not_block_repairs(): void {
		$other = $this->j->connect();
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 99999 ) );
		$this->j->reconService->run( 7, $this->connection );
		$this->j->shadowService->start( $other, 7 );

		$repaired = $this->j->reconService->repair( (int) $this->h->recon->diffs()[0]['id'], 'wc_to_system', 1 );

		$this->assertSame( 'repaired', $repaired['status'] );
	}

	/**
	 * A run left half done: 250 orders, 200 compared.
	 */
	private function halfDoneRun(): void {
		for ( $i = 1; $i <= 250; ++$i ) {
			$this->j->orders->add( 1000 + $i );
			$this->document( 1000 + $i );
		}
		$this->j->orders->onRead = function (): void {
			$this->h->clock->advance( 10 );
		};
		$first = $this->j->reconService->run( 7 );
		$this->assertSame( 200, $first['progress']['done'] );
		$this->j->scheduled = array();
	}

	public function test_a_run_another_request_is_advancing_is_not_advanced_twice(): void {
		$this->halfDoneRun();
		$reads = 0;
		$this->j->orders->onRead = function () use ( &$reads ): void {
			++$reads;
			$this->h->clock->advance( 10 );
		};
		$this->h->options->set( ReconService::LOCK, array( 'token' => 'someone-else', 'until' => $this->h->clock->now()->getTimestamp() + 60 ) );

		$tick     = $this->j->reconService->continueRun();
		$manual   = $this->j->reconService->run( 7 );
		$schedule = $this->j->reconService->tick();

		$this->assertSame( 0, $reads, 'nobody read an order: the run belongs to the request that holds the lock' );
		foreach ( array( $tick, $manual, $schedule['run'] ) as $answer ) {
			$this->assertSame( 'running', $answer['status'] );
			$this->assertSame( 200, $answer['progress']['done'] );
		}
		$this->assertSame( array(), $this->j->scheduled, 'and nothing was scheduled by the ones that stood back' );
		$this->assertSame( 'someone-else', $this->h->options->get( ReconService::LOCK )['token'], "a request that did not take the lock does not release the other's" );
	}

	public function test_an_expired_lock_from_a_request_that_died_does_not_block_the_run(): void {
		$this->halfDoneRun();
		$this->h->options->set( ReconService::LOCK, array( 'token' => 'dead', 'until' => $this->h->clock->now()->getTimestamp() + 60 ) );
		$this->h->clock->advance( 61 );
		$this->j->orders->onRead = null;

		$last = $this->j->reconService->continueRun();

		$this->assertSame( 'done', $last['status'] );
		$this->assertNull( $this->h->options->get( ReconService::LOCK ), 'the lock is released when the work is done' );
	}

	public function test_the_lock_is_released_after_a_run_even_when_it_throws(): void {
		$this->j->connector->flags['reconciliation'] = false;
		try {
			$this->j->reconService->run( 7 );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		$this->assertNull( $this->h->options->get( ReconService::LOCK ) );
	}

	public function test_a_second_manual_run_during_the_start_of_the_first_waits(): void {
		$this->j->orders->add( 601 );
		$this->document( 601 );
		$nested = null;
		$this->j->connector->afterCall = function ( string $method ) use ( &$nested ): void {
			if ( 'listForReconcile' === $method && null === $nested ) {
				try {
					$this->j->reconService->run( 7 );
					$nested = 'ran';
				} catch ( JobException $e ) {
					$nested = $e->httpStatus();
				}
			}
		};

		$run = $this->j->reconService->run( 7 );

		$this->assertSame( 409, $nested, 'the second request was told to wait' );
		$this->assertSame( 'done', $run['status'] );
		$this->assertCount( 1, $this->h->recon->runs( 10 ), 'one run, not two' );
	}

	public function test_ignore_needs_a_reason_and_stores_it(): void {
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 1 ) );
		$this->j->reconService->run( 7 );
		$id = (int) $this->h->recon->diffs()[0]['id'];

		try {
			$this->j->reconService->ignore( $id, '  ', 3 );
			$this->fail( 'a reason is required' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'reason', $e->errors() );
		}
		$ignored = $this->j->reconService->ignore( $id, 'Manual discount agreed', 3 );
		$this->assertSame( 'ignored', $ignored['status'] );
		$this->assertSame( 'Manual discount agreed', $ignored['note'] );
		$this->assertSame( 'User 3', $ignored['acted_by'] );
	}

	public function test_an_open_difference_is_not_stored_twice_by_the_next_run(): void {
		$this->j->orders->add( 602 );
		$this->document( 602, array( 'total_minor' => 1 ) );
		$this->j->reconService->run( 7 );
		$second = $this->j->reconService->run( 7 );

		$this->assertSame( 1, $second['differences'] );
		$this->assertCount( 1, $this->h->recon->diffs() );
	}

	public function test_a_long_run_stops_at_the_budget_schedules_itself_and_finishes(): void {
		for ( $i = 1; $i <= 250; ++$i ) {
			$this->j->orders->add( 1000 + $i );
			$this->document( 1000 + $i );
		}
		$this->j->orders->onRead = function (): void {
			$this->h->clock->advance( 10 );
		};

		$first = $this->j->reconService->run( 7 );
		$this->assertSame( 'running', $first['status'] );
		$this->assertSame( 200, $first['progress']['done'] );
		$this->assertSame( array( array( 'recon_continue' ) ), $this->j->scheduled );

		$last = $this->j->reconService->continueRun();
		$this->assertSame( 'done', $last['status'] );
		$this->assertSame( 250, $last['compared'] );
		$this->assertSame( 250, $last['matched'] );
		$this->assertNull( $this->j->reconService->continueRun(), 'nothing left in progress' );
	}

	public function test_a_system_that_cannot_be_read_fails_the_run_and_the_next_one_starts_fresh(): void {
		$this->j->orders->add( 601 );
		$this->j->connector->fail = array( 'listForReconcile' => new ConnectorError( ErrorCode::Net001, array( 'system' => 'Fakesystem' ) ) );

		$run = $this->j->reconService->run( 7 );
		$this->assertSame( 'failed', $run['status'] );

		$this->j->connector->fail = array();
		$this->document( 601 );
		$this->assertSame( 'done', $this->j->reconService->run( 7 )['status'] );
	}

	public function test_no_connection_that_can_be_compared_is_a_409(): void {
		$this->j->connector->flags['reconciliation'] = false;
		try {
			$this->j->reconService->run( 7 );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
	}

	public function test_orders_placed_before_go_live_are_not_expected_in_the_system(): void {
		$this->h->onboarding->save( array( 'answers' => array( 'went_live_at' => '2026-11-02T08:30:00Z' ) ) );
		$this->j->orders->add( 601, array( 'sync_state' => '', 'created_at' => '2026-11-02 08:00:00' ) );
		$this->j->orders->add( 602, array( 'sync_state' => '', 'created_at' => '2026-11-02 09:00:00' ) );

		$run = $this->j->reconService->run( 7 );

		$this->assertSame( 1, $run['compared'] );
		$this->assertSame( '602', (string) $this->h->recon->diffs()[0]['order_label'] );
	}

	public function test_the_nightly_tick_runs_once_after_the_store_time_hour(): void {
		$this->j->orders->add( 601 );
		$this->document( 601 );
		$this->h->clock->set( '2026-11-02 21:30:00' );
		$this->assertFalse( $this->j->reconService->tick()['ran'], '01:30 store time on the 3rd: the day is not due yet' );

		$this->h->clock->set( '2026-11-02 23:30:00' );
		$this->j->reconSettings->save( array( 'schedule_time' => '02:00' ) );
		$this->h->clock->set( '2026-11-03 00:30:00' );
		$first = $this->j->reconService->tick();
		$this->assertTrue( $first['ran'] );
		$this->assertSame( 'done', $first['run']['status'] );
		$this->assertFalse( $this->j->reconService->tick()['ran'], 'one run for the date' );

		$this->h->clock->set( '2026-11-04 00:30:00' );
		$this->assertTrue( $this->j->reconService->tick()['ran'], 'the next date runs again' );
	}

	public function test_the_overview_has_the_shape_of_the_screen_and_settings_are_validated(): void {
		$overview = $this->j->reconService->overview();
		$this->assertSame( array( 'last_run', 'runs', 'diffs', 'schedule_time', 'window_days', 'tolerance_minor', 'auto_repair_missing' ), array_keys( $overview ) );
		$this->assertSame( '02:00', $overview['schedule_time'] );
		$this->assertSame( 7, $overview['window_days'] );
		$this->assertSame( 1, $overview['tolerance_minor'] );
		$this->assertFalse( $overview['auto_repair_missing'] );

		try {
			$this->j->reconService->saveSettings( array( 'schedule_time' => '25:00', 'window_days' => 0, 'tolerance_minor' => -1, 'auto_repair_missing' => 'x' ) );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertCount( 4, $e->errors() );
		}
		$saved = $this->j->reconService->saveSettings( array( 'window_days' => 14, 'schedule_time' => '03:15' ) );
		$this->assertSame( 14, $saved['window_days'] );
		$this->assertSame( '03:15', $saved['schedule_time'] );
	}
}
