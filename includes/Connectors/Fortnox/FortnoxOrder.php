<?php
/**
 * The mapped order payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Reads the nested array the mapping engine builds from the rules of the "orders" mapping (paths such as partner.email and
 * order_line[].price_unit, see docs/connectors/fortnox.md). Money arrives as integer minor units. The client reference
 * always comes from the queue item, never from the payload.
 */
final class FortnoxOrder {

	/**
	 * Keys of the partner array.
	 *
	 * @var array<int,string>
	 */
	private const PARTNER_KEYS = array( 'name', 'email', 'company_name', 'phone', 'street', 'city', 'zip', 'country_id', 'vat' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                         $reference        WC-{id}.
	 * @param string                         $currency         ISO currency code.
	 * @param string                         $date             Order date, Y-m-d.
	 * @param string                         $note             Order note.
	 * @param int|null                       $totalMinor       Total Fortnox must show (the order total less the excluded part), or null.
	 * @param bool                           $pricesIncludeTax True when the mapped prices include tax.
	 * @param array<string,string>           $partner          Buyer.
	 * @param array<int,array<string,mixed>> $lines            Product lines: sku, name, quantity (text), unit (minor), tax.
	 * @param array<int,array<string,mixed>> $extras           Shipping, fee and discount lines: kind, name, amount (minor, discount positive), tax.
	 * @param string                         $paymentReference Payment transaction id.
	 * @param string                         $paidDate         Date the order was paid (Y-m-d), or an empty string when it is unpaid.
	 */
	private function __construct(
		public readonly string $reference,
		public readonly string $currency,
		public readonly string $date,
		public readonly string $note,
		public readonly ?int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $partner,
		public readonly array $lines,
		public readonly array $extras,
		public readonly string $paymentReference,
		public readonly string $paidDate = '',
	) {
	}

	/**
	 * Read and check a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Item                $item    Queue item.
	 * @param string              $today   Y-m-d used when the payload has no date.
	 * @param bool                $freeText Whether a line without a SKU may become a row of text.
	 * @return self|ConnectorError MAP-003 when the email is missing, VAL-002 for anything else wrong.
	 */
	public static function parse( array $payload, Item $item, string $today, bool $freeText = false ): self|ConnectorError {
		$partner = array();
		$raw     = is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array();
		foreach ( self::PARTNER_KEYS as $key ) {
			$value = FortnoxPayload::text( $raw[ $key ] ?? '' );
			if ( '' !== $value ) {
				$partner[ $key ] = $value;
			}
		}
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return new ConnectorError(
				ErrorCode::Map003,
				array(
					'order'  => $item->entityId,
					'system' => FortnoxConnector::LABEL,
				),
				null,
				null,
				null,
				'email missing'
			);
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return self::refused( 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$currency = strtoupper( FortnoxPayload::text( $payload['currency'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return self::refused( 'currency', __( 'The order has no valid currency code.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lines = array();
		foreach ( FortnoxPayload::rows( $payload['order_line'] ?? null ) as $index => $row ) {
			$line = self::line( $row, $index + 1, $freeText );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		if ( array() === $lines ) {
			return self::refused( 'order_line', __( 'The order has no product lines.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$extras = self::extras( $payload );
		$total  = FortnoxPayload::minor( $payload['total'] ?? null );
		if ( null !== $total ) {
			$total -= abs( (int) FortnoxPayload::minor( $payload['excluded_total'] ?? null ) );
		}
		$payment = is_array( $payload['payment'] ?? null ) ? $payload['payment'] : array();
		return new self(
			$item->clientReference(),
			$currency,
			FortnoxPayload::date( $payload['date_order'] ?? '', $today ),
			FortnoxPayload::text( $payload['note'] ?? '' ),
			$total,
			FortnoxPayload::flag( $payload['prices_include_tax'] ?? null ),
			$partner,
			$lines,
			$extras,
			FortnoxPayload::text( $payment['reference'] ?? '' ),
			'' !== FortnoxPayload::text( $payload['paid_at'] ?? '' ) ? FortnoxPayload::date( $payload['paid_at'], $today ) : ''
		);
	}

	/**
	 * The buyer's name: the company when given, else the name typed.
	 */
	public function accountName(): string {
		return '' !== ( $this->partner['company_name'] ?? '' ) ? $this->partner['company_name'] : ( $this->partner['name'] ?? '' );
	}

	/**
	 * One product line.
	 *
	 * @param array<string,mixed> $row      Row.
	 * @param int                 $number   Row number, 1 based.
	 * @param bool                $freeText Whether a row without a SKU is allowed.
	 * @return array<string,mixed>|ConnectorError
	 */
	private static function line( array $row, int $number, bool $freeText ): array|ConnectorError {
		$sku      = FortnoxPayload::text( $row['product'] ?? '' );
		$quantity = FortnoxPayload::quantity( $row['quantity'] ?? null );
		$unit     = FortnoxPayload::minor( $row['price_unit'] ?? null );
		if ( '' === $sku && ! $freeText ) {
			/* translators: %d: line number. */
			return self::refused( 'order_line.product', sprintf( __( 'Line %d has no SKU.', 'estasoft-enterprise-erpcrm-sync-engine' ), $number ) );
		}
		if ( null === $quantity || null === $unit ) {
			/* translators: %d: line number. */
			return self::refused( 'order_line', sprintf( __( 'Line %d has no valid quantity or price.', 'estasoft-enterprise-erpcrm-sync-engine' ), $number ) );
		}
		return array(
			'sku'      => $sku,
			'name'     => FortnoxPayload::text( $row['name'] ?? '' ),
			'quantity' => $quantity,
			'unit'     => $unit,
			'tax'      => FortnoxPayload::taxCode( $row['tax'] ?? null ),
		);
	}

	/**
	 * Shipping, fee and discount lines.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extras( array $payload ): array {
		$extras = array();
		$ship   = is_array( $payload['shipping'] ?? null ) ? $payload['shipping'] : array();
		$amount = FortnoxPayload::minor( $ship['total'] ?? null );
		if ( null !== $amount && 0 !== $amount ) {
			$extras[] = array(
				'kind'   => 'shipping',
				'name'   => '' !== FortnoxPayload::text( $ship['name'] ?? '' ) ? FortnoxPayload::text( $ship['name'] ) : 'Shipping',
				'amount' => abs( $amount ),
				'tax'    => FortnoxPayload::taxCode( $ship['tax'] ?? null ),
			);
		}
		foreach ( FortnoxPayload::rows( $payload['fee'] ?? null ) as $fee ) {
			$amount = FortnoxPayload::minor( $fee['total'] ?? null );
			if ( null !== $amount && 0 !== $amount ) {
				$extras[] = array(
					'kind'   => 'fee',
					'name'   => '' !== FortnoxPayload::text( $fee['name'] ?? '' ) ? FortnoxPayload::text( $fee['name'] ) : 'Fee',
					'amount' => $amount,
					'tax'    => FortnoxPayload::taxCode( $fee['tax'] ?? null ),
				);
			}
		}
		$discount = is_array( $payload['discount'] ?? null ) ? $payload['discount'] : array();
		$amount   = FortnoxPayload::minor( $discount['total'] ?? null );
		if ( null !== $amount && 0 !== $amount ) {
			$extras[] = array(
				'kind'   => 'discount',
				'name'   => '' !== FortnoxPayload::text( $discount['name'] ?? '' ) ? FortnoxPayload::text( $discount['name'] ) : 'Discount',
				'amount' => abs( $amount ),
				'tax'    => FortnoxPayload::taxCode( $discount['tax'] ?? null ),
			);
		}
		return $extras;
	}

	/**
	 * A SYNC-VAL-002 error.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 */
	private static function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => FortnoxConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'invalid ' . $field
		);
	}
}
