<?php
/**
 * Plain text from messages that were escaped where they were thrown.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Exception messages are escaped with `esc_html()` at the `throw` (WordPress.org plugin review asks for it), so a message
 * that is shown as plain text (a JSON answer, a log line, a WP-CLI line) has to be turned back into text first. HTML output
 * keeps the escaped form as it is.
 */
final class PlainText {

	/**
	 * Undo `esc_html()` for output that is not HTML.
	 *
	 * @param string $escaped A message produced by `esc_html()`.
	 */
	public static function fromEscaped( string $escaped ): string {
		return wp_specialchars_decode( $escaped, ENT_QUOTES );
	}
}
