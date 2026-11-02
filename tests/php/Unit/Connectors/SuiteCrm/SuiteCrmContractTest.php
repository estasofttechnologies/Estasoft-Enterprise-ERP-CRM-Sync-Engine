<?php
/**
 * The connector contract and the rules of the shared API base, checked against the SuiteCRM connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SuiteCrm;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\SuiteCrm\SuiteCrmConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSuiteCrmServer;

/**
 * A client credentials connector: the refused key is a refused client secret at the token endpoint. The order is sent in the neutral shape
 * every CRM connector receives.
 */
final class SuiteCrmContractTest extends ApiConnectorContractTestCase {

	private const SECRET = FakeSuiteCrmServer::CLIENT_SECRET;

	/**
	 * The fake SuiteCRM.
	 *
	 * @var FakeSuiteCrmServer
	 */
	private FakeSuiteCrmServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeSuiteCrmServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->server->add( 'Currencies', array( 'iso4217' => 'AED', 'status' => 'Active', 'name' => 'Dirham' ) );
		$this->server->add( 'Currencies', array( 'iso4217' => 'KWD', 'status' => 'Active', 'name' => 'Dinar' ) );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new SuiteCrmConnector( $this->kit->deps );
	}

	/**
	 * A connection to the fake server with the given client secret.
	 *
	 * @param string $secret Client secret.
	 */
	private function connectionWith( string $secret ): Connection {
		return $this->kit->connection(
			'suitecrm',
			array(
				'address'   => 'https://' . FakeSuiteCrmServer::HOST,
				'version'   => 'v8',
				'grant'     => 'client_credentials',
				'client_id' => FakeSuiteCrmServer::CLIENT_ID,
				'options'   => array( 'base_currency' => 'EUR' ),
			),
			array( 'client_secret' => $secret )
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( self::SECRET );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'wrong-secret-0000' );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item     = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'k-' . $orderId, ItemState::Syncing );
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$name     = (string) ( $options['name'] ?? 'Layla Haddad' );
		$space    = mb_strrpos( $name, ' ' );
		return array(
			$item,
			array(
				'currency' => $options['currency'] ?? 'EUR',
				'date'     => '2026-10-30',
				'paid_at'  => '2026-10-30 09:16:00',
				'total'    => $quantity * $unit,
				'contact'  => array(
					'email'      => $options['email'] ?? 'layla@example.test',
					'first_name' => false === $space ? '' : mb_substr( $name, 0, $space ),
					'last_name'  => false === $space ? $name : mb_substr( $name, $space + 1 ),
					'street'     => $options['street'] ?? 'Keizersgracht 1',
					'country'    => 'NL',
				),
				'lines'    => array( array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => (string) $quantity, 'unit_price' => $unit, 'total' => $quantity * $unit, 'tax' => 0 ) ),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		switch ( $kind ) {
			case 'rate_limit':
				$this->server->fail( 'POST', '#/V8/module\?#', FakeHttpClient::tooManyRequests( $seconds ) );
				break;
			case 'timeout':
				$this->server->fail( 'POST', '#/V8/module\?#', FakeHttpClient::timeout() );
				break;
			case 'server_error':
				$this->server->fail( 'POST', '#/V8/module\?#', FakeHttpClient::status( 500, 'Internal error' ) );
				break;
			case 'unknown_fault':
				$this->server->fail( 'POST', '#/V8/module\?#', $this->server->error( 400, 'Bad Request', 'Something odd happened' ) );
				break;
			case 'certificate':
				$this->server->fail( 'POST', '#/V8/module\?#', FakeHttpClient::certificateError() );
				break;
			case 'auth':
				$this->server->fail( '*', '#access_token#', new HttpResponse( 401, '{"error":"invalid_client"}', array( 'content-type' => 'application/json' ) ) );
				break;
		}
	}

	protected function secretValues(): array {
		return array( self::SECRET, 'wrong-secret-0000' );
	}
}
