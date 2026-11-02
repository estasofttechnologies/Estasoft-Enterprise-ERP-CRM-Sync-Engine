<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the rules of the "orders" mapping (target paths such as partner.email and
 * order_line[].price_unit, see docs/connectors/xero.md) into typed values. Money arrives as integer minor units. The client reference
 * always comes from the queue item, never from the payload, so a mapping mistake cannot break the duplicate protection.
 */
final class XeroOrderPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $clientRef        WC-{id}.
	 * @param string                         $currency         ISO currency code, or empty.
	 * @param array<string,string>           $partner          Billing customer fields.
	 * @param array<string,string>           $delivery         Delivery address fields.
	 * @param array<int,array<string,mixed>> $lines            Product lines: sku, name, quantity, price_minor, discount, tax.
	 * @param array<int,array<string,mixed>> $extras           Shipping, fee and discount lines: kind, name, price_minor, tax.
	 * @param string                         $date             Order date (YYYY-MM-DD), or empty.
	 * @param string                         $note             Order note.
	 * @param bool                           $pricesIncludeTax True when the mapped prices include tax.
	 * @param int|null                       $totalMinor       Total the shop expects Xero to show (order total less the part left out), or null.
	 * @param string                         $paymentReference Payment reference (transaction id).
	 * @param string                         $paymentDate      Day the order was paid, or empty when it is not paid.
	 * @param int                            $attempt          Tries the queue made so far; part of the idempotency key so a retry never gets the answer about a draft that was deleted.
	 */
	private function __construct(
		public readonly string $clientRef,
		public readonly string $currency,
		public readonly array $partner,
		public readonly array $delivery,
		public readonly array $lines,
		public readonly array $extras,
		public readonly string $date,
		public readonly string $note,
		public readonly bool $pricesIncludeTax,
		public readonly ?int $totalMinor,
		public readonly string $paymentReference,
		public readonly string $paymentDate,
		public readonly int $attempt = 0,
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
		$partner = XeroPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return XeroErrors::noEmail( $item->entityId );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return XeroErrors::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$lines = array();
		foreach ( XeroPayloadReader::rows( $payload['order_line'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1 );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return XeroErrors::refused( 'order_line', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$extras = self::extras( $payload );
		if ( $extras instanceof ConnectorError ) {
			return $extras;
		}

		$total = XeroPayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) XeroPayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$paymentBlock = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();

		return new self(
			$item->clientReference(),
			strtoupper( XeroPayloadReader::text( $payload['currency'] ?? '' ) ),
			$partner,
			XeroPayloadReader::address( $payload['delivery'] ?? null ),
			$lines,
			$extras,
			XeroPayloadReader::day( $payload['date_order'] ?? '' ),
			XeroPayloadReader::text( $payload['note'] ?? '' ),
			XeroPayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			$total,
			XeroPayloadReader::text( $paymentBlock['reference'] ?? '' ),
			XeroPayloadReader::day( $paymentBlock['date'] ?? '' ),
			$item->attempt
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
		$sku  = XeroPayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
		$name = XeroPayloadReader::text( $raw['name'] ?? '' );
		if ( '' === $sku && '' === $name ) {
			return XeroErrors::unknownProduct( '#' . $number );
		}
		$quantity = XeroPayloadReader::quantity( $raw['quantity'] ?? null );
		if ( null === $quantity ) {
			return XeroErrors::refused(
				'order_line[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		$price = XeroPayloadReader::minor( $raw['price_unit'] ?? null );
		if ( null === $price ) {
			return XeroErrors::refused(
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
			$codes = XeroPayloadReader::taxCodes( $raw['tax'] );
			if ( count( $codes ) > 1 ) {
				return XeroErrors::refused(
					'order_line[].tax',
					sprintf(
						/* translators: %d: line number. */
						__( 'Line %d has more than one tax code. Xero takes one tax type per line.', 'estasoft-erp-crm-sync-engine' ),
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
	 * Shipping, fee and discount lines. A zero or unreadable amount adds no line.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 */
	private static function extras( array $payload ): array|ConnectorError {
		$extras   = array();
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$blocks   = array( array( 'shipping', $shipping, __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) ) );
		foreach ( XeroPayloadReader::rows( $payload['fee'] ?? null ) as $fee ) {
			$blocks[] = array( 'fee', $fee, __( 'Fee', 'estasoft-erp-crm-sync-engine' ) );
		}
		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$blocks[] = array( 'discount', $discount, __( 'Discount', 'estasoft-erp-crm-sync-engine' ) );

		foreach ( $blocks as $block ) {
			list( $kind, $raw, $fallback ) = $block;
			$amount                        = XeroPayloadReader::minor( $raw['total'] ?? null );
			if ( null === $amount || 0 === $amount ) {
				continue;
			}
			$tax = null;
			if ( array_key_exists( 'tax', $raw ) ) {
				$codes = XeroPayloadReader::taxCodes( $raw['tax'] );
				if ( count( $codes ) > 1 ) {
					return XeroErrors::refused( $kind . '.tax', __( 'More than one tax code on a shipping, fee or discount line. Xero takes one tax type per line.', 'estasoft-erp-crm-sync-engine' ) );
				}
				$tax = array() === $codes ? '' : $codes[0];
			}
			$name     = XeroPayloadReader::text( $raw['name'] ?? '' );
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
