<?php
/**
 * Version 1 mapping rules shared by the ERP connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The starting rules a merchant sees before editing anything. Each rule follows the rule model of docs/ARCHITECTURE.md: id, target
 * path (from FieldSets), source path or list of paths (the canonical order model), transforms from the fixed library, required, optional
 * error_code. Dates are the shop's day (`date_utc` with `output_timezone: store`), because the systems store plain dates.
 */
final class DefaultRules {

	/**
	 * Rules for the orders mapping.
	 *
	 * @param bool $buyer    Include the customer block.
	 * @param bool $delivery Include the delivery block.
	 * @param bool $extras   Include shipping, fee and discount.
	 * @return array<int,array<string,mixed>>
	 */
	public static function orders( bool $buyer = true, bool $delivery = true, bool $extras = true ): array {
		$rules = array(
			self::rule( 'order-client-ref', 'client_ref', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
			self::rule(
				'order-date',
				'date_order',
				'order.created_at',
				array(
					self::transform(
						'date_utc',
						array(
							'format'          => 'date',
							'output_timezone' => 'store',
						)
					),
				)
			),
			self::rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'order-note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
			self::rule( 'order-total', 'total', 'order.total' ),
			self::rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
		);
		if ( $buyer ) {
			$rules = array_merge( $rules, self::person( 'partner.', 'billing', true ) );
		}
		if ( $delivery ) {
			$rules = array_merge( $rules, self::delivery() );
		}
		$rules = array_merge(
			$rules,
			array(
				self::rule( 'line-product', 'order_line[].product', 'lines[].sku', array( self::transform( 'sku_match', array( 'output' => 'ref' ) ) ), true, 'SYNC-MAP-001' ),
				self::rule( 'line-quantity', 'order_line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-price', 'order_line[].price_unit', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-tax', 'order_line[].tax', 'lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
			)
		);
		if ( $extras ) {
			$rules = array_merge(
				$rules,
				array(
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
		$rules[] = self::rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( self::transform( 'trim' ) ) );
		$rules[] = self::rule(
			'payment-paid-at',
			'payment.paid_at',
			'order.paid_at',
			array(
				self::transform(
					'date_utc',
					array(
						'format'          => 'date',
						'output_timezone' => 'store',
					)
				),
			)
		);
		return $rules;
	}

	/**
	 * Rules for the refunds mapping.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function refunds(): array {
		return array(
			self::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( self::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			self::rule(
				'refund-date',
				'date',
				'refund.created_at',
				array(
					self::transform(
						'date_utc',
						array(
							'format'          => 'date',
							'output_timezone' => 'store',
						)
					),
				)
			),
			self::rule( 'refund-currency', 'currency', 'order.currency' ),
			self::rule( 'refund-reason', 'reason', 'refund.reason', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-amount', 'amount', 'refund.amount' ),
			self::rule( 'refund-shipping', 'shipping_amount', 'refund.shipping_amount' ),
			self::rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
			self::rule( 'refund-line-amount', 'refund_line[].amount', 'refund.lines[].amount' ),
		);
	}

	/**
	 * Rules for the customers mapping.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function customers(): array {
		return self::person( 'partner.', 'billing', true );
	}

	/**
	 * Rules for the product match.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function products(): array {
		return array(
			self::rule( 'product-reference', 'product.reference', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
		);
	}

	/**
	 * Rules for stock in: the system's quantity to the WooCommerce stock quantity.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function stock(): array {
		return array(
			self::rule( 'stock-quantity', 'product.stock_quantity', 'quantity' ),
		);
	}

	/**
	 * Rules for tracking in: the system's delivery to the WooCommerce order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function tracking(): array {
		return array(
			self::rule(
				'tracking-status',
				'order.status',
				'status',
				array(
					self::transform(
						'value_map',
						array(
							'map' => array(
								'done'      => 'completed',
								'delivered' => 'completed',
							),
						)
					),
				)
			),
			self::rule( 'tracking-carrier', 'order.tracking.carrier', 'carrier', array( self::transform( 'trim' ) ) ),
			self::rule( 'tracking-number', 'order.tracking.number', 'tracking', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * Rules for a person block.
	 *
	 * @param string $prefix  Target prefix.
	 * @param string $source  Source block: billing.
	 * @param bool   $primary Whether the email and name are required.
	 * @return array<int,array<string,mixed>>
	 */
	private static function person( string $prefix, string $source, bool $primary ): array {
		return array(
			self::rule( 'partner-email', $prefix . 'email', $source . '.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ), $primary, 'SYNC-MAP-003' ),
			self::rule( 'partner-name', $prefix . 'name', array( $source . '.first_name', $source . '.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ), $primary, 'SYNC-VAL-002' ),
			self::rule( 'partner-company', $prefix . 'company_name', $source . '.company', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-phone', $prefix . 'phone', $source . '.phone', array( self::transform( 'phone_e164', array( 'default_country' => $source . '.country' ) ) ) ),
			self::rule( 'partner-street', $prefix . 'street', array( $source . '.address_1', $source . '.address_2' ), array( self::transform( 'join', array( 'separator' => ', ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'partner-city', $prefix . 'city', $source . '.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-state', $prefix . 'state', $source . '.state', array( self::transform( 'iso_state', array( 'country' => $source . '.country' ) ) ) ),
			self::rule( 'partner-zip', $prefix . 'zip', $source . '.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'partner-country', $prefix . 'country', $source . '.country', array( self::transform( 'iso_country' ) ) ),
			self::rule( 'partner-vat', $prefix . 'vat', 'order.buyer_tax_number', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * Rules for the delivery address.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function delivery(): array {
		return array(
			self::rule( 'delivery-name', 'delivery.name', array( 'shipping.first_name', 'shipping.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'delivery-phone', 'delivery.phone', 'shipping.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'shipping.country' ) ) ) ),
			self::rule( 'delivery-street', 'delivery.street', array( 'shipping.address_1', 'shipping.address_2' ), array( self::transform( 'join', array( 'separator' => ', ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'delivery-city', 'delivery.city', 'shipping.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-state', 'delivery.state', 'shipping.state', array( self::transform( 'iso_state', array( 'country' => 'shipping.country' ) ) ) ),
			self::rule( 'delivery-zip', 'delivery.zip', 'shipping.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-country', 'delivery.country', 'shipping.country', array( self::transform( 'iso_country' ) ) ),
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
