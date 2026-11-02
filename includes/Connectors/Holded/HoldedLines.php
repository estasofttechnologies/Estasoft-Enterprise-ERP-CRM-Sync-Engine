<?php
/**
 * Turns order and refund rows into Holded document items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every row arrives as a total in minor units (after discounts, without tax) and a quantity. A line's unit price is that total divided by the
 * quantity. When the division is not exact the row becomes two lines (3 units for 10.00 are 2 at 3.33 and 1 at 3.34), so the sum of the lines
 * equals the total to the minor unit, with no discount line and no float. A quantity that is not a whole number becomes one line of quantity 1.
 *
 * The tax of a row is a percentage: the code typed in the tax mapping ("21"), or, for a row that carries only the tax amount, the percentage
 * from a short list of usual rates that explains that amount. Holded adds the percentage to the net price; a row whose total includes tax is
 * converted to net first.
 */
final class HoldedLines {

	/**
	 * Largest tax difference, in minor units, between the tax a row carries and the tax a rate would give, for the rate to match.
	 */
	public const TAX_TOLERANCE = 1;

	/**
	 * Rates (percent) tried, in this order, for a row that carries a tax amount and no code: whole numbers first, then halves, then the
	 * few other rates in use in the European Union.
	 */
	private const RATES = array(
		'21',
		'10',
		'4',
		'0',
		'20',
		'19',
		'7',
		'22',
		'23',
		'24',
		'25',
		'27',
		'5',
		'6',
		'8',
		'9',
		'12',
		'13',
		'14',
		'15',
		'16',
		'17',
		'18',
		'26',
		'28',
		'29',
		'30',
		'1',
		'2',
		'3',
		'11',
		'5.5',
		'2.1',
		'8.5',
		'1.1',
		'0.5',
		'1.5',
		'2.5',
		'3.5',
		'4.5',
		'6.5',
		'7.5',
		'9.5',
		'10.5',
		'12.5',
		'13.5',
		'14.5',
		'15.5',
		'16.5',
		'17.5',
		'18.5',
		'19.5',
		'20.5',
		'21.5',
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly HoldedAmounts $amounts ) {
	}

	/**
	 * Build the document items.
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows: sku (empty or the SKU to link), name, quantity, total_minor, tax_code, tax_amount.
	 * @param string                         $currency  Currency code.
	 * @param bool                           $inclusive True when the totals include tax.
	 * @return array{items:array<int,array<string,mixed>>,total_minor:int}|ConnectorError Total is net plus tax, with tax rounded per item.
	 */
	public function build( array $rows, string $currency, bool $inclusive ): array|ConnectorError {
		$exponent = $this->amounts->exponent( $currency );
		$items    = array();
		$total    = 0;
		foreach ( $rows as $row ) {
			$net  = (int) $row['total_minor'];
			$code = trim( (string) ( $row['tax_code'] ?? '' ) );
			$bp   = 0;
			if ( '' === $code && isset( $row['tax_amount'] ) && (int) $row['tax_amount'] > 0 ) {
				$code = $this->rateFor( $net, (int) $row['tax_amount'] );
				if ( '' === $code ) {
					return $this->unmapped( (string) $row['name'] );
				}
			}
			if ( '' !== $code ) {
				$parsed = $this->amounts->basisPoints( rtrim( $code, '% ' ) );
				if ( null === $parsed || $parsed < 0 || $parsed > 10000 ) {
					return $this->unmapped( $code );
				}
				$bp = $parsed;
				if ( $inclusive ) {
					$net = $this->amounts->netFromGross( $net, $bp );
				}
			}
			foreach ( $this->pieces( $net, (float) $row['quantity'], (string) $row['name'] ) as $piece ) {
				$item = array(
					'name'     => HoldedPayloadReader::shorten( $piece['description'], 200 ),
					'units'    => $piece['quantity'],
					'subtotal' => $this->amounts->number( $piece['unit'], $exponent ),
					'tax'      => 0 === $bp % 100 ? intdiv( $bp, 100 ) : $bp / 100,
				);
				if ( '' !== (string) ( $row['sku'] ?? '' ) ) {
					$item['sku'] = (string) $row['sku'];
				}
				$items[] = $item;
				$line    = (int) round( $piece['quantity'] ) * $piece['unit'];
				$total  += $line + $this->amounts->taxOf( $line, $bp );
			}
		}
		return array(
			'items'       => $items,
			'total_minor' => $total,
		);
	}

	/**
	 * The usual rate whose tax on a net amount equals the given tax within the tolerance.
	 *
	 * @param int $net Net amount in minor units.
	 * @param int $tax Tax amount in minor units.
	 * @return string The rate in percent, or an empty string when none fits.
	 */
	public function rateFor( int $net, int $tax ): string {
		$best     = '';
		$bestDiff = self::TAX_TOLERANCE + 1;
		foreach ( self::RATES as $rate ) {
			$bp   = (int) $this->amounts->basisPoints( $rate );
			$diff = abs( $this->amounts->taxOf( $net, $bp ) - $tax );
			if ( $diff < $bestDiff ) {
				$best     = $rate;
				$bestDiff = $diff;
				if ( 0 === $diff ) {
					break;
				}
			}
		}
		return $best;
	}

	/**
	 * The lines one row becomes.
	 *
	 * @param int    $net         Net total of the row in minor units.
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
	 * SYNC-MAP-002 for a tax rate that is not a percentage.
	 *
	 * @param string $code Tax code or the name of the row.
	 */
	private function unmapped( string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => HoldedConnector::LABEL,
				'rate'    => $code,
				'system'  => HoldedConnector::LABEL,
			),
			null,
			null,
			null,
			'tax not a percentage'
		);
	}
}
