<?php
/**
 * Claims items and runs them through the pipeline.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Uuid;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One run: put expired pauses and locks right, probe connections whose circuit is open, then claim and process
 * items until nothing is left to claim or the work limit (at most 20 seconds) is reached. Each connection is limited to its
 * own number of items at once and requests per second; all connections together are limited by the global setting.
 * Several workers can run at once: claiming is atomic, the limits on items at once are checked and applied under one
 * named lock (see ClaimPlanner), and the rate limit is shared.
 *
 * The setting "requests per second" is applied here as a limit on how many items may START per second (one slot is taken
 * from the limiter before each item). It does not count the HTTP calls an item makes: one item can make several calls
 * (a lookup, a send, a read-back), and a connector that must keep to the system's limit paces its own calls.
 */
final class Worker {

	/**
	 * Longest a run works, in seconds.
	 */
	public const MAX_SECONDS = 20;

	/**
	 * How long a claim locks an item before the reaper may take it back, in seconds.
	 */
	public const LOCK_SECONDS = 60;

	/**
	 * Waits for a number of seconds.
	 *
	 * @var \Closure(float):void
	 */
	private \Closure $sleep;

	/**
	 * Counts what is running and claims what is left, under the claim lock.
	 */
	private ClaimPlanner $planner;

	/**
	 * @param QueueRepository      $queue       Queue.
	 * @param ConnectionRepository $connections Connections.
	 * @param ItemProcessor        $processor   Pipeline.
	 * @param RateLimiter          $limiter     Rate limit.
	 * @param Reaper               $reaper      Takes back dead locks.
	 * @param CircuitBreaker       $breaker     Probes open circuits.
	 * @param PauseController      $pause       Ends timed pauses.
	 * @param Settings             $settings    Settings (global concurrency).
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $sleep       Waits for a number of seconds; usleep by default.
	 * @param ClaimPlanner|null    $planner     Claims items; the standard one when null.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly ItemProcessor $processor,
		private readonly RateLimiter $limiter,
		private readonly Reaper $reaper,
		private readonly CircuitBreaker $breaker,
		private readonly PauseController $pause,
		private readonly Settings $settings,
		private readonly Clock $clock,
		?\Closure $sleep = null,
		?ClaimPlanner $planner = null
	) {
		$this->planner = $planner ?? new ClaimPlanner( $queue, $connections );
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	/**
	 * Do one run.
	 *
	 * @param int $maxSeconds  Work limit in seconds; never more than 20.
	 * @param int $concurrency Items claimed at a time; the lower of this and the global setting applies.
	 * @return WorkerReport What happened.
	 */
	public function run( int $maxSeconds = self::MAX_SECONDS, int $concurrency = 2 ): WorkerReport {
		$limit   = max( 1, min( self::MAX_SECONDS, $maxSeconds ) );
		$started = $this->clock->now();
		$worker  = Uuid::v4();
		$report  = new WorkerReport();
		$this->planner->reset();

		$this->pause->resumeDue();
		$this->breaker->probeDue();
		$this->reaper->run();

		$global = max( 1, min( $concurrency, $this->settings->globalConcurrency() ) );

		while ( true ) {
			if ( $this->elapsed( $started ) >= $limit ) {
				$report->stoppedBecause = 'time';
				break;
			}
			$batch = $this->planner->claim( $global, $worker, self::LOCK_SECONDS );
			if ( array() === $batch ) {
				$report->stoppedBecause = 'idle';
				break;
			}
			$report->claimed += count( $batch );
			foreach ( $batch as $index => $item ) {
				if ( $this->elapsed( $started ) >= $limit || ! $this->waitForSlot( $item, $started, $limit ) ) {
					// Out of time: give this item and the rest of the batch back without a try.
					foreach ( array_slice( $batch, $index ) as $rest ) {
						$this->queue->release( $rest->id, null, null, null, true, $worker );
						$report->add( Outcome::Held );
					}
					$report->stoppedBecause = 'time';
					break 2;
				}
				$report->add( $this->processor->process( $item, $worker ) );
			}
		}

		$report->seconds = $this->elapsed( $started );
		return $report;
	}

	/**
	 * Wait for the connection's next request slot.
	 *
	 * @param Item               $item    Item about to be sent.
	 * @param \DateTimeImmutable $started Start of the run.
	 * @param int                $limit   Work limit in seconds.
	 * @return bool False when the wait would run past the limit.
	 */
	private function waitForSlot( Item $item, \DateTimeImmutable $started, int $limit ): bool {
		$connection = $this->planner->connection( $item->connectionId );
		$rate       = (float) ( $connection?->settings['requests_per_second'] ?? 4 );
		$wait       = $this->limiter->acquire( $item->connectionId, $rate );
		if ( $wait <= 0.0 ) {
			return true;
		}
		if ( $this->elapsed( $started ) + $wait >= $limit ) {
			return false;
		}
		( $this->sleep )( $wait );
		return true;
	}


	/**
	 * Seconds since a moment.
	 *
	 * @param \DateTimeImmutable $started Start.
	 */
	private function elapsed( \DateTimeImmutable $started ): float {
		return max( 0.0, (float) $this->clock->now()->format( 'U.u' ) - (float) $started->format( 'U.u' ) );
	}
}
