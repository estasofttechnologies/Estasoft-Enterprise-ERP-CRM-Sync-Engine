<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array that the mapping engine builds from the "orders" rules (target paths such as customer.email and lines[].total, see
 * PayloadSchema). Money arrives as integer minor units. The client reference comes from the queue item, never from the payload, so a mapping
 * mistake cannot break duplicate protection.
 */
final class OrderPayload {

	/**
	 * Set up the object.
	 *
	 * @param string                         $currency         ISO currency code, upper case.
	 * @param array<string,string>           $customer         Customer fields (see customer()).
	 * @param array<int,array<string,mixed>> $lines            Product rows: sku, name, quantity, total_minor, tax_code, rate_bp (the tax rate in basis points, or null).
	 * @param array<int,array<string,mixed>> $extras           Shipping and fee rows: kind, name, total_minor, tax_code, rate_bp.
	 * @param string                         $date             Order date and time in UTC, "Y-m-d H:i:s", or a bare date.
	 * @param string                         $note             Order note.
	 * @param string                         $coupons          Coupon codes, joined.
	 * @param array<string,string>           $texts            header, introduction, remark, title (free text from the mapping).
	 * @param int|null                       $totalMinor       What the system should show: the order total less the part of products left out. Null when the mapping gives none.
	 * @param bool                           $pricesIncludeTax True when the mapped totals include tax.
	 * @param string                         $paymentDate      Payment date (UTC), or empty when the order is unpaid.
	 * @param string                         $paymentReference Gateway transaction id, or empty.
	 */
	private function __construct(
		public readonly string $currency,
		public readonly array $customer,
		public readonly array $lines,
		public readonly array $extras,
		public readonly string $date,
		public readonly string $note,
		public readonly string $coupons,
		public readonly array $texts,
		public readonly ?int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly string $paymentDate,
		public readonly string $paymentReference,
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    The queue item.
	 * @param string              $system  Vendor label for messages.
	 * @return self|ConnectorError The payload, SYNC-MAP-003 for a missing email, SYNC-MAP-001 for a line with nothing to name it, else SYNC-VAL-002.
	 */
	public static function parse( array $payload, Item $item, string $system ): self|ConnectorError {
		$customer = self::customer( $payload['customer'] ?? null );
		if ( '' === $customer['email'] ) {
			return Refusal::email( $system, $item->entityId );
		}
		if ( '' === $customer['name'] ) {
			return Refusal::value( $system, 'customer.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ), 'payload refused' );
		}
		$lines = array();
		foreach ( PayloadReader::rows( $payload['lines'] ?? null ) as $index => $raw ) {
			$line = self::productLine( $raw, $index + 1, $system );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return Refusal::value( $system, 'lines', __( 'The order has no product lines.', 'estasoft-erp-crm-sync-engine' ), 'payload refused' );
		}
		$extras   = array();
		$shipping = PayloadReader::block( $payload['shipping'] ?? null );
		$ship     = PayloadReader::minor( $shipping['total'] ?? null );
		if ( null !== $ship && 0 !== $ship ) {
			$name     = PayloadReader::text( $shipping['name'] ?? '' );
			$extras[] = array(
				'kind'        => 'shipping',
				'name'        => '' !== $name ? $name : __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
				'total_minor' => $ship,
				'tax_code'    => PayloadReader::taxCode( $shipping['tax_code'] ?? '' ),
				'rate_bp'     => self::rate( $shipping['tax_rate'] ?? null ),
			);
		}
		foreach ( PayloadReader::rows( $payload['fees'] ?? null ) as $fee ) {
			$total = PayloadReader::minor( $fee['total'] ?? null );
			if ( null === $total || 0 === $total ) {
				continue;
			}
			$name     = PayloadReader::text( $fee['name'] ?? '' );
			$extras[] = array(
				'kind'        => 'fee',
				'name'        => '' !== $name ? $name : __( 'Fee', 'estasoft-erp-crm-sync-engine' ),
				'total_minor' => $total,
				'tax_code'    => PayloadReader::taxCode( $fee['tax_code'] ?? '' ),
				'rate_bp'     => self::rate( $fee['tax_rate'] ?? null ),
			);
		}
		$total = PayloadReader::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) PayloadReader::minor( $payload['excluded_total'] ?? null ) );
		}
		$payment = PayloadReader::block( $payload['payment'] ?? null );
		$texts   = array();
		foreach ( array( 'header', 'title', 'introduction', 'remark' ) as $key ) {
			$texts[ $key ] = PayloadReader::text( $payload[ $key ] ?? '' );
		}
		return new self(
			strtoupper( PayloadReader::text( $payload['currency'] ?? '' ) ),
			$customer,
			$lines,
			$extras,
			PayloadReader::text( $payload['date'] ?? '' ),
			PayloadReader::text( $payload['note'] ?? '' ),
			PayloadReader::text( $payload['coupons'] ?? '' ),
			$texts,
			$total,
			PayloadReader::flag( $payload['prices_include_tax'] ?? null, false ),
			PayloadReader::text( $payment['date'] ?? '' ),
			PayloadReader::text( $payment['reference'] ?? '' )
		);
	}

	/**
	 * Rows the document will have: product lines, shipping and fees (at least one).
	 */
	public function rows(): int {
		return max( 1, count( $this->lines ) + count( $this->extras ) );
	}

	/**
	 * Whether the order has been paid.
	 */
	public function isPaid(): bool {
		return '' !== $this->paymentDate;
	}

	/**
	 * The customer block as plain strings. `name` is the company when there is one, else first and last name.
	 *
	 * @param mixed $raw Block from the payload.
	 * @return array<string,string>
	 */
	public static function customer( mixed $raw ): array {
		$block  = PayloadReader::block( $raw );
		$fields = array( 'email', 'first_name', 'last_name', 'company', 'phone', 'street', 'supplement', 'zip', 'city', 'state', 'country', 'vat_id', 'note' );
		$result = array();
		foreach ( $fields as $field ) {
			$result[ $field ] = PayloadReader::text( $block[ $field ] ?? '' );
		}
		$person         = trim( $result['first_name'] . ' ' . $result['last_name'] );
		$result['name'] = '' !== $result['company'] ? $result['company'] : $person;
		if ( '' === $result['name'] ) {
			$result['name'] = PayloadReader::text( $block['name'] ?? '' );
		}
		$result['email']   = strtolower( $result['email'] );
		$result['country'] = strtoupper( $result['country'] );
		return $result;
	}

	/**
	 * One product row.
	 *
	 * @param array<string,mixed> $raw    Row from the payload.
	 * @param int                 $number Position, for messages.
	 * @param string              $system Vendor label.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function productLine( array $raw, int $number, string $system ): array|ConnectorError {
		$sku  = PayloadReader::text( $raw['sku'] ?? '' );
		$name = PayloadReader::text( $raw['name'] ?? '' );
		if ( '' === $sku && '' === $name ) {
			return Refusal::product( $system, '#' . $number );
		}
		$quantity = PayloadReader::quantity( $raw['quantity'] ?? null );
		if ( $quantity <= 0.0 ) {
			return Refusal::value(
				$system,
				'lines[].quantity',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no quantity.', 'estasoft-erp-crm-sync-engine' ),
					$number
				),
				'payload refused'
			);
		}
		$total = PayloadReader::minor( $raw['total'] ?? null );
		if ( null === $total ) {
			return Refusal::value(
				$system,
				'lines[].total',
				sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no amount.', 'estasoft-erp-crm-sync-engine' ),
					$number
				),
				'payload refused'
			);
		}
		return array(
			'sku'         => $sku,
			'name'        => '' !== $name ? $name : $sku,
			'quantity'    => $quantity,
			'total_minor' => $total,
			'tax_code'    => PayloadReader::taxCode( $raw['tax_code'] ?? '' ),
			'rate_bp'     => self::rate( $raw['tax_rate'] ?? null ),
		);
	}

	/**
	 * A tax rate in percent (a number, text, or a list whose first entry counts) as basis points, or null when there is none.
	 *
	 * @param mixed $value Value from the payload.
	 */
	private static function rate( mixed $value ): ?int {
		$text = PayloadReader::taxCode( $value );
		return '' === $text ? null : ( new Amounts() )->basisPoints( $text );
	}
}
