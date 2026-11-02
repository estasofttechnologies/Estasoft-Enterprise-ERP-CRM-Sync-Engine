<?php
/**
 * Shared set-up of the SAP Business One connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Connectors\SapBusinessOne\SapBusinessOneConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\ArraySessionStore;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSapB1Server;
use PHPUnit\Framework\TestCase;

/**
 * A connector on the fake Service Layer, plus helpers that build items and payloads in the shape the default mapping produces.
 */
abstract class SapBusinessOneTestCase extends TestCase {

	/**
	 * Test kit.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake Service Layer.
	 */
	protected FakeSapB1Server $server;

	/**
	 * The connector as the engine sees it.
	 */
	protected GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeSapB1Server( $this->kit->http );
		$this->server->addCustomer( 'C20000', 'Existing Buyer', 'existing@example.test' );
		$this->connector = GuardedConnector::wrap( new SapBusinessOneConnector( $this->kit->deps, new ArraySessionStore() ), $this->kit->tokens );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A connection to the fake.
	 *
	 * @param array<string,mixed>  $options Connection options.
	 * @param array<string,mixed>  $config  Config overrides.
	 * @param array<string,string> $secrets Secret overrides.
	 */
	protected function connection( array $options = array(), array $config = array(), array $secrets = array() ): Connection {
		return new Connection(
			1,
			'sap-b1',
			ConnectorType::Erp,
			'SAP Business One',
			ConnectionStatus::Active,
			$config + array(
				'address'     => $this->server->address,
				'database'    => $this->server->database,
				'user'        => $this->server->user,
				'api_version' => $this->server->version,
				'options'     => $options,
			),
			array(),
			$secrets + array( 'password' => $this->server->password )
		);
	}

	/**
	 * A queue item.
	 *
	 * @param string     $id   WooCommerce id.
	 * @param EntityType $type Entity type.
	 */
	protected function item( string $id, EntityType $type = EntityType::Order ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'order:' . $id, hash( 'sha256', $type->value . $id ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload as the default rules build it.
	 *
	 * @param array<string,mixed> $override Values that replace the defaults (top level keys).
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return $override + array(
			'currency'   => 'AED',
			'order_date' => '2026-11-02 07:34:00',
			'note'       => 'Gift wrap please',
			'total'      => 5997,
			'partner'    => array(
				'name'    => 'Noor Al-Mansouri',
				'email'   => 'noor@example.test',
				'street'  => 'Sheikh Zayed Road',
				'city'    => 'Dubai',
				'country' => 'AE',
				'phone'   => '+971501234567',
			),
			'delivery'   => array(
				'name'    => 'Noor Al-Mansouri',
				'street'  => 'Marina Walk 4',
				'city'    => 'Dubai',
				'zip'     => '00000',
				'country' => 'AE',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Ceramic mug, sand',
					'quantity'   => 3,
					'price_unit' => 1999,
				),
			),
		);
	}
}
