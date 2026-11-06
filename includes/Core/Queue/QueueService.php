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
use Estasoft\SyncEngine\Domain\Item;

/**
 * Retry one, many or a whole group of failed items; skip with a reason; restore; sync now; and the detail of one item. Every
 * action is written to the log with the id of the person. Capability and nonce checks are the controller's job.
 *
 * After an item is changed by retry, skip, restore (or the bulk forms of them) the action
 * `estasoft_sync_item_state_changed( Item $item )` fires once for each changed item, with the item as it is after the change.
 * Listeners (the order meta mirror, for one) use it to follow the queue without polling.
 */
final class QueueService {

	/**
	 * Most ids in one bulk action.
	 */
	public const BULK_LIMIT = 500;

	/**
	 * Most batches one group retry works through (a safety stop; 500 items each).
	 */
	private const GROUP_MAX_ROUNDS = 2000;

	/**
	 * Set up the queue service.
	 *
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
		$this->changed( $id );
		$this->scheduler->runNow();
		return $this->queue->findRow( $id );
	}

	/**
	 * Retry several items.
	 *
	 * @param array<int,int> $ids Item ids (at most BULK_LIMIT are used).
	 * @return int How many were moved to the queue.
	 */
	public function retryMany( array $ids ): int {
		$count = $this->retryBatch( $this->limit( $ids ) );
		if ( $count > 0 ) {
			$this->scheduler->runNow();
		}
		return $count;
	}

	/**
	 * Retry a group on Needs attention: every failed item with an error code and, optionally, a cause. All of them, however
	 * many there are: the group is read and retried in batches until none is left.
	 *
	 * @param string      $errorCode SYNC-... code.
	 * @param string|null $cause     Cause text such as "SKU CS-LAMP-07"; null for every cause.
	 * @return int How many were moved to the queue.
	 */
	public function retryGroup( string $errorCode, ?string $cause = null ): int {
		$count   = 0;
		$afterId = 0;
		for ( $round = 0; $round < self::GROUP_MAX_ROUNDS; ++$round ) {
			$ids = $this->queue->failedIdsByGroup( $errorCode, $cause, self::BULK_LIMIT, $afterId );
			if ( array() === $ids ) {
				break;
			}
			$count  += $this->retryBatch( $ids );
			$afterId = max( $ids );
			if ( count( $ids ) < self::BULK_LIMIT ) {
				break;
			}
		}
		if ( $count > 0 ) {
			$this->scheduler->runNow();
		}
		return $count;
	}

	/**
	 * Retry a list of ids as they are, without a cap and without starting a run.
	 *
	 * @param array<int,int> $ids Item ids.
	 * @return int How many were moved to the queue.
	 */
	private function retryBatch( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( $this->queue->retry( $id ) ) {
				$this->audit( 'retry', $id );
				$this->changed( $id );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Skip an item with a reason. It leaves the flow, keeps the reason, and can be restored.
	 *
	 * @param int    $id     Item id.
	 * @param string $reason Why.
	 * @return array<string,mixed>|null The item after the change, or null when it cannot be skipped.
	 */
	public function skip( int $id, string $reason ): ?array {
		$reason = '' === trim( $reason ) ? __( 'Skipped by a person.', 'estasoft-enterprise-erpcrm-sync-engine' ) : trim( $reason );
		if ( ! $this->queue->skip( $id, $reason ) ) {
			return null;
		}
		$this->audit( 'skip', $id );
		$this->changed( $id );
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
		$this->changed( $id );
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
				$this->changed( $id );
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
	 * Tell listeners that an item was changed by a person.
	 *
	 * @param int $id Item id.
	 */
	private function changed( int $id ): void {
		$item = $this->queue->find( $id );
		if ( null === $item ) {
			return;
		}
		/**
		 * Fires after a person retried, skipped or restored an item (also in the bulk forms), once for each changed item.
		 * The worker's own results fire `estasoft_sync_item_synced`, `estasoft_sync_item_failed` and
		 * `estasoft_sync_shadow_processed` instead.
		 *
		 * @param Item $item The item as it is after the change.
		 */
		do_action( 'estasoft_sync_item_state_changed', $item );
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
				'retry'   => __( 'Retry requested by user #%d.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				/* translators: %d: user id. */
				'skip'    => __( 'Skipped by user #%d.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				/* translators: %d: user id. */
				'restore' => __( 'Restored by user #%d.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			);
			$message  = sprintf( $messages[ $what ], $user );
		} else {
			$messages = array(
				'retry'   => __( 'Retry requested.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'skip'    => __( 'Skipped.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'restore' => __( 'Restored.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
