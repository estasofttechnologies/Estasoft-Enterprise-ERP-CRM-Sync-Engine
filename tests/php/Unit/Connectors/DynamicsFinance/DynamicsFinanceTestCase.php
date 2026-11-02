<?php
/**
 * Shared set-up for the Finance and Supply Chain connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsFinance;

use Estasoft\SyncEngine\Connectors\DynamicsFinance\DynamicsFinanceConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeDynamicsFinanceServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector over the fake Finance and Operations service, with a working connection and helpers for items and payloads.
 */
abstract class DynamicsFinanceTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeDynamicsFinanceServer $fo;

	protected ConnectorInterface $connector;

	protected Connection $connection;

	protected function setUp(): void {
		$this->kit        = ApiTestKit::make();
		$this->fo         = new FakeDynamicsFinanceServer( $this->kit->http );
		$this->connector  = GuardedConnector::wrap( new DynamicsFinanceConnector( $this->kit->deps ) );
		$this->connection = $this->connection();
		$this->fo->addProduct( 'CS-MUG-01' );
		$this->fo->addProduct( 'CS-TEE-02' );
		$this->fo->addProduct( 'SHIP' );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A saved connection for the legal entity USMF.
	 *
	 * @param array<string,mixed>  $config  More config.
	 * @param array<string,string> $secrets Replace secrets.
	 * @param int                  $id      Connection id (a token is kept per connection).
	 */
	protected function connection( array $config = array(), array $secrets = array(), int $id = 1 ): Connection {
		return $this->kit->connection(
			'dynamics-finance',
			$config + array(
				'tenant_id'       => FakeDynamicsFinanceServer::TENANT,
				'client_id'       => FakeDynamicsFinanceServer::CLIENT_ID,
				'environment_url' => FakeDynamicsFinanceServer::ENVIRONMENT,
				'legal_entity'    => 'USMF',
				'customer_group'  => '10',
			),
			$secrets + array( 'client_secret' => FakeDynamicsFinanceServer::SECRET ),
			$id
		);
	}

	/**
	 * An item of an entity type.
	 *
	 * @param string     $id   WooCommerce id.
	 * @param EntityType $type Type.
	 */
	protected function item( string $id, EntityType $type = EntityType::Order ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'order:' . $id, 'key-' . $id, ItemState::Syncing );
	}

	/**
	 * A mapped order payload: three mugs at 19.99 with the 5 percent group, total AED 62.97.
	 *
	 * @param array<string,mixed> $override Values to replace.
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return $override + array(
			'order_date' => '2026-11-01 08:30:00',
			'currency'   => 'AED',
			'total'      => 6297,
			'partner'    => array(
				'email'      => 'jane.doe@example.test',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'phone'      => '+971500000000',
				'street'     => '1 Test Street',
				'city'       => 'Dubai',
				'zip'        => '00000',
				'country_id' => 'ARE',
			),
			'delivery'   => array(
				'name'       => 'Jane Doe',
				'street'     => '2 Delivery Road',
				'city'       => 'Abu Dhabi',
				'country_id' => 'ARE',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Mug',
					'quantity'   => 3,
					'price_unit' => 1999,
					'tax'        => 'FULL',
				),
			),
		);
	}

	/**
	 * Lines of the only sales order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function linesOfOrder(): array {
		$orders = $this->fo->all( 'SalesOrderHeadersV2' );
		$this->assertCount( 1, $orders, 'exactly one sales order' );
		$lines = array_values( array_filter( $this->fo->all( 'SalesOrderLines' ), static fn( array $line ): bool => $line['SalesOrderNumber'] === $orders[0]['SalesOrderNumber'] ) );
		usort( $lines, static fn( array $a, array $b ): int => $a['LineCreationSequenceNumber'] <=> $b['LineCreationSequenceNumber'] );
		return $lines;
	}
}
