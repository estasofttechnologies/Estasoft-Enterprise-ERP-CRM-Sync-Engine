<?php
/**
 * An inbound message was refused.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Inbound;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;

/**
 * Carries the HTTP status to answer with. The message never says which check failed beyond what the sender needs.
 */
final class InboundRefused extends JobException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string    $message    Short sentence, already escaped with `esc_html()` like every `JobException` message.
	 * @param int       $httpStatus 401 for a bad signature or time, 404 for an unknown or disabled connection, 409 for a replay, 413 for a body that is too large, 429 for too many requests.
	 * @param ErrorCode $code       The catalog code (SYNC-INB-001).
	 */
	public function __construct( string $message, int $httpStatus, ErrorCode $code = ErrorCode::Inb001 ) {
		parent::__construct( $message, $httpStatus, $code, array() );
	}
}
