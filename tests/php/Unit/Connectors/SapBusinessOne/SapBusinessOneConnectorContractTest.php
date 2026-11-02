<?php
/**
 * The connector contract, checked against the fake Service Layer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Connectors\SapBusinessOne\SapBusinessOneConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\ArraySessionStore;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSapB1Server;

final class SapBusinessOneConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Service Layer.
	 */
	private FakeSapB1Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeSapB1Server( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new SapBusinessOneConnector( $this->kit->deps, new ArraySessionStore() );
	}

	private function connectionWith( string $password ): Connection {
		return new Connection(
			1,
			'sap-b1',
			ConnectorType::Erp,
			'SAP Business One',
			ConnectionStatus::Active,
			array(
				'address'     => $this->server->address,
				'database'    => $this->server->database,
				'user'        => $this->server->user,
				'api_version' => 'v2',
			),
			array(),
			array( 'password' => $password )
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( $this->server->password );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'this-password-is-wrong-0000' );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item     = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$payload  = array(
			'currency'   => (string) ( $options['currency'] ?? 'AED' ),
			'order_date' => '2026-11-02 07:34:00',
			'total'      => $quantity * $unit,
			'partner'    => array(
				'name'    => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email'   => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
				'street'  => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
				'city'    => 'Dubai',
				'country' => 'AE',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'quantity'   => $quantity,
					'price_unit' => $unit,
				),
			),
		);
		return array( $item, $payload );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$error = static fn( int $status, string $text ): \Estasoft\SyncEngine\Contract\HttpResponse => FakeHttpClient::json( array( 'error' => array( 'code' => -1, 'message' => array( 'lang' => 'en-us', 'value' => $text ) ) ), $status );
		match ( $kind ) {
			'rate_limit'   => $this->server->failNext( FakeHttpClient::tooManyRequests( $seconds ) ),
			'timeout'      => $this->server->failNext( FakeHttpClient::timeout() ),
			'server_error' => $this->server->failNext( FakeHttpClient::status( 500, 'Internal Server Error with ' . $this->server->password ) ),
			'certificate'  => $this->server->failNext( FakeHttpClient::certificateError() ),
			'auth'         => $this->server->failNext( $error( 401, 'Invalid session.' ), 2 ),
			default        => $this->server->failNext( FakeHttpClient::status( 502, 'Bad gateway ' . $this->server->password . ' ' . $this->server->database ) ),
		};
	}

	protected function secretValues(): array {
		return array( $this->server->password, 'this-password-is-wrong-0000' );
	}
}
