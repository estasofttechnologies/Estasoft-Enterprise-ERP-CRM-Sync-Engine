<?php
/**
 * The references the connector writes into Qoyod.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One place decides what the `reference` of a document looks like, so a search and a create can never disagree.
 */
final class QoyodIds {

	/**
	 * Text added to the description of a document that has not passed the check.
	 */
	public const UNCHECKED = ' (unchecked)';

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
	 * The reference of the payment of an order.
	 *
	 * @param Item $item Queue item of the order.
	 */
	public function paymentReference( Item $item ): string {
		return $this->reference( $item ) . '-PAY';
	}
}
