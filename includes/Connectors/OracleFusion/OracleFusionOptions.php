<?php
/**
 * The settings of an Oracle Fusion connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed reads of the connection settings and options, with the defaults the documentation file gives.
 */
final class OracleFusionOptions {

	/**
	 * Set up with what it depends on.
	 *
	 * @param Options $options Raw options.
	 */
	private function __construct( private readonly Options $options ) {
	}

	/**
	 * Options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function from( Connection $connection ): self {
		return new self( Options::from( $connection ) );
	}

	/**
	 * `oauth` (default) or `basic`.
	 */
	public function authMethod(): string {
		return $this->options->choice( 'auth_method', array( 'oauth', 'basic' ), 'oauth' );
	}

	/**
	 * The business unit (a name) that owns the invoices.
	 */
	public function businessUnit(): string {
		return $this->options->text( 'business_unit' );
	}

	/**
	 * The transaction source (a name).
	 */
	public function transactionSource(): string {
		return $this->options->text( 'transaction_source' );
	}

	/**
	 * The transaction type of an invoice (a name).
	 */
	public function transactionType(): string {
		return $this->options->text( 'transaction_type' );
	}

	/**
	 * The payment terms (a name); empty leaves them to the customer's profile.
	 */
	public function paymentTerms(): string {
		return $this->options->text( 'payment_terms' );
	}

	/**
	 * The customer account number all web orders are billed to.
	 */
	public function billToAccount(): string {
		return $this->options->text( 'bill_to_account' );
	}

	/**
	 * The transaction type of a refund (an on-account credit memo type).
	 */
	public function creditMemoType(): string {
		return $this->options->text( 'credit_memo_type', 'Credit Memo' );
	}

	/**
	 * A memo line name put on every invoice line, or empty.
	 */
	public function memoLine(): string {
		return $this->options->text( 'memo_line' );
	}

	/**
	 * `fail` (default): an item number Fusion does not know stops the order. `free_text`: no check, lines are descriptive.
	 */
	public function unknownSku(): string {
		return $this->options->choice( 'unknown_sku', array( 'fail', 'free_text' ), 'fail' );
	}

	/**
	 * `fail` (default) or `warn`.
	 */
	public function totalMismatch(): string {
		return $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * Which attribute carries the shop reference: `purchase_order` (50 characters, default) or `cross_reference` (30).
	 */
	public function referenceField(): string {
		return $this->options->choice( 'reference_field', array( 'purchase_order', 'cross_reference' ), 'purchase_order' );
	}

	/**
	 * The attribute name for the reference.
	 */
	public function referenceAttribute(): string {
		return 'cross_reference' === $this->referenceField() ? 'CrossReference' : 'PurchaseOrder';
	}

	/**
	 * The length of the reference attribute.
	 */
	public function referenceLength(): int {
		return 'cross_reference' === $this->referenceField() ? 30 : 50;
	}

	/**
	 * The REST framework version header (2 or higher; the `q` syntax of this connector is the one of version 2 and later).
	 */
	public function frameworkVersion(): int {
		return $this->options->integer( 'rest_framework_version', 2, 9, 2 );
	}

	/**
	 * The identity domain address cut to scheme and host, or an empty string when it is not an https address.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function identityBase( Connection $connection ): string {
		$parts = wp_parse_url( trim( (string) $connection->config( 'identity_url', '' ) ) );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || '' === $host || 1 !== preg_match( '/^[a-z0-9.\-]+$/', $host ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) && (int) $parts['port'] > 0 && (int) $parts['port'] < 65536 ? ':' . (int) $parts['port'] : '';
		return 'https://' . $host . $port;
	}
}
