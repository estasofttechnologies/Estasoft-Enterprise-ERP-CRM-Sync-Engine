<?php
/**
 * The body of a sales invoice or a sales credit note.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Pure: it takes the checked payload, the contact id, the products and the tax rates found, and returns the body or the reason the document cannot be built
 * (SYNC-MAP-001 for a product Sage does not have, SYNC-VAL-002 for a line without a tax code). Sage does not work tax out: every line carries a tax_amount that
 * the caller calculates, so it is worked out here in whole minor units, half away from zero, per line and for the shipping. A zero rate sends no tax_amount
 * [documented], and a Spanish business sends none at all [documented]. Amounts are decimal strings.
 *
 * Prices that include tax: in the UK, Ireland and France a line carries `unit_price_includes_tax` true and no tax_amount, and Sage works the tax out [Sage community,
 * Sage's API description]. In Canada and Spain the flag does not apply, so the line is converted to net here (SageAmounts::netFromGross) and, in Canada, the tax is
 * calculated as for prices without tax. Shipping has no such flag and is always converted.
 */
final class SageBuilder {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageOptions $options Connection options.
	 * @param SageAmounts $amounts Conversion helper.
	 * @param string      $country Country of the business (lower-case ISO code).
	 */
	public function __construct( private readonly SageOptions $options, private readonly SageAmounts $amounts, private readonly string $country = 'gb' ) {
	}

	/**
	 * The sales invoice, as a draft.
	 *
	 * @param SageOrderPayload                        $payload   Checked payload.
	 * @param string                                  $contactId Sage contact id.
	 * @param string                                  $currency  Currency of the business.
	 * @param array<string,array<string,mixed>>       $products  Products by lower-case item code.
	 * @param array<string,array{id:string,rate:int}> $rates  Tax rates by lower-case id; the key "" holds the rate for a line with no tax.
	 * @param array<string,string>                    $ledgers   Ledger account ids for sales, fee and discount lines.
	 * @param array<int,string>                       $warnings  Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function invoice( SageOrderPayload $payload, string $contactId, string $currency, array $products, array $rates, array $ledgers, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $index => $line ) {
			$sku     = (string) $line['sku'];
			$product = '' !== $sku ? ( $products[ strtolower( $sku ) ] ?? null ) : null;
			if ( null === $product && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return SageErrors::unknownProduct( '' !== $sku ? $sku : (string) $line['name'] );
			}
			$rate = $this->rate( $line['tax'], $rates, $index + 1 );
			if ( $rate instanceof ConnectorError ) {
				return $rate;
			}
			$name = '' !== (string) $line['name'] ? (string) $line['name'] : ( null !== $product ? SagePayloadReader::text( $product['description'] ?? $sku ) : $sku );
			if ( '' === $name ) {
				$name = __( 'Item', 'estasoft-enterprise-erpcrm-sync-engine' );
			}
			$lines[] = $this->line( $product, (string) $ledgers['sales'], $name, (string) $line['quantity'], (int) $line['price_minor'], (float) $line['discount'], $rate, $currency, $payload->pricesIncludeTax, $cut );
		}
		foreach ( $payload->extras as $extra ) {
			$rate = $this->rate( $extra['tax'], $rates, 0 );
			if ( $rate instanceof ConnectorError ) {
				return $rate;
			}
			$lines[] = $this->line( null, (string) $ledgers[ (string) $extra['kind'] ], (string) $extra['name'], '1', (int) $extra['price_minor'], 0.0, $rate, $currency, $payload->pricesIncludeTax, $cut );
		}
		$invoice = array(
			'contact_id'    => $contactId,
			'date'          => $payload->date,
			'due_date'      => $this->dueDate( $payload->date ),
			'reference'     => $payload->clientRef,
			'status_id'     => 'DRAFT',
			'invoice_lines' => $lines,
		);
		if ( '' !== $payload->note ) {
			$invoice['notes'] = SagePayloadReader::limit( $payload->note, 2000, $cut );
		}
		if ( 0 !== $payload->shippingMinor ) {
			$shipping = $this->shipping( $payload->shippingMinor, $payload->shippingTax, $rates, $currency, $payload->pricesIncludeTax );
			if ( $shipping instanceof ConnectorError ) {
				return $shipping;
			}
			$invoice += $shipping;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long value was shortened to fit Sage.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $invoice;
	}

	/**
	 * The sales credit note.
	 *
	 * @param SageRefundPayload                       $payload   Checked payload.
	 * @param array<string,mixed>                     $invoice   The refunded order's invoice.
	 * @param string                                  $currency  Currency of the business.
	 * @param array<string,array<string,mixed>>       $products  Products by lower-case item code.
	 * @param array<string,array{id:string,rate:int}> $rates  Tax rates by lower-case id; the key "" holds the rate for a line with no tax.
	 * @param array<string,string>                    $ledgers   Ledger account ids for sales, fee and discount lines.
	 * @param array<int,string>                       $warnings  Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function creditNote( SageRefundPayload $payload, array $invoice, string $currency, array $products, array $rates, array $ledgers, array &$warnings ): array|ConnectorError {
		$cut      = false;
		$lines    = array();
		$shipping = array();
		foreach ( $payload->lines as $index => $line ) {
			if ( 'shipping' === ( $line['kind'] ?? '' ) ) {
				$built = $this->shipping( (int) $line['amount_minor'], $line['tax'], $rates, $currency, $payload->pricesIncludeTax );
				if ( $built instanceof ConnectorError ) {
					return $built;
				}
				$shipping = $built;
				continue;
			}
			$sku     = (string) $line['sku'];
			$product = '' !== $sku ? ( $products[ strtolower( $sku ) ] ?? null ) : null;
			if ( null === $product && '' !== $sku && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return SageErrors::unknownProduct( $sku );
			}
			$rate = $this->rate( 'amount' === ( $line['kind'] ?? '' ) ? '' : $line['tax'], $rates, $index + 1 );
			if ( $rate instanceof ConnectorError ) {
				return $rate;
			}
			$text = '' !== (string) $line['name'] ? (string) $line['name'] : ( '' !== $sku ? $sku : __( 'Refund', 'estasoft-enterprise-erpcrm-sync-engine' ) );
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
			$lines[] = $this->line( $product, (string) $ledgers['sales'], $text, $qty, $unit, 0.0, $rate, $currency, $payload->pricesIncludeTax, $cut );
		}
		$note  = array(
			'contact_id'        => SagePayloadReader::text( $invoice['contact']['id'] ?? '' ),
			'date'              => '' !== $payload->date ? $payload->date : SagePayloadReader::day( $invoice['date'] ?? '' ),
			'reference'         => $payload->clientRef,
			'notes'             => SagePayloadReader::limit( 'Refund of ' . $payload->orderRef . ( '' !== $payload->reason ? ': ' . $payload->reason : '' ), 2000, $cut ),
			'credit_note_lines' => $lines,
		);
		$note += $shipping;
		if ( $cut ) {
			$warnings[] = __( 'A long description was shortened to fit Sage.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $note;
	}

	/**
	 * The tax codes a document uses, so the sender can look them up before anything is created.
	 *
	 * @param array<int,array<string,mixed>> $lines    Product lines.
	 * @param array<int,array<string,mixed>> $extras   Fee and discount lines.
	 * @param string|null                    $shipping Shipping tax code.
	 * @return array{codes:array<int,string>,none:bool} The distinct codes, and whether some line needs the no-tax rate.
	 */
	public static function taxCodesUsed( array $lines, array $extras, ?string $shipping ): array {
		$codes = array();
		$none  = false;
		foreach ( array_merge( $lines, $extras ) as $line ) {
			$tax = $line['tax'] ?? null;
			if ( null !== $tax && '' !== (string) $tax ) {
				$codes[] = (string) $tax;
			}
			$none = $none || '' === $tax;
		}
		if ( null !== $shipping && '' !== $shipping ) {
			$codes[] = $shipping;
		}
		return array(
			'codes' => array_values( array_unique( $codes ) ),
			'none'  => $none || '' === $shipping,
		);
	}

