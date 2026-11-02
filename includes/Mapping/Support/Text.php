<?php
/**
 * Text helpers shared by the mapping transforms.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Transforms\Data\LatinFoldData;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions for emptiness, Unicode-aware trimming, case changes and accent-insensitive comparison.
 * Arabic text passes through untouched unless a function says otherwise.
 */
final class Text {

	/**
	 * Arabic-Indic and Extended Arabic-Indic digits mapped to ASCII digits.
	 */
	private const DIGITS = array(
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٫' => '.',
	);

	/**
	 * Arabic letter variants that people mix freely, reduced to one form for comparison only.
	 */
	private const ARABIC_FOLD = array(
		'أ' => 'ا',
		'إ' => 'ا',
		'آ' => 'ا',
		'ٱ' => 'ا',
		'ى' => 'ي',
		'ی' => 'ي',
		'ة' => 'ه',
		'ک' => 'ك',
	);

	/**
	 * Whether a value carries no information: null, an empty or blank string, an empty list, or a list of such values.
	 *
	 * @param mixed $value Any value.
	 */
	public static function isEmpty( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === self::trim( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::isEmpty( $item ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * A scalar as a string. Booleans become "true" or "false", floats lose trailing zeros, everything else becomes "".
	 *
	 * @param mixed $value Any value.
	 */
	public static function toString( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			$text = rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
			return '' === $text || '-' === $text ? '0' : $text;
		}
		return '';
	}

	/**
	 * Trim whitespace of any kind (including no-break and zero-width spaces) from both ends.
	 *
	 * @param string $text Text.
	 */
	public static function trim( string $text ): string {
		$trimmed = preg_replace( '/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $text );
		return is_string( $trimmed ) ? $trimmed : $text;
	}

	/**
	 * Replace every run of whitespace inside the text with one space.
	 *
	 * @param string $text Text.
	 */
	public static function collapseSpaces( string $text ): string {
		$collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $text );
		return is_string( $collapsed ) ? $collapsed : $text;
	}

	/**
	 * Upper case, Unicode aware when the mbstring extension exists.
	 *
	 * @param string $text Text.
	 */
	public static function upper( string $text ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}

	/**
	 * Lower case, Unicode aware when the mbstring extension exists.
	 *
	 * @param string $text Text.
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Title case: the first letter of each word upper case, the rest lower case.
	 *
	 * @param string $text Text.
	 */
	public static function title( string $text ): string {
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $text, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( strtolower( $text ) );
	}

	/**
	 * Digits written in Arabic-Indic forms become ASCII digits; the Arabic decimal separator becomes a dot.
	 *
	 * @param string $text Text.
	 */
	public static function asciiDigits( string $text ): string {
		return strtr( $text, self::DIGITS );
	}

	/**
	 * A comparison key: lower case, no accents, no Arabic marks, one form of each confusable Arabic letter,
	 * ASCII digits, punctuation replaced by single spaces. Never shown to a person or sent anywhere.
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		$key = self::lower( $text );
		$key = strtr( $key, LatinFoldData::MAP );
		$key = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}\p{Mn}]+/u', '', $key );
		$key = strtr( $key, self::ARABIC_FOLD );
		$key = self::asciiDigits( $key );
		$key = (string) preg_replace( '/[\'’‘ʻʼ`´]+/u', '', $key );
		$key = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $key );
		return self::trim( $key );
	}
}
