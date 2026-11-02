<?php
/**
 * Money and date conversion at the edge of the Odoo connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * The engine keeps money as integer minor units. Odoo sends and expects decimal numbers. Conversion happens here and
 * nowhere else, with string arithmetic, so no total is ever decided by a float comparison.
 */
final class OdooAmounts {

	/**
	 * Currencies whose minor unit is not 1/100 (the same table as the engine's own money helper).
	 *
	 * @var array<string,int>
	 */
	private const EXPONENTS = array(
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JOD' => 3,
		'TND' => 3,
		'LYD' => 3,
		'IQD' => 3,
		'JPY' => 0,
		'KRW' => 0,
		'VND' => 0,
		'CLP' => 0,
		'ISK' => 0,
		'UGX' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'KMF' => 0,
		'GNF' => 0,
		'DJF' => 0,
		'BIF' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
	);

	/**
	 * Decimal places of a currency's minor unit.
	 *
	 * @param string $currency ISO 4217 code; empty gives 2.
	 */
	public function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( trim( $currency ) ) ] ?? 2;
	}

	/**
	 * Odoo's decimal amount to integer minor units (rounds half away from zero at the currency's precision).
	 *
	 * @param mixed $amount   Number or numeric text from Odoo.
	 * @param int   $exponent Decimal places of the currency.
	 */
	public function toMinor( mixed $amount, int $exponent ): int {
		if ( is_int( $amount ) ) {
			return $amount * ( 10 ** $exponent );
		}
		if ( is_string( $amount ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', trim( $amount ) ) ) {
			$amount = (float) trim( $amount );
		}
		if ( ! is_float( $amount ) ) {
			return 0;
		}
		// number_format() rounds at the requested precision, which removes binary noise such as 437.9499999.
		$text     = number_format( $amount, $exponent, '.', '' );
		$negative = str_starts_with( $text, '-' );
		$digits   = str_replace( array( '-', '.' ), '', $text );
		$minor    = (int) $digits;
		return $negative ? -$minor : $minor;
	}

	/**
	 * Integer minor units to the decimal number Odoo expects.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function toDecimal( int $minor, int $exponent ): float {
		return (float) $this->toDecimalString( $minor, $exponent );
	}

	/**
	 * Integer minor units to a plain decimal string such as "219.00".
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function toDecimalString( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * A UTC date text in any common form to the "Y-m-d H:i:s" form Odoo stores.
	 *
	 * @param string $value ISO 8601 or MySQL date text, read as UTC when it has no zone.
	 * @return string Empty when the text is not a date.
	 */
	public function toOdooDatetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			return '';
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Odoo's "Y-m-d H:i:s" UTC text to an ISO 8601 UTC string.
	 *
	 * @param mixed $value Text from Odoo; false or empty gives an empty string.
	 */
	public function fromOdooDatetime( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			return '';
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}
}
