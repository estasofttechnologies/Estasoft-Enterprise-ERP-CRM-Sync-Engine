<?php
/**
 * Nightly reconciliation runs and the differences they found.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Reads and writes `estasoft_sync_recon_runs` and `estasoft_sync_recon_diffs` (`ReconRun` and `ReconDiff` in types.ts). Both shapes
 * carry `connection_id`, the system the run compared or the difference was found in, so a repair can be sent to the right one.
 */
final class ReconRepository extends Repository {

	/**
	 * Days runs and differences are kept.
	 */
	public const KEEP_DAYS = 90;

	/**
	 * Start a run.
	 *
	 * @param int $connectionId Connection.
	 * @param int $windowDays   Days compared.
	 * @return int Run id.
	 */
	public function startRun( int $connectionId, int $windowDays ): int {
		return $this->insert(
			$this->tables->reconRuns(),
			array(
				'connection_id' => $connectionId,
				'started_at'    => $this->now(),
				'window_days'   => max( 1, $windowDays ),
				'status'        => 'running',
			)
		);
	}

	/**
	 * Finish a run.
	 *
	 * @param int         $runId       Run id.
	 * @param int         $compared    Orders compared.
	 * @param int         $matched     Orders that matched.
	 * @param int         $differences Differences found.
	 * @param int         $repaired    Differences repaired automatically.
	 * @param string      $status      done or failed.
	 * @param string|null $errorCode   SYNC-... code when the run failed.
	 */
	public function finishRun( int $runId, int $compared, int $matched, int $differences, int $repaired, string $status = 'done', ?string $errorCode = null ): void {
		$this->updateById(
			$this->tables->reconRuns(),
			array(
				'finished_at' => $this->now(),
				'compared'    => $compared,
				'matched'     => $matched,
				'differences' => $differences,
				'repaired'    => $repaired,
				'status'      => 'failed' === $status ? 'failed' : 'done',
				'error_code'  => $errorCode,
			),
			$runId
		);
	}

	/**
	 * Record a difference.
	 *
	 * @param int    $runId       Run id.
	 * @param int    $connectionId Connection.
	 * @param int    $orderId     WooCommerce order id.
	 * @param string $orderLabel  Order number for display.
	 * @param string $type        missing_in_system, total_differs, refund_missing or status_differs.
	 * @param string $wcValue     What WooCommerce has.
	 * @param string $systemValue What the system has.
	 * @return int Difference id.
	 */
	public function addDiff( int $runId, int $connectionId, int $orderId, string $orderLabel, string $type, string $wcValue, string $systemValue ): int {
		return $this->insert(
			$this->tables->reconDiffs(),
			array(
				'run_id'        => $runId,
				'connection_id' => $connectionId,
				'order_id'      => $orderId,
				'order_label'   => mb_substr( $orderLabel, 0, 64 ),
				'diff_type'     => mb_substr( $type, 0, 24 ),
				'wc_value'      => mb_substr( $wcValue, 0, 255 ),
				'system_value'  => mb_substr( $systemValue, 0, 255 ),
				'status'        => 'open',
				'created_at'    => $this->now(),
			)
		);
	}

	/**
	 * A run.
	 *
	 * @param int $runId Run id.
	 * @return array<string,mixed>|null A `ReconRun`.
	 */
	public function findRun( int $runId ): ?array {
		$row = $this->row( "SELECT * FROM {$this->tables->reconRuns()} WHERE id = %d", array( $runId ) );
		return null === $row ? null : $this->runShape( $row );
	}

	/**
	 * The newest runs.
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>> `ReconRun` list, newest first.
	 */
	public function runs( int $limit = 30 ): array {
		$rows = $this->results( "SELECT * FROM {$this->tables->reconRuns()} ORDER BY id DESC LIMIT %d", array( max( 1, min( 200, $limit ) ) ) );
		return array_map( array( $this, 'runShape' ), $rows );
	}

