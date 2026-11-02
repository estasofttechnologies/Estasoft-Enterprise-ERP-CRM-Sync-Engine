<?php
/**
 * Shared set-up for the Odoo connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/FakeOdooWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Odoo\OdooConnector;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;
use PHPUnit\Framework\TestCase;

/**
 * Builds a fake Odoo server, a connector wired to it, and connections and items to use against it.
 */
abstract class OdooTestCase extends TestCase {

	/**
	 * Milliseconds the connector asked to sleep, in order.
	 *
	 * @var array<int,int>
	 */
	protected array $slept = array();

	/**
	 * A fake server with a product, a tax and a customer-friendly default set of data.
	 *
	 * @param int                 $major     Odoo major version.
	 * @param array<string,mixed> $options   Options for the server's database.
	 * @param int|null            $saasMinor Odoo Online release minor number.
	 */
	protected function server( int $major = 18, array $options = array(), ?int $saasMinor = null ): FakeOdooServer {
		$server = new FakeOdooServer( $major, 'erp.example.com', 'testdb', 'sync@example.com', 'sk-test-0123456789abcdef', $options, $saasMinor );
		$server->db->addTax( 'VAT 5% (AE)', 5.0 );
		$server->db->addTax( 'VAT 0% (AE)', 0.0 );
		$server->db->addProduct( 'CS-BOWL-02', 'Stoneware bowl set', 219.0, array( 1 => 14.0, 2 => 6.0 ), array( 1 => 2.0 ) );
		$server->db->addProduct( 'CS-MUG-01', 'Ceramic mug, sand', 42.0, array( 1 => 42.0 ) );
		return $server;
	}

	/**
	 * A connector that talks to a server and never sleeps.
	 *
	 * @param HttpClientInterface $http              Client (usually the fake server).
	 * @param callable|null       $allowPrivateHosts Private host decision.
	 */
	protected function connector( HttpClientInterface $http, ?callable $allowPrivateHosts = null ): OdooConnector {
		return new OdooConnector(
			$http,
			$allowPrivateHosts ?? static fn(): bool => false,
			static fn(): int => 1_800_000_000,
			function ( int $milliseconds ): void {
				$this->slept[] = $milliseconds;
			},
			static fn(): float => 0.0
		);
	}

	/**
	 * A connection to a server.
	 *
	 * @param FakeOdooServer      $server  Server.
	 * @param array<string,mixed> $config  Config overrides.
	 * @param array<string,string> $secrets Secret overrides.
	 */
	protected function connection( FakeOdooServer $server, array $config = array(), array $secrets = array() ): Connection {
		return new Connection(
			1,
			'odoo',
			ConnectorType::Erp,
			'Odoo',
			ConnectionStatus::Active,
			$config + array(
				'address'  => $server->baseUrl(),
				'database' => $server->database,
				'user'     => $server->login,
				'options'  => array( 'call_interval_ms' => 0 ),
			),
			array(),
			$secrets + array( 'api_key' => $server->apiKey )
		);
	}

	/**
	 * A queue item.
	 *
	 * @param EntityType $type     Entity type.
	 * @param string     $entityId WooCommerce id.
	 * @param string     $event    Event.
	 */
	protected function item( EntityType $type, string $entityId, string $event = 'created' ): Item {
		return new Item( 1, 1, $type, $entityId, $event, 'order:' . $entityId, hash( 'sha256', $type->value . $entityId . $event ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload as the mapping engine would build it with the default rules.
	 *
	 * @param array<string,mixed> $overrides Values to replace (top-level keys).
	 * @return array<string,mixed>
	 */
	protected function orderPayload( array $overrides = array() ): array {
		return $overrides + array(
			'client_order_ref' => 'WC-10490',
			'currency'         => 'AED',
			'date_order'       => '2026-11-02 07:40:00',
			'partner'          => array(
				'name'       => 'Noor Al-Mansouri',
				'email'      => 'noor@example.com',
				'phone'      => '+971501234567',
				'street'     => 'Sheikh Zayed Road',
				'city'       => 'Dubai',
				'state_id'   => 'AE-DU',
				'country_id' => 'AE',
			),
			'order_line'       => array(
				array(
					'product'    => 'CS-BOWL-02',
					'quantity'   => 2,
					'price_unit' => 21900,
					'tax'        => 'VAT 5% (AE)',
				),
			),
			'shipping'         => array(
				'total' => 2500,
				'name'  => 'Shipping',
				'tax'   => 'VAT 5% (AE)',
			),
		);
	}
}
