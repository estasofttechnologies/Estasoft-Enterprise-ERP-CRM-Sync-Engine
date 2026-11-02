<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Reads the "refunds" payload: the order reference, the date, the reason, the whole refund amount, and the parts of it that are product lines,
 * shipping, other amounts and products left out of the sync. Money arrives as integer minor units.
 */
final class HoldedRefundPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $currency       Currency of the order, upper case, or empty.
	 * @param string                         $orderRef       Client reference of the original order, WC-{id}.
	 * @param string                         $date           Refund date, Y-m-d, or empty.
	 * @param string                         $orderDate      Date of the original order, Y-m-d, or empty.
	 * @param string                         $reason         Reason text.
	 * @param int                            $amountMinor    The whole refund, with tax, positive.
	 * @param int                            $excludedMinor  The part that belongs to products left out of the sync.
	 * @param int                            $shippingMinor  Shipping refunded, with its tax.
	 * @param int                            $otherMinor     Fees, rounding or a manual amount, with tax.
	 * @param array<int,array<string,mixed>> $lines          Product rows: sku, name, quantity, amount_minor (net), tax_amount (minor or null).
	 */
	private function __construct(
		public readonly string $currency,
		public readonly string $orderRef,
		public readonly string $date,
		public readonly string $orderDate,
		public readonly string $reason,
		public readonly int $amountMinor,
		public readonly int $excludedMinor,
		public readonly int $shippingMinor,
		public readonly int $otherMinor,
		public readonly array $lines,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @return self|ConnectorError
	 */
	public static function parse( array $payload ): self|ConnectorError {
		$orderRef = HoldedPayloadReader::text( $payload['order_ref'] ?? '' );
		if ( '' === $orderRef ) {
			return self::refused( 'order_ref', __( 'The refund does not say which order it belongs to.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lines = array();
		foreach ( HoldedPayloadReader::rows( $payload['lines'] ?? null ) as $raw ) {
			$amount = HoldedPayloadReader::minor( $raw['amount'] ?? null );
			if ( null === $amount ) {
				continue;
			}
			$sku     = HoldedPayloadReader::text( $raw['sku'] ?? '' );
			$name    = HoldedPayloadReader::text( $raw['name'] ?? '' );
			$lines[] = array(
				'sku'          => $sku,
				'name'         => '' !== $name ? $name : $sku,
				'quantity'     => max( 0.0, HoldedPayloadReader::quantity( $raw['quantity'] ?? 1 ) ),
				'amount_minor' => abs( $amount ),
				'tax_amount'   => null === HoldedPayloadReader::minor( $raw['tax_amount'] ?? null ) ? null : abs( (int) HoldedPayloadReader::minor( $raw['tax_amount'] ?? null ) ),
			);
		}
		$amount = abs( (int) HoldedPayloadReader::minor( $payload['amount'] ?? null ) );
		if ( 0 === $amount && array() === $lines ) {
			return self::refused( 'amount', __( 'The refund has no amount and no lines.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return new self(
			strtoupper( HoldedPayloadReader::text( $payload['currency'] ?? '' ) ),
			$orderRef,
			HoldedOrderPayload::date( $payload['date'] ?? '' ),
			HoldedOrderPayload::date( $payload['order_date'] ?? '' ),
			HoldedPayloadReader::text( $payload['reason'] ?? '' ),
			$amount,
			abs( (int) HoldedPayloadReader::minor( $payload['excluded_amount'] ?? null ) ),
			abs( (int) HoldedPayloadReader::minor( $payload['shipping_amount'] ?? null ) ),
			abs( (int) HoldedPayloadReader::minor( $payload['other_amount'] ?? null ) ),
			$lines
		);
	}

	/**
	 * What the credit note should add up to: the refund less the part that belongs to products left out.
	 */
	public function expectedTotal(): int {
		return max( 0, $this->amountMinor - $this->excludedMinor );
	}

	/**
	 * A SYNC-VAL-002 for a field the payload gets wrong.
	 *
	 * @param string $field  Payload path.
	 * @param string $reason Short reason.
	 */
	private static function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => HoldedConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'payload refused'
		);
	}
}
