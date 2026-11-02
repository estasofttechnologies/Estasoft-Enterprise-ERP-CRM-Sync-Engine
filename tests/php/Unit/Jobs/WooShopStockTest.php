<?php
/**
 * The stock adapter against fake WooCommerce classes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

require_once __DIR__ . '/JobsAdapterTestCase.php';

use Estasoft\SyncEngine\Jobs\Adapters\WooShopStock;
use Estasoft\SyncEngine\Tests\Support\JobsWooRegistry;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;

final class WooShopStockTest extends JobsAdapterTestCase {

	public function test_products_with_a_sku_come_back_with_the_facts_the_job_needs(): void {
		$buffered = $this->product( 1, array(), array( '_estasoft_sync_stock_buffer' => '4' ) );
		$excluded = $this->product( 2, array(), array( '_estasoft_sync_exclude' => 'yes' ) );
		$noSku    = $this->product( 3, array( 'sku' => '' ) );
		$unmanaged = $this->product( 4, array( 'manage_stock' => false, 'stock_quantity' => null ) );
		$parent   = $this->product( 5, array(), array( '_estasoft_sync_exclude' => 'yes' ) );
		$child    = $this->product( 6, array( 'parent_id' => 5 ) );
		JobsWooRegistry::$productPages[1] = (object) array( 'products' => array( $buffered, $excluded, $noSku, $unmanaged, $child ), 'max_num_pages' => 2 );

		$page = ( new WooShopStock() )->products( 1, 100 );

		$this->assertTrue( $page['has_more'] );
		$byId = array_column( $page['items'], null, 'id' );
		$this->assertSame( array( 1, 2, 4, 6 ), array_keys( $byId ), 'a product with no SKU is not listed' );
		$this->assertSame( 4, $byId[1]['buffer'] );
		$this->assertSame( 5, $byId[1]['quantity'] );
		$this->assertTrue( $byId[1]['manages_stock'] );
		$this->assertTrue( $byId[2]['excluded'] );
		$this->assertFalse( $byId[4]['manages_stock'] );
		$this->assertNull( $byId[4]['quantity'] );
		$this->assertTrue( $byId[6]['excluded'], 'a variation follows its parent' );
		$args = JobsWooRegistry::$productQueries[0];
		$this->assertSame( array( 'simple', 'variation' ), $args['type'] );
		$this->assertSame( 100, $args['limit'] );
		$this->assertTrue( $args['paginate'] );
	}

	public function test_set_quantity_writes_through_the_product_object_and_remembers_the_system_value(): void {
		$product = $this->product( 1 );
		$shop    = new WooShopStock();

		$this->assertTrue( $shop->setQuantity( 1, 39, 42, '2026-11-02 12:00:00' ) );

		$this->assertSame( 39, $product->get_stock_quantity() );
		$this->assertSame( '42', $product->meta['_estasoft_sync_last_stock'] );
		$this->assertSame( '2026-11-02 12:00:00', $product->meta['_estasoft_sync_last_stock_at'] );
		$this->assertSame( 1, $product->saves );

		$shop->setQuantity( 1, -5, 0, '2026-11-02 12:05:00' );
		$this->assertSame( 0, $product->get_stock_quantity(), 'never below zero' );
		$this->assertFalse( $shop->setQuantity( 404, 1, 1, '2026-11-02 12:00:00' ) );
	}

	public function test_held_units_come_from_woocommerce(): void {
		$this->product( 1 );
		JobsWooRegistry::$held[1] = 3;
		$shop                     = new WooShopStock();
		$this->assertSame( 3, $shop->heldUnits( 1 ) );
		$this->assertSame( 0, $shop->heldUnits( 404 ) );
	}

	public function test_units_sold_but_not_yet_synced_are_counted_per_product_and_variation(): void {
		$order           = $this->order( 1 );
		$order->items    = array(
			'line_item' => array(
				new \WC_Order_Item_Product( array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2 ) ),
				new \WC_Order_Item_Product( array( 'product_id' => 11, 'variation_id' => 111, 'quantity' => 1 ) ),
			),
		);
		$other        = $this->order( 2 );
		$other->items = array( 'line_item' => array( new \WC_Order_Item_Product( array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 3 ) ) ) );
		WooRegistry::$ordersResult = array( $order, $other );

		$units = ( new WooShopStock() )->unsyncedSoldUnits();

		$this->assertSame( array( 10 => 5, 111 => 1 ), $units );
		$query = WooRegistry::$orderQueries[0];
		$this->assertSame( array( 'wc-processing', 'wc-on-hold' ), $query['status'] );
		$this->assertSame( array( 'queued', 'syncing', 'retrying', 'failed' ), $query['meta_query'][0]['value'] );
	}

	public function test_a_product_has_a_name_and_a_quantity(): void {
		$this->product( 1, array( 'name' => 'Cedar lamp' ) );
		$this->assertSame( array( 'id' => 1, 'sku' => 'SKU-1', 'name' => 'Cedar lamp', 'quantity' => 5 ), ( new WooShopStock() )->product( 1 ) );
		$this->assertNull( ( new WooShopStock() )->product( 404 ) );
	}
}
