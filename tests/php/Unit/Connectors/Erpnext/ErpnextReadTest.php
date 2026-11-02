<?php
/**
 * Reading stock, tracking, invoices and the nightly list from ERPNext.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;

final class ErpnextReadTest extends ErpnextTestCase {

	public function test_stock_is_summed_over_warehouses_and_held_is_the_reserved_quantity(): void {
		$this->server->put( 'Bin', array( 'item_code' => 'CS-MUG-01', 'warehouse' => 'Stores - CS', 'actual_qty' => 40, 'reserved_qty' => 3 ) );
		$this->server->put( 'Bin', array( 'item_code' => 'CS-MUG-01', 'warehouse' => 'Showroom - CS', 'actual_qty' => 2.5, 'reserved_qty' => 0.2 ) );
		$this->server->put( 'Bin', array( 'item_code' => 'CS-BOWL-02', 'warehouse' => 'Stores - CS', 'actual_qty' => 7, 'reserved_qty' => 0 ) );

		$all = $this->connector()->fetchStock( $this->connection(), array( 'cs-mug-01', 'CS-BOWL-02', 'SHIPPING', 'UNKNOWN' ), array() );
		$this->assertSame( array( 'quantity' => 42, 'held' => 4 ), $all['cs-mug-01'], 'Rounded down on hand, up held; the SKU the caller asked for keys the answer.' );
		$this->assertSame( array( 'quantity' => 7, 'held' => 0 ), $all['CS-BOWL-02'] );
		$this->assertArrayNotHasKey( 'SHIPPING', $all, 'A service item has no stock.' );
		$this->assertArrayNotHasKey( 'UNKNOWN', $all );

		$stores = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array( 'Stores - CS' ) );
		$this->assertSame( array( 'quantity' => 40, 'held' => 3 ), $stores['CS-MUG-01'] );
	}

	public function test_a_stock_item_without_a_bin_has_zero(): void {
		$this->assertSame( array( 'quantity' => 0, 'held' => 0 ), $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array() )['CS-MUG-01'] );
	}

	public function test_tracking_comes_from_delivery_notes_that_name_an_order(): void {
		$this->server->put( 'Delivery Note', array( 'docstatus' => 1, 'is_return' => 0, 'po_no' => 'WC-100', 'lr_no' => 'AWB 123', 'transporter_name' => 'Aramex', 'posting_date' => '2026-11-03', 'modified' => '2026-11-03 09:00:00' ) );
		$this->server->put( 'Delivery Note', array( 'docstatus' => 1, 'is_return' => 0, 'po_no' => 'WC-101, WC-102, PO-77', 'lr_no' => 'Z9', 'transporter_name' => 'DHL', 'posting_date' => '2026-11-03', 'modified' => '2026-11-03 10:00:00' ) );
		$this->server->put( 'Delivery Note', array( 'docstatus' => 1, 'is_return' => 0, 'po_no' => 'PO-1 WC-1000A', 'lr_no' => 'X', 'posting_date' => '2026-11-03', 'modified' => '2026-11-03 10:00:00' ) );
		$this->server->put( 'Delivery Note', array( 'docstatus' => 0, 'is_return' => 0, 'po_no' => 'WC-103', 'lr_no' => 'draft', 'posting_date' => '2026-11-03', 'modified' => '2026-11-03 10:00:00' ) );
		$this->server->put( 'Delivery Note', array( 'docstatus' => 1, 'is_return' => 0, 'po_no' => 'WC-104', 'lr_no' => 'old', 'posting_date' => '2025-01-01', 'modified' => '2025-01-01 10:00:00' ) );

		$rows = $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );
		$this->assertIsArray( $rows );
		$this->assertSame( array( 'WC-100', 'WC-101', 'WC-102' ), array_column( $rows, 'order_ref' ) );
		$this->assertSame( array( 'AWB 123', 'Z9', 'Z9' ), array_column( $rows, 'tracking' ) );
		$this->assertSame( 'Aramex', $rows[0]['carrier'] );
		$this->assertSame( 'done', $rows[0]['status'] );
		$this->assertSame( '2026-11-03T00:00:00Z', $rows[0]['done_at'] );
	}

	public function test_the_invoice_of_an_order_is_read_back(): void {
		$this->assertTrue( $this->sendOrder( array( 'documents' => 'order_invoice' ) )->isSuccess() );
		$order   = $this->server->all( 'Sales Order' )[0];
		$invoice = $this->connector()->fetchInvoice( $this->connection(), new ExternalRef( $order['name'], $order['name'] ) );

		$this->assertIsArray( $invoice );
		$this->assertSame( $this->server->all( 'Sales Invoice' )[0]['name'], $invoice['number'] );
		$this->assertSame( 'Unpaid', $invoice['status'] );
		$this->assertSame( '', $invoice['authority_status'] );
		$this->assertSame( '2026-11-02T00:00:00Z', $invoice['issued_at'] );
		$this->assertNull( $this->connector()->fetchInvoice( $this->connection(), new ExternalRef( 'SAL-ORD-NONE' ) ) );
	}

	public function test_the_nightly_list_has_totals_refunds_and_emails_for_orders_of_the_window(): void {
		$this->assertTrue( $this->sendOrder( array( 'documents' => 'order_invoice' ), array( 'total' => 43800 ) )->isSuccess() );
		$this->assertTrue( $this->connector()->send( $this->connection( array( 'documents' => 'order_invoice', 'refund_state' => 'post' ) ), $this->item( EntityType::Refund, '200' ), $this->refund() )->isSuccess() );
		$this->server->put( 'Sales Order', array( 'po_no' => 'PO-1', 'docstatus' => 1, 'transaction_date' => '2026-11-02', 'grand_total' => 5, 'currency' => 'AED' ) );
		$this->server->put( 'Sales Order', array( 'po_no' => 'WC-7', 'docstatus' => 1, 'transaction_date' => '2020-01-01', 'grand_total' => 5, 'currency' => 'AED' ) );

		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertIsArray( $list );
		$this->assertSame( array( 'WC-100' ), array_keys( $list ) );
		$this->assertSame( 43800, $list['WC-100']->extra['total_minor'] );
		$this->assertSame( 21900, $list['WC-100']->extra['refunded_minor'] );
		$this->assertSame( 'noor@example.test', $list['WC-100']->extra['email'] );
	}

	public function test_a_draft_refund_does_not_count_as_refunded(): void {
		$this->assertTrue( $this->sendOrder( array( 'documents' => 'order_invoice' ), array( 'total' => 43800 ) )->isSuccess() );
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item( EntityType::Refund, '200' ), $this->refund() )->isSuccess() );
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertSame( 0, $list['WC-100']->extra['refunded_minor'] );
	}

	public function test_lookups(): void {
		$connector = $this->connector();
		$this->assertTrue( $this->sendOrder()->isSuccess() );
		$customer = $connector->lookupCustomer( $this->connection(), 'NOOR@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $customer );
		$this->assertSame( 'Noor Al-Mansouri', $customer->ref );
		$this->assertNull( $connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );

		$product = $connector->lookupProduct( $this->connection(), 'cs-mug-01' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-MUG-01', $product->ref, 'The ERPNext spelling is what sku_match hands to the order.' );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'OLD-ITEM' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection(), '' ) );

		$found = $connector->searchProducts( $this->connection(), 'bowl' );
		$this->assertIsArray( $found );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->id, $found ) );
		$this->assertSame( array(), $connector->searchProducts( $this->connection(), '' ) );
	}

	public function test_tax_codes_are_the_enabled_templates_with_their_rates(): void {
		$codes = $this->connector()->listTaxes( $this->connection() );
		$this->assertIsArray( $codes );
		$byCode = array_column( $codes, null, 'code' );
		$this->assertSame( 5.0, $byCode['UAE VAT 5% - CS']['rate_percent'] );
		$this->assertSame( 0.0, $byCode['Compound - CS']['rate_percent'], 'A template that is not a plain percentage has no single rate.' );
		$this->assertNotInstanceOf( ConnectorError::class, $codes );
	}

	public function test_a_customer_item_is_matched_or_created_and_a_missing_email_is_refused(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Customer, '15' );
		$first     = $connector->send( $this->connection(), $item, array( 'partner' => array( 'name' => 'Layla', 'email' => 'layla@example.test' ) ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$second = $connector->send( $this->connection(), $item, array( 'partner' => array( 'name' => 'Layla', 'email' => 'LAYLA@example.test' ) ) );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$bad = $connector->send( $this->connection(), $item, array( 'partner' => array( 'name' => 'Layla' ) ) );
		$this->assertFalse( $bad->isSuccess() );
	}

	public function test_a_site_with_more_rows_than_one_page_is_read_to_the_end(): void {
		for ( $i = 1; $i <= 130; $i++ ) {
			$this->server->put( 'Delivery Note', array( 'docstatus' => 1, 'is_return' => 0, 'po_no' => 'WC-' . ( 1000 + $i ), 'lr_no' => 'T' . $i, 'posting_date' => '2026-11-03', 'modified' => '2026-11-03 10:00:00' ) );
		}
		$rows = $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );
		$this->assertCount( 130, $rows, 'Pages of 100: the second page is read too.' );
		$this->assertSame( 2, $this->server->count( 'GET', '#/api/resource/Delivery Note$#' ) );
	}

	public function test_a_period_with_more_rows_than_the_cap_is_refused_not_cut_short(): void {
		$this->kit->http->route(
			'GET',
			'#/api/resource/Sales%20Order\?#',
			static function ( array $request ): \Estasoft\SyncEngine\Contract\HttpResponse {
				parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
				$rows = array();
				for ( $i = (int) $query['limit_start']; $i < min( (int) $query['limit_start'] + (int) $query['limit_page_length'], 25000 ); $i++ ) {
					$rows[] = array( 'name' => 'SO-' . $i, 'po_no' => 'WC-' . $i, 'grand_total' => 10, 'currency' => 'AED', 'contact_email' => '', 'transaction_date' => '2026-11-02' );
				}
				return FakeHttpClient::json( array( 'data' => $rows ) );
			}
		);
		$result = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );

		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $result );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ErrorCode::Val002, $result->code );
		$this->assertSame( 'window', $result->context['field'] );
		$this->assertStringContainsString( '20000', (string) $result->context['reason'] );
	}
}
