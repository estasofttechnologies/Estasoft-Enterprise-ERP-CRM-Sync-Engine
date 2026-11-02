<?php
/**
 * The settings of a Wafeq connection that are not secrets.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the advanced fields of the connect screen from the connection's config (directly, or under `options`) and gives each one a checked
 * value: a value outside its list falls back to the default, so a typo can never switch a feature on.
 */
final class WafeqOptions {

	/**
	 * UAE places of supply Wafeq accepts.
	 */
	public const PLACES = array( 'ABU_DHABI', 'AJMAN', 'DUBAI', 'FUJAIRAH', 'RAS_AL_KHAIMAH', 'SHARJAH', 'UMM_AL_QUWAIN', 'OUTSIDE_UAE' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,mixed> $values Raw option values by key.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		$values  = array();
		$nested  = $connection->config( 'options' );
		$nested  = is_array( $nested ) ? $nested : array();
		$allowed = array( 'sales_account', 'payment_account', 'record_payment', 'invoice_state', 'unknown_sku', 'invoice_number_prefix', 'place_of_supply', 'language', 'total_mismatch' );
		foreach ( $allowed as $key ) {
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
	 * The account (id, code or exact name) that invoice lines are booked to; empty means "the only revenue account".
	 */
	public function salesAccount(): string {
		return $this->text( 'sales_account' );
	}

	/**
	 * The account (id, code or exact name) that receives payments.
	 */
	public function paymentAccount(): string {
		return $this->text( 'payment_account' );
	}

	/**
	 * Whether paid orders also create a payment in Wafeq.
	 */
	public function recordPayment(): bool {
		return 'yes' === $this->choice( 'record_payment', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * Whether finished invoices are finalized (posted) or left as drafts.
	 */
	public function finalize(): bool {
		return 'finalized' === $this->choice( 'invoice_state', array( 'finalized', 'draft' ), 'finalized' );
	}

	/**
	 * Whether a SKU Wafeq does not know becomes a text line instead of stopping the order.
	 */
	public function freeTextForUnknownSku(): bool {
		return 'free_text' === $this->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' );
	}

	/**
	 * Text put before the order number to make the invoice number.
	 */
	public function numberPrefix(): string {
		$prefix = $this->text( 'invoice_number_prefix' );
		if ( '' === $prefix && ! array_key_exists( 'invoice_number_prefix', $this->values ) ) {
			return 'WC-';
		}
		return mb_substr( $prefix, 0, 12 );
	}

	/**
	 * The UAE place of supply, or an empty string.
	 */
	public function placeOfSupply(): string {
		$value = strtoupper( $this->text( 'place_of_supply' ) );
		return in_array( $value, self::PLACES, true ) ? $value : '';
	}

	/**
	 * Document language.
	 */
	public function language(): string {
		return $this->choice( 'language', array( 'en', 'ar' ), 'en' );
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
		$value = $this->values[ $key ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * A choice option.
	 *
	 * @param string            $key     Option key.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $fallback Default.
	 */
	private function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
