<?php
/**
 * StateMirror (queue state onto orders) and OrderActions (what the buttons do).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\OrderActions;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\StateMirror;

final class StateMirrorAndActionsTest extends WooTestCase {

	private WooRecordingQueue $queue;
	private RecordingLog $log;
	private StateMirror $mirror;
	private OrderMeta $meta;

	protected function setUp(): void {
		parent::setUp();
		$this->queue  = new WooRecordingQueue();
		$this->log    = new RecordingLog();
		$this->meta   = new OrderMeta();
		$this->mirror = new StateMirror( $this->meta, $this->queue, $this->log );
	}

	public function test_a_synced_item_writes_the_system_number_and_time(): void {
		$order = $this->order( 10490 );
		$this->queue->addOrderItem(
			7,
			10490,
			'synced',
			array(
				'external_id'     => '490',
				'external_ref'    => 'S00490',
				'external_url'    => 'https://odoo.example/web#id=490',
				'finished_at'     => '2026-11-02T07:39:00Z',
				'mapping_version' => 7,
			)
		);
		$this->mirror->mirrorById( 7 );
		$info = $this->meta->read( $order );
		$this->assertSame( 'synced', $info->state );
		$this->assertSame( 'S00490', $info->externalRef );
		$this->assertSame( 'https://odoo.example/web#id=490', $info->externalUrl );
		$this->assertSame( '2026-11-02 07:39:00', $info->lastSync );
		$this->assertSame( 7, $info->mappingVersion );
		$this->assertSame( 0, $order->saves, 'Only meta is saved, never the order.' );
	}

	public function test_a_failed_item_changes_the_state_and_keeps_the_last_sync_facts(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'synced', OrderMeta::EXTERNAL_REF => 'S00490' ) );
		$this->queue->addOrderItem( 8, 10490, 'failed', array( 'error_code' => 'SYNC-MAP-001' ) );
		$this->mirror->mirrorById( 8 );
		$this->assertSame( 'failed', $order->meta[ OrderMeta::STATE ] );
		$this->assertSame( 'S00490', $order->meta[ OrderMeta::EXTERNAL_REF ], 'The earlier sync still happened.' );
	}

	public function test_a_shadow_item_is_marked_shadow_and_records_no_system_number(): void {
		$order = $this->order( 10490 );
		$this->queue->addOrderItem( 9, 10490, 'synced', array( 'mode' => 'shadow', 'external_ref' => 'S00490' ) );
		$this->mirror->mirrorById( 9 );
		$this->assertSame( 'shadow', $order->meta[ OrderMeta::STATE ] );
		$this->assertArrayNotHasKey( OrderMeta::EXTERNAL_REF, $order->meta, 'Nothing was sent, so there is no number to show.' );
	}

	public function test_a_skipped_item_keeps_who_skipped_it(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::SKIP => '{"reason":"old","by":"Layla Haddad","at":"2026-11-02 10:05:00"}' ) );
		$this->queue->addOrderItem( 10, 10490, 'skipped', array( 'skip_reason' => 'Test order' ) );
		$this->mirror->mirrorById( 10 );
		$skip = $this->meta->read( $order )->skip;
		$this->assertSame( 'Test order', $skip['reason'] );
		$this->assertSame( 'Layla Haddad', $skip['by'] );
	}

	public function test_restoring_clears_the_skip_record(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'skipped', OrderMeta::SKIP => '{"reason":"x"}' ) );
		$this->queue->addOrderItem( 11, 10490, 'queued' );
		$this->mirror->mirrorById( 11 );
		$this->assertSame( 'queued', $order->meta[ OrderMeta::STATE ] );
		$this->assertArrayNotHasKey( OrderMeta::SKIP, $order->meta );
	}

	public function test_items_about_refunds_and_customers_are_not_mirrored_onto_the_order(): void {
		$order = $this->order( 10490 );
		$this->queue->addOrderItem( 12, 10490, 'failed', array( 'entity_type' => 'refund' ) );
		$this->mirror->mirrorById( 12 );
		$this->assertSame( array(), $order->meta );
	}

	public function test_an_unknown_item_or_order_is_ignored_and_a_failure_is_logged_not_raised(): void {
		$this->mirror->mirrorById( 404 );
		$this->mirror->onItemChanged( null );
		$this->mirror->onItemChanged( 'abc' );
		$this->queue->addOrderItem( 13, 99999, 'synced' );
		$this->mirror->mirrorById( 13 );
		$this->assertSame( array(), $this->log->entries );

		$throwing = new class() extends WooRecordingQueue {
			public function item( int $itemId ): ?array {
				throw new \RuntimeException( 'x' );
			}
		};
		( new StateMirror( $this->meta, $throwing, $this->log ) )->mirrorById( 1 );
		$this->assertCount( 1, $this->log->level( 'warning' ) );
	}

	public function test_the_hook_callback_reads_the_item_id_from_the_item(): void {
		$order = $this->order( 10490 );
		$this->queue->addOrderItem( 14, 10490, 'retrying' );
		$this->mirror->onItemChanged( (object) array( 'id' => 14 ) );
		$this->assertSame( 'retrying', $order->meta[ OrderMeta::STATE ] );
	}

	public function test_skipping_from_the_order_screen_remembers_who_did_it(): void {
		$order = $this->order( 10490 );
		$this->mirror->noteSkip( $order, 'Test order', 'Layla Haddad', '2026-11-02 10:05:00' );
		$info = $this->meta->read( $order );
		$this->assertSame( 'skipped', $info->state );
		$this->assertSame( 'Layla Haddad', $info->skip['by'] );
	}

	private function actions(): OrderActions {
		return new OrderActions( $this->queue, $this->mirror );
	}

	public function test_sync_again_asks_the_queue_to_send_the_latest_data(): void {
		$this->assertSame( 'queued', $this->actions()->perform( 'sync_again', $this->order( 10490 ) ) );
		$this->assertSame( array( 'syncAgain:10490' ), $this->queue->calls );
		$this->queue->nextId = null;
		$this->assertSame( 'nothing', $this->actions()->perform( 'sync_again', $this->order( 10490 ) ) );
	}

	public function test_send_queues_an_order_that_was_never_sent(): void {
		$order = $this->order( 10490 );
		$this->queue->addOrderItem( 100, 10490, 'queued' );
		$this->assertSame( 'queued', $this->actions()->perform( 'send', $order ) );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
		$this->assertSame( 'queued', $order->meta[ OrderMeta::STATE ] );
	}

	public function test_retry_puts_the_latest_item_back_and_copies_its_state(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'failed' ) );
		$this->queue->addOrderItem( 8, 10490, 'queued' );
		$this->assertSame( 'retried', $this->actions()->perform( 'retry', $order ) );
		$this->assertSame( array( 'retry:8' ), $this->queue->calls );
		$this->assertSame( 'queued', $order->meta[ OrderMeta::STATE ] );
	}

	public function test_skip_records_the_reason_and_the_person(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'failed' ) );
		$this->queue->addOrderItem( 8, 10490, 'failed' );
		$this->assertSame( 'skipped', $this->actions()->perform( 'skip', $order, 'Layla Haddad' ) );
		$this->assertSame( array( 'skip:8:Skipped from the order screen.' ), $this->queue->calls );
		$this->assertSame( 'Layla Haddad', $this->meta->read( $order )->skip['by'] );
	}

	public function test_restore_brings_a_skipped_item_back(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'skipped' ) );
		$this->queue->addOrderItem( 8, 10490, 'queued' );
		$this->assertSame( 'restored', $this->actions()->perform( 'restore', $order ) );
		$this->assertSame( 'queued', $order->meta[ OrderMeta::STATE ] );
	}

	public function test_an_action_the_queue_refuses_reports_nothing_done(): void {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => 'synced' ) );
		$this->queue->addOrderItem( 8, 10490, 'synced' );
		$this->queue->actionResult = false;
		foreach ( array( 'retry', 'skip', 'restore' ) as $todo ) {
			$this->assertSame( 'nothing', $this->actions()->perform( $todo, $order ), $todo );
		}
		$this->assertSame( 'synced', $order->meta[ OrderMeta::STATE ], 'A refused action changes nothing on the order.' );
	}

	public function test_actions_on_an_order_with_no_queue_item_do_nothing(): void {
		foreach ( array( 'retry', 'skip', 'restore' ) as $todo ) {
			$this->assertSame( 'nothing', $this->actions()->perform( $todo, $this->order( 10490 ) ), $todo );
		}
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_an_unknown_action_is_a_failure(): void {
		$this->assertSame( 'failed', $this->actions()->perform( 'delete_everything', $this->order( 10490 ) ) );
		$this->assertSame( array(), $this->queue->calls );
	}
}
