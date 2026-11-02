<?php
/**
 * Product matching for the Mapping screen: list, search and choose.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Products;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Repositories\Paged;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Rest\ApiException;

/**
 * Shapes `ProductMatch` rows from three sources: WooCommerce (the port), the id map (what is linked) and the queue (how many failed
 * orders wait for the product). The choice of a system product by hand is stored by Mapping\SkuMatcher as an override, which
 * automatic matching never replaces.
 */
final class ProductMatchService {

	/**
	 * Products per page.
	 */
	public const PER_PAGE = 25;

	/**
	 * @param ProductSource        $products    WooCommerce products.
	 * @param SkuMatcher           $matcher     Matching by SKU and overrides.
	 * @param IdMapRepository      $idMap       Links between WooCommerce and the system.
	 * @param QueueRepository      $queue       Queue, for the count of blocked orders.
	 * @param ConnectionRepository $connections Connections (decrypted, only to search the system).
	 * @param ConnectorRegistry    $registry    Installed connectors.
	 */
	public function __construct(
		private readonly ProductSource $products,
		private readonly SkuMatcher $matcher,
		private readonly IdMapRepository $idMap,
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly ConnectorRegistry $registry
	) {
	}

	/**
	 * One page of products that need matching (`Paged<ProductMatch>`).
	 *
	 * @param int $connectionId Connection.
	 * @param int $page         Page from 1.
	 * @return array<string,mixed>
	 */
	public function unmatched( int $connectionId, int $page ): array {
		$found   = $this->products->unmatched( $page, self::PER_PAGE );
		$blocked = $this->blockedBySku();
		$items   = array();
		foreach ( $found['items'] as $product ) {
			$items[] = $this->shape( $connectionId, $product, $blocked );
		}
		return ( new Paged( $items, $found['total'], $page, self::PER_PAGE ) )->toArray();
	}

	/**
	 * Search the connected system's products by reference, name or barcode.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $query        Text to search for.
	 * @return array<int,array{id:string,ref:string,name:string}>
	 *
	 * @throws ApiException 404 when the connection or its connector is gone; 502 with the catalog code when the system fails.
	 * @throws \Estasoft\SyncEngine\Core\Crypto\CryptoException When the saved credentials cannot be read.
	 */
	public function search( int $connectionId, string $query ): array {
		$connection = $this->connections->find( $connectionId );
		$connector  = null === $connection ? null : $this->registry->get( $connection->connector );
		if ( null === $connection || null === $connector ) {
			throw ApiException::notFound( __( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$result = $connector->searchProducts( $connection, $query );
		if ( $result instanceof ConnectorError ) {
			throw ApiException::fromCatalog( $result->code, $result->context + array( 'system' => $connector->label() ), 502 );
		}
		$hits = array();
		foreach ( $result as $ref ) {
			$hits[] = array(
				'id'   => $ref->id,
				'ref'  => $ref->ref,
				'name' => isset( $ref->extra['name'] ) && is_scalar( $ref->extra['name'] ) ? (string) $ref->extra['name'] : $ref->ref,
			);
		}
		return $hits;
	}

	/**
	 * Choose the system's product for a WooCommerce product, or clear the choice.
	 *
	 * @param int         $connectionId Connection.
	 * @param int         $productId    WooCommerce product or variation id.
	 * @param string|null $externalId   The system's product id; null or empty clears the choice.
	 * @param string|null $externalRef  The system's reference for it, if the screen knows it.
	 * @return array<string,mixed> The `ProductMatch` after the change.
	 *
	 * @throws ApiException 404 when the product does not exist.
	 */
	public function map( int $connectionId, int $productId, ?string $externalId, ?string $externalRef = null ): array {
		$product = $this->products->find( $productId );
		if ( null === $product ) {
			throw ApiException::notFound( __( 'That product does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->matcher->setOverride( $connectionId, $productId, $externalId, $externalRef, '' === $product['sku'] ? null : $product['sku'] );
		return $this->shape( $connectionId, $product, $this->blockedBySku() );
	}

	/**
	 * A `ProductMatch` for one product.
	 *
	 * @param int                                           $connectionId Connection.
	 * @param array{id:int,sku:string,name:string}          $product      Product.
	 * @param array<string,int>                             $blocked      Failed orders by SKU.
	 * @return array<string,mixed>
	 */
	private function shape( int $connectionId, array $product, array $blocked ): array {
		$link = $this->idMap->get( $connectionId, SkuMatcher::ENTITY, (string) $product['id'] );
		return array(
			'product_id'    => $product['id'],
			'sku'           => $product['sku'],
			'name'          => $product['name'],
			'status'        => $this->matcher->status( $connectionId, $product['id'], $product['sku'] ),
			'external_id'   => $link['external_id'] ?? null,
			'external_ref'  => $link['external_ref'] ?? null,
			'external_name' => null,
			'orders_blocked' => $blocked[ strtolower( $product['sku'] ) ] ?? 0,
		);
	}

	/**
	 * How many failed orders wait for each SKU (SYNC-MAP-001), by lower-case SKU.
	 *
	 * @return array<string,int>
	 */
	private function blockedBySku(): array {
		$blocked = array();
		foreach ( $this->queue->attentionGroups() as $group ) {
			if ( ErrorCode::Map001->value !== $group['error_code'] || ! str_starts_with( $group['cause'], 'SKU ' ) ) {
				continue;
			}
			$sku             = strtolower( trim( substr( $group['cause'], 4 ) ) );
			$blocked[ $sku ] = ( $blocked[ $sku ] ?? 0 ) + $group['count'];
		}
		return $blocked;
	}
}
