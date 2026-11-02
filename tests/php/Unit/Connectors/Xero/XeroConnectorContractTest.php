<?php
/**
 * The Xero connector against the connector contract.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Xero\XeroConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeXeroServer;

final class XeroConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeXeroServer $xero;

	private ?Connection $working = null;

	protected function setUp(): void {
		parent::setUp();
		$this->xero    = new FakeXeroServer( $this->kit->http );
		$this->working = null;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new XeroConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		if ( null === $this->working ) {
			$config        = array(
				'client_id'         => FakeXeroServer::CLIENT_ID,
				'tenant_id'         => FakeXeroServer::TENANT,
				'organisation_name' => 'Demo Trading LLC',
			);
			$this->working = $this->kit->connection( 'xero', $config, $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() ), 1 );
		}
		return $this->working;
	}

	protected function brokenConnection(): Connection {
		$config  = array(
			'client_id' => FakeXeroServer::CLIENT_ID,
			'tenant_id' => FakeXeroServer::TENANT,
		);
		$secrets = array(
			'client_secret'       => 'xero-wrong-secret-00000000',
			'oauth_access_token'  => 'xero-stale-access-token',
			'oauth_refresh_token' => 'xero-stale-refresh-token',
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 600 ),
		);
		return $this->kit->connection( 'xero', $config, $secrets, 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'wc-' . $orderId . '-created', ItemState::Syncing );
		$unit = (int) ( $options['unit_minor'] ?? 4200 );
		$qty  = (int) ( $options['quantity'] ?? 1 );
		return array(
			$item,
			array(
				'client_order_ref' => 'WC-' . $orderId,
				'currency'         => $options['currency'] ?? 'AED',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => $options['name'] ?? 'Layla Haddad',
					'email'      => $options['email'] ?? 'layla@example.test',
					'street'     => $options['street'] ?? 'Sheikh Zayed Road',
					'country_id' => 'AE',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => $qty,
						'price_unit' => $unit,
						'tax'        => 'NONE',
					),
				),
				'total'            => $unit * $qty,
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->xero->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		$values = array( FakeXeroServer::CLIENT_SECRET, 'xero-wrong-secret-00000000', 'xero-stale-access-token', 'xero-stale-refresh-token' );
		return array_merge( $values, array_keys( $this->xero->accessTokens ), array_keys( $this->xero->refreshTokens ) );
	}
}
