<?php
/**
 * The mapped order payload of an ERP connector, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the rules of the "orders" mapping (target paths such as
 * partner.email and order_line[].price_unit) into typed values. Money arrives as integer minor units. The client reference always comes
 * from the queue item, never from the payload, so a mapping mistake cannot break the duplicate protection.
 */
final class OrderPayload {

	/**
	 * Set up with the values read.
	 *
	 * @param string                         $clientRef        WC-{id}.
	 * @param string                         $currency         ISO currency code, or empty.
	 * @param array<string,string>           $partner          Billing customer fields.
	 * @param array<string,string>           $delivery         Delivery address fields.
	 * @param array<int,array<string,mixed>> $lines            Product lines: sku, name, quantity, price_minor, discount, tax (null or list of codes).
	 * @param array<int,array<string,mixed>> $extras           Shipping, fee and discount: kind, name, amount_minor (positive), tax (null or list).
	 * @param string                         $date             Order date as Y-m-d, or empty.
	 * @param string                         $note             Order note as text.
	 * @param int|null                       $totalMinor       Total the shop expects the system to show, or null when the mapping gives none.
	 * @param bool                           $pricesIncludeTax True when the mapped prices include tax.
	 * @param string                         $paymentReference Payment reference (transaction id).
	 * @param string                         $paidAt           Date paid as Y-m-d, or empty when the order is not paid.
	 * @param bool                           $taxesMapped      True when the mapping supplies tax codes, so an empty code means no tax.
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
		public readonly ?int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly string $paymentReference,
		public readonly string $paidAt,
		public readonly bool $taxesMapped,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload      Mapped payload.
	 * @param Item                $item         The queue item.
	 * @param string              $system       System name for messages.
	 * @param Amounts             $amounts      Conversion helper.
	 * @param bool                $requireBuyer Whether the buyer's email and name are required.
	 * @return self|ConnectorError The payload, or MAP-003 (email missing), MAP-001 (a line without product) or VAL-002 (anything else wrong).
	 */
	public static function parse( array $payload, Item $item, string $system, Amounts $amounts, bool $requireBuyer = true ): self|ConnectorError {
		$partner = PayloadReader::address( $payload['partner'] ?? null );
		if ( $requireBuyer ) {
			if ( '' === ( $partner['email'] ?? '' ) ) {
				return Problems::missingEmail( $item->entityId );
			}
			if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
				return Problems::refused( $system, 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
		}

		$taxesMapped = false;
		$lines       = array();
		foreach ( PayloadReader::rows( $payload['order_line'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1, $system );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$taxesMapped = $taxesMapped || array_key_exists( 'tax', $raw );
			$lines[]     = $line;
		}
		if ( array() === $lines ) {
			return Problems::refused( $system, 'order_line', __( 'The order has no product lines.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$total = PayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) PayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$paymentBlock = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();

		return new self(
			$item->clientReference(),
			strtoupper( PayloadReader::text( $payload['currency'] ?? '' ) ),
			$partner,
			PayloadReader::address( $payload['delivery'] ?? null ),
			$lines,
			self::extras( $payload ),
			$amounts->dateOnly( PayloadReader::text( $payload['date_order'] ?? '' ) ),
			PayloadReader::text( $payload['note'] ?? '' ),
			$total,
			PayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			PayloadReader::text( $paymentBlock['reference'] ?? '' ),
			$amounts->dateOnly( PayloadReader::text( $paymentBlock['paid_at'] ?? '' ) ),
			$taxesMapped
		);
	}

	/**
	 * Whether the order is paid (the mapping supplied a payment date).
	 */
	public function isPaid(): bool {
		return '' !== $this->paidAt;
	}

	/**
	 * Every distinct tax code on the product lines and the extra lines.
	 *
	 * @return array<int,string>
	 */
	public function taxCodes(): array {
		$codes = array();
		foreach ( array_merge( $this->lines, $this->extras ) as $row ) {
			foreach ( (array) ( $row['tax'] ?? array() ) as $code ) {
				if ( '' !== (string) $code && ! in_array( (string) $code, $codes, true ) ) {
					$codes[] = (string) $code;
				}
			}
		}
		return $codes;
	}

	/**
	 * One product line.
	 *
	 * @param array<string,mixed> $raw    Line from the payload.
	 * @param int                 $number Position, for error texts.
	 * @param string              $system System name.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function productLine( array $raw, int $number, string $system ): array|ConnectorError {
		$sku = PayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
		if ( '' === $sku ) {
			return Problems::missingProduct( $system, '#' . $number );
		}
		$quantity = isset( $raw['quantity'] ) && is_numeric( $raw['quantity'] ) ? (float) $raw['quantity'] : 0.0;
		if ( $quantity <= 0.0 ) {
			return Problems::refused(
				$system,
				'order_line[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$number
				)
			);
		}
		$price = PayloadReader::minor( $raw['price_unit'] ?? null );
		if ( null === $price ) {
			return Problems::refused(
				$system,
				'order_line[].price_unit',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no price in minor units (a whole number such as 21900).', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$number
				)
			);
		}
		return array(
			'sku'         => $sku,
			'name'        => PayloadReader::text( $raw['name'] ?? '' ),
			'quantity'    => $quantity,
			'price_minor' => $price,
			'discount'    => isset( $raw['discount'] ) && is_numeric( $raw['discount'] ) ? max( 0.0, min( 100.0, (float) $raw['discount'] ) ) : 0.0,
			'tax'         => array_key_exists( 'tax', $raw ) ? PayloadReader::taxCodes( $raw['tax'] ) : null,
		);
	}

	/**
	 * Shipping, fee and discount rows. A zero or unreadable amount adds no row.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extras( array $payload ): array {
		$extras   = array();
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$row      = self::extra( 'shipping', $shipping, __( 'Shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( null !== $row ) {
			$extras[] = $row;
		}
		foreach ( PayloadReader::rows( $payload['fee'] ?? null ) as $fee ) {
			$row = self::extra( 'fee', $fee, __( 'Fee', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			if ( null !== $row ) {
				$extras[] = $row;
			}
		}
		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$row      = self::extra( 'discount', $discount, __( 'Discount', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( null !== $row ) {
			$row['amount_minor'] = abs( (int) $row['amount_minor'] );
			$extras[]            = $row;
		}
		return $extras;
	}

	/**
	 * One extra row, or null when it has no amount.
	 *
	 * @param string              $kind     shipping, fee or discount.
	 * @param array<string,mixed> $raw      Block from the payload.
	 * @param string              $fallback Name when the block has none.
	 * @return array<string,mixed>|null
	 */
	private static function extra( string $kind, array $raw, string $fallback ): ?array {
		$amount = PayloadReader::minor( $raw['total'] ?? null );
		if ( null === $amount || 0 === $amount ) {
			return null;
		}
		$name = PayloadReader::text( $raw['name'] ?? '' );
		return array(
			'kind'         => $kind,
			'name'         => '' !== $name ? $name : $fallback,
			'amount_minor' => $amount,
			'tax'          => array_key_exists( 'tax', $raw ) ? PayloadReader::taxCodes( $raw['tax'] ) : null,
		);
	}
}
