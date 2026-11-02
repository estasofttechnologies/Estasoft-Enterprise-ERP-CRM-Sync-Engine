<?php
/**
 * Claiming items: ordering, connection state, locks.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class QueueClaimTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
	}

	/**
	 * Set columns of a queue row.
	 *
	 * @param int                 $id      Queue id.
	 * @param array<string,mixed> $columns Column to value (null for NULL).
	 */
	private function set( int $id, array $columns ): void {
		$sets = array();
		foreach ( $columns as $name => $value ) {
			$sets[] = "`$name` = " . ( null === $value ? 'NULL' : $this->h->wpdb->prepare( '%s', $value ) );
		}
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET " . implode( ', ', $sets ) . " WHERE id = {$id}" ); // phpcs:ignore
	}

	/**
	 * Ids of the claimed items.
	 *
	 * @param array<int,\Estasoft\SyncEngine\Domain\Item> $items Items.
	 * @return array<int,int>
	 */
	private function ids( array $items ): array {
		return array_map( static fn( $item ): int => $item->id, $items );
	}

	public function test_a_claim_marks_the_item_syncing_and_locks_it_for_sixty_seconds(): void {
		$id    = $this->h->item( $this->conn );
		$items = $this->h->queue->claim( 5, 60, 'worker-a' );

		$this->assertSame( array( $id ), $this->ids( $items ) );
		$this->assertSame( ItemState::Syncing, $items[0]->state );
		$this->assertSame( EntityType::Order, $items[0]->entityType );
		$this->assertSame( 'WC-10490', $items[0]->clientReference() );
		$row = $this->h->row( $id );
		$this->assertSame( 'syncing', $row['state'] );
		$this->assertSame( 'worker-a', $row['locked_by'] );
		$this->assertSame( '2026-11-02 12:01:00', $row['locked_until'] );
	}

	public function test_an_item_is_claimed_only_once(): void {
		$this->h->item( $this->conn, '1' );
		$this->h->item( $this->conn, '2' );
		$first  = $this->h->queue->claim( 10, 60, 'worker-a' );
		$second = $this->h->queue->claim( 10, 60, 'worker-b' );
		$this->assertCount( 2, $first );
		$this->assertSame( array(), $second );
	}

	public function test_the_limit_is_respected_and_items_come_oldest_first(): void {
		$ids = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$ids[] = $this->h->item( $this->conn, (string) $i );
		}
		$this->assertSame( array( $ids[0], $ids[1] ), $this->ids( $this->h->queue->claim( 2, 60, 'w' ) ) );
		$this->assertSame( array( $ids[2] ), $this->ids( $this->h->queue->claim( 1, 60, 'w2' ) ) );
	}

	public function test_a_later_step_of_the_same_order_waits_for_the_earlier_one(): void {
		$created = $this->h->item( $this->conn, '10490', 'created' );
		$paid    = $this->h->item( $this->conn, '10490', 'paid' );
		$other   = $this->h->item( $this->conn, '10491', 'created' );

		$this->assertSame( array( $created, $other ), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ), 'paid waits behind created; the other order is free' );

		$this->h->queue->markSynced( $created, '1', 'S1' );
		$this->assertSame( array( $paid ), $this->ids( $this->h->queue->claim( 10, 60, 'w2' ) ), 'it is free as soon as the earlier step is synced' );
	}

	public function test_an_earlier_step_that_is_being_sent_blocks_the_next(): void {
		$this->h->item( $this->conn, '7', 'created' );
		$later = $this->h->item( $this->conn, '7', 'paid' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->assertSame( array(), $this->ids( $this->h->queue->claim( 10, 60, 'w2' ) ) );
		$this->assertSame( 'queued', $this->h->row( $later )['state'] );
	}

	public function test_an_earlier_failed_step_blocks_the_next_until_a_person_acts(): void {
		$created = $this->h->item( $this->conn, '7', 'created' );
		$paid    = $this->h->item( $this->conn, '7', 'paid' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->markFailed( $created, 'SYNC-MAP-001', 'Product X was not found in Fake.', 'SKU X' );

		$this->assertSame( array(), $this->ids( $this->h->queue->claim( 10, 60, 'w2' ) ), 'failed blocks' );

		$this->h->queue->retry( $created );
		$this->assertSame( array( $created ), $this->ids( $this->h->queue->claim( 10, 60, 'w3' ) ) );
		$this->h->queue->markSynced( $created, '1', 'S1' );
		$this->assertSame( array( $paid ), $this->ids( $this->h->queue->claim( 10, 60, 'w4' ) ) );
	}

	public function test_a_skipped_step_does_not_block(): void {
		$created = $this->h->item( $this->conn, '7', 'created' );
		$paid    = $this->h->item( $this->conn, '7', 'paid' );
		$this->h->queue->skip( $created, 'Entered by hand in the ERP' );
		$this->assertSame( array( $paid ), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ) );
	}

	public function test_a_retrying_step_blocks_the_next_even_before_it_is_due(): void {
		$created = $this->h->item( $this->conn, '7', 'created' );
		$paid    = $this->h->item( $this->conn, '7', 'paid' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->markRetrying( $created, '2026-11-02 12:05:00', 'SYNC-NET-001', 'Couldn’t reach Fake.' );

		$this->assertSame( array(), $this->ids( $this->h->queue->claim( 10, 60, 'w2' ) ), 'not due yet, and the later step must not overtake' );

		$this->h->clock->advance( 301 );
		$this->assertSame( array( $created ), $this->ids( $this->h->queue->claim( 10, 60, 'w3' ) ), 'due now' );
		$this->assertSame( 'queued', $this->h->row( $paid )['state'] );
	}

	public function test_an_item_waits_for_its_next_attempt_time(): void {
		$id = $this->h->item( $this->conn );
		$this->set( $id, array( 'state' => 'retrying', 'next_attempt_at' => '2026-11-02 12:00:30' ) );
		$this->assertSame( array(), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ) );
		$this->h->clock->advance( 30 );
		$this->assertSame( array( $id ), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ), 'due exactly at the time' );
	}

	public function test_items_of_a_paused_or_inactive_connection_are_not_claimed(): void {
		$this->h->item( $this->conn );
		foreach ( array( ConnectionStatus::Paused, ConnectionStatus::PausedCircuit, ConnectionStatus::NeedsReconnect, ConnectionStatus::Disconnected ) as $status ) {
			$this->h->connections->setStatus( $this->conn, $status );
			$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ), $status->value );
		}
		$this->h->connections->setStatus( $this->conn, ConnectionStatus::Active );
		$this->assertCount( 1, $this->h->queue->claim( 10, 60, 'w' ) );
	}

	public function test_a_timed_pause_that_has_not_ended_blocks_claiming(): void {
		$this->h->item( $this->conn );
		$this->h->sql( "UPDATE `{$this->h->tables->connections()}` SET paused_until = '2026-11-02 12:10:00' WHERE id = {$this->conn}" ); // phpcs:ignore
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ) );
		$this->h->clock->advance( 600 );
		$this->assertCount( 1, $this->h->queue->claim( 10, 60, 'w' ) );
	}

	public function test_a_lock_that_has_not_run_out_protects_the_row(): void {
		$id = $this->h->item( $this->conn );
		$this->set( $id, array( 'locked_until' => '2026-11-02 12:00:30', 'locked_by' => 'someone' ) );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ) );
		$this->h->clock->advance( 31 );
		$this->assertSame( array( $id ), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ) );
	}

	public function test_claiming_can_be_limited_to_one_connection(): void {
		$other = $this->h->connection();
		$a     = $this->h->item( $this->conn, '1' );
		$b     = $this->h->item( $other, '2' );
		$this->assertSame( array( $b ), $this->ids( $this->h->queue->claim( 10, 60, 'w', $other ) ) );
		$this->assertSame( array( $a ), $this->ids( $this->h->queue->claim( 10, 60, 'w', $this->conn ) ) );
	}

	public function test_the_same_order_number_on_two_connections_does_not_block_across_them(): void {
		$other = $this->h->connection();
		$a     = $this->h->item( $this->conn, '5', 'created' );
		$b     = $this->h->item( $other, '5', 'created' );
		$this->assertSame( array( $a, $b ), $this->ids( $this->h->queue->claim( 10, 60, 'w' ) ) );
	}

	public function test_shadow_items_are_claimed_like_live_ones(): void {
		$id    = $this->h->item( $this->conn, '9', 'created', null, ItemMode::Shadow );
		$items = $this->h->queue->claim( 10, 60, 'w' );
		$this->assertSame( array( $id ), $this->ids( $items ) );
		$this->assertSame( ItemMode::Shadow, $items[0]->mode );
	}

	public function test_synced_and_failed_items_are_never_claimed_again(): void {
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$this->h->queue->claim( 10, 60, 'w' );
		$this->h->queue->markSynced( $a, '1', 'S1' );
		$this->h->queue->markFailed( $b, 'SYNC-VAL-002', 'x' );
		$this->h->clock->advance( 3600 );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w2' ) );
	}

	public function test_claim_with_nothing_waiting_returns_nothing(): void {
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ) );
	}

	/**
	 * Make other workers win the race for the rows a claim is about to take: just before the claim's UPDATE runs, a thief takes
	 * the same rows. This is what happens when several workers look at the same moment.
	 *
	 * @param int $times How many of the claim's UPDATE statements are beaten.
	 * @return \Closure Removes the filter.
	 */
	private function stealBeforeTheUpdate( int $times ): \Closure {
		$count = 0;
		$steal = function ( string $query ) use ( &$count, $times ): string {
			if ( $count < $times && 1 === preg_match( '/^UPDATE `?' . preg_quote( $this->h->tables->queue(), '/' ) . "`? SET state = 'syncing', locked_by = 'claimer'.*?\\s+WHERE\\s+id IN \\(([0-9,]+)\\)/s", trim( $query ), $match ) ) {
				++$count;
				$this->h->wpdb->query( "UPDATE `{$this->h->tables->queue()}` SET state = 'syncing', locked_by = 'thief', locked_until = '2099-01-01 00:00:00' WHERE id IN ({$match[1]})" ); // phpcs:ignore
			}
			return $query;
		};
		add_filter( 'query', $steal );
		return static function () use ( $steal ): void {
			remove_filter( 'query', $steal );
		};
	}

	public function test_a_claim_that_loses_a_race_looks_again_instead_of_reporting_an_empty_queue(): void {
		// Four lost races take at most 4 x 3 rows, so 24 rows can never all be taken from under it.
		for ( $i = 1; $i <= 24; ++$i ) {
			$this->h->item( $this->conn, (string) ( 900 + $i ) );
		}
		$undo = $this->stealBeforeTheUpdate( 4 );
		try {
			$claimed = $this->h->queue->claim( 3, 60, 'claimer' );
		} finally {
			$undo();
		}
		$this->assertNotEmpty( $claimed, 'rows were still waiting, so the answer is not empty' );
		$ids = array_map( static fn( $item ) => $item->id, $claimed );
		$this->assertSame( $ids, array_map( 'intval', $this->h->wpdb->get_col( "SELECT id FROM `{$this->h->tables->queue()}` WHERE locked_by = 'claimer' ORDER BY id" ) ), 'and what it reports is exactly what it holds' ); // phpcs:ignore
		$this->assertSame( array(), array_intersect( $ids, array_map( 'intval', $this->h->wpdb->get_col( "SELECT id FROM `{$this->h->tables->queue()}` WHERE locked_by = 'thief'" ) ) ), 'none of the rows the thief took' ); // phpcs:ignore
	}

	public function test_an_empty_answer_still_means_nothing_is_waiting(): void {
		$this->assertSame( array(), $this->h->queue->claim( 3, 60, 'claimer' ) );
		$id = $this->h->item( $this->conn, '1' );
		$this->h->queue->claim( 1, 60, 'someone' );
		$this->assertSame( array(), $this->h->queue->claim( 3, 60, 'claimer' ), 'the only item is taken' );
		$this->assertSame( 'syncing', $this->h->queue->findRow( $id )['state'] );
	}

	public function test_a_claim_that_always_loses_gives_up_with_an_empty_answer_and_changes_nothing(): void {
		for ( $i = 1; $i <= 3; ++$i ) {
			$this->h->item( $this->conn, (string) ( 700 + $i ) );
		}
		$undo = $this->stealBeforeTheUpdate( 1000 );
		try {
			$this->assertSame( array(), $this->h->queue->claim( 3, 60, 'claimer' ) );
		} finally {
			$undo();
		}
		$this->assertSame( 0, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE locked_by = 'claimer'" ) ); // phpcs:ignore
	}
}
