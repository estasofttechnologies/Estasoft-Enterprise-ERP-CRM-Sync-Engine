<?php
/**
 * OrderHooks with the choices made in setup: send orders, send when, send refunds.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Tests\Support\WooFixedChoices;
use Estasoft\SyncEngine\Tests\Support\WooFixedGoLive;
use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\GoLive;
use Estasoft\SyncEngine\Woo\OrderEligibility;
use Estasoft\SyncEngine\Woo\OrderHooks;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\StateMirror;

final class OrderHooksChoicesTest extends WooTestCase {

	private WooRecordingQueue $queue;
	private RecordingLog $log;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new WooRecordingQueue();
		$this->log   = new RecordingLog();
	}

	/**
	 * The hooks for a store that went live on 1 Nov 2026, with the choices made in setup.
	 *
	 * @param bool   $orders  Send orders.
	 * @param string $when    Send when.
	 * @param bool   $refunds Send refunds.
	 */
	private function hooks( bool $orders = true, string $when = 'paid_or_cod', bool $refunds = true ): OrderHooks {
		$meta = new OrderMeta();
		return new OrderHooks(
			$this->queue,
			$meta,
			new OrderEligibility( new ProductMeta() ),
			new StateMirror( $meta, $this->queue, $this->log ),
			$this->log,
			new GoLive( new WooFixedGoLive( '2026-11-01 08:00:00' ) ),
			new WooFixedChoices( $orders, $when, $refunds )
		);
	}

	/**
	 * An order placed on 2 Nov 2026 with one product.
	 *
	 * @param array<string,mixed> $props Properties.
	 * @param array<string,mixed> $meta  Meta.
	 */
	private function placed( array $props = array(), array $meta = array() ): \WC_Order {
		return $this->order( 10490, $props, array( 'line_item' => array( $this->line( $this->product( 990, array( 'sku' => 'S1' ) ) ) ) ), $meta );
	}

	/**
	 * An order paid by card that is still waiting for the payment.
	 *
	 * @param array<string,mixed> $props Extra properties.
	 */
	private function unpaidCard( array $props = array() ): \WC_Order {
		return $this->placed( array_merge( array( 'status' => 'pending', 'payment_method' => 'stripe' ), $props ) );
	}

	public function test_with_send_orders_off_nothing_is_queued_by_any_hook(): void {
		$hooks = $this->hooks( false );
		$order = $this->placed( array(), array( OrderMeta::STATE => 'synced' ) );
		$hooks->onClassicCheckout( 10490, array(), $order );
		$hooks->onBlocksCheckout( $order );
		$hooks->onPaymentComplete( 10490 );
		$hooks->onStatusChanged( 10490, 'processing', 'cancelled' );
		$hooks->onOrderUpdated( 10490, $order );
		$hooks->flushUpdates();
		$hooks->onCustomerCreated( 42 );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$hooks->onRefundCreated( 10500 );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_with_send_orders_on_the_same_hooks_queue(): void {
		$hooks = $this->hooks( true );
		$order = $this->placed( array(), array( OrderMeta::STATE => 'synced' ) );
		$hooks->onCustomerCreated( 42 );
		$hooks->onStatusChanged( 10490, 'processing', 'cancelled' );
		$this->assertSame( array( 'enqueue:customer:42:created', 'enqueue:order:10490:cancelled' ), $this->queue->calls );
		$this->assertInstanceOf( \WC_Order::class, $order );
	}

	public function test_paid_or_cod_does_not_queue_an_unpaid_card_order_at_checkout(): void {
		$hooks = $this->hooks();
		$order = $this->unpaidCard();
		$hooks->onClassicCheckout( 10490, array(), $order );
		$hooks->onBlocksCheckout( $order );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_paid_or_cod_queues_a_cash_on_delivery_order_at_checkout(): void {
		$hooks = $this->hooks();
		$hooks->onClassicCheckout( 10490, array(), $this->placed( array( 'status' => 'pending', 'payment_method' => 'cod' ) ) );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_paid_or_cod_queues_an_order_that_is_already_paid_at_checkout(): void {
		$hooks = $this->hooks();
		$hooks->onBlocksCheckout( $this->placed( array( 'status' => 'processing', 'payment_method' => 'stripe' ) ) );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_paid_or_cod_queues_the_card_order_when_the_payment_completes(): void {
		$hooks = $this->hooks();
		$order = $this->unpaidCard();
		$hooks->onClassicCheckout( 10490, array(), $order );
		$this->assertSame( array(), $this->queue->calls );
		$hooks->onPaymentComplete( 10490 );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_paid_or_cod_waits_for_a_bank_transfer_to_be_marked_paid(): void {
		$hooks = $this->hooks();
		$this->unpaidCard( array( 'status' => 'on-hold', 'payment_method' => 'bacs' ) );
		$hooks->onStatusChanged( 10490, 'pending', 'on-hold' );
		$this->assertSame( array(), $this->queue->calls, 'On hold is not paid.' );
		$hooks->onStatusChanged( 10490, 'on-hold', 'processing' );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_paid_or_cod_queues_a_cash_on_delivery_order_that_goes_on_hold(): void {
		$hooks = $this->hooks();
		$this->placed( array( 'status' => 'on-hold', 'payment_method' => 'cod' ) );
		$hooks->onStatusChanged( 10490, 'pending', 'on-hold' );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_placed_queues_an_unpaid_order_at_checkout_as_before(): void {
		$hooks = $this->hooks( true, 'placed' );
		$hooks->onClassicCheckout( 10490, array(), $this->unpaidCard() );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_placed_queues_an_order_that_goes_on_hold_as_before(): void {
		$hooks = $this->hooks( true, 'placed' );
		$this->unpaidCard( array( 'status' => 'on-hold', 'payment_method' => 'bacs' ) );
		$hooks->onStatusChanged( 10490, 'pending', 'on-hold' );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_completed_waits_until_the_order_is_completed(): void {
		$hooks = $this->hooks( true, 'completed' );
		$order = $this->placed( array( 'status' => 'pending', 'payment_method' => 'cod' ) );
		$hooks->onClassicCheckout( 10490, array(), $order );
		$hooks->onBlocksCheckout( $order );
		$hooks->onPaymentComplete( 10490 );
		$hooks->onStatusChanged( 10490, 'pending', 'processing' );
		$hooks->onStatusChanged( 10490, 'processing', 'on-hold' );
		$this->assertSame( array(), $this->queue->calls );
		$hooks->onStatusChanged( 10490, 'processing', 'completed' );
		$this->assertSame( array( 'enqueue:order:10490:created' ), $this->queue->calls );
	}

	public function test_an_order_already_in_the_queue_still_gets_its_later_events_whatever_send_when_says(): void {
		$hooks = $this->hooks( true, 'completed' );
		$this->placed( array(), array( OrderMeta::STATE => 'synced' ) );
		$hooks->onPaymentComplete( 10490 );
		$hooks->onStatusChanged( 10490, 'processing', 'cancelled' );
		$this->assertSame( array( 'enqueue:order:10490:paid', 'enqueue:order:10490:cancelled' ), $this->queue->calls );
	}

	public function test_the_go_live_rule_still_applies(): void {
		$hooks = $this->hooks( true, 'completed' );
		$this->placed( array( 'date_created' => $this->moment( '2026-10-20 07:34:00' ) ) );
		$hooks->onStatusChanged( 10490, 'processing', 'completed' );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_with_send_refunds_off_a_refund_is_not_queued_and_the_log_says_why(): void {
		$hooks = $this->hooks( true, 'paid_or_cod', false );
		$this->placed( array(), array( OrderMeta::STATE => 'synced' ) );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$hooks->onRefundCreated( 10500 );
		$this->assertSame( array(), $this->queue->calls );
		$this->assertCount( 1, $this->log->level( 'info' ) );
	}

	public function test_with_send_refunds_on_a_refund_is_queued(): void {
		$hooks = $this->hooks();
		$this->placed( array(), array( OrderMeta::STATE => 'synced' ) );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490 ) );
		$hooks->onRefundCreated( 10500 );
		$this->assertSame( array( 'enqueue:refund:10500:refunded' ), $this->queue->calls );
	}

	/**
	 * A date in UTC.
	 *
	 * @param string $utc Moment.
	 */
	private function moment( string $utc ): \DateTimeImmutable {
		return new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
	}
}
