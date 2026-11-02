<?php
/**
 * Adding events to the queue.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Queue\IdempotencyKey;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class EnqueuerTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
	}

	public function test_an_order_event_becomes_one_queued_item_with_its_key(): void {
		$id = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );

		$this->assertGreaterThan( 0, $id );
		$row = $this->h->row( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 'live', $row['mode'] );
		$this->assertSame( 'order', $row['entity_type'] );
		$this->assertSame( '10490', $row['entity_id'] );
		$this->assertSame( 'created', $row['event'] );
		$this->assertSame( 'order:10490', $row['partition_key'] );
		$this->assertSame( (string) $this->conn, $row['connection_id'] );
		$this->assertSame( '5', $row['max_attempts'] );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'created', 0 ), $row['idempotency_key'] );
		$this->assertSame( $id, $this->h->idempotency->queueIdFor( $row['idempotency_key'] ) );
	}

	public function test_nothing_is_sent_and_no_payload_is_built_when_an_event_is_queued(): void {
		$this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( array(), $this->h->connector->calls, 'no call to the system' );
		$this->assertSame( array(), $this->h->builder->built, 'no payload is built in the customer\'s request' );
	}

	public function test_the_same_event_twice_is_one_item(): void {
		$first  = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$second = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$third  = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created', $this->conn );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
		$this->assertSame( 1, $this->h->count( $this->h->tables->idempotency() ) );
	}

	public function test_the_key_survives_the_queue_cleanup(): void {
		$first = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->h->queue->markSynced( $first, '1', 'S1' );
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET finished_at = '2026-01-01 00:00:00' WHERE id = {$first}" ); // phpcs:ignore
		$this->h->queue->prune();
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ), 'the item is gone' );
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ), 'a late duplicate event is still recognised and adds nothing' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ) );
	}

	public function test_different_events_of_one_order_are_separate_items(): void {
		$created = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$paid    = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'paid' );
		$other   = $this->h->enqueuer->enqueue( EntityType::Order, '10491', 'created' );
		$this->assertCount( 3, array_unique( array( $created, $paid, $other ) ) );
		$keys = array_map( fn( int $id ): string => $this->h->row( $id )['idempotency_key'], array( $created, $paid, $other ) );
		$this->assertCount( 3, array_unique( $keys ) );
	}

	public function test_an_edit_joins_a_waiting_update_instead_of_adding_another(): void {
		$first  = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$second = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
		$this->assertSame( 'SYNC-STATE-001', $this->h->queue->findRow( $first )['error_code'], 'it will say the latest version was sent' );
	}

	public function test_an_edit_after_the_update_was_sent_is_a_new_item(): void {
		$first = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->h->queue->markSynced( $first, '1', 'S1' );
		$second = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->assertNotSame( $first, $second );
		$this->h->queue->markSynced( $second, '1', 'S1' );
		$third = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->assertCount( 3, array_unique( array( $first, $second, $third ) ) );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'updated', 1 ), $this->h->row( $second )['idempotency_key'] );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'updated', 2 ), $this->h->row( $third )['idempotency_key'] );
	}

	public function test_an_edit_while_the_update_is_being_sent_is_a_new_item(): void {
		$first = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->h->queue->claim( 1, 60, 'w' );
		$second = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->assertNotSame( $first, $second, 'the data may have changed after it was read' );
		$this->assertTrue( $this->h->queue->findRow( $second )['waiting_for_earlier'] );
	}

	public function test_a_refund_waits_behind_its_own_order(): void {
		$order  = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$refund = $this->h->enqueuer->enqueue( EntityType::Refund, '10555', 'refunded', null, array( 'partition' => 'order:10490' ) );
		$this->assertSame( 'order:10490', $this->h->row( $refund )['partition_key'] );
		$this->assertSame( array( $order ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 10, 60, 'w' ) ) );

		$bare = $this->h->enqueuer->enqueue( EntityType::Refund, '10556', 'refunded' );
		$this->assertSame( 'refund:10556', $this->h->row( $bare )['partition_key'] );
	}

	public function test_display_values_for_the_lists_are_stored(): void {
		$id = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created', null, array( 'label' => '10490', 'customer' => 'Noor', 'total_minor' => 43800, 'currency' => 'AED', 'search' => 'CS-BOWL-04', 'partition' => '', 'ignored' => 'x' ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( '10490', $row['entity_label'] );
		$this->assertSame( 'Noor', $row['customer_name'] );
		$this->assertSame( array( 'minor' => 43800, 'currency' => 'AED' ), $row['total'] );
		$this->assertSame( 'order:10490', $row['partition_key'] );
	}

	public function test_another_module_can_supply_the_display_values_with_a_filter(): void {
		add_filter( 'estasoft_sync_queue_display', static fn( array $d, EntityType $t, string $id ): array => $d + array( 'label' => 'No. ' . $id ), 10, 3 );
		$id = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( 'No. 10490', $this->h->queue->findRow( $id )['entity_label'] );
	}

	public function test_a_site_can_stop_an_event_from_being_queued(): void {
		add_filter( 'estasoft_sync_should_enqueue', static fn( bool $yes, EntityType $type, string $id ): bool => '10490' === $id ? false : $yes, 10, 3 );
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ) );
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ), 'no key is burned for an event that was never queued' );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Order, '10491', 'created' ) );
	}

	public function test_without_a_connection_nothing_is_queued(): void {
		$this->h->connections->delete( $this->conn );
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
		$this->h->reset();
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ), 'no connection at all' );
	}

	public function test_events_the_connector_cannot_send_are_not_queued(): void {
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Customer, '5', 'created' ) );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Refund, '5', 'refunded' ) );
	}

	public function test_paused_connections_still_accept_items(): void {
		$this->h->connections->setStatus( $this->conn, ConnectionStatus::PausedCircuit, '2026-11-02 12:02:00', 'circuit' );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
		$this->h->connections->setStatus( $this->conn, ConnectionStatus::NeedsReconnect );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Order, '10491', 'created' ) );
	}

	public function test_an_event_can_be_sent_to_a_chosen_connection(): void {
		$other = $this->h->connection();
		$id    = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created', $other );
		$this->assertSame( (string) $other, $this->h->row( $id )['connection_id'] );
		$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created', $other + 50 ) );
	}

	public function test_while_the_shadow_window_is_open_new_items_are_shadow_items(): void {
		$this->h->onboarding->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => '2026-11-09T08:00:00Z' ) );
		$shadow = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $shadow )['mode'] );

		$this->h->clock->set( '2026-11-09 08:00:01' );
		$live = $this->h->enqueuer->enqueue( EntityType::Order, '10491', 'created' );
		$this->assertSame( 'live', $this->h->row( $live )['mode'] );
	}

	public function test_the_number_of_allowed_tries_follows_the_connection_settings(): void {
		$short = $this->h->connection( array( 'retry_delays_seconds' => array( 30, 60 ) ) );
		$id    = $this->h->enqueuer->enqueue( EntityType::Order, '1', 'created', $short );
		$this->assertSame( '3', $this->h->row( $id )['max_attempts'] );
	}

	public function test_it_never_throws_and_leaves_no_key_behind_when_the_queue_cannot_be_written(): void {
		$table = $this->h->tables->queue();
		$this->h->sql( "RENAME TABLE `{$table}` TO `{$table}_away`" ); // phpcs:ignore
		try {
			$this->h->wpdb->suppress_errors( true );
			$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
		} finally {
			$this->h->wpdb->suppress_errors( false );
			$this->h->sql( "RENAME TABLE `{$table}_away` TO `{$table}`" ); // phpcs:ignore
		}
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ), 'the failed attempt can be repeated' );
		$this->assertSame( 'error', $this->h->logs->latest( 1 )[0]['level'] );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
	}

	public function test_it_commits_and_rolls_back_with_the_order_transaction(): void {
		$this->h->sql( 'START TRANSACTION' );
		$inside = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertGreaterThan( 0, $inside );
		$this->h->sql( 'ROLLBACK' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ), 'the order was rolled back, so is the queue row' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ), 'and the key, so the event can happen again' );

		$this->h->sql( 'START TRANSACTION' );
		$this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->h->sql( 'COMMIT' );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
	}
}
