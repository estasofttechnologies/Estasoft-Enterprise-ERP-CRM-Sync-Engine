<?php
/**
 * Connection options of the NetSuite connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed reads of `config.options` (see docs/connectors/netsuite.md, section 5).
 */
final class NetSuiteOptions {

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
	 * The item field the SKU is matched against: `itemid` (default) or the id of a custom item field such as custitem_sku.
	 */
	public function skuField(): string {
		$field = strtolower( $this->options->text( 'sku_field', 'itemid' ) );
		return 1 === preg_match( '/^(itemid|upccode|mpn|displayname|custitem[a-z0-9_]{1,40})$/', $field ) ? $field : 'itemid';
	}

	/**
	 * Whether billing and delivery addresses are put on the sales order.
	 */
	public function sendAddresses(): bool {
		return $this->options->flag( 'send_addresses', true );
	}

	/**
	 * Internal id of the item for shipping lines; empty means the order's shipping cost field.
	 */
	public function shippingItem(): string {
		return $this->options->text( 'shipping_item' );
	}

	/**
	 * Internal id of the item for fee lines.
	 */
	public function feeItem(): string {
		return $this->options->text( 'fee_item' );
	}

	/**
	 * Internal id of the (discount) item for discount lines.
	 */
	public function discountItem(): string {
		return $this->options->text( 'discount_item' );
	}

	/**
	 * Internal id of the subsidiary new customers belong to (OneWorld accounts); empty on other accounts.
	 */
	public function subsidiary(): string {
		return $this->options->text( 'subsidiary' );
	}

	/**
	 * Internal id of the item for refunds that name no product.
	 */
	public function refundItem(): string {
		return $this->options->text( 'refund_item' );
	}
}
