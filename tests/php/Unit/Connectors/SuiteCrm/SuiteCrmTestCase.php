<?php
/**
 * Shared set-up of the SuiteCRM tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SuiteCrm;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\SuiteCrm\SuiteCrmConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSuiteCrmServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector over the fake SuiteCRM, with a connection that has no token yet (the client credentials grant fetches it).
 */
abstract class SuiteCrmTestCase extends TestCase {

	/**
	 * Fakes and dependencies.
	 *
	 * @var ApiTestKit
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake SuiteCRM.
	 *
	 * @var FakeSuiteCrmServer
	 */
	protected FakeSuiteCrmServer $server;

	/**
	 * The connector as the engine sees it.
	 *
	 * @var ConnectorInterface
	 */
	protected ConnectorInterface $connector;

	/**
	 * Build the kit, the server and the connector.
	 */
	protected function setUp(): void {
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeSuiteCrmServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->connector = GuardedConnector::wrap( new SuiteCrmConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * Remove the temporary folder.
	 */
	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A connection to the fake server (the default currency of the CRM is euro).
	 *
	 * @param array<string,mixed>  $config  Config added to the defaults.
	 * @param array<string,string> $secrets Secrets added to the defaults.
	 * @param int                  $id      Connection id.
	 */
	protected function connection( array $config = array(), array $secrets = array(), int $id = 1 ): Connection {
		$config  = $config + array(
			'address'   => 'https://' . FakeSuiteCrmServer::HOST,
			'version'   => 'v8',
			'grant'     => 'client_credentials',
			'client_id' => FakeSuiteCrmServer::CLIENT_ID,
			'options'   => array( 'base_currency' => 'EUR' ),
		);
		$secrets = $secrets + array( 'client_secret' => FakeSuiteCrmServer::CLIENT_SECRET );
		return $this->kit->connection( 'suitecrm', $config, $secrets, $id );
	}

	/**
	 * The options with the default currency added.
	 *
	 * @param array<string,mixed> $options Options.
	 * @return array<string,mixed>
	 */
	protected function options( array $options ): array {
		return array( 'options' => $options + array( 'base_currency' => 'EUR' ) );
	}

	/**
	 * An order item and its mapped payload (the neutral CRM paths).
	 *
	 * @param string              $id      WooCommerce order id.
	 * @param array<string,mixed> $payload Values that replace those of the default payload.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	protected function order( string $id, array $payload = array() ): array {
		$item = new Item( (int) $id, 1, EntityType::Order, $id, 'created', 'order:' . $id, 'k-' . $id, ItemState::Syncing );
		return array(
			$item,
			$payload + array(
				'currency' => 'EUR',
				'date'     => '2026-10-30',
				'paid_at'  => '2026-10-30 09:16:00',
				'note'     => 'Leave at the door',
				'total'    => 14100,
				'contact'  => array(
					'email'      => 'layla@example.test',
					'first_name' => 'Layla',
					'last_name'  => 'Haddad',
					'phone'      => '+31201234567',
					'street'     => 'Keizersgracht 1',
					'city'       => 'Amsterdam',
					'postcode'   => '1015AA',
					'country'    => 'NL',
				),
				'lines'    => array(
					array( 'sku' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => '1', 'unit_price' => 10000, 'total' => 10000, 'tax' => 2100 ),
					array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => '1', 'unit_price' => 2000, 'total' => 2000, 'tax' => 0 ),
				),
			),
		);
	}

	/**
	 * A refund item and its mapped payload.
	 *
	 * @param string              $id      Refund id.
	 * @param string              $orderId Order id.
	 * @param array<string,mixed> $payload Values that replace those of the default payload.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	protected function refund( string $id, string $orderId, array $payload = array() ): array {
		$item = new Item( (int) $id, 1, EntityType::Refund, $id, 'refunded', 'order:' . $orderId, 'r-' . $id, ItemState::Syncing );
		return array(
			$item,
			$payload + array(
				'order_ref' => 'WC-' . $orderId,
				'amount'    => 2000,
				'currency'  => 'EUR',
				'date'      => '2026-11-01',
				'reason'    => 'Damaged',
			),
		);
	}

	/**
	 * The records of a module in the fake.
	 *
	 * @param string $module Module.
	 * @return array<int,array<string,mixed>> Attributes of each record.
	 */
	protected function records( string $module ): array {
		return array_values( array_map( static fn( array $row ): array => $row['attributes'], $this->server->modules[ $module ] ) );
	}

	/**
	 * Log lines of the fake network that match a pattern.
	 *
	 * @param string $pattern Regular expression.
	 * @return array<int,string>
	 */
	protected function logged( string $pattern ): array {
		return array_values( array_filter( $this->server->log, static fn( string $line ): bool => 1 === preg_match( $pattern, $line ) ) );
	}
}
