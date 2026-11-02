<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the rules of the "orders" mapping (target paths such as partner.email and
 * order_line[].price_unit, see docs/connectors/myob.md) into typed values. Money arrives as integer minor units. The client reference
 * always comes from the queue item, never from the payload, so a mapping mistake cannot break the duplicate protection.
 */
final class MyobOrderPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $clientRef        WC-{id}.
	 * @param string                         $currency         ISO currency code, or empty.
	 * @param array<string,string>           $partner          Billing customer fields.
	 * @param array<string,string>           $delivery         Delivery address fields.
	 * @param array<int,array<string,mixed>> $lines            Product lines: sku, name, quantity, price_minor, discount, tax.
	 * @param array<int,array<string,mixed>> $extras           Fee and discount lines: kind, name, price_minor, tax.
	 * @param int                            $freightMinor     Shipping amount (freight) in minor units, 0 when none.
	 * @param string|null                    $freightTax       Tax code of the freight, empty for untaxed, null for MYOB's default.
	 * @param string                         $date             Order date (YYYY-MM-DD), or empty.
	 * @param string                         $note             Order note.
	 * @param bool                           $pricesIncludeTax True when the mapped prices include tax.
	 * @param int|null                       $totalMinor       Total the shop expects MYOB to show (order total less the part left out), or null.
	 * @param string                         $paymentReference Payment reference (transaction id).
	 * @param string                         $paymentDate      Day the order was paid, or empty when it is not paid.
	 */
	private function __construct(
		public readonly string $clientRef,
		public readonly string $currency,
		public readonly array $partner,
		public readonly array $delivery,
		public readonly array $lines,
		public readonly array $extras,
		public readonly int $freightMinor,
		public readonly ?string $freightTax,
		public readonly string $date,
		public readonly string $note,
		public readonly bool $pricesIncludeTax,
		public readonly ?int $totalMinor,
		public readonly string $paymentReference,
		public readonly string $paymentDate,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item.
	 * @return self|ConnectorError The payload, or MAP-003 (email missing), MAP-001 (a line without product) or VAL-002 (anything else wrong).
	 */
	public static function parse( array $payload, Item $item ): self|ConnectorError {
		$partner = MyobPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return MyobErrors::noEmail( $item->entityId );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return MyobErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$date = MyobPayloadReader::day( $payload['date_order'] ?? '' );
		if ( '' === $date ) {
			return MyobErrors::refused( 'date_order', __( 'The order has no date. MYOB needs the date of the invoice.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$lines = array();
		foreach ( MyobPayloadReader::rows( $payload['order_line'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1 );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return MyobErrors::refused( 'order_line', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$extras = self::extras( $payload );
		if ( $extras instanceof ConnectorError ) {
			return $extras;
		}
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$freight  = (int) MyobPayloadReader::minor( $shipping['total'] ?? null );
		$freeTax  = null;
		if ( 0 !== $freight && array_key_exists( 'tax', $shipping ) ) {
			$codes = MyobPayloadReader::taxCodes( $shipping['tax'] );
			if ( count( $codes ) > 1 ) {
				return MyobErrors::refused( 'shipping.tax', __( 'More than one tax code on the shipping. MYOB takes one tax code per line.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$freeTax = array() === $codes ? '' : $codes[0];
		}

		$total = MyobPayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) MyobPayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$paymentBlock = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();

		return new self(
			$item->clientReference(),
			strtoupper( MyobPayloadReader::text( $payload['currency'] ?? '' ) ),
			$partner,
			MyobPayloadReader::address( $payload['delivery'] ?? null ),
			$lines,
			$extras,
			$freight,
			$freeTax,
			$date,
			MyobPayloadReader::text( $payload['note'] ?? '' ),
			MyobPayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			$total,
			MyobPayloadReader::text( $paymentBlock['reference'] ?? '' ),
			MyobPayloadReader::day( $paymentBlock['date'] ?? '' )
		);
	}

	/**
	 * One product line.
	 *
	 * @param array<string,mixed> $raw    Line from the payload.
	 * @param int                 $number Position, for error texts.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function productLine( array $raw, int $number ): array|ConnectorError {
		$sku  = MyobPayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
		$name = MyobPayloadReader::text( $raw['name'] ?? '' );
		if ( '' === $sku ) {
			return MyobErrors::unknownProduct( '' !== $name ? $name : '#' . $number );
		}
		$quantity = MyobPayloadReader::quantity( $raw['quantity'] ?? null );
		if ( null === $quantity ) {
			return MyobErrors::refused(
				'order_line[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		$price = MyobPayloadReader::minor( $raw['price_unit'] ?? null );
		if ( null === $price ) {
			return MyobErrors::refused(
				'order_line[].price_unit',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no price in minor units (a whole number such as 21900).', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		$tax = null;
		if ( array_key_exists( 'tax', $raw ) ) {
			$codes = MyobPayloadReader::taxCodes( $raw['tax'] );
			if ( count( $codes ) > 1 ) {
				return MyobErrors::refused(
					'order_line[].tax',
					sprintf(
						/* translators: %d: line number. */
						__( 'Line %d has more than one tax code. MYOB takes one tax code per line.', 'estasoft-erp-crm-sync-engine' ),
						$number
					)
				);
			}
			$tax = array() === $codes ? '' : $codes[0];
		}
		return array(
			'sku'         => $sku,
			'name'        => $name,
			'quantity'    => $quantity,
			'price_minor' => $price,
			'discount'    => isset( $raw['discount'] ) && is_numeric( $raw['discount'] ) ? max( 0.0, min( 100.0, (float) $raw['discount'] ) ) : 0.0,
			'tax'         => $tax,
		);
	}

	/**
	 * Fee and discount lines. A zero or unreadable amount adds no line. Shipping is the invoice's freight, not a line.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 */
	private static function extras( array $payload ): array|ConnectorError {
		$extras = array();
		$blocks = array();
		foreach ( MyobPayloadReader::rows( $payload['fee'] ?? null ) as $fee ) {
			$blocks[] = array( 'fee', $fee, __( 'Fee', 'estasoft-erp-crm-sync-engine' ) );
		}
		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$blocks[] = array( 'discount', $discount, __( 'Discount', 'estasoft-erp-crm-sync-engine' ) );

		foreach ( $blocks as $block ) {
			list( $kind, $raw, $fallback ) = $block;
			$amount                        = MyobPayloadReader::minor( $raw['total'] ?? null );
			if ( null === $amount || 0 === $amount ) {
				continue;
			}
			$tax = null;
			if ( array_key_exists( 'tax', $raw ) ) {
				$codes = MyobPayloadReader::taxCodes( $raw['tax'] );
				if ( count( $codes ) > 1 ) {
					return MyobErrors::refused( $kind . '.tax', __( 'More than one tax code on a fee or discount line. MYOB takes one tax code per line.', 'estasoft-erp-crm-sync-engine' ) );
				}
				$tax = array() === $codes ? '' : $codes[0];
			}
			$name     = MyobPayloadReader::text( $raw['name'] ?? '' );
			$extras[] = array(
				'kind'        => $kind,
				'name'        => '' !== $name ? $name : $fallback,
				'price_minor' => 'discount' === $kind ? -abs( $amount ) : $amount,
				'tax'         => $tax,
			);
		}
		return $extras;
	}
}
