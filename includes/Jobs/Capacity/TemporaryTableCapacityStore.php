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
		$this->table = $this->wpdb->prefix . 'estasoft_sync_cap_' . bin2hex( random_bytes( 5 ) );
		$this->wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$created = $this->wpdb->query( "CREATE TEMPORARY TABLE `{$this->table}` ( id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, state VARCHAR(10) NOT NULL, next_at INT NOT NULL, claim CHAR(16) NULL, hash CHAR(64) NOT NULL, KEY state_next (state, next_at) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$this->wpdb->suppress_errors( false );
		if ( false === $created ) {
			$this->table = '';
			throw new \RuntimeException( 'No temporary table.' );
		}
		for ( $start = 1; $start <= $items; $start += self::BATCH ) {
			$marks = array();
			$args  = array();
			for ( $id = $start; $id < $start + self::BATCH && $id <= $items; ++$id ) {
				$marks[] = '(%s, %d, %s)';
				array_push( $args, 'queued', 0, hash( 'sha256', 'capacity-' . $id ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( $this->wpdb->prepare( "INSERT INTO `{$this->table}` (state, next_at, hash) VALUES " . implode( ',', $marks ), $args ) );
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
		$token = bin2hex( random_bytes( 8 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$this->table}` SET state = 'syncing', claim = %s WHERE state = 'queued' AND next_at <= %d ORDER BY id LIMIT %d", $token, $dueAt, $limit ) );
		$ids = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT id FROM `{$this->table}` WHERE claim = %s AND state = 'syncing'", $token ) );
		// phpcs:enable
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
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$this->table}` SET state = 'synced', claim = NULL WHERE id IN ($marks)", array_map( 'intval', $ids ) ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$this->table}` SET state = 'queued', next_at = %d, claim = NULL WHERE id = %d", $nextAt, $id ) );
	}

	/**
	 * Items not finished.
	 */
	public function remaining(): int {
		if ( '' === $this->table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}` WHERE state <> 'synced'" );
	}

	/**
	 * Drop the table.
	 */
	public function drop(): void {
		if ( '' === $this->table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `{$this->table}`" );
		$this->table = '';
	}

	/**
	 * A table.
	 */
	public function usesDatabase(): bool {
		return true;
	}
}
