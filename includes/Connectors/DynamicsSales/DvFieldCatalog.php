<?php
/**
 * The target fields shown on the mapping screens for Dynamics 365 Sales.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\FieldGroups;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; lists use `[]`. A CRM has no stock, tracking or tax
 * codes: tax travels as amounts.
 */
final class DvFieldCatalog {

	/**
	 * Fields of a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => FieldGroups::customer( FieldCatalogBuilder::make(), __( 'Contact', 'estasoft-erp-crm-sync-engine' ), 'partner.', true )->build(),
			'products'  => FieldGroups::products( FieldCatalogBuilder::make(), __( 'Product ID (productnumber)', 'estasoft-erp-crm-sync-engine' ) )->build(),
			'refunds'   => $this->refunds(),
			default     => array(),
		};
	}

	/**
	 * Order fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function orders(): array {
		$b = FieldGroups::orderHeader( FieldCatalogBuilder::make(), __( 'Deal (opportunity)', 'estasoft-erp-crm-sync-engine' ) );
		FieldGroups::customer( $b, __( 'Contact and account', 'estasoft-erp-crm-sync-engine' ), 'partner.', true );
		FieldGroups::lines( $b, __( 'Deal products', 'estasoft-erp-crm-sync-engine' ), false );
		FieldGroups::extras( $b, __( 'Shipping, fees and discount', 'estasoft-erp-crm-sync-engine' ), false );
		return $b->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Note on the deal', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'order_ref', __( 'Original order reference (WC-…)', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'amount', __( 'Refund total (minor units)', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'reason', __( 'Reason', 'estasoft-erp-crm-sync-engine' ) )
			->build();
	}
}
