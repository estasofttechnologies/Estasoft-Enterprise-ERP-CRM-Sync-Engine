<?php
/**
 * Fields and version 1 rules of the Oracle Fusion mapping.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultFields;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * No buyer data is mapped (every order is billed to one customer account), no tax code (Oracle Fusion works tax out) and the SKU match gives
 * the item number.
 */
final class OracleFusionMappings {

	/**
	 * The fields of a mapping type.
	 *
	 * @param string $mappingType orders, products or refunds.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		$fields = new DefaultFields( 'Oracle Fusion', __( 'Product (item number, found by the SKU match)', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, false );
		return match ( $mappingType ) {
			'orders'   => $fields->orders()
				->group( __( 'Billing', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->text( 'bill_to_customer_number', __( 'Customer account number (overrides the connection setting)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
