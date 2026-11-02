<?php
/**
 * Mapping fields and version 1 rules of the NetSuite connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultFields;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * NetSuite works out tax itself (SuiteTax), so lines carry no tax code. The SKU match gives the item's internal id.
 */
final class NetSuiteMappings {

	/**
	 * The fields of a mapping type.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		$fields = new DefaultFields( 'NetSuite', __( 'Product (the item\'s internal id, found by the SKU match)', 'estasoft-erp-crm-sync-engine' ), true, false );
		return match ( $mappingType ) {
			'orders'    => $fields->orders()->build(),
			'customers' => $fields->customers()->build(),
			'products'  => $fields->products()->build(),
			'refunds'   => $fields->refunds()->build(),
			default     => array(),
		};
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return ( new DefaultRules( 'id', true, false ) )->rules( $mappingType );
	}
}
