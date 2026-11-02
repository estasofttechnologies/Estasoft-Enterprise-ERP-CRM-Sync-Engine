<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\HubSpot;

use Estasoft\SyncEngine\Connectors\HubSpot\HubSpotConnector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeHubSpot;
use Estasoft\SyncEngine\Tests\Support\FakeSalesServer;
use Estasoft\SyncEngine\Tests\Support\SalesContractTestCase;

/**
 * The shared contract rules against the HubSpot connector and a fake HubSpot.
 */
final class HubSpotConnectorContractTest extends SalesContractTestCase {

	protected function makeServer(): FakeSalesServer {
		$server               = new FakeHubSpot();
		$server->currencies[] = 'KWD';
		$server->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		return $server;
	}

	protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector {
		return new HubSpotConnector( $deps );
	}

	protected function makeConnection( bool $working ): Connection {
		return $this->kit->connection( 'hubspot', array(), array( 'access_token' => $working ? FakeHubSpot::KEY : 'wrong-service-key-0002' ) );
	}

	protected function secretValues(): array {
		return array( FakeHubSpot::KEY, 'wrong-service-key-0002' );
	}
}
