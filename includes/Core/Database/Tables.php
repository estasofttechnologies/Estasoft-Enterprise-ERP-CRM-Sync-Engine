<?php
/**
 * Names of the plugin's database tables.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The nine tables, each named from the site's table prefix plus a constant. Never built from user input.
 */
final class Tables {

	/**
	 * Common name prefix after the WordPress table prefix.
	 */
	private const NAME_PREFIX = 'estasoft_sync_';

	/**
	 * Logical names.
	 *
	 * @var array<int,string>
	 */
	private const LOGICAL = array( 'connections', 'queue', 'logs', 'mappings', 'id_map', 'state', 'idempotency', 'recon_runs', 'recon_diffs' );

	/**
	 * Set up the table name helper.
	 *
	 * @param string $prefix The WordPress table prefix of the site ($wpdb->prefix).
	 */
	public function __construct( private readonly string $prefix ) {
	}

	/**
	 * Connections.
	 */
	public function connections(): string {
		return $this->name( 'connections' );
	}

	/**
	 * Queue (the outbox and the queue are one table).
	 */
	public function queue(): string {
		return $this->name( 'queue' );
	}

	/**
	 * Log entries.
	 */
	public function logs(): string {
		return $this->name( 'logs' );
	}

	/**
	 * Mapping versions.
	 */
	public function mappings(): string {
		return $this->name( 'mappings' );
	}

	/**
	 * WooCommerce id to system id.
	 */
	public function idMap(): string {
		return $this->name( 'id_map' );
	}

	/**
	 * Last synced data hashes.
	 */
	public function state(): string {
		return $this->name( 'state' );
	}

	/**
	 * Idempotency keys.
	 */
	public function idempotency(): string {
		return $this->name( 'idempotency' );
	}

	/**
	 * Reconciliation runs.
	 */
	public function reconRuns(): string {
		return $this->name( 'recon_runs' );
	}

	/**
	 * Reconciliation differences.
	 */
	public function reconDiffs(): string {
		return $this->name( 'recon_diffs' );
	}

	/**
	 * All table names by logical name.
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		$tables = array();
		foreach ( self::LOGICAL as $logical ) {
			$tables[ $logical ] = $this->name( $logical );
		}
		return $tables;
	}

	/**
	 * Full name for a logical table.
	 *
	 * @param string $logical One of the constants in LOGICAL.
	 */
	private function name( string $logical ): string {
		return $this->prefix . self::NAME_PREFIX . $logical;
	}
}
