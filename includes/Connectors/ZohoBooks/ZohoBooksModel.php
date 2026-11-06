<?php
/**
 * Mapping fields and version 1 rules for Zoho Books.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\CrmFields;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmMappings;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * The neutral sales fields plus the tax codes an accounting system needs.
 */
final class ZohoBooksModel {

	/**
	 * Fields of one mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public static function fields( string $mappingType ): array {
		$fields = CrmFields::catalog( $mappingType );
		if ( 'orders' !== $mappingType ) {
			return $fields;
		}
		return array_merge(
			$fields,
			FieldCatalogBuilder::make()
				->group( __( 'Tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->taxCode( 'lines[].tax_code', __( 'Line tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->taxCode( 'shipping.tax_code', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->taxCode( 'fees[].tax_code', __( 'Fee tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->build()
		);
	}

	/**
	 * Version 1 rules of one mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( string $mappingType ): array {
		$rules = CrmMappings::rules( $mappingType );
		if ( 'orders' !== $mappingType ) {
			return $rules;
		}
		$lookup = array( array( 'type' => 'tax_lookup' ) );
		return array_merge(
			$rules,
			array(
				array(
					'id'         => 'line-tax-code',
					'target'     => 'lines[].tax_code',
					'source'     => 'lines[].tax_rate',
					'transforms' => $lookup,
					'required'   => false,
					'error_code' => 'SYNC-MAP-002',
				),
				array(
					'id'         => 'shipping-tax-code',
					'target'     => 'shipping.tax_code',
					'source'     => 'shipping_lines[].tax_rate',
					'transforms' => $lookup,
					'required'   => false,
					'error_code' => 'SYNC-MAP-002',
				),
			)
		);
	}
}
