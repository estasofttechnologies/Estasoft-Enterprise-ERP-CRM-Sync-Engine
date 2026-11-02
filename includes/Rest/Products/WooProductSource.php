<?php
/**
 * WooCommerce products through the WooCommerce product API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Products;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Woo\ProductMeta;

/**
 * Uses `wc_get_products()` and `wc_get_product()` only. A product is listed when its SKU is empty or the plugin recorded that the
 * connected system did not know it (the match flag the Woo module writes while orders are built).
 */
final class WooProductSource implements ProductSource {

	/**
	 * One page of unmatched products, newest first.
	 *
	 * @param int $page    Page from 1.
	 * @param int $perPage Products per page.
	 * @return array{items:array<int,array{id:int,sku:string,name:string}>,total:int}
	 */
	public function unmatched( int $page, int $perPage ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$result = wc_get_products(
			array(
				'status'     => 'publish',
				'limit'      => $perPage,
				'page'       => $page,
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => ProductMeta::MATCH,
						'value' => ProductMeta::UNMATCHED,
					),
					array(
						'key'     => '_sku',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_sku',
						'value' => '',
					),
				),
			)
		);
		$items = array();
		foreach ( is_object( $result ) && isset( $result->products ) ? (array) $result->products : array() as $product ) {
			$items[] = $this->shape( $product );
		}
		return array(
			'items' => $items,
			'total' => is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $items ),
		);
	}

	/**
	 * One product.
	 *
	 * @param int $id Product or variation id.
	 * @return array{id:int,sku:string,name:string}|null
	 */
	public function find( int $id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product ? $this->shape( $product ) : null;
	}

	/**
	 * The three facts the screen shows.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{id:int,sku:string,name:string}
	 */
	private function shape( \WC_Product $product ): array {
		return array(
			'id'   => (int) $product->get_id(),
			'sku'  => (string) $product->get_sku(),
			'name' => (string) $product->get_name(),
		);
	}
}
