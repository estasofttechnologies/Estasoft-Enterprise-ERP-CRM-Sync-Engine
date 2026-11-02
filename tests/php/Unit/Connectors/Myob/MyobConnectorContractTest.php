<?php
/**
 * The MYOB connector against the connector contract.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Connectors\Myob\MyobConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeMyobServer;

final class MyobConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeMyobServer $myob;

	private ?Connection $working = null;

	protected function setUp(): void {
		parent::setUp();
		$this->myob    = new FakeMyobServer( $this->kit->http );
		$this->working = null;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new MyobConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		if ( null === $this->working ) {
			$config        = array(
				'client_id'         => FakeMyobServer::CLIENT_ID,
				'company_file_id'   => FakeMyobServer::FILE,
				'company_file_name' => 'Demo Trading Pty Ltd',
				'currency'          => 'AUD',
			);
			$this->working = $this->kit->connection( 'myob', $config, $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), 1 );
		}
		return $this->working;
	}

	protected function brokenConnection(): Connection {
		$config  = array(
			'client_id'       => FakeMyobServer::CLIENT_ID,
			'company_file_id' => FakeMyobServer::FILE,
			'currency'        => 'AUD',
		);
		$secrets = array(
			'client_secret'       => 'myob-wrong-secret-00000000',
			'oauth_access_token'  => 'myob-stale-access-token',
			'oauth_refresh_token' => 'myob-stale-refresh-token',
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 600 ),
		);
		return $this->kit->connection( 'myob', $config, $secrets, 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'wc-' . $orderId . '-created', ItemState::Syncing );
		$unit = (int) ( $options['unit_minor'] ?? 4200 );
		$qty  = (int) ( $options['quantity'] ?? 1 );
		return array(
			$item,
			array(
				'client_order_ref' => 'WC-' . $orderId,
				'currency'         => $options['currency'] ?? 'AUD',
				'date_order'       => '2026-11-01',
				'partner'          => array(
					'name'       => $options['name'] ?? 'Layla Haddad',
					'email'      => $options['email'] ?? 'layla@example.test',
					'street'     => $options['street'] ?? '1 Demo Street',
					'country_id' => 'AU',
				),
				'order_line'       => array(
					array(
						'product'    => 'CS-MUG-01',
						'name'       => 'Mug',
						'quantity'   => $qty,
						'price_unit' => $unit,
						'tax'        => 'FRE',
					),
				),
				'total'            => $unit * $qty,
			),
		);
	}

	/**
	 * The contract sends AED and KWD orders; a MYOB file works in one currency and the connector refuses the others. The same exponent check runs on a file
	 * whose own currency has three decimals.
	 */
	public function test_money_round_trips_by_the_currency_exponent_without_float_drift(): void {
		$connector = $this->connectorUnderTest();
		$aud       = $this->workingConnection();

		list( $two, $payloadTwo ) = $this->orderFixture( '9701', array( 'unit_minor' => 1999, 'quantity' => 3 ) );
		$sent                     = $connector->send( $aud, $two, $payloadTwo );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 5997, $this->totalMinorOf( $sent->ref ), '3 x 19.99, two decimals, exactly.' );

		$this->myob->currency = 'KWD';
		$config               = array(
			'client_id'       => FakeMyobServer::CLIENT_ID,
			'company_file_id' => FakeMyobServer::FILE,
			'currency'        => 'KWD',
		);
		$kwd                  = $this->kit->connection( 'myob', $config, $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), 7 );
		list( $three, $payloadThree ) = $this->orderFixture( '9702', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$sent                         = $connector->send( $kwd, $three, $payloadThree );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 37035, $this->totalMinorOf( $sent->ref ), '3 x 12.345 KWD, three decimals, exactly.' );
		$this->assertSame( 37035, $this->totalMinorOf( $connector->findExisting( $kwd, $three ) ) );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->myob->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		$values = array( FakeMyobServer::CLIENT_SECRET, 'myob-wrong-secret-00000000', 'myob-stale-access-token', 'myob-stale-refresh-token' );
		return array_merge( $values, array_keys( $this->myob->accessTokens ), array_keys( $this->myob->refreshTokens ) );
	}
}
