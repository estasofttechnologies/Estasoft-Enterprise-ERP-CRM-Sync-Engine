<?php
/**
 * The Odoo target fields shown on the mapping screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Field paths are the paths of the payload the mapping engine builds for the connector; arrays use []. For the two types
 * that read from Odoo (stock and tracking) the list names the Odoo fields the rules read.
 */
final class OdooFieldCatalog {

	/**
	 * Fields for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->customer( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ), 'partner.', true ),
			'products'  => $this->products(),
			'refunds'   => $this->refunds(),
			'stock'     => $this->stock(),
			'tracking'  => $this->tracking(),
			default     => array(),
		};
	}

	/**
	 * Order fields.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function orders(): array {
		$order  = __( 'Sale order', 'estasoft-enterprise-erpcrm-sync-engine' );
		$lines  = __( 'Order lines', 'estasoft-enterprise-erpcrm-sync-engine' );
		$extras = __( 'Shipping, fees and discounts', 'estasoft-enterprise-erpcrm-sync-engine' );
		$flow   = __( 'Documents in Odoo', 'estasoft-enterprise-erpcrm-sync-engine' );
		$fields = array(
			$this->field( 'client_order_ref', __( 'Customer reference (client_order_ref)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', true, $order ),
			$this->field( 'date_order', __( 'Order date', 'estasoft-enterprise-erpcrm-sync-engine' ), 'datetime', false, $order ),
			$this->field( 'currency', __( 'Currency (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $order ),
			$this->field( 'note', __( 'Terms and notes (note)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'text', false, $order ),
			$this->field( 'payment_term', __( 'Payment term (name)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $order ),
			$this->field( 'warehouse', __( 'Warehouse (code or name)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $order ),
			$this->field( 'total', __( 'Order total the shop expects, with tax (minor units); Odoo\'s total is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $order ),
			$this->field( 'excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $order ),
			$this->field( 'prices_include_tax', __( 'The mapped prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $order ),
		);
		$fields = array_merge(
			$fields,
			$this->customer( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ), 'partner.', true ),
			$this->address( __( 'Delivery address', 'estasoft-enterprise-erpcrm-sync-engine' ), 'delivery.' ),
			array(
				$this->field( 'order_line[].product', __( 'Product (internal reference)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'sku', true, $lines ),
				$this->field( 'order_line[].product_id', __( 'Product (Odoo id, overrides the reference)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'integer', false, $lines ),
				$this->field( 'order_line[].name', __( 'Line description', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $lines ),
				$this->field( 'order_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), 'number', true, $lines ),
				$this->field( 'order_line[].price_unit', __( 'Unit price (minor units; without tax unless the prices include tax)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', true, $lines ),
				$this->field( 'order_line[].discount', __( 'Discount in percent', 'estasoft-enterprise-erpcrm-sync-engine' ), 'number', false, $lines ),
				$this->field( 'order_line[].tax', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), 'tax_code', false, $lines ),
				$this->field( 'shipping.total', __( 'Shipping amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $extras ),
				$this->field( 'shipping.name', __( 'Shipping line text', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $extras ),
				$this->field( 'shipping.tax', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), 'tax_code', false, $extras ),
				$this->field( 'fee[].name', __( 'Fee text', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $extras ),
				$this->field( 'fee[].total', __( 'Fee amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $extras ),
				$this->field( 'discount.total', __( 'Discount amount (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $extras ),
				$this->field( 'discount.name', __( 'Discount text', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $extras ),
				$this->field( 'flow.confirm', __( 'Confirm the sale order', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $flow ),
				$this->field( 'flow.invoice', __( 'Invoice: none, draft or post', 'estasoft-enterprise-erpcrm-sync-engine' ), 'choice', false, $flow ),
				$this->field( 'flow.register_payment', __( 'Register the payment', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $flow ),
				$this->field( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $flow ),
			)
		);
		return $fields;
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function refunds(): array {
		$note  = __( 'Credit note', 'estasoft-enterprise-erpcrm-sync-engine' );
		$lines = __( 'Credit note lines', 'estasoft-enterprise-erpcrm-sync-engine' );
		return array(
			$this->field( 'order_ref', __( 'Original order reference', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', true, $note ),
			$this->field( 'date', __( 'Credit note date', 'estasoft-enterprise-erpcrm-sync-engine' ), 'datetime', false, $note ),
			$this->field( 'reason', __( 'Reason (added to the reference)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $note ),
			$this->field( 'zatca_reason', __( 'ZATCA reason code (Saudi Arabia)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $note ),
			$this->field( 'amount', __( 'Refund total (minor units), used when there are no lines', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $note ),
			$this->field( 'flow.post', __( 'Post the credit note', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $note ),
			$this->field( 'prices_include_tax', __( 'The mapped amounts include tax', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $note ),
			$this->field( 'refund_line[].product', __( 'Product (internal reference)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'sku', false, $lines ),
			$this->field( 'refund_line[].name', __( 'Line description', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $lines ),
			$this->field( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), 'number', false, $lines ),
			$this->field( 'refund_line[].amount', __( 'Line amount (minor units; without tax unless the amounts include tax)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $lines ),
			$this->field( 'refund_line[].tax', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), 'tax_code', false, $lines ),
			$this->field( 'shipping.total', __( 'Refunded shipping (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'money', false, $lines ),
			$this->field( 'shipping.tax', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), 'tax_code', false, $lines ),
		);
	}

	/**
	 * Product matching fields.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function products(): array {
		return array(
			$this->field( 'product.reference', __( 'Internal reference (default_code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'sku', true, __( 'Product match', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
		);
	}

	/**
	 * Odoo fields read for stock.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function stock(): array {
		$group = __( 'Odoo product', 'estasoft-enterprise-erpcrm-sync-engine' );
		return array(
			$this->field( 'qty_available', __( 'Quantity on hand', 'estasoft-enterprise-erpcrm-sync-engine' ), 'number', false, $group ),
			$this->field( 'free_qty', __( 'Free quantity (on hand minus reserved)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'number', false, $group ),
			$this->field( 'active', __( 'Product is active', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $group ),
			$this->field( 'sale_ok', __( 'Product can be sold', 'estasoft-enterprise-erpcrm-sync-engine' ), 'boolean', false, $group ),
		);
	}

	/**
	 * Odoo fields read for tracking.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function tracking(): array {
		$group = __( 'Odoo delivery', 'estasoft-enterprise-erpcrm-sync-engine' );
		return array(
			$this->field( 'state', __( 'Delivery state', 'estasoft-enterprise-erpcrm-sync-engine' ), 'choice', false, $group ),
			$this->field( 'carrier_id', __( 'Carrier', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( 'carrier_tracking_ref', __( 'Tracking number', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( 'name', __( 'Delivery reference', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( 'date_done', __( 'Date of transfer', 'estasoft-enterprise-erpcrm-sync-engine' ), 'datetime', false, $group ),
		);
	}

	/**
	 * Customer fields with a path prefix.
	 *
	 * @param string $group  Group label.
	 * @param string $prefix Path prefix such as partner.
	 * @param bool   $email  Whether the email is required.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function customer( string $group, string $prefix, bool $email ): array {
		return array(
			$this->field( $prefix . 'email', __( 'Email (matches the customer, ignoring case)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', $email, $group ),
			$this->field( $prefix . 'name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', true, $group ),
			$this->field( $prefix . 'company_name', __( 'Company name', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone', false, $group ),
			$this->field( $prefix . 'street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'street2', __( 'Street 2', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'state_id', __( 'State (ISO 3166-2 code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'state', false, $group ),
			$this->field( $prefix . 'zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'country_id', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country', false, $group ),
			$this->field( $prefix . 'vat', __( 'Tax number (VAT)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
		);
	}

	/**
	 * Address fields (a delivery contact) with a path prefix.
	 *
	 * @param string $group  Group label.
	 * @param string $prefix Path prefix such as delivery.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function address( string $group, string $prefix ): array {
		return array(
			$this->field( $prefix . 'name', __( 'Name', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone', false, $group ),
			$this->field( $prefix . 'street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'street2', __( 'Street 2', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'state_id', __( 'State (ISO 3166-2 code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'state', false, $group ),
			$this->field( $prefix . 'zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ), 'string', false, $group ),
			$this->field( $prefix . 'country_id', __( 'Country (ISO code)', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country', false, $group ),
		);
	}

	/**
	 * One field entry.
	 *
	 * @param string $path     Target path.
	 * @param string $label    Label.
	 * @param string $type     Type word.
	 * @param bool   $required Whether the field must be filled.
	 * @param string $group    Group label.
	 * @return array{path:string,label:string,type:string,required:bool,group:string}
	 */
	private function field( string $path, string $label, string $type, bool $required, string $group ): array {
		return array(
			'path'     => $path,
			'label'    => $label,
			'type'     => $type,
			'required' => $required,
			'group'    => $group,
		);
	}
}
