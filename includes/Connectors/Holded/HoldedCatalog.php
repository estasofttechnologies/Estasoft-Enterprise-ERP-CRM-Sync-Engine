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
use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Holded's v1 operations list has no product filter and no tax resource [third-party]. Products are read page by page and kept for ten minutes
 * in memory (a plan has a monthly call quota), indexed by SKU; the tax rate of a line is a plain percentage typed in the tax mapping.
 */
final class HoldedCatalog {

	/**
	 * Seconds a product list is kept.
	 */
	public const TTL = 600;

	/**
	 * The rates offered as suggestions on the tax mapping screen (Spanish VAT). Any other percentage can be typed.
	 */
	public const SUGGESTED_RATES = array( '21', '10', '4', '0' );

	/**
	 * Products by connection: [expires at, products by upper-case SKU].
	 *
	 * @var array<int,array{0:int,1:array<string,array<string,mixed>>}>
	 */
	private array $products = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedPager $pager Reads every page of a list.
	 * @param Clock       $clock Time source.
	 */
	public function __construct( private readonly HoldedPager $pager, private readonly Clock $clock ) {
	}

	/**
	 * The products by upper-case SKU. A product without a SKU is left out.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @return array<string,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function products( ApiClient $api, int $connectionId ): array {
		$now = $this->clock->now()->getTimestamp();
		if ( isset( $this->products[ $connectionId ] ) && $this->products[ $connectionId ][0] > $now ) {
			return $this->products[ $connectionId ][1];
		}
		$index = array();
		foreach ( $this->pager->all( $api, 'invoicing/v1/products' ) as $row ) {
			$sku = strtoupper( HoldedPayloadReader::text( $row['sku'] ?? '' ) );
			if ( '' !== $sku && ! isset( $index[ $sku ] ) ) {
				$index[ $sku ] = $row;
			}
		}
		$this->products[ $connectionId ] = array( $now + self::TTL, $index );
		return $index;
	}

	/**
	 * The product with a SKU (exact, case-insensitive).
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $sku          SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function productBySku( ApiClient $api, int $connectionId, string $sku ): ?array {
		$sku = strtoupper( trim( $sku ) );
		return '' === $sku ? null : ( $this->products( $api, $connectionId )[ $sku ] ?? null );
	}

	/**
	 * Products whose name or SKU contains a search text.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $query        Search text.
	 * @param int       $limit        Most products returned.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( ApiClient $api, int $connectionId, string $query, int $limit = 20 ): array {
		$query = mb_strtolower( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$found = array();
		foreach ( $this->products( $api, $connectionId ) as $sku => $row ) {
			if ( str_contains( mb_strtolower( $sku . ' ' . HoldedPayloadReader::text( $row['name'] ?? '' ) ), $query ) ) {
				$found[] = $row;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}
		return $found;
	}
}
