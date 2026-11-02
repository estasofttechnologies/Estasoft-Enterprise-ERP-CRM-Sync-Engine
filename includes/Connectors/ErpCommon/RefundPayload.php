<?php
/**
 * The mapped refund payload of an ERP connector, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the rules of the "refunds" mapping: order_ref, date, reason, amount, currency, refund_line[].{product, quantity, amount} and
 * shipping_amount. Money arrives as integer minor units; a line amount is the whole line before tax.
 */
final class RefundPayload {

	/**
	 * Set up with the values read.
	 *
	 * @param string                         $clientRef      WC-{refund id}.
	 * @param string                         $orderRef       WC-{order id} of the refunded order.
	 * @param string                         $currency       ISO currency code, or empty.
	 * @param string                         $date           Refund date as Y-m-d, or empty.
	 * @param string                         $reason         Reason as text.
	 * @param int|null                       $amountMinor    Refund amount with tax, or null when absent.
	 * @param array<int,array<string,mixed>> $lines          Refunded lines: sku, quantity, amount_minor.
	 * @param int                            $shippingMinor  Refunded shipping with tax.
	 */
	private function __construct(
		public readonly string $clientRef,
		public readonly string $orderRef,
		public readonly string $currency,
		public readonly string $date,
		public readonly string $reason,
		public readonly ?int $amountMinor,
		public readonly array $lines,
		public readonly int $shippingMinor,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item (a refund).
	 * @param string              $system  System name.
	 * @param Amounts             $amounts Conversion helper.
	 * @return self|ConnectorError
	 */
	public static function parse( array $payload, Item $item, string $system, Amounts $amounts ): self|ConnectorError {
		$orderRef = PayloadReader::text( $payload['order_ref'] ?? '' );
		if ( 1 !== preg_match( '/^WC-\d+$/', $orderRef ) ) {
			return Problems::refused( $system, 'order_ref', __( 'The refund does not name its order (expected WC- and the order number).', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lines = array();
		foreach ( PayloadReader::rows( $payload['refund_line'] ?? null ) as $index => $raw ) {
			$sku      = PayloadReader::text( $raw['product'] ?? '' );
			$quantity = isset( $raw['quantity'] ) && is_numeric( $raw['quantity'] ) ? abs( (float) $raw['quantity'] ) : 0.0;
			$amount   = PayloadReader::minor( $raw['amount'] ?? null );
			if ( '' === $sku ) {
				return Problems::missingProduct( $system, '#' . ( $index + 1 ) );
			}
			if ( $quantity <= 0.0 || null === $amount ) {
				return Problems::refused(
					$system,
					'refund_line',
					sprintf(
						/* translators: %d: line number. */
						__( 'Refund line %d has no quantity or amount.', 'estasoft-erp-crm-sync-engine' ),
						$index + 1
					)
				);
			}
			$lines[] = array(
				'sku'          => $sku,
				'quantity'     => $quantity,
				'amount_minor' => abs( $amount ),
			);
		}
		$amountMinor = PayloadReader::minor( $payload['amount'] ?? null );
		return new self(
			$item->clientReference(),
			$orderRef,
			strtoupper( PayloadReader::text( $payload['currency'] ?? '' ) ),
			$amounts->dateOnly( PayloadReader::text( $payload['date'] ?? '' ) ),
			PayloadReader::text( $payload['reason'] ?? '' ),
			null === $amountMinor ? null : abs( $amountMinor ),
			$lines,
			abs( (int) PayloadReader::minor( $payload['shipping_amount'] ?? null ) )
		);
	}

	/**
	 * Whether the refund is only an amount: no product lines and no refunded shipping to itemise.
	 */
	public function isAmountOnly(): bool {
		return array() === $this->lines && 0 === $this->shippingMinor;
	}
}
