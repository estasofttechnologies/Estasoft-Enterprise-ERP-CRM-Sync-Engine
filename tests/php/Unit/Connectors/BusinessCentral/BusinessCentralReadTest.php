<?php
/**
 * Lookups, tax groups, invoice read-back and the nightly check for Business Central.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Connectors\BusinessCentral\BusinessCentralConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class BusinessCentralReadTest extends BusinessCentralTestCase {

	public function test_a_customer_is_found_by_email_ignoring_case_and_carries_the_name_as_typed(): void {
		$this->bc->addCustomer( 'Noor@Example.test', 'نور المنصوري' );
		$ref = $this->connector->lookupCustomer( $this->connection, 'noor@example.TEST' );

		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( 'نور المنصوري', $ref->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
	}

	public function test_an_email_with_an_apostrophe_cannot_break_the_filter(): void {
		$this->bc->addCustomer( "o'brien@example.test", "O'Brien" );
		$ref = $this->connector->lookupCustomer( $this->connection, "O'Brien@example.test" );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertStringContainsString( "o''brien", rawurldecode( $this->kit->http->last()['url'] ) );
	}

	public function test_a_product_is_found_by_item_number_or_by_gtin(): void {
		$this->bc->addItem( 'GT-1', array( 'gtin' => '6291041500213' ) );
		$byNumber = $this->connector->lookupProduct( $this->connection, 'cs-mug-01' );
		$this->assertSame( 'CS-MUG-01', $byNumber->ref );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, '6291041500213' ) );
		$byGtin = $this->connector->lookupProduct( $this->connection( array( 'sku_field' => 'gtin' ) ), '6291041500213' );
		$this->assertSame( 'GT-1', $byGtin->ref );
	}

	public function test_product_search_looks_at_number_and_name_and_never_ors_two_fields(): void {
		$found = $this->connector->searchProducts( $this->connection, 'tee' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-TEE-02', $found[0]->ref );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( ' or ', rawurldecode( $request['url'] ) );
		}
	}

	public function test_every_company_call_is_pinned_to_the_company(): void {
		$this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );
		$this->connector->send( $this->connection, $this->orderItem( '4001' ), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			if ( ! str_contains( $request['url'], 'api.businesscentral' ) || preg_match( '#/api/v2\.0/companies(\?|$)#', $request['url'] ) ) {
				continue;
			}
			$this->assertStringContainsString( '/companies(' . \Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer::COMPANY_ID . ')/', $request['url'] );
		}
	}

	public function test_tax_groups_are_listed_without_a_rate(): void {
		/** @var BusinessCentralConnector $inner */
		$inner = $this->connector->inner();
		$codes = $inner->listTaxCodes( $this->connection );
		$this->assertSame( 'VAT5', $codes[0]['code'] );
		$this->assertSame( '', $codes[0]['rate_percent'] );
		$this->assertStringContainsString( 'Business Central', $codes[0]['name'] );
		$taxes = $this->connector->listTaxes( $this->connection );
		$this->assertSame( 'VAT5', $taxes[0]['code'] );
	}

	public function test_the_invoice_of_a_synced_order_is_read_back(): void {
		$connection = $this->connection( array( 'documents' => 'order' ) );
		$sent       = $this->connector->send( $connection, $this->orderItem( '4002' ), $this->payload() );
		$this->assertNull( $this->connector->fetchInvoice( $connection, $sent->ref ), 'An open order has no invoice yet.' );

		$this->kit->http->request( 'POST', 'https://api.businesscentral.dynamics.com/v2.0/production/api/v2.0/companies(' . \Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer::COMPANY_ID . ')/salesOrders(' . $sent->ref->id . ')/Microsoft.NAV.shipAndInvoice', array( 'Authorization' => 'Bearer ' . $this->bc->entra->issued[0], 'If-Match' => '*' ), null, 30 );
		$invoice = $this->connector->fetchInvoice( $connection, $sent->ref );
		$this->assertStringStartsWith( 'PINV-', $invoice['number'] );
		$this->assertSame( 'open', $invoice['status'] );
		$this->assertSame( '', $invoice['authority_status'] );
		$this->assertSame( '2026-11-01T00:00:00Z', $invoice['issued_at'] );
	}

	public function test_the_nightly_check_lists_orders_invoices_and_what_was_refunded(): void {
		$this->connector->send( $this->connection, $this->orderItem( '5001' ), $this->payload() );
		$invoiced = $this->connection( array( 'documents' => 'order_ship_invoice' ) );
		$this->connector->send( $invoiced, $this->orderItem( '5002' ), $this->payload() );
		$this->connector->send( $this->connection, $this->orderItem( '5101', EntityType::Refund ), array(
			'order_ref'   => 'WC-5001',
			'amount'      => 2099,
			'refund_line' => array( array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 1999 ) ),
		) );

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5001', 'WC-5002' ), array_keys( $list ) );
		$this->assertSame( 6297, $list['WC-5001']->extra['total_minor'] );
		$this->assertSame( 2099, $list['WC-5001']->extra['refunded_minor'] );
		$this->assertSame( 0, $list['WC-5002']->extra['refunded_minor'] );
		$this->assertSame( 'jane.doe@example.test', $list['WC-5001']->extra['email'] );
		$this->assertSame( 'invoice', $list['WC-5002']->extra['kind'] );

		$later = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-11-05T00:00:00Z' ) );
		$this->assertSame( array(), $later, 'Only documents on or after the window start.' );
	}

	public function test_a_list_is_read_page_by_page_and_never_cut_off(): void {
		$this->bc->odata->pageSize = 2;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->bc->addCustomer( 'buyer' . $i . '@example.test', 'Buyer ' . $i );
		}
		$ref = $this->connector->lookupCustomer( $this->connection, 'buyer5@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref, 'The match is on page three.' );

		for ( $i = 6001; $i <= 6005; $i++ ) {
			$result = $this->connector->send( $this->connection, $this->orderItem( (string) $i ), $this->payload() );
			$this->assertTrue( $result->isSuccess() );
		}
		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertCount( 5, $list );
		$existing = $this->connector->findExisting( $this->connection, $this->orderItem( '6005' ) );
		$this->assertNotNull( $existing );
	}

	public function test_an_unfinished_document_is_not_listed_as_missing_from_the_search(): void {
		$item                      = $this->orderItem( '7001' );
		$this->bc->failAfterWrites = array( 'salesOrderLines' => 0 );
		$this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertArrayHasKey( 'WC-7001', $list, 'The header exists in Business Central, so the nightly check sees it.' );
	}

	public function test_a_customer_item_is_found_or_created(): void {
		$item = $this->orderItem( '8001', EntityType::Customer );
		$p    = array( 'partner' => array( 'email' => 'new@example.test', 'name' => 'New Buyer', 'country_id' => 'AE' ) );
		$one  = $this->connector->send( $this->connection, $item, $p );
		$two  = $this->connector->send( $this->connection, $item, $p );
		$this->assertTrue( $one->isSuccess() );
		$this->assertFalse( $one->alreadyExisted );
		$this->assertTrue( $two->alreadyExisted );
		$this->assertCount( 1, $this->bc->all( 'customers' ) );

		$bad = $this->connector->send( $this->connection, $item, array( 'partner' => array( 'name' => 'No email' ) ) );
		$this->assertSame( ErrorCode::Val002, $bad->error->code );
	}

	public function test_an_entity_the_connector_does_not_send_is_refused(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '9', EntityType::Stock ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 0, $this->kit->http->count() );
	}
}
