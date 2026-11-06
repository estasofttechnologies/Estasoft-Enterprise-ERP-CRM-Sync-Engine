<?php
/**
 * The target fields shown on the mapping screens for Business Central.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\FieldGroups;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; lists use `[]`. Business Central has no stock or
 * tracking to read, so those two mapping types have no fields.
 */
final class BcFieldCatalog {

	/**
	 * Fields of a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => FieldGroups::customer( FieldCatalogBuilder::make(), __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )->build(),
			'products'  => FieldGroups::products( FieldCatalogBuilder::make(), __( 'Item number (or GTIN, by the connection option)', 'estasoft-enterprise-erpcrm-sync-engine' ) )->build(),
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
		$b = FieldGroups::orderHeader( FieldCatalogBuilder::make(), __( 'Sales order', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$b->boolean( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		FieldGroups::customer( $b, __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		FieldGroups::delivery( $b, __( 'Ship-to address', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		FieldGroups::lines( $b, __( 'Order lines', 'estasoft-enterprise-erpcrm-sync-engine' ), true );
		FieldGroups::extras( $b, __( 'Shipping, fees and discounts', 'estasoft-enterprise-erpcrm-sync-engine' ), true );
		return $b->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Credit memo', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Original order reference (WC-…)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date', __( 'Credit memo date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'reason', __( 'Reason (added as a comment line)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund total with tax (minor units); the credit memo total is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Credit memo lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].product', __( 'Product (item number or SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'refund_line[].name', __( 'Line description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Line amount without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Refunded shipping (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'other.total', __( 'Refund amount no line explains (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}
}
