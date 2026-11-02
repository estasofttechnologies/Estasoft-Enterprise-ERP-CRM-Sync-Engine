<?php
/**
 * The connector contract rules, checked against the Qoyod connector and a fake Qoyod.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Connectors\Qoyod\QoyodConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeQoyodServer;

final class QoyodConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Qoyod.
	 */
	private FakeQoyodServer $server;

	/**
	 * Currency of the working connection's Qoyod account.
	 */
	private string $account = 'SAR';

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeQoyodServer();
		$this->server->install( $this->kit->http );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new QoyodConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'qoyod', array( 'currency' => $this->account ), array( 'api_key' => FakeQoyodServer::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'qoyod', array(), array( 'api_key' => 'qyd-wrong-key-0123456789' ), 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		$item     = new Item( 1, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', 'k' . $orderId ), ItemState::Syncing );
		return array(
			$item,
			array(
				'date'     => '2026-11-02',
				'currency' => (string) ( $options['currency'] ?? $this->account ),
				'number'   => $orderId,
				'total'    => $quantity * $unit,
				'customer' => array(
					'name'    => (string) ( $options['name'] ?? 'Noor Almansoori' ),
					'email'   => (string) ( $options['email'] ?? 'noor@example.test' ),
					'address' => (string) ( $options['street'] ?? 'Sheikh Zayed Road' ),
					'country' => 'SA',
				),
				'lines'    => array(
					array(
						'sku'      => 'CS-MUG-01',
						'item'     => '1',
						'name'     => 'Ceramic mug',
						'quantity' => $quantity,
						'total'    => $quantity * $unit,
						'tax_code' => '2',
					),
				),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( FakeQoyodServer::KEY, 'qyd-wrong-key-0123456789' );
	}

	protected function customerNameFrom( ExternalRef $ref ): string {
		return $ref->ref;
	}

	/**
	 * A Qoyod account works in one currency and the connector refuses an order in another instead of guessing, so the two exponents are
	 * checked on two accounts (an account in AED, then an account in KWD with three decimals).
	 */
	public function test_money_round_trips_by_the_currency_exponent_without_float_drift(): void {
		$connector = $this->connectorUnderTest();

		$this->account = 'AED';
		$this->server->currency = 'AED';
		list( $two, $payloadTwo ) = $this->orderFixture( '9701', array( 'unit_minor' => 1999, 'quantity' => 3 ) );
		$sent = $connector->send( $this->workingConnection(), $two, $payloadTwo );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 5997, $this->totalMinorOf( $sent->ref ), '3 x 19.99, two decimals, exactly.' );

		$this->account = 'KWD';
		$this->server->currency = 'KWD';
		list( $three, $payloadThree ) = $this->orderFixture( '9702', array( 'unit_minor' => 12345, 'quantity' => 3 ) );
		$sent = $connector->send( $this->workingConnection(), $three, $payloadThree );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 37035, $this->totalMinorOf( $sent->ref ), '3 x 12.345 KWD, three decimals, exactly.' );
		$this->assertSame( 37035, $this->totalMinorOf( $connector->findExisting( $this->workingConnection(), $three ) ) );
	}
}
