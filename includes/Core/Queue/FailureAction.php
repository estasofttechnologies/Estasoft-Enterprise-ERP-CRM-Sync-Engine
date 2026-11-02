<?php
/**
 * What the queue does with a failed attempt.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

enum FailureAction: string {

	/**
	 * Try again later.
	 */
	case Retry = 'retry';

	/**
	 * Mark the item Failed; a person must act.
	 */
	case Fail = 'fail';

	/**
	 * The sign-in was refused: pause the connection and leave the item Queued (SYNC-AUTH-001).
	 */
	case PauseSignIn = 'pause_sign_in';

	/**
	 * The sign-in expired or was not completed: the connection needs reconnecting and the item waits.
	 */
	case NeedsReconnect = 'needs_reconnect';
}
