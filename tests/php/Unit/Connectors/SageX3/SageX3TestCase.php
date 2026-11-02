<?php
/**
 * Shared set-up for the Sage X3 connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageX3;

use Estasoft\SyncEngine\Connectors\SageX3\SageX3Connector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSageX3Server;
use PHPUnit\Framework\TestCase;

/**
 * A fake Sage X3 web service, a connector wired to it (wrapped the way the registry wraps it), and items and payloads to send.
 */
abstract class SageX3TestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeSageX3Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeSageX3Server( $this->kit->http );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * The connector as production hands it out.
	 */
	protected function connector(): GuardedConnector {
		return GuardedConnector::wrap( new SageX3Connector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * A connection to the fake web service with the customer of the web orders.
	 *
	 * @param array<string,mixed> $config   Extra config (settings).
	 * @param string              $password Password.
	 */
	protected function connection( array $config = array(), string $password = FakeSageX3Server::PASSWORD ): Connection {
		return $this->kit->connection(
			'sagex3',
			$config + array(
				'address'       => FakeSageX3Server::ADDRESS,
				'folder'        => FakeSageX3Server::FOLDER,
				'username'      => FakeSageX3Server::USER,
				'customer_code' => 'WEB001',
			),
			array( 'password' => $password )
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
			'note'       => 'Gift wrap please',
			'partner'    => array(
				'name'  => 'Noor Al-Mansouri',
				'email' => 'noor@example.test',
			),
			'order_line' => array(
				array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900 ),
			),
			'total'      => 45990,
		);
		return array_replace_recursive( $payload, $override );
	}

	/**
	 * Send an order and return the result.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Payload override.
	 * @param string              $id      Order id.
	 */
	protected function sendOrder( array $config = array(), array $payload = array(), string $id = '100' ) {
		return $this->connector()->send( $this->connection( $config + array( 'total_property' => 'ORDINVATI' ) ), $this->item( EntityType::Order, $id ), $this->order( $payload ) );
	}

	/**
	 * A failure message with its reason, for assertions.
	 *
	 * @param mixed $result Send result.
	 */
	protected function why( $result ): string {
		return null === $result->error ? '' : $result->error->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' );
	}
}
