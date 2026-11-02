<?php
/**
 * The connector contract, checked against the fake pod.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Connectors\OracleFusion\OracleFusionConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOracleFusionServer;

final class OracleFusionConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake pod.
	 */
	private FakeOracleFusionServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeOracleFusionServer( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new OracleFusionConnector( $this->kit->deps );
	}

	private function connectionWith( string $secret ): Connection {
		return $this->kit->connection(
			'oracle-fusion',
			array(
				'address'            => $this->server->address,
				'auth_method'        => 'oauth',
				'identity_url'       => $this->server->identity,
				'scope'              => $this->server->scope,
				'client_id'          => $this->server->clientId,
				'business_unit'      => $this->server->businessUnit,
				'transaction_source' => $this->server->source,
				'transaction_type'   => $this->server->type,
				'bill_to_account'    => '1001',
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
		return array( $this->server->clientSecret, 'this-secret-is-wrong-0000', $this->server->token, $this->server->password );
	}

	/**
	 * Oracle Fusion does not take customers (all web orders are billed to one customer account), so the base test, which sends a buyer and
	 * looks that buyer up, has nothing to find. This version checks the honest behaviour: the buyer is not sent and a lookup says there is none.
	 */
	public function test_arabic_text_comes_back_exactly_as_it_went_in(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		list( $item, $payload ) = $this->orderFixture( '9600' );
		$payload['partner']                  = array( 'name' => 'نور المنصوري', 'email' => 'noor.ar@example.test' );
		$payload['order_line'][0]['name']    = 'كوب سيراميك';
		$payload['note']                     = 'الرجاء التغليف كهدية';
		$sent = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertStringNotContainsString( 'noor.ar@example.test', $this->kit->http->transcript(), 'The buyer stays in WooCommerce.' );
		$this->assertNull( $connector->lookupCustomer( $connection, 'noor.ar@example.test' ) );
		$invoice = array_values( $this->server->all( 'receivablesInvoices' ) )[0];
		$this->assertStringContainsString( 'الرجاء التغليف كهدية', $invoice['Comments'], 'The note keeps its Arabic text, byte for byte.' );
	}
}
