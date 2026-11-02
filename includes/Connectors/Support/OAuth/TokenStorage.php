<?php
/**
 * Where tokens are kept.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The seam between the token manager and the connection store. Tokens live in the encrypted secrets of the connection under the reserved
 * keys oauth_access_token, oauth_refresh_token, oauth_expires_at and oauth_refresh_expires_at (epoch seconds).
 */
interface TokenStorage {

	/**
	 * Read a connection again with its current secrets.
	 *
	 * @param int $connectionId Connection.
	 */
	public function load( int $connectionId ): ?Connection;

	/**
	 * Save token secrets (merged into the existing secrets) and move the credential expiry.
	 *
	 * @param int                  $connectionId        Connection.
	 * @param array<string,string> $secrets             Secrets to set; an empty string removes one.
	 * @param int|null             $credentialExpiresAt Epoch seconds the sign-in runs out, or null to leave the date alone.
	 */
	public function save( int $connectionId, array $secrets, ?int $credentialExpiresAt = null ): void;

	/**
	 * A refresh was refused for good: hold the connection for a person (needs reconnect, or paused for SYNC-AUTH-001) and tell them.
	 *
	 * @param int       $connectionId Connection.
	 * @param ErrorCode $code         SYNC-AUTH-001, 002, 003 or 005.
	 * @param string    $system       System name.
	 */
	public function hold( int $connectionId, ErrorCode $code, string $system ): void;

	/**
	 * Active connections without secrets, for the scheduled refresh.
	 *
	 * @return array<int,Connection>
	 */
	public function activeConnections(): array;
}
