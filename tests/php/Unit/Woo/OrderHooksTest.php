<?php
/**
 * OrderHooks: WooCommerce events into the queue, without ever disturbing checkout.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Tests\Support\WooFixedClock;
use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\OrderHooks;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\StateMirror;

final class OrderHooksTest extends WooTestCase {

	private WooRecordingQueue $queue;
	private RecordingLog $log;
	private OrderHooks $hooks;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new WooRecordingQueue();
		$this->log   = new RecordingLog();
		$orders      = new OrderMeta();
		$this->hooks = new OrderHooks(
			$this->queue,
			$orders,
			new ProductMeta(),
			new StateMirror( $orders, $this->queue, $this->log ),
			$this->log,
			new WooFixedClock( '2026-11-02 07:40:00' )
		);
	}

	/**
	 * A normal order with one product, placed at 07:34 (six minutes before the fixed clock).
	 *
	 * @param int                 $id    Id.
	 * @param array<string,mixed> $props Properties.
	 * @param array<string,mixed> $meta  Meta.
	 */
	private function placed( int $id = 10490, array $props = array(), array $meta = array() ): \WC_Order {
		return $this->order( $id, $props, array( 'line_item' => array( $this->line( $this->product( 500 + $id, array( 'sku' => 'S' . $id ) ) ) ) ), $meta );
	}

	public function test_classic_checkout_queues_a_created_event(): void {
		$order = $this->placed();
		$this->hooks->onClassicCheckout( 10490, array(), $order );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_block_checkout_queues_a_created_event(): void {
		$this->hooks->onBlocksCheckout( $this->placed() );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_a_repeated_hook_for_the_same_event_is_ignored(): void {
		$order = $this->placed();
		$this->hooks->onClassicCheckout( 10490, array(), $order );
		$this->hooks->onClassicCheckout( 10490, array(), $order );
		$this->hooks->onBlocksCheckout( $order );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_the_queue_state_is_copied_onto_the_order_right_after_queueing(): void {
		$order = $this->placed();
		$this->queue->addOrderItem( 100, 10490, 'queued' );
		$this->hooks->onClassicCheckout( 10490, array(), $order );
		$this->assertSame( 'queued', $order->meta[ OrderMeta::STATE ] );
	}

	public function test_nothing_is_queued_when_there_is_no_connection(): void {
		$this->queue->nextId = null;
		$order               = $this->placed();
		$this->hooks->onClassicCheckout( 10490, array(), $order );
		$this->assertArrayNotHasKey( OrderMeta::STATE, $order->meta );
	}

	public function test_drafts_are_not_queued(): void {
		foreach ( array( 'checkout-draft', 'auto-draft', 'trash' ) as $status ) {
			$this->hooks->onBlocksCheckout( $this->placed( 10490 + strlen( $status ), array( 'status' => $status ) ) );
		}
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_an_order_with_only_excluded_products_is_not_queued(): void {
		$gift = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$this->order( 10490, array(), array( 'line_item' => array( $this->line( $gift ) ) ) );
		$this->hooks->onClassicCheckout( 10490 );
		$this->assertSame( array(), $this->queue->calls );
		$this->assertCount( 1, $this->log->level( 'info' ) );
	}

	public function test_an_order_with_some_excluded_products_is_queued(): void {
		$gift = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$bowl = $this->product( 701, array( 'sku' => 'BOWL' ) );
		$this->order( 10490, array(), array( 'line_item' => array( $this->line( $gift ), $this->line( $bowl ) ) ) );
		$this->hooks->onClassicCheckout( 10490 );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_a_hook_never_throws_even_when_the_queue_does(): void {
		$this->queue->throw = new \RuntimeException( 'database is gone' );
		$this->hooks->onClassicCheckout( 10490, array(), $this->placed() );
		$this->hooks->onBlocksCheckout( $this->placed( 10491 ) );
		$this->hooks->onPaymentComplete( 10490 );
		$this->hooks->onRefundCreated( 1 );
		$this->hooks->onCustomerCreated( 5 );
		$this->assertCount( 3, $this->log->level( 'error' ), 'Each failure is logged, none is raised.' );
		foreach ( $this->log->level( 'error' ) as $entry ) {
			$this->assertStringNotContainsString( 'database is gone', $entry[1] . wp_json_encode( $entry[2] ), 'The message of the exception is not logged.' );
		}
	}

	public function test_hooks_ignore_calls_with_nothing_useful(): void {
		$this->hooks->onClassicCheckout( 0 );
		$this->hooks->onClassicCheckout( 'abc' );
		$this->hooks->onBlocksCheckout( null );
		$this->hooks->onBlocksCheckout( 'not an order' );
		$this->hooks->onPaymentComplete( 999 );
		$this->hooks->onStatusChanged( 999, 'pending', 'processing' );
		$this->hooks->onRefundCreated( 999 );
		$this->hooks->onCustomerCreated( 0 );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_payment_complete_queues_paid_for_an_order_already_in_the_queue(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'synced' ) );
		$this->hooks->onPaymentComplete( 10490 );
		$this->assertSame( array( 'enqueue:order:10490:paid' ), $this->queue->calls );
	}

	public function test_payment_complete_for_an_order_that_was_never_queued_is_left_alone(): void {
		$this->placed();
		$this->hooks->onPaymentComplete( 10490 );
		$this->assertSame( array(), $this->queue->calls, 'Orders placed before go-live are "not sent" until a person sends them.' );
	}

	public function test_cancelling_a_known_order_queues_a_cancelled_event(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'synced' ) );
		$this->hooks->onStatusChanged( 10490, 'processing', 'cancelled' );
		$this->assertSame( array( 'enqueue:order:10490:cancelled' ), $this->queue->calls );
	}

	public function test_a_status_change_of_a_synced_order_queues_one_update_at_the_end_of_the_request(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'synced' ) );
		$this->hooks->onStatusChanged( 10490, 'processing', 'completed' );
		$this->hooks->onOrderUpdated( 10490 );
		$this->hooks->onOrderUpdated( 10490 );
		$this->assertSame( array(), $this->queue->calls, 'Nothing is queued while the request is still saving the order.' );
		$this->hooks->flushUpdates();
		$this->hooks->flushUpdates();
		$this->assertSame( array( 'enqueue:order:10490:updated' ), $this->queue->calls );
	}

	public function test_saving_an_order_that_is_still_waiting_needs_no_update(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'queued' ) );
		$this->hooks->onOrderUpdated( 10490 );
		$this->hooks->onStatusChanged( 10490, 'pending', 'processing' );
		$this->hooks->flushUpdates();
		$this->assertSame( array(), $this->queue->calls, 'The waiting item is built from the latest data when it is sent.' );
	}

	public function test_saving_an_order_that_was_never_queued_does_nothing(): void {
		$this->placed();
		$this->hooks->onOrderUpdated( 10490 );
		$this->hooks->flushUpdates();
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_a_new_order_made_outside_checkout_is_queued_when_it_becomes_live(): void {
		$this->placed( 10490, array( 'date_created' => new \DateTimeImmutable( '2026-11-02 07:35:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->hooks->onStatusChanged( 10490, 'pending', 'processing' );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_an_old_order_with_no_history_is_not_queued_by_a_status_change(): void {
		$this->placed( 10490, array( 'date_created' => new \DateTimeImmutable( '2026-10-20 07:35:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->hooks->onStatusChanged( 10490, 'processing', 'completed' );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_a_new_order_that_is_only_pending_is_not_queued_by_a_status_change(): void {
		$this->placed( 10490 );
		$this->hooks->onStatusChanged( 10490, 'auto-draft', 'pending' );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_a_refund_waits_behind_its_order_in_the_queue(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'synced' ) );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$this->hooks->onRefundCreated( 10500 );
		$this->hooks->onRefundCreated( 10500 );
		$this->assertSame( array( 'enqueue:refund:10500:refunded' ), $this->queue->calls );
		$this->assertSame( array( 'partition' => 'order:10490' ), $this->queue->metas[0] );
	}

	public function test_a_refund_of_an_order_that_was_never_queued_is_not_queued(): void {
		$this->placed();
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$this->hooks->onRefundCreated( 10500 );
		$this->assertSame( array(), $this->queue->calls );
		$this->assertCount( 1, $this->log->level( 'info' ) );
	}

	public function test_a_refund_of_a_skipped_order_is_not_queued(): void {
		$this->placed( 10490, array(), array( OrderMeta::STATE => 'skipped' ) );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$this->hooks->onRefundCreated( 10500 );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_a_new_customer_account_is_queued(): void {
		$this->hooks->onCustomerCreated( 42 );
		$this->hooks->onCustomerCreated( 42 );
		$this->assertSame( array( 'enqueue:customer:42:created' ), $this->queue->calls );
	}
}
