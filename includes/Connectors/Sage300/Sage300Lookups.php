<?php
/**
 * Lookups of Sage 300 setup data: items, tax groups, locations, the customer.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Items are read by key (`ICItems('SKU')`), one request each, and cached for the call.
 */
final class Sage300Lookups {

	/**
	 * Items found so far by lower-case SKU.
	 *
	 * @var array<string,array{id:string,unit:string}|null>
	 */
	private array $items = array();

	/**
	 * Tax groups by lower-case key, loaded once.
	 *
	 * @var array<string,array{id:string,name:string}>|null
	 */
	private ?array $groups = null;

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage300Client $client Client.
	 */
	public function __construct( private readonly Sage300Client $client ) {
	}

	/**
	 * Items by item number.
	 *
	 * @param array<int,string> $skus SKUs (item numbers).
	 * @return array<string,array{id:string,unit:string}> Keyed by lower-case SKU; unknown items are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function items( array $skus ): array {
		$found = array();
		foreach ( $skus as $sku ) {
			$sku = trim( $sku );
			$low = strtolower( $sku );
			if ( '' === $low ) {
				continue;
			}
			if ( ! array_key_exists( $low, $this->items ) ) {
				$row                 = $this->client->find( Sage300Client::keyed( 'IC/ICItems', $sku ) );
				$this->items[ $low ] = null === $row
					? null
					: array(
						'id'   => isset( $row['ItemNumber'] ) && is_scalar( $row['ItemNumber'] ) && '' !== (string) $row['ItemNumber'] ? trim( (string) $row['ItemNumber'] ) : $sku,
						'unit' => isset( $row['StockingUnitOfMeasure'] ) && is_scalar( $row['StockingUnitOfMeasure'] ) ? trim( (string) $row['StockingUnitOfMeasure'] ) : '',
					);
			}
			if ( null !== $this->items[ $low ] ) {
				$found[ $low ] = $this->items[ $low ];
			}
		}
		return $found;
	}

	/**
	 * The tax groups of the company.
	 *
	 * @return array<string,array{id:string,name:string}> Keyed by lower-case group key.
	 *
	 * @throws ApiException On failure.
	 */
	public function taxGroups(): array {
		if ( null === $this->groups ) {
			$this->groups = array();
			foreach ( $this->client->rows( 'TX/TXTaxGroups', '', 500 ) as $row ) {
				$key = isset( $row['TaxGroupKey'] ) && is_scalar( $row['TaxGroupKey'] ) ? trim( (string) $row['TaxGroupKey'] ) : '';
				if ( '' !== $key ) {
					$this->groups[ strtolower( $key ) ] = array(
						'id'   => $key,
						'name' => isset( $row['Description'] ) && is_scalar( $row['Description'] ) ? trim( (string) $row['Description'] ) : $key,
					);
				}
			}
		}
		return $this->groups;
	}

	/**
	 * The inventory locations of the company.
	 *
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function locations(): array {
		$out = array();
		foreach ( $this->client->rows( 'IC/ICLocations', '', 100 ) as $row ) {
			$id = isset( $row['Location'] ) && is_scalar( $row['Location'] ) ? trim( (string) $row['Location'] ) : '';
			if ( '' !== $id ) {
				$name  = isset( $row['Description'] ) && is_scalar( $row['Description'] ) ? trim( (string) $row['Description'] ) : '';
				$out[] = array(
					'id'   => $id,
					'name' => '' !== $name ? $name : $id,
				);
			}
		}
		return $out;
	}

	/**
	 * A customer by number.
	 *
	 * @param string $number Customer number.
	 * @return array{id:string,name:string,currency:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function customer( string $number ): ?array {
		$row = $this->client->find( Sage300Client::keyed( 'AR/ARCustomers', $number ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'       => isset( $row['CustomerNumber'] ) && is_scalar( $row['CustomerNumber'] ) && '' !== (string) $row['CustomerNumber'] ? trim( (string) $row['CustomerNumber'] ) : $number,
			'name'     => isset( $row['CustomerName'] ) && is_scalar( $row['CustomerName'] ) ? trim( (string) $row['CustomerName'] ) : '',
			'currency' => isset( $row['CurrencyCode'] ) && is_scalar( $row['CurrencyCode'] ) ? strtoupper( trim( (string) $row['CurrencyCode'] ) ) : '',
		);
	}
}
