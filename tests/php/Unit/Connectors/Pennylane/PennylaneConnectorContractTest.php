<?php
/**
 * The connector contract and the shared API rules, for Pennylane.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Pennylane;

use Estasoft\SyncEngine\Connectors\Pennylane\PennylaneConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakePennylaneServer;

/**
 * The generic rules against a fake Pennylane. Pennylane invoices are in euro only, so the money rule is replaced by one that checks euro exactly and
 * the refusal of every other currency.
 */
final class PennylaneConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Pennylane.
	 */
	private FakePennylaneServer $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->fake = new FakePennylaneServer( $this->kit->http );
		$this->fake->addProduct( 'CS-MUG-01', 'Mug' );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new PennylaneConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'pennylane', array( 'token_lifetime' => '12' ), array( 'api_token' => FakePennylaneServer::TOKEN ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'pennylane', array( 'token_lifetime' => '12' ), array( 'api_token' => 'pl-wrong-token-00000000' ), 2 );
	}

	protected function secretValues(): array {
		return array( FakePennylaneServer::TOKEN, 'pl-wrong-token-00000000' );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->fake->failNextWrite( $kind, $seconds );
	}

	/**
	 * An order item and a payload as the mapping engine builds it. The default VAT code is `exempt`, so a total equals the net sum.
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
				'street'     => (string) ( $options['street'] ?? '1 rue de la Paix' ),
				'zip'        => '75002',
				'city'       => 'Paris',
				'country'    => 'FR',
			),
			'lines'    => array(
				array(
					'sku'      => 'CS-MUG-01',
					'name'     => 'Mug',
					'quantity' => $quantity,
					'total'    => $net,
					'tax_code' => (string) ( $options['tax_code'] ?? 'exempt' ),
				),
			),
		);
		$item = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'key-' . $orderId, ItemState::Syncing );
		return array( $item, $payload );
	}

	/**
	 * Replaces the base rule: euro round-trips exactly, any other currency is refused with SYNC-VAL-002 and nothing is sent.
	 */
	public function test_money_round_trips_by_the_currency_exponent_without_float_drift(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();

		list( $eur, $payloadEur ) = $this->orderFixture( '9701', array( 'unit_minor' => 1999, 'quantity' => 3 ) );
		$sent = $connector->send( $connection, $eur, $payloadEur );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 5997, $this->totalMinorOf( $sent->ref ), '3 x 19.99 EUR, exactly.' );
		$this->assertSame( 5997, $this->totalMinorOf( $connector->send( $connection, $eur, $payloadEur )->ref ) );

		foreach ( array( 'AED' => 1999, 'KWD' => 12345 ) as $currency => $unit ) {
			list( $item, $payload ) = $this->orderFixture( '97' . ( 'AED' === $currency ? '03' : '04' ), array( 'currency' => $currency, 'unit_minor' => $unit, 'quantity' => 3 ) );
			$result = $connector->send( $connection, $item, $payload );
			$this->assertFalse( $result->isSuccess(), $currency );
			$this->assertInstanceOf( ConnectorError::class, $result->error );
			$this->assertSame( ErrorCode::Val002, $result->error->code, $currency );
		}
		$this->assertCount( 1, $this->fake->server->requests( 'POST', '#/customer_invoices$#' ), 'Only the euro order made an invoice; the second send found it.' );
		$this->assertCount( 1, $this->fake->server->requests( 'POST', '#/individual_customers$#' ), 'The refused orders created no customer.' );
	}

	public function test_findexisting_returns_the_invoice_by_external_reference_with_the_total(): void {
		list( $item, $payload ) = $this->orderFixture( '9810' );
		$sent  = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );
		$found = $this->connectorUnderTest()->findExisting( $this->workingConnection(), $item );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
		$this->assertSame( 3998, $this->totalMinorOf( $found ) );
		$this->assertNull( $this->connectorUnderTest()->findExisting( $this->workingConnection(), new Item( 9811, 1, EntityType::Order, '9811', 'created', 'order:9811', 'key-9811', ItemState::Syncing ) ) );
	}

	public function test_a_customer_is_found_again_by_the_reference_that_was_made_for_the_email(): void {
		list( $item, $payload ) = $this->orderFixture( '9820', array( 'email' => 'Anna.X@Example.test' ) );
		$this->assertTrue( $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload )->isSuccess() );

		$ref = $this->connectorUnderTest()->lookupCustomer( $this->workingConnection(), 'anna.x@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( 'Anna Beispiel', $ref->ref );
		$this->assertNull( $this->connectorUnderTest()->lookupCustomer( $this->workingConnection(), 'nobody@example.test' ) );
	}
}
