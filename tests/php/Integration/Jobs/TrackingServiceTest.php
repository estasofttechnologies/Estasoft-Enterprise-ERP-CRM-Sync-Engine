<?php
/**
 * TRK-1 to TRK-3 and AS-20.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class TrackingServiceTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
		$this->j->trackingService->saveSettings( $this->connection, array( 'tracking_enabled' => true ) );
		$this->j->orders->add( 501, array( 'status' => 'processing', 'payment_method' => 'bacs' ) );
		$this->j->connector->deliveries = array(
			array(
				'order_ref' => 'WC-501',
				'status'    => 'done',
				'carrier'   => 'Aramex',
				'tracking'  => 'AR123',
				'done_at'   => '2026-11-02T09:00:00Z',
			),
		);
	}

	public function test_a_delivery_completes_the_order_with_the_number_in_a_note_and_fires_the_action(): void {
		$calls = $this->listen( 'estasoft_sync_tracking_saved', 2 );

		$summary = $this->j->trackingService->run();

		$this->assertSame( 'completed', $this->j->orders->orders[501]['status'] );
		$this->assertSame( array( array( 'carrier' => 'Aramex', 'number' => 'AR123', 'at' => '2026-11-02T09:00:00Z' ) ), $this->j->orders->tracking[501] );
		$this->assertStringContainsString( 'AR123', $this->j->orders->notes[0][1] );
		$this->assertSame( 1, $summary['saved'] );
		$this->assertSame( 1, $summary['moved'] );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'AR123', $calls[0][1]['number'] );
		$this->assertSame( 'Aramex', $calls[0][1]['carrier'] );
		$recent = $this->j->stockService->overview()['tracking_recent'];
		$this->assertSame( 501, $recent[0]['order_id'] );
	}

	public function test_reading_the_same_delivery_again_changes_nothing(): void {
		$this->j->trackingService->run();
		$this->j->orders->orders[501]['status'] = 'processing';
		$this->j->orders->notes                 = array();
		$calls                                  = $this->listen( 'estasoft_sync_tracking_saved', 2 );

		$summary = $this->j->trackingService->run();

		$this->assertSame( 0, $summary['saved'] );
		$this->assertCount( 1, $this->j->orders->tracking[501] );
		$this->assertSame( array(), $this->j->orders->notes );
		$this->assertCount( 0, $calls );
	}

	public function test_an_order_never_moves_backward_or_out_of_a_closed_status(): void {
		$this->j->trackingService->saveSettings( $this->connection, array( 'delivered_becomes' => 'processing' ) );
		$this->j->orders->orders[501]['status'] = 'completed';
		$this->j->trackingService->run();
		$this->assertSame( 'completed', $this->j->orders->orders[501]['status'] );

		$this->j->trackingService->saveSettings( $this->connection, array( 'delivered_becomes' => 'completed' ) );
		$this->j->orders->orders[501]['status'] = 'cancelled';
		$this->j->trackingService->run();
		$this->assertSame( 'cancelled', $this->j->orders->orders[501]['status'] );
		$this->assertSame( array(), $this->j->orders->statusChanges );
	}

	public function test_cash_on_delivery_queues_one_payment_collected_event_once(): void {
		$this->j->trackingService->saveSettings( $this->connection, array( 'cod_payment_on_delivery' => true ) );
		$this->j->orders->orders[501]['payment_method'] = 'cod';

		$first = $this->j->trackingService->run();
		$this->j->orders->orders[501]['status'] = 'processing';
		$second = $this->j->trackingService->run();

		$this->assertSame( 1, $first['cod_events'] );
		$this->assertSame( 0, $second['cod_events'] );
		$rows = $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( null, 'order', null, null, null, $this->connection ) )->items;
		$this->assertCount( 1, $rows );
		$this->assertSame( 'delivered', $rows[0]['event'] );
		$this->assertSame( '501', $rows[0]['entity_id'] );
	}

	public function test_no_cod_event_for_other_payment_methods_or_when_the_option_is_off(): void {
		$this->j->trackingService->saveSettings( $this->connection, array( 'cod_payment_on_delivery' => true ) );
		$this->j->trackingService->run();
		$this->assertSame( 0, $this->h->queue->countsByState( $this->connection, null )['queued'] );
	}

	public function test_unknown_orders_are_counted_and_other_clients_deliveries_ignored(): void {
		$this->j->connector->deliveries[] = array( 'order_ref' => 'WC-9999', 'status' => 'done', 'carrier' => '', 'tracking' => '', 'done_at' => '2026-11-02T09:05:00Z' );
		$this->j->connector->deliveries[] = array( 'order_ref' => 'SO-12', 'status' => 'done', 'carrier' => '', 'tracking' => '', 'done_at' => '2026-11-02T09:06:00Z' );

		$summary = $this->j->trackingService->run();

		$this->assertSame( 1, $summary['unknown_orders'] );
		$this->assertSame( 1, $summary['deliveries'] );
	}

	public function test_shadow_mode_only_counts(): void {
		$this->j->shadowService->start( $this->connection, 7 );

		$summary = $this->j->trackingService->run();

		$this->assertTrue( $summary['shadow'] );
		$this->assertSame( 1, $summary['deliveries'] );
		$this->assertSame( 'processing', $this->j->orders->orders[501]['status'] );
		$this->assertSame( array(), $this->j->orders->tracking );
		$this->assertSame( array(), $this->j->orders->notes );
	}

	public function test_it_does_nothing_when_off_and_remembers_where_it_stopped(): void {
		$this->j->trackingService->saveSettings( $this->connection, array( 'tracking_enabled' => false ) );
		$this->j->trackingService->run();
		$this->assertSame( 0, $this->j->connector->count( 'fetchTracking' ) );

		$this->j->trackingService->saveSettings( $this->connection, array( 'tracking_enabled' => true ) );
		$this->j->trackingService->run();
		$this->j->trackingService->run();
		$calls = array_values( array_filter( $this->j->connector->calls, static fn( array $call ): bool => 'fetchTracking' === $call[0] ) );
		$this->assertSame( '2026-11-02T09:00:00+00:00', $calls[1][1], 'continues from the last delivery' );
	}

	public function test_a_failure_is_counted_and_the_place_is_kept(): void {
		$this->j->connector->fail = array( 'fetchTracking' => new ConnectorError( ErrorCode::Net001, array( 'system' => 'Fakesystem' ) ) );

		$summary = $this->j->trackingService->run();

		$this->assertSame( 1, $summary['errors'] );
		$this->assertSame( 'processing', $this->j->orders->orders[501]['status'] );
	}

	public function test_settings_are_validated_and_saved_per_connection(): void {
		try {
			$this->j->trackingService->saveSettings( $this->connection, array( 'delivered_becomes' => 'shipped-ish', 'tracking_enabled' => 'maybe' ) );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'delivered_becomes', 'tracking_enabled' ), array_keys( $e->errors() ) );
		}
		$saved = $this->j->trackingService->saveSettings( $this->connection, array( 'delivered_becomes' => 'wc-on-hold', 'cod_payment_on_delivery' => '1' ) );
		$this->assertSame( 'on-hold', $saved['delivered_becomes'] );
		$this->assertTrue( $saved['cod_payment_on_delivery'] );
		$this->assertTrue( $saved['tracking_enabled'], 'fields not sent keep their value' );
	}
}
