<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the "orders" rules (target paths such as customer.email and lines[].total,
 * see docs/connectors/daftra.md). Money arrives as integer minor units. The client reference comes from the queue item, never from the
 * payload, so a mapping mistake cannot break duplicate protection.
 */
final class DaftraOrderPayload {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $currency         ISO currency code, upper case.
	 * @param array<string,string>           $customer         Customer fields: name, email, phone, company, tax_number, address, city, state, postcode, country.
	 * @param array<int,array<string,mixed>> $lines            Product rows: sku, item, name, quantity, total_minor, tax_code.
	 * @param array<int,array<string,mixed>> $extras           Shipping and fee rows: kind, name, quantity, total_minor, tax_code, tax_amount.
	 * @param string                         $date             Order date, Y-m-d.
	 * @param string                         $note             Order note.
	 * @param string                         $number           Order number as the shop shows it, or empty.
	 * @param int|null                       $totalMinor       What Daftra should show: the order total less the part of products left out. Null when the mapping gives none.
	 * @param bool                           $pricesIncludeTax True when the mapped totals include tax.
	 * @param bool                           $paid             True when the order has a payment date.
	 * @param string                         $paymentDate      Payment date, Y-m-d, or empty.
	 * @param string                         $paymentReference Gateway transaction id, or empty.
	 * @param string                         $flowInvoice      "issued", "draft" or empty (the connection option decides).
	 * @param bool|null                      $flowPayment      A payload override of the payment option, or null.
	 */
	private function __construct(
		public readonly string $currency,
		public readonly array $customer,
		public readonly array $lines,
		public readonly array $extras,
		public readonly string $date,
		public readonly string $note,
		public readonly string $number,
		public readonly ?int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly bool $paid,
		public readonly string $paymentDate,
		public readonly string $paymentReference,
		public readonly string $flowInvoice,
		public readonly ?bool $flowPayment,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item.
	 * @return self|ConnectorError The payload, SYNC-MAP-003 for a missing email, or SYNC-MAP-001 / SYNC-VAL-002 for anything else wrong.
	 */
	public static function parse( array $payload, Item $item ): self|ConnectorError {
		$customer = self::customer( $payload['customer'] ?? null );
		if ( '' === $customer['email'] ) {
			return new ConnectorError(
				ErrorCode::Map003,
				array(
					'order'  => $item->entityId,
					'system' => DaftraConnector::LABEL,
				),
				null,
				null,
				null,
				'email missing'
			);
		}
		if ( '' === $customer['name'] ) {
			return self::refused( 'customer.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$lines = array();
		foreach ( DaftraPayloadReader::rows( $payload['lines'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1 );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return self::refused( 'lines', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$extras   = array();
		$shipping = DaftraPayloadReader::block( $payload['shipping'] ?? null );
		$ship     = DaftraPayloadReader::minor( $shipping['total'] ?? null );
		if ( null !== $ship && 0 !== $ship ) {
			$name     = DaftraPayloadReader::text( $shipping['name'] ?? '' );
			$extras[] = array(
				'kind'        => 'shipping',
				'name'        => '' !== $name ? $name : __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
				'quantity'    => 1.0,
				'total_minor' => $ship,
				'tax_code'    => DaftraPayloadReader::taxCode( $shipping['tax_code'] ?? '' ),
				'tax_amount'  => null,
			);
		}
		foreach ( DaftraPayloadReader::rows( $payload['fees'] ?? null ) as $fee ) {
			$total = DaftraPayloadReader::minor( $fee['total'] ?? null );
			if ( null === $total || 0 === $total ) {
				continue;
			}
			$name     = DaftraPayloadReader::text( $fee['name'] ?? '' );
			$extras[] = array(
				'kind'        => 'fee',
				'name'        => '' !== $name ? $name : __( 'Fee', 'estasoft-erp-crm-sync-engine' ),
				'quantity'    => 1.0,
				'total_minor' => $total,
				'tax_code'    => DaftraPayloadReader::taxCode( $fee['tax_code'] ?? '' ),
				'tax_amount'  => DaftraPayloadReader::minor( $fee['tax_amount'] ?? null ),
			);
		}

		$total = DaftraPayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) DaftraPayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$payment = DaftraPayloadReader::block( $payload['payment'] ?? null );
		$flow    = DaftraPayloadReader::block( $payload['flow'] ?? null );
		$paidOn  = self::date( $payment['date'] ?? '' );
		$mode    = strtolower( DaftraPayloadReader::text( $flow['invoice'] ?? '' ) );

		return new self(
			strtoupper( DaftraPayloadReader::text( $payload['currency'] ?? '' ) ),
			$customer,
			$lines,
			$extras,
			self::date( $payload['date'] ?? '' ),
			DaftraPayloadReader::text( $payload['note'] ?? '' ),
			DaftraPayloadReader::text( $payload['number'] ?? '' ),
			$total,
			DaftraPayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			'' !== $paidOn,
			$paidOn,
			DaftraPayloadReader::text( $payment['reference'] ?? '' ),
			in_array( $mode, array( 'issued', 'draft' ), true ) ? $mode : '',
			array_key_exists( 'payment', $flow ) ? DaftraPayloadReader::flag( $flow['payment'], false ) : null
		);
	}

	/**
	 * The customer block as plain strings.
	 *
	 * @param mixed $raw Block from the payload.
	 * @return array{name:string,email:string,phone:string,company:string,tax_number:string,address:string,city:string,state:string,postcode:string,country:string}
	 */
	public static function customer( mixed $raw ): array {
		$block = DaftraPayloadReader::block( $raw );
		$text  = static fn( string $field ): string => DaftraPayloadReader::text( $block[ $field ] ?? '' );
		$name  = $text( 'name' );
		return array(
			'name'       => '' !== $name ? $name : $text( 'company' ),
			'email'      => strtolower( $text( 'email' ) ),
			'phone'      => $text( 'phone' ),
			'company'    => $text( 'company' ),
			'tax_number' => $text( 'tax_number' ),
			'address'    => $text( 'address' ),
			'city'       => $text( 'city' ),
			'state'      => $text( 'state' ),
			'postcode'   => $text( 'postcode' ),
			'country'    => strtoupper( $text( 'country' ) ),
		);
	}

	/**
	 * One product row.
	 *
	 * @param array<string,mixed> $raw    Row from the payload.
	 * @param int                 $number Position, for messages.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function productLine( array $raw, int $number ): array|ConnectorError {
		$sku  = DaftraPayloadReader::text( $raw['sku'] ?? '' );
		$item = DaftraPayloadReader::text( $raw['item'] ?? '' );
		$name = DaftraPayloadReader::text( $raw['name'] ?? '' );
		if ( '' === $sku && '' === $item && '' === $name ) {
			return new ConnectorError(
				ErrorCode::Map001,
				array(
					'sku'    => '#' . $number,
					'system' => DaftraConnector::LABEL,
				),
				null,
				null,
				null,
				'line has no product'
			);
		}
		$quantity = DaftraPayloadReader::quantity( $raw['quantity'] ?? null );
		if ( $quantity <= 0.0 ) {
			return self::refused(
				'lines[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		$total = DaftraPayloadReader::minor( $raw['total'] ?? null );
		if ( null === $total ) {
			return self::refused(
				'lines[].total',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no amount.', 'estasoft-erp-crm-sync-engine' ),
					$number
				)
			);
		}
		return array(
			'sku'         => $sku,
			'item'        => $item,
			'name'        => '' !== $name ? $name : $sku,
			'quantity'    => $quantity,
			'total_minor' => $total,
			'tax_code'    => DaftraPayloadReader::taxCode( $raw['tax_code'] ?? '' ),
			'tax_amount'  => null,
		);
	}

	/**
	 * A date as Y-m-d, or an empty string when the value is not a date.
	 *
	 * @param mixed $value Value from the payload.
	 */
	public static function date( mixed $value ): string {
		$text = DaftraPayloadReader::text( $value );
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $text, $parts ) ) {
			return $parts[1];
		}
		return '';
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
				'system' => DaftraConnector::LABEL,
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
