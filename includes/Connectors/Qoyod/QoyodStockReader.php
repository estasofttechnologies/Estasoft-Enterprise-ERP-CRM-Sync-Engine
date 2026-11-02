<?php
/**
 * Reads product stock from Qoyod.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A Qoyod product carries `inventories[]` with the stock of each branch (a decimal string) [documented in the product answer]. The stock of a
 * SKU is the sum over the branches asked for, or over all of them. Qoyod documents no reserved quantity, so `held` is 0. A product that does
 * not track quantity has no stock figure and is left out.
 */
final class QoyodStockReader {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodCatalog $catalog Branches and products.
	 */
	public function __construct( private readonly QoyodCatalog $catalog ) {
	}

	/**
	 * Stock for SKUs.
	 *
	 * @param ApiClient         $api        Client.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $inventories Branch ids that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}> Keyed by SKU as asked for.
	 *
	 * @throws ApiException On failure.
	 */
	public function fetch( ApiClient $api, array $skus, array $inventories ): array {
		$products = $this->catalog->productsBySkus( $api, $skus );
		$result   = array();
		foreach ( $skus as $sku ) {
			$product = $products[ strtoupper( trim( $sku ) ) ] ?? null;
			if ( null === $product || false === ( $product['track_quantity'] ?? true ) || ! is_array( $product['inventories'] ?? null ) ) {
				continue;
			}
			$quantity = 0;
			foreach ( $product['inventories'] as $branch ) {
				if ( ! is_array( $branch ) ) {
					continue;
				}
				if ( array() !== $inventories && ! in_array( QoyodPayloadReader::text( $branch['id'] ?? '' ), $inventories, true ) ) {
					continue;
				}
				$stock = $branch['stock'] ?? 0;
				if ( is_numeric( $stock ) ) {
					$quantity += (int) floor( (float) $stock );
				}
			}
			$result[ $sku ] = array(
				'quantity' => $quantity,
				'held'     => 0,
			);
		}
		return $result;
	}
}
