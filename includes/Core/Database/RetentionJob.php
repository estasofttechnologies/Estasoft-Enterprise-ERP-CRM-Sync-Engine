<?php
/**
 * The daily clean-up.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\LogPruner;
use Estasoft\SyncEngine\Core\Repositories\IdempotencyRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Repositories\ReconRepository;

/**
 * Applies the retention times of spec section 5.2: queue items (synced 30 days, skipped 90, failed until resolved plus 90),
 * log entries (7, 14 or 30 days), idempotency keys and reconciliation history (90 days). Also CON-9: the waiting items of
 * a connection that was disconnected are deleted 30 days after the disconnect; mapping versions and the id map stay.
 */
final class RetentionJob {

	/**
	 * Set up the retention job.
	 *
	 * @param QueueRepository       $queue       Queue.
	 * @param IdempotencyRepository $idempotency Idempotency ledger.
	 * @param ReconRepository       $recon       Reconciliation history.
	 * @param LogPruner             $logs        Log pruner.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly IdempotencyRepository $idempotency,
		private readonly ReconRepository $recon,
		private readonly LogPruner $logs
	) {
	}

	/**
	 * Delete what is past its retention time.
	 *
	 * @return array{queue:int,disconnected:int,logs:int,idempotency:int,recon:int} Rows deleted per table (`disconnected`: waiting items of disconnected connections, from the queue table).
	 */
	public function run(): array {
		return array(
			'queue'        => $this->queue->prune(),
			'disconnected' => $this->queue->pruneDisconnected(),
			'logs'         => $this->logs->run(),
			'idempotency'  => $this->idempotency->prune(),
			'recon'        => $this->recon->prune(),
		);
	}
}
