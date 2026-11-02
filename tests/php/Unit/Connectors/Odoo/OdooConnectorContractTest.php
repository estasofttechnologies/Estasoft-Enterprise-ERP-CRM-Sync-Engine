<?php
/**
 * The connector contract, checked against Odoo 18 over JSON-RPC.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\OdooConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

class OdooConnectorContractTest extends ConnectorContractTestCase {

	/**
	 * The fake Odoo.
	 *
	 * @var FakeOdooServer
	 */
	protected FakeOdooServer $server;

	/**
	 * The connector.
	 *
	 * @var OdooConnector
	 */
	private OdooConnector $odoo;

	/**
	 * Odoo major version this class tests.
	 */
	protected function major(): int {
		return 18;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeOdooServer( $this->major() );
		$this->server->db->addTax( 'VAT 5% (AE)', 5.0 );
		$this->server->db->addProduct( 'CS-BOWL-02', 'Stoneware bowl set', 219.0, array( 1 => 14.0 ) );
		$this->server->db->addProduct( 'CS-MUG-01', 'Ceramic mug, sand', 42.0, array( 1 => 42.0 ) );
		$this->server->db->insert( 'product.pricelist', array( 'name' => 'KWD list', 'currency_id' => 3, 'active' => true ) );
		$this->odoo = new OdooConnector( $this->server, static fn(): bool => false, static fn(): int => 1_800_000_000, static function (): void {}, static fn(): float => 0.0 );
	}

	protected function connectorUnderTest(): ConnectorInterface {
		return $this->odoo;
	}

	/**
	 * A connection to the fake.
	 *
	 * @param string $key API key.
	 */
	private function connectionWith( string $key ): Connection {
		return new Connection(
			1,
			'odoo',
			ConnectorType::Erp,
			'Odoo',
			ConnectionStatus::Active,
			array(
				'address'  => $this->server->baseUrl(),
				'database' => $this->server->database,
				'user'     => $this->server->login,
				'options'  => array( 'call_interval_ms' => 0 ),
			),
			array(),
			array( 'api_key' => $key )
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( $this->server->apiKey );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'this-key-is-wrong-0000' );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$payload = array(
			'currency'   => (string) ( $options['currency'] ?? 'AED' ),
			'partner'    => array(
				'name'       => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email'      => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
				'street'     => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
				'city'       => 'Dubai',
				'country_id' => 'AE',
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
		$response = match ( $kind ) {
			'rate_limit'    => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'       => FakeHttpClient::timeout(),
			'server_error'  => FakeHttpClient::status( 500, 'Internal Server Error, request was authorised with ' . $this->server->apiKey ),
			'certificate'   => FakeHttpClient::certificateError(),
			'auth'          => FakeHttpClient::status( 401, 'Unauthorized' ),
			default         => $this->major() >= 19
				? FakeHttpClient::json( array( 'name' => 'builtins.RuntimeError', 'message' => 'boom ' . $this->server->apiKey, 'arguments' => array( 'boom' ), 'context' => array(), 'debug' => '' ), 500 )
				: FakeHttpClient::json( array( 'jsonrpc' => '2.0', 'id' => 1, 'error' => array( 'code' => 200, 'message' => 'Odoo Server Error', 'data' => array( 'name' => 'builtins.RuntimeError', 'message' => 'boom ' . $this->server->apiKey, 'arguments' => array( 'boom' ), 'context' => array(), 'debug' => '' ) ) ) ),
		};
		$this->server->failNext( $response, 'create' );
	}

	protected function secretValues(): array {
		return array( $this->server->apiKey, 'this-key-is-wrong-0000' );
	}

	protected function requestTimeouts(): array {
		return array_column( $this->server->requests, 'timeout' );
	}
}
