<?php
/**
 * A mapped order, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * The payload the mapping engine builds for the three Microsoft connectors has the same paths: `currency`, `order_date`, `note`, `total`,
 * `excluded_total`, `prices_include_tax`, `partner.*`, `delivery.*`, `order_line[].*`, `shipping.*`, `fee[].*`, `discount.*` and
 * `payment.reference`. This class turns it into typed values and refuses a payload that is wrong on its face before any request goes out.
 * Amounts are integer minor units; a decimal or a text that is not a whole number is refused rather than rounded.
 */
final class OrderData {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string                $currency         ISO 4217 code, upper case.
	 * @param string                $orderDate        Order date YYYY-MM-DD, or an empty string.
	 * @param string                $note             Order note.
	 * @param int|null              $totalMinor       The total the shop expects, with tax, or null when the mapping did not supply it.
	 * @param int                   $excludedMinor    Part of that total that belongs to products left out of the sync.
	 * @param bool                  $pricesIncludeTax Whether the mapped prices include tax.
	 * @param array<string,string>  $partner          email, name, first_name, last_name, company_name, phone, street, street2, city, state_id, zip, country_id, vat.
	 * @param array<string,string>  $delivery         name, phone, street, street2, city, state_id, zip, country_id.
	 * @param array<int,OrderLine>  $lines            Product lines.
	 * @param OrderExtra|null       $shipping         Shipping.
	 * @param array<int,OrderExtra> $fees            Fees.
	 * @param OrderExtra|null       $discount         Order discount (positive amount).
	 * @param string                $paymentReference Payment reference.
	 * @param array<string,string>  $extra            Other text values by path: warehouse, site.
	 */
	public function __construct(
		public readonly string $currency,
		public readonly string $orderDate,
		public readonly string $note,
		public readonly ?int $totalMinor,
		public readonly int $excludedMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $partner,
		public readonly array $delivery,
		public readonly array $lines,
		public readonly ?OrderExtra $shipping,
		public readonly array $fees,
		public readonly ?OrderExtra $discount,
		public readonly string $paymentReference,
		public readonly array $extra = array(),
	) {
	}

