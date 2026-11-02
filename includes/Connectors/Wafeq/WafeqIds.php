<?php
/**
 * The references and idempotency keys the connector writes into Wafeq.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One place decides what `external_id` and `X-Wafeq-Idempotency-Key` look like, so a search and a create can never disagree.
 */
final class WafeqIds {

	/**
	 * Longest external id Wafeq accepts.
	 */
	public const MAX_EXTERNAL_ID = 255;

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

	/**
	 * The external id of a contact: the first 24 hex characters of the SHA-256 of the lower-case email. It holds no personal data.
	 *
	 * @param string $email Email address.
	 */
	public function contactExternalId( string $email ): string {
		return 'WC-C-' . substr( hash( 'sha256', strtolower( trim( $email ) ) ), 0, 24 );
	}

	/**
	 * A version 4 shaped UUID derived from the item's idempotency key and a step name. The same item and step always give the same key, so a
	 * retry inside the hour Wafeq remembers keys for is recognised.
	 *
	 * @param Item   $item Queue item.
	 * @param string $step Step name (contact, invoice, payment, credit-note).
	 */
	public function idempotencyKey( Item $item, string $step ): string {
		$hash    = hash( 'sha256', $item->idempotencyKey . '|' . $step );
		$hex     = substr( $hash, 0, 32 );
		$hex[12] = '4';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * The invoice or credit note number for an order: the prefix plus the order number, cut to a sane length.
	 *
	 * @param string $prefix Option prefix.
	 * @param string $number Order number or refund id.
	 */
	public function documentNumber( string $prefix, string $number ): string {
		return mb_substr( $prefix . $number, 0, 64 );
	}
}
