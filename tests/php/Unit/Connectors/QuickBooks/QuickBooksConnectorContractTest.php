<?php
/**
 * The QuickBooks connector against the connector contract.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeQuickBooksServer;

final class QuickBooksConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeQuickBooksServer $qbo;

	private ?Connection $working = null;

	protected function setUp(): void {
		parent::setUp();
		$this->qbo     = new FakeQuickBooksServer( $this->kit->http );
		$this->working = null;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new QuickBooksConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		if ( null === $this->working ) {
			$config        = array(
				'client_id'    => FakeQuickBooksServer::CLIENT_ID,
				'realm_id'     => FakeQuickBooksServer::REALM,
				'environment'  => 'production',
				'company_name' => 'Demo Trading Ltd',
			);
			$this->working = $this->kit->connection( 'quickbooks', $config, $this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() ), 1 );
		}
		return $this->working;
	}

	protected function brokenConnection(): Connection {
		$config  = array(
			'client_id'   => FakeQuickBooksServer::CLIENT_ID,
			'realm_id'    => FakeQuickBooksServer::REALM,
			'environment' => 'production',
		);
		$secrets = array(
			'client_secret'       => 'qbo-wrong-secret-00000000',
			'oauth_access_token'  => 'qbo-stale-access-token',
			'oauth_refresh_token' => 'qbo-stale-refresh-token',
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 600 ),
		);
		return $this->kit->connection( 'quickbooks', $config, $secrets, 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'wc-' . $orderId . '-created', ItemState::Syncing );
		$unit = (int) ( $options['unit_minor'] ?? 4200 );
		$qty  = (int) ( $options['quantity'] ?? 1 );
		return array(
			$item,
			array(
				'client_order_ref' => 'WC-' . $orderId,
				'currency'         => $options['currency'] ?? 'GBP',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => $options['name'] ?? 'Layla Haddad',
					'email'      => $options['email'] ?? 'layla@example.test',
					'street'     => $options['street'] ?? '1 Demo Street',
					'country_id' => 'GB',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => $qty,
						'price_unit' => $unit,
						'tax'        => '6',
					),
				),
				'total'            => $unit * $qty,
			),
		);
	}

	public function test_ping_is_one_fresh_call_every_time_and_false_when_the_vendor_refuses(): void {
		$connector  = $this->apiConnector();
		$connection = $this->workingConnection();

		$this->assertTrue( $connector->ping( $connection ) );
		$this->assertCount( 1, $this->kit->http->requests, 'The probe is one request, not the several of a connection test.' );
		$this->assertSame( 'GET', $this->kit->http->requests[0]['method'] );
		$this->assertStringContainsString( 'companyinfo', $this->kit->http->requests[0]['url'] );

		$this->assertTrue( $connector->ping( $connection ) );
		$this->assertCount( 2, $this->kit->http->requests, 'A second probe is a second request: nothing is answered from what an earlier call cached.' );

		$this->assertFalse( $connector->ping( $this->brokenConnection() ) );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->qbo->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		$values = array( FakeQuickBooksServer::CLIENT_SECRET, 'qbo-wrong-secret-00000000', 'qbo-stale-access-token', 'qbo-stale-refresh-token' );
		return array_merge( $values, array_keys( $this->qbo->accessTokens ), array_keys( $this->qbo->refreshTokens ) );
	}
}
