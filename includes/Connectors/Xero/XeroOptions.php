<?php
/**
 * Settings of a Xero connection that are not credentials.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Read from the connection's config: the array "options", or the same keys directly in the config. Every key has a safe default.
 * Keys: documents (invoice, invoice_approved, invoice_paid), sales_account, shipping_account, fee_account, discount_account, payment_account,
 * refund_state (draft, authorised, authorised_allocate), unknown_sku (refuse, free_text), due_days (0 to 365), no_tax_type, total_mismatch (fail, warn),
 * allow_backorders (yes or no). See docs/connectors/xero.md.
 */
final class XeroOptions {

	/**
	 * Keys that are read from the top level of the config.
	 *
	 * @var array<int,string>
	 */
	private const KEYS = array( 'documents', 'sales_account', 'shipping_account', 'fee_account', 'discount_account', 'payment_account', 'refund_state', 'unknown_sku', 'due_days', 'no_tax_type', 'total_mismatch', 'allow_backorders' );

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
	 * @param string $key      Key.
	 * @param string $fallback Value when absent.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $fallback;
	}

	/**
	 * A choice from a fixed list.
	 *
	 * @param string            $key      Key.
	 * @param array<int,string> $allowed  Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key, $fallback ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A yes or no option ("1", "yes", "true", "on" and true mean yes).
	 *
	 * @param string $key      Key.
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
	 * @param string $key      Key.
	 * @param int    $fallback Value when absent or not a number.
	 * @param int    $min      Smallest allowed.
	 * @param int    $max      Largest allowed.
	 */
	public function integer( string $key, int $fallback, int $min, int $max ): int {
		$value = $this->values[ $key ] ?? null;
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * The document steps asked for: invoice, invoice_approved or invoice_paid.
	 */
	public function documents(): string {
		return $this->choice( 'documents', array( 'invoice', 'invoice_approved', 'invoice_paid' ), 'invoice_approved' );
	}

	/**
	 * Whether the invoice is approved.
	 */
	public function approves(): bool {
		return 'invoice' !== $this->documents();
	}

	/**
	 * Whether the payment is recorded.
	 */
	public function pays(): bool {
		return 'invoice_paid' === $this->documents();
	}
}
