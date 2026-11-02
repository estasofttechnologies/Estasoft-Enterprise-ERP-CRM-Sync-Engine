<?php
/**
 * What a person can do with queue items.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class QueueServiceTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
		wp_unschedule_hook( Scheduler::HOOK_RUN );
	}

	protected function tearDown(): void {
		wp_unschedule_hook( Scheduler::HOOK_RUN );
		wp_set_current_user( 0 );
		Scheduler::schedule();
		parent::tearDown();
	}

	/**
	 * Fail an item the way a missing product does.
	 *
	 * @param string $order Order id.
	 * @param string $sku   SKU.
	 */
	private function failedItem( string $order, string $sku = 'CS-LAMP-07' ): int {
		$id = $this->h->item( $this->conn, $order );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', "Product $sku was not found in Fakesystem.", "SKU $sku" );
		return $id;
	}

	/**
	 * Whether a queue run is scheduled for now.
	 */
	private function runRequested(): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ Scheduler::HOOK_RUN ] ) ) {
				foreach ( $events[ Scheduler::HOOK_RUN ] as $event ) {
					if ( array() !== $event['args'] ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	public function test_retrying_a_failed_item_queues_it_again_and_asks_for_a_run(): void {
		$id  = $this->failedItem( '1' );
		$row = $this->h->service->retry( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertTrue( $this->runRequested() );
	}

	public function test_only_failed_and_retrying_items_can_be_retried(): void {
		$synced = $this->h->item( $this->conn, '1' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$queued = $this->h->item( $this->conn, '2' );
		$this->assertNull( $this->h->service->retry( $synced ) );
		$this->assertNull( $this->h->service->retry( $queued ) );
		$this->assertNull( $this->h->service->retry( 999999 ) );
		$this->assertFalse( $this->runRequested(), 'nothing changed, no run' );
	}

	public function test_a_group_is_retried_together(): void {
		$a = $this->failedItem( '1', 'CS-LAMP-07' );
		$b = $this->failedItem( '2', 'CS-LAMP-07' );
		$c = $this->failedItem( '3', 'CS-BOWL-04' );
		$this->assertSame( 2, $this->h->service->retryGroup( 'SYNC-MAP-001', 'SKU CS-LAMP-07' ) );
		$this->assertSame( 'queued', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'queued', $this->h->queue->findRow( $b )['state'] );
		$this->assertSame( 'failed', $this->h->queue->findRow( $c )['state'] );
		$this->assertSame( 0, $this->h->service->retryGroup( 'SYNC-MAP-002' ) );
	}

	public function test_a_group_that_is_fixed_goes_through_on_the_next_run(): void {
		$a = $this->failedItem( '1' );
		$b = $this->failedItem( '2' );
		$this->h->service->retryGroup( 'SYNC-MAP-001', 'SKU CS-LAMP-07' );
		$this->h->settings->update( array( 'global_concurrency' => 5 ) );
		$report = $this->h->worker->run();
		$this->assertSame( 2, $report->synced );
		$this->assertSame( 'synced', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'synced', $this->h->queue->findRow( $b )['state'] );
	}

	public function test_skipping_keeps_the_reason_and_restoring_brings_it_back(): void {
		$id   = $this->failedItem( '1' );
		$skip = $this->h->service->skip( $id, 'Entered by hand in the ERP' );
		$this->assertSame( 'skipped', $skip['state'] );
		$this->assertSame( 'Entered by hand in the ERP', $skip['skip_reason'] );
		$this->assertNull( $this->h->service->skip( $id, 'again' ), 'already skipped' );

		$restored = $this->h->service->restore( $id );
		$this->assertSame( 'queued', $restored['state'] );
		$this->assertNull( $restored['skip_reason'] );
		$this->assertNull( $this->h->service->restore( $id ) );
	}

	public function test_a_skip_without_a_reason_gets_a_default_one(): void {
		$id = $this->failedItem( '1' );
		$this->assertSame( 'Skipped by a person.', $this->h->service->skip( $id, '  ' )['skip_reason'] );
	}

	public function test_an_item_being_sent_cannot_be_skipped(): void {
		$id = $this->h->item( $this->conn, '1' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->assertNull( $this->h->service->skip( $id, 'no' ) );
		$this->assertSame( 'syncing', $this->h->queue->findRow( $id )['state'] );
	}

	public function test_bulk_skip_retry_and_restore(): void {
		$a = $this->failedItem( '1' );
		$b = $this->failedItem( '2' );
		$this->assertSame( 2, $this->h->service->bulk( array( $a, $b ), 'skip', 'Test orders' ) );
		$this->assertSame( 'skipped', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 2, $this->h->service->bulk( array( $a, $b, $a ), 'restore' ) );
		$this->assertSame( 'queued', $this->h->queue->findRow( $b )['state'] );
		$this->h->queue->markFailed( $a, 'SYNC-VAL-002', 'x' );
		$this->assertSame( 1, $this->h->service->bulk( array( $a, $b ), 'retry' ) );
		$this->assertNull( $this->h->service->bulk( array( $a ), 'explode' ) );
	}

	public function test_a_bulk_action_is_capped_and_ignores_bad_ids(): void {
		$ids = array( 0, -4, 'x', null );
		$this->assertSame( 0, $this->h->service->retryMany( $ids ) );
		$many = range( 1, 700 );
		$this->assertSame( 0, $this->h->service->retryMany( $many ), 'no such items, but the list is handled' );
	}

	public function test_what_people_do_is_written_to_the_log_with_their_user_id(): void {
		wp_set_current_user( 1 );
		$id = $this->failedItem( '1' );
		$this->h->service->retry( $id );
		$this->h->queue->markFailed( $id, 'SYNC-VAL-002', 'x' );
		$this->h->service->skip( $id, 'because' );
		$this->h->service->restore( $id );
		$messages = array_column( $this->h->logs->timeline( $id ), 'message' );
		$this->assertSame( array( 'Retry requested by user #1.', 'Skipped by user #1.', 'Restored by user #1.' ), $messages );
	}

	public function test_sync_now_asks_for_a_run(): void {
		$this->assertTrue( $this->h->service->syncNow() );
		$this->assertTrue( $this->runRequested() );
	}

	public function test_detail_has_every_field_of_queue_item_detail(): void {
		$id = $this->h->item( $this->conn, '10485', 'created', null, \Estasoft\SyncEngine\Domain\ItemMode::Live, array( 'label' => '10485' ) );
		$this->h->item( $this->conn, '10485', 'paid' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'CS-BOWL-04' ), 200, null, null, 'No product with the reference CS-BOWL-04' );
		$this->h->worker->run();

		$detail = $this->h->service->detail( $id );
		$keys   = array( 'id', 'connection_id', 'entity_type', 'entity_id', 'entity_label', 'event', 'state', 'mode', 'attempt', 'max_attempts', 'next_attempt_at', 'error_code', 'error_message', 'external_id', 'external_ref', 'external_url', 'skip_reason', 'customer_name', 'total', 'waiting_for_earlier', 'created_at', 'updated_at', 'finished_at', 'order_edit_url', 'idempotency_key', 'partition_key', 'mapping_version', 'timeline', 'request_excerpt', 'response_excerpt', 'log_id', 'siblings' );
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $detail, $key );
		}
		$this->assertSame( array(), array_diff( array_keys( $detail ), $keys ) );
		$this->assertSame( 'failed', $detail['state'] );
		$this->assertSame( 'order:10485', $detail['partition_key'] );
		$this->assertSame( 'SYNC-MAP-001', $detail['timeline'][0]['code'] );
		$this->assertSame( 'No product with the reference CS-BOWL-04', $detail['response_excerpt'] );
		$this->assertStringContainsString( 'WC-10485', (string) $detail['request_excerpt'] );
		$this->assertCount( 2, $detail['siblings'] );
		$this->assertSame( 'created', $detail['siblings'][0]['event'] );
		$this->assertNull( $this->h->service->detail( 999999 ) );
	}

	public function test_detail_of_an_item_that_was_never_tried_has_empty_excerpts(): void {
		$id     = $this->h->item( $this->conn, '1' );
		$detail = $this->h->service->detail( $id );
		$this->assertSame( array(), $detail['timeline'] );
		$this->assertNull( $detail['request_excerpt'] );
		$this->assertNull( $detail['response_excerpt'] );
		$this->assertNull( $detail['log_id'] );
	}
}
