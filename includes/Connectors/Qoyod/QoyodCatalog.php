<?php
/**
 * Branches, products and tax codes of a Qoyod company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Qoyod lists branches at `/inventories` and finds products with a Ransack search on the SKU. It has no tax resource: the three standard
 * tax ids are documented where products are created ("1" VAT 15 percent, "2" VAT 0 percent, "3" tax exempt), and a company may have more,
 * typed by the merchant in the tax mapping. Branches are kept in memory for ten minutes per connection.
 */
final class QoyodCatalog {

	/**
	 * Seconds branches are kept.
	 */
	public const TTL = 600;

	/**
	 * Most SKUs asked for in one stock request.
	 */
	public const STOCK_BATCH = 50;

	/**
	 * Branches by connection: [expires at, rows].
	 *
	 * @var array<int,array{0:int,1:array<int,array<string,mixed>>}>
	 */
	private array $branches = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodPager $pager Reads every page of a list.
	 * @param Clock      $clock Time source.
	 */
	public function __construct( private readonly QoyodPager $pager, private readonly Clock $clock ) {
	}

	/**
	 * The standard tax codes with their rates in basis points.
	 *
	 * @return array<int|string,array{name:string,rate_bp:int}>
	 */
	public function standardTaxes(): array {
		return array(
			'1' => array(
				'name'    => __( 'VAT 15%', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_bp' => 1500,
			),
			'2' => array(
				'name'    => __( 'VAT 0%', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_bp' => 0,
			),
			'3' => array(
				'name'    => __( 'Tax exempt', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_bp' => 0,
			),
		);
	}

	/**
	 * Branches (inventories).
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function branches( ApiClient $api, int $connectionId ): array {
		$now = $this->clock->now()->getTimestamp();
		if ( isset( $this->branches[ $connectionId ] ) && $this->branches[ $connectionId ][0] > $now ) {
			return $this->branches[ $connectionId ][1];
		}
		$rows                            = $this->pager->all( $api, 'inventories', array(), 'inventories' );
		$this->branches[ $connectionId ] = array( $now + self::TTL, $rows );
		return $rows;
	}

	/**
	 * The branch the documents are booked to: the one the setting names, else the only branch.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $setting      The inventory_id option.
	 * @return string|ConnectorError The branch id, or SYNC-VAL-002 asking the person to choose.
	 *
	 * @throws ApiException On failure.
	 */
	public function branchId( ApiClient $api, int $connectionId, string $setting ): string|ConnectorError {
		$branches = $this->branches( $api, $connectionId );
		if ( '' !== $setting ) {
			foreach ( $branches as $branch ) {
				if ( QoyodPayloadReader::text( $branch['id'] ?? '' ) === $setting ) {
					return $setting;
				}
			}
			return $this->refused( 'inventory_id', __( 'Qoyod has no branch with the number in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( 1 === count( $branches ) ) {
			return QoyodPayloadReader::text( $branches[0]['id'] ?? '' );
		}
		return $this->refused( 'inventory_id', __( 'Choose the branch in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * The product with a SKU (exact, case-insensitive).
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function productBySku( ApiClient $api, string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		foreach ( $this->pager->all( $api, 'products', array( 'q' => array( 'sku_cont' => $sku ) ), 'products', static fn( array $row ): bool => str_contains( strtolower( QoyodPayloadReader::text( $row['sku'] ?? '' ) ), strtolower( $sku ) ) ) as $product ) {
			if ( 0 === strcasecmp( QoyodPayloadReader::text( $product['sku'] ?? '' ), $sku ) ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * Products whose name or SKU contains the text.
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
		$q    = array( 'sku_or_name_en_or_name_ar_cont' => $query );
		$rows = $this->pager->all( $api, 'products', array( 'q' => $q ), 'products' );
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Products for a list of SKUs, in batches.
	 *
	 * @param ApiClient         $api  Client.
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array<string,mixed>> Products by upper-case SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function productsBySkus( ApiClient $api, array $skus ): array {
		$found = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'trim', $skus ) ) ) ), self::STOCK_BATCH ) as $batch ) {
			// Rails reads q[sku_in][]=a&q[sku_in][]=b as a list; http_build_query would write q[sku_in][0]=a, which Rails reads as a hash.
			$path = 'products?' . implode( '&', array_map( static fn( string $sku ): string => 'q%5Bsku_in%5D%5B%5D=' . rawurlencode( $sku ), $batch ) );
			foreach ( $this->pager->all( $api, $path, array(), 'products' ) as $product ) {
				$found[ strtoupper( QoyodPayloadReader::text( $product['sku'] ?? '' ) ) ] = $product;
			}
		}
		return $found;
	}

	/**
	 * A SYNC-VAL-002 for a setting that cannot be used.
	 *
	 * @param string $field  Setting name.
	 * @param string $reason Short reason.
	 */
	private function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'setting refused'
		);
	}
}
