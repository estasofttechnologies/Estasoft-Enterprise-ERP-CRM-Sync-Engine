<?php
/**
 * Where the product matching screen gets WooCommerce products.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Products;

defined( 'ABSPATH' ) || exit;

/**
 * A port over WooCommerce, so the matching service is tested without it.
 */
interface ProductSource {

	/**
	 * One page of products that are not matched: no SKU, or the system did not know the SKU the last time it was asked.
	 *
	 * @param int $page    Page from 1.
	 * @param int $perPage Products per page.
	 * @return array{items:array<int,array{id:int,sku:string,name:string}>,total:int}
	 */
	public function unmatched( int $page, int $perPage ): array;

	/**
	 * One product.
	 *
	 * @param int $id Product or variation id.
	 * @return array{id:int,sku:string,name:string}|null
	 */
	public function find( int $id ): ?array;
}
