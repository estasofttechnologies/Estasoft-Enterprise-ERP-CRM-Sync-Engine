<?php
/**
 * Finds QuickBooks items by SKU or name.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The WooCommerce SKU is the QuickBooks item SKU (`Sku`, max 100 characters, filterable). Several SKUs are asked for with one query
 * (`Sku in ('A','B')`), twenty at a time. Inactive items are not returned by a query, so an inactive item counts as unknown.
 */
final class QuickBooksItems {

	/**
	 * SKUs asked for in one query.
	 */
	private const CHUNK = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksApi $api Client.
	 */
	public function __construct( private readonly QuickBooksApi $api ) {
	}

	/**
	 * Items for a list of SKUs.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>> Items keyed by the lower-case SKU.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function bySkus( array $skus ): array {
		$found = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( $skus, static fn( string $sku ): bool => '' !== $sku ) ) ), self::CHUNK ) as $chunk ) {
			$list = implode( ',', array_map( array( QuickBooksApi::class, 'quote' ), $chunk ) );
			foreach ( $this->api->select( 'Item', 'Sku in (' . $list . ')' ) as $item ) {
				if ( '' !== QuickBooksPayloadReader::text( $item['Sku'] ?? '' ) && 'Category' !== ( $item['Type'] ?? '' ) ) {
					$found[ strtolower( QuickBooksPayloadReader::text( $item['Sku'] ) ) ] = $item;
				}
			}
		}
		return $found;
	}

	/**
	 * The items named in the options for some kinds of line (shipping, fee, discount, generic). Each distinct name is looked up once.
	 *
	 * @param array<int,string> $kinds   Kinds needed.
	 * @param QuickBooksOptions $options Connection options.
	 * @return array<string,array<string,mixed>|null> Item per kind; null when the option is empty or QuickBooks has no such item.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function forKinds( array $kinds, QuickBooksOptions $options ): array {
		$named = array();
		$cache = array();
		foreach ( array_unique( $kinds ) as $kind ) {
			$value = $options->itemFor( $kind );
			if ( '' === $value ) {
				$named[ $kind ] = null;
				continue;
			}
			if ( ! array_key_exists( $value, $cache ) ) {
				$cache[ $value ] = $this->named( $value );
			}
			$named[ $kind ] = $cache[ $value ];
		}
		return $named;
	}

	/**
	 * An item named in a connection option: found by SKU, else by name.
	 *
	 * @param string $value SKU or name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function named( string $value ): ?array {
		if ( '' === $value ) {
			return null;
		}
		$bySku = $this->bySkus( array( $value ) );
		if ( isset( $bySku[ strtolower( $value ) ] ) ) {
			return $bySku[ strtolower( $value ) ];
		}
		foreach ( $this->api->select( 'Item', 'Name = ' . QuickBooksApi::quote( $value ) ) as $item ) {
			if ( 'Category' !== ( $item['Type'] ?? '' ) && 0 === strcasecmp( QuickBooksPayloadReader::text( $item['Name'] ?? '' ), $value ) ) {
				return $item;
			}
		}
		return null;
	}
}
