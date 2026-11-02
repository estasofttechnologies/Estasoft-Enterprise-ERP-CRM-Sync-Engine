<?php
/**
 * Shared set-up for the Business Central connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Connectors\BusinessCentral\BusinessCentralConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector over the fake Business Central, with a working connection and helpers for items and payloads.
 */
abstract class BusinessCentralTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeBusinessCentralServer $bc;

	protected ConnectorInterface $connector;

	protected Connection $connection;

	protected function setUp(): void {
		$this->kit       = ApiTestKit::make();
		$this->bc        = new FakeBusinessCentralServer( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new BusinessCentralConnector( $this->kit->deps ) );
		$this->connection = $this->connection();
		$this->bc->addItem( 'CS-MUG-01', array( 'displayName' => 'Mug' ) );
		$this->bc->addItem( 'CS-TEE-02', array( 'displayName' => 'Tee shirt' ) );
		$this->bc->addItem( 'SHIP', array( 'displayName' => 'Shipping', 'type' => 'Service' ) );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A saved connection.
	 *
	 * @param array<string,mixed>  $config  More config.
	 * @param array<string,string> $secrets Replace secrets.
	 * @param int                  $id      Connection id (a token is kept per connection).
	 */
	protected function connection( array $config = array(), array $secrets = array(), int $id = 1 ): Connection {
		return $this->kit->connection(
			'business-central',
			$config + array(
				'tenant_id'   => FakeBusinessCentralServer::TENANT,
				'client_id'   => FakeBusinessCentralServer::CLIENT_ID,
				'environment' => FakeBusinessCentralServer::ENVIRONMENT,
			),
			$secrets + array( 'client_secret' => FakeBusinessCentralServer::SECRET ),
			$id
		);
	}

	/**
	 * An order item.
	 *
	 * @param string $id Order id.
	 */
	protected function orderItem( string $id, EntityType $type = EntityType::Order ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'order:' . $id, 'key-' . $id, ItemState::Syncing );
	}

	/**
	 * A mapped order payload that BC prices without tax at 5 percent.
	 *
	 * @param array<string,mixed> $override Values to replace.
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return $override + array(
			'order_date'  => '2026-11-01 08:30:00',
			'currency'    => 'AED',
			'total'       => 6297,
			'partner'     => array(
				'email'   => 'jane.doe@example.test',
				'name'    => 'Jane Doe',
				'phone'   => '+971500000000',
				'street'  => '1 Test Street',
				'city'    => 'Dubai',
				'zip'     => '00000',
				'country_id' => 'AE',
			),
			'delivery'    => array(
				'name'       => 'Jane Doe',
				'street'     => '2 Delivery Road',
				'city'       => 'Abu Dhabi',
				'country_id' => 'AE',
			),
			'order_line'  => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Mug',
					'quantity'   => 3,
					'price_unit' => 1999,
					'tax'        => 'VAT5',
				),
			),
		);
	}

	/**
	 * Lines of the only document of a collection.
	 *
	 * @param string $collection Collection.
	 * @return array<int,array<string,mixed>>
	 */
	protected function linesOfOnly( string $collection ): array {
		$docs = $this->bc->all( $collection );
		$this->assertCount( 1, $docs, 'exactly one document in ' . $collection );
		return $this->bc->lines[ (string) $docs[0]['id'] ] ?? array();
	}

	/**
	 * Requests whose method and URL match.
	 *
	 * @param string $method  Method.
	 * @param string $pattern Regular expression for the URL.
	 * @return array<int,array<string,mixed>>
	 */
	protected function requests( string $method, string $pattern ): array {
		return array_values( array_filter( $this->kit->http->requests, static fn( array $r ): bool => $r['method'] === $method && 1 === preg_match( $pattern, rawurldecode( $r['url'] ) ) ) );
	}
}
