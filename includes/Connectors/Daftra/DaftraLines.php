<?php
/**
 * Turns order and refund rows into Daftra invoice items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every row arrives as a total in minor units (after discounts, without tax) and a quantity. A line's unit price is that total divided by the
 * quantity. When the division is not exact the row becomes two lines (3 units for 10.00 are 2 at 3.33 and 1 at 3.34), so the sum of the lines
 * equals the total to the minor unit, with no discount line and no float. A quantity that is not a whole number becomes one line of quantity 1.
 *
 * Daftra taxes are either added to the price or included in it (`Tax.included`). When the tax of a row is included, the row is priced with the
 * tax in it (net plus tax, whole-number arithmetic) so Daftra's own figure comes out as the shop's; rounding Daftra does per line may differ by a
 * minor unit, which the total check allows once per order.
 */
final class DaftraLines {

	/**
	 * Largest tax difference, in minor units, between the tax a row carries and the tax a rate would give, for the rate to match.
	 */
	public const TAX_TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly DaftraAmounts $amounts ) {
	}

	/**
	 * Build the invoice items.
	 *
	 * @param array<int,array<string,mixed>>                                                    $rows      Rows: item (product id or empty), name, quantity, total_minor, tax_code, tax_amount.
	 * @param string                                                                            $currency  Currency code.
	 * @param bool                                                                              $inclusive True when the totals include tax.
	 * @param array<string,array{id:string,name:string,rate_bp:?int,included:bool,active:bool}> $taxes     Daftra taxes by id.
	 * @param string                                                                            $store     Warehouse id for every item, or empty.
	 * @return array{lines:array<int,array<string,mixed>>,net_minor:int}|ConnectorError
	 */
	public function build( array $rows, string $currency, bool $inclusive, array $taxes, string $store ): array|ConnectorError {
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
			if ( '' !== $code ) {
				if ( ! isset( $taxes[ $code ] ) ) {
					return $this->unmapped( $code );
				}
				$priced = $this->price( $total, $taxes[ $code ], $inclusive );
				if ( $priced instanceof ConnectorError ) {
					return $priced;
				}
				$total = $priced;
			}
			foreach ( $this->pieces( $total, (float) $row['quantity'], (string) $row['name'] ) as $piece ) {
				$line = array(
					'item'       => $piece['description'],
					'unit_price' => $this->amounts->number( $piece['unit'], $exponent ),
					'quantity'   => $piece['quantity'],
				);
				if ( '' !== (string) ( $row['item'] ?? '' ) ) {
					$line['product_id'] = (int) $row['item'];
				}
				if ( '' !== $code ) {
					$line['tax1'] = (int) $code;
				}
				if ( '' !== $store ) {
					$line['store_id'] = (int) $store;
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
	 * The tax whose amount on a net total equals the given tax within the tolerance (active taxes with a rate above zero only).
	 *
	 * @param int                                                                               $net   Net amount in minor units.
	 * @param int                                                                               $tax   Tax amount in minor units.
	 * @param array<string,array{id:string,name:string,rate_bp:?int,included:bool,active:bool}> $taxes Daftra taxes.
	 * @return string The tax id, or an empty string when none fits.
	 */
	public function rateFor( int $net, int $tax, array $taxes ): string {
		$best     = '';
		$bestDiff = self::TAX_TOLERANCE + 1;
		foreach ( $taxes as $id => $rate ) {
			if ( ! $rate['active'] || null === $rate['rate_bp'] || $rate['rate_bp'] <= 0 ) {
				continue;
			}
			$diff = abs( $this->amounts->taxOf( $net, $rate['rate_bp'] ) - $tax );
			if ( $diff < $bestDiff ) {
				$best     = (string) $id;
				$bestDiff = $diff;
			}
		}
		return $best;
	}

	/**
	 * The amount a row is priced at, given the tax Daftra applies to it.
	 *
	 * @param int                                                                 $total     The row's total in minor units.
	 * @param array{id:string,name:string,rate_bp:?int,included:bool,active:bool} $tax       The tax.
	 * @param bool                                                                $inclusive True when the total includes tax.
	 * @return int|ConnectorError
	 */
	private function price( int $total, array $tax, bool $inclusive ): int|ConnectorError {
		if ( $inclusive === $tax['included'] ) {
			return $total;
		}
		if ( null === $tax['rate_bp'] ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => DaftraConnector::LABEL,
					'field'  => 'tax1',
					'reason' => sprintf(
						/* translators: %s: tax name. */
						__( 'The rate of the tax "%s" is not known, so prices cannot be converted to the way Daftra treats it.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$tax['name']
					),
				),
				null,
				null,
				null,
				'rate unknown'
			);
		}
		return $inclusive ? $this->amounts->netFromGross( $total, $tax['rate_bp'] ) : $total + $this->amounts->taxOf( $total, $tax['rate_bp'] );
	}

	/**
	 * The lines one row becomes.
	 *
	 * @param int    $net         Total of the row in minor units.
	 * @param float  $quantity    Quantity.
	 * @param string $description Line text.
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
	 * SYNC-MAP-002 for a tax Daftra does not have.
	 *
	 * @param string $code Tax id or the name of the row.
	 */
	private function unmapped( string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => DaftraConnector::LABEL,
				'rate'    => $code,
				'system'  => DaftraConnector::LABEL,
			),
			null,
			null,
			null,
			'tax not found'
		);
	}
}
