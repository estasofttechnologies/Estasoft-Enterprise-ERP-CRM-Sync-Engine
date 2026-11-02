<?php
/**
 * Products of the environment.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Active products found by their Product ID (`productnumber`), which is the WooCommerce SKU.
 */
final class DvProducts {

	/**
	 * Matches returned by a product search.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Columns read of a product.
	 */
	private const SELECT = 'productid,productnumber,name,_defaultuomid_value,statecode,price';

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient $dv Client.
	 */
	public function __construct( private readonly DvClient $dv ) {
	}

	/**
	 * The active product with a Product ID, or null.
	 *
	 * @param string $sku SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$rows = $this->dv->rows(
			'products',
			array(
				'$filter' => OData::all( array( OData::eq( 'productnumber', $sku ), 'statecode eq 0' ) ),
				'$select' => self::SELECT,
			)
		);
		foreach ( $rows as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['productnumber'] ?? '' ), $sku ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The products of a list of SKUs, keyed by SKU (null for an unknown one).
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>|null>
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( array $skus ): array {
		$found = array();
		foreach ( array_unique( $skus ) as $sku ) {
			$found[ $sku ] = $this->find( $sku );
		}
		return $found;
	}

	/**
	 * Products whose Product ID starts with, or whose name contains, a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		return array_slice(
			$this->dv->rows(
				'products',
				array(
					'$filter' => OData::all( array( '(' . OData::startsWith( 'productnumber', $query ) . ' or ' . OData::contains( 'name', $query ) . ')', 'statecode eq 0' ) ),
					'$select' => self::SELECT,
					'$top'    => self::SEARCH_LIMIT,
				)
			),
			0,
			self::SEARCH_LIMIT
		);
	}

	/**
	 * A product row as a reference.
	 *
	 * @param array<string,mixed> $row Product row.
	 */
	public function ref( array $row ): ExternalRef {
		return new ExternalRef(
			(string) ( $row['productid'] ?? '' ),
			(string) ( $row['productnumber'] ?? '' ),
			null,
			array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'price' => $row['price'] ?? null,
			)
		);
	}
}
