<?php
/**
 * Fitting text into a field of a limited length.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Systems cap the length of descriptions and notes. A long note is cut with an ellipsis in one documented way; the reference that makes
 * a retry safe is never cut (a reference that does not fit is refused by the connector instead).
 */
final class Text {

	/**
	 * Cut a text to a number of characters, with an ellipsis when something was removed.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result in characters (at least 2).
	 */
	public static function fit( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, max( 1, $max - 1 ) ) ) . '…';
	}

	/**
	 * Whether fit() would cut a text.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest allowed length.
	 */
	public static function exceeds( string $text, int $max ): bool {
		return mb_strlen( trim( $text ) ) > $max;
	}
}
