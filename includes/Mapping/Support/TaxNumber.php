<?php
/**
 * One written form for a buyer's tax number.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

defined( 'ABSPATH' ) || exit;

/**
 * People type the same number as "300 1234 5678 9003", "٣٠٠١٢٣٤٥٦٧٨٩٠٠٣" or "300-123456789-003". The reader stores the plain form and the
 * validator checks the plain form, so the number that was validated is the number that is sent.
 */
final class TaxNumber {

	/**
	 * Marks and separators that carry no meaning in a tax number: any kind of space, dashes, dots, slashes, underscores, the Arabic
	 * thousands separator, zero-width characters and the direction marks that appear when a number is pasted from right-to-left text.
	 */
	private const IGNORED = '/[\s\p{Zs}\-\x{2010}-\x{2015}\x{2212}.\/_\x{066C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{0640}]+/u';

	/**
	 * The plain form: ASCII digits, upper-case letters, no separators.
	 *
	 * @param string $value Number as typed.
	 */
	public static function normalise( string $value ): string {
		$text = Text::asciiDigits( $value );
		$text = (string) preg_replace( self::IGNORED, '', $text );
		return strtoupper( $text );
	}
}
