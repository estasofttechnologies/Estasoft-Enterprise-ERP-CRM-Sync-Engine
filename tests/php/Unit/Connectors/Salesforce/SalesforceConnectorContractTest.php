<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Salesforce;

use Estasoft\SyncEngine\Connectors\Salesforce\SalesforceConnector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeSalesforce;
use Estasoft\SyncEngine\Tests\Support\FakeSalesServer;
use Estasoft\SyncEngine\Tests\Support\SalesContractTestCase;

/**
 * The shared contract rules against the Salesforce connector and a fake Salesforce.
 */
final class SalesforceConnectorContractTest extends SalesContractTestCase {

	protected function makeServer(): FakeSalesServer {
		$server                      = new FakeSalesforce();
		$server->multiCurrency       = true;
		$server->opportunityFields[] = SalesforceConnector::REFERENCE_FIELD; // A fully set up org: the merchant has added the reference field.
		$server->uniqueFields[]      = SalesforceConnector::REFERENCE_FIELD;
		return $server;
	}

	protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector {
		return new SalesforceConnector( $deps );
	}

	protected function makeConnection( bool $working ): Connection {
		$expires = (string) ( (int) $this->kit->clock->now()->format( 'U' ) + 3600 );
		return $this->kit->connection(
			'salesforce',
			array( 'domain' => 'example.my.salesforce.com', 'client_id' => '3MVG9SyntheticConsumerKey' ),
			array(
				'client_secret'       => $working ? FakeSalesforce::SECRET : 'wrong-consumer-secret-0002',
				'oauth_access_token'  => $working ? FakeSalesforce::ACCESS : 'wrong-access-token-0003',
				'oauth_refresh_token' => $working ? FakeSalesforce::REFRESH : 'wrong-refresh-token-0004',
				'oauth_expires_at'    => $expires,
			)
		);
	}

	protected function secretValues(): array {
		return array( FakeSalesforce::ACCESS, FakeSalesforce::REFRESH, FakeSalesforce::FRESH, FakeSalesforce::SECRET, 'wrong-consumer-secret-0002', 'wrong-access-token-0003', 'wrong-refresh-token-0004' );
	}
}
