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
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
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
			->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'contact.email', __( 'Email', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', $required )
			->text( 'contact.first_name', __( 'First name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'contact.last_name', __( 'Last name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'contact.company', __( 'Company', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'contact.phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( 'contact.street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'contact.city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'contact.state', __( 'State or region', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'contact.postcode', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'contact.country', __( 'Country', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' )
			->build();
	}

	/**
	 * Fields or rules of an order.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function orders(): array {
		$deal  = FieldCatalogBuilder::make()
			->group( __( 'Order', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'reference', __( 'Order reference (WC-number)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency', true )
			->date( 'date', __( 'Order date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->datetime( 'paid_at', __( 'Paid on (empty means unpaid)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'note', __( 'Order note', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total with tax', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'excluded_total', __( 'Total of products left out of the sync', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'Line prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
		$lines = FieldCatalogBuilder::make()
			->group( __( 'Lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'Line SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'lines[].name', __( 'Line name', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->number( 'lines[].quantity', __( 'Line quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'lines[].unit_price', __( 'Line unit price without tax', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'lines[].total', __( 'Line total without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'lines[].tax', __( 'Line tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fees[].name', __( 'Fee name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fees[].total', __( 'Fee without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'discount.codes', __( 'Coupon codes', 'estasoft-enterprise-erpcrm-sync-engine' ) )
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
			->group( __( 'Refund', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Order reference (WC-number)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'amount', __( 'Refund amount', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->date( 'date', __( 'Refund date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'reason', __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}
}
