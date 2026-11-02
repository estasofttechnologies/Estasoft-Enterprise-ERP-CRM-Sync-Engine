<?php
/**
 * Steps that must run before dbDelta when a site upgrades.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;

/**
 * dbDelta adds tables, columns and new indexes, but it cannot drop an index, cannot change an index that keeps its
 * name, cannot add a unique index over duplicate rows, and fails when a column shrinks below the length of stored
 * data. These steps prepare the existing rows and indexes so that dbDelta then succeeds. Every step first checks
 * whether it is needed, so running them again changes nothing, and a fresh install (no tables yet) skips all of them.
 */
final class Upgrades {

	/**
	 * Longest stored log message and queue error message, in characters (spec 5.2 and 5.3).
	 */
	public const MESSAGE_LIMIT = 500;

	/**
	 * @param \wpdb  $wpdb   Database.
	 * @param Tables $tables Table names.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables
	) {
	}

	/**
	 * Run every step that applies.
	 */
	public function run(): void {
		if ( $this->tableExists( $this->tables->logs() ) ) {
			$this->shortenColumn( $this->tables->logs(), 'message' );
		}
		if ( $this->tableExists( $this->tables->queue() ) ) {
			$this->shortenColumn( $this->tables->queue(), 'error_message' );
			$this->replaceQueueIndexes();
		}
		if ( $this->tableExists( $this->tables->connections() ) ) {
			$this->secretHintsToPresence();
		}
	}

	/**
	 * Cut stored text to the new column length, so the narrower column can be applied in strict mode.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name (a trusted constant).
	 */
	private function shortenColumn( string $table, string $column ): void {
		// Once the column has been narrowed there is nothing longer to cut, so a large table is not scanned again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$type = (string) $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), 1 );
		if ( sprintf( 'varchar(%d)', self::MESSAGE_LIMIT ) === strtolower( $type ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$table}` SET `{$column}` = LEFT(`{$column}`, %d) WHERE CHAR_LENGTH(`{$column}`) > %d", self::MESSAGE_LIMIT, self::MESSAGE_LIMIT ) );
	}

	/**
	 * Version 1.1.0 queue indexes: the claim index gains `priority`, the plain idempotency index becomes a unique index
	 * over (connection_id, idempotency_key). Old rows that repeat a key are removed first (the lowest id stays).
	 */
	private function replaceQueueIndexes(): void {
		$table = $this->tables->queue();

		$claim = $this->indexColumns( $table, 'claim' );
		if ( array() !== $claim && 3 !== count( $claim ) ) {
			$this->alter( $table, 'DROP INDEX `claim`' );
		}

		if ( array() === $this->indexColumns( $table, 'connection_key' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->wpdb->query( "DELETE q FROM `{$table}` q INNER JOIN `{$table}` k ON k.connection_id = q.connection_id AND k.idempotency_key = q.idempotency_key AND k.id < q.id" );
		}

		if ( array() !== $this->indexColumns( $table, 'idempotency' ) ) {
			$this->alter( $table, 'DROP INDEX `idempotency`' );
		}
	}

	/**
	 * Secrets are write-only: the connection row keeps only which secrets exist, never a character of them. Rows written
	 * by the first schema version stored the last four characters; rewrite them as presence flags.
	 */
	private function secretHintsToPresence(): void {
		$table = $this->tables->connections();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( "SELECT id, secret_hints_json FROM `{$table}` WHERE secret_hints_json IS NOT NULL AND secret_hints_json <> '' AND secret_hints_json <> '{}'", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$hints = Json::decode( (string) $row['secret_hints_json'] );
			$clean = array();
			foreach ( $hints as $name => $value ) {
				$clean[ (string) $name ] = true;
			}
			if ( $clean !== $hints ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->update( $table, array( 'secret_hints_json' => Json::encode( $clean ) ), array( 'id' => (int) $row['id'] ) );
			}
		}
	}

	/**
	 * Columns of an index, in order; empty when the index does not exist.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @return array<int,string>
	 */
	private function indexColumns( string $table, string $index ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $this->wpdb->get_results( $this->wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ), ARRAY_A );
		usort( $rows, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_map( static fn( array $row ): string => (string) $row['Column_name'], $rows );
	}

	/**
	 * Run an ALTER TABLE.
	 *
	 * @param string $table     Table name.
	 * @param string $statement The part after the table name (a trusted constant).
	 */
	private function alter( string $table, string $statement ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query( "ALTER TABLE `{$table}` {$statement}" );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private function tableExists( string $table ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) );
	}
}
