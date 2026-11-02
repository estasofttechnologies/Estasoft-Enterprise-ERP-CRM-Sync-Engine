<?php
/**
 * Matches WooCommerce products to the connected system's products by SKU.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\Ports\ConnectionSource;
use Estasoft\SyncEngine\Mapping\Ports\IdMapStore;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Order of attempts: a match a person set by hand (id map, is_override) for the variation and then the product; a stored match for
 * the same SKU; a read-only lookup in the connected system by SKU (lookup_product), which is then remembered. Nothing is created in
 * the connected system. No match gives null, which the sku_match transform turns into SYNC-MAP-001.
 */
final class SkuMatcher implements ProductResolver {

	/**
	 * Id map type for products and variations.
	 */
	public const ENTITY = 'product';

	/**
	 * Called after each lookup that reached a verdict: (connectionId, productId, sku, ?ExternalRef). Keeps the product screen current.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $onVerdict;

	/**
	 * Set up with what it depends on.
	 *
	 * @param IdMapStore        $idMap       Links between WooCommerce and system ids.
	 * @param ConnectionSource  $connections Connections.
	 * @param ConnectorRegistry $connectors  Installed connectors.
	 * @param \Closure|null     $onVerdict   Notified of each verdict.
	 */
	public function __construct(
		private readonly IdMapStore $idMap,
		private readonly ConnectionSource $connections,
		private readonly ConnectorRegistry $connectors,
		?\Closure $onVerdict = null
	) {
		$this->onVerdict = $onVerdict;
	}

	/**
	 * Find the system's product for a SKU and remember a successful lookup.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU as typed in WooCommerce.
	 * @param int    $productId    WooCommerce product id, 0 when unknown.
	 * @param int    $variationId  WooCommerce variation id, 0 when none.
	 * @return ExternalRef|null
	 *
	 * @throws LookupFailedException When the system could not be asked.
	 */
	public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
		$sku                        = Text::trim( $sku );
		list( $found, $fromLookup ) = $this->find( $connectionId, $sku, $productId, $variationId, true );

		$wcId = $variationId > 0 ? $variationId : $productId;
		if ( $fromLookup && null !== $found && $wcId > 0 ) {
			$this->idMap->link( $connectionId, self::ENTITY, (string) $wcId, $found->id, '' === $found->ref ? $sku : $found->ref, $found->url, $sku, false );
		}
		if ( null !== $this->onVerdict ) {
			( $this->onVerdict )( $connectionId, $productId, $sku, $found );
		}
		return $found;
	}

	/**
	 * Find a product without writing anything. Asks the connected system only when told to.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @param int    $productId    WooCommerce product id, 0 when unknown.
	 * @param int    $variationId  WooCommerce variation id, 0 when none.
	 * @param bool   $ask          Whether a lookup in the connected system is allowed.
	 * @return ExternalRef|null
	 *
	 * @throws LookupFailedException When asked and the system answers with an error.
	 */
	public function peek( int $connectionId, string $sku, int $productId = 0, int $variationId = 0, bool $ask = false ): ?ExternalRef {
		return $this->find( $connectionId, Text::trim( $sku ), $productId, $variationId, $ask )[0];
	}

	/**
	 * Choose the system's product for a WooCommerce product by hand (Map this SKU), or clear the choice.
	 *
	 * @param int         $connectionId Connection.
	 * @param int         $productId    WooCommerce product or variation id.
	 * @param string|null $externalId   The system's product id, or null to clear the override.
	 * @param string|null $externalRef  The system's reference for the product.
	 * @param string|null $sku          The product's SKU.
	 */
	public function setOverride( int $connectionId, int $productId, ?string $externalId, ?string $externalRef = null, ?string $sku = null ): void {
		if ( null === $externalId || '' === $externalId ) {
			$this->idMap->forget( $connectionId, self::ENTITY, (string) $productId );
			return;
		}
		$this->idMap->link( $connectionId, self::ENTITY, (string) $productId, $externalId, $externalRef, null, $sku, true );
	}

	/**
	 * What is known about a product without asking the system: override, matched, unmatched or no_sku.
	 *
	 * @param int    $connectionId Connection.
	 * @param int    $productId    WooCommerce product or variation id.
	 * @param string $sku          Its SKU.
	 * @return string One of override, matched, unmatched, no_sku.
	 */
	public function status( int $connectionId, int $productId, string $sku ): string {
		$link = $this->idMap->get( $connectionId, self::ENTITY, (string) $productId );
		if ( null !== $link && $link['is_override'] ) {
			return 'override';
		}
		if ( '' === Text::trim( $sku ) ) {
			return 'no_sku';
		}
		return null !== $link && 0 === strcasecmp( (string) $link['sku'], Text::trim( $sku ) ) ? 'matched' : 'unmatched';
	}

	/**
	 * The attempts in order.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          Trimmed SKU.
	 * @param int    $productId    Product id.
	 * @param int    $variationId  Variation id.
	 * @param bool   $ask          Whether to ask the system.
	 * @return array{0:?ExternalRef,1:bool} The match and whether it came from a fresh lookup.
	 *
	 * @throws LookupFailedException When asked and the system answers with an error.
	 */
	private function find( int $connectionId, string $sku, int $productId, int $variationId, bool $ask ): array {
		$ids = array_values( array_filter( array( $variationId, $productId ) ) );

		foreach ( $ids as $id ) {
			$link = $this->idMap->get( $connectionId, self::ENTITY, (string) $id );
			if ( null !== $link && $link['is_override'] ) {
				return array( new ExternalRef( $link['external_id'], (string) $link['external_ref'], $link['external_url'] ), false );
			}
		}
		if ( '' === $sku ) {
			return array( null, false );
		}

		$link = array() === $ids ? null : $this->idMap->get( $connectionId, self::ENTITY, (string) $ids[0] );
		if ( null === $link || 0 !== strcasecmp( (string) $link['sku'], $sku ) ) {
			$link = $this->idMap->findBySku( $connectionId, $sku );
		}
		if ( null !== $link ) {
			return array( new ExternalRef( $link['external_id'], (string) $link['external_ref'], $link['external_url'] ), false );
		}
		return $ask ? array( $this->lookup( $connectionId, $sku ), true ) : array( null, false );
	}

	/**
	 * Ask the connected system for a product by SKU (read-only).
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @return ExternalRef|null
	 *
	 * @throws LookupFailedException When the system answers with an error.
	 */
	private function lookup( int $connectionId, string $sku ): ?ExternalRef {
		$connection = $this->connections->find( $connectionId );
		$connector  = null === $connection ? null : $this->connectors->get( $connection->connector );
		if ( null === $connection || null === $connector ) {
			return null;
		}
		$found = $connector->lookupProduct( $connection, $sku );
		if ( $found instanceof ConnectorError ) {
			$failure = new LookupFailedException( $found );
			throw $failure;
		}
		return $found;
	}
}
