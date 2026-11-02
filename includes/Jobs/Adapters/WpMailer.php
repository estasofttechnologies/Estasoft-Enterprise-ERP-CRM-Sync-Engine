<?php
/**
 * Sends e-mail with wp_mail.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\Mailer;

/**
 * Sends the HTML body and puts the plain-text version in the message as its alternative part (through the `phpmailer_init`
 * action, for this one message only). Subject and addresses lose line breaks first, so nothing can add a header.
 */
final class WpMailer implements Mailer {

	/**
	 * Send a message.
	 *
	 * @param array<int,string> $to      Recipients.
	 * @param string            $subject One line.
	 * @param string            $text    Plain-text body.
	 * @param string            $html    HTML body.
	 * @return bool Whether wp_mail accepted it.
	 */
	public function send( array $to, string $subject, string $text, string $html ): bool {
		$recipients = array();
		foreach ( $to as $address ) {
			$clean = trim( (string) preg_replace( '/[\r\n]+/', '', (string) $address ) );
			if ( '' !== $clean && false !== is_email( $clean ) ) {
				$recipients[] = $clean;
			}
		}
		if ( array() === $recipients ) {
			return false;
		}
		$subject = trim( (string) preg_replace( '/[\r\n]+/', ' ', $subject ) );
		$alt     = static function ( $mailer ) use ( $text ): void {
			if ( is_object( $mailer ) && property_exists( $mailer, 'AltBody' ) ) {
				$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $recipients, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $sent;
	}
}
