<?php
/**
 * The list of canonical source fields rules can read.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Mapping\Support\MetaKeyPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the canonical model in docs/ARCHITECTURE.md. The mapping screen lists these as "WooCommerce fields"; the validator checks
 * rule sources against them. Fields the store adds itself are read as meta.<key> (for example meta._gift_message), except keys that look like payment tokens, card data, passwords or visitor addresses (MetaKeyPolicy).
 */
final class CanonicalFields {

	/**
	 * Every canonical field, in screen order.
	 *
	 * @return array<int,array{path:string,label:string,type:string,group:string}>
	 */
	public function all(): array {
		$groups = array(
			'Order'          => __( 'Order', 'estasoft-erp-crm-sync-engine' ),
			'Payment'        => __( 'Payment', 'estasoft-erp-crm-sync-engine' ),
			'Customer'       => __( 'Customer', 'estasoft-erp-crm-sync-engine' ),
			'Billing'        => __( 'Billing', 'estasoft-erp-crm-sync-engine' ),
			'Shipping'       => __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
			'Order lines'    => __( 'Order lines', 'estasoft-erp-crm-sync-engine' ),
			'Shipping lines' => __( 'Shipping lines', 'estasoft-erp-crm-sync-engine' ),
			'Fees'           => __( 'Fees', 'estasoft-erp-crm-sync-engine' ),
			'Taxes'          => __( 'Taxes', 'estasoft-erp-crm-sync-engine' ),
			'Coupons'        => __( 'Coupons', 'estasoft-erp-crm-sync-engine' ),
			'Refund'         => __( 'Refund', 'estasoft-erp-crm-sync-engine' ),
		);
		$fields = array();
		foreach ( $this->table() as $row ) {
			$fields[] = array(
				'path'  => $row[0],
				'label' => $row[2],
				'type'  => $row[1],
				'group' => $groups[ $row[3] ],
			);
		}
		return $fields;
	}

