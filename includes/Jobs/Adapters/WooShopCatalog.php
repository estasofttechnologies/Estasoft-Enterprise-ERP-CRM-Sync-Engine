<?php
/**
 * Products for the readiness checks, through wc_get_products.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\ShopCatalog;

/**
 * Scans sellable products and variations in pages of 100 (at most 10,000 products) once per object and answers from the scan.
 */
final class WooShopCatalog implements ShopCatalog {

	/**
	 * Products read per call.
	 */
	private const PAGE = 100;

	/**
	 * Most products scanned.
	 */
	private const MAX_PRODUCTS = 10000;

	/**
	 * Result of the scan.
	 *
	 * @var array{count:int,missing:array<int,array{id:int,name:string}>,skus:array<string,array<int,int>>}|null
	 */
	private ?array $scan = null;

	/**
	 * Number of products checked.
	 */
	public function productCount(): int {
		return $this->scan()['count'];
	}

	/**
	 * Products without a SKU.
	 *
	 * @param int $limit Most rows to list.
	 * @return array{total:int,items:array<int,array{id:int,name:string}>}
	 */
	public function withoutSku( int $limit ): array {
		$missing = $this->scan()['missing'];
		return array(
			'total' => count( $missing ),
			'items' => array_slice( $missing, 0, max( 0, $limit ) ),
		);
	}

	/**
	 * SKUs used more than once.
	 *
	 * @param int $limit Most SKUs to list.
	 * @return array{total:int,items:array<int,array{sku:string,ids:array<int,int>}>}
	 */
	public function duplicateSkus( int $limit ): array {
		$items = array();
		foreach ( $this->scan()['skus'] as $sku => $ids ) {
			if ( count( $ids ) > 1 ) {
				$items[] = array(
					'sku' => (string) $sku,
					'ids' => $ids,
				);
			}
		}
		return array(
			'total' => count( $items ),
			'items' => array_slice( $items, 0, max( 0, $limit ) ),
		);
	}

	/**
	 * Names and SKUs of some products.
	 *
	 * @param array<int,int> $ids Product or variation ids.
	 * @return array<int,array{id:int,sku:string,name:string}>
	 */
	public function byId( array $ids ): array {
		$found = array();
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $found;
		}
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product ) {
				$found[ $id ] = array(
					'id'   => $id,
					'sku'  => (string) $product->get_sku(),
					'name' => (string) $product->get_name(),
				);
			}
		}
		return $found;
	}

	/**
	 * Scan the catalog once.
	 *
	 * @return array{count:int,missing:array<int,array{id:int,name:string}>,skus:array<string,array<int,int>>}
	 */
	private function scan(): array {
		if ( null !== $this->scan ) {
			return $this->scan;
		}
		$scan = array(
			'count'   => 0,
			'missing' => array(),
			'skus'    => array(),
		);
		if ( ! function_exists( 'wc_get_products' ) ) {
			$this->scan = $scan;
			return $scan;
		}
		for ( $page = 1; $scan['count'] < self::MAX_PRODUCTS; ++$page ) {
			$result   = wc_get_products(
				array(
					'status'   => array( 'publish', 'private' ),
					'type'     => array( 'simple', 'variation' ),
					'limit'    => self::PAGE,
					'page'     => $page,
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'paginate' => true,
					'return'   => 'objects',
				)
			);
			$products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : ( is_array( $result ) ? $result : array() );
			$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
			foreach ( $products as $product ) {
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				++$scan['count'];
				$sku = trim( (string) $product->get_sku() );
				if ( '' === $sku ) {
					$scan['missing'][] = array(
						'id'   => (int) $product->get_id(),
						'name' => (string) $product->get_name(),
					);
					continue;
				}
				$scan['skus'][ strtolower( $sku ) ][] = (int) $product->get_id();
			}
			if ( $page >= $pages || array() === $products ) {
				break;
			}
		}
		$this->scan = $scan;
		return $scan;
	}
}
