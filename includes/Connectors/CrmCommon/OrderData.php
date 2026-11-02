<?php
/**
 * An order as a connector reads it from the mapped payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the payload with every amount in integer minor units.
 */
final class OrderData {

	/**
	 * Create the object.
	 *
	 * @param string                                                  $reference      Client reference WC-{id}.
	 * @param string                                                  $currency       ISO currency code.
	 * @param string                                                  $date           Order date, Y-m-d, UTC.
	 * @param string                                                  $note           Customer or order note.
	 * @param bool                                                    $paid           Whether the order is paid.
	 * @param int                                                     $totalMinor     What the vendor's record should total: order total minus the excluded part.
	 * @param bool                                                    $pricesIncludeTax Whether the line prices already hold tax.
	 * @param array<string,string>                                    $contact        email, first_name, last_name, company, phone, street, city, state, postcode, country.
	 * @param array<int,OrderLine>                                    $lines          Product lines.
	 * @param int                                                     $shippingMinor  Shipping without tax.
	 * @param array<int,array{name:string,total:int,tax_code:string}> $fees           Fees without tax.
	 * @param int                                                     $discountMinor  Discount, positive number.
	 * @param string                                                  $discountCodes  Coupon codes.
	 * @param string                                                  $paymentReference Payment transaction id.
	 * @param string                                                  $shippingTaxCode  Tax code of the shipping line, may be empty.
	 * @param string                                                  $paidDate         Date the order was paid, Y-m-d, UTC; empty when it is not paid.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $currency,
		public readonly string $date,
		public readonly string $note,
		public readonly bool $paid,
		public readonly int $totalMinor,
		public readonly bool $pricesIncludeTax,
		public readonly array $contact,
		public readonly array $lines,
		public readonly int $shippingMinor = 0,
		public readonly array $fees = array(),
		public readonly int $discountMinor = 0,
		public readonly string $discountCodes = '',
		public readonly string $paymentReference = '',
		public readonly string $shippingTaxCode = '',
		public readonly string $paidDate = '',
	) {
	}

	/**
	 * First and last name joined; the company name when the buyer gave no name.
	 */
	public function fullName(): string {
		$name = trim( ( $this->contact['first_name'] ?? '' ) . ' ' . ( $this->contact['last_name'] ?? '' ) );
		return '' !== $name ? $name : trim( $this->contact['company'] ?? '' );
	}

	/**
	 * The buyer's email address.
	 */
	public function email(): string {
		return $this->contact['email'] ?? '';
	}
}
