<?php
/**
 * The connector contract, checked against the fake NetSuite.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Connectors\NetSuite\NetSuiteConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeNetSuiteServer;

final class NetSuiteConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake NetSuite.
	 */
	private FakeNetSuiteServer $server;

	/**
	 * Private key the merchant pastes.
	 */
	private string $privateKey = '';

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeNetSuiteServer( $this->kit->http );
		$key          = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		openssl_pkey_export( $key, $this->privateKey );
		$this->server->publicKey = (string) openssl_pkey_get_details( $key )['key'];
	}

	protected function apiConnector(): AbstractApiConnector {
		return new NetSuiteConnector( $this->kit->deps );
	}

	private function connectionWith( string $clientId ): Connection {
		return $this->kit->connection(
			'netsuite',
			array(
				'account_id'     => $this->server->account,
				'certificate_id' => $this->server->certificateId,
				'algorithm'      => 'ES256',
				'client_id'      => $clientId,
			),
			array( 'private_key' => $this->privateKey )
		);
	}

	protected function workingConnection(): Connection {
		return $this->connectionWith( $this->server->clientId );
	}

	protected function brokenConnection(): Connection {
		return $this->connectionWith( 'this-client-id-is-wrong-0000' );
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
					'product'    => $this->itemIdOf( 'CS-MUG-01' ),
					'quantity'   => $quantity,
					'price_unit' => $unit,
				),
			),
		);
		return array( $item, $payload );
	}

	private function itemIdOf( string $name ): string {
		foreach ( $this->server->items as $item ) {
			if ( $item['itemid'] === $name ) {
				return $item['id'];
			}
		}
		return '';
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		if ( 'auth' === $kind ) {
			$this->refuseSignIn();
			return;
		}
		match ( $kind ) {
			'rate_limit'   => $this->server->failNext( FakeHttpClient::tooManyRequests( $seconds ) ),
			'timeout'      => $this->server->failNext( FakeHttpClient::timeout() ),
			'server_error' => $this->server->failNext( FakeHttpClient::status( 500, 'Internal Server Error with ' . $this->server->token ) ),
			'certificate'  => $this->server->failNext( FakeHttpClient::certificateError() ),
			default        => $this->server->failNext(
				FakeHttpClient::json(
					array(
						'status'         => 500,
						'o:errorDetails' => array(
							array(
								'detail'      => 'Unexpected boom ' . $this->server->token . ' ' . $this->privateKey,
								'o:errorCode' => 'UNEXPECTED_ERROR',
							),
						),
					),
					500
				)
			),
		};
	}

	/**
	 * The access token a client holds stops working and NetSuite refuses the new assertion.
	 */
	private function refuseSignIn(): void {
		$this->server->rotateToken();
		$this->server->tokenFailures = 1;
	}

	protected function secretValues(): array {
		return array( $this->server->token, $this->privateKey );
	}
}
