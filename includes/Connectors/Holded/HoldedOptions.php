<?php
/**
 * The advanced settings of a Holded connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the advanced fields of the connect screen from the connection's config (directly, or under `options`) and gives each one a checked
 * value: a value outside its list falls back to the default, so a typo can never switch a feature on.
 */
final class HoldedOptions {

	/**
	 * Document types an order can become.
	 */
	public const ORDER_TYPES = array( 'invoice', 'salesorder' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,string> $values Raw option values by key.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		$values = array();
		$nested = $connection->config( 'options' );
		$nested = is_array( $nested ) ? $nested : array();
		foreach ( array( 'document_type', 'warehouse_id', 'sales_channel_id', 'payment_method_id', 'unknown_sku', 'total_mismatch' ) as $key ) {
			$value = $connection->config( $key );
			if ( null === $value && array_key_exists( $key, $nested ) ) {
				$value = $nested[ $key ];
			}
			if ( is_scalar( $value ) ) {
				$values[ $key ] = trim( (string) $value );
			}
		}
		return new self( $values );
	}

	/**
	 * The document an order becomes: invoice or salesorder.
	 */
	public function documentType(): string {
		return $this->choice( 'document_type', self::ORDER_TYPES, 'invoice' );
	}

	/**
	 * The warehouse id for documents, or an empty string for none.
	 */
	public function warehouseId(): string {
		return $this->identifier( 'warehouse_id' );
	}

	/**
	 * The sales channel id, or an empty string.
	 */
	public function salesChannelId(): string {
		return $this->identifier( 'sales_channel_id' );
	}

	/**
	 * The payment method id, or an empty string.
	 */
	public function paymentMethodId(): string {
		return $this->identifier( 'payment_method_id' );
	}

	/**
	 * Whether a SKU Holded does not know becomes a text line instead of stopping the order.
	 */
	public function freeTextForUnknownSku(): bool {
		return 'free_text' === $this->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' );
	}

	/**
	 * Whether a total that differs from the shop's stops the document (true) or only adds a warning.
	 */
	public function failOnMismatch(): bool {
		return 'fail' === $this->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * An identifier option: letters, digits, hyphens and underscores only.
	 *
	 * @param string $key Option key.
	 */
	private function identifier( string $key ): string {
		$value = trim( $this->values[ $key ] ?? '' );
		return 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $value ) ? $value : '';
	}

	/**
	 * A choice option.
	 *
	 * @param string            $key      Option key.
	 * @param array<int,string> $allowed  Allowed values.
	 * @param string            $fallback Value when the option is missing or not allowed.
	 */
	private function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( trim( $this->values[ $key ] ?? '' ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
