<?php
/**
 * Log entries.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Reads and writes `estasoft_sync_logs`. Messages and excerpts arrive already redacted: the Logger
 * does that, this class only stores and reads.
 */
final class LogRepository extends Repository {

	/**
	 * Most rows in a CSV download.
	 */
	private const CSV_LIMIT = 20000;

	/**
	 * Rows deleted per statement when pruning.
	 */
	private const PRUNE_BATCH = 2000;

	/**
	 * Longest stored message, in characters (LOG-1).
	 */
	public const MESSAGE_LIMIT = 500;

	/**
	 * Add an entry.
	 *
	 * @param array<string,mixed> $entry level, message (required); connection_id, queue_id, entity_ref, code, http_status,
	 *                                   duration_ms, attempt, mapping_version, request_excerpt, response_excerpt (optional).
	 * @return int The new id, or 0 when the row could not be written.
	 */
	public function write( array $entry ): int {
		$level = isset( $entry['level'] ) && in_array( $entry['level'], LogQuery::LEVELS, true ) ? (string) $entry['level'] : 'info';
		return $this->insert(
			$this->tables->logs(),
			array(
				'ts'               => Time::sqlMs( $this->clock->now() ),
				'level'            => $level,
				'connection_id'    => isset( $entry['connection_id'] ) ? (int) $entry['connection_id'] : null,
				'queue_id'         => isset( $entry['queue_id'] ) ? (int) $entry['queue_id'] : null,
				'entity_ref'       => isset( $entry['entity_ref'] ) ? mb_substr( (string) $entry['entity_ref'], 0, 100 ) : null,
				'code'             => isset( $entry['code'] ) ? mb_substr( (string) $entry['code'], 0, 20 ) : null,
				'message'          => mb_substr( (string) ( $entry['message'] ?? '' ), 0, self::MESSAGE_LIMIT ),
				'http_status'      => isset( $entry['http_status'] ) ? (int) $entry['http_status'] : null,
				'duration_ms'      => isset( $entry['duration_ms'] ) ? (int) $entry['duration_ms'] : null,
				'attempt'          => isset( $entry['attempt'] ) ? (int) $entry['attempt'] : null,
				'mapping_version'  => isset( $entry['mapping_version'] ) ? (int) $entry['mapping_version'] : null,
				'request_excerpt'  => isset( $entry['request_excerpt'] ) ? (string) $entry['request_excerpt'] : null,
				'response_excerpt' => isset( $entry['response_excerpt'] ) ? (string) $entry['response_excerpt'] : null,
			)
		);
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param LogQuery $query Filters.
	 */
	public function query( LogQuery $query ): Paged {
		list( $where, $args ) = $this->where( $query );
		$total                = (int) $this->value( "SELECT COUNT(*) FROM {$this->tables->logs()} WHERE $where", $args );
		$offset               = ( $query->page - 1 ) * $query->perPage;
		$rows                 = $this->results(
			"SELECT * FROM {$this->tables->logs()} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( $query->perPage, $offset ) )
		);
		return new Paged( array_map( array( $this, 'entry' ), $rows ), $total, $query->page, $query->perPage );
	}

	/**
	 * One entry with its excerpts (`LogEntryDetail`).
	 *
	 * @param int $id Entry id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->row( "SELECT * FROM {$this->tables->logs()} WHERE id = %d", array( $id ) );
		if ( null === $row ) {
			return null;
		}
		return array_merge(
			$this->entry( $row ),
			array(
				'request_excerpt'  => self::stringOrNull( $row['request_excerpt'] ),
				'response_excerpt' => self::stringOrNull( $row['response_excerpt'] ),
				'attempt'          => self::intOrNull( $row['attempt'] ),
				'mapping_version'  => self::intOrNull( $row['mapping_version'] ),
			)
		);
	}

	/**
	 * The newest entries, for the dashboard.
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>>
	 */
	public function latest( int $limit = 10 ): array {
		$rows = $this->results( "SELECT * FROM {$this->tables->logs()} ORDER BY id DESC LIMIT %d", array( max( 1, min( 100, $limit ) ) ) );
		return array_map( array( $this, 'entry' ), $rows );
	}

	/**
	 * The story of one queue item, oldest first (`TimelineEvent[]`).
	 *
	 * @param int $queueId Queue item id.
	 * @return array<int,array{at:string,level:string,code:?string,message:string}>
	 */
	public function timeline( int $queueId ): array {
		$rows = $this->results( "SELECT ts, level, code, message FROM {$this->tables->logs()} WHERE queue_id = %d ORDER BY id ASC LIMIT 200", array( $queueId ) );
		return array_map(
			static fn( array $row ): array => array(
				'at'      => (string) Time::isoMs( (string) $row['ts'] ),
				'level'   => (string) $row['level'],
				'code'    => self::stringOrNull( $row['code'] ),
				'message' => (string) $row['message'],
			),
			$rows
		);
	}

	/**
	 * Delete entries older than a number of days.
	 *
	 * @param int $days Keep this many days.
	 * @return int Rows deleted.
	 */
	public function prune( int $days ): int {
		$cutoff = Time::sql( $this->clock->now()->modify( sprintf( '-%d days', max( 1, $days ) ) ) );
		$total  = 0;
		for ( $i = 0; $i < 200; ++$i ) {
			$deleted = $this->run( "DELETE FROM {$this->tables->logs()} WHERE ts < %s LIMIT %d", array( $cutoff, self::PRUNE_BATCH ) );
			$total  += $deleted;
			if ( $deleted < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Delete every entry (the Clear logs tool).
	 *
	 * @return int Rows deleted.
	 */
	public function clear(): int {
		return $this->run( "DELETE FROM {$this->tables->logs()} WHERE id > 0" );
	}

	/**
	 * A CSV of the entries that match, newest first, at most 20,000 rows. Cells that start with = + - or @ get a
	 * leading apostrophe so a spreadsheet does not run them as formulas.
	 *
	 * @param LogQuery $query Filters (paging is ignored).
	 */
	public function csv( LogQuery $query ): string {
		list( $where, $args ) = $this->where( $query );
		$rows                 = $this->results(
			"SELECT * FROM {$this->tables->logs()} WHERE $where ORDER BY id DESC LIMIT %d",
			array_merge( $args, array( self::CSV_LIMIT ) )
		);
		$lines                = array( self::csvLine( array( 'time', 'level', 'code', 'message', 'connection_id', 'queue_id', 'item', 'http_status', 'duration_ms' ) ) );
		foreach ( $rows as $row ) {
			$lines[] = self::csvLine(
				array(
					(string) Time::isoMs( (string) $row['ts'] ),
					(string) $row['level'],
					(string) ( $row['code'] ?? '' ),
					(string) $row['message'],
					(string) ( $row['connection_id'] ?? '' ),
					(string) ( $row['queue_id'] ?? '' ),
					(string) ( $row['entity_ref'] ?? '' ),
					(string) ( $row['http_status'] ?? '' ),
					(string) ( $row['duration_ms'] ?? '' ),
				)
			);
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * The `LogEntry` shape of a row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function entry( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'ts'            => (string) Time::isoMs( (string) $row['ts'] ),
			'level'         => (string) $row['level'],
			'connection_id' => self::intOrNull( $row['connection_id'] ),
			'queue_id'      => self::intOrNull( $row['queue_id'] ),
			'entity_ref'    => self::stringOrNull( $row['entity_ref'] ),
			'code'          => self::stringOrNull( $row['code'] ),
			'message'       => (string) $row['message'],
			'http_status'   => self::intOrNull( $row['http_status'] ),
			'duration_ms'   => self::intOrNull( $row['duration_ms'] ),
		);
	}

	/**
	 * WHERE clause and arguments for a query.
	 *
	 * @param LogQuery $query Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function where( LogQuery $query ): array {
		$parts = array( '1=1' );
		$args  = array();
		if ( null !== $query->level ) {
			$parts[] = 'level = %s';
			$args[]  = $query->level;
		}
		if ( null !== $query->connectionId ) {
			$parts[] = 'connection_id = %d';
			$args[]  = $query->connectionId;
		}
		if ( null !== $query->queueId ) {
			$parts[] = 'queue_id = %d';
			$args[]  = $query->queueId;
		}
		if ( null !== $query->code ) {
			$parts[] = 'code = %s';
			$args[]  = $query->code;
		}
		$seconds = $query->rangeSeconds();
		if ( null !== $seconds ) {
			$parts[] = 'ts >= %s';
			$args[]  = Time::sqlMs( $this->clock->now()->modify( sprintf( '-%d seconds', $seconds ) ) );
		}
		if ( null !== $query->search ) {
			$like    = $this->like( $query->search );
			$parts[] = '(message LIKE %s OR code LIKE %s OR entity_ref LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * One CSV line.
	 *
	 * @param array<int,string> $cells Cell values.
	 */
	private static function csvLine( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			if ( '' !== $cell && 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}
}
