<?php
/**
 * Shared set-up of the Exact Online tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Exact\ExactConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeExactServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector over the fake Exact Online, with a signed-in connection and the items the fixtures use.
 */
abstract class ExactTestCase extends TestCase {

	/**
	 * Client secret of the test app.
	 */
	protected const SECRET = 'exact-client-secret-0123456789';

	/**
	 * Fakes and dependencies.
	 *
	 * @var ApiTestKit
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake Exact Online.
	 *
	 * @var FakeExactServer
	 */
	protected FakeExactServer $server;

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
		$this->server = new FakeExactServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->server->addItem( 'CS-MUG-01', '0' );
		$this->server->addItem( 'CS-TEE-02', '2' );
		$this->server->addItem( 'SHIP-01', '0', false );
		$this->connector = GuardedConnector::wrap( new ExactConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * Remove the temporary folder.
	 */
	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A signed-in connection with a valid token.
	 *
	 * @param array<string,mixed>  $config  Config added to the defaults.
	 * @param array<string,string> $secrets Secrets added to the defaults.
	 * @param string               $token   Access token.
	 */
	protected function connection( array $config = array(), array $secrets = array(), string $token = 'access-0' ): Connection {
		$this->server->allowTokens( $token, 'refresh-' . $token );
		$config  = $config + array(
			'region'        => 'nl',
			'client_id'     => 'exact-client-id',
			'division'      => FakeExactServer::DIVISION,
			'division_name' => 'Test BV',
		);
		$secrets = $secrets + array(
			'client_secret'      => self::SECRET,
			'oauth_access_token' => $token,
			'oauth_refresh_token' => 'refresh-' . $token,
			'oauth_expires_at'   => (string) ( $this->kit->clock->now()->getTimestamp() + 600 ),
		);
		return $this->kit->connection( 'exact-online', $config, $secrets );
	}

	/**
	 * An order item and its mapped payload.
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
				'currency'   => 'EUR',
				'date_order' => '2026-10-30 09:15:00',
				'note'       => 'Leave at the door',
				'total'      => 12100 + 2000,
				'partner'    => array(
					'name'       => 'Layla Haddad',
					'email'      => 'layla@example.test',
					'phone'      => '+31201234567',
					'street'     => 'Keizersgracht 1',
					'city'       => 'Amsterdam',
					'zip'        => '1015AA',
					'country_id' => 'NL',
				),
				'order_line' => array(
					array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 10000, 'tax' => '2' ),
					array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 2000, 'tax' => '0' ),
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
				'order_ref'    => 'WC-' . $orderId,
				'date'         => '2026-11-01',
				'reason'       => 'Damaged',
				'amount'       => 2000,
				'currency'     => 'EUR',
				'refund_line'  => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'amount' => 2000, 'tax' => '0' ) ),
			),
		);
	}

	/**
	 * Requests of the fake network whose "METHOD path" matches a pattern.
	 *
	 * @param string $pattern Regular expression for the log lines.
	 * @return array<int,string>
	 */
	protected function logged( string $pattern ): array {
		return array_values( array_filter( $this->server->log, static fn( string $line ): bool => 1 === preg_match( $pattern, $line ) ) );
	}
}
