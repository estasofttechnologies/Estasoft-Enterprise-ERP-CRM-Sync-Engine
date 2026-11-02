<?php
/**
 * Mapping module: rule engine, tax and SKU matching, payload builder, preview and versions.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Mapping\Adapters\CoreConnectionSource;
use Estasoft\SyncEngine\Mapping\Adapters\CoreEventLog;
use Estasoft\SyncEngine\Mapping\Adapters\CoreIdMapStore;
use Estasoft\SyncEngine\Mapping\Adapters\CoreMappingStore;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Container keys set by this module:
 *
 * - payload_builder          Mapping\PayloadBuilder (the seam read by Core\Queue\ItemProcessor)
 * - mapping.transforms       TransformRegistry
 * - mapping.rule_engine      RuleEngine
 * - mapping.canonical_fields CanonicalFields
 * - mapping.service          MappingService (draft, publish, rollback, versions)
 * - mapping.previewer        Previewer
 * - mapping.tax_map          TaxMap
 * - mapping.sku_matcher      SkuMatcher
 * - mapping.store            Ports\MappingStore over the core repository
 *
 * Core keys come from Core\Services; the one key read from the Woo module is the constant below.
 */
final class MappingModule implements ModuleInterface {

	/**
	 * Key set by this module for the queue (the same value as Core\Services::PAYLOAD_BUILDER).
	 */
	public const PAYLOAD_BUILDER = Services::PAYLOAD_BUILDER;

	/**
	 * Key of the WooCommerce reader, set by the Woo module.
	 */
	private const WOO_ORDER_READER = 'woo.order_reader';

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$container->set( 'mapping.transforms', static fn (): TransformRegistry => TransformRegistry::standard() );
		$container->set( 'mapping.canonical_fields', static fn (): CanonicalFields => new CanonicalFields() );
		$container->set(
			'mapping.rule_engine',
			static fn ( Container $c ): RuleEngine => new RuleEngine( $c->get( 'mapping.transforms' ) )
		);

		$container->set(
			'mapping.store',
			static fn ( Container $c ): CoreMappingStore => new CoreMappingStore( $c->get( Services::MAPPING_REPOSITORY ) )
		);
		$container->set(
			'mapping.id_map',
			static fn ( Container $c ): CoreIdMapStore => new CoreIdMapStore( $c->get( Services::ID_MAP_REPOSITORY ) )
		);
		$container->set(
			'mapping.connections',
			static fn ( Container $c ): CoreConnectionSource => new CoreConnectionSource( $c->get( Services::CONNECTION_REPOSITORY ) )
		);
		$container->set(
			'mapping.log',
			static fn ( Container $c ): CoreEventLog => new CoreEventLog( $c->get( Services::LOGGER ) )
		);

		$container->set(
			'mapping.tax_map',
			static fn ( Container $c ): TaxMap => new TaxMap( $c->get( 'mapping.store' ), $c->get( 'mapping.log' ) )
		);
		$container->set(
			'mapping.sku_matcher',
			static fn ( Container $c ): SkuMatcher => new SkuMatcher(
				$c->get( 'mapping.id_map' ),
				$c->get( 'mapping.connections' ),
				$c->get( Services::CONNECTOR_REGISTRY ),
				static function ( int $connectionId, int $productId, string $sku, ?ExternalRef $found ): void {
					/**
					 * Fires after a product was looked up by SKU while building an order, so screens can show whether it matches.
					 *
					 * @param int    $connectionId Connection.
					 * @param int    $productId    WooCommerce product id (0 when unknown).
					 * @param string $sku          SKU.
					 * @param bool   $matched      Whether the connected system has the product.
					 */
					do_action( 'estasoft_sync_product_match', $connectionId, $productId, $sku, null !== $found );
				}
			)
		);

		$container->set(
			self::PAYLOAD_BUILDER,
			static fn ( Container $c ): PayloadBuilder => new PayloadBuilder(
				$c->get( 'mapping.store' ),
				$c->get( self::WOO_ORDER_READER ),
				$c->get( 'mapping.rule_engine' ),
				$c->get( 'mapping.tax_map' ),
				$c->get( 'mapping.sku_matcher' ),
				$c->get( Services::CONNECTOR_REGISTRY ),
				$c->get( 'mapping.canonical_fields' ),
				$c->get( 'mapping.log' )
			)
		);
		$container->set(
			'mapping.previewer',
			static fn ( Container $c ): Previewer => new Previewer(
				$c->get( 'mapping.store' ),
				$c->get( self::WOO_ORDER_READER ),
				$c->get( 'mapping.rule_engine' ),
				$c->get( 'mapping.tax_map' ),
				$c->get( 'mapping.sku_matcher' ),
				$c->get( 'mapping.connections' ),
				$c->get( Services::CONNECTOR_REGISTRY ),
				$c->get( 'mapping.canonical_fields' )
			)
		);
		$container->set(
			'mapping.service',
			static fn ( Container $c ): MappingService => new MappingService(
				$c->get( 'mapping.store' ),
				$c->get( 'mapping.rule_engine' ),
				new RuleSanitizer(),
				$c->get( 'mapping.connections' ),
				$c->get( Services::CONNECTOR_REGISTRY ),
				$c->get( 'mapping.canonical_fields' ),
				$c->get( 'mapping.log' )
			)
		);
	}

	/**
	 * This module adds no WordPress hooks: the queue and the REST layer call its services.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
	}
}
