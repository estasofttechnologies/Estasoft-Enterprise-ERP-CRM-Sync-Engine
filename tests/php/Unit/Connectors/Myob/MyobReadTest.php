<?php
/**
 * What the MYOB connector reads: tax codes, items, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class MyobReadTest extends MyobTestCase {

	private function order( string $id, array $merge = array(), ?array $options = null ): ExternalRef {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		$result     = $this->connector->send( $connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		return $result->ref;
	}

	public function test_tax_codes_are_listed_with_their_rates(): void {
		$codes = $this->connector->listTaxes( $this->connection );

		$this->assertSame( array( 'GST', 'FRE', 'N-T', 'WET', 'VAT' ), array_column( $codes, 'code' ) );
		$this->assertSame( 'Goods & Services Tax', $codes[0]['label'] );
		$this->assertSame( 10.0, $codes[0]['rate_percent'] );
		$this->assertSame( 29.0, $codes[3]['rate_percent'] );
	}

	public function test_a_product_is_found_by_its_number_and_a_search_looks_at_number_and_name(): void {
		$this->myob->addItem( 'CS-BOWL-02', array( 'Name' => 'Bowl set' ) );

		$product = $this->connector->lookupProduct( $this->connection, 'CS-BOWL-02' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-BOWL-02', $product->ref );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );

		$byNumber = $this->connector->searchProducts( $this->connection, 'bowl-02' );
		$byName   = $this->connector->searchProducts( $this->connection, 'Bowl' );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $byNumber ) );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->ref, $byName ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
	}

	public function test_a_number_with_a_quote_is_escaped_by_doubling_it(): void {
		$this->assertNull( $this->connector->lookupProduct( $this->connection, "A'B" ) );

		$this->assertSame( "Number eq 'A''B'", $this->myob->calls( 'GET', '/Inventory/Item' )[0]->query['$filter'] );
	}

	public function test_a_customer_is_found_by_email_ignoring_case(): void {
		$this->myob->addCustomer( 'Noor', 'noor@example.test' );
		$found = $this->connector->lookupCustomer( $this->connection, 'NOOR@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Noor', $found->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, '' ) );
	}

	public function test_stock_is_the_quantity_on_hand_of_inventoried_items_only_and_can_be_read_per_location(): void {
		$this->myob->addItem( 'CS-NEG-04', array( 'Name' => 'Oversold', 'IsInventoried' => true, 'QuantityOnHand' => -3.0, 'QuantityCommitted' => 0.0 ) );

		$total = $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01', 'CS-NEG-04', 'WC-GENERAL', 'NO-SUCH' ), array() );
		$this->assertSame(
			array(
				'CS-MUG-01' => array( 'quantity' => 40, 'held' => 3 ),
				'CS-NEG-04' => array( 'quantity' => 0, 'held' => 0 ),
			),
			$total,
			'A service and an unknown number have no stock; a negative quantity is zero.'
		);

		$dubai = $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01' ), array( 'DXB' ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 10, 'held' => 1 ) ), $dubai );
		$both = $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01' ), array( 'main warehouse', 'DXB' ) );
		$this->assertSame( 40, $both['CS-MUG-01']['quantity'], 'A location is matched by identifier or name, ignoring case.' );
		$this->assertSame( array(), $this->connector->fetchStock( $this->connection, array( 'CS-MUG-01' ), array( 'NOWHERE' ) ) );
	}

	public function test_invoice_facts_carry_the_number_status_and_date(): void {
		$ref = $this->order( '3001' );
		$this->assertSame( 'unpaid', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );

		$paid  = $this->order( '3002', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid' ) );
		$facts = $this->connector->fetchInvoice( $this->connection, $paid );
		$this->assertSame( $paid->ref, $facts['number'] );
		$this->assertSame( 'paid', $facts['status'] );
		$this->assertSame( '2026-11-01', $facts['issued_at'] );
		$this->assertSame( '', $facts['pdf_url'] );

		$this->assertNull( $this->connector->fetchInvoice( $this->connection, new ExternalRef( '99999999-0000-4000-8000-000000000000', 'x' ) ) );
	}

	public function test_a_partly_paid_invoice_is_partial(): void {
		$ref = $this->order( '3003' );
		$this->myob->store['Invoice'][ $ref->id ]['BalanceDueAmount'] = 40.0;

		$this->assertSame( 'partial', $this->connector->fetchInvoice( $this->connection, $ref )['status'] );
	}

	public function test_the_nightly_list_holds_the_shops_invoices_from_the_day_on_with_their_refunds(): void {
		$one = $this->order( '3010' );
		$this->order( '3011', array( 'date_order' => '2026-10-01' ) );
		$this->myob->store['Invoice']['990'] = array( 'UID' => '990', 'Number' => '00009999', 'Date' => '2026-11-01T00:00:00', 'CustomerPurchaseOrderNumber' => 'PO-77', 'TotalAmount' => 5.0, 'Status' => 'Open' );
		$this->connector->send( $this->connection, $this->item( '3110', EntityType::Refund ), $this->refundPayload( '3010' ) );

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-25', new \DateTimeZone( 'UTC' ) ) );

		$this->assertSame( array( 'WC-3010' ), array_keys( $list ), 'Not the older invoice, not an invoice that is not the shop\'s, not the credit as an order.' );
		$this->assertSame( $one->id, $list['WC-3010']->id );
		$this->assertSame( 9240, $list['WC-3010']->extra['total_minor'] );
		$this->assertSame( 4620, $list['WC-3010']->extra['refunded_minor'] );
		$filter = $this->myob->calls( 'GET', '/Sale/Invoice/Item' );
		$this->assertSame( "startswith(CustomerPurchaseOrderNumber,'WC-') and Date ge datetime'2026-10-25'", end( $filter )->query['$filter'] );
	}

	public function test_the_nightly_list_reads_every_page(): void {
		for ( $i = 1; $i <= 2300; $i++ ) {
			$uid                                   = sprintf( '00000000-0000-4000-a000-%012d', $i );
			$this->myob->store['Invoice'][ $uid ] = array( 'UID' => $uid, 'Number' => sprintf( '%08d', $i ), 'Date' => '2026-11-01T00:00:00', 'CustomerPurchaseOrderNumber' => 'WC-' . ( 5000 + $i ), 'TotalAmount' => 1.0, 'Status' => 'Open' );
		}

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-11-01', new \DateTimeZone( 'UTC' ) ) );

		$this->assertCount( 2300, $list );
		$this->assertCount( 3, $this->myob->calls( 'GET', '/Sale/Invoice/Item' ) );
	}

	public function test_a_customer_item_creates_a_card_and_links_an_existing_one(): void {
		$item    = $this->item( '77', EntityType::Customer );
		$payload = array( 'partner' => array( 'name' => 'Sara Nasser', 'email' => 'sara@example.test', 'country_id' => 'AU' ) );

		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$created = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $created->isSuccess(), (string) $created->error?->message() );
		$this->assertFalse( $created->alreadyExisted );
		$card = array_values( $this->myob->store['Customer'] )[0];
		$this->assertSame( 'Sara', $card['FirstName'] );
		$this->assertSame( 'Nasser', $card['LastName'] );
		$this->assertSame( 'sara@example.test', $card['Addresses'][0]['Email'], 'The email is kept even with no street, so the card is found again.' );

		$again = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted, 'A card that has the email is linked, not copied.' );
		$this->assertCount( 1, $this->myob->store['Customer'] );

		$noEmail = $this->connector->send( $this->connection, $this->item( '79', EntityType::Customer ), array( 'partner' => array( 'name' => 'X' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );
		$noName = $this->connector->send( $this->connection, $this->item( '80', EntityType::Customer ), array( 'partner' => array( 'email' => 'x@example.test' ) ) );
		$this->assertFalse( $noName->isSuccess() );
	}

	public function test_reads_return_an_error_and_do_not_throw_when_myob_is_down(): void {
		$this->kit->http->queue( FakeHttpClient::status( 503, '{"Errors":[{"Name":"ServiceUnavailable","Message":"unavailable"}]}' ) );
		$result = $this->connector->listTaxes( $this->connection );

		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Net002, $result->code );
	}
}
