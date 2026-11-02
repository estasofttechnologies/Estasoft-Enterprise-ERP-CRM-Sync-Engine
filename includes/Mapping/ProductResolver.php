<?php
/**
 * Finds the connected system's product for a WooCommerce product.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ExternalRef;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by SkuMatcher. The sku_match transform depends on this interface only.
 */
interface ProductResolver {

	/**
	 * The system's product for a SKU, or null when there is none.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU as typed in WooCommerce.
	 * @param int    $productId    WooCommerce product id, 0 when unknown.
	 * @param int    $variationId  WooCommerce variation id, 0 when none.
	 * @return ExternalRef|null Id and reference of the system's product.
	 *
	 * @throws LookupFailedException When the system could not be asked (network, sign-in); the item is retried, not failed as unmatched.
	 */
	public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef;
}
