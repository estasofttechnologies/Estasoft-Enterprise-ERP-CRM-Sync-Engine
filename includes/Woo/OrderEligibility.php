<?php
/**
 * Which orders may be queued at all.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * The checks every way of queuing an order shares: the hooks, the order box button and the bulk actions. A draft (the empty order of
 * the "Add order" screen, a checkout still being filled in, an order in the trash) is never queued, and neither is an order whose
 * product lines are all marked Exclude from syncing.
 */
final class OrderEligibility {

	/**
	 * Statuses that are never synced.
	 */
	public const IGNORED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param ProductMeta $products Product meta (exclusion flag).
	 */
	public function __construct( private readonly ProductMeta $products ) {
	}

	/**
	 * Whether the order is a draft, in the trash, or not saved yet.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function isDraft( \WC_Order $order ): bool {
		return (int) $order->get_id() <= 0 || in_array( $order->get_status(), self::IGNORED_STATUSES, true );
	}

	/**
	 * Whether the order has product lines and every one is an excluded product.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function hasOnlyExcludedProducts( \WC_Order $order ): bool {
		$names = $this->excludedNames( $order );
		return array() !== $names && count( $names ) === $this->lineCount( $order );
	}

	/**
	 * Names of the excluded products on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,string>
	 */
	public function excludedNames( \WC_Order $order ): array {
		$names = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$product = $item->get_product();
				if ( $product instanceof \WC_Product && $this->products->isExcluded( $product ) ) {
					$names[] = (string) $item->get_name();
				}
			}
		}
		return $names;
	}

	/**
	 * Number of product lines on an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function lineCount( \WC_Order $order ): int {
		$count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether a refund has product lines and every one is an excluded product, with no refunded shipping or fee beside them.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 */
	public function refundHasOnlyExcludedProducts( \WC_Order_Refund $refund ): bool {
		$lines = 0;
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			++$lines;
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product || ! $this->products->isExcluded( $product ) ) {
				return false;
			}
		}
		if ( 0 === $lines ) {
			return false;
		}
		foreach ( array( 'shipping', 'fee' ) as $type ) {
			foreach ( $refund->get_items( $type ) as $item ) {
				if ( ( $item instanceof \WC_Order_Item_Shipping || $item instanceof \WC_Order_Item_Fee ) && 0.0 !== (float) $item->get_total() ) {
					return false;
				}
			}
		}
		return true;
	}
}
