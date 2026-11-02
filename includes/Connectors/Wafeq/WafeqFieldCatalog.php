<?php
/**
 * The target fields of the Wafeq mapping screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Payload paths the connector reads (see docs/connectors/wafeq.md, section 5). The wire names of Wafeq are an implementation detail
 * of the senders; the mapping speaks in these paths.
 */
final class WafeqFieldCatalog {

	/**
	 * Fields for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->customer( FieldCatalogBuilder::make() )->build(),
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product', 'estasoft-erp-crm-sync-engine' ) )->sku( 'product.sku', __( 'SKU (matched to the Wafeq item SKU)', 'estasoft-erp-crm-sync-engine' ), true )->build(),
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
		$builder = FieldCatalogBuilder::make()
			->group( __( 'Invoice', 'estasoft-erp-crm-sync-engine' ) )
			->date( 'date', __( 'Invoice date', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'currency', true )
			->longText( 'note', __( 'Notes', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'number', __( 'Order number (the invoice number is the prefix plus this)', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'total', __( 'Order total the shop expects, with tax (minor units); the Wafeq total is checked against it', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->boolean( 'prices_include_tax', __( 'The mapped line totals include tax', 'estasoft-erp-crm-sync-engine' ) );
		$builder = $this->customer( $builder )
			->group( __( 'Invoice lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'lines[].item', __( 'Wafeq item (id)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'lines[].name', __( 'Description', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'lines[].total', __( 'Line total after discounts, without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money', true )
			->taxCode( 'lines[].tax_code', __( 'Tax rate', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Shipping and fees', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping description', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'shipping.total', __( 'Shipping, without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->taxCode( 'shipping.tax_code', __( 'Shipping tax rate', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'fees[].name', __( 'Fee description', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'fees[].total', __( 'Fee, without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'fees[].tax_amount', __( 'Tax on the fee (minor units); the rate is found from it', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->group( __( 'Payment', 'estasoft-erp-crm-sync-engine' ) )
			->date( 'payment.date', __( 'Payment date (an order with a date is a paid order)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Documents in Wafeq', 'estasoft-erp-crm-sync-engine' ) )
			->choice( 'flow.invoice', __( 'Invoice state: finalized or draft', 'estasoft-erp-crm-sync-engine' ) )
			->boolean( 'flow.payment', __( 'Record the payment', 'estasoft-erp-crm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * The customer fields.
	 *
	 * @param FieldCatalogBuilder $builder Builder.
	 */
	private function customer( FieldCatalogBuilder $builder ): FieldCatalogBuilder {
		return $builder
			->group( __( 'Customer', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.name', __( 'Name', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'customer.email', __( 'Email', 'estasoft-erp-crm-sync-engine' ), 'email', true )
			->field( 'customer.phone', __( 'Phone', 'estasoft-erp-crm-sync-engine' ), 'phone' )
			->text( 'customer.company', __( 'Company', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.tax_number', __( 'Tax registration number', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.address', __( 'Address', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.city', __( 'City', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.state', __( 'State or region', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.postcode', __( 'Postal code', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'customer.country', __( 'Country (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'country' );
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Credit note', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC- and the order id)', 'estasoft-erp-crm-sync-engine' ), true )
			->date( 'date', __( 'Credit note date', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'currency' )
			->longText( 'reason', __( 'Reason', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'amount', __( 'Whole refund, with tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'excluded_amount', __( 'Part that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'shipping_amount', __( 'Shipping refunded, with tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'other_amount', __( 'Other refunded amounts, with tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->group( __( 'Credit note lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'lines[].name', __( 'Description', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'lines[].amount', __( 'Line amount without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->field( 'lines[].tax_amount', __( 'Tax on the line (minor units); the rate is found from it', 'estasoft-erp-crm-sync-engine' ), 'money' )
			->build();
	}
}
