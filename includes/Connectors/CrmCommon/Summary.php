<?php
/**
 * The plain-text summary of an order or refund that goes into a note or description.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the text from the payload only. The client reference is alone on the first line, so a cut never removes it and a search can rely on it.
 */
final class Summary {

	/**
	 * The order as text.
	 *
	 * @param OrderData $order Order.
	 */
	public static function order( OrderData $order ): string {
		$cur   = $order->currency;
		$lines = array( $order->reference );
		/* translators: 1: order date */
		$lines[] = sprintf( __( 'Order date: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $order->date );
		$name    = $order->fullName();
		/* translators: 1: customer name, 2: customer email */
		$lines[] = sprintf( __( 'Customer: %1$s <%2$s>', 'estasoft-enterprise-erpcrm-sync-engine' ), $name, $order->email() );
		$lines[] = __( 'Items:', 'estasoft-enterprise-erpcrm-sync-engine' );
		foreach ( $order->lines as $line ) {
			$label = '' !== $line->sku ? $line->name . ' (' . $line->sku . ')' : $line->name;
			/* translators: 1: quantity, 2: product, 3: line total with currency */
			$lines[] = '- ' . sprintf( __( '%1$s x %2$s: %3$s', 'estasoft-enterprise-erpcrm-sync-engine' ), $line->quantity, $label, Amounts::decimal( $line->totalMinor, $cur ) . ' ' . $cur );
		}
		if ( $order->shippingMinor > 0 ) {
			/* translators: 1: amount with currency */
			$lines[] = sprintf( __( 'Shipping: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), Amounts::decimal( $order->shippingMinor, $cur ) . ' ' . $cur );
		}
		foreach ( $order->fees as $fee ) {
			$lines[] = ( '' !== $fee['name'] ? $fee['name'] : __( 'Fee', 'estasoft-enterprise-erpcrm-sync-engine' ) ) . ': ' . Amounts::decimal( $fee['total'], $cur ) . ' ' . $cur;
		}
		if ( $order->discountMinor > 0 ) {
			/* translators: 1: amount with currency, 2: coupon codes */
			$lines[] = sprintf( __( 'Discount: -%1$s %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ), Amounts::decimal( $order->discountMinor, $cur ) . ' ' . $cur, '' !== $order->discountCodes ? '(' . $order->discountCodes . ')' : '' );
		}
		/* translators: 1: amount with currency */
		$lines[] = sprintf( __( 'Total: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), Amounts::decimal( $order->totalMinor, $cur ) . ' ' . $cur );
		if ( '' !== $order->note ) {
			/* translators: 1: order note */
			$lines[] = sprintf( __( 'Note: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $order->note );
		}
		return implode( "\n", $lines );
	}

	/**
	 * The refund as text.
	 *
	 * @param RefundData $refund Refund.
	 */
	public static function refund( RefundData $refund ): string {
		$amount = '' !== $refund->currency ? Amounts::decimal( $refund->amountMinor, $refund->currency ) . ' ' . $refund->currency : (string) $refund->amountMinor;
		/* translators: 1: order reference, 2: amount with currency */
		$text = $refund->reference . "\n" . sprintf( __( 'Refund of order %1$s: %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->orderRef, $amount );
		if ( '' !== $refund->reason ) {
			/* translators: 1: reason */
			$text .= "\n" . sprintf( __( 'Reason: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $refund->reason );
		}
		return $text;
	}
}
