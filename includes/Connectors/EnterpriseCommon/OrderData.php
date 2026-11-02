<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Typed view of the nested array the mapping engine builds from the "orders" rules. Money is integer minor units. The reference always
 * comes from the queue item, never from the payload, so a mapping mistake cannot break the idempotency key.
 */
final class OrderData {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string               $reference        WC-{id}.
	 * @param string               $currency         ISO currency code.
	 * @param string               $orderDate        Order date (Y-m-d, UTC), or empty.
	 * @param string               $note             Order note.
	 * @param array<string,string> $partner          Billing customer fields.
	 * @param array<string,string> $delivery         Delivery address fields.
	 * @param array<int,OrderLine> $lines            Product lines.
	 * @param array<int,Charge>    $charges          Shipping, fee and discount amounts that are not zero.
	 * @param string               $paymentReference Payment reference (transaction id), or empty.
	 * @param int|null             $totalMinor       Total the shop expects the system to show, or null when the mapping gave none.
	 * @param bool                 $pricesIncludeTax True when the mapped prices include tax.
	 * @param array<string,mixed>  $raw              The payload as given, for connector-specific paths.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $currency,
		public readonly string $orderDate,
		public readonly string $note,
		public readonly array $partner,
		public readonly array $delivery,
		public readonly array $lines,
		public readonly array $charges,
		public readonly string $paymentReference,
		public readonly ?int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $raw = array(),
	) {
	}

	/**
	 * The charges of one kind.
	 *
	 * @param string $kind shipping, fee or discount.
	 * @return array<int,Charge>
	 */
	public function chargesOf( string $kind ): array {
		return array_values( array_filter( $this->charges, static fn( Charge $charge ): bool => $charge->kind === $kind ) );
	}

	/**
	 * A text value of the raw payload at a top-level key, trimmed.
	 *
	 * @param string $key Key.
	 */
	public function rawText( string $key ): string {
		return PayloadReader::text( $this->raw[ $key ] ?? '' );
	}

	/**
	 * Note text with the payment reference added, for systems that have a single note field.
	 *
	 * @param string $paymentLabel Words put before the payment reference.
	 */
	public function noteWithPayment( string $paymentLabel ): string {
		$parts = array();
		if ( '' !== $this->note ) {
			$parts[] = $this->note;
		}
		if ( '' !== $this->paymentReference ) {
			$parts[] = $paymentLabel . ' ' . $this->paymentReference;
		}
		return implode( "\n", $parts );
	}
}
