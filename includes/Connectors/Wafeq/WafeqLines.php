<?php
/**
 * Turns order and refund rows into Wafeq line items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every row arrives as a total in minor units (after discounts) and a quantity. A line's unit price is that total divided by the quantity.
 * When the division is not exact the row becomes two lines (3 units for 10.00 are 2 at 3.33 and 1 at 3.34), so the sum of the lines equals the
 * total to the minor unit, with no discount line and no float. A quantity that is not a whole number becomes one line of quantity 1.
 */
final class WafeqLines {

	/**
	 * Largest tax difference, in minor units, between the tax a refund line carries and the tax a rate would give, for the rate to match.
	 */
	public const TAX_TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly WafeqAmounts $amounts ) {
	}

	/**
	 * Build the line items.
	 *
	 * @param array<int,array<string,mixed>>                                      $rows             Rows: name, quantity, total_minor, tax_code, tax_amount, item.
	 * @param string                                                              $currency         Currency code.
	 * @param bool                                                                $pricesIncludeTax True when the totals include tax.
	 * @param array<string,array{id:string,name:string,type:string,rate_bp:?int}> $rates            Wafeq tax rates by id.
	 * @param string                                                              $account          Account id for the lines.
	 * @return array{lines:array<int,array<string,mixed>>,net_minor:int}|ConnectorError
	 */
	public function build( array $rows, string $currency, bool $pricesIncludeTax, array $rates, string $account ): array|ConnectorError {
		$exponent = $this->amounts->exponent( $currency );
		$lines    = array();
		$sum      = 0;
		foreach ( $rows as $row ) {
			$total = (int) $row['total_minor'];
			$code  = (string) ( $row['tax_code'] ?? '' );
			if ( '' === $code && isset( $row['tax_amount'] ) && (int) $row['tax_amount'] > 0 ) {
				$derived = $this->rateFor( $total, (int) $row['tax_amount'], $rates );
				if ( '' === $derived ) {
					return $this->unmapped( (string) $row['name'] );
				}
				$code = $derived;
			}
			$bp = null;
			if ( '' !== $code ) {
				if ( ! isset( $rates[ $code ] ) ) {
					return $this->unmapped( $code );
				}
				$bp = $rates[ $code ]['rate_bp'];
				if ( $pricesIncludeTax ) {
					if ( null === $bp ) {
						return $this->unreadableRate( $rates[ $code ]['name'] );
					}
					$total = $this->amounts->netFromGross( $total, $bp );
				}
			}
			foreach ( $this->pieces( $total, (float) $row['quantity'], (string) $row['name'] ) as $piece ) {
				$line = array(
					'account'     => $account,
					'description' => $piece['description'],
					'quantity'    => $piece['quantity'],
					'unit_amount' => $this->amounts->number( $piece['unit'], $exponent ),
				);
				if ( '' !== $code ) {
					$line['tax_rate'] = $code;
				}
				if ( '' !== (string) ( $row['item'] ?? '' ) ) {
					$line['item'] = (string) $row['item'];
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
	 * The tax rate id whose tax on a net amount equals the given tax within the tolerance.
	 *
	 * @param int                                                                 $net   Net amount in minor units.
	 * @param int                                                                 $tax   Tax amount in minor units.
	 * @param array<string,array{id:string,name:string,type:string,rate_bp:?int}> $rates Wafeq tax rates.
	 * @return string The rate id, or an empty string when none fits.
	 */
	public function rateFor( int $net, int $tax, array $rates ): string {
		$best     = '';
		$bestDiff = self::TAX_TOLERANCE + 1;
		foreach ( $rates as $id => $rate ) {
			if ( null === $rate['rate_bp'] || $rate['rate_bp'] <= 0 ) {
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
	 * The lines one row becomes.
	 *
	 * @param int    $net         Net total of the row in minor units.
	 * @param float  $quantity    Quantity.
	 * @param string $description Line description.
	 * @return array<int,array{quantity:int|float,unit:int,description:string}>
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
	 * SYNC-MAP-002 for a tax code Wafeq does not have.
	 *
	 * @param string $code Code or the name of the row.
	 */
	private function unmapped( string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => WafeqConnector::LABEL,
				'rate'    => $code,
				'system'  => WafeqConnector::LABEL,
			),
			null,
			null,
			null,
			'tax not found'
		);
	}

	/**
	 * SYNC-VAL-002 for a tax whose rate Wafeq did not report.
	 *
	 * @param string $name Tax name.
	 */
	private function unreadableRate( string $name ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => WafeqConnector::LABEL,
				'field'  => 'tax_rate',
				'reason' => sprintf(
					/* translators: %s: tax name. */
					__( 'The rate of the tax "%s" is not known, so tax-inclusive prices cannot be converted.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$name
				),
			),
			null,
			null,
			null,
			'rate unknown'
		);
	}
}