	/**
	 * Read and check a mapped payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param string              $system  System name for messages.
	 * @return self|ConnectorError The order, or the refusal.
	 */
	public static function parse( array $payload, string $system ): self|ConnectorError {
		$currency = strtoupper( self::text( $payload['currency'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return Failures::invalid( $system, 'currency', __( 'The order has no currency code.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency missing' );
		}
		$rows = $payload['order_line'] ?? array();
		if ( ! is_array( $rows ) || array() === $rows ) {
			return Failures::invalid( $system, 'order_line', __( 'The order has no product lines.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no lines' );
		}
		$lines = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			$line = self::line( is_array( $row ) ? $row : array(), $system, $index );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		$amounts = array();
		foreach ( array( 'total', 'excluded_total' ) as $key ) {
			$amounts[ $key ] = self::minor( $payload[ $key ] ?? null );
			if ( false === $amounts[ $key ] ) {
				return Failures::invalid( $system, $key, __( 'Amounts must be whole numbers of the smallest currency unit.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'amount' );
			}
		}
		$shipping = self::extra( $payload['shipping'] ?? null, 'shipping', $system );
		$discount = self::extra( $payload['discount'] ?? null, 'discount', $system );
		$fees     = array();
		foreach ( is_array( $payload['fee'] ?? null ) ? array_values( $payload['fee'] ) : array() as $fee ) {
			$one = self::extra( $fee, 'fee[]', $system );
			if ( $one instanceof ConnectorError ) {
				return $one;
			}
			if ( null !== $one ) {
				$fees[] = $one;
			}
		}
		foreach ( array( $shipping, $discount ) as $checked ) {
			if ( $checked instanceof ConnectorError ) {
				return $checked;
			}
		}
		$payment = is_array( $payload['payment'] ?? null ) ? $payload['payment'] : array();
		return new self(
			$currency,
			Dates::calendarDate( $payload['order_date'] ?? '' ),
			self::text( $payload['note'] ?? '' ),
			is_int( $amounts['total'] ) ? $amounts['total'] : null,
			is_int( $amounts['excluded_total'] ) ? $amounts['excluded_total'] : 0,
			self::bool( $payload['prices_include_tax'] ?? false ),
			self::strings( $payload['partner'] ?? null ),
			self::strings( $payload['delivery'] ?? null ),
			$lines,
			$shipping instanceof OrderExtra ? $shipping : null,
			$fees,
			$discount instanceof OrderExtra ? $discount : null,
			self::text( $payment['reference'] ?? '' ),
			array(
				'warehouse' => self::text( $payload['warehouse'] ?? '' ),
				'site'      => self::text( $payload['site'] ?? '' ),
			)
		);
	}

	/**
	 * The total Business Central and the others should reach: the shop's total less what was left out. Null when unknown.
	 */
	public function expectedMinor(): ?int {
		return null === $this->totalMinor ? null : max( 0, $this->totalMinor - $this->excludedMinor );
	}

	/**
	 * Name of the buyer: the company, else the name, else first and last name, else the email.
	 */
	public function buyerName(): string {
		foreach ( array( 'company_name', 'name' ) as $key ) {
			if ( '' !== ( $this->partner[ $key ] ?? '' ) ) {
				return $this->partner[ $key ];
			}
		}
		$person = trim( ( $this->partner['first_name'] ?? '' ) . ' ' . ( $this->partner['last_name'] ?? '' ) );
		return '' !== $person ? $person : ( $this->partner['email'] ?? '' );
	}

	/**
	 * Name of the person: the name, else first and last name, else empty.
	 */
	public function personName(): string {
		if ( '' !== ( $this->partner['name'] ?? '' ) ) {
			return $this->partner['name'];
		}
		return trim( ( $this->partner['first_name'] ?? '' ) . ' ' . ( $this->partner['last_name'] ?? '' ) );
	}

	/**
	 * Read one product line.
	 *
	 * @param array<string,mixed> $row    Row of order_line[].
	 * @param string              $system System name.
	 * @param int                 $index  Row number.
	 */
	private static function line( array $row, string $system, int $index ): OrderLine|ConnectorError {
		$sku = self::text( $row['product'] ?? '' );
		if ( '' === $sku ) {
			return Failures::invalid( $system, 'order_line[].product', __( 'A line has no product reference (SKU).', 'estasoft-enterprise-erpcrm-sync-engine' ), 'line ' . $index . ' has no product' );
		}
		$quantity = $row['quantity'] ?? null;
		if ( is_string( $quantity ) && is_numeric( $quantity ) ) {
			$quantity = $quantity + 0;
		}
		if ( ! ( is_int( $quantity ) || is_float( $quantity ) ) || $quantity <= 0 ) {
			return Failures::invalid( $system, 'order_line[].quantity', __( 'A line needs a quantity above zero.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'line ' . $index . ' quantity' );
		}
		$price = self::minor( $row['price_unit'] ?? null );
		if ( ! is_int( $price ) || $price < 0 ) {
			return Failures::invalid( $system, 'order_line[].price_unit', __( 'A line needs a price in whole numbers of the smallest currency unit.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'line ' . $index . ' price' );
		}
		$tax = self::minor( self::first( $row['tax_amount'] ?? null ) );
		return new OrderLine( $sku, self::text( $row['name'] ?? '' ), $quantity, $price, self::text( $row['tax'] ?? '' ), is_int( $tax ) ? $tax : 0 );
	}

	/**
	 * Read shipping, a fee or a discount.
	 *
	 * @param mixed  $value  The block.
	 * @param string $field  Path for messages.
	 * @param string $system System name.
	 */
	private static function extra( mixed $value, string $field, string $system ): OrderExtra|ConnectorError|null {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$total = self::minor( $value['total'] ?? null );
		if ( ! is_int( $total ) || $total < 0 ) {
			return Failures::invalid( $system, $field . '.total', __( 'Amounts must be whole numbers of the smallest currency unit.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'extra amount' );
		}
		if ( 0 === $total ) {
			return null;
		}
		$tax = self::minor( self::first( $value['tax_amount'] ?? null ) );
		return new OrderExtra( self::text( self::first( $value['name'] ?? '' ) ), $total, self::text( self::first( $value['tax'] ?? '' ) ), is_int( $tax ) ? $tax : 0 );
	}

	/**
	 * Whole minor units from a value: an integer, a whole float, or digits. Null/empty gives null; anything else false.
	 *
	 * @param mixed $value Value.
	 * @return int|false|null
	 */
	private static function minor( mixed $value ): int|false|null {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		return false;
	}

	/**
	 * A value that a rule read from a list (`shipping_lines[].tax_rate`) and wrote to a single field comes as a list of one: take it.
	 *
	 * @param mixed $value Value or list of values.
	 */
	private static function first( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $entry ) {
			if ( null !== $entry && '' !== $entry ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * A trimmed text from a scalar.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) || is_int( $value ) || is_float( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The texts of a block, by key.
	 *
	 * @param mixed $block Block.
	 * @return array<string,string>
	 */
	private static function strings( mixed $block ): array {
		$found = array();
		foreach ( is_array( $block ) ? $block : array() as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
				$found[ $key ] = trim( (string) $value );
			}
		}
		return $found;
	}

	/**
	 * A yes/no value.
	 *
	 * @param mixed $value Value.
	 */
	private static function bool( mixed $value ): bool {
		return true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true ) );
	}
}
