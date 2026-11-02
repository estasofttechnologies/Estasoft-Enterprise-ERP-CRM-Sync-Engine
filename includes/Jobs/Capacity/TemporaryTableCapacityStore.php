<?php
/**
 * The capacity test queue in a temporary table.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Capacity;

defined( 'ABSPATH' ) || exit;

/**
 * A MySQL TEMPORARY table belongs to the database connection that created it: no other request can see it, it never touches
 * the plugin's real tables, creating and dropping it does not commit anything, and the server removes it when the connection
 * ends even if this request dies. The name is random and starts with the table prefix; it never comes from a request.
 */
final class TemporaryTableCapacityStore implements CapacityStore {

	/**
	 * Rows inserted per statement.
	 */
	private const BATCH = 100;

	/**
	 * Table name, set by prepare().
	 *
	 * @var string
	 */
	private string $table = '';

	/**
	 * Set up with what it depends on.
	 *
	 * @param \wpdb $wpdb Database connection.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
	}

	/**
	 * Create the table and fill it.
	 *
	 * @param int $items How many.
	 *
	 * @throws \RuntimeException When the table cannot be created.
	 */
	public function prepare( int $items ): void {
		$wpdb        = $this->wpdb;
		$this->table = $wpdb->prefix . 'estasoft_sync_cap_' . bin2hex( random_bytes( 5 ) );
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$created = $wpdb->query( $wpdb->prepare( 'CREATE TEMPORARY TABLE %i ( id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, state VARCHAR(10) NOT NULL, next_at INT NOT NULL, claim CHAR(16) NULL, hash CHAR(64) NOT NULL, KEY state_next (state, next_at) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $this->table ) );
		$wpdb->suppress_errors( false );
		if ( false === $created ) {
			$this->table = '';
			throw new \RuntimeException( 'No temporary table.' );
		}
		for ( $start = 1; $start <= $items; $start += self::BATCH ) {
			$hashes = array();
			$args   = array();
			for ( $id = $start; $id < $start + self::BATCH && $id <= $items; ++$id ) {
				$hash     = hash( 'sha256', 'capacity-' . $id );
				$hashes[] = $hash;
				array_push( $args, 'queued', $hash );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a temporary table that only this request can see; the row placeholders are built from a count and every value is bound.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (state, next_at, hash) VALUES ' . implode( ',', array_fill( 0, count( $hashes ), '(%s, 0, %s)' ) ), array_merge( array( $this->table ), $args ) ) );
		}
	}

	/**
	 * Claim due items: select, then update only the rows still queued, then read back what was taken.
	 *
	 * @param int $limit Most items.
	 * @param int $dueAt Due time.
	 * @return array<int,int>
	 */
	public function claim( int $limit, int $dueAt ): array {
		if ( '' === $this->table ) {
			return array();
		}
		$wpdb  = $this->wpdb;
		$token = bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'syncing', claim = %s WHERE state = 'queued' AND next_at <= %d ORDER BY id LIMIT %d", $this->table, $token, $dueAt, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE claim = %s AND state = 'syncing'", $this->table, $token ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Finish items.
	 *
	 * @param array<int,int> $ids Item ids.
	 */
	public function finish( array $ids ): void {
		if ( '' === $this->table || array() === $ids ) {
			return;
		}
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'synced', claim = NULL WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $this->table ), array_map( 'intval', array_values( $ids ) ) ) ) );
	}

	/**
	 * Wait until a time.
	 *
	 * @param int $id     Item id.
	 * @param int $nextAt Time.
	 */
	public function retryLater( int $id, int $nextAt ): void {
		if ( '' === $this->table ) {
			return;
		}
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'queued', next_at = %d, claim = NULL WHERE id = %d", $this->table, $nextAt, $id ) );
	}

	/**
	 * Items not finished.
	 */
	public function remaining(): int {
		if ( '' === $this->table ) {
			return 0;
		}
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE state <> 'synced'", $this->table ) );
	}

	/**
	 * Drop the table.
	 */
	public function drop(): void {
		if ( '' === $this->table ) {
			return;
		}
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- a temporary table that only this request can see; there is nothing to cache.
		$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', $this->table ) );
		$this->table = '';
	}

	/**
	 * A table.
	 */
	public function usesDatabase(): bool {
		return true;
	}
}
