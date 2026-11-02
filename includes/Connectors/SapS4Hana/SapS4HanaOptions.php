<?php
/**
 * Connection options of the SAP S/4HANA connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed reads of the connection's config and `config.options` (see docs/connectors/sap-s4hana.md, section 5).
 */
final class SapS4HanaOptions {

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
	 * `shop` (default): each item carries the shop's price. `system`: S/4HANA prices the order.
	 */
	public function priceSource(): string {
		return $this->options->choice( 'price_source', array( 'shop', 'system' ), 'shop' );
	}

	/**
	 * Condition type of the price on an item (PR00 by default).
	 */
	public function priceCondition(): string {
		$type = strtoupper( $this->options->text( 'price_condition', 'PR00' ) );
		return 1 === preg_match( '/^[A-Z0-9]{1,4}$/', $type ) ? $type : 'PR00';
	}

	/**
	 * Sales order type (OR by default).
	 */
	public function salesOrderType(): string {
		$type = strtoupper( $this->options->text( 'sales_order_type', 'OR' ) );
		return 1 === preg_match( '/^[A-Z0-9]{1,4}$/', $type ) ? $type : 'OR';
	}

	/**
	 * Credit memo request type (CR by default).
	 */
	public function creditMemoType(): string {
		$type = strtoupper( $this->options->text( 'credit_memo_type', 'CR' ) );
		return 1 === preg_match( '/^[A-Z0-9]{1,4}$/', $type ) ? $type : 'CR';
	}

	/**
	 * `fail` (default): an order with shipping, fees or a discount is refused. `ignore`: they are left out.
	 */
	public function extraCharges(): string {
		return $this->options->choice( 'extra_charges', array( 'fail', 'ignore' ), 'fail' );
	}

	/**
	 * `fail` (default) or `warn`.
	 */
	public function totalMismatch(): string {
		return $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * Sales organization.
	 */
	public function salesOrganization(): string {
		return $this->options->text( 'sales_organization' );
	}

	/**
	 * Distribution channel.
	 */
	public function distributionChannel(): string {
		return $this->options->text( 'distribution_channel' );
	}

	/**
	 * Division (the OData property is OrganizationDivision).
	 */
	public function division(): string {
		return $this->options->text( 'division' );
	}

	/**
	 * The customer number all web orders are sold to.
	 */
	public function soldToParty(): string {
		return $this->options->text( 'sold_to_party' );
	}

	/**
	 * `oauth` (default) or `basic`.
	 */
	public function authMethod(): string {
		return $this->options->choice( 'auth_method', array( 'oauth', 'basic' ), 'oauth' );
	}
}
