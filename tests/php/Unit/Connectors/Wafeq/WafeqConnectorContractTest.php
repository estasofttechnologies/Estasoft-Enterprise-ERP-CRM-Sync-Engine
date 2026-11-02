<?php
/**
 * The connector contract rules, checked against the Wafeq connector and a fake Wafeq.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeWafeqServer;

final class WafeqConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Wafeq.
	 */
	private FakeWafeqServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeWafeqServer();
		$this->server->install( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new WafeqConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'wafeq', array(), array( 'api_key' => FakeWafeqServer::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'wafeq', array(), array( 'api_key' => 'wfq-wrong-key-0123456789' ), 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$name     = (string) ( $options['name'] ?? 'Noor Almansoori' );
		$email    = (string) ( $options['email'] ?? 'noor@example.test' );
		$currency = (string) ( $options['currency'] ?? 'SAR' );
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$item     = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', 'k' . $orderId ), ItemState::Syncing );
		return array(
			$item,
			array(
				'date'     => '2026-11-02',
				'currency' => $currency,
				'number'   => $orderId,
				'total'    => $quantity * $unit,
				'customer' => array(
					'name'    => $name,
					'email'   => $email,
					'address' => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
					'country' => 'AE',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'item'     => 'itm_mug',
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
		return array( FakeWafeqServer::KEY, 'wfq-wrong-key-0123456789' );
	}

	protected function customerNameFrom( \Estasoft\SyncEngine\Contract\ExternalRef $ref ): string {
		return $ref->ref;
	}
}
