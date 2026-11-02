<?php
/**
 * What the Xero connector reads: tax rates, products, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class XeroReadTest extends XeroTestCase {

	private function order( string $id, array $merge = array() ): ExternalRef {
		$result = $this->connector->send( $this->connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		return $result->ref;
	}

	public function test_tax_codes_are_the_active_rates_that_can_be_used_on_sales(): void {
		$codes = $this->connector->listTaxes( $this->connection );

		$this->assertSame( array( 'OUTPUT', 'OUTPUT2', 'NONE' ), array_column( $codes, 'code' ), 'No archived rate and no purchases-only rate.' );
		$this->assertSame( 'VAT 5%', $codes[0]['label'] );
		$this->assertSame( 5.0, $codes[0]['rate_percent'] );
		$this->assertSame( 'Status=="ACTIVE"', $this->xero->calls( 'GET', '/TaxRates' )[0]->query['where'] );
	}

	public function test_a_product_is_found_by_its_code_and_a_search_reads_the_whole_item_list(): void {
		$this->xero->addItem( 'CS-BOWL-02', array( 'Name' => 'Bowl set' ) );

		$product = $this->connector->lookupProduct( $this->connection, 'cs-bowl-02' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-BOWL-02', $product->ref, 'The code case is Xero\'s.' );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );

		$found = $this->connector->searchProducts( $this->connection, 'bowl' );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $found ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
	}

	public function test_a_code_with_a_quote_cannot_be_searched_and_is_not_found(): void {
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'A"B' ) );
		$this->assertSame( array(), $this->xero->calls( 'GET', '/Items' ), 'No request is made with a character that would break the filter.' );
	}

	public function test_a_customer_is_found_by_email_ignoring_case(): void {
		$this->xero->addContact( 'Noor', 'noor@example.test' );
		$found = $this->connector->lookupCustomer( $this->connection, 'NOOR@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Noor', $found->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
	}

	public function test_a_customer_is_found_on_the_second_page_of_matches(): void {
		for ( $i = 1; $i <= 120; $i++ ) {
			$this->xero->addContact( 'Match ' . $i, sprintf( 'x%03dmatch@example.test', $i ) );
		}
		$this->xero->addContact( 'Target', 'match@example.test' );
		for ( $i = 1; $i <= 120; $i++ ) {
			$this->xero->addContact( 'More ' . $i, sprintf( 'y%03dmatch@example.test', $i ) );
		}

		$found = $this->connector->lookupCustomer( $this->connection, 'match@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Target', $found->ref );
		$this->assertGreaterThanOrEqual( 2, count( $this->xero->calls( 'GET', '/Contacts' ) ), 'The match was on the second page.' );
	}

	public function test_stock_is_the_quantity_on_hand_of_tracked_items_only(): void {
		$this->xero->addItem( 'CS-BOWL-02', array( 'IsTrackedAsInventory' => true, 'QuantityOnHand' => 7.9 ) );
		$this->xero->addItem( 'CS-SERVICE', array( 'IsTrackedAsInventory' => false ) );
		$this->xero->addItem( 'CS-NEG', array( 'IsTrackedAsInventory' => true, 'QuantityOnHand' => -3.0 ) );

		$stock = $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01', 'CS-BOWL-02', 'CS-SERVICE', 'CS-NEG', 'GONE' ), array( 'ignored' ) );

		$this->assertSame(
			array(
				'CS-MUG-01'  => array( 'quantity' => 40, 'held' => 0 ),
				'CS-BOWL-02' => array( 'quantity' => 7, 'held' => 0 ),
				'CS-NEG'     => array( 'quantity' => 0, 'held' => 0 ),
			),
			$stock
		);
	}

	public function test_invoice_facts_carry_the_number_status_date_and_online_link_of_an_approved_invoice(): void {
		$ref   = $this->order( '3001' );
		$facts = $this->connector->fetchInvoice( $this->connection, $ref );

		$this->assertSame( $ref->ref, $facts['number'] );
		$this->assertSame( 'authorised', $facts['status'] );
		$this->assertSame( '', $facts['authority_status'], 'Xero has no tax authority step in its API.' );
		$this->assertSame( 'https://in.xero.test/online/' . $ref->id, $facts['pdf_url'] );
		$this->assertSame( '2026-11-01T00:00:00', $facts['issued_at'] );

		$draft = $this->connector->send( $this->connect( array( 'documents' => 'invoice' ) ), $this->item( '3002' ), $this->payload( '3002' ) )->ref;
		$this->assertSame( '', $this->connector->fetchInvoice( $this->connection, $draft )['pdf_url'], 'A draft has no online link.' );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection, new ExternalRef( '00000000-0000-4000-8000-999999999999' ) ) );
	}

	public function test_the_nightly_list_is_filtered_on_the_server_and_paged(): void {
		$this->order( '3010' );
		$this->order( '3011' );
		$this->xero->invoices['manual'] = $this->xero->invoiceByReference( 'WC-3010' );
		$this->xero->invoices['manual']['InvoiceID'] = 'manual';
		$this->xero->invoices['manual']['Reference'] = 'MANUAL-1';
		$old = $this->xero->invoiceByReference( 'WC-3011' );
		$this->xero->invoices['old'] = array( 'InvoiceID' => 'old', 'Reference' => 'WC-3000', 'DateString' => '2025-01-01T00:00:00', 'Status' => 'AUTHORISED' ) + $old;
		$this->xero->invoices['void'] = array( 'InvoiceID' => 'void', 'Reference' => 'WC-3012', 'Status' => 'VOIDED' ) + $old;

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );

		$this->assertSame( array( 'WC-3010', 'WC-3011' ), array_keys( $list ) );
		$this->assertSame( 8820, $list['WC-3010']->extra['total_minor'] );
		$this->assertSame( 0, $list['WC-3010']->extra['refunded_minor'] );
		$this->assertArrayHasKey( 'email', $list['WC-3010']->extra );
		$where = $this->xero->calls( 'GET', '/Invoices' );
		$this->assertStringContainsString( 'Reference.StartsWith("WC-")', end( $where )->query['where'] );
		$this->assertStringContainsString( 'Date>=DateTime(2026, 10, 01)', end( $where )->query['where'] );
	}

	public function test_the_nightly_list_reads_every_page(): void {
		$this->order( '3020' );
		$base = $this->xero->invoiceByReference( 'WC-3020' );
		for ( $i = 1; $i <= 130; $i++ ) {
			$id                         = 'bulk' . $i;
			$this->xero->invoices[ $id ] = array( 'InvoiceID' => $id, 'Reference' => 'WC-' . ( 4000 + $i ) ) + $base;
		}

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );

		$this->assertCount( 131, $list );
		$this->assertGreaterThanOrEqual( 2, count( $this->xero->calls( 'GET', '/Invoices' ) ) );
	}

	public function test_a_customer_item_creates_a_contact_with_the_shops_reference_and_links_an_existing_one(): void {
		$item    = $this->item( '77', \Estasoft\SyncEngine\Domain\EntityType::Customer );
		$payload = array( 'partner' => array( 'name' => 'Sara Nasser', 'email' => 'sara@example.test', 'country_id' => 'AE' ) );

		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$created = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $created->isSuccess(), (string) $created->error?->message() );
		$this->assertFalse( $created->alreadyExisted );
		$this->assertSame( 'WC-77', array_values( $this->xero->contacts )[0]['ContactNumber'] );

		$again = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->xero->contacts );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );

		$known = $this->connector->send( $this->connection, $this->item( '78', \Estasoft\SyncEngine\Domain\EntityType::Customer ), $payload );
		$this->assertTrue( $known->alreadyExisted, 'A contact that has the email is linked, not copied.' );
		$this->assertCount( 1, $this->xero->contacts );

		$noEmail = $this->connector->send( $this->connection, $this->item( '79', \Estasoft\SyncEngine\Domain\EntityType::Customer ), array( 'partner' => array( 'name' => 'X' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );
	}

	public function test_reads_return_an_error_and_do_not_throw_when_xero_is_down(): void {
		$this->kit->http->queue( \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::status( 503, '{"Message":"unavailable"}' ) );
		$result = $this->connector->listTaxes( $this->connection );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Net002, $result->code );
	}
}
