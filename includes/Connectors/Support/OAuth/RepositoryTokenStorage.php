<?php
/**
 * Token storage over the connection repository.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Secrets are written through ConnectionRepository::update(), which encrypts them; a refused sign-in is held through PauseController, which
 * logs the code and fires the existing notification hook (`estasoft_sync_connection_paused` with reason `sign_in`).
 */
final class RepositoryTokenStorage implements TokenStorage {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param PauseController      $pauses      Pauses and reconnect holds.
	 */
	public function __construct( private readonly ConnectionRepository $connections, private readonly PauseController $pauses ) {
	}

	/**
	 * Read a connection again with its current secrets.
	 *
	 * @param int $connectionId Connection.
	 */
	public function load( int $connectionId ): ?Connection {
		return $this->connections->find( $connectionId );
	}

	/**
	 * Save token secrets and move the credential expiry.
	 *
	 * @param int                  $connectionId        Connection.
	 * @param array<string,string> $secrets             Secrets; an empty string removes one.
	 * @param int|null             $credentialExpiresAt Epoch seconds, or null to leave the date alone.
	 */
	public function save( int $connectionId, array $secrets, ?int $credentialExpiresAt = null ): void {
		$changes = array( 'secrets' => array() );
		foreach ( $secrets as $name => $value ) {
			$changes['secrets'][ $name ] = '' === $value ? null : $value;
		}
		if ( null !== $credentialExpiresAt ) {
			$changes['credential_expires_at'] = gmdate( 'Y-m-d H:i:s', $credentialExpiresAt );
		}
		$this->connections->update( $connectionId, $changes );
	}

	/**
	 * Hold the connection for a person.
	 *
	 * @param int       $connectionId Connection.
	 * @param ErrorCode $code         SYNC-AUTH-001, 002, 003 or 005.
	 * @param string    $system       System name.
	 */
	public function hold( int $connectionId, ErrorCode $code, string $system ): void {
		if ( ErrorCode::Auth001 === $code ) {
			$this->pauses->pauseForRefusedSignIn( $connectionId, $system );
			return;
		}
		$this->pauses->markNeedsReconnect( $connectionId, $code, $system );
	}

	/**
	 * Active connections without secrets.
	 *
	 * @return array<int,Connection>
	 */
	public function activeConnections(): array {
		return array_values(
			array_filter(
				$this->connections->allWithoutSecrets(),
				static fn( Connection $connection ): bool => ConnectionStatus::Active === $connection->status
			)
		);
	}
}
