<?php
/**
 * Text that has to fit a field.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * Cuts text to a field's length with an ellipsis. Arabic and other scripts are cut on characters, never inside one. A reference field is
 * never passed through here: it must not be shortened.
 */
final class Text {

	/**
	 * Cut a text to at most `$max` characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result, ellipsis included.
	 * @return array{0:string,1:bool} The text and whether it was cut.
	 */
	public static function fit( string $text, int $max ): array {
		$text = trim( $text );
		if ( $max < 2 || mb_strlen( $text ) <= $max ) {
			return array( $text, false );
		}
		return array( rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…', true );
	}

	/**
	 * The text cut to fit; the first element of fit().
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result.
	 */
	public static function cut( string $text, int $max ): string {
		return self::fit( $text, $max )[0];
	}
	/**
	 * Cut every listed field of a body to its limit.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param array<string,int>   $limits Longest text by field name.
	 * @return array{0:array<string,mixed>,1:array<int,string>} The body and the names of the fields that were cut.
	 */
	public static function fitFields( array $body, array $limits ): array {
		$cut = array();
		foreach ( $limits as $field => $max ) {
			if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
				continue;
			}
			list( $body[ $field ], $was ) = self::fit( $body[ $field ], $max );
			if ( $was ) {
				$cut[] = $field;
			}
		}
		return array( $body, $cut );
	}
}
