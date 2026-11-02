<?php
/**
 * Set-up shared by the adapter tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

require_once dirname( __DIR__, 2 ) . '/Support/JobsWooFakes.php';

use Estasoft\SyncEngine\Tests\Support\JobsWooRegistry;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use PHPUnit\Framework\TestCase;

abstract class JobsAdapterTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WooRegistry::reset();
		JobsWooRegistry::reset();
	}

	/**
	 * Register an order with wc_get_order().
	 *
	 * @param int                 $id    Id.
	 * @param array<string,mixed> $props Properties over the defaults.
	 * @param array<string,mixed> $meta  Meta.
	 */
	protected function order( int $id, array $props = array(), array $meta = array() ): \Jobs_Fake_Wc_Order {
		$order = new \Jobs_Fake_Wc_Order(
			array_merge(
				array(
					'id'                 => $id,
					'status'             => 'processing',
					'currency'           => 'AED',
					'total'              => '486.15',
					'total_refunded'     => '0.00',
					'billing_email'      => 'noor@example.test',
					'billing_first_name' => 'Noor',
					'billing_last_name'  => 'Al-Mansouri',
					'payment_method'     => 'cod',
					'date_created'       => new \DateTimeImmutable( '2026-11-02 11:21:00', new \DateTimeZone( 'Asia/Dubai' ) ),
				),
				$props
			)
		);
		$order->meta                  = $meta;
		WooRegistry::$orders[ $id ] = $order;
		return $order;
	}

	/**
	 * Register a product with wc_get_product().
	 *
	 * @param int                 $id    Id.
	 * @param array<string,mixed> $props Properties over the defaults.
	 * @param array<string,mixed> $meta  Meta.
	 */
	protected function product( int $id, array $props = array(), array $meta = array() ): \Jobs_Fake_Wc_Product {
		$product       = new \Jobs_Fake_Wc_Product( array_merge( array( 'id' => $id, 'sku' => 'SKU-' . $id, 'name' => 'Product ' . $id, 'manage_stock' => true, 'stock_quantity' => 5 ), $props ) );
		$product->meta = $meta;
		WooRegistry::$products[ $id ] = $product;
		return $product;
	}
}
