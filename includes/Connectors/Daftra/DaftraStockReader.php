<?php
/**
 * Reads product stock from Daftra.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A Daftra product carries one `stock_balance` for the whole account [documented in the product list]; the list has no figure per warehouse. The
 * quantity is the whole-number part of the balance, `held` is 0 (Daftra documents no reserved quantity), and a product that does not track stock
 * is left out. A request that names warehouses is refused, because a figure for some warehouses would be a guess.
 */
final class DaftraStockReader {

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraCatalog $catalog Products.
	 */
	public function __construct( private readonly DaftraCatalog $catalog ) {
	}

	/**
	 * Stock for SKUs.
	 *
	 * @param ApiClient         $api        Client.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse ids that count; Daftra can only answer for all of them.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError Keyed by SKU as asked for.
	 *
	 * @throws ApiException On failure.
	 */
	public function fetch( ApiClient $api, array $skus, array $warehouses ): array|ConnectorError {
		if ( array() !== $warehouses ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => DaftraConnector::LABEL,
					'field'  => 'warehouses',
					'reason' => __( 'Daftra reports one stock figure for all warehouses. Count all warehouses toward shop stock.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'stock per warehouse'
			);
		}
		$products = $this->catalog->productsBySkus( $api, $skus );
		$result   = array();
		foreach ( $skus as $sku ) {
			$product = $products[ strtoupper( trim( $sku ) ) ] ?? null;
			if ( null === $product || ! DaftraPayloadReader::flag( $product['track_stock'] ?? null, false ) || ! is_numeric( $product['stock_balance'] ?? null ) ) {
				continue;
			}
			$result[ $sku ] = array(
				'quantity' => (int) floor( (float) $product['stock_balance'] ),
				'held'     => 0,
			);
		}
		return $result;
	}
}
