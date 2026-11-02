<?php
/**
 * Products as the readiness checks see them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WooCommerce products through `wc_get_products` only.
 */
interface ShopCatalog {

	/**
	 * Number of sellable products and variations checked.
	 */
	public function productCount(): int;

	/**
	 * Products and variations without a SKU.
	 *
	 * @param int $limit Most rows to list.
	 * @return array{total:int,items:array<int,array{id:int,name:string}>}
	 */
	public function withoutSku( int $limit ): array;

	/**
	 * SKUs used by more than one product.
	 *
	 * @param int $limit Most SKUs to list.
	 * @return array{total:int,items:array<int,array{sku:string,ids:array<int,int>}>}
	 */
	public function duplicateSkus( int $limit ): array;

	/**
	 * Names and SKUs of some products.
	 *
	 * @param array<int,int> $ids Product or variation ids.
	 * @return array<int,array{id:int,sku:string,name:string}> Keyed by id.
	 */
	public function byId( array $ids ): array;
}
