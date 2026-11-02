<?php
/**
 * Field catalog groups the three Microsoft connectors share.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;

/**
 * The target paths of the mapped payload (see MappingRules and OrderData). A connector adds its own groups after these.
 */
final class FieldGroups {

	/**
	 * Order header fields.
	 *
	 * @param FieldCatalogBuilder $b     Builder.
	 * @param string              $group Group heading.
	 */
	public static function orderHeader( FieldCatalogBuilder $b, string $group ): FieldCatalogBuilder {
		return $b->group( $group )
			->date( 'order_date', __( 'Order date', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'currency', true )
			->longText( 'note', __( 'Order note', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'total', __( 'Order total the shop expects, with tax (minor units); the system\'s total is checked against it', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The buyer.
	 *
	 * @param FieldCatalogBuilder $b      Builder.
	 * @param string              $group  Group heading.
	 * @param string              $prefix Path prefix.
	 * @param bool                $split  True for first and last name, false for one name.
	 */
	public static function customer( FieldCatalogBuilder $b, string $group, string $prefix = 'partner.', bool $split = false ): FieldCatalogBuilder {
		$b->group( $group )->field( $prefix . 'email', __( 'Email (matches the customer, ignoring case)', 'estasoft-erp-crm-sync-engine' ), 'email', true );
		if ( $split ) {
			$b->text( $prefix . 'first_name', __( 'First name', 'estasoft-erp-crm-sync-engine' ) )->text( $prefix . 'last_name', __( 'Last name', 'estasoft-erp-crm-sync-engine' ), true );
		} else {
			$b->text( $prefix . 'name', __( 'Name', 'estasoft-erp-crm-sync-engine' ), true );
		}
		return $b->text( $prefix . 'company_name', __( 'Company name', 'estasoft-erp-crm-sync-engine' ) )
			->field( $prefix . 'phone', __( 'Phone', 'estasoft-erp-crm-sync-engine' ), 'phone' )
			->text( $prefix . 'street', __( 'Street', 'estasoft-erp-crm-sync-engine' ) )
			->text( $prefix . 'street2', __( 'Street 2', 'estasoft-erp-crm-sync-engine' ) )
			->text( $prefix . 'city', __( 'City', 'estasoft-erp-crm-sync-engine' ) )
			->field( $prefix . 'state_id', __( 'State', 'estasoft-erp-crm-sync-engine' ), 'string' )
			->text( $prefix . 'zip', __( 'Postcode', 'estasoft-erp-crm-sync-engine' ) )
			->field( $prefix . 'country_id', __( 'Country (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'country' )
			->text( $prefix . 'vat', __( 'Tax number', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The delivery address.
	 *
	 * @param FieldCatalogBuilder $b     Builder.
	 * @param string              $group Group heading.
	 */
	public static function delivery( FieldCatalogBuilder $b, string $group ): FieldCatalogBuilder {
		return $b->group( $group )
			->text( 'delivery.name', __( 'Name', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'delivery.phone', __( 'Phone', 'estasoft-erp-crm-sync-engine' ), 'phone' )
			->text( 'delivery.street', __( 'Street', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'delivery.street2', __( 'Street 2', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'delivery.city', __( 'City', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'delivery.state_id', __( 'State', 'estasoft-erp-crm-sync-engine' ), 'string' )
			->text( 'delivery.zip', __( 'Postcode', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'delivery.country_id', __( 'Country (ISO code)', 'estasoft-erp-crm-sync-engine' ), 'country' );
	}

	/**
	 * Product lines.
	 *
	 * @param FieldCatalogBuilder $b        Builder.
	 * @param string              $group    Group heading.
	 * @param bool                $taxCodes True for a tax code per line, false for a tax amount.
	 */
	public static function lines( FieldCatalogBuilder $b, string $group, bool $taxCodes ): FieldCatalogBuilder {
		$b->group( $group )
			->sku( 'order_line[].product', __( 'Product (item number or SKU)', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'order_line[].name', __( 'Line description', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), true );
		if ( $taxCodes ) {
			return $b->taxCode( 'order_line[].tax', __( 'Tax code', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $b->money( 'order_line[].tax_amount', __( 'Tax of the line (minor units)', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * Shipping, fees and the discount.
	 *
	 * @param FieldCatalogBuilder $b        Builder.
	 * @param string              $group    Group heading.
	 * @param bool                $taxCodes    True for tax codes, false for tax amounts.
	 * @param bool                $discountTax True to list a tax code for the discount (only where the system takes one).
	 */
	public static function extras( FieldCatalogBuilder $b, string $group, bool $taxCodes, bool $discountTax = true ): FieldCatalogBuilder {
		$b->group( $group )
			->money( 'shipping.total', __( 'Shipping amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping line text', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee text', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fee[].total', __( 'Fee amount (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount amount (minor units, a positive number)', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'discount.name', __( 'Discount text', 'estasoft-erp-crm-sync-engine' ) );
		if ( $taxCodes ) {
			$b->taxCode( 'shipping.tax', __( 'Shipping tax code', 'estasoft-erp-crm-sync-engine' ) )
				->taxCode( 'fee[].tax', __( 'Fee tax code', 'estasoft-erp-crm-sync-engine' ) );
			return $discountTax ? $b->taxCode( 'discount.tax', __( 'Discount tax code', 'estasoft-erp-crm-sync-engine' ) ) : $b;
		}
		return $b->money( 'shipping.tax_amount', __( 'Shipping tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fee[].tax_amount', __( 'Fee tax (minor units)', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * Product matching.
	 *
	 * @param FieldCatalogBuilder $b     Builder.
	 * @param string              $label Label of the field.
	 */
	public static function products( FieldCatalogBuilder $b, string $label ): FieldCatalogBuilder {
		return $b->group( __( 'Product match', 'estasoft-erp-crm-sync-engine' ) )->sku( 'product.reference', $label, true );
	}
}
