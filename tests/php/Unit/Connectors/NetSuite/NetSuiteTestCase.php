<?php
/**
 * Shared set-up of the NetSuite connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Connectors\NetSuite\NetSuiteConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeNetSuiteServer;
use PHPUnit\Framework\TestCase;

/**
 * A connector on the fake NetSuite with an EC key, plus helpers that build items and payloads in the shape the default mapping produces.
 */
abstract class NetSuiteTestCase extends TestCase {

	/**
	 * Test kit.
	 */
	protected ApiTestKit $kit;

	/**
	 * The fake NetSuite.
	 */
	protected FakeNetSuiteServer $server;

	/**
	 * The connector as the engine sees it.
	 */
	protected GuardedConnector $connector;

	/**
	 * The private key (PEM) the merchant pastes.
	 */
	protected string $privateKey = '';

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeNetSuiteServer( $this->kit->http );
		$key          = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		openssl_pkey_export( $key, $this->privateKey );
		$this->server->publicKey = (string) openssl_pkey_get_details( $key )['key'];
		$this->server->addCustomer( 'existing@example.test', 'Existing', 'Buyer' );
		$this->connector = GuardedConnector::wrap( new NetSuiteConnector( $this->kit->deps ), $this->kit->tokens );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * A connection to the fake. The saved copy (which the token manager reads) is replaced only when the account, key or certificate changes,
	 * so a token survives from one call to the next.
	 *
	 * @param array<string,mixed>  $options Connection options.
	 * @param array<string,mixed>  $config  Config overrides.
	 * @param array<string,string> $secrets Secret overrides.
	 */
	protected function connection( array $options = array(), array $config = array(), array $secrets = array() ): Connection {
		$base = $config + array(
			'account_id'     => $this->server->account,
			'certificate_id' => $this->server->certificateId,
			'algorithm'      => 'ES256',
			'client_id'      => $this->server->clientId,
		);
		$keys = $secrets + array( 'private_key' => $this->privateKey );
		$row  = $this->kit->storage->rows[1] ?? null;
		$held = null === $row ? array() : array_diff_key( $row['secrets'], array_flip( array( 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_refresh_expires_at' ) ) );
		if ( null === $row || $row['config'] !== $base || $held !== $keys ) {
			$this->kit->storage->add( 1, 'netsuite', $base, $keys );
		}
		return new Connection( 1, 'netsuite', ConnectorType::Erp, 'NetSuite', ConnectionStatus::Active, $base + array( 'options' => $options ), array(), $keys );
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
	 * The internal id of a fake item by its item name.
	 *
	 * @param string $name Item name.
	 */
	protected function itemId( string $name ): string {
		foreach ( $this->server->items as $item ) {
			if ( $item['itemid'] === $name ) {
				return $item['id'];
			}
		}
		return '';
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
			'partner'    => array(
				'name'    => 'Noor Al-Mansouri',
				'email'   => 'noor@example.test',
				'street'  => 'Sheikh Zayed Road',
				'city'    => 'Dubai',
				'country' => 'AE',
				'phone'   => '+971501234567',
			),
			'delivery'   => array(
				'name'    => 'Noor Al-Mansouri',
				'street'  => 'Marina Walk 4',
				'city'    => 'Dubai',
				'country' => 'AE',
			),
			'order_line' => array(
				array(
					'product'    => $this->itemId( 'CS-MUG-01' ),
					'name'       => 'Ceramic mug, sand',
					'quantity'   => 3,
					'price_unit' => 1999,
				),
			),
		);
	}
}
