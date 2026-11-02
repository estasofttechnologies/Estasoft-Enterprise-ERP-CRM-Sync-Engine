<?php
/**
 * Reading stock, shipments, invoices, customers, products and the order list from Acumatica.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Acumatica;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;

final class AcumaticaReadTest extends AcumaticaTestCase {

	public function test_stock_is_the_sum_over_all_warehouses_by_default(): void {
		$stock = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01', 'CS-BOWL-02' ), array() );

		$this->assertSame( array( 'quantity' => 45, 'held' => 0 ), $stock['CS-MUG-01'] );
		$this->assertSame( array( 'quantity' => 12, 'held' => 0 ), $stock['CS-BOWL-02'] );
	}

	public function test_stock_can_be_limited_to_chosen_warehouses(): void {
		$stock = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array( 'showroom' ) );

		$this->assertSame( 5, $stock['CS-MUG-01']['quantity'] );
	}

	public function test_stock_is_keyed_by_the_requested_sku_and_leaves_out_unknown_and_non_stock_items(): void {
		$stock = $this->connector()->fetchStock( $this->connection(), array( 'cs-mug-01', 'NO-SUCH', 'SHIPPING' ), array() );

		$this->assertSame( array( 'cs-mug-01' ), array_keys( $stock ) );
	}

	public function test_stock_is_read_in_chunks_of_ten_skus(): void {
		$skus = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$skus[] = 'SKU-' . $i;
		}
		$stock = $this->connector()->fetchStock( $this->connection(), $skus, array() );

		$this->assertSame( array(), $stock );
		$this->assertSame( 3, $this->server->count( 'GET', '#/StockItem$#' ) );
	}

	public function test_tracking_lists_confirmed_shipments_of_shop_orders_with_their_package_numbers(): void {
		$this->sendOrder();
		$this->server->addShipment( 'SO/000001', 'SH0001', 'AE-TRK-77', 'Confirmed' );
		$rows = $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'WC-100', $rows[0]['order_ref'] );
		$this->assertSame( 'done', $rows[0]['status'] );
		$this->assertSame( 'AE-TRK-77', $rows[0]['tracking'] );
		$this->assertSame( '2026-11-03T00:00:00Z', $rows[0]['done_at'] );
	}

	public function test_a_shipment_that_is_not_confirmed_is_not_reported(): void {
		$this->sendOrder();
		$this->server->addShipment( 'SO/000001', 'SH0001', 'AE-TRK-77', 'Open' );

		$this->assertSame( array(), $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) ) );
	}

	public function test_orders_that_are_not_the_shops_are_not_reported(): void {
		$this->server->put( 'SalesOrder', array( 'OrderType' => 'SO', 'OrderNbr' => '009999', 'CustomerOrder' => 'PO-77', 'Status' => 'Open', 'CurrencyID' => 'AED', 'OrderTotal' => 10.0, 'Shipments' => array() ) );
		$this->server->addShipment( 'SO/009999', 'SH0009', 'X-1' );

		$this->assertSame( array(), $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) ) );
	}

	public function test_only_orders_changed_since_the_time_are_read_for_tracking(): void {
		$this->sendOrder();
		$this->server->addShipment( 'SO/000001', 'SH0001', 'AE-TRK-77' );

		$this->assertSame( array(), $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-05T00:00:00Z' ) ) );
	}

	public function test_a_confirmed_shipment_without_a_package_number_is_reported_with_an_empty_number(): void {
		$this->sendOrder();
		$this->server->addShipment( 'SO/000001', 'SH0001', '' );
		$rows = $this->connector()->fetchTracking( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );

		$this->assertSame( '', $rows[0]['tracking'] );
	}

	public function test_the_invoice_of_an_order_is_read_through_its_shipments(): void {
		$result = $this->sendOrder();
		$this->server->put( 'SalesInvoice', array( 'Type' => 'Invoice', 'ReferenceNbr' => 'INV0001', 'Status' => 'Open', 'Date' => '2026-11-04T00:00:00', 'Amount' => 438.0 ) );
		$this->server->addShipment( 'SO/000001', 'SH0001', 'T1', 'Confirmed', 'INV0001' );
		$invoice = $this->connector()->fetchInvoice( $this->connection(), $result->ref );

		$this->assertSame( 'INV0001', $invoice['number'] );
		$this->assertSame( 'Open', $invoice['status'] );
		$this->assertSame( '', $invoice['authority_status'] );
		$this->assertSame( '2026-11-04T00:00:00Z', $invoice['issued_at'] );
	}

	public function test_an_order_without_an_invoice_has_no_invoice_facts(): void {
		$result = $this->sendOrder();

		$this->assertNull( $this->connector()->fetchInvoice( $this->connection(), $result->ref ) );
		$this->assertNull( $this->connector()->fetchInvoice( $this->connection(), new ExternalRef( 'SO/999999', '999999' ) ) );
	}

	public function test_the_nightly_list_has_totals_and_the_credit_memos_that_were_released(): void {
		$this->sendOrder();
		$this->sendOrder( array(), array(), '101' );
		$this->connector()->send( $this->connection( array( 'refund_state' => 'release' ) ), $this->item( EntityType::Refund, '501' ), $this->refund() );
		$this->connector()->send( $this->connection(), $this->item( EntityType::Refund, '502' ), $this->refund( array( 'order_ref' => 'WC-101' ) ) );
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );

		$this->assertSame( array( 'WC-100', 'WC-101' ), array_keys( $list ) );
		$this->assertSame( 43800, $list['WC-100']->extra['total_minor'] );
		$this->assertSame( 21900, $list['WC-100']->extra['refunded_minor'], 'A released credit memo counts.' );
		$this->assertSame( 0, $list['WC-101']->extra['refunded_minor'], 'A credit memo on hold does not.' );
		$this->assertSame( 'AED', $list['WC-100']->extra['currency'] );
	}

	public function test_a_cancelled_order_is_listed_with_its_state(): void {
		$this->sendOrder();
		$this->server->records['SalesOrder']['SO/000001']['Status'] = 'Cancelled';
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );

		$this->assertSame( 'cancelled', $list['WC-100']->extra['state'] );
	}

	public function test_orders_before_the_window_are_not_listed(): void {
		$this->sendOrder();

		$this->assertSame( array(), $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-12-01T00:00:00Z' ) ) );
	}

	public function test_the_nightly_list_is_read_page_by_page(): void {
		for ( $i = 1; $i <= 130; $i++ ) {
			$this->server->put( 'SalesOrder', array( 'OrderType' => 'SO', 'OrderNbr' => sprintf( '%06d', $i ), 'CustomerOrder' => 'WC-' . $i, 'Date' => '2026-11-02T00:00:00', 'Status' => 'Open', 'CurrencyID' => 'AED', 'OrderTotal' => 10.0 ) );
		}
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );

		$this->assertCount( 130, $list );
		$this->assertGreaterThanOrEqual( 2, $this->server->count( 'GET', '#/SalesOrder$#' ) );
	}

	public function test_a_customer_is_found_by_email_and_an_unknown_one_is_null(): void {
		$this->server->addCustomer( 'ALPHA', 'Noor existing', 'noor@example.test' );
		$ref = $this->connector()->lookupCustomer( $this->connection(), 'NOOR@example.test' );

		$this->assertSame( 'ALPHA', $ref->id );
		$this->assertSame( 'Noor existing', $ref->ref );
		$this->assertStringContainsString( 'AcctCD=ALPHA', (string) $ref->url );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), '' ) );
	}

	public function test_a_product_is_found_by_inventory_id_and_an_inactive_one_is_not(): void {
		$ref = $this->connector()->lookupProduct( $this->connection(), 'cs-mug-01' );

		$this->assertSame( 'CS-MUG-01', $ref->id );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), 'OLD-ITEM' ) );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), '  ' ) );
	}

	public function test_products_are_searched_by_id_or_description(): void {
		$byId   = $this->connector()->searchProducts( $this->connection(), 'mug' );
		$byName = $this->connector()->searchProducts( $this->connection(), 'item cs-bowl' );

		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $ref ): string => $ref->id, $byId ) );
		$this->assertSame( array( 'CS-BOWL-02' ), array_map( static fn( ExternalRef $ref ): string => $ref->id, $byName ) );
		$this->assertSame( array(), $this->connector()->searchProducts( $this->connection(), '' ) );
	}

	public function test_a_quote_in_a_search_is_doubled_in_the_filter(): void {
		$this->assertSame( array(), $this->connector()->searchProducts( $this->connection(), "o'brien" ) );
		$this->assertStringContainsString( "o''brien", (string) $this->server->calls[ count( $this->server->calls ) - 1 ]['query']['$filter'] );
	}

	public function test_tax_categories_are_listed_without_a_rate_and_inactive_ones_are_left_out(): void {
		$codes = $this->connector()->listTaxes( $this->connection() );

		$names = array_column( $codes, 'code' );
		sort( $names );
		$this->assertSame( array( 'EXEMPT', 'VAT5' ), $names );
		$this->assertStringContainsString( 'rate set by the tax zone', $codes[0]['label'] );
		$this->assertSame( 0.0, $codes[0]['rate_percent'] );
	}

	public function test_a_period_with_more_orders_than_the_cap_is_refused_not_cut_short(): void {
		$this->kit->http->route(
			'GET',
			'#/entity/Default/24\.200\.001/SalesOrder\?#',
			static function ( array $request ): \Estasoft\SyncEngine\Contract\HttpResponse {
				parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
				$rows = array();
				for ( $i = (int) $query['$skip']; $i < min( (int) $query['$skip'] + (int) $query['$top'], 25000 ); $i++ ) {
					$rows[] = array(
						'OrderType'     => array( 'value' => 'SO' ),
						'OrderNbr'      => array( 'value' => sprintf( '%06d', $i ) ),
						'CustomerOrder' => array( 'value' => 'WC-' . $i ),
						'OrderTotal'    => array( 'value' => 10 ),
						'CurrencyID'    => array( 'value' => 'AED' ),
						'Status'        => array( 'value' => 'Open' ),
					);
				}
				return \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( $rows );
			}
		);
		$connection = $this->connection();
		$result     = $this->connector()->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );

		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $result );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ErrorCode::Val002, $result->code );
		$this->assertSame( 'window', $result->context['field'] );
		$this->assertStringContainsString( '20000', (string) $result->context['reason'] );
	}
}