	/**
	 * The tax rate for a line's tax key. A line with no key at all cannot be sent: Sage needs a rate on every line.
	 *
	 * @param string|null                             $code   Tax key of the line.
	 * @param array<string,array{id:string,rate:int}> $rates  Tax rates by lower-case id.
	 * @param int                                     $number Line number for the message (0 for a fee or discount).
	 * @return array{id:string,rate:int}|ConnectorError
	 */
	private function rate( ?string $code, array $rates, int $number ): array|ConnectorError {
		if ( null === $code ) {
			return SageErrors::refused(
				'order_line[].tax',
				0 === $number
					? __( 'A fee or discount line has no tax code. Sage needs a tax rate on every line.', 'estasoft-enterprise-erpcrm-sync-engine' )
					: sprintf(
						/* translators: %d: line number. */
						__( 'Line %d has no tax code. Sage needs a tax rate on every line.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$number
					)
			);
		}
		$rate = $rates[ strtolower( $code ) ] ?? null;
		if ( null === $rate ) {
			return '' === $code
				? SageErrors::refused( 'no_tax_rate', __( 'Sage has no tax rate for a line without tax that this connector can find. Set the tax rate id in the connection option "no_tax_rate".', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				: SageErrors::unknownTax( '', $code );
		}
		return $rate;
	}

	/**
	 * One sales line.
	 *
	 * @param array<string,mixed>|null  $product     Sage product, or null for free text.
	 * @param string                    $ledgerId    Ledger account id.
	 * @param string                    $name        Description.
	 * @param string                    $quantity    Quantity as decimal text.
	 * @param int                       $unit        Unit price in minor units (negative for a discount); with tax in it when $includesTax.
	 * @param float                     $discount    Discount percent.
	 * @param array{id:string,rate:int} $rate        Tax rate.
	 * @param string                    $currency    Currency.
	 * @param bool                      $includesTax Whether the price includes tax.
	 * @param bool                      $cut         Set to true when a description was shortened.
	 * @return array<string,mixed>
	 */
	private function line( ?array $product, string $ledgerId, string $name, string $quantity, int $unit, float $discount, array $rate, string $currency, bool $includesTax, bool &$cut ): array {
		$gross = $this->amounts->lineTotal( $quantity, $unit );
		$off   = $this->amounts->percentOf( $gross, $discount );
		$line  = array(
			'description'       => SagePayloadReader::limit( $name, 500, $cut ),
			'ledger_account_id' => $ledgerId,
			'quantity'          => $quantity,
			'unit_price'        => $this->amounts->text( $unit, $currency ),
			'tax_rate_id'       => $rate['id'],
		);
		if ( null !== $product ) {
			$line['product_id'] = SagePayloadReader::text( $product['id'] ?? '' );
		}
		if ( $includesTax && $this->sageWorksTaxOut() ) {
			$line['unit_price_includes_tax'] = true;
			if ( 0 !== $off ) {
				$line['discount_amount'] = $this->amounts->text( $off, $currency );
			}
			return $line;
		}
		$net = $gross - $off;
		if ( $includesTax ) {
			$net                = $this->amounts->netFromGross( $net, $rate['rate'] );
			$converted          = $this->quantityAndUnit( $quantity, $net );
			$line['quantity']   = $converted[0];
			$line['unit_price'] = $this->amounts->text( $converted[1], $currency );
			if ( $converted[0] !== $quantity ) {
				$line['description'] = SagePayloadReader::limit( $name . ' (' . $quantity . ')', 500, $cut );
			}
		} elseif ( 0 !== $off ) {
			$line['discount_amount'] = $this->amounts->text( $off, $currency );
		}
		if ( 0 !== $rate['rate'] && ! $this->taxAmountForbidden() ) {
			$line['tax_amount'] = $this->amounts->text( $this->amounts->taxOn( $net, $rate['rate'] ), $currency );
		}
		return $line;
	}

	/**
	 * A quantity and a unit price whose product is exactly the net of a line: the same quantity when the net divides by it, else a quantity of 1.
	 *
	 * @param string $quantity Quantity as decimal text.
	 * @param int    $net      Net amount of the line in minor units.
	 * @return array{0:string,1:int}
	 */
	private function quantityAndUnit( string $quantity, int $net ): array {
		if ( 1 === preg_match( '/^[1-9]\d{0,6}$/', $quantity ) && 0 === $net % (int) $quantity ) {
			return array( $quantity, intdiv( $net, (int) $quantity ) );
		}
		return array( '1', $net );
	}

	/**
	 * The shipping fields of a document. The shipping has no "includes tax" flag, so an amount with tax in it is converted to net.
	 *
	 * @param int                                     $amount      Shipping amount in minor units.
	 * @param string|null                             $code        Tax code of the shipping, empty for none, null for not given.
	 * @param array<string,array{id:string,rate:int}> $rates       Tax rates by lower-case id.
	 * @param string                                  $currency    Currency.
	 * @param bool                                    $includesTax Whether the amount includes tax.
	 * @return array<string,string>|ConnectorError
	 */
	private function shipping( int $amount, ?string $code, array $rates, string $currency, bool $includesTax ): array|ConnectorError {
		$rate = $this->rate( $code ?? '', $rates, 0 );
		if ( $rate instanceof ConnectorError ) {
			return $rate;
		}
		$net    = $includesTax ? $this->amounts->netFromGross( $amount, $rate['rate'] ) : $amount;
		$fields = array(
			'shipping_net_amount'  => $this->amounts->text( $net, $currency ),
			'shipping_tax_rate_id' => $rate['id'],
		);
		if ( 0 !== $rate['rate'] && ! $this->taxAmountForbidden() ) {
			$fields['shipping_tax_amount'] = $this->amounts->text( $this->amounts->taxOn( $net, $rate['rate'] ), $currency );
		}
		return $fields;
	}

	/**
	 * Whether Sage works the tax out of a price that includes it: the UK, Ireland and France.
	 */
	private function sageWorksTaxOut(): bool {
		return in_array( $this->country, array( 'gb', 'ie', 'fr' ), true );
	}

	/**
	 * Whether Sage refuses a tax_amount: a Spanish business works it out itself [documented].
	 */
	private function taxAmountForbidden(): bool {
		return 'es' === $this->country;
	}

	/**
	 * The due date: the order date plus the connection's days.
	 *
	 * @param string $date Order date (YYYY-MM-DD).
	 */
	private function dueDate( string $date ): string {
		$days = $this->options->integer( 'due_days', 0, 0, 365 );
		if ( 0 === $days ) {
			return $date;
		}
		try {
			return ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return $date;
		}
	}
}
