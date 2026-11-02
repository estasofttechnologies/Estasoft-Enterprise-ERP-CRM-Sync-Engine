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

	/**
	 * Claim the item and let the worker die: its lock runs out and the reaper runs.
	 *
	 * @return int How many items the reaper took back.
	 */
	private function dieOnce(): int {
		$this->assertCount( 1, $this->h->queue->claim( 1, 60, 'dies-' . uniqid() ) );
		$this->h->clock->advance( 61 );
		return $this->h->reaper->run();
	}

	public function test_an_item_that_keeps_killing_its_worker_is_stopped_after_three_times(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );

		$this->assertSame( 1, $this->dieOnce() );
		$this->assertSame( '1', $this->h->row( $id )['reaps'] );
		$this->assertSame( 'queued', $this->h->row( $id )['state'] );
		$this->assertSame( 1, $this->dieOnce() );
		$this->assertSame( '2', $this->h->row( $id )['reaps'] );
		$this->assertSame( 'queued', $this->h->row( $id )['state'], 'twice is still worth another try' );

		$this->assertSame( 1, $this->dieOnce() );
		$row = $this->h->row( $id );
		$this->assertSame( 'failed', $row['state'], 'the third time it is set aside' );
		$this->assertSame( '3', $row['reaps'] );
		$this->assertSame( 'SYNC-LOCK-001', $row['error_code'] );
		$this->assertStringContainsString( '3 times', (string) $row['error_message'] );
		$this->assertStringContainsString( 'person', (string) $row['error_message'] );
		$this->assertSame( 'Worker stopped 3 times', $row['error_cause'] );
		$this->assertSame( '1', $row['failed_before'] );
		$this->assertNotNull( $row['finished_at'] );
		$this->assertNull( $row['locked_by'] );
		$this->assertNull( $row['locked_until'] );
		$this->assertSame( '0', $row['attempt'], 'no try was counted for a worker that died' );

		$this->assertSame( array(), $this->h->queue->claim( 5, 60, 'next' ), 'it is not sent again by itself' );
		$this->assertSame( 0, $this->h->reaper->run() );

		$errors = array_values( array_filter( $this->h->logs->timeline( $id ), static fn( array $e ): bool => 'error' === $e['level'] ) );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'SYNC-LOCK-001', $errors[0]['code'] );

		$groups = $this->h->queue->attentionGroups();
		$this->assertSame( 'SYNC-LOCK-001', $groups[0]['error_code'], 'it shows on Needs attention' );
		$this->assertSame( array( $id ), $groups[0]['item_ids'] );
	}

	public function test_a_persons_retry_starts_the_count_again(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$this->dieOnce();
		$this->dieOnce();
		$this->dieOnce();
		$this->assertSame( 'failed', $this->h->row( $id )['state'] );

		$this->assertNotNull( $this->h->service->retry( $id ) );
		$row = $this->h->row( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( '0', $row['reaps'] );
		$this->assertNull( $row['error_code'] );

		$this->dieOnce();
		$this->dieOnce();
		$this->assertSame( 'queued', $this->h->row( $id )['state'], 'two more is not three' );
		$this->assertSame( '2', $this->h->row( $id )['reaps'] );
	}

	public function test_restoring_a_skipped_item_starts_the_count_again(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$this->dieOnce();
		$this->dieOnce();
		$this->h->queue->skip( $id, 'x' );
		$this->h->queue->restore( $id );
		$this->assertSame( '0', $this->h->row( $id )['reaps'] );
	}

	public function test_a_worker_that_lives_long_enough_to_report_clears_the_count(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$this->dieOnce();
		$this->dieOnce();
		$this->assertSame( '2', $this->h->row( $id )['reaps'] );

		$items = $this->h->queue->claim( 1, 60, 'lives' );
		$this->h->queue->markRetrying( $id, '2026-11-02 14:00:00', 'SYNC-NET-001', 'x', '', 'lives' );
		$this->assertSame( '0', $this->h->row( $id )['reaps'], 'the item did not kill this one' );
		$this->assertCount( 1, $items );
	}

	public function test_the_other_items_of_an_order_wait_behind_a_stopped_item(): void {
		$conn  = $this->h->connection();
		$first = $this->h->item( $conn, '10490', 'created' );
		$next  = $this->h->item( $conn, '10490', 'paid' );
		$this->dieOnce();
		$this->dieOnce();
		$this->dieOnce();
		$this->assertSame( 'failed', $this->h->row( $first )['state'] );
		$this->assertSame( array(), $this->h->queue->claim( 5, 60, 'w' ), 'QUE-4: a failed step holds back the later ones' );
		$this->assertSame( 'queued', $this->h->row( $next )['state'] );
	}
}
