<?php
/**
 * A mapped refund, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * The refund payload: `order_ref` (WC-123), `date`, `reason`, `amount` (the whole refund with tax, minor units), `refund_line[]` (product,
 * name, quantity, amount before tax), `shipping.total`, `other.total` and `prices_include_tax`. The refund has no currency of its own: it is
 * the currency of the document the order became.
 */
final class BcRefundData {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string                                                                 $orderRef      WC-123.
	 * @param string                                                                 $date          Refund date YYYY-MM-DD, or empty.
	 * @param string                                                                 $reason        Reason.
	 * @param int|null                                                               $amountMinor   Whole refund with tax, or null.
	 * @param array<int,array{sku:string,name:string,quantity:int|float,amount:int}> $lines         Refunded product lines.
	 * @param int                                                                    $shippingMinor Refunded shipping.
	 * @param int                                                                    $otherMinor    Refund amount no line explains.
	 */
	public function __construct(
		public readonly string $orderRef,
		public readonly string $date,
		public readonly string $reason,
		public readonly ?int $amountMinor,
		public readonly array $lines,
		public readonly int $shippingMinor,
		public readonly int $otherMinor,
	) {
	}

	/**
	 * Read and check a mapped refund payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param string              $system  System name.
	 */
	public static function parse( array $payload, string $system ): self|ConnectorError {
		$orderRef = is_string( $payload['order_ref'] ?? null ) ? trim( $payload['order_ref'] ) : '';
		if ( 1 !== preg_match( '/^WC-\d+$/', $orderRef ) ) {
			return Failures::invalid( $system, 'order_ref', __( 'A refund needs the reference of its order, WC- and the order number.', 'estasoft-erp-crm-sync-engine' ), 'order ref' );
		}
		$lines = array();
		foreach ( is_array( $payload['refund_line'] ?? null ) ? array_values( $payload['refund_line'] ) : array() as $row ) {
			$row      = is_array( $row ) ? $row : array();
			$sku      = is_string( $row['product'] ?? null ) ? trim( $row['product'] ) : '';
			$quantity = $row['quantity'] ?? null;
			$amount   = self::minor( $row['amount'] ?? null );
			if ( '' === $sku || ! ( is_int( $quantity ) || is_float( $quantity ) || ( is_string( $quantity ) && is_numeric( $quantity ) ) ) || $quantity + 0 <= 0 || null === $amount || $amount < 0 ) {
				return Failures::invalid( $system, 'refund_line[]', __( 'A refund line needs a product, a quantity above zero and an amount.', 'estasoft-erp-crm-sync-engine' ), 'refund line' );
			}
			$lines[] = array(
				'sku'      => $sku,
				'name'     => is_string( $row['name'] ?? null ) ? trim( $row['name'] ) : '',
				'quantity' => $quantity + 0,
				'amount'   => $amount,
			);
		}
		$shipping = is_array( $payload['shipping'] ?? null ) ? self::minor( $payload['shipping']['total'] ?? null ) : null;
		$other    = is_array( $payload['other'] ?? null ) ? self::minor( $payload['other']['total'] ?? null ) : null;
		$amount   = self::minor( $payload['amount'] ?? null );
		if ( array() === $lines && ( $shipping ?? 0 ) <= 0 && ( $other ?? 0 ) <= 0 ) {
			// A refund with only a total: it becomes one line on the refund item.
			$other = $amount;
		}
		if ( array() === $lines && ( $shipping ?? 0 ) <= 0 && ( $other ?? 0 ) <= 0 ) {
			return Failures::invalid( $system, 'amount', __( 'The refund has no lines and no amount.', 'estasoft-erp-crm-sync-engine' ), 'empty refund' );
		}
		return new self(
			$orderRef,
			self::date( $payload['date'] ?? '' ),
			is_string( $payload['reason'] ?? null ) ? trim( $payload['reason'] ) : '',
			$amount,
			$lines,
			max( 0, $shipping ?? 0 ),
			max( 0, $other ?? 0 )
		);
	}

	/**
	 * Whole minor units from an int, a whole float or digits; null otherwise.
	 *
	 * @param mixed $value Value.
	 */
	private static function minor( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}$/', trim( $value ) ) ? (int) trim( $value ) : null;
	}

	/**
	 * The UTC date of a date or date-time text.
	 *
	 * @param mixed $value Text.
	 */
	private static function date( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		try {
			return ( new \DateTimeImmutable( trim( $value ), new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return '';
		}
	}
}
