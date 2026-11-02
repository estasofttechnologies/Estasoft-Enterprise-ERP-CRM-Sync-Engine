<?php
/**
 * The marker that makes a Holded document findable again.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Holded has no external-id field. The connector writes `[WC-10490] 79.98 EUR` as the first line of a document's notes: the bracketed reference
 * is what a later search looks for, and the amount and currency are what the document was meant to add up to. A document whose total no longer
 * matches that amount is a document that failed its check, and is not taken for a finished one.
 */
final class HoldedIds {

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
	 * The bracketed reference a search looks for.
	 *
	 * @param string $reference Reference such as WC-10490.
	 */
	public function marker( string $reference ): string {
		return '[' . $reference . ']';
	}

	/**
	 * The first line of the notes.
	 *
	 * @param string $reference Reference such as WC-10490.
	 * @param string $amount    The total the document should show, as a decimal string.
	 * @param string $currency  Currency code.
	 */
	public function line( string $reference, string $amount, string $currency ): string {
		return $this->marker( $reference ) . ' ' . $amount . ' ' . strtoupper( $currency );
	}

	/**
	 * The amount and currency a notes text promises for a reference.
	 *
	 * @param string $notes     Notes text.
	 * @param string $reference Reference such as WC-10490.
	 * @return array{amount:string,currency:string}|null Null when the notes do not carry the promise.
	 */
	public function promise( string $notes, string $reference ): ?array {
		$pattern = '/' . preg_quote( $this->marker( $reference ), '/' ) . '\s+(-?\d+(?:\.\d+)?)\s+([A-Za-z]{3})(?![A-Za-z])/';
		if ( 1 !== preg_match( $pattern, $notes, $parts ) ) {
			return null;
		}
		return array(
			'amount'   => $parts[1],
			'currency' => strtoupper( $parts[2] ),
		);
	}
}
