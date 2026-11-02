<?php
/**
 * Small text helpers shared by the sales-system connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Length limits and comparisons that keep Arabic and other scripts intact.
 */
final class Text {

	/**
	 * Cut text to a number of characters, ending with an ellipsis. Returns the text and whether it was cut.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters (not bytes).
	 * @return array{0:string,1:bool}
	 */
	public static function limit( string $text, int $max ): array {
		if ( $max < 2 || self::length( $text ) <= $max ) {
			return array( $text, false );
		}
		return array( rtrim( self::slice( $text, $max - 1 ) ) . "\u{2026}", true );
	}

	/**
	 * Case-insensitive equality of two email addresses or names.
	 *
	 * @param string $left  One text.
	 * @param string $right Another text.
	 */
	public static function same( string $left, string $right ): bool {
		return self::lower( trim( $left ) ) === self::lower( trim( $right ) );
	}

	/**
	 * Lower-case in UTF-8.
	 *
	 * @param string $text Text.
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * The first characters of a text.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters to keep.
	 */
	public static function slice( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}

	/**
	 * Escape a value for a quoted literal in a query language that uses backslashes (SOQL, Zoho criteria).
	 *
	 * @param string $text Text.
	 */
	public static function escapeQuoted( string $text ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $text );
	}
}
