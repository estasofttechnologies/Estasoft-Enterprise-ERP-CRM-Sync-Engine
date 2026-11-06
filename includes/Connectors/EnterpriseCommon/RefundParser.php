<?php
/**
 * Reads and checks a mapped refund payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Target paths of the "refunds" rules: order_ref, date, reason, amount, refund_line[].{product, quantity, amount, tax}.
 */
final class RefundParser {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string  $system  System name for messages.
	 * @param Amounts $amounts Conversion helper.
	 */
	public function __construct( private readonly string $system, private readonly Amounts $amounts ) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item (a refund; its client reference is WC-{refund id}).
	 * @return RefundData|ConnectorError
	 */
	public function parse( array $payload, Item $item ): RefundData|ConnectorError {
		$orderRef = PayloadReader::text( $payload['order_ref'] ?? '' );
		if ( 1 !== preg_match( '/^WC-[A-Za-z0-9_-]{1,40}$/', $orderRef ) ) {
			return Problem::validation( $this->system, 'order_ref', __( 'The refund does not name its order (WC- and the order number).', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lines = array();
		foreach ( PayloadReader::rows( $payload['refund_line'] ?? null ) as $index => $raw ) {
			$quantity = $raw['quantity'] ?? 1;
			$amount   = PayloadReader::minor( $raw['amount'] ?? null );
			if ( null === $amount || ! is_numeric( $quantity ) || (float) $quantity <= 0.0 ) {
				return Problem::validation(
					$this->system,
					'refund_line[]',
					sprintf(
						/* translators: %d: line number. */
						__( 'Refund line %d needs a quantity and an amount in minor units.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$index + 1
					)
				);
			}
			$lines[] = new RefundLine(
				PayloadReader::text( $raw['product'] ?? '' ),
				$this->amounts->quantityText( is_string( $quantity ) ? $quantity : ( is_float( $quantity ) ? $quantity : (int) $quantity ) ),
				abs( $amount ),
				array_key_exists( 'tax', $raw ) ? PayloadReader::taxCodes( $raw['tax'] ) : null
			);
		}
		$amount = PayloadReader::minor( $payload['amount'] ?? null );
		if ( array() === $lines && ( null === $amount || 0 === $amount ) ) {
			return Problem::validation( $this->system, 'amount', __( 'The refund has no lines and no amount.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return new RefundData(
			$item->clientReference(),
			$orderRef,
			PayloadReader::date( $payload['date'] ?? '' ),
			PayloadReader::text( $payload['reason'] ?? '' ),
			null === $amount ? null : abs( $amount ),
			$lines
		);
	}
}
