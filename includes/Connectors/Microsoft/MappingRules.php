<?php
/**
 * Starting mapping rules the three Microsoft connectors share.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * Version 1 rules in the rule model of docs/ARCHITECTURE.md: id, target path, source path or list of paths (the canonical order model),
 * transforms from the fixed library, required, optional error_code. The target paths are the ones of the connectors' field catalogs
 * (see FieldGroups); the three connectors take the same payload shape, so the rules are written once.
 */
final class MappingRules {

	/**
	 * Order header: date, currency, note, expected total, excluded part, payment reference.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function orderHeader(): array {
		return array(
			self::rule( 'order-date', 'order_date', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'date' ) ) ) ),
			self::rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'order-note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
			self::rule( 'order-total', 'total', 'order.total' ),
			self::rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
			self::rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * The buyer, from the billing block.
	 *
	 * @param string $prefix  Target prefix, `partner.`.
	 * @param bool   $split   True to map first and last name separately (a CRM), false to join them into `name`.
	 * @param string $country Output of iso_country: alpha2 or alpha3.
	 * @return array<int,array<string,mixed>>
	 */
	public static function customer( string $prefix = 'partner.', bool $split = false, string $country = 'alpha2' ): array {
		$rules = array(
			self::rule( 'partner-email', $prefix . 'email', 'billing.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ), true, 'SYNC-MAP-003' ),
		);
		if ( $split ) {
			$rules[] = self::rule( 'partner-first-name', $prefix . 'first_name', 'billing.first_name', array( self::transform( 'trim' ) ) );
			$rules[] = self::rule( 'partner-last-name', $prefix . 'last_name', 'billing.last_name', array( self::transform( 'trim' ) ), true, 'SYNC-VAL-002' );
		} else {
			$rules[] = self::rule( 'partner-name', $prefix . 'name', array( 'billing.first_name', 'billing.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ), true, 'SYNC-VAL-002' );
		}
		return array_merge(
			$rules,
			array(
				self::rule( 'partner-company', $prefix . 'company_name', 'billing.company', array( self::transform( 'trim' ) ) ),
				self::rule( 'partner-phone', $prefix . 'phone', 'billing.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
				self::rule( 'partner-street', $prefix . 'street', 'billing.address_1', array( self::transform( 'trim' ) ) ),
				self::rule( 'partner-street2', $prefix . 'street2', 'billing.address_2', array( self::transform( 'trim' ) ) ),
				self::rule( 'partner-city', $prefix . 'city', 'billing.city', array( self::transform( 'trim' ) ) ),
				self::rule( 'partner-state', $prefix . 'state_id', 'billing.state', array( self::transform( 'iso_state', array( 'country' => 'billing.country' ) ) ) ),
				self::rule( 'partner-zip', $prefix . 'zip', 'billing.postcode', array( self::transform( 'trim' ) ) ),
				self::rule( 'partner-country', $prefix . 'country_id', 'billing.country', array( self::transform( 'iso_country', array( 'output' => $country ) ) ) ),
				self::rule( 'partner-vat', $prefix . 'vat', 'order.buyer_tax_number', array( self::transform( 'trim' ) ) ),
			)
		);
	}

	/**
	 * The delivery address, from the shipping block.
	 *
	 * @param string $country Output of iso_country: alpha2 or alpha3.
	 * @return array<int,array<string,mixed>>
	 */
	public static function delivery( string $country = 'alpha2' ): array {
		return array(
			self::rule( 'delivery-name', 'delivery.name', array( 'shipping.first_name', 'shipping.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'delivery-phone', 'delivery.phone', 'shipping.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'shipping.country' ) ) ) ),
			self::rule( 'delivery-street', 'delivery.street', 'shipping.address_1', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-street2', 'delivery.street2', 'shipping.address_2', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-city', 'delivery.city', 'shipping.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-state', 'delivery.state_id', 'shipping.state', array( self::transform( 'iso_state', array( 'country' => 'shipping.country' ) ) ) ),
			self::rule( 'delivery-zip', 'delivery.zip', 'shipping.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'delivery-country', 'delivery.country_id', 'shipping.country', array( self::transform( 'iso_country', array( 'output' => $country ) ) ) ),
		);
	}

	/**
	 * Product lines, shipping, fees and the discount.
	 *
	 * @param bool $taxCodes  True when the system has tax codes (rates go through the tax mapping), false for a system that carries tax amounts.
	 * @param bool $taxLookup With tax codes: false leaves the code out of the starting rules, so the system uses the item's own tax group.
	 * @return array<int,array<string,mixed>>
	 */
	public static function lines( bool $taxCodes, bool $taxLookup = true ): array {
		$rules = array(
			self::rule( 'line-product', 'order_line[].product', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
			self::rule( 'line-name', 'order_line[].name', 'lines[].name', array( self::transform( 'trim' ) ) ),
			self::rule( 'line-quantity', 'order_line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'line-price', 'order_line[].price_unit', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
		);
		if ( $taxCodes && $taxLookup ) {
			$rules[] = self::rule( 'line-tax', 'order_line[].tax', 'lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' );
			$rules[] = self::rule( 'shipping-tax', 'shipping.tax', 'shipping_lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' );
		} elseif ( ! $taxCodes ) {
			$rules[] = self::rule( 'line-tax-amount', 'order_line[].tax_amount', 'lines[].line_tax' );
			$rules[] = self::rule( 'shipping-tax-amount', 'shipping.tax_amount', 'shipping_lines[].tax' );
			$rules[] = self::rule( 'fee-tax-amount', 'fee[].tax_amount', 'fees[].tax' );
		}
		return array_merge(
			$rules,
			array(
				self::rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				self::rule( 'shipping-name', 'shipping.name', array( 'const' => 'Shipping' ) ),
				self::rule( 'fee-name', 'fee[].name', 'fees[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'fee-total', 'fee[].total', 'fees[].total' ),
				self::rule( 'discount-total', 'discount.total', 'order.discount_total' ),
				self::rule( 'discount-name', 'discount.name', 'coupons[].code', array( self::transform( 'join', array( 'separator' => ', ' ) ) ) ),
			)
		);
	}

	/**
	 * Product matching: the WooCommerce SKU is the system's item number.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function products(): array {
		return array(
			self::rule( 'product-reference', 'product.reference', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
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
	public static function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
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
	public static function transform( string $type, array $args = array() ): array {
		$transform = array( 'type' => $type );
		if ( array() !== $args ) {
			$transform['args'] = $args;
		}
		return $transform;
	}
}
