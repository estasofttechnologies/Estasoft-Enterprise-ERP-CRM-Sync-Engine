<?php
/**
 * Keeps each product's "matched or not" verdict current.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping module fires estasoft_sync_product_match after it looked a SKU up while building an order. This writes the verdict on the
 * product, so the product box and the "Not matched" filter can show it without asking the connected system.
 */
final class ProductMatchRecorder {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ProductMeta $meta Product meta.
	 */
	public function __construct( private readonly ProductMeta $meta ) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'estasoft_sync_product_match', array( $this, 'record' ), 10, 4 );
	}

	/**
	 * Write a verdict.
	 *
	 * @param mixed $connectionId Connection (unused).
	 * @param mixed $productId    WooCommerce product id.
	 * @param mixed $sku          SKU (unused).
	 * @param mixed $matched      Whether the connected system has the product.
	 */
	public function record( mixed $connectionId = 0, mixed $productId = 0, mixed $sku = '', mixed $matched = false ): void {
		unset( $connectionId, $sku );
		if ( (int) $productId <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( (int) $productId );
		if ( $product instanceof \WC_Product ) {
			$this->meta->recordMatch( $product, (bool) $matched );
		}
	}
}
