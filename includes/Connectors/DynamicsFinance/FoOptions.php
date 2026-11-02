<?php
/**
 * The options of a Finance and Supply Chain connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed view of the connection's options. A value that is not allowed falls back to the default.
 */
final class FoOptions {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param Options $raw       Raw options.
	 * @param string  $legalEntity The legal entity (dataAreaId), lower case.
	 */
	private function __construct( private readonly Options $raw, private readonly string $legalEntity ) {
	}

	/**
	 * Read the options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		$entity = strtolower( trim( (string) $connection->config( 'legal_entity', '' ) ) );
		return new self( Options::fromConnection( $connection ), $entity );
	}

	/**
	 * The legal entity (dataAreaId) every call is pinned to, or an empty string when the setting is not valid.
	 */
	public function legalEntity(): string {
		return 1 === preg_match( '/^[a-z0-9_]{1,4}$/', $this->legalEntity ) ? $this->legalEntity : '';
	}

	/**
	 * How the customer is chosen: match_or_create, match_only or fixed.
	 */
	public function customerPolicy(): string {
		$policy = $this->raw->choice( 'customer_policy', array( 'match_or_create', 'match_only', 'fixed' ), 'match_or_create' );
		return 'fixed' === $policy && '' === $this->fixedCustomer() ? 'match_or_create' : $policy;
	}

	/**
	 * Customer account used for every order when the policy is fixed.
	 */
	public function fixedCustomer(): string {
		return $this->raw->text( 'fixed_customer' );
	}

	/**
	 * CustomerGroupId given to customers the connector creates.
	 */
	public function customerGroup(): string {
		return $this->raw->text( 'customer_group' );
	}

	/**
	 * Item number of the service item used for shipping or fees.
	 *
	 * @param string $kind shipping or fee.
	 */
	public function lineItem( string $kind ): string {
		return $this->raw->text( $kind . '_item' );
	}

	/**
	 * Default shipping warehouse, or empty.
	 */
	public function warehouse(): string {
		return $this->raw->text( 'shipping_warehouse' );
	}

	/**
	 * Default shipping site, or empty.
	 */
	public function site(): string {
		return $this->raw->text( 'shipping_site' );
	}

	/**
	 * The requested shipping date: order (the shop's order date) or today.
	 */
	public function shippingDate(): string {
		return $this->raw->choice( 'shipping_date', array( 'order', 'today' ), 'order' );
	}

	/**
	 * How a new sales order gets its number: automatic (the number sequence of the legal entity assigns it, the connector sends none) or
	 * shop_reference (the connector sends the shop reference `WC-123` as the number, for a legal entity whose sales order sequence is manual).
	 */
	public function orderNumbering(): string {
		return $this->raw->choice( 'order_numbering', array( 'automatic', 'shop_reference' ), 'automatic' );
	}

	/**
	 * What a total that differs, or one that F&O does not give, does: fail or warn.
	 */
	public function totalMismatch(): string {
		return $this->raw->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}
}
