<?php
/**
 * The options of a Dynamics 365 Sales connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Options;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed view of the connection's options. A value that is not allowed falls back to the default.
 */
final class DvOptions {

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
	 * How the contact is chosen: match_or_create or match_only.
	 */
	public function customerPolicy(): string {
		return $this->raw->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' );
	}

	/**
	 * Whether a buyer with a company name gets an account.
	 */
	public function createAccounts(): bool {
		return $this->raw->flag( 'create_accounts', true );
	}

	/**
	 * What to do with a SKU that is not a product: refuse or write_in (a free-text line).
	 */
	public function unknownSku(): string {
		return $this->raw->choice( 'unknown_sku', array( 'refuse', 'write_in' ), 'refuse' );
	}

	/**
	 * Logical name of a custom text column that holds WC-{id}, or an empty string.
	 */
	public function referenceColumn(): string {
		$column = strtolower( $this->raw->text( 'reference_column' ) );
		return 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $column ) ? $column : '';
	}

	/**
	 * Name of the price list to bind to the opportunity, or empty.
	 */
	public function priceList(): string {
		return $this->raw->text( 'price_list' );
	}

	/**
	 * What a total that differs does: fail or warn.
	 */
	public function totalMismatch(): string {
		return $this->raw->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}
}
