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

	public function test_the_ledger_survives_the_queue_cleanup_so_the_next_ordinal_is_not_reused(): void {
		$first = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->h->queue->markSynced( $first, '1', 'S1' );
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET finished_at = '2026-01-01 00:00:00' WHERE id = {$first}" ); // phpcs:ignore
		$this->h->queue->prune();
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ), 'the item is gone' );

		$again = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertNotNull( $again, 'the same event after the first one finished is a new event' );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'created', 1 ), $this->h->row( $again )['idempotency_key'], 'the ledger still knew ordinal 0' );
	}

	/**
	 * @dataProvider everyEvent
	 */
	public function test_a_second_event_of_any_kind_after_the_first_was_synced_is_a_new_item( string $event ): void {
		$first = $this->h->enqueuer->enqueue( EntityType::Order, '10490', $event );
		$this->assertNotNull( $first );
		$this->h->queue->markSynced( $first, '1', 'S1' );

		$second = $this->h->enqueuer->enqueue( EntityType::Order, '10490', $event );
		$this->assertNotNull( $second, "the second $event was not dropped" );
		$this->assertNotSame( $first, $second );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', $event, 1 ), $this->h->row( $second )['idempotency_key'] );
		$this->assertSame( 'queued', $this->h->row( $second )['state'] );

		$this->h->queue->markSynced( $second, '1', 'S1' );
		$third = $this->h->enqueuer->enqueue( EntityType::Order, '10490', $event );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', $event, 2 ), $this->h->row( (int) $third )['idempotency_key'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function everyEvent(): array {
		$events = array( 'created', 'paid', 'updated', 'refunded', 'cancelled', 'delivered' );
		return array_combine( $events, array_map( static fn( string $event ): array => array( $event ), $events ) );
	}

	public function test_a_second_event_while_the_first_is_still_waiting_joins_it(): void {
		foreach ( array( 'cancelled', 'paid', 'delivered' ) as $event ) {
			$first  = $this->h->enqueuer->enqueue( EntityType::Order, '77', $event );
			$second = $this->h->enqueuer->enqueue( EntityType::Order, '77', $event );
			$this->assertSame( $first, $second, $event );
		}
		$this->assertSame( 3, $this->h->count( $this->h->tables->queue() ) );
	}

	public function test_an_update_at_day_95_after_updates_at_day_0_and_day_60_gets_a_fresh_key(): void {
		$this->h->clock->set( '2026-01-01 10:00:00' );
		$day0 = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->h->queue->markSynced( $day0, '1', 'S1' );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'updated', 0 ), $this->h->row( $day0 )['idempotency_key'] );

		$this->h->clock->set( '2026-03-02 10:00:00' ); // Day 60.
		$day60 = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->h->queue->markSynced( $day60, '1', 'S1' );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'updated', 1 ), $this->h->row( $day60 )['idempotency_key'] );

		$this->h->clock->set( '2026-04-06 10:00:00' ); // Day 95: the day 0 key is 95 days old and is cleaned up, the day 60 key is not.
		$this->assertSame( 1, $this->h->idempotency->prune() );
		$this->assertSame( 1, $this->h->count( $this->h->tables->idempotency() ) );
		$this->assertSame( 1, $this->h->idempotency->countFor( $this->conn, EntityType::Order, '10490', 'updated' ), 'a count would say 1, which is the number of the key that is still stored' );

		$day95 = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$this->assertNotNull( $day95, 'the update is not dropped as a repeat' );
		$this->assertNotSame( $day60, $day95 );
		$this->assertSame( IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'updated', 2 ), $this->h->row( $day95 )['idempotency_key'], 'MAX(ordinal) + 1, not a count' );
	}

	public function test_the_next_ordinal_is_the_highest_stored_plus_one(): void {
		$this->assertSame( 0, $this->h->idempotency->nextOrdinal( $this->conn, EntityType::Order, '5', 'paid' ) );
		foreach ( array( 0, 4 ) as $ordinal ) {
			$this->h->idempotency->reserve( IdempotencyKey::make( $this->conn, EntityType::Order, '5', 'paid', $ordinal ), $this->conn, EntityType::Order, '5', 'paid', $ordinal );
		}
		$this->assertSame( 5, $this->h->idempotency->nextOrdinal( $this->conn, EntityType::Order, '5', 'paid' ) );
		$this->assertSame( 0, $this->h->idempotency->nextOrdinal( $this->conn, EntityType::Order, '5', 'cancelled' ), 'another event has its own count' );
		$this->assertSame( 0, $this->h->idempotency->nextOrdinal( $this->conn, EntityType::Order, '6', 'paid' ), 'and another order' );
	}

	public function test_a_duplicate_key_in_the_queue_returns_the_existing_item_and_adds_nothing(): void {
		// The queue holds a finished item with the key, but the ledger has forgotten it (two requests raced, or it was cleaned).
		$key      = IdempotencyKey::make( $this->conn, EntityType::Order, '10490', 'created', 0 );
		$existing = $this->h->queue->enqueue( $this->conn, EntityType::Order, '10490', 'created', 'order:10490', $key );
		$this->h->queue->markSynced( $existing, '1', 'S1' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ) );

		$id = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( $existing, $id, 'the existing item is returned' );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ), 'no second row' );
		$this->assertSame( $existing, $this->h->idempotency->queueIdFor( $key ), 'and the ledger points at it again' );
		$this->assertSame( array(), array_filter( $this->h->logs->latest( 10 ), static fn( array $e ): bool => 'error' === $e['level'] ), 'a duplicate is not an error' );
	}

	public function test_a_priority_can_be_given_and_is_stored(): void {
		$normal = $this->h->enqueuer->enqueue( EntityType::Order, '1', 'created' );
		$urgent = $this->h->enqueuer->enqueue( EntityType::Order, '2', 'created', null, array( 'priority' => 1 ) );
		$silly  = $this->h->enqueuer->enqueue( EntityType::Order, '3', 'created', null, array( 'priority' => 99 ) );
		$this->assertSame( '5', $this->h->row( $normal )['priority'] );
		$this->assertSame( '1', $this->h->row( $urgent )['priority'] );
		$this->assertSame( '9', $this->h->row( $silly )['priority'], 'kept within 0 to 9' );
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

	public function test_while_shadow_mode_is_on_new_items_are_shadow_items(): void {
		$this->h->onboarding->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => '2026-11-09T08:00:00Z' ) );
		$shadow = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $shadow )['mode'] );
	}

	public function test_shadow_mode_does_not_end_by_itself_when_the_planned_date_passes(): void {
		$this->h->onboarding->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => '2026-11-09T08:00:00Z' ) );
		$this->h->clock->set( '2026-11-09 08:00:01' );
		$late = $this->h->enqueuer->enqueue( EntityType::Order, '10491', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $late )['mode'], 'the date is information only' );

		$this->h->clock->set( '2027-02-01 00:00:00' );
		$much_later = $this->h->enqueuer->enqueue( EntityType::Order, '10492', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $much_later )['mode'] );
	}

	public function test_an_unreadable_date_leaves_new_items_in_shadow(): void {
		$this->h->onboarding->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => 'next tuesday-ish' ) );
		$id = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $id )['mode'] );
	}

	public function test_items_are_live_again_only_after_an_explicit_end(): void {
		$this->h->onboarding->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z' ) );
		$shadow = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' );
		$this->assertSame( 'shadow', $this->h->row( $shadow )['mode'] );

		$this->h->onboarding->endShadow( $this->h->clock->now(), 'go_live' );
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

	/**
	 * Record the statements the database receives while work runs.
	 *
	 * @param \Closure $work Work.
	 * @return array<int,string> The statements, in order.
	 */
	private function statementsDuring( \Closure $work ): array {
		$seen   = array();
		$record = static function ( string $query ) use ( &$seen ): string {
			$seen[] = trim( $query );
			return $query;
		};
		add_filter( 'query', $record );
		try {
			$work();
		} finally {
			remove_filter( 'query', $record );
		}
		return $seen;
	}

	/**
	 * Index of the first statement that starts with a text, or -1.
	 *
	 * @param array<int,string> $statements Statements.
	 * @param string            $start      Start of the statement.
	 * @param int               $from       Where to start looking.
	 */
	private function position( array $statements, string $start, int $from = 0 ): int {
		for ( $i = $from, $n = count( $statements ); $i < $n; ++$i ) {
			if ( 0 === stripos( $statements[ $i ], $start ) ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Whether the connection is inside a transaction: a savepoint can be rolled back to only in one.
	 */
	private function inTransaction(): bool {
		$suppress = $this->h->wpdb->suppress_errors( true );
		$this->h->wpdb->query( 'SAVEPOINT estasoft_probe' );
		$inside = false !== $this->h->wpdb->query( 'ROLLBACK TO SAVEPOINT estasoft_probe' );
		$this->h->wpdb->suppress_errors( $suppress );
		return $inside;
	}

	public function test_without_an_open_transaction_the_key_the_row_and_the_link_are_one_transaction_of_their_own(): void {
		$statements = $this->statementsDuring( fn() => $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );

		$begin  = $this->position( $statements, 'START TRANSACTION' );
		$ledger = $this->position( $statements, 'INSERT IGNORE INTO ' . $this->h->tables->idempotency() );
		$queue  = $this->position( $statements, 'INSERT INTO `' . $this->h->tables->queue() . '`' );
		$link   = $this->position( $statements, 'UPDATE ' . $this->h->tables->idempotency() );
		$commit = $this->position( $statements, 'COMMIT' );
		$this->assertGreaterThanOrEqual( 0, $begin );
		$this->assertGreaterThan( $begin, $ledger );
		$this->assertGreaterThan( $ledger, $queue );
		$this->assertGreaterThan( $queue, $link );
		$this->assertGreaterThan( $link, $commit, 'one commit after all three writes' );
		$this->assertSame( -1, $this->position( $statements, 'COMMIT', $commit + 1 ), 'and only one' );
		$this->assertNotContains( 'ROLLBACK', $statements, 'nothing had to be undone' );
		$this->assertFalse( $this->inTransaction(), 'nothing is left open' );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
	}

	public function test_inside_an_open_transaction_it_uses_a_savepoint_and_never_nests_or_commits(): void {
		$this->h->sql( 'START TRANSACTION' );
		$statements = $this->statementsDuring( fn() => $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ) );
		$this->assertTrue( $this->inTransaction(), 'the order\'s transaction is still open' );

		$this->assertSame( -1, $this->position( $statements, 'START TRANSACTION' ), 'no second transaction' );
		$this->assertSame( -1, $this->position( $statements, 'COMMIT' ), 'nothing is committed behind the order\'s back' );
		$this->assertNotContains( 'ROLLBACK', $statements );
		$this->assertGreaterThanOrEqual( 0, $this->position( $statements, 'SAVEPOINT' ) );
		$this->assertGreaterThanOrEqual( 0, $this->position( $statements, 'RELEASE SAVEPOINT' ) );

		// The other connection cannot see the row: it is not committed.
		$other = $this->databaseConnection();
		$this->assertSame( '0', (string) $other->query( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}`" )->fetch_row()[0] );
		$this->h->sql( 'COMMIT' );
		$this->assertSame( '1', (string) $other->query( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}`" )->fetch_row()[0], 'it commits with the order' );
		$other->close();
	}

	public function test_a_failed_write_in_the_middle_leaves_neither_a_key_nor_an_item(): void {
		// Make the last write fail: the link between the key and the item.
		$break = fn( string $query ): string => 0 === stripos( trim( $query ), 'UPDATE ' . $this->h->tables->idempotency() ) ? 'UPDATE estasoft_no_such_table SET x = 1' : $query;
		add_filter( 'query', $break );
		$this->h->wpdb->suppress_errors( true );
		try {
			$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ), 'it never throws' );
		} finally {
			$this->h->wpdb->suppress_errors( false );
			remove_filter( 'query', $break );
		}
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ), 'the row was undone with the rest, not left without its link' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ), 'the key was undone, so the event can happen again' );
		$this->assertSame( 'error', $this->h->logs->latest( 1 )[0]['level'] );
		$this->assertFalse( $this->inTransaction() );
		$this->assertNotNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ), 'and the next try works' );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
	}

	public function test_a_failed_write_inside_the_order_transaction_undoes_only_its_own_work(): void {
		$this->h->sql( 'START TRANSACTION' );
		$before = $this->h->enqueuer->enqueue( EntityType::Order, '1', 'created' );
		$this->assertGreaterThan( 0, $before );

		$break = fn( string $query ): string => 0 === stripos( trim( $query ), 'UPDATE ' . $this->h->tables->idempotency() ) ? 'UPDATE estasoft_no_such_table SET x = 1' : $query;
		add_filter( 'query', $break );
		$this->h->wpdb->suppress_errors( true );
		try {
			$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '2', 'created' ) );
		} finally {
			$this->h->wpdb->suppress_errors( false );
			remove_filter( 'query', $break );
		}
		$this->assertTrue( $this->inTransaction(), 'the order\'s transaction goes on' );
		$after = $this->h->enqueuer->enqueue( EntityType::Order, '3', 'created' );
		$this->assertGreaterThan( 0, $after );
		$this->h->sql( 'COMMIT' );

		$this->assertSame( array( $before, $after ), array_map( 'intval', $this->h->wpdb->get_col( "SELECT id FROM `{$this->h->tables->queue()}` ORDER BY id" ) ), 'the first and the third event are kept, the failed one is not' ); // phpcs:ignore
		$this->assertSame( 2, $this->h->count( $this->h->tables->idempotency() ) );
	}

	public function test_a_failed_write_inside_the_order_transaction_rolls_back_with_the_order_as_well(): void {
		$this->h->sql( 'START TRANSACTION' );
		$this->h->enqueuer->enqueue( EntityType::Order, '1', 'created' );
		$this->h->sql( 'ROLLBACK' );
		$this->assertSame( 0, $this->h->count( $this->h->tables->queue() ) );
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ) );
	}

	public function test_an_exception_in_the_middle_is_caught_logged_and_undone(): void {
		$boom = function ( string $query ): string {
			if ( 0 === stripos( trim( $query ), 'INSERT INTO `' . $this->h->tables->queue() . '`' ) ) {
				throw new \RuntimeException( 'boom' );
			}
			return $query;
		};
		add_filter( 'query', $boom );
		try {
			$this->assertNull( $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'created' ), 'checkout is never blocked' );
		} finally {
			remove_filter( 'query', $boom );
		}
		$this->assertSame( 0, $this->h->count( $this->h->tables->idempotency() ), 'the key was undone' );
		$this->assertFalse( $this->inTransaction(), 'no transaction was left open' );
		$logged = $this->h->logs->latest( 1 )[0];
		$this->assertSame( 'error', $logged['level'] );
		$this->assertStringContainsString( 'RuntimeException', $logged['message'] );
		$this->assertStringNotContainsString( 'boom', $logged['message'] );
	}

	public function test_an_event_that_joins_a_waiting_item_writes_inside_the_unit_too(): void {
		$first      = $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' );
		$statements = $this->statementsDuring( fn() => $this->h->enqueuer->enqueue( EntityType::Order, '10490', 'updated' ) );
		$this->assertGreaterThanOrEqual( 0, $this->position( $statements, 'START TRANSACTION' ) );
		$this->assertGreaterThanOrEqual( 0, $this->position( $statements, 'COMMIT' ) );
		$this->assertSame( 'SYNC-STATE-001', $this->h->queue->findRow( $first )['error_code'] );
	}

	public function test_the_unit_helper_passes_a_null_result_through_as_success(): void {
		$ran = $this->h->queue->atomically( static fn() => null );
		$this->assertNull( $ran );
		$this->assertFalse( $this->h->queue->atomically( static fn() => false ) );
		$this->assertSame( 5, $this->h->queue->atomically( static fn() => 5 ) );
		$this->assertFalse( $this->inTransaction() );
		$this->expectException( \LogicException::class );
		try {
			$this->h->queue->atomically( static function (): never {
				throw new \LogicException( 'passed on' );
			} );
		} finally {
			$this->assertFalse( $this->inTransaction() );
		}
	}

	/**
	 * A second database connection, to see what is committed.
	 */
	private function databaseConnection(): \mysqli {
		$link = new \mysqli( (string) ( getenv( 'WP_DB_HOST' ) ?: 'localhost' ), (string) ( getenv( 'WP_DB_USER' ) ?: 'root' ), (string) ( getenv( 'WP_DB_PASSWORD' ) ?: '' ), (string) ( getenv( 'WP_DB_NAME' ) ?: 'estasoft_test' ) );
		$this->assertSame( 0, $link->connect_errno, (string) $link->connect_error );
		return $link;
	}
}
