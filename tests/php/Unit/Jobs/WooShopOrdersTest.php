<?php
/**
 * The order adapter against fake WooCommerce classes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

require_once __DIR__ . '/JobsAdapterTestCase.php';

use Estasoft\SyncEngine\Jobs\Adapters\WooShopOrders;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;

final class WooShopOrdersTest extends JobsAdapterTestCase {

	public function test_a_summary_has_minor_units_by_the_currency_exponent_and_utc_time(): void {
		$this->order( 10490, array( 'order_number' => 'A-10490' ), array( '_estasoft_sync_state' => 'synced' ) );
		$this->order( 10491, array( 'currency' => 'KWD', 'total' => '12.345', 'total_refunded' => '2.500' ) );
		$shop = new WooShopOrders();

		$aed = $shop->find( 10490 );
		$kwd = $shop->find( 10491 );

		$this->assertSame( 48615, $aed['total_minor'] );
		$this->assertSame( 'A-10490', $aed['label'] );
		$this->assertSame( 'Noor Al-Mansouri', $aed['customer'] );
		$this->assertSame( '2026-11-02 07:21:00', $aed['created_at'], 'stored in UTC' );
		$this->assertSame( 'synced', $aed['sync_state'] );
		$this->assertSame( 'cod', $aed['payment_method'] );
		$this->assertSame( 12345, $kwd['total_minor'], '3 decimals, no float error' );
		$this->assertSame( 2500, $kwd['refunded_minor'] );
		$this->assertNull( $shop->find( 999 ) );
		$this->assertNull( $shop->find( 0 ) );
	}

	public function test_status_moves_take_a_note_and_a_status_it_already_has_is_not_a_move(): void {
		$order = $this->order( 1 );
		$shop  = new WooShopOrders();

		$this->assertTrue( $shop->updateStatus( 1, 'wc-completed', 'Delivered' ) );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'Delivered', $order->notes[0][0] );
		$this->assertFalse( $shop->updateStatus( 1, 'completed', 'again' ) );
		$this->assertFalse( $shop->updateStatus( 404, 'completed', 'x' ) );

		$shop->addNote( 1, 'A private note' );
		$this->assertSame( array( 'A private note', 0 ), $order->notes[ count( $order->notes ) - 1 ] );
	}

	public function test_tracking_is_written_as_meta_through_save_meta_data_and_never_saves_the_order(): void {
		$order = $this->order( 1 );
		$shop  = new WooShopOrders();
		$entry = array( 'carrier' => 'Aramex', 'number' => 'AR123', 'at' => '2026-11-02T09:00:00Z' );

		$shop->saveTracking( 1, array( $entry ) );

		$this->assertSame( array( $entry ), $shop->tracking( 1 ) );
		$this->assertSame( 'AR123', $order->meta['_estasoft_sync_tracking_number'] );
		$this->assertSame( 'Aramex', $order->meta['_estasoft_sync_tracking_carrier'] );
		$this->assertSame( 1, $order->metaSaves );
		$this->assertSame( 0, $order->saves, 'save() would look like a change to sync' );
		$this->assertSame( array(), $shop->tracking( 404 ) );
	}

	public function test_invoice_facts_round_trip_and_feed_the_order_box_keys(): void {
		$order = $this->order( 1 );
		$shop  = new WooShopOrders();
		$facts = array( 'number' => 'INV/1', 'status' => 'waiting', 'pdf_url' => 'https://erp.test/p.pdf', 'issued_at' => '2026-11-02T07:00:00Z' );

		$shop->saveInvoice( 1, $facts );

		$this->assertSame( $facts, $shop->invoice( 1 ) );
		$this->assertSame( 'INV/1', $order->meta['_estasoft_sync_invoice_number'] );
		$this->assertSame( 'waiting', $order->meta['_estasoft_sync_invoice_status'] );
		$this->assertSame( 'https://erp.test/p.pdf', $order->meta['_estasoft_sync_invoice_pdf'] );
		$this->assertSame( 0, $order->saves );
		$this->assertNull( $shop->invoice( 404 ) );
	}

	public function test_invoice_rows_are_one_query_with_a_meta_filter_for_status_and_search(): void {
		$order = $this->order( 1, array(), array( '_estasoft_sync_invoice' => '{"number":"INV/9","status":"waiting"}' ) );
		WooRegistry::$ordersResult = (object) array( 'orders' => array( $order ), 'total' => 41, 'max_num_pages' => 2 );
		$shop = new WooShopOrders();

		$rows = $shop->invoiceRows( 'waiting', 'INV/9', 2, 25 );

		$this->assertSame( 41, $rows['total'] );
		$this->assertSame( 'INV/9', $rows['items'][0]['invoice']['number'] );
		$this->assertSame( 1, $rows['items'][0]['id'] );
		$args = WooRegistry::$orderQueries[0];
		$this->assertTrue( $args['paginate'] );
		$this->assertSame( 25, $args['limit'] );
		$this->assertSame( 2, $args['page'] );
		$keys = array_column( $args['meta_query'], 'key' );
		$this->assertSame( array( '_estasoft_sync_invoice', '_estasoft_sync_invoice_status', '_estasoft_sync_invoice_number' ), $keys );
		$this->assertSame( 'LIKE', $args['meta_query'][2]['compare'] );

		$shop->invoiceRows( null, '10490', 1, 25 );
		$this->assertSame( '10490', WooRegistry::$orderQueries[1]['s'], 'a number is a search over the orders' );
	}

	public function test_ids_since_asks_for_paid_orders_in_pages_oldest_first(): void {
		WooRegistry::$ordersResult = (object) array( 'orders' => array( '5', '9' ), 'total' => 3, 'max_num_pages' => 2 );
		$shop = new WooShopOrders();

		$page = $shop->idsSince( new \DateTimeImmutable( '2026-10-26 00:00:00 UTC' ), 1, 500 );

		$this->assertSame( array( 5, 9 ), $page['ids'] );
		$this->assertTrue( $page['has_more'] );
		$args = WooRegistry::$orderQueries[0];
		$this->assertSame( '>=1792972800', $args['date_created'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertSame( 'ids', $args['return'] );
		$this->assertNotContains( 'wc-pending', $args['status'] );
		$this->assertNotContains( 'wc-cancelled', $args['status'] );
		$this->assertFalse( $shop->idsSince( new \DateTimeImmutable( 'now' ), 2, 500 )['has_more'] );
	}

	public function test_statuses_lose_the_prefix_and_refund_ids_are_listed(): void {
		$order          = $this->order( 1 );
		$order->refunds = array( new \WC_Order_Refund( array( 'id' => 77 ) ), new \WC_Order_Refund( array( 'id' => 78 ) ) );
		$shop           = new WooShopOrders();

		$this->assertSame( array( 'pending', 'processing', 'completed' ), array_keys( $shop->statuses() ) );
		$this->assertSame( array( 77, 78 ), $shop->refundIds( 1 ) );
		$this->assertSame( array(), $shop->refundIds( 404 ) );
	}

	public function test_recent_orders_come_from_wc_get_orders(): void {
		WooRegistry::$ordersResult = array( $this->order( 3 ), $this->order( 2 ) );
		$rows = ( new WooShopOrders() )->recent( 2 );
		$this->assertSame( array( 3, 2 ), array_column( $rows, 'id' ) );
		$this->assertSame( 2, WooRegistry::$orderQueries[0]['limit'] );
	}
}
