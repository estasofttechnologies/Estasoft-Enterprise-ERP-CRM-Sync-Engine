<?php
/**
 * Shared set-up for the Wafeq connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeWafeqServer;
use PHPUnit\Framework\TestCase;

/**
 * Builds the shared fakes, a connector wired to a fake Wafeq, connections and items to use against it.
 */
abstract class WafeqTestCase extends TestCase {

	/**
	 * Fakes and dependencies.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake Wafeq.
	 */
	protected FakeWafeqServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeWafeqServer();
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
		return GuardedConnector::wrap( new WafeqConnector( $this->kit->deps ) );
	}

	/**
	 * A working connection.
	 *
	 * @param array<string,mixed> $config Options.
	 * @param string              $key    API key.
	 * @param int                 $id     Connection id.
	 */
	protected function connection( array $config = array(), string $key = FakeWafeqServer::KEY, int $id = 1 ): Connection {
		return $this->kit->connection( 'wafeq', $config, array( 'api_key' => $key ), $id );
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
	 * A mapped order payload.
	 *
	 * @param array<string,mixed> $override Values that replace the defaults (top-level keys).
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return array_replace(
			array(
				'date'     => '2026-11-02',
				'currency' => 'SAR',
				'note'     => 'Gift wrap please',
				'number'   => '10490',
				'total'    => 7998,
				'customer' => array(
					'name'     => 'نور المنصوري',
					'email'    => 'Noor@Example.com',
					'phone'    => '+966501234567',
					'address'  => 'شارع الشيخ زايد',
					'city'     => 'الرياض',
					'postcode' => '12345',
					'country'  => 'SA',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'item'     => 'itm_mug',
						'name'     => 'Ceramic mug',
						'quantity' => 2,
						'total'    => 7998,
					),
				),
			),
			$override
		);
	}

	/**
	 * A mapped refund payload.
	 *
	 * @param array<string,mixed> $override Values that replace the defaults.
	 * @return array<string,mixed>
	 */
	protected function refundPayload( array $override = array() ): array {
		return array_replace(
			array(
				'order_ref' => 'WC-10490',
				'date'      => '2026-11-03',
				'currency'  => 'SAR',
				'reason'    => 'Damaged in transit',
				'amount'    => 3999,
				'lines'     => array(
					array(
						'sku'      => 'CS-MUG-01',
						'quantity' => 1,
						'amount'   => 3999,
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