	/**
	 * Whether a path is a canonical field or a store field (meta.<key>). A row index such as lines[0].sku counts as lines[].sku.
	 *
	 * @param string $path Source path.
	 */
	public function has( string $path ): bool {
		$normal = (string) preg_replace( '/\[\d+\]/', '[]', $path );
		if ( 1 === preg_match( '/^meta\.[A-Za-z0-9_\-]+$/', $normal ) ) {
			return MetaKeyPolicy::isAllowed( substr( $normal, 5 ) );
		}
		foreach ( $this->table() as $row ) {
			if ( $row[0] === $normal ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The meta keys a set of rules asks for (every source or condition of the form meta.<key>).
	 *
	 * @param array<int,array<string,mixed>> $rules Rules.
	 * @return array<int,string> Keys without the "meta." prefix, unique.
	 */
	public function metaKeys( array $rules ): array {
		$paths = array();
		foreach ( $rules as $raw ) {
			$rule  = Rule::fromArray( is_array( $raw ) ? $raw : array() );
			$paths = array_merge( $paths, $rule->sources, null === $rule->when ? array() : array( $rule->when['source'] ) );
		}
		$keys = array();
		foreach ( $paths as $path ) {
			if ( 0 === strpos( $path, 'meta.' ) && MetaKeyPolicy::isAllowed( substr( $path, 5 ) ) ) {
				$keys[] = substr( $path, 5 );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Path, type, label, group key.
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:string}>
	 */
	private function table(): array {
		return array(
			array( 'order.id', 'number', __( 'Order ID', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.number', 'text', __( 'Order number', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.status', 'text', __( 'Order status', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.currency', 'text', __( 'Currency', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.created_at', 'datetime', __( 'Date placed', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.paid_at', 'datetime', __( 'Date paid', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.note', 'text', __( 'Customer note', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.client_ref', 'text', __( 'Client reference (WC-ID)', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.total', 'money', __( 'Order total', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.subtotal', 'money', __( 'Subtotal', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.discount_total', 'money', __( 'Discount total', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.shipping_total', 'money', __( 'Shipping total', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.tax_total', 'money', __( 'Tax total', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.excluded_count', 'number', __( 'Product lines left out (Exclude from syncing)', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.excluded_total', 'money', __( 'Total of the lines left out, with tax', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.excluded_tax', 'money', __( 'Tax of the lines left out', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.buyer_tax_number', 'text', __( 'Buyer tax number', 'estasoft-erp-crm-sync-engine' ), 'Order' ),
			array( 'order.payment.method_id', 'text', __( 'Payment method ID', 'estasoft-erp-crm-sync-engine' ), 'Payment' ),
			array( 'order.payment.method_title', 'text', __( 'Payment method', 'estasoft-erp-crm-sync-engine' ), 'Payment' ),
			array( 'order.payment.transaction_id', 'text', __( 'Transaction ID', 'estasoft-erp-crm-sync-engine' ), 'Payment' ),
			array( 'order.payment.last4', 'text', __( 'Card last four digits', 'estasoft-erp-crm-sync-engine' ), 'Payment' ),
			array( 'order.payment.is_cod', 'boolean', __( 'Cash on delivery', 'estasoft-erp-crm-sync-engine' ), 'Payment' ),
			array( 'customer.id', 'number', __( 'Customer ID', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.email', 'text', __( 'Customer email', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.first_name', 'text', __( 'Customer first name', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.last_name', 'text', __( 'Customer last name', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.company', 'text', __( 'Customer company', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.phone', 'text', __( 'Customer phone', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'customer.is_guest', 'boolean', __( 'Guest checkout', 'estasoft-erp-crm-sync-engine' ), 'Customer' ),
			array( 'billing.first_name', 'text', __( 'Billing first name', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.last_name', 'text', __( 'Billing last name', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.company', 'text', __( 'Billing company', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.address_1', 'text', __( 'Billing address line 1', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.address_2', 'text', __( 'Billing address line 2', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.city', 'text', __( 'Billing city', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.state', 'text', __( 'Billing state', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.postcode', 'text', __( 'Billing postcode', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.country', 'text', __( 'Billing country', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.phone', 'text', __( 'Billing phone', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'billing.email', 'text', __( 'Billing email', 'estasoft-erp-crm-sync-engine' ), 'Billing' ),
			array( 'shipping.first_name', 'text', __( 'Shipping first name', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.last_name', 'text', __( 'Shipping last name', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.company', 'text', __( 'Shipping company', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.address_1', 'text', __( 'Shipping address line 1', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.address_2', 'text', __( 'Shipping address line 2', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.city', 'text', __( 'Shipping city', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.state', 'text', __( 'Shipping state', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.postcode', 'text', __( 'Shipping postcode', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.country', 'text', __( 'Shipping country', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'shipping.phone', 'text', __( 'Shipping phone', 'estasoft-erp-crm-sync-engine' ), 'Shipping' ),
			array( 'lines[].product_id', 'number', __( 'Line product ID', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].variation_id', 'number', __( 'Line variation ID', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].sku', 'text', __( 'Line SKU', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].name', 'text', __( 'Line name', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].quantity', 'number', __( 'Line quantity', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].unit_price', 'money', __( 'Line unit price', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].line_total', 'money', __( 'Line total', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].line_tax', 'money', __( 'Line tax', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].tax_rate', 'number', __( 'Line tax rate (%)', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'lines[].tax_class', 'text', __( 'Line tax class', 'estasoft-erp-crm-sync-engine' ), 'Order lines' ),
			array( 'shipping_lines[].method_id', 'text', __( 'Shipping method ID', 'estasoft-erp-crm-sync-engine' ), 'Shipping lines' ),
			array( 'shipping_lines[].method_title', 'text', __( 'Shipping method', 'estasoft-erp-crm-sync-engine' ), 'Shipping lines' ),
			array( 'shipping_lines[].total', 'money', __( 'Shipping line total', 'estasoft-erp-crm-sync-engine' ), 'Shipping lines' ),
			array( 'shipping_lines[].tax', 'money', __( 'Shipping line tax', 'estasoft-erp-crm-sync-engine' ), 'Shipping lines' ),
			array( 'shipping_lines[].tax_rate', 'number', __( 'Shipping tax rate (%)', 'estasoft-erp-crm-sync-engine' ), 'Shipping lines' ),
			array( 'fees[].name', 'text', __( 'Fee name', 'estasoft-erp-crm-sync-engine' ), 'Fees' ),
			array( 'fees[].total', 'money', __( 'Fee total', 'estasoft-erp-crm-sync-engine' ), 'Fees' ),
			array( 'fees[].tax', 'money', __( 'Fee tax', 'estasoft-erp-crm-sync-engine' ), 'Fees' ),
			array( 'taxes[].rate_id', 'number', __( 'Tax rate ID', 'estasoft-erp-crm-sync-engine' ), 'Taxes' ),
			array( 'taxes[].label', 'text', __( 'Tax label', 'estasoft-erp-crm-sync-engine' ), 'Taxes' ),
			array( 'taxes[].rate_percent', 'number', __( 'Tax rate (%)', 'estasoft-erp-crm-sync-engine' ), 'Taxes' ),
			array( 'taxes[].amount', 'money', __( 'Tax amount', 'estasoft-erp-crm-sync-engine' ), 'Taxes' ),
			array( 'coupons[].code', 'text', __( 'Coupon code', 'estasoft-erp-crm-sync-engine' ), 'Coupons' ),
			array( 'coupons[].discount', 'money', __( 'Coupon discount', 'estasoft-erp-crm-sync-engine' ), 'Coupons' ),
			array( 'refund.id', 'number', __( 'Refund ID', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.order_id', 'number', __( 'Refunded order ID', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.amount', 'money', __( 'Refund amount', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.reason', 'text', __( 'Refund reason', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.created_at', 'datetime', __( 'Refund date', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.lines[].sku', 'text', __( 'Refunded line SKU', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.lines[].quantity', 'number', __( 'Refunded line quantity', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.lines[].amount', 'money', __( 'Refunded line amount', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.lines[].tax', 'money', __( 'Refunded line tax', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.shipping_amount', 'money', __( 'Refunded shipping, with tax', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.excluded_amount', 'money', __( 'Refunded amount of the lines left out', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
			array( 'refund.other_amount', 'money', __( 'Refunded amount not explained by lines or shipping', 'estasoft-erp-crm-sync-engine' ), 'Refund' ),
		);
	}
}
