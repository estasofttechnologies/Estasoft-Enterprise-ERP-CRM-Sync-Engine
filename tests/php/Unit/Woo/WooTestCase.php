<?php
/**
 * Shared set-up for the WooCommerce adapter tests: fakes, a reset registry, and builders for orders and products.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

require_once dirname( __DIR__, 2 ) . '/Support/WooFakes.php';
require_once dirname( __DIR__, 2 ) . '/Support/WooWpStubs.php';

use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use PHPUnit\Framework\TestCase;

abstract class WooTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WooRegistry::reset();
		\WC_Customer::$known = array();
	}

	/**
	 * A product.
	 *
	 * @param int                 $id    Id.
	 * @param array<string,mixed> $props Properties (sku, type, stock_quantity, parent_id, name).
	 * @param array<string,mixed> $meta  Meta.
	 */
	protected function product( int $id, array $props = array(), array $meta = array() ): \WC_Product {
		$product       = new \WC_Product( array( 'id' => $id ) + $props );
		$product->meta = $meta;
		WooRegistry::$products[ $id ] = $product;
		return $product;
	}

	/**
	 * A product line.
	 *
	 * @param \WC_Product|null    $product Product, or null for a deleted one.
	 * @param array<string,mixed> $props   Line properties.
	 */
	protected function line( ?\WC_Product $product, array $props = array() ): \WC_Order_Item_Product {
		return new \WC_Order_Item_Product(
			array_merge(
				array(
					'product'      => $product ?? false,
					'product_id'   => $product ? $product->get_id() : 0,
					'variation_id' => 0,
					'name'         => 'Line',
					'quantity'     => 1,
					'subtotal'     => '10.00',
					'total'        => '10.00',
					'total_tax'    => '0.00',
					'taxes'        => array( 'total' => array() ),
					'tax_class'    => '',
				),
				$props
			)
		);
	}

	/**
	 * An order registered with wc_get_order().
	 *
	 * @param int                                $id    Id.
	 * @param array<string,mixed>                $props Properties.
	 * @param array<string,array<int,object>>    $items Items by type.
	 * @param array<string,mixed>                $meta  Meta.
	 */
	protected function order( int $id, array $props = array(), array $items = array(), array $meta = array() ): \WC_Order {
		$order = new \WC_Order(
			array_merge(
				array(
					'id'                     => $id,
					'status'                 => 'processing',
					'currency'               => 'AED',
					'total'                  => '486.15',
					'subtotal'               => '438.00',
					'discount_total'         => '0.00',
					'shipping_total'         => '25.00',
					'total_tax'              => '23.15',
					'customer_id'            => 0,
					'payment_method'         => 'cod',
					'payment_method_title'   => 'Cash on delivery',
					'transaction_id'         => '',
					'customer_note'          => 'Gift wrap please',
					'billing_first_name'     => 'Noor',
					'billing_last_name'      => 'Al-Mansouri',
					'billing_email'          => 'noor@example.com',
					'billing_phone'          => '050 123 4567',
					'billing_country'        => 'AE',
					'billing_state'          => 'DU',
					'billing_city'           => 'Dubai',
					'date_created'           => new \DateTimeImmutable( '2026-11-02 07:34:00', new \DateTimeZone( 'UTC' ) ),
				),
				$props
			)
		);
		$order->items = $items;
		$order->meta  = $meta;
		WooRegistry::$orders[ $id ] = $order;
		return $order;
	}
}