	/**
	 * The newest run.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lastRun(): ?array {
		$runs = $this->runs( 1 );
		return $runs[0] ?? null;
	}

	/**
	 * Differences, newest first.
	 *
	 * @param string|null $status open, repaired, ignored, or null for all.
	 * @param int|null    $runId  Limit to one run.
	 * @param int         $limit  How many.
	 * @return array<int,array<string,mixed>> `ReconDiff` list.
	 */
	public function diffs( ?string $status = null, ?int $runId = null, int $limit = 200 ): array {
		$parts = array( '1=1' );
		$args  = array();
		if ( null !== $status ) {
			$parts[] = 'status = %s';
			$args[]  = $status;
		}
		if ( null !== $runId ) {
			$parts[] = 'run_id = %d';
			$args[]  = $runId;
		}
		$args[] = max( 1, min( 1000, $limit ) );
		$rows   = $this->results( "SELECT * FROM {$this->tables->reconDiffs()} WHERE " . implode( ' AND ', $parts ) . ' ORDER BY id DESC LIMIT %d', $args );
		return array_map( array( $this, 'diffShape' ), $rows );
	}

	/**
	 * One difference.
	 *
	 * @param int $id Difference id.
	 * @return array<string,mixed>|null
	 */
	public function diff( int $id ): ?array {
		$row = $this->row( "SELECT * FROM {$this->tables->reconDiffs()} WHERE id = %d", array( $id ) );
		return null === $row ? null : $this->diffShape( $row );
	}

	/**
	 * Mark a difference repaired.
	 *
	 * @param int    $id Difference id.
	 * @param string $by Who acted.
	 * @return bool Whether an open difference was changed.
	 */
	public function markRepaired( int $id, string $by ): bool {
		return $this->close( $id, 'repaired', null, $by );
	}

	/**
	 * Ignore a difference, with the reason.
	 *
	 * @param int    $id     Difference id.
	 * @param string $reason Why.
	 * @param string $by     Who acted.
	 * @return bool Whether an open difference was changed.
	 */
	public function ignore( int $id, string $reason, string $by ): bool {
		return $this->close( $id, 'ignored', $reason, $by );
	}

	/**
	 * Count open differences.
	 */
	public function openCount(): int {
		return (int) $this->value( "SELECT COUNT(*) FROM {$this->tables->reconDiffs()} WHERE status = %s", array( 'open' ) );
	}

	/**
	 * Delete runs and differences older than the retention time.
	 *
	 * @param int $days Keep this many days.
	 * @return int Rows deleted.
	 */
	public function prune( int $days = self::KEEP_DAYS ): int {
		$cutoff = Time::sql( $this->clock->now()->modify( sprintf( '-%d days', max( 1, $days ) ) ) );
		return $this->run( "DELETE FROM {$this->tables->reconDiffs()} WHERE created_at < %s", array( $cutoff ) )
			+ $this->run( "DELETE FROM {$this->tables->reconRuns()} WHERE started_at < %s", array( $cutoff ) );
	}

	/**
	 * Close an open difference.
	 *
	 * @param int         $id     Difference id.
	 * @param string      $status repaired or ignored.
	 * @param string|null $note   Reason.
	 * @param string      $by     Who acted.
	 */
	private function close( int $id, string $status, ?string $note, string $by ): bool {
		$args = array( $status );
		$text = $this->bind( $note, '%s', $args );
		array_push( $args, mb_substr( $by, 0, 100 ), $this->now(), $id, 'open' );
		return $this->run(
			"UPDATE {$this->tables->reconDiffs()} SET status = %s, note = $text, acted_by = %s, acted_at = %s WHERE id = %d AND status = %s",
			$args
		) > 0;
	}

	/**
	 * The `ReconRun` shape.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function runShape( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'connection_id' => (int) $row['connection_id'],
			'started_at'    => (string) Time::iso( (string) $row['started_at'] ),
			'finished_at'   => Time::iso( self::stringOrNull( $row['finished_at'] ) ),
			'window_days'   => (int) $row['window_days'],
			'compared'      => (int) $row['compared'],
			'matched'       => (int) $row['matched'],
			'differences'   => (int) $row['differences'],
			'repaired'      => (int) $row['repaired'],
			'status'        => (string) $row['status'],
		);
	}

	/**
	 * The `ReconDiff` shape.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function diffShape( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'run_id'        => (int) $row['run_id'],
			'connection_id' => (int) $row['connection_id'],
			'order_id'      => (int) $row['order_id'],
			'order_label'   => (string) $row['order_label'],
			'type'          => (string) $row['diff_type'],
			'wc_value'      => (string) $row['wc_value'],
			'system_value'  => (string) $row['system_value'],
			'status'        => (string) $row['status'],
			'note'          => self::stringOrNull( $row['note'] ),
			'acted_by'      => self::stringOrNull( $row['acted_by'] ),
			'acted_at'      => Time::iso( self::stringOrNull( $row['acted_at'] ) ),
		);
	}
}
