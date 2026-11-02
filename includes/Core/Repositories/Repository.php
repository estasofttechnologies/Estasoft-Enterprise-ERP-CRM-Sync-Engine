<?php
/**
 * Base class of the repositories: the only place that talks to $wpdb.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Wraps $wpdb so every query goes through prepare() with its arguments, and so the PHPCS exceptions
 * for direct queries live in one file. Table names come from Tables (prefix plus a constant), never from input.
 */
abstract class Repository {

	/**
	 * Set when a query through this class failed; transactions read it to decide between commit and rollback.
	 */
	protected bool $queryFailed = false;

	/**
	 * Text of the last database error that set $queryFailed.
	 */
	protected string $lastError = '';

	/**
	 * @param \wpdb  $wpdb   Database.
	 * @param Tables $tables Table names.
	 * @param Clock  $clock  Time source.
	 */
	public function __construct(
		protected readonly \wpdb $wpdb,
		protected readonly Tables $tables,
		protected readonly Clock $clock
	) {
	}

	/**
	 * The current time as a MySQL datetime (UTC).
	 */
	protected function now(): string {
		return Time::sql( $this->clock->now() );
	}

	/**
	 * Prepare a statement. Without arguments the statement is returned unchanged.
	 *
	 * @param string            $sql  Statement with %s, %d and %f placeholders.
	 * @param array<int,mixed>  $args Arguments in order.
	 */
	protected function prepare( string $sql, array $args = array() ): string {
		if ( array() === $args ) {
			return $sql;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$prepared = $this->wpdb->prepare( $sql, $args );
		return is_string( $prepared ) ? $prepared : '';
	}

	/**
	 * Run a SELECT and return all rows.
	 *
	 * @param string           $sql  Statement.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<int,array<string,mixed>>
	 */
	protected function results( string $sql, array $args = array() ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $this->prepare( $sql, $args ), ARRAY_A );
		$this->noteError();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a SELECT and return the first row.
	 *
	 * @param string           $sql  Statement.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<string,mixed>|null
	 */
	protected function row( string $sql, array $args = array() ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $this->prepare( $sql, $args ), ARRAY_A );
		$this->noteError();
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Run a SELECT and return the first column of the first row.
	 *
	 * @param string           $sql  Statement.
	 * @param array<int,mixed> $args Arguments.
	 */
	protected function value( string $sql, array $args = array() ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$value = $this->wpdb->get_var( $this->prepare( $sql, $args ) );
		$this->noteError();
		return null === $value ? null : (string) $value;
	}

	/**
	 * Run a statement that changes data.
	 *
	 * @param string           $sql  Statement.
	 * @param array<int,mixed> $args Arguments.
	 * @return int Rows affected; 0 on failure.
	 */
	protected function run( string $sql, array $args = array() ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->wpdb->query( $this->prepare( $sql, $args ) );
		$this->noteError();
		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Insert a row; null values are written as NULL.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data  Column to value.
	 * @return int The new id, or 0 on failure.
	 */
	protected function insert( string $table, array $data ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $this->wpdb->insert( $table, $data );
		$this->noteError();
		return false === $ok ? 0 : (int) $this->wpdb->insert_id;
	}

	/**
	 * Update rows by id.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data  Column to value; null becomes NULL.
	 * @param int                 $id    Row id.
	 * @return bool Whether the statement ran.
	 */
	protected function updateById( string $table, array $data, int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = false !== $this->wpdb->update( $table, $data, array( 'id' => $id ) );
		$this->noteError();
		return $ok;
	}

	/**
	 * Remember a database error from the statement that just ran.
	 */
	private function noteError(): void {
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->queryFailed = true;
			$this->lastError   = (string) $this->wpdb->last_error;
		}
	}

	/**
	 * A list of placeholders for an IN() clause.
	 *
	 * @param array<int,mixed> $values Values.
	 * @param string           $format %d or %s.
	 */
	protected function placeholders( array $values, string $format = '%d' ): string {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), $format ) );
	}

	/**
	 * Placeholder for a value that may be null: the literal NULL (no argument) or the format with the value appended.
	 *
	 * @param mixed             $value  Value or null.
	 * @param string            $format %s or %d.
	 * @param array<int,mixed>  $args   Arguments collected so far (appended to).
	 */
	protected function bind( mixed $value, string $format, array &$args ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$args[] = $value;
		return $format;
	}

	/**
	 * Escape text for a LIKE pattern.
	 *
	 * @param string $text Raw text.
	 */
	protected function like( string $text ): string {
		return '%' . $this->wpdb->esc_like( $text ) . '%';
	}

	/**
	 * Cast a nullable column to int or null.
	 *
	 * @param mixed $value Raw column.
	 */
	protected static function intOrNull( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Cast a nullable column to string or null.
	 *
	 * @param mixed $value Raw column.
	 */
	protected static function stringOrNull( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
