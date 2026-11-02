<?php
/**
 * Mapping fields and version 1 rules of the SAP Business One connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultFields;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * The SKU match gives the item code. Stock rules read the quantities the connector returns (`quantity`, `held`).
 */
final class SapBusinessOneMappings {

	/**
	 * The fields of a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds or stock.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		$fields = new DefaultFields( 'SAP Business One', __( 'Product (item code, found by the SKU match)', 'estasoft-erp-crm-sync-engine' ), true, true );
		return match ( $mappingType ) {
			'orders'    => $fields->orders()->build(),
			'customers' => $fields->customers()->build(),
			'products'  => $fields->products()->build(),
			'refunds'   => $fields->refunds()->build(),
			'stock'     => FieldCatalogBuilder::make()
				->group( __( 'Stock in SAP Business One', 'estasoft-erp-crm-sync-engine' ) )
				->number( 'quantity', __( 'On hand (InStock of the chosen warehouses)', 'estasoft-erp-crm-sync-engine' ) )
				->number( 'held', __( 'Committed (Committed of the chosen warehouses)', 'estasoft-erp-crm-sync-engine' ) )
				->build(),
			default     => array(),
		};
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds or stock.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		$rules = new DefaultRules( 'ref', true, true );
		if ( 'stock' === $mappingType ) {
			return array( $rules->rule( 'stock-quantity', 'product.stock_quantity', 'quantity' ) );
		}
		return $rules->rules( $mappingType );
	}
}
