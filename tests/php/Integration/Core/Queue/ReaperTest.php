<?php
/**
 * The reaper.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class ReaperTest extends CoreIntegrationTestCase {

	public function test_a_worker_that_died_is_noticed_after_the_lock_runs_out(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$this->h->queue->claim( 1, 60, 'worker-that-dies' );

		$this->h->clock->advance( 59 );
		$this->assertSame( 0, $this->h->reaper->run(), 'the lock is still good' );
		$this->assertSame( 'syncing', $this->h->queue->findRow( $id )['state'] );

		$this->h->clock->advance( 2 );
		$this->assertSame( 1, $this->h->reaper->run() );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'], 'a dead worker\'s try does not count' );
		$this->assertSame( 'SYNC-LOCK-001', $row['error_code'] );
		$this->assertNull( $this->h->row( $id )['locked_until'] );

		$timeline = $this->h->logs->timeline( $id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'warning', $timeline[0]['level'] );
		$this->assertSame( 'SYNC-LOCK-001', $timeline[0]['code'] );
		$this->assertSame( 'The background task stopped while sending. The item was queued again.', $timeline[0]['message'] );
		$this->assertSame( 0, $this->h->reaper->run(), 'only once' );
	}

	public function test_only_items_being_sent_are_taken_back(): void {
		$conn = $this->h->connection();
		$a    = $this->h->item( $conn, '1' );
		$b    = $this->h->item( $conn, '2' );
		$c    = $this->h->item( $conn, '3' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->markRetrying( $b, '2026-11-02 12:30:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->markFailed( $c, 'SYNC-VAL-002', 'x' );
		$this->h->clock->advance( 3600 );
		$this->assertSame( 1, $this->h->reaper->run() );
		$this->assertSame( 'queued', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'retrying', $this->h->queue->findRow( $b )['state'] );
		$this->assertSame( 'failed', $this->h->queue->findRow( $c )['state'] );
	}

	public function test_a_reaped_item_is_sent_again_and_the_system_is_asked_first(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$items = $this->h->queue->claim( 1, 60, 'dead' );
		// The dead worker had created the order before it died.
		$this->h->connector->send( $this->h->connections->find( $conn ), $items[0], array() );
		$this->h->clock->advance( 61 );
		$this->h->reaper->run();

		$this->h->worker->run();
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( 'SYNC-DUP-001', $row['error_code'], 'found and linked, not created twice' );
		$this->assertCount( 1, $this->h->connector->documents );
	}
}
