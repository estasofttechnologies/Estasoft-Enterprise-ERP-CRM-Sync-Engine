<?php
/**
 * Puts WooCommerce events in the queue.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\Ports\EventLog;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks used, and what each does:
 *
 * - woocommerce_checkout_order_processed (classic checkout) and woocommerce_store_api_checkout_order_processed (Cart and Checkout blocks):
 *   event "created". The two never both fire for one checkout; a repeat in one request is ignored anyway.
 * - woocommerce_payment_complete: event "paid", for orders already in the queue's history.
 * - woocommerce_order_status_changed: "cancelled" when the order is cancelled; "updated" for other changes of an order already known;
 *   "created" for an order with no history that was created in the last fifteen minutes (admin, REST and programmatic orders do not pass
 *   through checkout). Older orders with no history are "not sent" and stay that way until a person sends them.
 * - woocommerce_update_order: remembers the order, and at shutdown queues one "updated" for each (any save of an order already synced).
 * - woocommerce_refund_created: event "refunded" for the refund, when the parent order is in the queue's history. This hook fires once
 *   per refund and after it is saved; woocommerce_order_refunded is not used because it would fire a second time for the same refund.
 * - woocommerce_created_customer: event "created" for the customer.
 *
 * Every callback catches everything it can throw and logs it: a sync problem must never stop a customer from checking out. A callback
 * does one insert through the queue and one small meta write; it makes no network call. About transactions: this class does not open one.
 * If WooCommerce or the host has a transaction open when the hook fires, the queue row is part of it; otherwise it is a single atomic
 * INSERT right after the order was saved, and the nightly reconciliation finds an order whose process died in between.
 */
final class OrderHooks {

	public const CREATED   = 'created';
	public const PAID      = 'paid';
	public const UPDATED   = 'updated';
	public const REFUNDED  = 'refunded';
	public const CANCELLED = 'cancelled';

	/**
	 * How long after creation an order with no history still counts as new (seconds).
	 */
	private const NEW_ORDER_WINDOW = 900;

	/**
	 * Sync states in which a later change to the order needs a new update sent. A waiting item is built at send time and needs none.
	 */
	private const UPDATABLE_STATES = array( 'synced', 'shadow', 'syncing' );

	/**
	 * Statuses that are never synced.
	 */
	private const IGNORED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash' );

	/**
	 * Events already queued in this request, to ignore repeated hook calls.
	 *
	 * @var array<string,true>
	 */
	private array $seen = array();

	/**
	 * Orders saved in this request that need an update sent.
	 *
	 * @var array<int,true>
	 */
	private array $touched = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param QueueGateway $queue    Queue.
	 * @param OrderMeta    $orders   Order meta.
	 * @param ProductMeta  $products Product meta.
	 * @param StateMirror  $mirror   Copies queue state to the order.
	 * @param EventLog     $log      Event log.
	 * @param Clock        $clock    Clock.
	 */
	public function __construct(
		private readonly QueueGateway $queue,
		private readonly OrderMeta $orders,
		private readonly ProductMeta $products,
		private readonly StateMirror $mirror,
		private readonly EventLog $log,
		private readonly Clock $clock
	) {
	}

	/**
	 * Add the WordPress hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'onClassicCheckout' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'onBlocksCheckout' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'onPaymentComplete' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'onStatusChanged' ), 20, 4 );
		add_action( 'woocommerce_update_order', array( $this, 'onOrderUpdated' ), 20, 1 );
		add_action( 'woocommerce_refund_created', array( $this, 'onRefundCreated' ), 20, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'onCustomerCreated' ), 20, 1 );
		add_action( 'shutdown', array( $this, 'flushUpdates' ), 5 );
	}

	/**
	 * Classic checkout finished creating the order.
	 *
	 * @param mixed $orderId    Order id.
	 * @param mixed $postedData Posted checkout fields (unused).
	 * @param mixed $order      The order, when WooCommerce passes it.
	 */
	public function onClassicCheckout( mixed $orderId = 0, mixed $postedData = null, mixed $order = null ): void {
		$this->guard(
			function () use ( $orderId, $order ): void {
				$this->queueOrder( (int) $orderId, self::CREATED, $order instanceof \WC_Order ? $order : null );
			}
		);
	}

