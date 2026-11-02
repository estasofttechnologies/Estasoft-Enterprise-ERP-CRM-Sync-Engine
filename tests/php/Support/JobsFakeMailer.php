<?php
/**
 * A mailer that keeps what it was asked to send.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\Mailer;

final class JobsFakeMailer implements Mailer {

	/**
	 * Messages sent.
	 *
	 * @var array<int,array{to:array<int,string>,subject:string,text:string,html:string}>
	 */
	public array $sent = array();

	/**
	 * Whether the mail system accepts messages.
	 */
	public bool $accepts = true;

	/**
	 * Send.
	 *
	 * @param array<int,string> $to      Recipients.
	 * @param string            $subject Subject.
	 * @param string            $text    Plain text.
	 * @param string            $html    HTML.
	 */
	public function send( array $to, string $subject, string $text, string $html ): bool {
		$this->sent[] = array(
			'to'      => $to,
			'subject' => $subject,
			'text'    => $text,
			'html'    => $html,
		);
		return $this->accepts;
	}
}
