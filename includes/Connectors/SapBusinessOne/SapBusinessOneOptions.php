<?php
/**
 * Connection options of the SAP Business One connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed reads of `config.options` (see docs/connectors/sap-b1.md, section 5).
 */
final class SapBusinessOneOptions {

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
	 * `order` (default) or `order_invoice`.
	 */
	public function documents(): string {
		return $this->options->choice( 'documents', array( 'order', 'order_invoice' ), 'order' );
	}

	/**
	 * `match_or_create` (default) or `match_only`.
	 */
	public function customerPolicy(): string {
		return $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' );
	}

	/**
	 * `fail` (default) or `warn`.
	 */
	public function totalMismatch(): string {
		return $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * The field that carries the client reference on orders: NumAtCard (default) or a user field such as U_WCRef.
	 */
	public function referenceField(): string {
		$field = $this->options->text( 'reference_field', 'NumAtCard' );
		return 1 === preg_match( '/^U_[A-Za-z0-9_]{1,19}$/', $field ) ? $field : 'NumAtCard';
	}

	/**
	 * The tax field of document lines: VatGroup (default) or TaxCode.
	 */
	public function taxField(): string {
		return $this->options->choice( 'tax_field', array( 'vatgroup', 'taxcode' ), 'vatgroup' ) === 'taxcode' ? 'TaxCode' : 'VatGroup';
	}

	/**
	 * Warehouse code for order lines; empty uses the item's default warehouse.
	 */
	public function warehouse(): string {
		return $this->options->text( 'warehouse' );
	}

	/**
	 * Days between the order date and the due date.
	 */
	public function dueDays(): int {
		return $this->options->integer( 'due_days', 0, 365, 0 );
	}

	/**
	 * Item code for shipping lines.
	 */
	public function shippingItem(): string {
		return $this->options->text( 'shipping_item' );
	}

	/**
	 * Item code for fee lines.
	 */
	public function feeItem(): string {
		return $this->options->text( 'fee_item' );
	}

	/**
	 * Item code for discount lines (a negative price).
	 */
	public function discountItem(): string {
		return $this->options->text( 'discount_item' );
	}

	/**
	 * Item code for refunds that name no product.
	 */
	public function refundItem(): string {
		return $this->options->text( 'refund_item' );
	}

	/**
	 * Number series for new business partners; 0 makes the connector send a card code.
	 */
	public function partnerSeries(): int {
		return $this->options->integer( 'bp_series', 0, 999999, 0 );
	}

	/**
	 * `v2` (default, OData v4) or `v1` (OData v3).
	 */
	public function apiVersion(): string {
		return $this->options->choice( 'api_version', array( 'v1', 'v2' ), 'v2' );
	}
}
