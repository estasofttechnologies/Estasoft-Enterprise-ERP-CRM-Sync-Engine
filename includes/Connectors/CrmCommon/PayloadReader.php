<?php
/**
 * Reads and checks a mapped payload before anything is sent.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Returns a value object or a ConnectorError; never throws. Every check happens before the first request.
 */
final class PayloadReader {

	/**
	 * Create the object.
	 *
	 * @param string $system Name of the system, for messages.
	 * @param string $today  Y-m-d used when the payload has no date.
	 */
	public function __construct( private readonly string $system, private readonly string $today ) {
	}

	/**
	 * Read an order payload.
	 *
	 * @param Item                $item    Queue item (its id gives the client reference).
	 * @param array<string,mixed> $payload Mapped payload.
	 * @return OrderData|ConnectorError
	 */
	public function order( Item $item, array $payload ): OrderData|ConnectorError {
		$contact = $this->contact( $payload );
		if ( '' === ( $contact['email'] ?? '' ) ) {
			return new ConnectorError(
				ErrorCode::Map003,
				array(
					'order'  => $item->entityId,
					'system' => $this->system,
				),
				null,
				null,
				null,
				'no email'
			);
		}
		$currency = strtoupper( $this->text( $payload['currency'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return $this->invalid( 'currency', __( 'The order has no valid currency code.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$total = $this->minor( $payload['total'] ?? null );
		if ( null === $total || $total < 0 ) {
			return $this->invalid( 'total', __( 'The order total is missing or not a number.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$excluded = $this->minor( $payload['excluded_total'] ?? 0 ) ?? 0;
		$lines    = $this->lines( $payload['lines'] ?? array() );
		if ( $lines instanceof ConnectorError ) {
			return $lines;
		}
		if ( array() === $lines ) {
			return $this->invalid( 'lines', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$fees = array();
		foreach ( (array) ( $payload['fees'] ?? array() ) as $fee ) {
			if ( is_array( $fee ) && null !== $this->minor( $fee['total'] ?? null ) ) {
				$fees[] = array(
					'name'     => $this->text( $fee['name'] ?? '' ),
					'total'    => (int) $this->minor( $fee['total'] ),
					'tax_code' => $this->text( $fee['tax_code'] ?? '' ),
				);
			}
		}
		$shipping = (array) ( $payload['shipping'] ?? array() );
		$discount = (array) ( $payload['discount'] ?? array() );
		$payment  = (array) ( $payload['payment'] ?? array() );
		$paidAt   = $this->text( $payload['paid_at'] ?? '' );

		return new OrderData(
			$item->clientReference(),
			$currency,
			$this->date( $payload['date'] ?? '' ),
			$this->text( $payload['note'] ?? '' ),
			'' !== $paidAt,
			max( 0, $total - max( 0, $excluded ) ),
			$this->flag( $payload['prices_include_tax'] ?? false ),
			$contact,
			$lines,
			max( 0, $this->minor( $shipping['total'] ?? 0 ) ?? 0 ),
			$fees,
			abs( $this->minor( $discount['total'] ?? 0 ) ?? 0 ),
			$this->text( $discount['codes'] ?? '' ),
			$this->text( $payment['reference'] ?? '' ),
			$this->text( $shipping['tax_code'] ?? '' ),
			'' !== $paidAt ? $this->date( $paidAt ) : ''
		);
	}

	/**
	 * Read a refund payload.
	 *
	 * @param Item                $item    Queue item (its id gives the refund reference).
	 * @param array<string,mixed> $payload Mapped payload.
	 * @return RefundData|ConnectorError
	 */
	public function refund( Item $item, array $payload ): RefundData|ConnectorError {
		$currency = strtoupper( $this->text( $payload['currency'] ?? '' ) );
		$amount   = $this->minor( $payload['amount'] ?? null );
		if ( null === $amount || $amount <= 0 ) {
			return $this->invalid( 'amount', __( 'The refund amount is missing or not above zero.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$order = $this->text( $payload['order_ref'] ?? '' );
		if ( 1 !== preg_match( '/^WC-\d+$/', $order ) ) {
			return $this->invalid( 'order_ref', __( 'The refund does not name its order.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return new RefundData( $item->clientReference(), $order, abs( $amount ), 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '', $this->text( $payload['reason'] ?? '' ), $this->date( $payload['date'] ?? '' ) );
	}

	/**
	 * Read the buyer from an order or customer payload; names, email and address as typed.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @return array<string,string>
	 */
	public function contact( array $payload ): array {
		$source = (array) ( $payload['contact'] ?? array() );
		$out    = array();
		foreach ( array( 'email', 'first_name', 'last_name', 'company', 'phone', 'street', 'city', 'state', 'postcode', 'country' ) as $key ) {
			$out[ $key ] = $this->text( $source[ $key ] ?? '' );
		}
		$out['email'] = 1 === preg_match( '/^[^@\s]+@[^@\s]+$/u', $out['email'] ) ? $out['email'] : '';
		return $out;
	}

	/**
	 * Read the product lines.
	 *
	 * @param mixed $lines Raw lines.
	 * @return array<int,OrderLine>|ConnectorError
	 */
	private function lines( mixed $lines ): array|ConnectorError {
		$out = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$quantity = $this->text( $line['quantity'] ?? '' );
			$unit     = $this->minor( $line['unit_price'] ?? null );
			if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $quantity ) || (float) $quantity <= 0 || null === $unit || $unit < 0 ) {
				return $this->invalid( 'lines', __( 'A product line has no valid quantity or price.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$total = $this->minor( $line['total'] ?? null );
			if ( null === $total ) {
				$total = ctype_digit( $quantity ) ? $unit * (int) $quantity : $unit;
			}
			$out[] = new OrderLine(
				$this->text( $line['name'] ?? '' ),
				$this->text( $line['sku'] ?? '' ),
				$quantity,
				$unit,
				$total,
				$this->minor( $line['tax'] ?? 0 ) ?? 0,
				$this->text( $line['tax_code'] ?? '' )
			);
		}
		return $out;
	}

	/**
	 * Integer minor units from the payload; anything else is not an amount.
	 *
	 * @param mixed $value Raw amount.
	 */
	private function minor( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * A trimmed text value.
	 *
	 * @param mixed $value Raw value.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A yes or no value.
	 *
	 * @param mixed $value Raw value.
	 */
	private function flag( mixed $value ): bool {
		return true === $value || in_array( strtolower( is_scalar( $value ) ? (string) $value : '' ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * A Y-m-d date from a payload value.
	 *
	 * @param mixed $value Raw date text.
	 */
	private function date( mixed $value ): string {
		$text = $this->text( $value );
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $text, $parts ) ? $parts[1] : $this->today;
	}

	/**
	 * A validation error.
	 *
	 * @param string $field  Field name.
	 * @param string $reason Reason.
	 */
	private function invalid( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $this->system,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'payload ' . $field
		);
	}
}
