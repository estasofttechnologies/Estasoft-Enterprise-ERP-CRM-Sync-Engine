<?php
/**
 * Orders and refunds that have nothing the other system could use.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Domain\EntityType;

defined( 'ABSPATH' ) || exit;

/**
 * Checked before any rule runs, by the payload builder and by the preview, so both show one clear reason instead of a rule that fails
 * further down: an order with no product lines, an order whose products are all left out, or a refund that only covers products that
 * are left out.
 */
final class NothingToSend {

	/**
	 * Why there is nothing to send, or an empty string when there is.
	 *
	 * @param EntityType     $type  Entity type of the item.
	 * @param CanonicalOrder $order What was read.
	 */
	public static function reason( EntityType $type, CanonicalOrder $order ): string {
		if ( EntityType::Order === $type && 0 === $order->count( 'lines[]' ) ) {
			return self::number( $order->get( 'order.excluded_count' ) ) > 0
				? __( 'Every product on the order is marked Exclude from syncing.', 'estasoft-erp-crm-sync-engine' )
				: __( 'The order has no products to send.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( EntityType::Refund === $type
			&& 0 === $order->count( 'refund.lines[]' )
			&& self::number( $order->get( 'refund.excluded_amount' ) ) > 0
			&& 0 === self::number( $order->get( 'refund.shipping_amount' ) )
			&& 0 === self::number( $order->get( 'refund.other_amount' ) )
		) {
			return __( 'Every refunded product is marked Exclude from syncing.', 'estasoft-erp-crm-sync-engine' );
		}
		return '';
	}

	/**
	 * A whole number from a canonical value; anything else counts as zero.
	 *
	 * @param mixed $value Value.
	 */
	private static function number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
