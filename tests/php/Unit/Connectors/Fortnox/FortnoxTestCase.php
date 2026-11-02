<?php
/**
 * Shared set-up of the Fortnox tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Fortnox;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Fortnox\FortnoxConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeFortnoxServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector over the fake Fortnox, with a signed-in connection and the articles the fixtures use.
 */
abstract class FortnoxTestCase extends TestCase {

	/**
	 * Fakes and dependencies.
	 *
	 * @var ApiTestKit
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake Fortnox.
	 *
	 * @var FakeFortnoxServer
	 */
	protected FakeFortnoxServer $server;

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
		$this->server = new FakeFortnoxServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->server->addArticle( 'CS-MUG-01', 10.0, 2.0 );
		$this->server->addArticle( 'CS-TEE-02', 5.0, 0.0 );
		$this->connector = GuardedConnector::wrap( new FortnoxConnector( $this->kit->deps ), $this->kit->tokens );
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
	protected function connection( array $config = array(), array $secrets = array(), string $token = 'fx-access-0' ): Connection {
		$this->server->allowTokens( $token, 'refresh-' . $token );
		$config  = $config + array(
			'client_id'    => FakeFortnoxServer::CLIENT_ID,
			'tenant_id'    => '1234567',
			'company_name' => 'Test AB',
		);
		$secrets = $secrets + array(
			'client_secret'       => FakeFortnoxServer::CLIENT_SECRET,
			'oauth_access_token'  => $token,
			'oauth_refresh_token' => 'refresh-' . $token,
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() + 3600 ),
		);
		return $this->kit->connection( 'fortnox', $config, $secrets );
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
				'currency'   => 'SEK',
				'date_order' => '2026-10-30 09:15:00',
				'paid_at'    => '2026-10-30 09:16:00',
				'note'       => 'Leave at the door',
				'total'      => 14500,
				'partner'    => array(
					'name'       => 'Layla Haddad',
					'email'      => 'layla@example.test',
					'phone'      => '+46701234567',
					'street'     => 'Drottninggatan 1',
					'city'       => 'Stockholm',
					'zip'        => '11151',
					'country_id' => 'SE',
				),
				'order_line' => array(
					array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 10000, 'tax' => '25' ),
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
				'order_ref'   => 'WC-' . $orderId,
				'date'        => '2026-11-01',
				'reason'      => 'Damaged',
				'amount'      => 2000,
				'currency'    => 'SEK',
				'refund_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'amount' => 2000, 'tax' => '0' ) ),
			),
		);
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
