<?php
/**
 * UTF-8 helpers that need no extension.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Text coming from WooCommerce is UTF-8 but may hold stray bytes, control characters or very long names. These helpers make it
 * safe for XML and CSV files. They work on code points, so Arabic, German and Nordic text is never split in the middle of a letter.
 */
final class Utf8 {

	/**
	 * The strict UTF-8 byte patterns of RFC 3629.
	 */
	private const SEQUENCE = '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/';

	/**
	 * Keep only valid UTF-8 sequences.
	 *
	 * @param string $text Any bytes.
	 */
	public static function valid( string $text ): string {
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}
		preg_match_all( self::SEQUENCE, $text, $found );
		return implode( '', $found[0] );
	}

	/**
	 * One line of safe text: invalid bytes, control characters and characters XML 1.0 forbids are removed, every run of white
	 * space (including line breaks and no-break spaces) becomes one space, and the ends are trimmed.
	 *
	 * @param string $text Text.
	 */
	public static function line( string $text ): string {
		$text  = self::valid( $text );
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		$clean = null === $clean ? '' : $clean;
		$clean = preg_replace( '/[\s\x{A0}\x{2028}\x{2029}]+/u', ' ', $clean );
		return trim( null === $clean ? '' : $clean );
	}

	/**
	 * The characters of a text, one string each.
	 *
	 * @param string $text Valid UTF-8.
	 * @return array<int,string>
	 */
	public static function chars( string $text ): array {
		$parts = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return false === $parts ? array() : $parts;
	}

	/**
	 * Number of characters.
	 *
	 * @param string $text Valid UTF-8.
	 */
	public static function length( string $text ): int {
		if ( 1 === preg_match( '/^[\x00-\x7F]*$/', $text ) ) {
			return strlen( $text );
		}
		return count( self::chars( $text ) );
	}

	/**
	 * Shorten a text to a number of characters. A text that is too long ends with the ellipsis (three dots by default) inside the
	 * limit, so the person can see it was cut.
	 *
	 * @param string $text     Valid UTF-8.
	 * @param int    $max      Largest number of characters.
	 * @param string $ellipsis What marks a cut.
	 */
	public static function cut( string $text, int $max, string $ellipsis = '...' ): string {
		if ( self::length( $text ) <= $max ) {
			return $text;
		}
		$keep = max( 0, $max - self::length( $ellipsis ) );
		return rtrim( implode( '', array_slice( self::chars( $text ), 0, $keep ) ) ) . $ellipsis;
	}

	/**
	 * The code point of one character.
	 *
	 * @param string $char One UTF-8 character.
	 */
	public static function codePoint( string $char ): int {
		$bytes = strlen( $char );
		$first = ord( $char[0] );
		if ( 1 === $bytes ) {
			return $first;
		}
		if ( 2 === $bytes ) {
			return ( ( $first & 0x1F ) << 6 ) | ( ord( $char[1] ) & 0x3F );
		}
		if ( 3 === $bytes ) {
			return ( ( $first & 0x0F ) << 12 ) | ( ( ord( $char[1] ) & 0x3F ) << 6 ) | ( ord( $char[2] ) & 0x3F );
		}
		return ( ( $first & 0x07 ) << 18 ) | ( ( ord( $char[1] ) & 0x3F ) << 12 ) | ( ( ord( $char[2] ) & 0x3F ) << 6 ) | ( ord( $char[3] ) & 0x3F );
	}
}
