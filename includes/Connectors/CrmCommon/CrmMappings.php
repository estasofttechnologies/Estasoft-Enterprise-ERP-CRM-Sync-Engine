<?php
/**
 * Version 1 mapping rules for sales systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The same rules for every CRM connector, because the targets are the neutral paths of CrmFields.
 */
final class CrmMappings {

	/**
	 * Rules of one mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => self::orders(),
			'customers' => self::contact( true ),
			'products'  => array( self::rule( 'product-sku', 'product.sku', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ) ),
			'refunds'   => self::refunds(),
			default     => array(),
		};
	}

	/**
	 * Fields or rules of an order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function orders(): array {
		return array_merge(
			array(
				self::rule( 'order-reference', 'reference', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'order-date', 'date', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
				self::rule( 'order-paid', 'paid_at', 'order.paid_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
				self::rule( 'order-note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
				self::rule( 'order-total', 'total', 'order.total', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
				self::rule( 'order-payment', 'payment.reference', 'order.payment.transaction_id', array( self::transform( 'trim' ) ) ),
			),
			self::contact( true ),
			array(
				self::rule( 'line-sku', 'lines[].sku', 'lines[].sku', array( self::transform( 'trim' ) ) ),
				self::rule( 'line-name', 'lines[].name', 'lines[].name', array( self::transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
				self::rule( 'line-quantity', 'lines[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-unit-price', 'lines[].unit_price', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-total', 'lines[].total', 'lines[].line_total' ),
				self::rule( 'line-tax', 'lines[].tax', 'lines[].line_tax' ),
				self::rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				self::rule( 'fee-name', 'fees[].name', 'fees[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'fee-total', 'fees[].total', 'fees[].total' ),
				self::rule( 'discount-total', 'discount.total', 'order.discount_total' ),
				self::rule( 'discount-codes', 'discount.codes', 'coupons[].code', array( self::transform( 'join', array( 'separator' => ', ' ) ) ) ),
			)
		);
	}

	/**
	 * Rules for the buyer.
	 *
	 * @param bool $primary Whether the email is required.
	 * @return array<int,array<string,mixed>>
	 */
	private static function contact( bool $primary ): array {
		return array(
			self::rule( 'contact-email', 'contact.email', 'billing.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ), $primary, 'SYNC-MAP-003' ),
			self::rule( 'contact-first-name', 'contact.first_name', 'billing.first_name', array( self::transform( 'trim' ) ) ),
			self::rule( 'contact-last-name', 'contact.last_name', 'billing.last_name', array( self::transform( 'trim' ) ) ),
			self::rule( 'contact-company', 'contact.company', 'billing.company', array( self::transform( 'trim' ) ) ),
			self::rule( 'contact-phone', 'contact.phone', 'billing.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
			self::rule( 'contact-street', 'contact.street', array( 'billing.address_1', 'billing.address_2' ), array( self::transform( 'join', array( 'separator' => ', ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'contact-city', 'contact.city', 'billing.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'contact-state', 'contact.state', 'billing.state', array( self::transform( 'iso_state', array( 'country' => 'billing.country' ) ) ) ),
			self::rule( 'contact-postcode', 'contact.postcode', 'billing.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'contact-country', 'contact.country', 'billing.country', array( self::transform( 'iso_country' ) ) ),
		);
	}

	/**
	 * Fields or rules of a refund.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function refunds(): array {
		return array(
			self::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( self::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-amount', 'amount', 'refund.amount', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-currency', 'currency', 'order.currency' ),
			self::rule( 'refund-date', 'date', 'refund.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
			self::rule( 'refund-reason', 'reason', 'refund.reason', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * Build one mapping rule.
	 *
	 * @param string              $id         Rule id.
	 * @param string              $target     Target path.
	 * @param string|array<mixed> $source     Source path or paths.
	 * @param array<int,mixed>    $transforms Transforms.
	 * @param bool                $required   Required.
	 * @param string|null         $errorCode  Error code when required and empty.
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
	 * Build one transform.
	 *
	 * @param string              $type Transform type.
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