	/**
	 * Block checkout finished creating the order.
	 *
	 * @param mixed $order The order.
	 */
	public function onBlocksCheckout( mixed $order = null ): void {
		$this->guard(
			function () use ( $order ): void {
				if ( $order instanceof \WC_Order ) {
					$this->queueOrder( (int) $order->get_id(), self::CREATED, $order );
				}
			}
		);
	}

	/**
	 * An order was paid.
	 *
	 * @param mixed $orderId Order id.
	 */
	public function onPaymentComplete( mixed $orderId = 0 ): void {
		$this->guard(
			function () use ( $orderId ): void {
				$order = $this->load( (int) $orderId );
				if ( null !== $order && '' !== $this->orders->state( $order ) ) {
					$this->queueOrder( (int) $orderId, self::PAID, $order );
				}
			}
		);
	}

	/**
	 * An order changed status.
	 *
	 * @param mixed $orderId Order id.
	 * @param mixed $from    Old status without the wc- prefix.
	 * @param mixed $to      New status.
	 * @param mixed $order   The order, when WooCommerce passes it.
	 */
	public function onStatusChanged( mixed $orderId = 0, mixed $from = '', mixed $to = '', mixed $order = null ): void {
		$this->guard(
			function () use ( $orderId, $to, $order ): void {
				$order = $order instanceof \WC_Order ? $order : $this->load( (int) $orderId );
				if ( null === $order ) {
					return;
				}
				$status = (string) $to;
				$state  = $this->orders->state( $order );
				$known  = '' !== $state;
				if ( ! $known && $this->isNew( $order ) && in_array( $status, array( 'processing', 'on-hold', 'completed' ), true ) ) {
					$this->queueOrder( (int) $orderId, self::CREATED, $order );
					return;
				}
				if ( ! $known ) {
					return;
				}
				if ( 'cancelled' === $status ) {
					$this->queueOrder( (int) $orderId, self::CANCELLED, $order );
					return;
				}
				if ( in_array( $state, self::UPDATABLE_STATES, true ) ) {
					$this->touched[ (int) $orderId ] = true;
				}
			}
		);
	}

	/**
	 * An order was saved: remember it if it has already been synced.
	 *
	 * @param mixed $orderId Order id.
	 */
	public function onOrderUpdated( mixed $orderId = 0 ): void {
		$this->guard(
			function () use ( $orderId ): void {
				$order = $this->load( (int) $orderId );
				if ( null !== $order && in_array( $this->orders->state( $order ), self::UPDATABLE_STATES, true ) ) {
					$this->touched[ (int) $orderId ] = true;
				}
			}
		);
	}

	/**
	 * At the end of the request, queue one update for each order that was saved.
	 */
	public function flushUpdates(): void {
		$ids           = array_keys( $this->touched );
		$this->touched = array();
		foreach ( $ids as $id ) {
			$this->guard(
				function () use ( $id ): void {
					$order = $this->load( $id );
					if ( null !== $order ) {
						$this->queueOrder( $id, self::UPDATED, $order );
					}
				}
			);
		}
	}

