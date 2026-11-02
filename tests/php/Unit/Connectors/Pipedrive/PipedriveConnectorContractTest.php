<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Pipedrive;

use Estasoft\SyncEngine\Connectors\Pipedrive\PipedriveConnector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakePipedrive;
use Estasoft\SyncEngine\Tests\Support\FakeSalesServer;
use Estasoft\SyncEngine\Tests\Support\SalesContractTestCase;

/**
 * The shared contract rules against the Pipedrive connector and a fake Pipedrive.
 */
final class PipedriveConnectorContractTest extends SalesContractTestCase {

	protected function makeServer(): FakeSalesServer {
		$server               = new FakePipedrive();
		$server->currencies[] = 'KWD';
		$server->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		return $server;
	}

	protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector {
		return new PipedriveConnector( $deps );
	}

	protected function makeConnection( bool $working ): Connection {
		return $this->kit->connection( 'pipedrive', array( 'company_domain' => 'example' ), array( 'api_token' => $working ? FakePipedrive::KEY : 'ffffffffffffffffffffffffffffffffffffffff' ) );
	}

	protected function secretValues(): array {
		return array( FakePipedrive::KEY, 'ffffffffffffffffffffffffffffffffffffffff' );
	}
}
