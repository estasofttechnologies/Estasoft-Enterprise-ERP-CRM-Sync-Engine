<?php
/**
 * Sends e-mail.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * One call: a plain-text body with a simple HTML alternative. Implemented over `wp_mail`; no external service is used.
 */
interface Mailer {

	/**
	 * Send a message.
	 *
	 * @param array<int,string> $to      Recipients (already validated).
	 * @param string            $subject One line.
	 * @param string            $text    Plain-text body.
	 * @param string            $html    HTML body, built with escaping.
	 * @return bool Whether the mail system accepted it.
	 */
	public function send( array $to, string $subject, string $text, string $html ): bool;
}
