<?php
/**
 * The references the connector writes into Daftra.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One place decides what the purchase-order reference (`po_number`) of a document and the `transaction_id` of a payment look like, so a
 * search and a create can never disagree.
 */
final class DaftraIds {

	/**
	 * The reference of a queue item: WC-{order id} for orders, WC-R-{refund id} for refunds, WC-C-{id} for customers.
	 *
	 * @param Item $item Queue item.
	 */
	public function reference( Item $item ): string {
		return match ( $item->entityType ) {
			EntityType::Refund   => 'WC-R-' . $item->entityId,
			EntityType::Customer => 'WC-C-' . $item->entityId,
			default              => $item->clientReference(),
		};
	}

	/**
	 * The client reference of the order a refund belongs to, read from the partition key `order:{id}`.
	 *
	 * @param Item $item Queue item of the refund.
	 * @return string WC-{order id}, or an empty string when the partition key names no order.
	 */
	public function orderReference( Item $item ): string {
		return 1 === preg_match( '/^order:(\d+)$/', $item->partitionKey, $parts ) ? 'WC-' . $parts[1] : '';
	}

	/**
	 * The transaction id of the payment of an order.
	 *
	 * @param Item $item Queue item of the order.
	 */
	public function paymentReference( Item $item ): string {
		return $this->reference( $item ) . '-PAY';
	}
}
