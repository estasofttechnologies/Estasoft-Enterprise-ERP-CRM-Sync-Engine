<?php
/**
 * The contract tests for a CRM or accounting connector built on the shared base, run against a fake vendor server.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;

abstract class SalesContractTestCase extends ApiConnectorContractTestCase {

	protected FakeSalesServer $server;

	/**
	 * A fake vendor server holding what the fixtures need.
	 */
	abstract protected function makeServer(): FakeSalesServer;

	/**
	 * The connector built on the shared dependencies.
	 *
	 * @param ConnectorDependencies $deps Dependencies.
	 */
	abstract protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector;

	/**
	 * A connection; with a wrong secret when $working is false.
	 *
	 * @param bool $working Whether the secret is right.
	 */
	abstract protected function makeConnection( bool $working ): Connection;

	protected function setUp(): void {
		parent::setUp();
		$this->server = $this->makeServer();
		$this->server->install( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return $this->makeConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->makeConnection( true );
	}

	protected function brokenConnection(): Connection {
		return $this->makeConnection( false );
	}

	/**
	 * @param string              $orderId Order id.
	 * @param array<string,mixed> $options Fixture options.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	protected function orderFixture( string $orderId, array $options = array() ): array {
		return SalesFixtures::order( $orderId, $options );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}
}
