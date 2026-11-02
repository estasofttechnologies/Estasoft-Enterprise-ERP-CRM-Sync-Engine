<?php
/**
 * The connector contract, checked against the fake tenant.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Connectors\SapS4Hana\SapS4HanaConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSapS4Server;

final class SapS4HanaConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake tenant.
	 */
	private FakeSapS4Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeSapS4Server( $this->kit->http );
		$this->server->products['CS-MUG-01'] = '42.00';
	}

	protected function apiConnector(): AbstractApiConnector {
		return new SapS4HanaConnector( $this->kit->deps );
	}

	private function connectionWith( string $secret ): Connection {
		return $this->kit->connection(
			'sap-s4hana',
			array(
				'address'              => $this->server->address,
				'auth_method'          => 'oauth',
				'token_url'            => $this->server->tokenUrl,
				'scope'                => $this->server->scope,
				'client_id'            => $this->server->clientId,
				'sales_organization'   => '1710',
				'distribution_channel' => '10',
				'division'             => '00',
				'sold_to_party'        => '17100001',
			),
			array( 'client_secret' => $secret )
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( $this->server->clientSecret );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'this-secret-is-wrong-0000' );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item     = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$payload  = array(
			'currency'   => (string) ( $options['currency'] ?? 'AED' ),
			'order_date' => '2026-11-02 07:34:00',
			'total'      => $quantity * $unit,
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
		if ( 'auth' === $kind ) {
			$this->server->rotateToken();
			$this->server->tokenFailures = 1;
			return;
		}
		match ( $kind ) {
			'rate_limit'   => $this->server->failNext( FakeHttpClient::tooManyRequests( $seconds ) ),
			'timeout'      => $this->server->failNext( FakeHttpClient::timeout() ),
			'server_error' => $this->server->failNext( FakeHttpClient::status( 500, 'Internal Server Error with ' . $this->server->clientSecret ) ),
			'certificate'  => $this->server->failNext( FakeHttpClient::certificateError() ),
			default        => $this->server->failNext( FakeHttpClient::status( 502, 'Bad gateway ' . $this->server->clientSecret . ' ' . $this->server->token ) ),
		};
	}

	protected function secretValues(): array {
		return array( $this->server->clientSecret, 'this-secret-is-wrong-0000', $this->server->token, $this->server->csrf );
	}

	/**
	 * S/4HANA does not sync customers (all web orders are sold to one customer number), so the base test, which sends a buyer and
	 * looks that buyer up, has nothing to find. This version checks the honest behaviour: Arabic text in the order never breaks the
	 * send, the buyer is not sent, and a customer lookup says there is none.
	 */
	public function test_arabic_text_comes_back_exactly_as_it_went_in(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		list( $item, $payload ) = $this->orderFixture( '9600', array( 'name' => 'نور المنصوري', 'email' => 'noor.ar@example.test', 'street' => 'شارع الشيخ زايد، برج ٢٣' ) );
		$payload['partner'] = array( 'name' => 'نور المنصوري', 'email' => 'noor.ar@example.test' );
		$sent               = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertStringNotContainsString( 'noor.ar@example.test', $this->kit->http->transcript(), 'The buyer stays in WooCommerce.' );
		$this->assertNull( $connector->lookupCustomer( $connection, 'noor.ar@example.test' ) );
	}

	protected function totalMinorOf( ExternalRef $ref ): ?int {
		return isset( $ref->extra['net_minor'] ) ? (int) $ref->extra['net_minor'] : null;
	}
}
