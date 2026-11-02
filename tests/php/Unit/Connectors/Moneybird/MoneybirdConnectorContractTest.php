<?php
/**
 * The connector contract and the shared API rules, for Moneybird.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Moneybird;

use Estasoft\SyncEngine\Connectors\Moneybird\MoneybirdConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeMoneybirdServer;

/**
 * The generic rules against a fake Moneybird, including the money rule with two- and three-decimal currencies.
 */
final class MoneybirdConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Moneybird.
	 */
	private FakeMoneybirdServer $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->fake = new FakeMoneybirdServer( $this->kit->http );
		$this->fake->addProduct( 'CS-MUG-01', 'Mug' );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new MoneybirdConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'moneybird', array( 'administration_id' => FakeMoneybirdServer::ADMINISTRATION ), array( 'api_token' => FakeMoneybirdServer::TOKEN ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'moneybird', array( 'administration_id' => FakeMoneybirdServer::ADMINISTRATION ), array( 'api_token' => 'mb-wrong-token-00000000' ), 2 );
	}

	protected function secretValues(): array {
		return array( FakeMoneybirdServer::TOKEN, 'mb-wrong-token-00000000' );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->fake->failNextWrite( $kind, $seconds );
	}

	/**
	 * An order item and a payload as the mapping engine builds it. The default tax code is the 0 percent rate, so a total equals the net sum.
	 *
	 * @param string              $orderId WooCommerce order id.
	 * @param array<string,mixed> $options name, email, street, currency, quantity, unit_minor, tax_code.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	protected function orderFixture( string $orderId, array $options = array() ): array {
		$name     = (string) ( $options['name'] ?? 'Anna Beispiel' );
		$parts    = explode( ' ', $name, 2 );
		$quantity = (int) ( $options['quantity'] ?? 2 );
		$unit     = (int) ( $options['unit_minor'] ?? 1999 );
		$net      = $quantity * $unit;
		$payload  = array(
			'currency' => (string) ( $options['currency'] ?? 'EUR' ),
			'date'     => '2026-11-02 10:30:00',
			'total'    => $net,
			'customer' => array(
				'email'      => (string) ( $options['email'] ?? 'anna.' . $orderId . '@example.test' ),
				'first_name' => $parts[0],
				'last_name'  => $parts[1] ?? $parts[0],
				'street'     => (string) ( $options['street'] ?? 'Hoofdstraat 1' ),
				'zip'        => '1011 AB',
				'city'       => 'Amsterdam',
				'country'    => 'NL',
			),
			'lines'    => array(
				array(
					'sku'      => 'CS-MUG-01',
					'name'     => 'Mug',
					'quantity' => $quantity,
					'total'    => $net,
					'tax_code' => (string) ( $options['tax_code'] ?? '500' ),
				),
			),
		);
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'key-' . $orderId, ItemState::Syncing );
		return array( $item, $payload );
	}

	public function test_a_wrong_administration_number_is_refused_before_any_request_and_a_foreign_one_fails_the_test(): void {
		$bad = $this->kit->connection( 'moneybird', array( 'administration_id' => '12ab' ), array( 'api_token' => FakeMoneybirdServer::TOKEN ), 3 );
		$this->assertFalse( $this->connectorUnderTest()->test( $bad )->ok );
		$this->assertSame( 0, count( $this->kit->http->requests ) );

		$other  = $this->kit->connection( 'moneybird', array( 'administration_id' => '999' ), array( 'api_token' => FakeMoneybirdServer::TOKEN ), 4 );
		$result = $this->connectorUnderTest()->test( $other );
		$this->assertFalse( $result->ok );
		$this->assertNotNull( $result->error );
	}

	public function test_findexisting_returns_the_invoice_by_reference_with_the_number(): void {
		list( $item, $payload ) = $this->orderFixture( '9810' );
		$sent  = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );
		$found = $this->connectorUnderTest()->findExisting( $this->workingConnection(), $item );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
		$this->assertStringContainsString( '/find_by_reference/WC-9810.json', $this->kit->http->last()['url'] );
	}
}
