<?php
/**
 * Shared set-up for the MYOB connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Myob\MyobConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeMyobServer;
use PHPUnit\Framework\TestCase;

/**
 * A kit (fake network, clock, token manager), a fake MYOB on it, the connector wrapped as the registry wraps it, and builders for items and payloads
 * in the shape the default mapping produces. The fake company file is Australian: currency AUD, tax code GST at 10 percent, FRE at 0.
 */
abstract class MyobTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeMyobServer $myob;

	protected ConnectorInterface $connector;

	protected Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->kit        = ApiTestKit::make();
		$this->myob       = new FakeMyobServer( $this->kit->http );
		$this->connector  = GuardedConnector::wrap( new MyobConnector( $this->kit->deps ), $this->kit->tokens );
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
	 * @param array<string,mixed> $config  More config.
	 * @param array<string,mixed> $secrets More secrets.
	 */
	protected function connect( array $options = array(), int $id = 1, array $config = array(), array $secrets = array() ): Connection {
		$config += array(
			'client_id'         => FakeMyobServer::CLIENT_ID,
			'company_file_id'   => FakeMyobServer::FILE,
			'company_file_name' => 'Demo Trading Pty Ltd',
			'currency'          => 'AUD',
		);
		if ( array() !== $options ) {
			$config['options'] = $options;
		}
		return $this->kit->connection( 'myob', $config, $secrets + $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), $id );
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
	 * An order payload as the default mapping builds it: two mugs at 42.00 with GST, so the total is 92.40.
	 *
	 * @param string              $id    Order id.
	 * @param array<string,mixed> $merge Values merged over the defaults.
	 * @return array<string,mixed>
	 */
	protected function payload( string $id, array $merge = array() ): array {
		return array_replace_recursive(
			array(
				'client_order_ref' => 'WC-' . $id,
				'currency'         => 'AUD',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => 'Layla Haddad',
					'first_name' => 'Layla',
					'last_name'  => 'Haddad',
					'email'      => 'layla@example.test',
					'phone'      => '+61400000123',
					'street'     => '1 Demo Street',
					'city'       => 'Sydney',
					'state_id'   => 'NSW',
					'zip'        => '2000',
					'country_id' => 'AU',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => 2,
						'price_unit' => 4200,
						'tax'        => 'GST',
					),
				),
				'total'            => 9240,
			),
			$merge
		);
	}

	/**
	 * A refund payload: one mug of the order WC-{order} refunded, 42.00 plus GST.
	 *
	 * @param string              $order Order id.
	 * @param array<string,mixed> $merge Values merged over the defaults.
	 * @return array<string,mixed>
	 */
	protected function refundPayload( string $order, array $merge = array() ): array {
		return array_replace_recursive(
			array(
				'order_ref'   => 'WC-' . $order,
				'date'        => '2026-11-02',
				'reason'      => 'Damaged in transit',
				'amount'      => 4620,
				'refund_line' => array(
					array(
						'product'  => 'CS-MUG-01',
						'name'     => 'Mug',
						'quantity' => 1,
						'amount'   => 4200,
						'tax'      => 'GST',
					),
				),
			),
			$merge
		);
	}
}
