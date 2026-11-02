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

	/**
	 * Item ids from the calls of estasoft_sync_item_state_changed.
	 *
	 * @param \ArrayObject<int,array<int,mixed>> $calls Calls.
	 * @return array<int,int>
	 */
	private function changedIds( \ArrayObject $calls ): array {
		return array_map( static fn( array $args ): int => $args[0]->id, $calls->getArrayCopy() );
	}

	public function test_retry_skip_and_restore_each_announce_the_item_after_the_change(): void {
		$calls = $this->listen( 'estasoft_sync_item_state_changed', 1 );

		$failed = $this->failedItem( '1' );
		$this->h->service->retry( $failed );
		$this->assertSame( array( $failed ), $this->changedIds( $calls ) );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Domain\Item::class, $calls[0][0] );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ItemState::Queued, $calls[0][0]->state, 'the item as it is after the change' );
		$this->assertSame( 0, $calls[0][0]->attempt );

		$this->h->service->skip( $failed, 'Test order' );
		$this->assertSame( array( $failed, $failed ), $this->changedIds( $calls ) );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ItemState::Skipped, $calls[1][0]->state );

		$this->h->service->restore( $failed );
		$this->assertSame( array( $failed, $failed, $failed ), $this->changedIds( $calls ) );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ItemState::Queued, $calls[2][0]->state );
	}

	public function test_nothing_is_announced_for_an_item_that_did_not_change(): void {
		$calls  = $this->listen( 'estasoft_sync_item_state_changed', 1 );
		$synced = $this->h->item( $this->conn, '1' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$this->h->service->retry( $synced );
		$this->h->service->skip( $synced, 'x' );
		$this->h->service->restore( $synced );
		$this->h->service->retry( 424242 );
		$this->assertCount( 0, $calls );
	}

	public function test_the_bulk_forms_announce_every_changed_item_once(): void {
		$calls = $this->listen( 'estasoft_sync_item_state_changed', 1 );
		$a     = $this->failedItem( '1' );
		$b     = $this->failedItem( '2' );
		$synced = $this->h->item( $this->conn, '3' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );

		$this->assertSame( 2, $this->h->service->retryMany( array( $a, $b, $synced ) ) );
		$this->assertSame( array( $a, $b ), $this->changedIds( $calls ) );

		$calls->exchangeArray( array() );
		$this->assertSame( 2, $this->h->service->bulk( array( $a, $b, $synced ), 'skip', 'Bulk' ) );
		$this->assertSame( array( $a, $b ), $this->changedIds( $calls ) );

		$calls->exchangeArray( array() );
		$this->assertSame( 2, $this->h->service->bulk( array( $a, $b ), 'restore' ) );
		$this->assertSame( array( $a, $b ), $this->changedIds( $calls ) );

		$calls->exchangeArray( array() );
		$this->h->queue->markFailed( $a, 'SYNC-VAL-002', 'x' );
		$this->h->queue->markFailed( $b, 'SYNC-VAL-002', 'x' );
		$this->assertSame( 2, $this->h->service->bulk( array( $a, $b ), 'retry' ) );
		$this->assertSame( array( $a, $b ), $this->changedIds( $calls ) );
		$calls->exchangeArray( array() );
		$this->assertSame( 2, $this->h->service->skipMany( array( $a, $b ), 'Many' ) );
		$this->assertSame( 2, $this->h->service->restoreMany( array( $a, $b ) ) );
		$this->assertSame( array( $a, $b, $a, $b ), $this->changedIds( $calls ) );
	}

	public function test_retrying_a_group_announces_each_item_of_it(): void {
		$calls = $this->listen( 'estasoft_sync_item_state_changed', 1 );
		$a     = $this->failedItem( '1', 'CS-A' );
		$b     = $this->failedItem( '2', 'CS-A' );
		$this->failedItem( '3', 'CS-B' );
		$this->assertSame( 2, $this->h->service->retryGroup( 'SYNC-MAP-001', 'SKU CS-A' ) );
		$this->assertSame( array( $a, $b ), $this->changedIds( $calls ) );
	}

	public function test_retrying_a_group_works_through_all_of_it_not_just_the_first_500(): void {
		$conn  = $this->conn;
		$total = 1130;
		$queue = $this->h->tables->queue();
		$now   = '2026-11-02 12:00:00';
		// Raw SQL: far faster than 1,130 round trips through the repository.
		$values = array();
		for ( $i = 1; $i <= $total; ++$i ) {
			$values[] = $this->h->wpdb->prepare( "(%d, 'order', %s, 'created', %s, %s, 'failed', 5, 5, 'SYNC-MAP-001', 'SKU CS-LAMP-07', 1, %s, %s, %s)", $conn, (string) ( 70000 + $i ), 'order:' . ( 70000 + $i ), hash( 'sha256', "k$i" ), $now, $now, $now ); // phpcs:ignore
		}
		$this->h->sql( "INSERT INTO `$queue` (connection_id, entity_type, entity_id, event, partition_key, idempotency_key, state, attempt, max_attempts, error_code, error_cause, failed_before, created_at, updated_at, finished_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore
		$other = $this->failedItem( '5', 'CS-OTHER' );
		$this->assertSame( $total + 1, (int) $this->h->count( $queue ) );

		$moved = $this->h->service->retryGroup( 'SYNC-MAP-001', 'SKU CS-LAMP-07' );

		$this->assertSame( $total, $moved, 'every item of the group, in batches' );
		$this->assertSame( 0, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `$queue` WHERE state = 'failed' AND error_cause = 'SKU CS-LAMP-07'" ) ); // phpcs:ignore
		$this->assertSame( $total, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `$queue` WHERE state = 'queued' AND attempt = 0" ) ); // phpcs:ignore
		$this->assertSame( 'failed', $this->h->queue->findRow( $other )['state'], 'another group is untouched' );
	}

	public function test_retrying_a_group_of_any_cause_works_through_all_of_it_too(): void {
		$queue  = $this->h->tables->queue();
		$now    = '2026-11-02 12:00:00';
		$values = array();
		for ( $i = 1; $i <= 620; ++$i ) {
			$values[] = $this->h->wpdb->prepare( "(%d, 'order', %s, 'created', %s, %s, 'failed', 5, 5, 'SYNC-MAP-001', %s, 1, %s, %s, %s)", $this->conn, (string) ( 80000 + $i ), 'order:' . ( 80000 + $i ), hash( 'sha256', "c$i" ), 'SKU S' . ( $i % 3 ), $now, $now, $now ); // phpcs:ignore
		}
		$this->h->sql( "INSERT INTO `$queue` (connection_id, entity_type, entity_id, event, partition_key, idempotency_key, state, attempt, max_attempts, error_code, error_cause, failed_before, created_at, updated_at, finished_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore
		$this->assertSame( 620, $this->h->service->retryGroup( 'SYNC-MAP-001' ) );
		$this->assertSame( 0, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `$queue` WHERE state = 'failed'" ) ); // phpcs:ignore
	}

	public function test_a_group_that_fails_again_while_it_is_retried_is_not_chased_forever(): void {
		$a = $this->failedItem( '1' );
		$b = $this->failedItem( '2' );
		// An item that fails again right away is not picked a second time in the same call.
		add_action(
			'estasoft_sync_item_state_changed',
			function ( $item ) use ( $a, $b ): void {
				$this->h->queue->markFailed( $item->id, 'SYNC-MAP-001', 'again', 'SKU CS-LAMP-07' );
			}
		);
		$this->assertSame( 2, $this->h->service->retryGroup( 'SYNC-MAP-001', 'SKU CS-LAMP-07' ) );
		$this->assertSame( 'failed', $this->h->queue->findRow( $a )['state'] );
		$this->assertSame( 'failed', $this->h->queue->findRow( $b )['state'] );
	}

	public function test_retrying_many_still_works_on_at_most_500_ids_at_a_time(): void {
		$ids = range( 1, 700 );
		$this->assertSame( 0, $this->h->service->retryMany( $ids ), 'ids that do not exist change nothing' );
	}
}
