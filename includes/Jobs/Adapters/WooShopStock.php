<?php
/**
 * Product stock through the WooCommerce data layer.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\ShopStock;

/**
 * Reads with `wc_get_products`, `wc_get_orders` and product objects; writes with `set_stock_quantity()` and `save()`. No SQL on
 * posts, post meta or the order tables. The system's quantity and the time are kept in the product meta the product box shows.
 */
final class WooShopStock implements ShopStock {

	/**
	 * Product meta: the person's "exclude from syncing" flag.
	 */
	public const EXCLUDE = '_estasoft_sync_exclude';

	/**
	 * Product meta: a safety buffer for this product only.
	 */
	public const BUFFER = '_estasoft_sync_stock_buffer';

	/**
	 * Product meta: the last quantity the system reported.
	 */
	public const LAST_STOCK = '_estasoft_sync_last_stock';

	/**
	 * Product meta: when the last quantity was read (UTC).
	 */
	public const LAST_STOCK_AT = '_estasoft_sync_last_stock_at';

	/**
	 * Order meta read to find orders the system does not know yet.
	 */
	private const ORDER_STATE = '_estasoft_sync_state';

	/**
	 * Most orders looked at when counting units sold but not yet synced.
	 */
	private const ORDER_LIMIT = 500;

	/**
	 * One page of products and variations that carry a SKU.
	 *
	 * @param int $page    Page from 1.
	 * @param int $perPage Rows per page.
	 * @return array{items:array<int,array{id:int,sku:string,name:string,manages_stock:bool,quantity:?int,excluded:bool,buffer:?int}>,has_more:bool}
	 */
	public function products( int $page, int $perPage ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'items'    => array(),
				'has_more' => false,
			);
		}
		$result = wc_get_products(
			array(
				'status'   => array( 'publish', 'private' ),
				'type'     => array( 'simple', 'variation' ),
				'limit'    => max( 1, $perPage ),
				'page'     => max( 1, $page ),
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'paginate' => true,
				'return'   => 'objects',
			)
		);
		$products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : ( is_array( $result ) ? $result : array() );
		$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
		$items    = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' === $sku ) {
				continue;
			}
			$stock    = $product->get_stock_quantity();
			$buffer   = $product->get_meta( self::BUFFER, true );
			$items[]  = array(
				'id'            => (int) $product->get_id(),
				'sku'           => $sku,
				'name'          => (string) $product->get_name(),
				'manages_stock' => true === $product->get_manage_stock( 'edit' ),
				'quantity'      => null === $stock ? null : (int) $stock,
				'excluded'      => $this->excluded( $product ),
				'buffer'        => is_numeric( $buffer ) ? max( 0, (int) $buffer ) : null,
			);
		}
		return array(
			'items'    => $items,
			'has_more' => $page < $pages,
		);
	}

	/**
	 * Set the stock quantity.
	 *
	 * @param int    $productId      Product or variation id.
	 * @param int    $quantity       New shop quantity.
	 * @param int    $systemQuantity Quantity the system reported.
	 * @param string $atUtc          UTC "Y-m-d H:i:s".
	 * @return bool Whether the product was saved.
	 */
	public function setQuantity( int $productId, int $quantity, int $systemQuantity, string $atUtc ): bool {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : false;
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		$product->set_stock_quantity( max( 0, $quantity ) );
		$product->update_meta_data( self::LAST_STOCK, (string) $systemQuantity );
		$product->update_meta_data( self::LAST_STOCK_AT, $atUtc );
		$product->save();
		return true;
	}

	/**
	 * Units held by unpaid orders.
	 *
	 * @param int $productId Product or variation id.
	 */
	public function heldUnits( int $productId ): int {
		if ( ! function_exists( 'wc_get_held_stock_quantity' ) || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$product = wc_get_product( $productId );
		return $product instanceof \WC_Product ? max( 0, (int) wc_get_held_stock_quantity( $product ) ) : 0;
	}

	/**
	 * Units in paid orders the system does not know yet.
	 *
	 * @return array<int,int>
	 */
	public function unsyncedSoldUnits(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'status'     => array( 'wc-processing', 'wc-on-hold' ),
				'limit'      => self::ORDER_LIMIT,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::ORDER_STATE,
						'value'   => array( 'queued', 'syncing', 'retrying', 'failed' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$units = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items() as $line ) {
				if ( ! $line instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$id = (int) $line->get_variation_id() > 0 ? (int) $line->get_variation_id() : (int) $line->get_product_id();
				if ( $id > 0 ) {
					$units[ $id ] = ( $units[ $id ] ?? 0 ) + max( 0, (int) $line->get_quantity() );
				}
			}
		}
		return $units;
	}

	/**
	 * Name and quantity of a product.
	 *
	 * @param int $productId Product or variation id.
	 * @return array{id:int,sku:string,name:string,quantity:?int}|null
	 */
	public function product( int $productId ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : false;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$stock = $product->get_stock_quantity();
		return array(
			'id'       => (int) $product->get_id(),
			'sku'      => (string) $product->get_sku(),
			'name'     => (string) $product->get_name(),
			'quantity' => null === $stock ? null : (int) $stock,
		);
	}

	/**
	 * Whether the product, or the parent of a variation, is marked "Exclude from syncing".
	 *
	 * @param \WC_Product $product Product.
	 */
	private function excluded( \WC_Product $product ): bool {
		if ( $this->yes( $product->get_meta( self::EXCLUDE, true ) ) ) {
			return true;
		}
		$parent = (int) $product->get_parent_id();
		if ( $parent > 0 && function_exists( 'wc_get_product' ) ) {
			$parent_product = wc_get_product( $parent );
			return $parent_product instanceof \WC_Product && $this->yes( $parent_product->get_meta( self::EXCLUDE, true ) );
		}
		return false;
	}

	/**
	 * Whether a stored flag means yes.
	 *
	 * @param mixed $value Stored value.
	 */
	private function yes( mixed $value ): bool {
		return in_array( $value, array( 'yes', '1', 1, true ), true );
	}
}
