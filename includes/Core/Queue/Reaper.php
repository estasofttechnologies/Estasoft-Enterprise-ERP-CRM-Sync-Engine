<?php
/**
 * Takes back items whose worker died.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * An item claimed by a worker has a lock of 60 seconds. If the worker dies (timeout, crash, server restart) the lock
 * runs out and the item is put back in the queue without counting a try, marked SYNC-LOCK-001. Runs every minute.
 */
final class Reaper {

	/**
	 * @param QueueRepository $queue  Queue.
	 * @param Logger          $logger Log.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly Logger $logger
	) {
	}

	/**
	 * Take back every item whose lock has run out.
	 *
	 * @return int How many items were put back.
	 */
	public function run(): int {
		$ids = $this->queue->reapStaleLockIds();
		foreach ( $ids as $id ) {
			$this->logger->warning(
				ErrorCode::Lock001->template(),
				array(
					'queue_id' => $id,
					'code'     => ErrorCode::Lock001,
				)
			);
		}
		return count( $ids );
	}
}
