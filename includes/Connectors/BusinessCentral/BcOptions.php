<?php
/**
 * The options of a Business Central connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed view of the connection's options. A value that is not allowed falls back to the default (see Microsoft\Options).
 */
final class BcOptions {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param Options $raw Raw options.
	 */
	private function __construct( private readonly Options $raw ) {
	}

	/**
	 * Read the options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		return new self( Options::fromConnection( $connection ) );
	}

	/**
	 * Which BC documents an order becomes: order, order_ship_invoice or invoice.
	 */
	public function documents(): string {
		return $this->raw->choice( 'documents', array( 'order', 'order_ship_invoice', 'invoice' ), 'order' );
	}

	/**
	 * How the customer is chosen: match_or_create, match_only or fixed.
	 */
	public function customerPolicy(): string {
		$policy = $this->raw->choice( 'customer_policy', array( 'match_or_create', 'match_only', 'fixed' ), 'match_or_create' );
		return 'fixed' === $policy && '' === $this->fixedCustomer() ? 'match_or_create' : $policy;
	}

	/**
	 * Customer number used for every order when the policy is fixed.
	 */
	public function fixedCustomer(): string {
		return $this->raw->text( 'fixed_customer' );
	}

	/**
	 * What to do with a SKU that is not an item: refuse or account (a line on a G/L account).
	 */
	public function unknownSku(): string {
		$policy = $this->raw->choice( 'unknown_sku', array( 'refuse', 'account' ), 'refuse' );
		return 'account' === $policy && '' === $this->raw->text( 'unknown_sku_account' ) ? 'refuse' : $policy;
	}

	/**
	 * G/L account for lines of unknown SKUs.
	 */
	public function unknownSkuAccount(): string {
		return $this->raw->text( 'unknown_sku_account' );
	}

	/**
	 * The item field the SKU is matched with: number or gtin.
	 */
	public function skuField(): string {
		return $this->raw->choice( 'sku_field', array( 'number', 'gtin' ), 'number' );
	}

	/**
	 * Posting date: order (the shop's order date) or today (Business Central's own date).
	 */
	public function postingDate(): string {
		return $this->raw->choice( 'posting_date', array( 'order', 'today' ), 'order' );
	}

	/**
	 * What a total that differs does: fail or warn.
	 */
	public function totalMismatch(): string {
		return $this->raw->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * Whether a credit memo is left as a draft or posted: draft or post.
	 */
	public function refundState(): string {
		return $this->raw->choice( 'refund_state', array( 'draft', 'post' ), 'draft' );
	}

	/**
	 * The target of a non-product line: array( 'Item'|'Account', number ), or null when none is configured.
	 *
	 * @param string $kind shipping, fee, discount or refund.
	 * @return array{0:string,1:string}|null
	 */
	public function lineTarget( string $kind ): ?array {
		$item = $this->raw->text( $kind . '_item' );
		if ( '' !== $item ) {
			return array( 'Item', $item );
		}
		$account = $this->raw->text( $kind . '_account' );
		return '' !== $account ? array( 'Account', $account ) : null;
	}

	/**
	 * The name of the option that would set a line target, for messages.
	 *
	 * @param string $kind shipping, fee, discount or refund.
	 */
	public function lineOptionName( string $kind ): string {
		return $kind . '_item';
	}
}
