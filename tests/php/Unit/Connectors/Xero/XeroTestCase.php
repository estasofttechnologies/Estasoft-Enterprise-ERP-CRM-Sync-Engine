<?php
/**
 * Shared set-up for the Xero connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\Xero\XeroConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeXeroServer;
use PHPUnit\Framework\TestCase;

/**
 * A kit (fake network, clock, token manager), a fake Xero on it, the connector wrapped as the registry wraps it, and builders for items and
 * payloads in the shape the default mapping produces.
 */
abstract class XeroTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeXeroServer $xero;

	protected ConnectorInterface $connector;

	protected Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->xero      = new FakeXeroServer( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new XeroConnector( $this->kit->deps ), $this->kit->tokens );
		$this->connection = $this->connect();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A signed-in connection with options.
	 *
	 * @param array<string,mixed> $options Options.
	 * @param int                 $id      Connection id.
	 */
	protected function connect( array $options = array(), int $id = 1 ): Connection {
		$config = array(
			'client_id'         => FakeXeroServer::CLIENT_ID,
			'tenant_id'         => FakeXeroServer::TENANT,
			'organisation_name' => 'Demo Trading LLC',
		);
		if ( array() !== $options ) {
			$config['options'] = $options;
		}
		return $this->kit->connection( 'xero', $config, $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() ), $id );
	}

	/**
	 * A queue item.
	 *
	 * @param string     $id      WooCommerce id.
	 * @param EntityType $type    Entity type.
	 * @param int        $attempt Tries made so far.
	 */
	protected function item( string $id, EntityType $type = EntityType::Order, int $attempt = 0 ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'order:' . $id, 'key-' . $id, ItemState::Syncing, attempt: $attempt );
	}

	/**
	 * An order payload as the default mapping builds it.
	 *
	 * @param string              $id    Order id.
	 * @param array<string,mixed> $merge Values merged over the defaults.
	 * @return array<string,mixed>
	 */
	protected function payload( string $id, array $merge = array() ): array {
		return array_replace_recursive(
			array(
				'client_order_ref' => 'WC-' . $id,
				'currency'         => 'AED',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => 'Layla Haddad',
					'first_name' => 'Layla',
					'last_name'  => 'Haddad',
					'email'      => 'layla@example.test',
					'phone'      => '+971501234567',
					'street'     => 'Sheikh Zayed Road',
					'city'       => 'Dubai',
					'country_id' => 'AE',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => 2,
						'price_unit' => 4200,
						'tax'        => 'OUTPUT',
					),
				),
				'total'            => 8820,
			),
			$merge
		);
	}
}
