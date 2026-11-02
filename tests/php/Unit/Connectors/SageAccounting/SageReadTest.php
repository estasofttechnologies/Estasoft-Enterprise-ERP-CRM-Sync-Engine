<?php
/**
 * What the Sage connector reads: tax rates, products, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class SageReadTest extends SageTestCase {

	private function order( string $id, array $merge = array(), ?array $options = null ): ExternalRef {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		$result     = $this->connector->send( $connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		return $result->ref;
	}

	public function test_tax_rates_are_listed_without_the_retired_ones(): void {
		$codes = $this->connector->listTaxes( $this->connection );

		$this->assertSame( array( 'GB_STANDARD', 'GB_REDUCED', 'GB_ZERO', 'GB_NO_TAX' ), array_column( $codes, 'code' ) );
		$this->assertSame( 'Standard 20.00%', $codes[0]['label'] );
		$this->assertSame( 20.0, $codes[0]['rate_percent'] );
		$this->assertSame( 0.0, $codes[3]['rate_percent'] );
	}

	public function test_a_product_is_found_by_its_item_code_and_a_search_reads_code_and_description(): void {
		$this->sage->addProduct( 'CS-BOWL-02', array( 'description' => 'Bowl set' ) );

		$product = $this->connector->lookupProduct( $this->connection, 'cs-bowl-02' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-BOWL-02', $product->ref, 'The code case is Sage\'s.' );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'WC-RETIRED' ), 'A retired product is not offered.' );

		$byCode = $this->connector->searchProducts( $this->connection, 'bowl-02' );
		$byName = $this->connector->searchProducts( $this->connection, 'Bowl set' );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $byCode ) );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $byName ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
	}

	public function test_a_customer_is_found_by_email_through_the_search_and_an_exact_comparison(): void {
		$this->sage->addContact( 'Noor', 'noor@example.test' );
		$this->sage->addContact( 'Noor Two', 'someone-noor@example.test' );

		$found = $this->connector->lookupCustomer( $this->connection, 'NOOR@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Noor', $found->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, '' ) );
		$search = $this->sage->calls( 'GET', '/contacts' )[0];
		$this->assertSame( 'NOOR@example.test', $search->query['search'] );
		$this->assertSame( 'CUSTOMER', $search->query['contact_type_id'] );
	}

	public function test_stock_is_not_offered_and_the_flag_says_so(): void {
		$this->assertSame( array(), $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01' ), array() ) );
		$this->assertFalse( $this->connector->capabilities()[ \Estasoft\SyncEngine\Domain\Capability::StockIn->value ] );
	}

	public function test_invoice_facts_carry_the_number_status_and_date(): void {
		$ref = $this->order( '3001' );
		$this->assertSame( 'unpaid', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );

		$draft = $this->order( '3002', array(), array( 'documents' => 'invoice_draft' ) );
		$this->assertSame( 'draft', $this->connector->fetchInvoice( $this->connection, $draft )['status'] );

		$paid  = $this->order( '3003', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'bank-1200' ) );
		$facts = $this->connector->fetchInvoice( $this->connection, $paid );
		$this->assertSame( 'paid', $facts['status'] );
		$this->assertSame( $paid->ref, $facts['number'] );
		$this->assertSame( '2026-11-01', $facts['issued_at'] );
		$this->assertSame( '', $facts['pdf_url'] );

		$this->assertNull( $this->connector->fetchInvoice( $this->connection, new ExternalRef( 'si-99999', 'x' ) ) );
	}

	public function test_a_partly_paid_invoice_is_partial(): void {
		$ref = $this->order( '3004' );
		$this->sage->store['invoice'][ $ref->id ]['status'] = array( 'id' => 'PART_PAID' );

		$this->assertSame( 'partial', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );
	}

	public function test_the_nightly_list_holds_the_shops_invoices_from_the_day_on_with_their_refunds(): void {
		$one = $this->order( '3010' );
		$this->order( '3011', array( 'date_order' => '2026-10-01' ) );
		$this->sage->store['invoice']['si-990'] = array( 'id' => 'si-990', 'reference' => 'PO-77', 'status' => array( 'id' => 'UNPAID' ), 'date' => '2026-11-01', 'total_amount' => '5.00' );
		$this->sage->store['invoice']['si-991'] = array( 'id' => 'si-991', 'reference' => 'WC-3012', 'status' => array( 'id' => 'VOID' ), 'date' => '2026-11-01', 'total_amount' => '0.00' );
		$this->connector->send( $this->connection, $this->item( '3110', EntityType::Refund ), $this->refundPayload( '3010' ) );

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-25', new \DateTimeZone( 'UTC' ) ) );

		$this->assertSame( array( 'WC-3010' ), array_keys( $list ), 'Not the older invoice, not an invoice that is not the shop\'s, not a voided one.' );
		$this->assertSame( $one->id, $list['WC-3010']->id );
		$this->assertSame( 10080, $list['WC-3010']->extra['total_minor'] );
		$this->assertSame( 5040, $list['WC-3010']->extra['refunded_minor'] );
		$this->assertSame( '2026-10-25', $this->sage->calls( 'GET', '/sales_invoices' )[ count( $this->sage->calls( 'GET', '/sales_invoices' ) ) - 1 ]->query['from_date'] );
	}

	public function test_the_nightly_list_reads_every_page(): void {
		for ( $i = 1; $i <= 450; $i++ ) {
			$this->sage->store['invoice'][ 'p' . $i ] = array( 'id' => 'p' . $i, 'reference' => 'WC-' . ( 5000 + $i ), 'status' => array( 'id' => 'UNPAID' ), 'date' => '2026-11-01', 'total_amount' => '1.00' );
		}

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-11-01', new \DateTimeZone( 'UTC' ) ) );

		$this->assertCount( 450, $list );
		$pages = array_filter( $this->sage->calls( 'GET', '/sales_invoices' ), static fn( $r ): bool => isset( $r->query['from_date'] ) );
		$this->assertCount( 3, $pages );
	}

	public function test_a_customer_item_creates_a_contact_and_links_an_existing_one(): void {
		$item    = $this->item( '77', EntityType::Customer );
		$payload = array( 'partner' => array( 'name' => 'Sara Nasser', 'email' => 'sara@example.test', 'country_id' => 'GB' ) );

		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$created = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $created->isSuccess(), (string) $created->error?->message() );
		$this->assertFalse( $created->alreadyExisted );
		$this->assertSame( 'Sara Nasser', array_values( $this->sage->store['contact'] )[0]['name'] );

		$again = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted, 'A contact that has the email is linked, not copied.' );
		$this->assertCount( 1, $this->sage->store['contact'] );

		$noEmail = $this->connector->send( $this->connection, $this->item( '79', EntityType::Customer ), array( 'partner' => array( 'name' => 'X' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );
		$noName = $this->connector->send( $this->connection, $this->item( '80', EntityType::Customer ), array( 'partner' => array( 'email' => 'x@example.test' ) ) );
		$this->assertFalse( $noName->isSuccess() );
	}

	public function test_reads_return_an_error_and_do_not_throw_when_sage_is_down(): void {
		$this->kit->http->queue( FakeHttpClient::status( 503, '[{"$severity":"error","$dataCode":"Unavailable","$message":"unavailable"}]' ) );
		$result = $this->connector->listTaxes( $this->connection );

		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Net002, $result->code );
	}
}
