<?php
/**
 * The target fields of the Fortnox mappings.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Payload paths for the mapping screens, with the labels the person sees. See docs/connectors/fortnox.md, section 6.
 */
final class FortnoxFields {

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
			'products'  => FieldCatalogBuilder::make()->group( __( 'Article', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.reference', __( 'Article number', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
			'refunds'   => self::refunds(),
			'stock'     => FieldCatalogBuilder::make()
				->group( __( 'Fortnox article', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'quantity', __( 'Quantity in stock', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'held', __( 'Reserved quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->datetime( 'paid_at', __( 'Paid on (empty means unpaid; used when the payment is recorded)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'note', __( 'Order note (internal comment)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total with VAT (checked against Fortnox)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'excluded_total', __( 'Total of products left out of the sync', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'Line prices include VAT (sets VATIncluded)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->field( 'partner.email', __( 'Email (finds the customer)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', $required )
			->text( 'partner.name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), $required )
			->text( 'partner.company_name', __( 'Company name (used as the customer name when given)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->sku( 'order_line[].product', __( 'Article (matched by article number)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'order_line[].name', __( 'Line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->taxCode( 'order_line[].tax', __( 'VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->taxCode( 'shipping.tax', __( 'Shipping VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fee[].total', __( 'Fee amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'fee[].tax', __( 'Fee VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'discount.name', __( 'Discount line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'discount.tax', __( 'Discount VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->longText( 'reason', __( 'Reason (internal comment)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund amount with VAT (checked against Fortnox)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->boolean( 'prices_include_tax', __( 'Line amounts include VAT', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].product', __( 'Article (matched by article number)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'refund_line[].name', __( 'Line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Line amount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'refund_line[].tax', __( 'VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.amount', __( 'Refunded shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax', __( 'Shipping VAT rate', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}
}
