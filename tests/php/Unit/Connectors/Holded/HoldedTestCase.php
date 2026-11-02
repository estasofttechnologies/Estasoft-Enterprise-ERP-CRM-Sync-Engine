<?php
/**
 * Shared set-up for the Holded connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Connectors\Holded\HoldedConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHoldedIndexStore;
use Estasoft\SyncEngine\Tests\Support\FakeHoldedServer;
use PHPUnit\Framework\TestCase;

/**
 * Builds the shared fakes, a connector wired to a fake Holded, connections and items to use against it.
 */
abstract class HoldedTestCase extends TestCase {

	/**
	 * Fakes and dependencies.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake Holded.
	 */
	protected FakeHoldedServer $server;

	/**
	 * Where the indexes and the call quota are kept (in memory here, transients in the engine).
	 */
	protected FakeHoldedIndexStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->store  = new FakeHoldedIndexStore();
		$this->server = new FakeHoldedServer();
		$this->server->install( $this->kit->http );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * The connector as the registry hands it out.
	 */
	protected function connector(): GuardedConnector {
		return GuardedConnector::wrap( new HoldedConnector( $this->kit->deps, $this->store ) );
	}

	/**
	 * A working connection.
	 *
	 * @param array<string,mixed> $config Options.
	 * @param string              $key    API key.
	 * @param int                 $id     Connection id.
	 */
	protected function connection( array $config = array(), string $key = FakeHoldedServer::KEY, int $id = 1 ): Connection {
		return $this->kit->connection( 'holded', $config, array( 'api_key' => $key ), $id );
	}

	/**
	 * A queue item.
	 *
	 * @param EntityType $type   Entity type.
	 * @param string     $id     WooCommerce id.
	 * @param string     $event  Event.
	 * @param string     $parent Parent order id for a refund.
	 */
	protected function item( EntityType $type = EntityType::Order, string $id = '10490', string $event = 'created', string $parent = '' ): Item {
		$partition = EntityType::Refund === $type ? 'order:' . $parent : 'order:' . $id;
		return new Item( 1, 1, $type, $id, $event, $partition, hash( 'sha256', "v1:1:{$type->value}:{$id}:{$event}:0" ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload (two mugs, 79.98 EUR net, SKU CS-MUG-01, 21 percent VAT, 96.78 in all).
	 *
	 * @param array<string,mixed> $override Values that replace the defaults (top-level keys).
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return array_replace(
			array(
				'date'     => '2026-11-02',
				'currency' => 'EUR',
				'note'     => 'Gift wrap please',
				'number'   => '10490',
				'total'    => 9678,
				'customer' => array(
					'name'       => 'Núria Ibáñez',
					'email'      => 'Nuria@Example.com',
					'phone'      => '+34600123456',
					'address'    => 'Calle de Alcalá 12',
					'city'       => 'Madrid',
					'state'      => 'Madrid',
					'postcode'   => '28014',
					'country'    => 'ES',
					'tax_number' => 'B12345674',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'name'     => 'Ceramic mug',
						'quantity' => 2,
						'total'    => 7998,
						'tax_code' => '21',
					),
				),
			),
			$override
		);
	}

	/**
	 * A mapped refund payload (one mug back: 39.99 and 8.40 tax, 48.39 in all).
	 *
	 * @param array<string,mixed> $override Values that replace the defaults.
	 * @return array<string,mixed>
	 */
	protected function refundPayload( array $override = array() ): array {
		return array_replace(
			array(
				'order_ref'  => 'WC-10490',
				'order_date' => '2026-11-02',
				'date'       => '2026-11-03',
				'currency'   => 'EUR',
				'reason'     => 'Damaged in transit',
				'amount'     => 4839,
				'lines'      => array(
					array(
						'sku'        => 'CS-MUG-01',
						'quantity'   => 1,
						'amount'     => 3999,
						'tax_amount' => 840,
					),
				),
			),
			$override
		);
	}

	/**
	 * Requests whose URL matches a pattern.
	 *
	 * @param string $method  Method.
	 * @param string $pattern Regular expression for the URL.
	 * @return array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	protected function requests( string $method, string $pattern ): array {
		return array_values( array_filter( $this->kit->http->matching( $pattern ), static fn( array $request ): bool => $request['method'] === $method ) );
	}

	/**
	 * The decoded body of a request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 * @return array<string,mixed>
	 */
	protected function bodyOf( array $request ): array {
		return (array) json_decode( (string) $request['body'], true );
	}
}
