<?php
/**
 * Product stock in the shop.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes WooCommerce stock through the WooCommerce data layer only (`wc_get_products`, `$product->set_stock_quantity()`).
 */
interface ShopStock {

	/**
	 * One page of products and variations that carry a SKU, in id order.
	 *
	 * @param int $page    Page from 1.
	 * @param int $perPage Rows per page.
	 * @return array{items:array<int,array{id:int,sku:string,name:string,manages_stock:bool,quantity:?int,excluded:bool,buffer:?int}>,has_more:bool}
	 */
	public function products( int $page, int $perPage ): array;

	/**
	 * Set the stock quantity of a product, and remember the value read from the system and when.
	 *
	 * @param int    $productId Product or variation id.
	 * @param int    $quantity  New shop quantity (not below zero).
	 * @param int    $systemQuantity Quantity the system reported, for the product box.
	 * @param string $atUtc     UTC "Y-m-d H:i:s".
	 * @return bool Whether the product was saved.
	 */
	public function setQuantity( int $productId, int $quantity, int $systemQuantity, string $atUtc ): bool;

	/**
	 * Units held by unpaid orders for a product.
	 *
	 * @param int $productId Product or variation id.
	 */
	public function heldUnits( int $productId ): int;

	/**
	 * Units sold in paid orders that the system does not know yet (queued, retrying, failed or being sent).
	 *
	 * @return array<int,int> Product or variation id to units.
	 */
	public function unsyncedSoldUnits(): array;

	/**
	 * Name and current quantity of a product.
	 *
	 * @param int $productId Product or variation id.
	 * @return array{id:int,sku:string,name:string,quantity:?int}|null
	 */
	public function product( int $productId ): ?array;
}
