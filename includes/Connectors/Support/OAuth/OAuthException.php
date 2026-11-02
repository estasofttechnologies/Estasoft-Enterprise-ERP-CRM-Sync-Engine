<?php
/**
 * A token could not be obtained or refreshed.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * An ApiException raised by the token manager. Its error is SYNC-AUTH-001, 002, 003, 004 or 005 for a refusal, or a retryable
 * SYNC-NET / SYNC-RATE code for a failure that may pass. The message never holds a token or a secret.
 */
final class OAuthException extends ApiException {
}
