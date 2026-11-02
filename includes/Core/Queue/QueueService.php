<?php
/**
 * What a person can do with queue items. The REST controllers call this class.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;

/**
 * Retry one, many or a whole group of failed items; skip with a reason; restore; sync now; and the detail of one item. Every
 * action is written to the log with the id of the person. Capability and nonce checks are the controller's job.
 */
final class QueueService {

	/**
	 * Most ids in one bulk action.
	 */
	public const BULK_LIMIT = 500;

	/**
	 * @param QueueRepository $queue     Queue.
	 * @param LogRepository   $logs      Log entries (for the item timeline).
	 * @param Logger          $logger    Log.
	 * @param Scheduler       $scheduler Background tasks ("Sync now").
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly LogRepository $logs,
		private readonly Logger $logger,
		private readonly Scheduler $scheduler
	) {
	}

	/**
	 * Retry one item now.
	 *
	 * @param int $id Item id.
	 * @return array<string,mixed>|null The item after the change, or null when it cannot be retried (only failed and retrying items can).
	 */
	public function retry( int $id ): ?array {
		if ( ! $this->queue->retry( $id ) ) {
			return null;
		}
		$this->audit( 'retry', $id );
		$this->scheduler->runNow();
		return $this->queue->findRow( $id );
	}

	/**
	 * Retry several items.
	 *
	 * @param array<int,int> $ids Item ids.
	 * @return int How many were moved to the queue.
	 */
	public function retryMany( array $ids ): int {
		$count = 0;
		foreach ( $this->limit( $ids ) as $id ) {
			if ( $this->queue->retry( $id ) ) {
				$this->audit( 'retry', $id );
				++$count;
			}
		}
		if ( $count > 0 ) {
			$this->scheduler->runNow();
		}
		return $count;
	}

	/**
	 * Retry a group on Needs attention: every failed item with an error code and, optionally, a cause.
	 *
	 * @param string      $errorCode SYNC-... code.
	 * @param string|null $cause     Cause text such as "SKU CS-LAMP-07"; null for every cause.
	 * @return int How many were moved to the queue.
	 */
	public function retryGroup( string $errorCode, ?string $cause = null ): int {
		return $this->retryMany( $this->queue->failedIdsByGroup( $errorCode, $cause ) );
	}

	/**
	 * Skip an item with a reason. It leaves the flow, keeps the reason, and can be restored.
	 *
	 * @param int    $id     Item id.
	 * @param string $reason Why.
	 * @return array<string,mixed>|null The item after the change, or null when it cannot be skipped.
	 */
	public function skip( int $id, string $reason ): ?array {
		$reason = '' === trim( $reason ) ? __( 'Skipped by a person.', 'estasoft-erp-crm-sync-engine' ) : trim( $reason );
		if ( ! $this->queue->skip( $id, $reason ) ) {
			return null;
		}
		$this->audit( 'skip', $id );
		return $this->queue->findRow( $id );
	}

	/**
	 * Skip several items with one reason.
	 *
	 * @param array<int,int> $ids    Item ids.
	 * @param string         $reason Why.
	 * @return int How many were skipped.
	 */
	public function skipMany( array $ids, string $reason ): int {
		$count = 0;
		foreach ( $this->limit( $ids ) as $id ) {
			if ( null !== $this->skip( $id, $reason ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Put a skipped item back in the queue.
	 *
	 * @param int $id Item id.
	 * @return array<string,mixed>|null The item after the change, or null when it was not skipped.
	 */
	public function restore( int $id ): ?array {
		if ( ! $this->queue->restore( $id ) ) {
			return null;
		}
		$this->audit( 'restore', $id );
		$this->scheduler->runNow();
		return $this->queue->findRow( $id );
	}

	/**
	 * Restore several items.
	 *
	 * @param array<int,int> $ids Item ids.
	 * @return int How many were restored.
	 */
	public function restoreMany( array $ids ): int {
		$count = 0;
		foreach ( $this->limit( $ids ) as $id ) {
			if ( $this->queue->restore( $id ) ) {
				$this->audit( 'restore', $id );
				++$count;
			}
		}
		if ( $count > 0 ) {
			$this->scheduler->runNow();
		}
		return $count;
	}

	/**
	 * A bulk action as `POST /queue/bulk` sends it.
	 *
	 * @param array<int,int> $ids    Item ids.
	 * @param string         $action skip, retry or restore.
	 * @param string|null    $reason Reason for skip.
	 * @return int|null How many changed; null for an unknown action.
	 */
	public function bulk( array $ids, string $action, ?string $reason = null ): ?int {
		return match ( $action ) {
			'skip'    => $this->skipMany( $ids, (string) $reason ),
			'retry'   => $this->retryMany( $ids ),
			'restore' => $this->restoreMany( $ids ),
			default   => null,
		};
	}

	/**
	 * Start a queue run as soon as possible.
	 *
	 * @return bool Whether a run was requested.
	 */
	public function syncNow(): bool {
		return $this->scheduler->runNow();
	}

	/**
	 * The `QueueItemDetail` of an item: the row, its timeline, the excerpts of the latest attempt, and its siblings.
	 *
	 * @param int $id Item id.
	 * @return array<string,mixed>|null
	 */
	public function detail( int $id ): ?array {
		$row = $this->queue->findRow( $id );
		if ( null === $row ) {
			return null;
		}
		$request  = null;
		$response = null;
		if ( null !== $row['log_id'] ) {
			$entry    = $this->logs->find( (int) $row['log_id'] );
			$request  = $entry['request_excerpt'] ?? null;
			$response = $entry['response_excerpt'] ?? null;
		}
		return array_merge(
			$row,
			array(
				'timeline'         => $this->logs->timeline( $id ),
				'request_excerpt'  => $request,
				'response_excerpt' => $response,
				'siblings'         => $this->queue->siblings( $id ),
			)
		);
	}

	/**
	 * Write who did what.
	 *
	 * @param string $what One of retry, skip, restore.
	 * @param int    $id   Item id.
	 */
	private function audit( string $what, int $id ): void {
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user > 0 ) {
			$messages = array(
				/* translators: %d: user id. */
				'retry'   => __( 'Retry requested by user #%d.', 'estasoft-erp-crm-sync-engine' ),
				/* translators: %d: user id. */
				'skip'    => __( 'Skipped by user #%d.', 'estasoft-erp-crm-sync-engine' ),
				/* translators: %d: user id. */
				'restore' => __( 'Restored by user #%d.', 'estasoft-erp-crm-sync-engine' ),
			);
			$message  = sprintf( $messages[ $what ], $user );
		} else {
			$messages = array(
				'retry'   => __( 'Retry requested.', 'estasoft-erp-crm-sync-engine' ),
				'skip'    => __( 'Skipped.', 'estasoft-erp-crm-sync-engine' ),
				'restore' => __( 'Restored.', 'estasoft-erp-crm-sync-engine' ),
			);
			$message  = $messages[ $what ];
		}
		$this->logger->info( $message, array( 'queue_id' => $id ) );
	}

	/**
	 * Clean a list of ids and cap its length.
	 *
	 * @param array<int,mixed> $ids Ids.
	 * @return array<int,int>
	 */
	private function limit( array $ids ): array {
		$clean = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		return array_slice( $clean, 0, self::BULK_LIMIT );
	}
}
