<?php
/**
 * The target fields of the Exact Online mappings.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Payload paths for the mapping screens, with the labels the person sees. See docs/connectors/exact-online.md, section 6.
 */
final class ExactFields {

	/**
	 * Fields of one mapping type; empty for a type the connector does not use.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public static function catalog( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => array_merge( self::order(), self::partner( true ), self::lines(), self::extras() ),
			'customers' => self::partner( true ),
			'products'  => FieldCatalogBuilder::make()->group( __( 'Item', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.reference', __( 'Item code', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
			'refunds'   => self::refunds(),
			'stock'     => FieldCatalogBuilder::make()
				->group( __( 'Exact Online item', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'quantity', __( 'Quantity in stock (sum of the chosen warehouses)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'held', __( 'Quantity planned for delivery on open orders', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->build(),
			default     => array(),
		};
	}

	/**
	 * Header fields of an order.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function order(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Order', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency', true )
			->datetime( 'date_order', __( 'Order date (becomes the invoice date)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'note', __( 'Order note (Remarks)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total with VAT (checked against Exact Online)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'excluded_total', __( 'Total of products left out of the sync', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'Line prices include VAT', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Customer fields.
	 *
	 * @param bool $required Whether the email and name are required.
	 * @return array<int,FieldDef>
	 */
	private static function partner( bool $required ): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'partner.email', __( 'Email (finds the account)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', $required )
			->text( 'partner.name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), $required )
			->text( 'partner.company_name', __( 'Company name (used as the account name when given)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'partner.phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( 'partner.street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'partner.city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'partner.zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'partner.country_id', __( 'Country', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' )
			->text( 'partner.vat', __( 'VAT number', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Product lines.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function lines(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'order_line[].product', __( 'Item (matched by item code)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'order_line[].name', __( 'Line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->taxCode( 'order_line[].tax', __( 'VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Shipping, fee and discount lines.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function extras(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Shipping, fees and discount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax', __( 'Shipping VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fee[].total', __( 'Fee amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'fee[].tax', __( 'Fee VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'discount.name', __( 'Discount line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'discount.tax', __( 'Discount VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Refund', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC-number)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date', __( 'Refund date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'reason', __( 'Reason (Remarks)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund amount with VAT (checked against Exact Online)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->boolean( 'prices_include_tax', __( 'Line amounts include VAT', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].product', __( 'Item (matched by item code)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'refund_line[].name', __( 'Line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Line amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'refund_line[].tax', __( 'VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.amount', __( 'Refunded shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax', __( 'Shipping VAT code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}
}
