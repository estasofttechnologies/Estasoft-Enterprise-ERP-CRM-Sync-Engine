<?php
/**
 * The QuickBooks target fields shown on the mapping screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; arrays use []. They carry the same names as the other
 * accounting connectors, so one mapping reads the same everywhere. For stock the list names the QuickBooks fields the rules read.
 */
final class QuickBooksFieldCatalog {

	/**
	 * Fields for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->customer( FieldCatalogBuilder::make()->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'partner.', true )->build(),
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product match', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.reference', __( 'Item SKU (the SKU is the QuickBooks item SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
			'refunds'   => $this->refunds(),
			'stock'     => FieldCatalogBuilder::make()
				->group( __( 'QuickBooks item', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'QtyOnHand', __( 'Quantity on hand (tracked inventory items only)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->boolean( 'TrackQtyOnHand', __( 'Item is tracked as inventory', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->build(),
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
			->text( 'client_order_ref', __( 'Document number (DocNumber; always the shop reference WC-{id})', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date_order', __( 'Invoice date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->longText( 'note', __( 'Order note (not sent to QuickBooks)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total the shop expects, with tax (minor units); QuickBooks\' total is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$builder = $this->customer( $builder->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'partner.', true );
		$builder = $this->address( $builder->group( __( 'Delivery address', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 'delivery.' );
		return $builder
			->group( __( 'Invoice lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'order_line[].product', __( 'Item SKU (the SKU is the QuickBooks item SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'order_line[].name', __( 'Line description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'order_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'order_line[].price_unit', __( 'Unit price (minor units; without tax unless the prices include tax)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->number( 'order_line[].discount', __( 'Discount in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'order_line[].tax', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Shipping, fees and discounts', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping line text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fee[].total', __( 'Fee amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'fee[].tax', __( 'Fee tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'discount.total', __( 'Discount amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'discount.name', __( 'Discount text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'discount.tax', __( 'Discount tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Payment', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->date( 'payment.date', __( 'Payment date (the order is paid when this is set)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		return FieldCatalogBuilder::make()
			->group( __( 'Credit memo', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Original order reference', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'date', __( 'Credit memo date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'reason', __( 'Reason (added to the first line)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund total (minor units); QuickBooks\' total is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'The mapped amounts include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Credit memo lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].product', __( 'Item SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'refund_line[].name', __( 'Line description', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].amount', __( 'Line amount (minor units; without tax unless the amounts include tax)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'refund_line[].tax', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Refunded shipping (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Customer fields with a path prefix.
	 *
	 * @param FieldCatalogBuilder $builder Builder with its group set.
	 * @param string              $prefix  Path prefix such as partner.
	 * @param bool                $email   Whether the email is required.
	 */
	private function customer( FieldCatalogBuilder $builder, string $prefix, bool $email ): FieldCatalogBuilder {
		return $builder
			->field( $prefix . 'email', __( 'Email (matches the customer, ignoring case)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', $email )
			->text( $prefix . 'name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( $prefix . 'first_name', __( 'First name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'last_name', __( 'Last name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'company_name', __( 'Company name (used as the customer name when set)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( $prefix . 'street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'street2', __( 'Street 2', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'state_id', __( 'State (ISO 3166-2 code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'state' )
			->text( $prefix . 'zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'country_id', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' )
			->text( $prefix . 'vat', __( 'Tax number', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * Delivery address fields with a path prefix.
	 *
	 * @param FieldCatalogBuilder $builder Builder with its group set.
	 * @param string              $prefix  Path prefix such as delivery.
	 */
	private function address( FieldCatalogBuilder $builder, string $prefix ): FieldCatalogBuilder {
		return $builder
			->text( $prefix . 'street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'street2', __( 'Street 2', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( $prefix . 'city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'state_id', __( 'State (ISO 3166-2 code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'state' )
			->text( $prefix . 'zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( $prefix . 'country_id', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' );
	}
}
