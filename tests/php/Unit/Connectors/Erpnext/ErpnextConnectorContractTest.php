<?php
/**
 * The contract rules of the specification, run against the ERPNext connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Connectors\Erpnext\ErpnextConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeErpnextServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ErpnextConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeErpnextServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeErpnextServer( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new ErpnextConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'erpnext', array( 'address' => FakeErpnextServer::ADDRESS ), array( 'api_key' => FakeErpnextServer::KEY, 'api_secret' => FakeErpnextServer::SECRET ), 1 );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'erpnext', array( 'address' => FakeErpnextServer::ADDRESS ), array( 'api_key' => FakeErpnextServer::KEY, 'api_secret' => 'wrong-secret-0000' ), 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
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
		$secret   = FakeErpnextServer::SECRET;
		$response = match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 500, 'Internal Server Error, token ' . FakeErpnextServer::KEY . ':' . $secret ),
			'certificate'  => FakeHttpClient::certificateError(),
			'auth'         => FakeHttpClient::json( array( 'exc_type' => 'AuthenticationError' ), 401 ),
			default        => FakeHttpClient::json( array( 'exc_type' => 'RuntimeError', 'exception' => 'boom ' . $secret ), 500 ),
		};
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', $response );
	}

	protected function secretValues(): array {
		return array( FakeErpnextServer::KEY, FakeErpnextServer::SECRET, 'wrong-secret-0000' );
	}
}
