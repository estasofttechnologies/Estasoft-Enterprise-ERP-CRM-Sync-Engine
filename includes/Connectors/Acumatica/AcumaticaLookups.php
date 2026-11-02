<?php
/**
 * Lookups of Acumatica setup data: stock and non-stock items, tax categories, warehouses.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Inventory IDs are matched without regard to case (the database collation does the same). An item that is not Active is treated as
 * missing, so an order never carries a product that Acumatica would refuse to sell.
 */
final class AcumaticaLookups {

	/**
	 * Inventory IDs asked for in one filter.
	 */
	private const CHUNK = 10;

	/**
	 * Items found so far by lower-case SKU: the Inventory ID and whether it is a stock item.
	 *
	 * @var array<string,array{id:string,stock:bool}|null>
	 */
	private array $items = array();

	/**
	 * Tax categories by lower-case id, loaded once.
	 *
	 * @var array<string,array{id:string,name:string}>|null
	 */
	private ?array $categories = null;

	/**
	 * Set up with the services it uses.
	 *
	 * @param AcumaticaClient $client Client.
	 */
	public function __construct( private readonly AcumaticaClient $client ) {
	}

	/**
	 * Active items by SKU.
	 *
	 * @param array<int,string> $skus SKUs (Inventory IDs).
	 * @return array<string,array{id:string,stock:bool}> Keyed by lower-case SKU; unknown and inactive items are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function items( array $skus ): array {
		$wanted = array();
		foreach ( $skus as $sku ) {
			$low = strtolower( trim( $sku ) );
			if ( '' !== $low && ! array_key_exists( $low, $this->items ) ) {
				$wanted[ $low ] = trim( $sku );
			}
		}
		foreach ( array(
			'StockItem'    => true,
			'NonStockItem' => false,
		) as $entity => $stock ) {
			$missing = array_filter( $wanted, fn( string $low ): bool => ! isset( $this->items[ $low ] ), ARRAY_FILTER_USE_KEY );
			foreach ( array_chunk( array_values( $missing ), self::CHUNK ) as $chunk ) {
				$filter = implode( ' or ', array_map( static fn( string $sku ): string => 'InventoryID eq ' . AcumaticaValue::literal( $sku ), $chunk ) );
				foreach ( $this->client->query(
					$entity,
					array(
						'$filter' => $filter,
						'$select' => 'InventoryID,ItemStatus',
					)
				) as $row ) {
					$id = AcumaticaValue::text( $row, 'InventoryID' );
					if ( '' !== $id && 'active' === strtolower( AcumaticaValue::text( $row, 'ItemStatus' ) ) ) {
						$this->items[ strtolower( $id ) ] = array(
							'id'    => $id,
							'stock' => $stock,
						);
					}
				}
			}
		}
		$found = array();
		foreach ( $wanted as $low => $sku ) {
			if ( ! isset( $this->items[ $low ] ) ) {
				$this->items[ $low ] = null;
			}
		}
		foreach ( $skus as $sku ) {
			$low = strtolower( trim( $sku ) );
			if ( isset( $this->items[ $low ] ) ) {
				$found[ $low ] = $this->items[ $low ];
			}
		}
		return $found;
	}

	/**
	 * The tax categories of the site.
	 *
	 * @return array<string,array{id:string,name:string}> Keyed by lower-case id; inactive categories are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function taxCategories(): array {
		if ( null === $this->categories ) {
			$this->categories = array();
			foreach ( $this->client->query( 'TaxCategory', array( '$select' => 'TaxCategoryID,Description,Active' ), 500 ) as $row ) {
				$id = AcumaticaValue::text( $row, 'TaxCategoryID' );
				if ( '' !== $id && false !== AcumaticaValue::get( $row, 'Active' ) ) {
					$this->categories[ strtolower( $id ) ] = array(
						'id'   => $id,
						'name' => AcumaticaValue::text( $row, 'Description' ),
					);
				}
			}
		}
		return $this->categories;
	}

	/**
	 * The warehouses of the site.
	 *
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function warehouses(): array {
		$out = array();
		foreach ( $this->client->query( 'Warehouse', array( '$select' => 'WarehouseID,Description' ), 100 ) as $row ) {
			$id = AcumaticaValue::text( $row, 'WarehouseID' );
			if ( '' !== $id ) {
				$name  = AcumaticaValue::text( $row, 'Description' );
				$out[] = array(
					'id'   => $id,
					'name' => '' !== $name ? $name : $id,
				);
			}
		}
		return $out;
	}
}
