<?php
/**
 * Money in integer minor units.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts are integers in the currency's minor unit (fils, cents). No floats anywhere: conversion
 * from and to decimal strings uses string arithmetic only.
 */
final class Money {

	/**
	 * Currencies whose minor unit is not 1/100.
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
	 * Number of decimal places in the minor unit of a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
	}

	/**
	 * Parse a plain decimal string such as "438.00" or "12.5" into minor units.
	 *
	 * @param string $amount   Decimal string with an optional sign and "." as the separator.
	 * @param string $currency ISO 4217 code.
	 * @return int Minor units.
	 *
	 * @throws \InvalidArgumentException When the string is not a decimal number or has more places than the currency allows.
	 */
	public static function fromDecimalString( string $amount, string $currency ): int {
		$amount = trim( $amount );
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $parts ) ) {
			throw new \InvalidArgumentException( 'Not a decimal amount.' );
		}
		$exponent = self::exponent( $currency );
		$fraction = $parts[3] ?? '';
		if ( strlen( rtrim( $fraction, '0' ) ) > $exponent ) {
			throw new \InvalidArgumentException( 'Too many decimal places for the currency.' );
		}
		$fraction = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );
		$minor    = (int) ( $parts[2] . $fraction );
		return '-' === $parts[1] ? -$minor : $minor;
	}

	/**
	 * Format minor units as a plain decimal string ("438.00"). No thousands separator, no symbol.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 */
	public static function toDecimalString( int $minor, string $currency ): string {
		$exponent = self::exponent( $currency );
		$sign     = $minor < 0 ? '-' : '';
		$digits   = (string) abs( $minor );
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Move an amount between two exponents with integer arithmetic (rounds half away from zero when reducing).
	 *
	 * @param int $minor Amount.
	 * @param int $from  Current exponent.
	 * @param int $to    Wanted exponent.
	 */
	public static function rescale( int $minor, int $from, int $to ): int {
		if ( $from === $to ) {
			return $minor;
		}
		if ( $to > $from ) {
			return $minor * ( 10 ** ( $to - $from ) );
		}
		$divisor = 10 ** ( $from - $to );
		$half    = intdiv( $divisor, 2 );
		return $minor >= 0 ? intdiv( $minor + $half, $divisor ) : -intdiv( -$minor + $half, $divisor );
	}
}
