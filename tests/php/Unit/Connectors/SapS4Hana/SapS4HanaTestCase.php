<?php
/**
 * Shared set-up of the SAP S/4HANA connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Connectors\SapS4Hana\SapS4HanaConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSapS4Server;
use PHPUnit\Framework\TestCase;

/**
 * A connector on the fake tenant, plus helpers that build items and payloads in the shape the default mapping produces.
 */
abstract class SapS4HanaTestCase extends TestCase {

	/**
	 * Test kit.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake tenant.
	 */
	protected FakeSapS4Server $server;

	/**
	 * The connector as the engine sees it.
	 */
	protected GuardedConnector $connector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit       = ApiTestKit::make();
		$this->server    = new FakeSapS4Server( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new SapS4HanaConnector( $this->kit->deps ), $this->kit->tokens );
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
			'address'              => $this->server->address,
			'auth_method'          => 'oauth',
			'token_url'            => $this->server->tokenUrl,
			'scope'                => $this->server->scope,
			'client_id'            => $this->server->clientId,
			'sales_organization'   => '1710',
			'distribution_channel' => '10',
			'division'             => '00',
			'sold_to_party'        => '17100001',
		);
		$keys = $secrets + array(
			'client_secret' => $this->server->clientSecret,
			'password'      => $this->server->password,
		);
		$row  = $this->kit->storage->rows[1] ?? null;
		$held = null === $row ? array() : array_diff_key( $row['secrets'], array_flip( array( 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_refresh_expires_at' ) ) );
		if ( null === $row || $row['config'] !== $base || $held !== $keys ) {
			$this->kit->storage->add( 1, 'sap-s4hana', $base, $keys );
		}
		return new Connection( 1, 'sap-s4hana', ConnectorType::Erp, 'SAP S/4HANA', ConnectionStatus::Active, $base + array( 'options' => $options ), array(), $keys );
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
			'total'      => 6300,
			'order_line' => array(
				array(
					'product'    => 'CS-MUG-01',
					'name'       => 'Ceramic mug, sand',
					'quantity'   => 3,
					'price_unit' => 2100,
				),
			),
		);
	}
}
