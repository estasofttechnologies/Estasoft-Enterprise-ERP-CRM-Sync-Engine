<?php
/**
 * Money conversion at the edge of the Xero connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Inside the plugin money is an integer in minor units. Xero reads and writes decimal numbers, so conversion happens here and only here,
 * with the currency's exponent (KWD, BHD and OMR have three places). Nothing is added or compared as a float.
 */
final class XeroAmounts {

	/**
	 * Most that two totals may differ and still count as the same (one minor unit of rounding).
	 */
	public const TOLERANCE = 1;

	/**
	 * A minor-unit amount as the number Xero expects. The text goes through Money so the digits are exact; the cast only turns it into a JSON number.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO currency code.
	 */
	public function number( int $minor, string $currency ): float {
		return (float) Money::toDecimalString( $minor, '' === $currency ? 'USD' : $currency );
	}

	/**
	 * A quantity text as the number Xero expects.
	 *
	 * @param string $quantity Decimal text.
	 */
	public function quantity( string $quantity ): float {
		return (float) $quantity;
	}

	/**
	 * Minor units from a number Xero returned.
	 *
	 * @param mixed  $value    Number or numeric text.
	 * @param string $currency ISO currency code.
	 * @return int|null Null when the value is not numeric.
	 */
	public function fromNumber( mixed $value, string $currency ): ?int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return null;
		}
		$exponent = Money::exponent( '' === $currency ? 'USD' : $currency );
		try {
			return Money::fromDecimalString( number_format( (float) $value, $exponent, '.', '' ), '' === $currency ? 'USD' : $currency );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return null;
		}
	}

	/**
	 * Whether two totals agree within the tolerance.
	 *
	 * @param int $expected Total the shop expects.
	 * @param int $actual   Total the system worked out.
	 */
	public function agree( int $expected, int $actual ): bool {
		return abs( $expected - $actual ) <= self::TOLERANCE;
	}

	/**
	 * A total with its currency for a message, such as "120.50 AED".
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO currency code.
	 */
	public function show( int $minor, string $currency ): string {
		return trim( Money::toDecimalString( $minor, '' === $currency ? 'USD' : $currency ) . ' ' . $currency );
	}

	/**
	 * The line total (quantity times unit price) in minor units, rounded half away from zero, with whole-number arithmetic.
	 *
	 * @param string $quantity  Decimal text with at most six places.
	 * @param int    $unitMinor Unit price in minor units.
	 */
	public function lineTotal( string $quantity, int $unitMinor ): int {
		$parts  = explode( '.', $quantity . '.' );
		$scaled = (int) ( $parts[0] . str_pad( substr( $parts[1], 0, 6 ), 6, '0' ) );
		$gross  = $scaled * $unitMinor;
		$half   = 500000;
		return $gross >= 0 ? intdiv( $gross + $half, 1000000 ) : -intdiv( -$gross + $half, 1000000 );
	}
}
