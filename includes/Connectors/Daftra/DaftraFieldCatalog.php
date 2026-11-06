<?php
/**
 * The target fields of the Daftra mapping screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Payload paths the connector reads (see docs/connectors/daftra.md, section 5). The wire names of Daftra are an implementation detail
 * of the senders; the mapping speaks in these paths.
 */
final class DaftraFieldCatalog {

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
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.sku', __( 'SKU (matched to the Daftra product SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
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
			->group( __( 'Invoice', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->date( 'date', __( 'Invoice date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency', true )
			->longText( 'note', __( 'Notes', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'number', __( 'Order number (the invoice number is the prefix plus this)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'total', __( 'Order total the shop expects, with tax (minor units); the Daftra total is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->boolean( 'prices_include_tax', __( 'The mapped line totals include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$builder = $this->customer( $builder )
			->group( __( 'Invoice lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'lines[].item', __( 'Daftra product (id)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'lines[].name', __( 'Description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'lines[].total', __( 'Line total after discounts, without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', true )
			->taxCode( 'lines[].tax_code', __( 'Tax (Daftra tax)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Shipping and fees', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'shipping.total', __( 'Shipping, without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->taxCode( 'shipping.tax_code', __( 'Shipping tax rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fees[].name', __( 'Fee description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'fees[].total', __( 'Fee, without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'fees[].tax_amount', __( 'Tax on the fee (minor units); the rate is found from it', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->group( __( 'Payment', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->date( 'payment.date', __( 'Payment date (an order with a date is a paid order)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Documents in Daftra', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->choice( 'flow.invoice', __( 'Invoice state: issued or draft', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'flow.payment', __( 'Record the payment', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * The customer fields.
	 *
	 * @param FieldCatalogBuilder $builder Builder.
	 */
	private function customer( FieldCatalogBuilder $builder ): FieldCatalogBuilder {
		return $builder
			->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'customer.email', __( 'Email', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', true )
			->field( 'customer.phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( 'customer.company', __( 'Company', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.tax_number', __( 'Business number (tax number)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.address', __( 'Address', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.state', __( 'State or region', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.postcode', __( 'Postal code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'customer.country', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' );
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Credit note', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC- and the order id)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date', __( 'Credit note date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->longText( 'reason', __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'amount', __( 'Whole refund, with tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'excluded_amount', __( 'Part that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'shipping_amount', __( 'Shipping refunded, with tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'other_amount', __( 'Other refunded amounts, with tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->group( __( 'Credit note lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'lines[].name', __( 'Description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'lines[].amount', __( 'Line amount without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->field( 'lines[].tax_amount', __( 'Tax on the line (minor units); the rate is found from it', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money' )
			->build();
	}
}
