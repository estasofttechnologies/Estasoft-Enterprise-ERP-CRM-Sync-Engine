<?php
/**
 * Reads and checks a mapped order payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the "orders" rules (target paths such as partner.email and
 * order_line[].price_unit, see the connector's document) into an OrderData. Money arrives as integer minor units.
 */
final class OrderParser {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string  $system        System name for messages.
	 * @param Amounts $amounts       Conversion helper.
	 * @param bool    $requireEmail  Whether the customer's email and name are required (false when every order goes to one fixed customer).
	 */
	public function __construct( private readonly string $system, private readonly Amounts $amounts, private readonly bool $requireEmail = true ) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item.
	 * @return OrderData|ConnectorError The order, or MAP-001 (a line without a product), MAP-003 (email missing) or VAL-002.
	 */
	public function parse( array $payload, Item $item ): OrderData|ConnectorError {
		$partner = PayloadReader::address( $payload['partner'] ?? null );
		if ( $this->requireEmail ) {
			if ( '' === ( $partner['email'] ?? '' ) ) {
				return Problem::missingEmail( $this->system, $item->entityId );
			}
			if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
				return Problem::validation( $this->system, 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
		}
		$currency = strtoupper( PayloadReader::text( $payload['currency'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return Problem::validation( $this->system, 'currency', __( 'The order has no currency code such as USD.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lines = array();
		foreach ( PayloadReader::rows( $payload['order_line'] ?? null ) as $index => $raw ) {
			$line = $this->line( $raw, $index + 1 );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return Problem::validation( $this->system, 'order_line', __( 'The order has no product lines.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$total = PayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) PayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$block = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : array();
		return new OrderData(
			$item->clientReference(),
			$currency,
			PayloadReader::date( $payload['order_date'] ?? '' ),
			PayloadReader::text( $payload['note'] ?? '' ),
			$partner,
			PayloadReader::address( $payload['delivery'] ?? null ),
			$lines,
			$this->charges( $payload ),
			PayloadReader::text( $block['reference'] ?? '' ),
			$total,
			PayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			$payload
		);
	}

	/**
	 * One product line.
	 *
	 * @param array<string,mixed> $raw    Line from the payload.
	 * @param int                 $number Position, for error texts.
	 */
	private function line( array $raw, int $number ): OrderLine|ConnectorError {
		$sku = PayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
		if ( '' === $sku ) {
			return Problem::unknownSku( $this->system, '#' . $number );
		}
		$quantity = $raw['quantity'] ?? null;
		if ( ! is_numeric( $quantity ) || (float) $quantity <= 0.0 ) {
			return Problem::validation(
				$this->system,
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
			return Problem::validation(
				$this->system,
				'order_line[].price_unit',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no price in minor units (a whole number such as 21900).', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$number
				)
			);
		}
		return new OrderLine(
			$sku,
			PayloadReader::text( $raw['name'] ?? '' ),
			$this->amounts->quantityText( is_string( $quantity ) ? $quantity : ( is_float( $quantity ) ? $quantity : (int) $quantity ) ),
			$price,
			array_key_exists( 'tax', $raw ) ? PayloadReader::taxCodes( $raw['tax'] ) : null
		);
	}

	/**
	 * Shipping, fee and discount amounts. A zero or unreadable amount adds nothing.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,Charge>
	 */
	private function charges( array $payload ): array {
		$charges  = array();
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$charge   = $this->charge( 'shipping', $shipping, __( 'Shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( null !== $charge ) {
			$charges[] = $charge;
		}
		foreach ( PayloadReader::rows( $payload['fee'] ?? null ) as $fee ) {
			$charge = $this->charge( 'fee', $fee, __( 'Fee', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			if ( null !== $charge ) {
				$charges[] = $charge;
			}
		}
		$discount = isset( $payload['discount'] ) && is_array( $payload['discount'] ) ? $payload['discount'] : array();
		$charge   = $this->charge( 'discount', $discount, __( 'Discount', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( null !== $charge ) {
			$charges[] = new Charge( 'discount', $charge->name, -abs( $charge->amountMinor ), $charge->taxCodes );
		}
		return $charges;
	}

	/**
	 * One charge, or null when it has no amount.
	 *
	 * @param string              $kind     shipping, fee or discount.
	 * @param array<string,mixed> $raw      Block from the payload.
	 * @param string              $fallback Name when the block has none.
	 */
	private function charge( string $kind, array $raw, string $fallback ): ?Charge {
		$amount = PayloadReader::minor( $raw['total'] ?? null );
		if ( null === $amount || 0 === $amount ) {
			return null;
		}
		$name = PayloadReader::text( $raw['name'] ?? '' );
		return new Charge( $kind, '' !== $name ? $name : $fallback, $amount, array_key_exists( 'tax', $raw ) ? PayloadReader::taxCodes( $raw['tax'] ) : null );
	}
}
