<?php
/**
 * The contract rules of the specification, run against the Acumatica connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Acumatica;

use Estasoft\SyncEngine\Connectors\Acumatica\AcumaticaConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeAcumaticaServer;

final class AcumaticaConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeAcumaticaServer $server;

	private ?Connection $working = null;

	protected function setUp(): void {
		parent::setUp();
		$this->server  = new FakeAcumaticaServer( $this->kit->http );
		$this->working = null;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new AcumaticaConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		if ( null === $this->working ) {
			$config        = array(
				'address'   => FakeAcumaticaServer::ADDRESS,
				'client_id' => FakeAcumaticaServer::CLIENT_ID,
			);
			$this->working = $this->kit->connection( 'acumatica', $config, $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ), 1 );
		}
		return $this->working;
	}

	protected function brokenConnection(): Connection {
		$config  = array(
			'address'   => FakeAcumaticaServer::ADDRESS,
			'client_id' => FakeAcumaticaServer::CLIENT_ID,
		);
		$secrets = array(
			'client_secret'       => 'acu-wrong-secret-00000000',
			'oauth_access_token'  => 'acu-stale-access-token',
			'oauth_refresh_token' => 'acu-stale-refresh-token',
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 600 ),
		);
		return $this->kit->connection( 'acumatica', $config, $secrets, 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$payload = array(
			'currency'   => (string) ( $options['currency'] ?? 'AED' ),
			'date_order' => '2026-11-02',
			'partner'    => array(
				'name'    => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email'   => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
				'street'  => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
				'city'    => 'Dubai',
				'country' => 'AE',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-BOWL-02',
					'quantity'   => $options['quantity'] ?? 1,
					'price_unit' => $options['unit_minor'] ?? 21900,
				),
			),
		);
		return array( $item, $payload );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( FakeAcumaticaServer::CLIENT_SECRET, 'acu-wrong-secret-00000000', 'acu-stale-access-token', 'acu-stale-refresh-token' );
	}
}
