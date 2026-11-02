<?php
/**
 * Shared set-up for the Sage Accounting connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\SageAccounting\SageAccountingConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSageAccountingServer;
use PHPUnit\Framework\TestCase;

/**
 * A kit (fake network, clock, token manager), a fake Sage on it, the connector wrapped as the registry wraps it, and builders for items and payloads in the shape the
 * default mapping produces. The fake business is British: currency GBP, tax rates GB_STANDARD (20), GB_REDUCED (5), GB_ZERO and GB_NO_TAX (0), income accounts 4000, 4010
 * and 4900, one bank account.
 */
abstract class SageTestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeSageAccountingServer $sage;

	protected ConnectorInterface $connector;

	protected Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->kit        = ApiTestKit::make();
		$this->sage       = new FakeSageAccountingServer( $this->kit->http );
		$this->connector  = GuardedConnector::wrap( new SageAccountingConnector( $this->kit->deps ), $this->kit->tokens );
		$this->connection = $this->connect();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A signed-in connection with options. The default options name the sales account.
	 *
	 * @param array<string,mixed> $options Options merged over the defaults.
	 * @param int                 $id      Connection id.
	 * @param array<string,mixed> $config  More config.
	 */
	protected function connect( array $options = array(), int $id = 1, array $config = array() ): Connection {
		$config += array(
			'client_id'     => FakeSageAccountingServer::CLIENT_ID,
			'business_id'   => FakeSageAccountingServer::BUSINESS,
			'business_name' => 'Demo Trading Ltd',
			'country'       => 'gb',
			'options'       => $options + array( 'sales_ledger_account' => '4000' ),
		);
		return $this->kit->connection( 'sage-accounting', $config, $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() ), $id );
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
	 * An order payload as the default mapping builds it: two mugs at 42.00 with standard VAT, so the total is 100.80.
	 *
	 * @param string              $id    Order id.
	 * @param array<string,mixed> $merge Values merged over the defaults.
	 * @return array<string,mixed>
	 */
	protected function payload( string $id, array $merge = array() ): array {
		return array_replace_recursive(
			array(
				'client_order_ref' => 'WC-' . $id,
				'currency'         => 'GBP',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => 'Layla Haddad',
					'first_name' => 'Layla',
					'last_name'  => 'Haddad',
					'email'      => 'layla@example.test',
					'phone'      => '+447700900123',
					'street'     => '1 Demo Street',
					'city'       => 'London',
					'zip'        => 'N1 9GU',
					'country_id' => 'GB',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => 2,
						'price_unit' => 4200,
						'tax'        => 'GB_STANDARD',
					),
				),
				'total'            => 10080,
			),
			$merge
		);
	}

	/**
	 * A refund payload: one mug of the order WC-{order} refunded, 42.00 plus VAT.
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
				'amount'      => 5040,
				'refund_line' => array(
					array(
						'product'  => 'CS-MUG-01',
						'name'     => 'Mug',
						'quantity' => 1,
						'amount'   => 4200,
						'tax'      => 'GB_STANDARD',
					),
				),
			),
			$merge
		);
	}
}
