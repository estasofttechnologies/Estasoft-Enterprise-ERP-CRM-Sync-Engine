<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Reads the "refunds" payload: the order reference, the date, the reason, the whole refund amount, and the parts of it that are product lines,
 * shipping, other amounts and products left out of the sync. Money arrives as integer minor units.
 */
final class WafeqRefundPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $currency       Currency of the order, upper case, or empty.
	 * @param string                         $orderRef       Client reference of the original order, WC-{id}.
	 * @param string                         $date           Refund date, Y-m-d, or empty.
	 * @param string                         $reason         Reason text.
	 * @param int                            $amountMinor    The whole refund, with tax, positive.
	 * @param int                            $excludedMinor  The part that belongs to products left out of the sync.
	 * @param int                            $shippingMinor  Shipping refunded, with its tax.
	 * @param int                            $otherMinor     Fees, rounding or a manual amount, with tax.
	 * @param array<int,array<string,mixed>> $lines          Product rows: sku, item, name, quantity, amount_minor (net), tax_amount (minor or null).
	 */
	private function __construct(
		public readonly string $currency,
		public readonly string $orderRef,
		public readonly string $date,
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
		$orderRef = WafeqPayloadReader::text( $payload['order_ref'] ?? '' );
		if ( '' === $orderRef ) {
			return self::refused( 'order_ref', __( 'The refund does not say which order it belongs to.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lines = array();
		foreach ( WafeqPayloadReader::rows( $payload['lines'] ?? null ) as $raw ) {
			$amount = WafeqPayloadReader::minor( $raw['amount'] ?? null );
			if ( null === $amount ) {
				continue;
			}
			$sku     = WafeqPayloadReader::text( $raw['sku'] ?? '' );
			$name    = WafeqPayloadReader::text( $raw['name'] ?? '' );
			$lines[] = array(
				'sku'          => $sku,
				'item'         => WafeqPayloadReader::text( $raw['item'] ?? '' ),
				'name'         => '' !== $name ? $name : $sku,
				'quantity'     => max( 0.0, WafeqPayloadReader::quantity( $raw['quantity'] ?? 1 ) ),
				'amount_minor' => abs( $amount ),
				'tax_amount'   => null === WafeqPayloadReader::minor( $raw['tax_amount'] ?? null ) ? null : abs( (int) WafeqPayloadReader::minor( $raw['tax_amount'] ?? null ) ),
			);
		}
		$amount = abs( (int) WafeqPayloadReader::minor( $payload['amount'] ?? null ) );
		if ( 0 === $amount && array() === $lines ) {
			return self::refused( 'amount', __( 'The refund has no amount and no lines.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return new self(
			strtoupper( WafeqPayloadReader::text( $payload['currency'] ?? '' ) ),
			$orderRef,
			WafeqOrderPayload::date( $payload['date'] ?? '' ),
			WafeqPayloadReader::text( $payload['reason'] ?? '' ),
			$amount,
			abs( (int) WafeqPayloadReader::minor( $payload['excluded_amount'] ?? null ) ),
			abs( (int) WafeqPayloadReader::minor( $payload['shipping_amount'] ?? null ) ),
			abs( (int) WafeqPayloadReader::minor( $payload['other_amount'] ?? null ) ),
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
				'system' => WafeqConnector::LABEL,
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
