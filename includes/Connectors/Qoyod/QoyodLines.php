<?php
/**
 * Turns order and refund rows into Qoyod line items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every row arrives as a total in minor units (after discounts) and a quantity. A line's unit price is that total divided by the quantity.
 * When the division is not exact the row becomes two lines (3 units for 10.00 are 2 at 3.33 and 1 at 3.34), so the sum of the lines equals the
 * total to the minor unit, with no discount line and no float. A quantity that is not a whole number becomes one line of quantity 1.
 * Qoyod lines name a product, so every row must carry a product id. Tax inclusive prices are not converted: the line says
 * `is_inclusive` and Qoyod does the arithmetic.
 */
final class QoyodLines {

	/**
	 * Largest tax difference, in minor units, between the tax a line carries and the tax a rate would give, for the rate to match.
	 */
	public const TAX_TOLERANCE = 1;

	/**
	 * Qoyod's standard tax code for VAT 0 percent [third-party]. A line that names no tax gets the default tax of its product in Qoyod, which
	 * would add tax the shop never charged, so a row always carries a code; a row the shop gave none is refused unless the merchant chose
	 * this code or the exempt code for such rows.
	 */
	public const ZERO_RATED = '2';

	/**
	 * Qoyod's standard code for tax-exempt sales [third-party].
	 */
	public const EXEMPT = '3';

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly QoyodAmounts $amounts ) {
	}

	/**
	 * Build the line items.
	 *
	 * @param array<int,array<string,mixed>>                   $rows      Rows: item (product id), name, quantity, total_minor, tax_code, tax_amount.
	 * @param string                                           $currency  Currency code.
	 * @param bool                                             $inclusive True when the totals include tax.
	 * @param string                                           $taxField  tax_id or tax_percent.
	 * @param string                                           $branch    Branch id for every line.
	 * @param array<int|string,array{name:string,rate_bp:int}> $taxes    Known tax codes with their rates.
	 * @param string                                           $untaxed  Code for a row that has no tax code and no tax amount, or an empty string to refuse such a row.
	 * @return array{lines:array<int,array<string,mixed>>,net_minor:int}|ConnectorError
	 */
	public function build( array $rows, string $currency, bool $inclusive, string $taxField, string $branch, array $taxes, string $untaxed = '' ): array|ConnectorError {
		$exponent = $this->amounts->exponent( $currency );
		$lines    = array();
		$sum      = 0;
		foreach ( $rows as $row ) {
			$total = (int) $row['total_minor'];
			$code  = (string) ( $row['tax_code'] ?? '' );
			if ( '' === $code && isset( $row['tax_amount'] ) && (int) $row['tax_amount'] > 0 ) {
				$code = $this->rateFor( $total, (int) $row['tax_amount'], $taxes );
				if ( '' === $code ) {
					return $this->unmapped( (string) $row['name'] );
				}
			}
			if ( '' === $code ) {
				if ( '' === $untaxed ) {
					return $this->untaxed( (string) $row['name'] );
				}
				$code = $untaxed;
			}
			$percent = null;
			if ( 'tax_percent' === $taxField ) {
				if ( ! isset( $taxes[ $code ] ) ) {
					return $this->unmapped( $code );
				}
				$percent = $this->amounts->decimalString( $taxes[ $code ]['rate_bp'], 2 );
			}
			foreach ( $this->pieces( $total, (float) $row['quantity'], (string) $row['name'] ) as $piece ) {
				$line = array(
					'product_id'   => (int) $row['item'],
					'description'  => $piece['description'],
					'unit_price'   => $this->amounts->decimalString( $piece['unit'], $exponent ),
					'quantity'     => $piece['quantity'],
					'is_inclusive' => $inclusive,
					'inventory_id' => (int) $branch,
				);
				if ( null !== $percent ) {
					$line['tax_percent'] = $percent;
				} else {
					$line['tax_id'] = $code;
				}
				$lines[] = $line;
				$sum    += (int) round( $piece['quantity'] ) * $piece['unit'];
			}
		}
		return array(
			'lines'     => $lines,
			'net_minor' => $sum,
		);
	}

	/**
	 * The tax code whose tax on a net amount equals the given tax within the tolerance (rates above zero only).
	 *
	 * @param int                                              $net   Net amount in minor units.
	 * @param int                                              $tax   Tax amount in minor units.
	 * @param array<int|string,array{name:string,rate_bp:int}> $taxes Known tax codes.
	 * @return string The code, or an empty string when none fits.
	 */
	public function rateFor( int $net, int $tax, array $taxes ): string {
		$best     = '';
		$bestDiff = self::TAX_TOLERANCE + 1;
		foreach ( $taxes as $code => $rate ) {
			if ( $rate['rate_bp'] <= 0 ) {
				continue;
			}
			$diff = abs( $this->amounts->taxOf( $net, $rate['rate_bp'] ) - $tax );
			if ( $diff < $bestDiff ) {
				$best     = (string) $code;
				$bestDiff = $diff;
			}
		}
		return $best;
	}

	/**
	 * The lines one row becomes.
	 *
	 * @param int    $net         Total of the row in minor units.
	 * @param float  $quantity    Quantity.
	 * @param string $description Line description.
	 * @return array<int,array{quantity:int,unit:int,description:string}>
	 */
	private function pieces( int $net, float $quantity, string $description ): array {
		$whole = (int) $quantity;
		if ( $quantity <= 0.0 || (float) $whole !== $quantity ) {
			$text = rtrim( rtrim( number_format( $quantity, 4, '.', '' ), '0' ), '.' );
			return array(
				array(
					'quantity'    => 1,
					'unit'        => $net,
					'description' => '' !== $text && $quantity > 0.0 ? sprintf( '%s (× %s)', $description, $text ) : $description,
				),
			);
		}
		$sign = $net < 0 ? -1 : 1;
		$abs  = abs( $net );
		$unit = intdiv( $abs, $whole );
		$rest = $abs - $unit * $whole;
		if ( 0 === $rest ) {
			return array(
				array(
					'quantity'    => $whole,
					'unit'        => $sign * $unit,
					'description' => $description,
				),
			);
		}
		return array(
			array(
				'quantity'    => $whole - $rest,
				'unit'        => $sign * $unit,
				'description' => $description,
			),
			array(
				'quantity'    => $rest,
				'unit'        => $sign * ( $unit + 1 ),
				'description' => $description,
			),
		);
	}

	/**
	 * SYNC-VAL-002 for a row that has no tax and no instruction what to do with such rows.
	 *
	 * @param string $name Name of the row.
	 */
	private function untaxed( string $name ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => 'lines[].tax_code',
				'reason' => sprintf(
					/* translators: %s: name of the line. */
					__( 'The line "%s" has no tax code. Map its tax under Tax mapping, or choose in the connection settings whether lines without tax are zero-rated (code 2) or exempt (code 3).', 'estasoft-erp-crm-sync-engine' ),
					$name
				),
			),
			null,
			null,
			null,
			'untaxed row'
		);
	}

	/**
	 * SYNC-MAP-002 for a tax code the connector does not know.
	 *
	 * @param string $code Code or the name of the row.
	 */
	private function unmapped( string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => QoyodConnector::LABEL,
				'rate'    => $code,
				'system'  => QoyodConnector::LABEL,
			),
			null,
			null,
			null,
			'tax not found'
		);
	}
}
