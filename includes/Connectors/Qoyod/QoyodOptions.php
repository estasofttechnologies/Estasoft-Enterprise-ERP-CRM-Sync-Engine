<?php
/**
 * The settings of a Qoyod connection that are not secrets.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the advanced fields of the connect screen from the connection's config (directly, or under `options`) and gives each one a checked
 * value: a value outside its list falls back to the default, so a typo can never switch a feature on.
 */
final class QoyodOptions {

	/**
	 * Hosts the API answers on.
	 */
	public const ENDPOINTS = array( 'api.qoyod.com', 'www.qoyod.com' );

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
		foreach ( array( 'endpoint', 'inventory_id', 'currency', 'approval', 'record_payment', 'payment_account_id', 'line_tax_field', 'shipping_product_sku', 'fee_product_sku', 'total_mismatch' ) as $key ) {
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
	 * The host to call.
	 */
	public function endpoint(): string {
		return $this->choice( 'endpoint', self::ENDPOINTS, 'api.qoyod.com' );
	}

	/**
	 * The branch (inventory) id the documents are booked to; empty means "the only branch".
	 */
	public function inventoryId(): string {
		$value = $this->text( 'inventory_id' );
		return 1 === preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	/**
	 * The currency of the Qoyod account.
	 */
	public function currency(): string {
		$value = strtoupper( $this->text( 'currency' ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $value ) ? $value : 'SAR';
	}

	/**
	 * Whether a checked invoice is approved (true) or left as a draft (false).
	 */
	public function approve(): bool {
		return 'after_check' === $this->choice( 'approval', array( 'after_check', 'draft' ), 'after_check' );
	}

	/**
	 * Whether paid orders also create a payment in Qoyod.
	 */
	public function recordPayment(): bool {
		return 'yes' === $this->choice( 'record_payment', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * The account (id) that receives payments.
	 */
	public function paymentAccountId(): string {
		$value = $this->text( 'payment_account_id' );
		return 1 === preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	/**
	 * Which key carries the tax of a line: tax_id (the code) or tax_percent.
	 */
	public function taxField(): string {
		return $this->choice( 'line_tax_field', array( 'tax_id', 'tax_percent' ), 'tax_id' );
	}

	/**
	 * SKU of the service product that carries shipping, or an empty string.
	 */
	public function shippingSku(): string {
		return $this->text( 'shipping_product_sku' );
	}

	/**
	 * SKU of the service product that carries fees, or an empty string.
	 */
	public function feeSku(): string {
		return $this->text( 'fee_product_sku' );
	}

	/**
	 * Whether a total that differs from the shop's stops the invoice (true) or only adds a warning.
	 */
	public function failOnMismatch(): bool {
		return 'fail' === $this->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * A text option.
	 *
	 * @param string $key Option key.
	 */
	private function text( string $key ): string {
		return trim( $this->values[ $key ] ?? '' );
	}

	/**
	 * A choice option.
	 *
	 * @param string            $key      Option key.
	 * @param array<int,string> $allowed  Allowed values.
	 * @param string            $fallback Value when the option is missing or not allowed.
	 */
	private function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
