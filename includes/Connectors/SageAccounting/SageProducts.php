<?php
/**
 * Products of the business.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The WooCommerce SKU is the Sage product's item code. The search parameter finds products by text, so each SKU is searched and the item code is compared
 * exactly (ignoring case); retired products count as unknown.
 */
final class SageProducts {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageApi $api Client.
	 */
	public function __construct( private readonly SageApi $api ) {
	}

	/**
	 * Products for a list of SKUs.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>> Products keyed by the lower-case item code.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function byCodes( array $skus ): array {
		$found = array();
		foreach ( array_values( array_unique( array_filter( $skus, static fn( string $sku ): bool => '' !== $sku ) ) ) as $sku ) {
			$product = $this->product( $sku );
			if ( null !== $product ) {
				$found[ strtolower( $sku ) ] = $product;
			}
		}
		return $found;
	}

	/**
	 * One product by item code.
	 *
	 * @param string $sku SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function product( string $sku ): ?array {
		foreach ( $this->api->select( 'products', array( 'search' => $sku ), 1 ) as $product ) {
			if ( 0 === strcasecmp( SagePayloadReader::text( $product['item_code'] ?? '' ), $sku ) && false !== ( $product['active'] ?? true ) ) {
				return $product;
			}
		}
		return null;
	}
}
