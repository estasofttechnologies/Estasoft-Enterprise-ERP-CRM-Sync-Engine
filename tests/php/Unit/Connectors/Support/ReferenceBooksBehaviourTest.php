<?php
/**
 * Behaviour the shared base gives a key-based connector, shown with the reference connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksVendor;
use PHPUnit\Framework\TestCase;

final class ReferenceBooksBehaviourTest extends TestCase {

	private ApiTestKit $kit;

	private ReferenceBooksVendor $vendor;

	private ReferenceBooksConnector $raw;

	private GuardedConnector $connector;

	protected function setUp(): void {
		$this->kit       = ApiTestKit::make();
		$this->vendor    = new ReferenceBooksVendor( $this->kit->http );
		$this->raw       = new ReferenceBooksConnector( $this->kit->deps );
		$this->connector = GuardedConnector::wrap( $this->raw );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connection( string $key = ReferenceBooksVendor::KEY ): Connection {
		return new Connection( 1, 'reference-books', ConnectorType::Accounting, 'Acme Books', ConnectionStatus::Active, array( 'tenant' => 'tenant-77' ), array(), array( 'api_key' => $key ) );
	}

	private function item( string $orderId ): Item {
		return new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'wc-' . $orderId . '-created', ItemState::Syncing );
	}

	/**
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @return array<string,mixed>
	 */
	private function payload( string $orderId, array $lines = array() ): array {
		return array(
			'invoice' => array( 'reference' => 'WC-' . $orderId, 'currency' => 'AED' ),
			'contact' => array( 'name' => 'Layla Haddad', 'email' => 'Layla@Example.test' ),
			'lines'   => array() === $lines ? array( array( 'sku' => 'CS-MUG-01', 'quantity' => 2, 'unit_price' => 4200 ) ) : $lines,
		);
	}

	public function test_create_order_sends_the_reference_money_as_decimal_strings_and_an_idempotency_key(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '501' ), $this->payload( '501' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 8400, $result->ref->extra['total_minor'] );
		$request = $this->vendor->server->requests( 'POST', '#^/v1/invoices$#' )[0];
		$body    = $request->json();
		$this->assertSame( 'WC-501', $body['reference'] );
		$this->assertSame( '42.00', $body['lines'][0]['unit_price'], 'Minor units become a decimal string at the edge.' );
		$this->assertSame( 'wc-501-created', $request->header( 'Idempotency-Key' ) );
		$this->assertSame( 'layla@example.test', strtolower( $this->vendor->contacts[0]['email'] ) );
	}

	public function test_find_before_create_links_instead_of_duplicating_even_when_the_match_is_on_a_later_page(): void {
		foreach ( array( '1', '2', '3', '4', '5' ) as $n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
		}
		$before = count( $this->vendor->invoices );

		$again = $this->connector->send( $this->connection(), $this->item( '5' ), $this->payload( '5' ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $before, count( $this->vendor->invoices ) );
		$this->assertCount( $before, array_unique( array_column( $this->vendor->invoices, 'id' ) ) );
		$this->assertSame( 0, count( array_filter( $this->vendor->server->requests( 'POST', '#invoices#' ), fn( $r ) => 'WC-5' === $r->json()['reference'] && $r !== $this->vendor->server->requests( 'POST', '#invoices#' )[4] ) ) );
	}

	public function test_a_contact_is_found_by_email_regardless_of_case_and_created_once(): void {
		$this->connector->send( $this->connection(), $this->item( '601' ), $this->payload( '601' ) );
		$second = $this->payload( '602' );
		$second['contact']['email'] = 'LAYLA@EXAMPLE.TEST';
		$this->connector->send( $this->connection(), $this->item( '602' ), $second );
		$this->assertCount( 1, $this->vendor->contacts );
	}

	public function test_an_unknown_sku_is_map_001_and_nothing_is_created(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '701' ), $this->payload( '701', array( array( 'sku' => 'NOPE-1', 'quantity' => 1, 'unit_price' => 100 ) ) ) );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'NOPE-1', $result->error->context['sku'] );
		$this->assertSame( array(), $this->vendor->invoices );
	}

	public function test_the_plan_gate_and_a_validation_error_use_the_vendors_own_codes(): void {
		$this->vendor->planAllowsWrites = false;
		$gated = $this->connector->send( $this->connection(), $this->item( '801' ), $this->payload( '801' ) );
		$this->assertSame( ErrorCode::Auth006, $gated->error->code, 'AUTH-006 comes from the vendor code, not from the word plan.' );
		$this->assertSame( 'Acme Books', $gated->error->context['system'] );
		$test = $this->connector->test( $this->connection() );
		$this->assertTrue( $test->ok );
		$this->assertSame( array( 'create_invoices' => false ), $test->permissions );
		$this->assertSame( array( 'create_invoices' ), $test->missingPermissions() );
	}

	public function test_a_rate_limit_carries_the_vendors_wait_and_a_retry_succeeds_once(): void {
		$this->vendor->failNextWrite( 'rate_limit', 42 );
		$first = $this->connector->send( $this->connection(), $this->item( '901' ), $this->payload( '901' ) );
		$this->assertSame( ErrorCode::Rate001, $first->error->code );
		$this->assertSame( 42, $first->error->retryAfter );
		$this->assertSame( array(), $this->vendor->invoices );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '901' ), $this->payload( '901' ) )->isSuccess() );
		$this->assertCount( 1, $this->vendor->invoices );
	}

	public function test_a_wrong_total_from_the_vendor_is_refused_not_synced(): void {
		$this->kit->http->route( 'POST', '#/v1/invoices$#', static fn() => FakeHttpClient::json( array( 'id' => 'x', 'number' => 'N', 'reference' => 'WC-1001', 'currency' => 'AED', 'total' => '83.99', 'status' => 'draft' ), 201 ) );
		$result = $this->connector->send( $this->connection(), $this->item( '1001' ), $this->payload( '1001' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
	}

	public function test_secrets_and_hosts_never_appear_in_errors_or_logs(): void {
		$this->kit->http->route( 'GET', '#/v1/company$#', static fn() => FakeHttpClient::status( 400, '{"message":"Bad request from key ' . ReferenceBooksVendor::KEY . ' on api.acme-books.test for tenant-77"}' ) );
		$test = $this->connector->test( $this->connection() );
		$text = $test->error->message() . '|' . $test->error->detail . '|' . wp_json_encode( $test->error->context );
		$this->assertStringNotContainsString( ReferenceBooksVendor::KEY, $text );
		$this->assertStringNotContainsString( 'acme-books.test', $text );
		$this->assertStringNotContainsString( 'tenant-77', $text );
		$this->assertStringNotContainsString( ReferenceBooksVendor::KEY, print_r( $this->connection(), true ) );
	}

	public function test_without_the_guard_test_still_returns_a_result_and_other_methods_throw_the_api_exception(): void {
		$result = $this->raw->test( $this->connection( 'wrong-key-123456' ) );
		$this->assertFalse( $result->ok, 'test() turns a failure into a result even without the guard.' );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );

		$this->expectException( ApiException::class );
		$this->raw->lookupCustomer( $this->connection( 'wrong-key-123456' ), 'a@example.test' );
	}

	public function test_it_never_calls_a_host_it_did_not_declare(): void {
		$connector = new class( $this->kit->deps ) extends ReferenceBooksConnector {
			protected function baseUrl( Connection $connection ): string {
				return 'https://evil.example.test/v1';
			}
		};
		$result = GuardedConnector::wrap( $connector )->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_tax_codes_reach_the_rest_layer_through_list_taxes(): void {
		$this->assertSame( array( array( 'code' => 'AE5', 'name' => 'VAT 5%', 'rate_percent' => '5.0' ), array( 'code' => 'AE0', 'name' => 'Zero rated', 'rate_percent' => '0' ) ), $this->raw->listTaxCodes( $this->connection() ) );
		$taxes = $this->connector->listTaxes( $this->connection() );
		$this->assertSame( array( 'code' => 'AE5', 'label' => 'VAT 5%', 'rate_percent' => 5.0 ), $taxes[0] );
		$this->assertTrue( method_exists( $this->connector, 'listTaxes' ) );
	}

	public function test_reconciliation_pages_through_every_invoice_and_reads_back_the_status(): void {
		foreach ( array( '1', '2', '3', '4', '5' ) as $n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
		}
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-1', 'WC-2', 'WC-3', 'WC-4', 'WC-5' ), array_keys( $list ) );
		$this->assertSame( 8400, $list['WC-3']->extra['total_minor'] );
		$this->assertSame( 'layla@example.test', strtolower( $list['WC-3']->extra['email'] ) );

		$invoice = $this->connector->fetchInvoice( $this->connection(), $list['WC-2'] );
		$this->assertSame( 'draft', $invoice['status'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( 'inv-999' ) ), '404 is a normal answer here.' );
	}

	public function test_credential_form_for_a_key_connector(): void {
		$fields = $this->connector->credentialFields();
		$this->assertCount( 1, $fields );
		$this->assertSame( 'api_key', $fields[0]->name );
		$this->assertTrue( $fields[0]->secret );
		$this->assertSame( array( 'requests_per_second' => 5.0, 'concurrency' => 1 ), $this->connector->rateLimitHints() );
		$this->assertCount( 8, $this->connector->describeFields( 'orders' ) );
	}
}
