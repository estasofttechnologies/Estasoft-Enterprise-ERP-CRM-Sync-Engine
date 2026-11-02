<?php
/**
 * Taxes, products and warehouses of a Daftra account.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Daftra lists taxes (not paged) and finds products by `product_code`. Taxes are kept in memory for ten minutes per connection. A product code
 * filter is checked locally for an exact, case-insensitive match, because a filter may match more than the code asked for.
 */
final class DaftraCatalog {

	/**
	 * Seconds taxes are kept.
	 */
	public const TTL = 600;

	/**
	 * Most SKUs looked up one by one; more than this and the whole product list is read once instead.
	 */
	public const ONE_BY_ONE = 10;

	/**
	 * Taxes by connection: [expires at, taxes].
	 *
	 * @var array<int,array{0:int,1:array<string,array{id:string,name:string,rate_bp:?int,included:bool,active:bool}>}>
	 */
	private array $taxes = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraPager   $pager   Reads every page of a list.
	 * @param Clock         $clock   Time source.
	 * @param DaftraAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly DaftraPager $pager, private readonly Clock $clock, private readonly DaftraAmounts $amounts ) {
	}

	/**
	 * Taxes by id: name, rate in basis points (null when unreadable), whether the tax is included in prices, whether it is active.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @return array<string,array{id:string,name:string,rate_bp:?int,included:bool,active:bool}>
	 *
	 * @throws ApiException On failure.
	 */
	public function taxes( ApiClient $api, int $connectionId ): array {
		$now = $this->clock->now()->getTimestamp();
		if ( isset( $this->taxes[ $connectionId ] ) && $this->taxes[ $connectionId ][0] > $now ) {
			return $this->taxes[ $connectionId ][1];
		}
		$taxes = array();
		foreach ( $this->pager->all( $api, 'taxes.json', array(), 'Tax' ) as $row ) {
			$id = DaftraPayloadReader::text( $row['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$taxes[ $id ] = array(
				'id'       => $id,
				'name'     => DaftraPayloadReader::text( $row['name'] ?? $id ),
				'rate_bp'  => $this->amounts->basisPoints( $row['value'] ?? null ),
				'included' => DaftraPayloadReader::flag( $row['included'] ?? null, false ),
				'active'   => DaftraPayloadReader::flag( $row['is_active'] ?? null, true ),
			);
		}
		$this->taxes[ $connectionId ] = array( $now + self::TTL, $taxes );
		return $taxes;
	}

	/**
	 * Warehouses.
	 *
	 * @param ApiClient $api Client.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function stores( ApiClient $api ): array {
		return $this->pager->all( $api, 'stores.json', array(), 'Store' );
	}

	/**
	 * The product with a code (exact, case-insensitive).
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Product code.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function productBySku( ApiClient $api, string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		foreach ( $this->pager->all( $api, 'products.json', array( 'product_code' => $sku ), 'Product' ) as $product ) {
			if ( 0 === strcasecmp( DaftraPayloadReader::text( $product['product_code'] ?? '' ), $sku ) ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * Products whose name or code matches a search text.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $query Search text.
	 * @param int       $limit Most products returned.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( ApiClient $api, string $query, int $limit = 20 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		return array_slice( $this->pager->all( $api, 'products.json', array( 'keywords' => $query ), 'Product' ), 0, $limit );
	}

	/**
	 * Products for a list of SKUs: looked up one by one when there are few, read from the whole list when there are many.
	 *
	 * @param ApiClient         $api  Client.
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>> Products by upper-case product code.
	 *
	 * @throws ApiException On failure.
	 */
	public function productsBySkus( ApiClient $api, array $skus ): array {
		$wanted = array_values( array_unique( array_filter( array_map( 'trim', $skus ) ) ) );
		$found  = array();
		if ( count( $wanted ) <= self::ONE_BY_ONE ) {
			foreach ( $wanted as $sku ) {
				$product = $this->productBySku( $api, $sku );
				if ( null !== $product ) {
					$found[ strtoupper( $sku ) ] = $product;
				}
			}
			return $found;
		}
		$index = array_flip( array_map( 'strtoupper', $wanted ) );
		foreach ( $this->pager->all( $api, 'products.json', array(), 'Product' ) as $product ) {
			$code = strtoupper( DaftraPayloadReader::text( $product['product_code'] ?? '' ) );
			if ( isset( $index[ $code ] ) ) {
				$found[ $code ] = $product;
			}
		}
		return $found;
	}
}
