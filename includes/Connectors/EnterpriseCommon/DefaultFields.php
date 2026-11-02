<?php
/**
 * The target fields of the mapping screens shared by the ERP connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; arrays use []. The connector adds its own fields
 * to the builder it gets back.
 */
final class DefaultFields {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $system           System name for labels.
	 * @param string $productLabel     Label of the product field of order lines (says what the SKU match puts there).
	 * @param bool   $customerRequired Whether the customer email and name are required.
	 * @param bool   $withTax          Whether lines carry a tax code.
	 * @param bool   $parties          Whether the buyer's and the delivery address fields are offered.
	 */
	public function __construct( private readonly string $system, private readonly string $productLabel, private readonly bool $customerRequired = true, private readonly bool $withTax = true, private readonly bool $parties = true ) {
	}

	/**
	 * The order fields as a builder, so the connector can append its own.
	 */
	public function orders(): FieldCatalogBuilder {
		$builder = FieldCatalogBuilder::make()
			->group( __( 'Order', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'client_ref', __( 'Order reference (WC- and the order number)', 'estasoft-erp-crm-sync-engine' ), true )
			->date( 'order_date', __( 'Order date', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'currency', true )
			->longText( 'note', __( 'Note', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'total', sprintf( /* translators: %s: system name. */ __( 'Order total the shop expects, with tax (minor units); the total in %s is checked against it', 'estasoft-erp-crm-sync-engine' ), $this->system ) )
			->money( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-erp-crm-sync-engine' ) );
		if ( $this->parties ) {
			$this->person( $builder, __( 'Customer', 'estasoft-erp-crm-sync-engine' ), 'partner.', $this->customerRequired, true );
			$this->person( $builder, __( 'Delivery address', 'estasoft-erp-crm-sync-engine' ), 'delivery.', false, false );
		}
		$builder->group( __( 'Order lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'order_line[].product', $this->productLabel, true )
			->text( 'order_line[].name', __( 'Line description', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price (minor units; without tax unless the prices include tax)', 'estasoft-erp-crm-sync-engine' ), true );
		if ( $this->withTax ) {
			$builder->taxCode( 'order_line[].tax', __( 'Tax code', 'estasoft-erp-crm-sync-engine' ) );
		}
		$builder->group( __( 'Shipping, fees and discounts', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping line text', 'estasoft-erp-crm-sync-engine' ) );
		if ( $this->withTax ) {
			$builder->taxCode( 'shipping.tax', __( 'Shipping tax code', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $builder
			->text( 'fee[].name', __( 'Fee text', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fee[].total', __( 'Fee amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'discount.name', __( 'Discount text', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Payment', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The customer fields.
	 */
	public function customers(): FieldCatalogBuilder {
		$builder = FieldCatalogBuilder::make();
		if ( $this->parties ) {
			$this->person( $builder, __( 'Customer', 'estasoft-erp-crm-sync-engine' ), 'partner.', $this->customerRequired, true );
		}
		return $builder;
	}

	/**
	 * The product match field.
	 */
	public function products(): FieldCatalogBuilder {
		return FieldCatalogBuilder::make()
			->group( __( 'Product', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'product.reference', sprintf( /* translators: %s: system name. */ __( 'Product code in %s', 'estasoft-erp-crm-sync-engine' ), $this->system ), true );
	}

	/**
	 * The refund fields.
	 */
	public function refunds(): FieldCatalogBuilder {
		$builder = FieldCatalogBuilder::make()
			->group( __( 'Refund', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC- and the order number)', 'estasoft-erp-crm-sync-engine' ), true )
			->date( 'date', __( 'Refund date', 'estasoft-erp-crm-sync-engine' ) )
			->longText( 'reason', __( 'Reason', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'amount', __( 'Refund amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Refund lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'refund_line[].product', $this->productLabel )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Amount refunded for the line (minor units, without tax)', 'estasoft-erp-crm-sync-engine' ) );
		if ( $this->withTax ) {
			$builder->taxCode( 'refund_line[].tax', __( 'Tax code', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $builder;
	}

	/**
	 * Name, contact and address fields of a person.
	 *
	 * @param FieldCatalogBuilder $builder  Builder.
	 * @param string              $group    Group heading.
	 * @param string              $prefix   Path prefix.
	 * @param bool                $required Whether the email and name are required.
	 * @param bool                $contact  Whether email, company and tax number are offered (the delivery address has none).
	 */
	private function person( FieldCatalogBuilder $builder, string $group, string $prefix, bool $required, bool $contact ): void {
		$builder->group( $group )->text( $prefix . 'name', __( 'Name', 'estasoft-erp-crm-sync-engine' ), $required );
		if ( $contact ) {
			$builder->field( $prefix . 'email', __( 'Email', 'estasoft-erp-crm-sync-engine' ), 'email', $required )
				->text( $prefix . 'company_name', __( 'Company', 'estasoft-erp-crm-sync-engine' ) );
		}
		$builder->field( $prefix . 'phone', __( 'Phone', 'estasoft-erp-crm-sync-engine' ), 'phone' )
			->text( $prefix . 'street', __( 'Street', 'estasoft-erp-crm-sync-engine' ) )
			->text( $prefix . 'city', __( 'City', 'estasoft-erp-crm-sync-engine' ) )
			->text( $prefix . 'state', __( 'State or region', 'estasoft-erp-crm-sync-engine' ) )
			->text( $prefix . 'zip', __( 'Postal code', 'estasoft-erp-crm-sync-engine' ) )
			->field( $prefix . 'country', __( 'Country (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'country' );
		if ( $contact ) {
			$builder->text( $prefix . 'vat', __( 'Tax number', 'estasoft-erp-crm-sync-engine' ) );
		}
	}
}
