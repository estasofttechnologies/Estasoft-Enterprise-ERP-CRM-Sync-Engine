<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the "refunds" mapping: order_ref, date, reason, amount, currency, prices_include_tax, refund_line[] (product, name, quantity,
 * amount, tax) and shipping (amount, tax). Amounts are positive minor units.
 */
final class FortnoxRefund {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $reference        WC-{refund id}.
	 * @param string                         $orderRef         WC-{order id}.
	 * @param string                         $currency         ISO currency code, or empty.
	 * @param string                         $date             Refund date, Y-m-d.
	 * @param string                         $reason           Reason.
	 * @param int                            $amountMinor      Refund amount including tax, positive.
	 * @param bool                           $pricesIncludeTax Whether line amounts include tax.
	 * @param array<int,array<string,mixed>> $lines            Lines: sku, name, quantity (text), amount (minor, whole line), tax.
	 * @param int                            $shippingMinor    Refunded shipping.
	 * @param string                         $shippingTax      Tax code of the shipping.
	 */
	private function __construct(
		public readonly string $reference,
		public readonly string $orderRef,
		public readonly string $currency,
		public readonly string $date,
		public readonly string $reason,
		public readonly int $amountMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $lines,
		public readonly int $shippingMinor,
		public readonly string $shippingTax,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    Queue item (a refund).
	 * @param string              $today   Y-m-d used when the payload has no date.
	 * @return self|ConnectorError
	 */
	public static function parse( array $payload, Item $item, string $today ): self|ConnectorError {
		$orderRef = FortnoxPayload::text( $payload['order_ref'] ?? '' );
		if ( 1 !== preg_match( '/^WC-\d+$/', $orderRef ) ) {
			return self::refused( 'order_ref', __( 'The refund does not name its order.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$amount = FortnoxPayload::minor( $payload['amount'] ?? null );
		if ( null === $amount || 0 === $amount ) {
			return self::refused( 'amount', __( 'The refund amount is missing.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lines = array();
		foreach ( FortnoxPayload::rows( $payload['refund_line'] ?? null ) as $row ) {
			$lineAmount = FortnoxPayload::minor( $row['amount'] ?? null );
			if ( null === $lineAmount || 0 === $lineAmount ) {
				continue;
			}
			$lines[] = array(
				'sku'      => FortnoxPayload::text( $row['product'] ?? '' ),
				'name'     => FortnoxPayload::text( $row['name'] ?? '' ),
				'quantity' => FortnoxPayload::quantity( $row['quantity'] ?? null ) ?? '1',
				'amount'   => abs( $lineAmount ),
				'tax'      => FortnoxPayload::taxCode( $row['tax'] ?? null ),
			);
		}
		$ship     = is_array( $payload['shipping'] ?? null ) ? $payload['shipping'] : array();
		$currency = strtoupper( FortnoxPayload::text( $payload['currency'] ?? '' ) );
		$valid    = 1 === preg_match( '/^[A-Z]{3}$/', $currency );
		return new self(
			$item->clientReference(),
			$orderRef,
			$valid ? $currency : '',
			FortnoxPayload::date( $payload['date'] ?? '', $today ),
			FortnoxPayload::text( $payload['reason'] ?? '' ),
			abs( $amount ),
			FortnoxPayload::flag( $payload['prices_include_tax'] ?? null ),
			$lines,
			abs( (int) FortnoxPayload::minor( $ship['amount'] ?? null ) ),
			FortnoxPayload::taxCode( $ship['tax'] ?? null )
		);
	}

	/**
	 * A SYNC-VAL-002 error.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 */
	private static function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => FortnoxConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'invalid ' . $field
		);
	}
}
