<?php
/**
 * Helpers for writing rule lists, and a neutral example set.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Connectors use rule() and transform() to write their default mappings without repeating array keys. neutralOrders() is a small
 * canonical-to-target example that does not belong to any connector; the engine's tests and the documentation use it.
 * The default rules of each system live in that system's connector.
 */
final class Presets {

	/**
	 * Build one rule.
	 *
	 * @param string                                                  $id         Rule id.
	 * @param string                                                  $target     Target path.
	 * @param string|array<int,string>|array{const:mixed}             $source     Source path, list of paths or {const: value}.
	 * @param array<int,array{type:string,args?:array<string,mixed>}> $transforms Steps.
	 * @param bool                                                    $required   Whether an empty result stops the order.
	 * @param string|null                                             $errorCode  Code to raise when it does.
	 * @param array{source:string,equals:string}|null                 $when       Condition.
	 * @return array<string,mixed>
	 */
	public static function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null, ?array $when = null ): array {
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
		if ( null !== $when ) {
			$rule['when'] = $when;
		}
		return $rule;
	}

	/**
	 * Build one transform step.
	 *
	 * @param string              $type Transform name.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{type:string,args:array<string,mixed>}
	 */
	public static function transform( string $type, array $args = array() ): array {
		return array(
			'type' => $type,
			'args' => $args,
		);
	}

	/**
	 * A neutral set of order rules: reference, customer, address, lines with tax codes, shipping and totals.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function neutralOrders(): array {
		return array(
			self::rule( 'ref', 'reference', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'email', 'customer.email', 'billing.email', array( self::transform( 'trim' ), self::transform( 'case', array( 'mode' => 'lower' ) ) ), true, 'SYNC-MAP-003' ),
			self::rule( 'name', 'customer.name', array( 'billing.first_name', 'billing.last_name' ), array( self::transform( 'join', array( 'separator' => ' ' ) ), self::transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'phone', 'customer.phone', 'billing.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
			self::rule( 'country', 'customer.country', 'billing.country', array( self::transform( 'iso_country' ) ) ),
			self::rule( 'state', 'customer.state', 'billing.state', array( self::transform( 'iso_state' ) ) ),
			self::rule( 'vat', 'customer.tax_number', 'order.buyer_tax_number', array( self::transform( 'trim' ) ) ),
			self::rule( 'placed', 'placed_at', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
			self::rule( 'currency', 'currency', 'order.currency', array( self::transform( 'case', array( 'mode' => 'upper' ) ) ) ),
			self::rule( 'payment', 'payment_term', 'order.payment.method_id', array( self::transform( 'value_map', array( 'map' => array( 'cod' => 'Pay on delivery' ) ) ) ) ),
			self::rule( 'note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
			self::rule( 'line_sku', 'lines[].sku', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
			self::rule( 'line_qty', 'lines[].quantity', 'lines[].quantity' ),
			self::rule( 'line_price', 'lines[].unit_price', 'lines[].unit_price' ),
			self::rule( 'line_tax', 'lines[].tax_code', 'lines[].tax_rate', array( self::transform( 'tax_lookup' ) ) ),
			self::rule( 'ship', 'shipping[].amount', 'shipping_lines[].total' ),
			self::rule( 'total', 'total', 'order.total' ),
		);
	}
}
