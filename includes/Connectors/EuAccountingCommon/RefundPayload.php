<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Reads the "refunds" payload: the order reference, the date, the reason, the whole refund amount, and the parts of it that are product
 * lines (net, with their tax), shipping and other amounts (with tax) and products left out of the sync. Money arrives as integer minor units.
 */
final class RefundPayload {

	/**
	 * Set up the object.
	 *
	 * @param string                         $orderRef      Client reference of the original order, WC-{id}.
	 * @param string                         $date          Refund date (UTC) or empty.
	 * @param string                         $reason        Reason text.
	 * @param int                            $amountMinor   The whole refund, with tax, positive.
	 * @param int                            $excludedMinor The part that belongs to products left out of the sync.
	 * @param int                            $shippingMinor Shipping refunded, with its tax.
	 * @param int                            $otherMinor    Fees, rounding or a manual amount, with tax.
	 * @param array<int,array<string,mixed>> $lines         Product rows: sku, name, quantity, amount_minor (net), tax_minor.
	 * @param string                         $email         Billing email of the order (lower case), used to find the customer's documents.
	 * @param string                         $orderDate     Date of the refunded order (UTC text), used to narrow the search for its invoice.
	 * @param string                         $currency      Currency of the order, upper case.
	 */
	private function __construct(
		public readonly string $orderRef,
		public readonly string $date,
		public readonly string $reason,
		public readonly int $amountMinor,
		public readonly int $excludedMinor,
		public readonly int $shippingMinor,
		public readonly int $otherMinor,
		public readonly array $lines,
		public readonly string $email = '',
		public readonly string $orderDate = '',
		public readonly string $currency = '',
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param string              $system  Vendor label.
	 * @return self|ConnectorError
	 */
	public static function parse( array $payload, string $system ): self|ConnectorError {
		$orderRef = PayloadReader::text( $payload['order_ref'] ?? '' );
		if ( '' === $orderRef ) {
			return Refusal::value( $system, 'order_ref', __( 'The refund does not say which order it belongs to.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'payload refused' );
		}
		$lines = array();
		foreach ( PayloadReader::rows( $payload['lines'] ?? null ) as $raw ) {
			$amount = PayloadReader::minor( $raw['amount'] ?? null );
			if ( null === $amount ) {
				continue;
			}
			$sku     = PayloadReader::text( $raw['sku'] ?? '' );
			$name    = PayloadReader::text( $raw['name'] ?? '' );
			$tax     = PayloadReader::minor( $raw['tax_amount'] ?? null );
			$lines[] = array(
				'sku'          => $sku,
				'name'         => '' !== $name ? $name : $sku,
				'quantity'     => max( 0.0, PayloadReader::quantity( $raw['quantity'] ?? 1 ) ),
				'amount_minor' => abs( $amount ),
				'tax_minor'    => null === $tax ? 0 : abs( $tax ),
			);
		}
		$amount = abs( (int) PayloadReader::minor( $payload['amount'] ?? null ) );
		if ( 0 === $amount && array() === $lines ) {
			return Refusal::value( $system, 'amount', __( 'The refund has no amount and no lines.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'payload refused' );
		}
		return new self(
			$orderRef,
			PayloadReader::text( $payload['date'] ?? '' ),
			PayloadReader::text( $payload['reason'] ?? '' ),
			$amount,
			abs( (int) PayloadReader::minor( $payload['excluded_amount'] ?? null ) ),
			abs( (int) PayloadReader::minor( $payload['shipping_amount'] ?? null ) ),
			abs( (int) PayloadReader::minor( $payload['other_amount'] ?? null ) ),
			$lines,
			strtolower( PayloadReader::text( PayloadReader::block( $payload['customer'] ?? null )['email'] ?? '' ) ),
			PayloadReader::text( $payload['order_date'] ?? '' ),
			strtoupper( PayloadReader::text( $payload['currency'] ?? '' ) )
		);
	}

	/**
	 * Rows the credit note will have: product rows, shipping and other amounts (at least one).
	 */
	public function rows(): int {
		return max( 1, count( $this->lines ) + ( $this->shippingMinor > 0 ? 1 : 0 ) + ( $this->otherMinor > 0 ? 1 : 0 ) );
	}

	/**
	 * What the credit note should add up to: the refund less the part that belongs to products left out.
	 */
	public function expectedTotal(): int {
		return max( 0, $this->amountMinor - $this->excludedMinor );
	}
}
