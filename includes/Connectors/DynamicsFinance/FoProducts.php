<?php
/**
 * Released products of the legal entity.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Items found by their item number (`ReleasedProductsV2.ItemNumber`), which is the WooCommerce SKU. The search uses the wildcard form the
 * OData page documents for strings (`eq '*text*'`); a `*` typed by the person is taken out first.
 */
final class FoProducts {

	/**
	 * Matches returned by a product search.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param FoClient $fo Client.
	 */
	public function __construct( private readonly FoClient $fo ) {
	}

	/**
	 * The released product with an item number, or null.
	 *
	 * @param string $sku SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku || ! FoClient::literalSafe( $sku ) ) {
			return null;
		}
		foreach ( $this->fo->rows( 'ReleasedProductsV2', array( OData::eq( 'ItemNumber', $sku ) ) ) as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['ItemNumber'] ?? '' ), $sku ) ) {
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
	 * Products whose item number contains a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( string $query ): array {
		$query = trim( str_replace( '*', '', $query ) );
		if ( '' === $query ) {
			return array();
		}
		return array_slice( $this->fo->rows( 'ReleasedProductsV2', array( OData::eq( 'ItemNumber', '*' . $query . '*' ) ), array( '$top' => self::SEARCH_LIMIT ) ), 0, self::SEARCH_LIMIT );
	}

	/**
	 * A product row as a reference.
	 *
	 * @param array<string,mixed> $row Product row.
	 */
	public function ref( array $row ): ExternalRef {
		return new ExternalRef(
			(string) ( $row['ItemNumber'] ?? '' ),
			(string) ( $row['ItemNumber'] ?? '' ),
			null,
			array(
				'name' => (string) ( $row['SearchName'] ?? $row['ProductName'] ?? '' ),
			)
		);
	}
}
