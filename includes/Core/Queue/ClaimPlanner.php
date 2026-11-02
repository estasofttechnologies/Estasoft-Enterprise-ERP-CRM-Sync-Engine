<?php
/**
 * Decides how many items to claim, and claims them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The limits on items sent at once are "count what is running, then claim what is left". Done by several workers at the
 * same moment, that read-then-write would let each of them see the same free slot and together go over the limit. The whole
 * step is therefore done while holding a MySQL named lock (`QueueRepository::withClaimLock()`), so the count and the claim
 * of one worker cannot interleave with those of another, on any server of a shared database.
 *
 * Limits: each connection has its own number of items at once (`concurrency`), and all connections together are limited by
 * the global setting. The requests-per-second limit is not applied here; the worker applies it per item start.
 */
class ClaimPlanner {

	/**
	 * Seconds to wait for the lock before giving up for this round.
	 */
	public const LOCK_WAIT = 2;

	/**
	 * Connections already read in this run.
	 *
	 * @var array<int,Connection|null>
	 */
	private array $cache = array();

	/**
	 * @param QueueRepository      $queue       Queue.
	 * @param ConnectionRepository $connections Connections.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections
	) {
	}

	/**
	 * Forget the connections read so far (a new run starts).
	 */
	public function reset(): void {
		$this->cache = array();
	}

	/**
	 * A connection without secrets, read once per run.
	 *
	 * @param int $id Connection id.
	 */
	public function connection( int $id ): ?Connection {
		if ( ! array_key_exists( $id, $this->cache ) ) {
			$this->cache[ $id ] = $this->connections->findWithoutSecrets( $id );
		}
		return $this->cache[ $id ];
	}

	/**
	 * Claim items for every active connection, within the per-connection and global limits.
	 *
	 * When another worker holds the claim lock it is busy filling the free slots, so nothing is claimed in this round and
	 * the caller treats it like an empty queue.
	 *
	 * @param int    $global      Items at once, all connections together.
	 * @param string $workerId    Id of this run; it is the lock token of the items.
	 * @param int    $lockSeconds How long a claim locks an item before the reaper may take it back.
	 * @return array<int,Item>
	 */
	public function claim( int $global, string $workerId, int $lockSeconds ): array {
		$batch = $this->guard(
			function () use ( $global, $workerId, $lockSeconds ): array {
				$slots = $global - $this->running( null );
				$batch = array();
				foreach ( $this->connections->activeIds() as $id ) {
					if ( $slots <= 0 ) {
						break;
					}
					$connection = $this->connection( $id );
					if ( null === $connection ) {
						continue;
					}
					$room = min( (int) ( $connection->settings['concurrency'] ?? 2 ), $slots ) - $this->running( $id );
					if ( $room <= 0 ) {
						continue;
					}
					$claimed = $this->queue->claim( $room, $lockSeconds, $workerId, $id );
					$slots  -= count( $claimed );
					$batch   = array_merge( $batch, $claimed );
				}
				return $batch;
			}
		);
		return is_array( $batch ) ? $batch : array();
	}

	/**
	 * Items being sent right now, for one connection or all.
	 *
	 * @param int|null $connectionId Connection, or null for all.
	 */
	protected function running( ?int $connectionId ): int {
		return $this->queue->countSyncing( $connectionId );
	}

	/**
	 * Run the count-and-claim step under the claim lock.
	 *
	 * @param \Closure $work The step.
	 * @return mixed What the step returned, or an empty list when the lock was busy.
	 */
	protected function guard( \Closure $work ): mixed {
		return $this->queue->withClaimLock( $work, self::LOCK_WAIT, array() );
	}
}
