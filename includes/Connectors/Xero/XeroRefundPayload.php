<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The target paths of the "refunds" mapping: order_ref, date, reason, amount, prices_include_tax, refund_line[].{product, name, quantity, amount, tax}
 * and shipping.{total, tax}. Amounts are integer minor units; a line amount is the line total, not a unit price.
 */
final class XeroRefundPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $clientRef        WC-{refund id}.
	 * @param string                         $orderRef         WC-{order id} of the invoice that is refunded.
	 * @param string                         $date             Refund date (YYYY-MM-DD), or empty.
	 * @param string                         $reason           Reason text.
	 * @param int|null                       $amountMinor      Refund total the shop expects, or null.
	 * @param bool                           $pricesIncludeTax True when the mapped amounts include tax.
	 * @param array<int,array<string,mixed>> $lines            Lines: sku, name, quantity, amount_minor, tax.
	 * @param int                            $attempt          Tries the queue made so far; part of the idempotency key.
	 */
	private function __construct(
		public readonly string $clientRef,
		public readonly string $orderRef,
		public readonly string $date,
		public readonly string $reason,
		public readonly ?int $amountMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $lines,
		public readonly int $attempt = 0,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item (a refund).
	 * @return self|ConnectorError
	 */
	public static function parse( array $payload, Item $item ): self|ConnectorError {
		$orderRef = XeroPayloadReader::text( $payload['order_ref'] ?? '' );
		if ( 1 !== preg_match( '/^WC-\d+$/', $orderRef ) ) {
			return XeroErrors::refused( 'order_ref', __( 'The refund does not name the order it belongs to.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lines = array();
		foreach ( XeroPayloadReader::rows( $payload['refund_line'] ?? null ) as $index => $raw ) {
			$amount = XeroPayloadReader::minor( $raw['amount'] ?? null );
			if ( null === $amount ) {
				return XeroErrors::refused(
					'refund_line[].amount',
					sprintf(
						/* translators: %d: line number. */
						__( 'Refund line %d has no amount in minor units.', 'estasoft-erp-crm-sync-engine' ),
						$index + 1
					)
				);
			}
			if ( 0 === $amount ) {
				continue;
			}
			$sku = XeroPayloadReader::text( $raw['product'] ?? ( $raw['sku'] ?? '' ) );
			$tax = null;
			if ( array_key_exists( 'tax', $raw ) ) {
				$codes = XeroPayloadReader::taxCodes( $raw['tax'] );
				$tax   = array() === $codes ? '' : $codes[0];
			}
			$lines[] = array(
				'sku'          => $sku,
				'name'         => XeroPayloadReader::text( $raw['name'] ?? '' ),
				'quantity'     => XeroPayloadReader::quantity( $raw['quantity'] ?? null ) ?? '1',
				'amount_minor' => abs( $amount ),
				'tax'          => $tax,
			);
		}
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$ship     = XeroPayloadReader::minor( $shipping['total'] ?? null );
		if ( null !== $ship && 0 !== $ship ) {
			$tax = null;
			if ( array_key_exists( 'tax', $shipping ) ) {
				$codes = XeroPayloadReader::taxCodes( $shipping['tax'] );
				$tax   = array() === $codes ? '' : $codes[0];
			}
			$lines[] = array(
				'sku'          => '',
				'name'         => __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
				'quantity'     => '1',
				'amount_minor' => abs( $ship ),
				'tax'          => $tax,
				'kind'         => 'shipping',
			);
		}
		$amount = XeroPayloadReader::minor( $payload['amount'] ?? null );
		if ( array() === $lines ) {
			if ( null === $amount || $amount <= 0 ) {
				return XeroErrors::refused( 'refund_line', __( 'The refund has no lines and no amount.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$lines[] = array(
				'sku'          => '',
				'name'         => __( 'Refund', 'estasoft-erp-crm-sync-engine' ),
				'quantity'     => '1',
				'amount_minor' => $amount,
				'tax'          => null,
				'kind'         => 'amount',
			);
		}
		return new self(
			$item->clientReference(),
			$orderRef,
			XeroPayloadReader::day( $payload['date'] ?? '' ),
			XeroPayloadReader::text( $payload['reason'] ?? '' ),
			null === $amount ? null : abs( $amount ),
			XeroPayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			$lines,
			$item->attempt
		);
	}
}
