<?php
/**
 * Fills in what the queue lists show for an order: its number, the customer, the total and what to search by.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked to the queue's estasoft_sync_queue_display filter, so every caller that queues an order, refund or customer gets the same
 * list values without each one having to build them. Values a caller already supplied are kept. Reads through the data layer only.
 */
final class QueueDisplay {

	/**
	 * Add the filter.
	 */
	public function register(): void {
		add_filter( 'estasoft_sync_queue_display', array( $this, 'fill' ), 10, 4 );
	}

	/**
	 * Add what is missing.
	 *
	 * @param mixed $display  Values so far: label, customer, total_minor, currency, search.
	 * @param mixed $type     Entity type.
	 * @param mixed $entityId WooCommerce id.
	 * @param mixed $event    Event (unused).
	 * @return array<string,mixed>
	 */
	public function fill( mixed $display = array(), mixed $type = null, mixed $entityId = '', mixed $event = '' ): array {
		unset( $event );
		$display = is_array( $display ) ? $display : array();
		if ( ! $type instanceof EntityType || ! function_exists( 'wc_get_order' ) ) {
			return $display;
		}
		try {
			$found = match ( $type ) {
				EntityType::Order    => $this->forOrder( (int) $entityId ),
				EntityType::Refund   => $this->forRefund( (int) $entityId ),
				EntityType::Customer => $this->forCustomer( (int) $entityId ),
				default              => array(),
			};
		} catch ( \Throwable $e ) {
			return $display;
		}
		return $display + $found;
	}

	/**
	 * Values for an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>
	 */
	private function forOrder( int $orderId ): array {
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}
		return array(
			'label'       => (string) $order->get_order_number(),
			'customer'    => $this->name( $order ),
			'total_minor' => MinorUnits::parse( $order->get_total(), MinorUnits::exponent( (string) $order->get_currency() ) ) ?? 0,
			'currency'    => (string) $order->get_currency(),
			'search'      => $this->search( $order ),
		);
	}

	/**
	 * Values for a refund: the order's number and customer, and the refunded amount.
	 *
	 * @param int $refundId Refund id.
	 * @return array<string,mixed>
	 */
	private function forRefund( int $refundId ): array {
		$refund = wc_get_order( $refundId );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return array();
		}
		$parent = wc_get_order( (int) $refund->get_parent_id() );
		if ( ! $parent instanceof \WC_Order ) {
			return array();
		}
		return array(
			'label'       => (string) $parent->get_order_number(),
			'customer'    => $this->name( $parent ),
			'total_minor' => abs( MinorUnits::parse( $refund->get_amount(), MinorUnits::exponent( (string) $parent->get_currency() ) ) ?? 0 ),
			'currency'    => (string) $parent->get_currency(),
			'search'      => $this->search( $parent ),
		);
	}

	/**
	 * Values for a customer.
	 *
	 * @param int $userId User id.
	 * @return array<string,mixed>
	 */
	private function forCustomer( int $userId ): array {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( false === $user ) {
			return array();
		}
		return array(
			'label'    => (string) $user->display_name,
			'customer' => (string) $user->display_name,
			'search'   => (string) $user->user_email,
		);
	}

	/**
	 * The customer's name for the lists.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function name( \WC_Order $order ): string {
		$name = Text::trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return '' === $name ? Text::trim( (string) $order->get_billing_company() ) : $name;
	}

	/**
	 * What to search the queue by: the buyer's email and the SKUs on the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,string>
	 */
	private function search( \WC_Order $order ): array {
		$terms = array( (string) $order->get_billing_email() );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$product = $item->get_product();
				$terms[] = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
			}
		}
		return array_values( array_unique( array_filter( $terms, static fn( string $term ): bool => '' !== $term ) ) );
	}
}
