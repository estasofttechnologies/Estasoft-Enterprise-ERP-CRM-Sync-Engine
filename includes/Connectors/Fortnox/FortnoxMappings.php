<?php
/**
 * Version 1 mapping rules for Fortnox.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

/**
 * The starting rules a merchant sees before editing anything. Each rule follows the rule model of docs/ARCHITECTURE.md. For the inbound
 * type "stock" the source is the Fortnox value and the target is the WooCommerce path.
 */
final class FortnoxMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => self::orders(),
			'customers' => self::partner(),
			'products'  => array( self::rule( 'product-reference', 'product.reference', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ) ),
			'refunds'   => self::refunds(),
			'stock'     => array( self::rule( 'stock-quantity', 'product.stock_quantity', 'quantity' ) ),
			default     => array(),
		};
	}

	/**
	 * Orders to sales invoices or sales orders.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function orders(): array {
		return array_merge(
			array(
				self::rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'order-date', 'date_order', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
				self::rule( 'order-paid', 'paid_at', 'order.paid_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
				self::rule( 'order-note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
				self::rule( 'order-total', 'total', 'order.total' ),
				self::rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
				self::rule( 'order-payment', 'payment.reference', 'order.payment.transaction_id', array( self::transform( 'trim' ) ) ),
			),
			self::partner(),
			array(
				self::rule( 'line-product', 'order_line[].product', 'lines[].sku', array( self::transform( 'sku_match', array( 'output' => 'ref' ) ) ), true, 'SYNC-MAP-001' ),
				self::rule( 'line-name', 'order_line[].name', 'lines[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'line-quantity', 'order_line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-price', 'order_line[].price_unit', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-tax', 'order_line[].tax', 'lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				self::rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				self::rule( 'shipping-tax', 'shipping.tax', 'shipping_lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				self::rule( 'shipping-name', 'shipping.name', array( 'const' => 'Shipping' ) ),
				self::rule( 'fee-name', 'fee[].name', 'fees[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'fee-total', 'fee[].total', 'fees[].total' ),
				self::rule( 'discount-total', 'discount.total', 'order.discount_total' ),
				self::rule( 'discount-name', 'discount.name', 'coupons[].code', array( self::transform( 'join', array( 'separator' => ', ' ) ) ) ),
			)
		);
	}

	/**
	 * Refunds to credit notes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function refunds(): array {
		return array(
			self::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( self::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-date', 'date', 'refund.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
			self::rule( 'refund-reason', 'reason', 'refund.reason', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-amount', 'amount', 'refund.amount', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-currency', 'currency', 'order.currency' ),
			self::rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
			self::rule( 'refund-line-amount', 'refund_line[].amount', 'refund.lines[].amount' ),
			self::rule( 'refund-shipping', 'shipping.amount', 'refund.shipping_amount' ),
		);
	}

	/**
	 * Rules for the billing customer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function partner(): array {
		return array(
			self::rule( 'partner-email', 'partner.email', 'billing.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ), true, 'SYNC-MAP-003' ),
			self::rule( 'partner-name', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'partner-company', 'partner.company_name', 'billing.company', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-phone', 'partner.phone', 'billing.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
			self::rule( 'partner-street', 'partner.street', array( 'billing.address_1', 'billing.address_2' ), array( self::transform( 'join', array( 'separator' => ', ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'partner-city', 'partner.city', 'billing.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-zip', 'partner.zip', 'billing.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-country', 'partner.country_id', 'billing.country', array( self::transform( 'iso_country' ) ) ),
			self::rule( 'partner-vat', 'partner.vat', 'order.buyer_tax_number', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * One rule.
	 *
	 * @param string                                      $id         Stable id inside the rule list.
	 * @param string                                      $target     Target path.
	 * @param string|array<int,string>|array{const:mixed} $source     Source path, list of paths, or a constant.
	 * @param array<int,array<string,mixed>>              $transforms Transforms in order.
	 * @param bool                                        $required   Whether an empty result stops the item.
	 * @param string|null                                 $errorCode  Catalog code raised when a required value is empty.
	 * @return array<string,mixed>
	 */
	private static function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
		$rule = array(
			'id'         => $id,
			'target'     => $target,
			'source'     => $source,
			'transforms' => $transforms,
			'required'   => $required,
		);
		if ( null !== $errorCode ) {
			$rule['error_code'] = $errorCode;
		}
		return $rule;
	}

	/**
	 * One transform.
	 *
	 * @param string              $type Transform name from the fixed library.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	private static function transform( string $type, array $args = array() ): array {
		$transform = array( 'type' => $type );
		if ( array() !== $args ) {
			$transform['args'] = $args;
		}
		return $transform;
	}
}
