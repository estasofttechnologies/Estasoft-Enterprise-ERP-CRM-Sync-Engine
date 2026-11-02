<?php
/**
 * The connector contract and the shared API rules, for Lexware Office.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\LexwareOffice;

use Estasoft\SyncEngine\Connectors\LexwareOffice\LexwareOfficeConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeLexwareOfficeServer;

/**
 * The generic rules against a fake Lexware Office. The money rule of the base is replaced: Lexware accepts euro only, so the test proves exact
 * euro totals and that other currencies (two and three decimals) are refused before anything is sent.
 */
final class LexwareOfficeConnectorContractTest extends ApiConnectorContractTestCase {

	/**
	 * The fake Lexware Office.
	 */
	private FakeLexwareOfficeServer $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->fake = new FakeLexwareOfficeServer( $this->kit->http );
		$this->fake->addArticle( 'CS-MUG-01', 'Mug' );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new LexwareOfficeConnector( $this->kit->deps );
	}

	protected function workingConnection(): Connection {
		return $this->kit->connection( 'lexware-office', array(), array( 'api_key' => FakeLexwareOfficeServer::KEY ) );
	}

	protected function brokenConnection(): Connection {
		return $this->kit->connection( 'lexware-office', array(), array( 'api_key' => 'lx-wrong-key-7d1e0000-aaaa-bbbb-cccc-123456789abc' ), 2 );
	}

	protected function secretValues(): array {
		return array( FakeLexwareOfficeServer::KEY, 'lx-wrong-key-7d1e0000-aaaa-bbbb-cccc-123456789abc' );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->fake->failNextWrite( $kind, $seconds );
	}

	/**
	 * An order item and a payload as the mapping engine builds it.
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
		$tax      = (string) ( $options['tax_code'] ?? '0' );
		$net      = $quantity * $unit;
		$payload  = array(
			'currency' => (string) ( $options['currency'] ?? 'EUR' ),
			'date'     => '2026-11-02 10:30:00',
			'total'    => $net,
			'customer' => array(
				'email'      => (string) ( $options['email'] ?? 'anna.' . $orderId . '@example.test' ),
				'first_name' => $parts[0],
				'last_name'  => $parts[1] ?? $parts[0],
				'street'     => (string) ( $options['street'] ?? 'Musterstraße 1' ),
				'zip'        => '10115',
				'city'       => 'Berlin',
				'country'    => 'DE',
			),
			'lines'    => array(
				array(
					'sku'      => 'CS-MUG-01',
					'name'     => 'Mug',
					'quantity' => $quantity,
					'total'    => $net,
					'tax_code' => $tax,
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
		$this->assertCount( 1, $this->fake->server->requests( 'POST', '#^/v1/invoices$#' ), 'Only the euro order made an invoice; the second send found it.' );
		$this->assertCount( 1, $this->fake->server->requests( 'POST', '#^/v1/contacts$#' ), 'The refused orders created no contact.' );
	}
}
