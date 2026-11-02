<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ZohoBooks;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\ZohoBooks\ZohoBooksConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeSalesServer;
use Estasoft\SyncEngine\Tests\Support\FakeZohoBooks;
use Estasoft\SyncEngine\Tests\Support\SalesContractTestCase;

/**
 * The shared contract rules against the Zoho Books connector and a fake Zoho Books.
 */
final class ZohoBooksConnectorContractTest extends SalesContractTestCase {

	protected function makeServer(): FakeSalesServer {
		$server = new FakeZohoBooks();
		$server->addItem( 'CS-MUG-01', 'Ceramic mug, sand' );
		return $server;
	}

	protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector {
		return new ZohoBooksConnector( $deps );
	}

	protected function makeConnection( bool $working ): Connection {
		$expires = (string) ( (int) $this->kit->clock->now()->format( 'U' ) + 3600 );
		return $this->kit->connection(
			'zoho-books',
			array( 'data_centre' => 'com', 'client_id' => '1000.SYNTHETICCLIENT', 'organization_id' => FakeZohoBooks::ORG, 'organization_currency' => 'AED', 'options' => array( 'unknown_sku' => 'free_text' ) ),
			array(
				'client_secret'       => $working ? FakeZohoBooks::SECRET : 'wrong-client-secret-1003',
				'oauth_access_token'  => $working ? FakeZohoBooks::ACCESS : 'wrong-access-token-1004',
				'oauth_refresh_token' => $working ? FakeZohoBooks::REFRESH : 'wrong-refresh-token-1005',
				'oauth_expires_at'    => $expires,
			)
		);
	}

	protected function secretValues(): array {
		return array( FakeZohoBooks::ACCESS, FakeZohoBooks::REFRESH, FakeZohoBooks::FRESH, FakeZohoBooks::SECRET, 'wrong-client-secret-1003', 'wrong-access-token-1004', 'wrong-refresh-token-1005' );
	}
}
