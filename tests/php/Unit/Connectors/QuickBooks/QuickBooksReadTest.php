<?php
/**
 * What the QuickBooks connector reads: tax codes, products, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class QuickBooksReadTest extends QuickBooksTestCase {

	private function order( string $id, array $merge = array(), ?array $options = null ): ExternalRef {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		$result     = $this->connector->send( $connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		return $result->ref;
	}

	public function test_tax_codes_are_the_active_codes_with_the_sum_of_their_rates(): void {
		$codes = $this->connector->listTaxes( $this->connection );

		$this->assertSame( array( '5', '4', '6', 'NON', 'TAX' ), array_column( $codes, 'code' ), 'The inactive code is not listed.' );
		$this->assertSame( '20.0 % S', str_replace( '.0% S', '.0 % S', $codes[0]['label'] ) );
		$this->assertSame( 20.0, $codes[0]['rate_percent'] );
		$this->assertSame( 0.0, $codes[3]['rate_percent'] );
	}

	public function test_a_product_is_found_by_its_sku_and_a_search_looks_at_sku_and_name(): void {
		$this->qbo->addItem( 'CS-BOWL-02', array( 'Name' => 'Bowl set' ) );

		$product = $this->connector->lookupProduct( $this->connection, 'CS-BOWL-02' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-BOWL-02', $product->ref );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );

		$bySku  = $this->connector->searchProducts( $this->connection, 'bowl-02' );
		$byName = $this->connector->searchProducts( $this->connection, 'Bowl' );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $bySku ) );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $byName ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
	}

	public function test_a_sku_with_a_quote_is_escaped_in_the_query(): void {
		$this->assertNull( $this->connector->lookupProduct( $this->connection, "A'B" ) );

		$query = $this->qbo->calls( 'GET', '/query' )[0]->query['query'];
		$this->assertStringContainsString( "Sku in ('A\\'B')", $query );
	}

	public function test_a_customer_is_found_by_email_ignoring_case(): void {
		$this->qbo->addCustomer( 'Noor', 'noor@example.test' );
		$found = $this->connector->lookupCustomer( $this->connection, 'NOOR@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Noor', $found->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, '' ) );
	}

	public function test_a_query_that_quickbooks_cannot_run_on_the_email_falls_back_to_the_display_name(): void {
		$this->qbo->addCustomer( 'noor@example.test', 'noor@example.test' );
		$this->kit->http->queue( FakeHttpClient::json( array( 'Fault' => array( 'Error' => array( array( 'Message' => 'QueryValidationError: property PrimaryEmailAddr is not queryable', 'code' => '4000' ) ), 'type' => 'ValidationFault' ) ), 400 ) );

		$found = $this->connector->lookupCustomer( $this->connection, 'noor@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
	}

	public function test_stock_is_the_quantity_on_hand_of_tracked_items_only(): void {
		$this->qbo->addItem( 'CS-FRAME-03', array( 'Name' => 'Frame', 'Type' => 'Inventory', 'TrackQtyOnHand' => true, 'QtyOnHand' => 7.0 ) );
		$this->qbo->addItem( 'CS-NEG-04', array( 'Name' => 'Oversold', 'Type' => 'Inventory', 'TrackQtyOnHand' => true, 'QtyOnHand' => -3.0 ) );

		$stock = $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01', 'CS-FRAME-03', 'CS-NEG-04', 'WC-SERVICE', 'NO-SUCH' ), array() );

		$this->assertSame(
			array(
				'CS-MUG-01'   => array( 'quantity' => 40, 'held' => 0 ),
				'CS-FRAME-03' => array( 'quantity' => 7, 'held' => 0 ),
				'CS-NEG-04'   => array( 'quantity' => 0, 'held' => 0 ),
			),
			$stock,
			'A service and an unknown SKU have no stock; a negative quantity is zero.'
		);
	}

	public function test_invoice_facts_carry_the_number_status_and_date(): void {
		$ref = $this->order( '3001', array(), array( 'documents' => 'invoice_paid' ) );
		$this->assertSame( 'unpaid', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );

		$paid = $this->order( '3002', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid' ) );
		$facts = $this->connector->fetchInvoice( $this->connection, $paid );
		$this->assertSame( 'WC-3002', $facts['number'] );
		$this->assertSame( 'paid', $facts['status'] );
		$this->assertSame( '2026-11-01', $facts['issued_at'] );
		$this->assertSame( '', $facts['pdf_url'], 'QuickBooks gives no link a customer can open.' );

		$this->assertNull( $this->connector->fetchInvoice( $this->connection, new ExternalRef( '99999', 'WC-1' ) ) );
	}

	public function test_a_partly_paid_invoice_is_partial(): void {
		$ref = $this->order( '3003' );
		$this->qbo->store['Invoice'][ $ref->id ]['Balance'] = 40.0;

		$this->assertSame( 'partial', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );
	}

	public function test_the_nightly_list_holds_the_shops_invoices_from_the_day_on_with_their_refunds(): void {
		$one = $this->order( '3010' );
		$this->order( '3011', array( 'date_order' => '2026-10-01' ) );
		$this->qbo->store['Invoice']['990'] = array( 'Id' => '990', 'SyncToken' => '0', 'DocNumber' => 'INV-77', 'TxnDate' => '2026-11-01', 'TotalAmt' => 5.0, 'CurrencyRef' => array( 'value' => 'GBP' ) );
		$this->connector->send( $this->connection, $this->item( '3110', EntityType::Refund ), $this->refundPayload( '3010' ) );

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-25', new \DateTimeZone( 'UTC' ) ) );

		$this->assertSame( array( 'WC-3010' ), array_keys( $list ), 'Not the older invoice, not an invoice that is not the shop\'s.' );
		$this->assertSame( $one->id, $list['WC-3010']->id );
		$this->assertSame( 10080, $list['WC-3010']->extra['total_minor'] );
		$this->assertSame( 5040, $list['WC-3010']->extra['refunded_minor'] );
		$this->assertSame( 'layla@example.test', $list['WC-3010']->extra['email'] );
	}

	public function test_the_nightly_list_reads_every_page(): void {
		for ( $i = 1; $i <= 230; $i++ ) {
			$this->qbo->store['Invoice'][ 'p' . $i ] = array( 'Id' => 'p' . $i, 'SyncToken' => '0', 'DocNumber' => 'WC-' . ( 5000 + $i ), 'TxnDate' => '2026-11-01', 'TotalAmt' => 1.0, 'CurrencyRef' => array( 'value' => 'GBP' ) );
		}

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-11-01', new \DateTimeZone( 'UTC' ) ) );

		$this->assertCount( 230, $list );
		$pages = array_filter( $this->qbo->calls( 'GET', '/query' ), static fn( $r ): bool => false !== strpos( (string) $r->query['query'], 'from Invoice' ) );
		$this->assertCount( 3, $pages );
	}

	public function test_a_customer_item_creates_a_customer_and_links_an_existing_one(): void {
		$item    = $this->item( '77', EntityType::Customer );
		$payload = array( 'partner' => array( 'name' => 'Sara Nasser', 'email' => 'sara@example.test', 'country_id' => 'GB' ) );

		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$created = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $created->isSuccess(), (string) $created->error?->message() );
		$this->assertFalse( $created->alreadyExisted );
		$this->assertSame( 'Sara Nasser', array_values( $this->qbo->store['Customer'] )[0]['DisplayName'] );

		$again = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted, 'A customer that has the email is linked, not copied.' );
		$this->assertCount( 1, $this->qbo->store['Customer'] );

		$noEmail = $this->connector->send( $this->connection, $this->item( '79', EntityType::Customer ), array( 'partner' => array( 'name' => 'X' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );
		$noName = $this->connector->send( $this->connection, $this->item( '80', EntityType::Customer ), array( 'partner' => array( 'email' => 'x@example.test' ) ) );
		$this->assertFalse( $noName->isSuccess() );
	}

	public function test_reads_return_an_error_and_do_not_throw_when_quickbooks_is_down(): void {
		$this->kit->http->queue( FakeHttpClient::status( 503, '{"Fault":{"Error":[{"Message":"unavailable","code":"10000"}],"type":"SystemFault"}}' ) );
		$result = $this->connector->listTaxes( $this->connection );

		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Net002, $result->code );
	}
}
