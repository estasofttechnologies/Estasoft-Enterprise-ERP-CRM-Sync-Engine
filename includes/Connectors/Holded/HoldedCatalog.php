<?php
/**
 * Products and the tax rates Holded works with.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Holded's v1 operations list has no product filter and no tax resource [third-party]. Products are found through the product index
 * (`HoldedIndex`), kept between requests and read page by page; the tax rate of a line is a plain percentage typed in the tax mapping.
 */
final class HoldedCatalog {

	/**
	 * The rates offered as suggestions on the tax mapping screen (Spanish VAT). Any other percentage can be typed.
	 */
	public const SUGGESTED_RATES = array( '21', '10', '4', '0' );

	/**
	 * Name of the product index.
	 */
	public const INDEX = 'products';

	/**
	 * Seconds a finished product index is trusted for a SKU it does not hold; after that the list is read again, so a product added in Holded
	 * is found within minutes.
	 */
	public const MISS_TTL = 300;

	/**
	 * Path of the product list.
	 */
	private const PATH = 'invoicing/v1/products';

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedIndex $index Persistent indexes.
	 */
	public function __construct( private readonly HoldedIndex $index ) {
	}

	/**
	 * The product with a SKU (exact, case-insensitive): its id, name and SKU as Holded writes it.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $sku          SKU.
	 * @return array{id:string,name:string,sku:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function productBySku( ApiClient $api, int $connectionId, string $sku ): ?array {
		$sku = strtoupper( trim( $sku ) );
		if ( '' === $sku ) {
			return null;
		}
		$value = $this->index->find( $api, $connectionId, self::INDEX, self::PATH, $this->entry(), $sku, self::MISS_TTL );
		return null === $value ? null : $this->decode( $value );
	}

	/**
	 * Products whose name or SKU contains a search text. This reads the whole product list.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $query        Search text.
	 * @param int       $limit        Most products returned.
	 * @return array<int,array{id:string,name:string,sku:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( ApiClient $api, int $connectionId, string $query, int $limit = 20 ): array {
		$query = mb_strtolower( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$found = array();
		foreach ( $this->index->all( $api, $connectionId, self::INDEX, self::PATH, $this->entry() ) as $value ) {
			$product = $this->decode( $value );
			if ( str_contains( mb_strtolower( $product['sku'] . ' ' . $product['name'] ), $query ) ) {
				$found[] = $product;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Turns a product record into its index entry: the upper-case SKU, and `id`, `name`, `sku` on separate lines.
	 *
	 * @return callable(array<string,mixed>):?array{0:string,1:string}
	 */
	private function entry(): callable {
		return static function ( array $row ): ?array {
			$sku = HoldedPayloadReader::text( $row['sku'] ?? '' );
			$id  = HoldedPayloadReader::text( $row['id'] ?? '' );
			if ( '' === $sku || '' === $id ) {
				return null;
			}
			$name = HoldedPayloadReader::shorten( str_replace( array( "\r", "\n" ), ' ', HoldedPayloadReader::text( $row['name'] ?? '' ) ), 80 );
			return array( strtoupper( $sku ), $id . "\n" . $name . "\n" . str_replace( array( "\r", "\n" ), ' ', $sku ) );
		};
	}

	/**
	 * An index entry as a product.
	 *
	 * @param string $value The entry.
	 * @return array{id:string,name:string,sku:string}
	 */
	private function decode( string $value ): array {
		$parts = explode( "\n", $value, 3 );
		return array(
			'id'   => $parts[0],
			'name' => $parts[1] ?? '',
			'sku'  => $parts[2] ?? '',
		);
	}
}
