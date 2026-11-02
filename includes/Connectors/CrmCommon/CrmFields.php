<?php
/**
 * The target fields of the mapping screens for sales systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * One neutral set of target paths for every CRM connector; each connector turns them into its own fields.
 */
final class CrmFields {

	/**
	 * Fields of one mapping type; empty for types the connector does not use.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public static function catalog( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => self::orders(),
			'customers' => self::customer( true ),
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product', 'estasoft-erp-crm-sync-engine' ) )->sku( 'product.sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ), true )->build(),
			'refunds'   => self::refunds(),
			default     => array(),
		};
	}

	/**
	 * Fields of a customer.
	 *
	 * @param bool $required Whether the email is required.
	 * @return array<int,FieldDef>
	 */
	private static function customer( bool $required ): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Customer', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'contact.email', __( 'Email', 'estasoft-erp-crm-sync-engine' ), 'email', $required )
			->text( 'contact.first_name', __( 'First name', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'contact.last_name', __( 'Last name', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'contact.company', __( 'Company', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'contact.phone', __( 'Phone', 'estasoft-erp-crm-sync-engine' ), 'phone' )
			->text( 'contact.street', __( 'Street', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'contact.city', __( 'City', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'contact.state', __( 'State or region', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'contact.postcode', __( 'Postcode', 'estasoft-erp-crm-sync-engine' ) )
			->field( 'contact.country', __( 'Country', 'estasoft-erp-crm-sync-engine' ), 'country' )
			->build();
	}

	/**
	 * Fields or rules of an order.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function orders(): array {
		$deal  = FieldCatalogBuilder::make()
			->group( __( 'Order', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'reference', __( 'Order reference (WC-number)', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-erp-crm-sync-engine' ), 'currency', true )
			->date( 'date', __( 'Order date', 'estasoft-erp-crm-sync-engine' ) )
			->datetime( 'paid_at', __( 'Paid on (empty means unpaid)', 'estasoft-erp-crm-sync-engine' ) )
			->longText( 'note', __( 'Order note', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'total', __( 'Order total with tax', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'excluded_total', __( 'Total of products left out of the sync', 'estasoft-erp-crm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'Line prices include tax', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-erp-crm-sync-engine' ) )
			->build();
		$lines = FieldCatalogBuilder::make()
			->group( __( 'Lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'Line SKU', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'lines[].name', __( 'Line name', 'estasoft-erp-crm-sync-engine' ), true )
			->number( 'lines[].quantity', __( 'Line quantity', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'lines[].unit_price', __( 'Line unit price without tax', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'lines[].total', __( 'Line total without tax', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'lines[].tax', __( 'Line tax', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping without tax', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'fees[].name', __( 'Fee name', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fees[].total', __( 'Fee without tax', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'discount.codes', __( 'Coupon codes', 'estasoft-erp-crm-sync-engine' ) )
			->build();
		return array_merge( $deal, self::customer( true ), $lines );
	}

	/**
	 * Fields or rules of a refund.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Refund', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC-number)', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'amount', __( 'Refund amount', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-erp-crm-sync-engine' ), 'currency' )
			->date( 'date', __( 'Refund date', 'estasoft-erp-crm-sync-engine' ) )
			->longText( 'reason', __( 'Reason', 'estasoft-erp-crm-sync-engine' ) )
			->build();
	}
}
