<?php
/**
 * Token storage in memory, for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Support\OAuth\TokenStorage;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Keeps connections with their secrets in an array, records saves and holds, and lets a test change what a second worker would see.
 */
final class ApiMemoryTokenStorage implements TokenStorage {

	/**
	 * Rows by connection id.
	 *
	 * @var array<int,array{connector:string,type:ConnectorType,label:string,status:ConnectionStatus,config:array<string,mixed>,secrets:array<string,string>,credential_expires_at:?int}>
	 */
	public array $rows = array();

	/**
	 * Holds recorded, in order: [connection id, error code value].
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	public array $holds = array();

	/**
	 * Number of save() calls.
	 */
	public int $saves = 0;

	/**
	 * Called inside load() with the connection id, so a test can simulate another worker saving a token meanwhile.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $onLoad = null;

	/**
	 * Add a connection.
	 *
	 * @param int                  $id        Id.
	 * @param string               $connector Connector id.
	 * @param array<string,mixed>  $config    Config.
	 * @param array<string,string> $secrets   Secrets.
	 * @param ConnectionStatus     $status    Status.
	 * @param string               $label     Label.
	 */
	public function add( int $id, string $connector, array $config = array(), array $secrets = array(), ConnectionStatus $status = ConnectionStatus::Active, string $label = '' ): Connection {
		$this->rows[ $id ] = array(
			'connector'             => $connector,
			'type'                  => ConnectorType::Accounting,
			'label'                 => '' === $label ? $connector : $label,
			'status'                => $status,
			'config'                => $config,
			'secrets'               => $secrets,
			'credential_expires_at' => null,
		);
		return $this->build( $id, true );
	}

	/**
	 * Read a connection again.
	 *
	 * @param int $connectionId Connection.
	 */
	public function load( int $connectionId ): ?Connection {
		if ( null !== $this->onLoad ) {
			( $this->onLoad )( $connectionId, $this );
		}
		return isset( $this->rows[ $connectionId ] ) ? $this->build( $connectionId, true ) : null;
	}

	/**
	 * Save secrets.
	 *
	 * @param int                  $connectionId        Connection.
	 * @param array<string,string> $secrets             Secrets.
	 * @param int|null             $credentialExpiresAt Epoch seconds.
	 */
	public function save( int $connectionId, array $secrets, ?int $credentialExpiresAt = null ): void {
		++$this->saves;
		foreach ( $secrets as $name => $value ) {
			if ( '' === $value ) {
				unset( $this->rows[ $connectionId ]['secrets'][ $name ] );
			} else {
				$this->rows[ $connectionId ]['secrets'][ $name ] = $value;
			}
		}
		if ( null !== $credentialExpiresAt ) {
			$this->rows[ $connectionId ]['credential_expires_at'] = $credentialExpiresAt;
		}
	}

	/**
	 * Record a hold and change the status the way PauseController does.
	 *
	 * @param int       $connectionId Connection.
	 * @param ErrorCode $code         Code.
	 * @param string    $system       System.
	 */
	public function hold( int $connectionId, ErrorCode $code, string $system ): void {
		unset( $system );
		if ( ConnectionStatus::Active !== ( $this->rows[ $connectionId ]['status'] ?? null ) ) {
			return;
		}
		$this->holds[] = array( $connectionId, $code->value );
		$this->rows[ $connectionId ]['status'] = ErrorCode::Auth001 === $code ? ConnectionStatus::Paused : ConnectionStatus::NeedsReconnect;
	}

	/**
	 * Active connections without secrets.
	 *
	 * @return array<int,Connection>
	 */
	public function activeConnections(): array {
		$list = array();
		foreach ( array_keys( $this->rows ) as $id ) {
			if ( ConnectionStatus::Active === $this->rows[ $id ]['status'] ) {
				$list[] = $this->build( $id, false );
			}
		}
		return $list;
	}

	/**
	 * The status of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function status( int $connectionId ): ConnectionStatus {
		return $this->rows[ $connectionId ]['status'];
	}

	/**
	 * Build the domain object.
	 *
	 * @param int  $id          Id.
	 * @param bool $withSecrets Include secrets.
	 */
	private function build( int $id, bool $withSecrets ): Connection {
		$row = $this->rows[ $id ];
		return new Connection( $id, $row['connector'], $row['type'], $row['label'], $row['status'], $row['config'], array(), $withSecrets ? $row['secrets'] : array() );
	}
}
