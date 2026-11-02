<?php
/**
 * Shared set-up of the Oracle Fusion connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Connectors\OracleFusion\OracleFusionConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeOracleFusionServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector on the fake pod, plus helpers that build items and payloads in the shape the default mapping produces.
 */
abstract class OracleFusionTestCase extends TestCase {

	/**
	 * Test kit.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake pod.
	 */
	protected FakeOracleFusionServer $server;

	/**
	 * The connector as the engine sees it.
	 */
	protected GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->server    = new FakeOracleFusionServer( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new OracleFusionConnector( $this->kit->deps ), $this->kit->tokens );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A connection to the fake. The saved copy (which the token manager reads) is replaced only when the sign-in settings change, so a token
	 * survives from one call to the next.
	 *
	 * @param array<string,mixed>  $options Connection options.
	 * @param array<string,mixed>  $config  Config overrides.
	 * @param array<string,string> $secrets Secret overrides.
	 */
	protected function connection( array $options = array(), array $config = array(), array $secrets = array() ): Connection {
		$base = $config + array(
			'address'            => $this->server->address,
			'auth_method'        => 'oauth',
			'identity_url'       => $this->server->identity,
			'scope'              => $this->server->scope,
			'client_id'          => $this->server->clientId,
			'business_unit'      => $this->server->businessUnit,
			'transaction_source' => $this->server->source,
			'transaction_type'   => $this->server->type,
			'bill_to_account'    => '1001',
		);
		$keys = $secrets + array(
			'client_secret' => $this->server->clientSecret,
			'password'      => $this->server->password,
		);
		$row  = $this->kit->storage->rows[1] ?? null;
		$held = null === $row ? array() : array_diff_key( $row['secrets'], array_flip( array( 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_refresh_expires_at' ) ) );
		if ( null === $row || $row['config'] !== $base || $held !== $keys ) {
			$this->kit->storage->add( 1, 'oracle-fusion', $base, $keys );
		}
		return new Connection( 1, 'oracle-fusion', ConnectorType::Erp, 'Oracle Fusion Cloud ERP', ConnectionStatus::Active, $base + array( 'options' => $options ), array(), $keys );
	}

	/**
	 * A queue item.
	 *
	 * @param string     $id   WooCommerce id.
	 * @param EntityType $type Entity type.
	 */
	protected function item( string $id, EntityType $type = EntityType::Order ): Item {
		return new Item( (int) $id, 1, $type, $id, 'created', 'order:' . $id, hash( 'sha256', $type->value . $id ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload as the default rules build it.
	 *
	 * @param array<string,mixed> $override Values that replace the defaults (top level keys).
	 * @return array<string,mixed>
	 */
	protected function payload( array $override = array() ): array {
		return $override + array(
			'currency'   => 'AED',
			'order_date' => '2026-11-02 07:34:00',
			'note'       => 'Gift wrap please',
			'total'      => 5997,
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Ceramic mug, sand',
					'quantity'   => 3,
					'price_unit' => 1999,
				),
			),
		);
	}

	/**
	 * The requests of one method that went to a path.
	 *
	 * @param string $method Method.
	 * @param string $suffix End of the path.
	 * @return array<int,array<string,mixed>>
	 */
	protected function calls( string $method, string $suffix ): array {
		return array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => $method === $request['method'] && str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), $suffix ) ) );
	}
}
