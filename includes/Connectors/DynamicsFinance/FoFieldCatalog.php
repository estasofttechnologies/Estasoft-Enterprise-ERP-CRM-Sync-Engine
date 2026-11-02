<?php
/**
 * The target fields shown on the mapping screens for Finance and Supply Chain.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\FieldGroups;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; lists use `[]`. Refunds, stock and tracking have
 * no fields: the connector does not send or read them.
 */
final class FoFieldCatalog {

	/**
	 * Fields of a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => FieldGroups::customer( FieldCatalogBuilder::make(), __( 'Customer', 'estasoft-erp-crm-sync-engine' ), 'partner.', true )->build(),
			'products'  => FieldGroups::products( FieldCatalogBuilder::make(), __( 'Item number (ItemNumber)', 'estasoft-erp-crm-sync-engine' ) )->build(),
			default     => array(),
		};
	}

	/**
	 * Order fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function orders(): array {
		$b = FieldGroups::orderHeader( FieldCatalogBuilder::make(), __( 'Sales order', 'estasoft-erp-crm-sync-engine' ) );
		$b->boolean( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'warehouse', __( 'Shipping warehouse (WarehouseId)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'site', __( 'Shipping site (SiteId)', 'estasoft-erp-crm-sync-engine' ) );
		FieldGroups::customer( $b, __( 'Customer', 'estasoft-erp-crm-sync-engine' ), 'partner.', true );
		FieldGroups::delivery( $b, __( 'Delivery address', 'estasoft-erp-crm-sync-engine' ) );
		FieldGroups::lines( $b, __( 'Order lines', 'estasoft-erp-crm-sync-engine' ), true );
		FieldGroups::extras( $b, __( 'Shipping, fees and discount', 'estasoft-erp-crm-sync-engine' ), true, false );
		return $b->build();
	}
}
