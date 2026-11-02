<?php
/**
 * The shared connector contract, run against the Holded connector and a fake Holded.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Connectors\Holded\HoldedConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeHoldedServer;

final class HoldedConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Holded.
	 */
	private FakeHoldedServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeHoldedServer();
		$this->server->install( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new HoldedConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'holded', array(), array( 'api_key' => FakeHoldedServer::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'holded', array(), array( 'api_key' => 'hld-wrong-key-0123456789' ), 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$item     = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', 'k' . $orderId ), ItemState::Syncing );
		return array(
			$item,
			array(
				'date'     => '2026-11-02',
				'currency' => (string) ( $options['currency'] ?? 'EUR' ),
				'number'   => $orderId,
				'total'    => $quantity * $unit,
				'customer' => array(
					'name'    => (string) ( $options['name'] ?? 'Noor Almansoori' ),
					'email'   => (string) ( $options['email'] ?? 'noor@example.test' ),
					'address' => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
					'country' => 'ES',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'name'     => 'Ceramic mug',
						'quantity' => $quantity,
						'total'    => $quantity * $unit,
					),
				),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( FakeHoldedServer::KEY, 'hld-wrong-key-0123456789' );
	}

	protected function customerNameFrom( ExternalRef $ref ): string {
		return $ref->ref;
	}
}
