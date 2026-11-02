<?php
/**
 * The connector contract rules, checked against the Daftra connector and a fake Daftra.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Daftra;

use Estasoft\SyncEngine\Connectors\Daftra\DaftraConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeDaftraServer;

final class DaftraConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Daftra.
	 */
	private FakeDaftraServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeDaftraServer();
		$this->server->install( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new DaftraConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'daftra', array( 'subdomain' => 'acme' ), array( 'api_key' => FakeDaftraServer::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'daftra', array( 'subdomain' => 'acme' ), array( 'api_key' => 'dft-wrong-key-0123456789' ), 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$item     = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', 'k' . $orderId ), ItemState::Syncing );
		return array(
			$item,
			array(
				'date'     => '2026-11-02',
				'currency' => (string) ( $options['currency'] ?? 'SAR' ),
				'number'   => $orderId,
				'total'    => $quantity * $unit,
				'customer' => array(
					'name'    => (string) ( $options['name'] ?? 'Noor Almansoori' ),
					'email'   => (string) ( $options['email'] ?? 'noor@example.test' ),
					'address' => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
					'country' => 'AE',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'item'     => '11',
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
		return array( FakeDaftraServer::KEY, 'dft-wrong-key-0123456789' );
	}

	protected function customerNameFrom( ExternalRef $ref ): string {
		return $ref->ref;
	}
}
