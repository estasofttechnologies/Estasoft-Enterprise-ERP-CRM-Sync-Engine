<?php
/**
 * The settings of a Daftra connection that are not secrets.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the account address and the advanced fields of the connect screen from the connection's config (directly, or under `options`) and
 * gives each one a checked value: a value outside its list falls back to the default, so a typo can never switch a feature on.
 */
final class DaftraOptions {

	/**
	 * Domains the API answers on [documented examples use daftara.com; response examples also show daftra.com].
	 */
	public const DOMAINS = array( 'daftara.com', 'daftra.com' );

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
		foreach ( array( 'subdomain', 'domain', 'store_id', 'invoice_state', 'record_payment', 'payment_method', 'unknown_sku', 'link_credit_notes', 'total_mismatch' ) as $key ) {
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
	 * The account name: the part of the address before the domain, or an empty string when it is not a plain name.
	 */
	public function subdomain(): string {
		$value = strtolower( $this->text( 'subdomain' ) );
		return 1 === preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value ) ? $value : '';
	}

	/**
	 * The domain.
	 */
	public function domain(): string {
		return $this->choice( 'domain', self::DOMAINS, 'daftara.com' );
	}

	/**
	 * The warehouse (store) id for documents, or an empty string for the account default.
	 */
	public function storeId(): string {
		$value = $this->text( 'store_id' );
		return 1 === preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	/**
	 * Whether a checked invoice is issued (true) or left as a draft (false).
	 */
	public function issue(): bool {
		return 'issued' === $this->choice( 'invoice_state', array( 'issued', 'draft' ), 'issued' );
	}

	/**
	 * Whether paid orders also create a payment in Daftra.
	 */
	public function recordPayment(): bool {
		return 'yes' === $this->choice( 'record_payment', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * The payment method key (cash, bank, cheque or a gateway key).
	 */
	public function paymentMethod(): string {
		$value = $this->text( 'payment_method' );
		return 1 === preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $value ) ? $value : '';
	}

	/**
	 * Whether a SKU Daftra does not know becomes a text line instead of stopping the order.
	 */
	public function freeTextForUnknownSku(): bool {
		return 'free_text' === $this->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' );
	}

	/**
	 * Whether a credit note is linked to its invoice through `subscription_id`.
	 */
	public function linkCreditNotes(): bool {
		return 'yes' === $this->choice( 'link_credit_notes', array( 'no', 'yes' ), 'no' );
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
