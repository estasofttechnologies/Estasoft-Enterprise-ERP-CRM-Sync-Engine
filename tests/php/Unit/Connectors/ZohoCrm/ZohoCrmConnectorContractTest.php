<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ZohoCrm;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\ZohoCrm\ZohoCrmConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeSalesServer;
use Estasoft\SyncEngine\Tests\Support\FakeZohoCrm;
use Estasoft\SyncEngine\Tests\Support\SalesContractTestCase;

/**
 * The shared contract rules against the Zoho CRM connector and a fake Zoho CRM.
 */
final class ZohoCrmConnectorContractTest extends SalesContractTestCase {

	protected function makeServer(): FakeSalesServer {
		$server                = new FakeZohoCrm();
		$server->multiCurrency = true;
		return $server;
	}

	protected function makeConnector( ConnectorDependencies $deps ): AbstractApiConnector {
		return new ZohoCrmConnector( $deps );
	}

	protected function makeConnection( bool $working ): Connection {
		$expires = (string) ( (int) $this->kit->clock->now()->format( 'U' ) + 3600 );
		return $this->kit->connection(
			'zoho-crm',
			array( 'data_centre' => 'com', 'client_id' => '1000.SYNTHETICCLIENT', 'org_currency' => 'AED' ),
			array(
				'client_secret'       => $working ? FakeZohoCrm::SECRET : 'wrong-client-secret-0003',
				'oauth_access_token'  => $working ? FakeZohoCrm::ACCESS : 'wrong-access-token-0004',
				'oauth_refresh_token' => $working ? FakeZohoCrm::REFRESH : 'wrong-refresh-token-0005',
				'oauth_expires_at'    => $expires,
			)
		);
	}

	protected function secretValues(): array {
		return array( FakeZohoCrm::ACCESS, FakeZohoCrm::REFRESH, FakeZohoCrm::FRESH, FakeZohoCrm::SECRET, 'wrong-client-secret-0003', 'wrong-access-token-0004', 'wrong-refresh-token-0005' );
	}
}
