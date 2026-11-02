<?php
/**
 * Mapping fields and version 1 rules of the SAP S/4HANA connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultFields;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * No buyer data is mapped (every order is sold to one customer), no tax code (S/4HANA determines tax) and the SKU match gives the material number.
 */
final class SapS4HanaMappings {

	/**
	 * The fields of a mapping type.
	 *
	 * @param string $mappingType orders, products or refunds.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		$fields = new DefaultFields( 'SAP S/4HANA', __( 'Product (material number, found by the SKU match)', 'estasoft-erp-crm-sync-engine' ), false, false, false );
		return match ( $mappingType ) {
			'orders'   => $fields->orders()
				->group( __( 'Customer', 'estasoft-erp-crm-sync-engine' ) )
				->text( 'sold_to_party', __( 'Sold-to customer number (overrides the connection setting)', 'estasoft-erp-crm-sync-engine' ) )
				->build(),
			'products' => $fields->products()->build(),
			'refunds'  => $fields->refunds()->build(),
			default    => array(),
		};
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return ( new DefaultRules( 'ref', false, false, false ) )->rules( $mappingType );
	}
}
