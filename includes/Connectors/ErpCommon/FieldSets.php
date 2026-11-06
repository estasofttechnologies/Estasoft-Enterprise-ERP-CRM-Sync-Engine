<?php
/**
 * The target fields shared by the ERP connectors, for the mapping screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * The payload paths that OrderPayload and RefundPayload read. A connector chooses the blocks it uses: the buyer block when it creates or
 * matches customers, the delivery block when it can keep a second address, the extras when it can carry shipping, fees and a discount.
 */
final class FieldSets {

	/**
	 * Order fields.
	 *
	 * @param string $system   System name for labels.
	 * @param bool   $buyer    Include the customer block.
	 * @param bool   $delivery Include the delivery address block.
	 * @param bool   $extras   Include shipping, fee and discount.
	 * @return array<int,FieldDef>
	 */
	public static function orders( string $system, bool $buyer = true, bool $delivery = true, bool $extras = true ): array {
		$builder = FieldCatalogBuilder::make()
			->group( sprintf( /* translators: %s: system name. */ __( 'Order in %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) )
			->text( 'client_ref', __( 'Customer reference (the order number, WC-...)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date_order', __( 'Order date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'note', __( 'Customer note', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total the shop expects, with tax (minor units); the total in the system is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( $buyer ) {
			$builder = self::person( $builder->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'partner.', true );
		}
		if ( $delivery ) {
			$builder = self::person( $builder->group( __( 'Delivery address', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'delivery.', false );
		}
		$builder = $builder->group( __( 'Order lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'order_line[].product', __( 'Product (item code, same as the SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price (minor units; without tax unless the prices include tax)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->number( 'order_line[].discount', __( 'Discount in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'order_line[].tax', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( $extras ) {
			$builder = $builder->group( __( 'Shipping, fees and discounts', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->money( 'shipping.total', __( 'Shipping amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->text( 'shipping.name', __( 'Shipping line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->taxCode( 'shipping.tax', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->text( 'fee[].name', __( 'Fee text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->money( 'fee[].total', __( 'Fee amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->taxCode( 'fee[].tax', __( 'Fee tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->money( 'discount.total', __( 'Discount amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->text( 'discount.name', __( 'Discount text', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $builder->group( __( 'Payment', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference (transaction id)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->date( 'payment.paid_at', __( 'Date paid (empty when the order is not paid)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Refund fields.
	 *
	 * @param string $system System name.
	 * @param string $noun   What the refund becomes, for labels: credit note, credit memo, return.
	 * @return array<int,FieldDef>
	 */
	public static function refunds( string $system, string $noun ): array {
		return FieldCatalogBuilder::make()
			->group( sprintf( /* translators: 1: system name, 2: document name. */ __( '%2$s in %1$s', 'estasoft-enterprise-erpcrm-sync-engine' ), $system, $noun ) )
			->text( 'order_ref', __( 'Original order reference (WC-...)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date', __( 'Refund date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'reason', __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund total (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping_amount', __( 'Refunded shipping, with tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Refunded lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].product', __( 'Product (item code, same as the SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Line amount before tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * The product match field.
	 *
	 * @param string $label Label of the field in the system, for example "Item code".
	 * @return array<int,FieldDef>
	 */
	public static function products( string $label ): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Product match', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'product.reference', $label, true )
			->build();
	}

	/**
	 * The customer fields alone (the "customers" mapping type).
	 *
	 * @return array<int,FieldDef>
	 */
	public static function customers(): array {
		return self::person( FieldCatalogBuilder::make()->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'partner.', true )->build();
	}

	/**
	 * Stock read from the system (inbound): the values the connector returns.
	 *
	 * @param string $system System name.
	 * @return array<int,FieldDef>
	 */
	public static function stock( string $system ): array {
		return FieldCatalogBuilder::make()
			->group( sprintf( /* translators: %s: system name. */ __( '%s stock', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) )
			->number( 'quantity', __( 'Quantity on hand', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'held', __( 'Quantity held (reserved)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Tracking read from the system (inbound): the values the connector returns.
	 *
	 * @param string $system System name.
	 * @return array<int,FieldDef>
	 */
	public static function tracking( string $system ): array {
		return FieldCatalogBuilder::make()
			->group( sprintf( /* translators: %s: system name. */ __( '%s delivery', 'estasoft-enterprise-erpcrm-sync-engine' ), $system ) )
			->choice( 'status', __( 'Delivery state', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'carrier', __( 'Carrier', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'tracking', __( 'Tracking number', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->datetime( 'done_at', __( 'Date of delivery', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Person fields with a path prefix.
	 *
	 * @param FieldCatalogBuilder $builder Builder with the group set.
	 * @param string              $prefix  partner. or delivery.
	 * @param bool                $buyer   Whether this is the buyer (email and name required).
	 */
	private static function person( FieldCatalogBuilder $builder, string $prefix, bool $buyer ): FieldCatalogBuilder {
		$builder = $buyer
			? $builder->field( $prefix . 'email', __( 'Email (matches the customer, ignoring case)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', true )
			: $builder;
		return $builder
			->text( $prefix . 'name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), $buyer )
			->text( $prefix . 'company_name', __( 'Company name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( $prefix . 'street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'state', __( 'State (ISO 3166-2 code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'state' )
			->text( $prefix . 'zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'country', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' )
			->text( $prefix . 'vat', __( 'Tax number (VAT)', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}
}
