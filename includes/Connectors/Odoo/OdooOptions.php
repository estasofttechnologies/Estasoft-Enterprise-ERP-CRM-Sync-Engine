<?php
/**
 * Settings of an Odoo connection that are not credentials.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Read from the connection's config: the array "options", or the same keys directly in the config. Every key has a safe
 * default, so a connection without any of them works.
 *
 * Keys: documents (order, order_invoice, order_invoice_payment), invoice_state (post or draft), refund_state (draft or post),
 * customer_policy (match_or_create, match_only, always_create), duplicate_customer (oldest, fail), missing_product (fail,
 * create), create_service_products (yes or no), call_interval_ms (0 to 10000), pricelist (name or id of the price list to use
 * for every order; its currency must be the order's), create_pricelist (yes or no: make a price list when Odoo has none in the
 * order's currency), total_mismatch (fail or warn: what to do when Odoo's total differs from the shop's by more than one
 * minor unit), and shipping_product, fee_product, discount_product, refund_product (the internal references of the service
 * products Odoo uses for those lines).
 */
final class OdooOptions {

	/**
	 * Keys that are read from the top level of the config.
	 *
	 * @var array<int,string>
	 */
	private const KEYS = array( 'documents', 'invoice_state', 'refund_state', 'customer_policy', 'duplicate_customer', 'missing_product', 'create_service_products', 'call_interval_ms', 'shipping_product', 'fee_product', 'discount_product', 'refund_product', 'pricelist', 'create_pricelist', 'total_mismatch' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,mixed> $values Option values.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * Read the options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		$values = array();
		foreach ( self::KEYS as $key ) {
			$value = $connection->config( $key );
			if ( is_scalar( $value ) ) {
				$values[ $key ] = $value;
			}
		}
		$options = $connection->config( 'options' );
		if ( is_array( $options ) ) {
			$values = $options + $values;
		}
		return new self( $values );
	}

	/**
	 * A text option.
	 *
	 * @param string $key     Key.
	 * @param string $fallback Value when absent.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $fallback;
	}

	/**
	 * A choice from a fixed list.
	 *
	 * @param string            $key     Key.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key, $fallback ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A yes or no option ("1", "yes", "true", "on" and true mean yes).
	 *
	 * @param string $key     Key.
	 * @param bool   $fallback Value when absent.
	 */
	public function flag( string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $this->values ) ) {
			return $fallback;
		}
		$value = $this->values[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) ( is_scalar( $value ) ? $value : '' ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * A whole number within limits.
	 *
	 * @param string $key     Key.
	 * @param int    $fallback Value when absent or not a number.
	 * @param int    $min     Smallest allowed.
	 * @param int    $max     Largest allowed.
	 */
	public function integer( string $key, int $fallback, int $min, int $max ): int {
		$value = $this->values[ $key ] ?? null;
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
