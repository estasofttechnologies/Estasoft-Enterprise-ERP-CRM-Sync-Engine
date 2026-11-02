<?php
/**
 * Shared set-up for the ERPNext connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Connectors\Erpnext\ErpnextConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeErpnextServer;
use PHPUnit\Framework\TestCase;

/**
 * A fake ERPNext site, a connector wired to it (wrapped the way the registry wraps it), and items and payloads to send.
 */
abstract class ErpnextTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeErpnextServer $server;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeErpnextServer( $this->kit->http );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * The connector as production hands it out.
	 */
	protected function connector(): GuardedConnector {
		return GuardedConnector::wrap( new ErpnextConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * A connection to the fake site.
	 *
	 * @param array<string,mixed> $options Options (config keys).
	 */
	protected function connection( array $options = array(), string $secret = FakeErpnextServer::SECRET ): Connection {
		return $this->kit->connection(
			'erpnext',
			array( 'address' => FakeErpnextServer::ADDRESS ) + $options,
			array( 'api_key' => FakeErpnextServer::KEY, 'api_secret' => $secret )
		);
	}

	/**
	 * A queue item.
	 *
	 * @param EntityType $type Type.
	 * @param string     $id   Entity id.
	 */
	protected function item( EntityType $type, string $id ): Item {
		return new Item( 1, 1, $type, $id, 'created', $type->value . ':' . $id, hash( 'sha256', $id ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload.
	 *
	 * @param array<string,mixed> $override Values to merge over the default.
	 * @return array<string,mixed>
	 */
	protected function order( array $override = array() ): array {
		$payload = array(
			'client_ref' => 'WC-100',
			'currency'   => 'AED',
			'date_order' => '2026-11-02',
			'partner'    => array(
				'name'    => 'Noor Al-Mansouri',
				'email'   => 'noor@example.test',
				'phone'   => '+971501234567',
				'street'  => 'Sheikh Zayed Road',
				'city'    => 'Dubai',
				'zip'     => '00000',
				'country' => 'AE',
			),
			'order_line' => array(
				array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900 ),
			),
		);
		return array_replace_recursive( $payload, $override );
	}

	/**
	 * A mapped refund payload.
	 *
	 * @param array<string,mixed> $override Values to merge over the default.
	 * @return array<string,mixed>
	 */
	protected function refund( array $override = array() ): array {
		return array_replace(
			array(
				'order_ref'   => 'WC-100',
				'date'        => '2026-11-03',
				'reason'      => 'Damaged',
				'amount'      => 21900,
				'refund_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900 ) ),
			),
			$override
		);
	}

	/**
	 * Send an order and return the result.
	 *
	 * @param array<string,mixed> $options Connection options.
	 * @param array<string,mixed> $payload Payload override.
	 * @param string              $id      Order id.
	 */
	protected function sendOrder( array $options = array(), array $payload = array(), string $id = '100' ) {
		return $this->connector()->send( $this->connection( $options ), $this->item( EntityType::Order, $id ), $this->order( $payload ) );
	}
}
