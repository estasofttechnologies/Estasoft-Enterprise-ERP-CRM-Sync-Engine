<?php
/**
 * Connections held in an array.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Mapping\Ports\ConnectionSource;

final class ArrayConnections implements ConnectionSource {

	/**
	 * @var array<int,Connection>
	 */
	private array $connections = array();

	public function __construct() {
		$this->connections[1] = self::connection( 1 );
	}

	public static function connection( int $id = 1, string $connector = 'fake' ): Connection {
		return new Connection( $id, $connector, ConnectorType::Erp, 'Fake system, Cedar & Stone', ConnectionStatus::Active );
	}

	public function find( int $connectionId ): ?Connection {
		return $this->connections[ $connectionId ] ?? null;
	}
}