	/**
	 * A refund was created.
	 *
	 * @param mixed $refundId Refund id.
	 */
	public function onRefundCreated( mixed $refundId = 0 ): void {
		$this->guard(
			function () use ( $refundId ): void {
				if ( ! function_exists( 'wc_get_order' ) || $this->seenBefore( 'refund:' . (int) $refundId . ':' . self::REFUNDED ) ) {
					return;
				}
				$refund = wc_get_order( (int) $refundId );
				if ( ! $refund instanceof \WC_Order_Refund ) {
					return;
				}
				$parent = $this->load( (int) $refund->get_parent_id() );
				if ( null === $parent || ! in_array( $this->orders->state( $parent ), array( 'queued', 'syncing', 'synced', 'retrying', 'failed', 'shadow' ), true ) ) {
					$this->log->info( __( 'A refund was not queued because its order was never queued.', 'estasoft-erp-crm-sync-engine' ), array( 'refund_id' => (int) $refundId ) );
					return;
				}
				$this->queue->enqueue(
					EntityType::Refund,
					(string) (int) $refundId,
					self::REFUNDED,
					array( 'partition' => 'order:' . (int) $parent->get_id() )
				);
			}
		);
	}

	/**
	 * A customer account was created.
	 *
	 * @param mixed $customerId User id.
	 */
	public function onCustomerCreated( mixed $customerId = 0 ): void {
		$this->guard(
			function () use ( $customerId ): void {
				$id = (int) $customerId;
				if ( $id > 0 && ! $this->seenBefore( 'customer:' . $id . ':' . self::CREATED ) ) {
					$this->queue->enqueue( EntityType::Customer, (string) $id, self::CREATED );
				}
			}
		);
	}

	/**
	 * Queue an event for an order, unless it is a draft, only has excluded products, or was already queued in this request.
	 *
	 * @param int            $orderId Order id.
	 * @param string         $event   Event.
	 * @param \WC_Order|null $order   The order, if already loaded.
	 */
	private function queueOrder( int $orderId, string $event, ?\WC_Order $order ): void {
		if ( $orderId <= 0 || $this->seenBefore( 'order:' . $orderId . ':' . $event ) ) {
			return;
		}
		$order = $order ?? $this->load( $orderId );
		if ( null === $order || in_array( $order->get_status(), self::IGNORED_STATUSES, true ) ) {
			return;
		}
		if ( $this->hasOnlyExcludedProducts( $order ) ) {
			$this->log->info( __( 'An order was not queued because all of its products are marked Exclude from syncing.', 'estasoft-erp-crm-sync-engine' ), array( 'order_id' => $orderId ) );
			return;
		}
		$queueId = $this->queue->enqueue( EntityType::Order, (string) $orderId, $event );
		if ( null !== $queueId ) {
			$this->mirror->mirrorById( $queueId );
		}
	}

	/**
	 * Whether the order has product lines and every one is an excluded product.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function hasOnlyExcludedProducts( \WC_Order $order ): bool {
		$lines = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			++$lines;
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product || ! $this->products->isExcluded( $product ) ) {
				return false;
			}
		}
		return $lines > 0;
	}

	/**
	 * Whether the order was created in the last fifteen minutes.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function isNew( \WC_Order $order ): bool {
		$created = $order->get_date_created();
		return null !== $created && ( $this->clock->now()->getTimestamp() - $created->getTimestamp() ) <= self::NEW_ORDER_WINDOW;
	}

	/**
	 * Load an order, or null when WooCommerce is missing or there is no such order.
	 *
	 * @param int $orderId Order id.
	 */
	private function load( int $orderId ): ?\WC_Order {
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $orderId );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Whether a key was seen in this request; remembers it.
	 *
	 * @param string $key Key.
	 */
	private function seenBefore( string $key ): bool {
		if ( isset( $this->seen[ $key ] ) ) {
			return true;
		}
		$this->seen[ $key ] = true;
		return false;
	}

	/**
	 * Run a callback so that nothing it throws reaches WooCommerce.
	 *
	 * @param callable $work Work to do.
	 */
	private function guard( callable $work ): void {
		try {
			$work();
		} catch ( \Throwable $e ) {
			$this->log->error( __( 'A WooCommerce event could not be queued. Checkout was not affected.', 'estasoft-erp-crm-sync-engine' ), array( 'error' => get_class( $e ) ) );
		}
	}
}
