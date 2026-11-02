<?php
/**
 * The Business Central connector against the connector contract and the rules of the shared API base.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Connectors\BusinessCentral\BusinessCentralConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer;

final class BusinessCentralContractTest extends ApiConnectorContractTestCase {

	private FakeBusinessCentralServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeBusinessCentralServer( $this->kit->http );
		$this->server->addItem( 'CS-MUG-01', array( 'displayName' => 'Mug' ) );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new BusinessCentralConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection(
			'business-central',
			array(
				'tenant_id'   => FakeBusinessCentralServer::TENANT,
				'client_id'   => FakeBusinessCentralServer::CLIENT_ID,
				'environment' => FakeBusinessCentralServer::ENVIRONMENT,
			),
			array( 'client_secret' => FakeBusinessCentralServer::SECRET )
		);
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection(
			'business-central',
			array(
				'tenant_id'   => FakeBusinessCentralServer::TENANT,
				'client_id'   => FakeBusinessCentralServer::CLIENT_ID,
				'environment' => FakeBusinessCentralServer::ENVIRONMENT,
			),
			array( 'client_secret' => 'wrong-client-secret-value' ),
			2
		);
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
				'name'       => (string) ( $options['name'] ?? 'Noor Al-Mansouri' ),
				'email'      => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
				'street'     => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
				'city'       => 'Dubai',
				'country_id' => 'AE',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Mug',
					'quantity'   => $quantity,
					'price_unit' => $unit,
					'tax'        => 'ZERO',
				),
			),
		);
		return array( $item, $payload );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->retryAfter = $seconds;
		if ( 'auth' === $kind ) {
			// The secret was rotated since the token was issued: the API refuses the token and Microsoft refuses the secret.
			$this->server->failNextWrite = 'auth';
			$this->server->entra->revokeAll();
			$this->server->entra->failNext = 'invalid_secret';
			return;
		}
		$this->server->failNextWrite = $kind;
	}

	protected function secretValues(): array {
		return array( FakeBusinessCentralServer::SECRET, 'wrong-client-secret-value' );
	}
}
