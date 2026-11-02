<?php
/**
 * The body of an item invoice or a credit invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Pure: it takes the checked payload, the customer UID, the items found and the tax code UIDs, and returns the body or the reason the document cannot
 * be built (SYNC-MAP-001 for a product MYOB does not have, SYNC-VAL-002 for an item that cannot be sold or a missing item option). Prices are sent as they
 * arrived; MYOB is told whether they include tax (IsTaxInclusive), so no tax arithmetic happens here. A line total is the quantity times the unit
 * price in whole minor units, less the line discount, because MYOB wants the total next to its parts.
 */
final class MyobInvoiceBuilder {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobOptions $options Connection options.
	 * @param MyobAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly MyobOptions $options, private readonly MyobAmounts $amounts ) {
	}

	/**
	 * The item invoice.
	 *
	 * @param MyobOrderPayload                       $payload     Checked payload.
	 * @param string                                 $customerUid MYOB customer UID.
	 * @param string                                 $currency    Currency of the file.
	 * @param array<string,array<string,mixed>>      $items       Items by lower-case number.
	 * @param array<string,array<string,mixed>|null> $named       Items named in the options, by kind (fee, discount, generic).
	 * @param array<string,string>                   $tax         Tax code UIDs by lower-case code.
	 * @param string                                 $noTaxUid    UID of the tax code for an untaxed line, or empty.
	 * @param array<int,string>                      $warnings    Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function invoice( MyobOrderPayload $payload, string $customerUid, string $currency, array $items, array $named, array $tax, string $noTaxUid, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $line ) {
			$item = $items[ strtolower( (string) $line['sku'] ) ] ?? null;
			if ( null === $item ) {
				return MyobErrors::unknownProduct( (string) $line['sku'] );
			}
			if ( false === ( $item['IsSold'] ?? true ) ) {
				return $this->notSold( (string) $line['sku'] );
			}
			$name    = '' !== (string) $line['name'] ? (string) $line['name'] : MyobPayloadReader::text( $item['Name'] ?? $line['sku'] );
			$lines[] = $this->line( (string) $item['UID'], $name, (string) $line['quantity'], (int) $line['price_minor'], (float) $line['discount'], $this->taxUid( $line['tax'], $tax, $noTaxUid ), $currency, $cut );
		}
		foreach ( $payload->extras as $extra ) {
			$kind = (string) $extra['kind'];
			$item = $named[ $kind ] ?? ( $named['generic'] ?? null );
			if ( null === $item ) {
				return $this->needsItem( $kind . '_item', 'generic_item' );
			}
			$price = (int) $extra['price_minor'];
			// A discount is a line with a negative quantity and a positive price: MYOB refuses a negative price with a non-zero total (error 12101).
			$lines[] = $this->line( (string) $item['UID'], (string) $extra['name'], $price < 0 ? '-1' : '1', abs( $price ), 0.0, $this->taxUid( $extra['tax'], $tax, $noTaxUid ), $currency, $cut );
		}
		$invoice = array(
			'Date'                        => $payload->date . ' 00:00:00',
			'Customer'                    => array( 'UID' => $customerUid ),
			'CustomerPurchaseOrderNumber' => $payload->clientRef,
			'IsTaxInclusive'              => $payload->pricesIncludeTax,
			'Lines'                       => $lines,
		);
		if ( 0 !== $payload->freightMinor ) {
			$invoice['Freight'] = $this->amounts->number( $payload->freightMinor, $currency );
			$freightTax         = $this->taxUid( $payload->freightTax, $tax, $noTaxUid );
			if ( '' !== $freightTax ) {
				$invoice['FreightTaxCode'] = array( 'UID' => $freightTax );
			}
		}
		if ( '' !== $payload->note ) {
			$invoice['Comment'] = MyobPayloadReader::limit( $payload->note, 2000, $cut );
		}
		$days = $this->options->integer( 'due_days', 0, 0, 365 );
		if ( $days > 0 ) {
			$invoice['Terms'] = array(
				'PaymentIsDue'   => 'InAGivenNumberOfDays',
				'BalanceDueDate' => $days,
			);
		}
		$address = self::shipTo( $payload->delivery );
		if ( '' !== $address ) {
			$invoice['ShipToAddress'] = MyobPayloadReader::limit( $address, 255, $cut );
		}
		if ( $cut ) {
			$warnings[] = __( 'A long value was shortened to fit MYOB.', 'estasoft-erp-crm-sync-engine' );
		}
		return $invoice;
	}

	/**
	 * The credit invoice: the same layout with negative quantities and positive prices.
	 *
	 * @param MyobRefundPayload                      $payload     Checked payload.
	 * @param array<string,mixed>                    $invoice     The refunded order's invoice.
	 * @param string                                 $currency    Currency of the file.
	 * @param array<string,array<string,mixed>>      $items       Items by lower-case number.
	 * @param array<string,array<string,mixed>|null> $named       Items named in the options (generic).
	 * @param array<string,string>                   $tax         Tax code UIDs by lower-case code.
	 * @param string                                 $noTaxUid    UID of the tax code for an untaxed line, or empty.
	 * @param array<int,string>                      $warnings    Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function credit( MyobRefundPayload $payload, array $invoice, string $currency, array $items, array $named, array $tax, string $noTaxUid, array &$warnings ): array|ConnectorError {
		$cut     = false;
		$lines   = array();
		$freight = 0;
		$fTax    = null;
		foreach ( $payload->lines as $index => $line ) {
			$kind = (string) ( $line['kind'] ?? '' );
			if ( 'shipping' === $kind ) {
				$freight = (int) $line['amount_minor'];
				$fTax    = $line['tax'];
				continue;
			}
			$sku  = (string) $line['sku'];
			$item = '' !== $sku ? ( $items[ strtolower( $sku ) ] ?? null ) : ( $named['generic'] ?? null );
			if ( null === $item ) {
				return '' !== $sku ? MyobErrors::unknownProduct( $sku ) : $this->needsItem( 'generic_item', '' );
			}
			$text = '' !== (string) $line['name'] ? (string) $line['name'] : ( '' !== $sku ? $sku : MyobPayloadReader::text( $item['Name'] ?? '' ) );
			if ( 0 === $index && '' !== $payload->reason ) {
				$text = $payload->reason . ': ' . $text;
			}
			$amount   = (int) $line['amount_minor'];
			$quantity = (string) $line['quantity'];
			if ( 1 === preg_match( '/^[1-9]\d{0,6}$/', $quantity ) && 0 === $amount % (int) $quantity ) {
				$qty  = $quantity;
				$unit = intdiv( $amount, (int) $quantity );
			} else {
				$qty   = '1';
				$unit  = $amount;
				$text .= ' (' . $quantity . ')';
			}
			$lines[] = $this->line( (string) $item['UID'], $text, '-' . $qty, $unit, 0.0, $this->taxUid( $line['tax'], $tax, $noTaxUid ), $currency, $cut );
		}
		$credit = array(
			'Date'                        => ( '' !== $payload->date ? $payload->date : MyobPayloadReader::day( $invoice['Date'] ?? '' ) ) . ' 00:00:00',
			'Customer'                    => array( 'UID' => MyobPayloadReader::text( $invoice['Customer']['UID'] ?? '' ) ),
			'CustomerPurchaseOrderNumber' => $payload->clientRef,
			'JournalMemo'                 => MyobPayloadReader::limit( 'Refund of ' . $payload->orderRef, 255, $cut ),
			'IsTaxInclusive'              => $payload->pricesIncludeTax,
			'Lines'                       => $lines,
		);
		if ( 0 !== $freight ) {
			$credit['Freight'] = $this->amounts->number( -$freight, $currency );
			$freightTax        = $this->taxUid( $fTax, $tax, $noTaxUid );
			if ( '' !== $freightTax ) {
				$credit['FreightTaxCode'] = array( 'UID' => $freightTax );
			}
		}
		if ( '' !== $payload->reason ) {
			$credit['Comment'] = MyobPayloadReader::limit( $payload->reason, 2000, $cut );
		}
		if ( $cut ) {
			$warnings[] = __( 'A long description was shortened to fit MYOB.', 'estasoft-erp-crm-sync-engine' );
		}
		return $credit;
	}

	/**
	 * The tax codes a document uses, in the order they appear, so the sender can look them up before anything is created.
	 *
	 * @param array<int,array<string,mixed>> $lines   Product lines.
	 * @param array<int,array<string,mixed>> $extras  Fee and discount lines.
	 * @param string|null                    $freight Freight tax code.
	 * @return array<int,string>
	 */
	public static function taxCodesUsed( array $lines, array $extras, ?string $freight ): array {
		$codes = array();
		foreach ( array_merge( $lines, $extras ) as $line ) {
			if ( isset( $line['tax'] ) && '' !== (string) $line['tax'] ) {
				$codes[] = (string) $line['tax'];
			}
		}
		if ( null !== $freight && '' !== $freight ) {
			$codes[] = $freight;
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * The UID for a line's tax key: null key (not sent) gives empty, an empty code gives the no-tax code, a code gives its UID.
	 *
	 * @param string|null          $code     Tax key of the line.
	 * @param array<string,string> $tax      Tax code UIDs by lower-case code.
	 * @param string               $noTaxUid UID of the no-tax code.
	 */
	private function taxUid( ?string $code, array $tax, string $noTaxUid ): string {
		if ( null === $code ) {
			return '';
		}
		return '' === $code ? $noTaxUid : ( $tax[ strtolower( $code ) ] ?? '' );
	}

	/**
	 * One sales line.
	 *
	 * @param string $itemUid  Item UID.
	 * @param string $name     Description.
	 * @param string $quantity Quantity as decimal text (negative on a credit or a discount).
	 * @param int    $unit     Unit price in minor units (never negative).
	 * @param float  $discount Discount percent.
	 * @param string $taxUid   Tax code UID, or empty to leave it to MYOB.
	 * @param string $currency Currency.
	 * @param bool   $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>
	 */
	private function line( string $itemUid, string $name, string $quantity, int $unit, float $discount, string $taxUid, string $currency, bool &$cut ): array {
		$gross = $this->amounts->lineTotal( $quantity, $unit );
		$scale = (int) round( $discount * 100 );
		$net   = 0 === $scale ? $gross : ( $gross >= 0 ? intdiv( $gross * ( 10000 - $scale ) + 5000, 10000 ) : -intdiv( -$gross * ( 10000 - $scale ) + 5000, 10000 ) );
		$line  = array(
			'Type'            => 'Transaction',
			'Description'     => MyobPayloadReader::limit( $name, 1000, $cut ),
			'ShipQuantity'    => $this->amounts->quantity( $quantity ),
			'UnitPrice'       => $this->amounts->number( $unit, $currency ),
			'DiscountPercent' => $discount,
			'Total'           => $this->amounts->number( $net, $currency ),
			'Item'            => array( 'UID' => $itemUid ),
		);
		if ( '' !== $taxUid ) {
			$line['TaxCode'] = array( 'UID' => $taxUid );
		}
		return $line;
	}

	/**
	 * The delivery address as the free text MYOB keeps on an invoice.
	 *
	 * @param array<string,string> $delivery Delivery fields.
	 */
	private static function shipTo( array $delivery ): string {
		$place = implode( ' ', array_filter( array( $delivery['city'] ?? '', $delivery['state_id'] ?? '', $delivery['zip'] ?? '' ), static fn( string $part ): bool => '' !== $part ) );
		$parts = array_filter(
			array( $delivery['street'] ?? '', $delivery['street2'] ?? '', $place, $delivery['country_id'] ?? '' ),
			static fn( string $part ): bool => '' !== $part
		);
		return implode( "\n", $parts );
	}

	/**
	 * The failure for an item that is not for sale.
	 *
	 * @param string $number Item number.
	 */
	private function notSold( string $number ): ConnectorError {
		return MyobErrors::refused(
			'Item',
			sprintf(
				/* translators: %s: a MYOB item number. */
				__( 'The item "%s" is not marked "I sell this item" in MYOB, so it cannot go on an invoice.', 'estasoft-erp-crm-sync-engine' ),
				$number
			)
		);
	}

	/**
	 * The failure for a missing item option.
	 *
	 * @param string $option Option name.
	 * @param string $other  Another option that would do, or empty.
	 */
	private function needsItem( string $option, string $other ): ConnectorError {
		$names = '' === $other ? '"' . $option . '"' : '"' . $option . '" (or "' . $other . '")';
		return MyobErrors::refused(
			$option,
			sprintf(
				/* translators: %s: the name of one or two connection options. */
				__( 'Set the number of an item that is sold in the connection option %s: MYOB needs an item on every line.', 'estasoft-erp-crm-sync-engine' ),
				$names
			)
		);
	}
}
