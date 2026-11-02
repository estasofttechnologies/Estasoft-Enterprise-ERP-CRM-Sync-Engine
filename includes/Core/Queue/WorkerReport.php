<?php
/**
 * What one worker run did.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Counters returned by Worker::run().
 */
final class WorkerReport {

	/**
	 * Items claimed.
	 */
	public int $claimed = 0;

	/**
	 * Items the system accepted, linked to an existing record, found unchanged, or built in shadow mode.
	 */
	public int $synced = 0;

	/**
	 * Items that will be tried again.
	 */
	public int $retrying = 0;

	/**
	 * Items that now wait for a person.
	 */
	public int $failed = 0;

	/**
	 * Items given back without a try (connection paused, connector missing, time ran out).
	 */
	public int $held = 0;

	/**
	 * Items skipped because their connection is gone.
	 */
	public int $skipped = 0;

	/**
	 * Seconds the run took.
	 */
	public float $seconds = 0.0;

	/**
	 * Why the run ended: idle (nothing left to claim) or time (the work limit was reached).
	 */
	public string $stoppedBecause = 'idle';

	/**
	 * Count one finished item.
	 *
	 * @param Outcome $outcome How it ended.
	 */
	public function add( Outcome $outcome ): void {
		switch ( $outcome ) {
			case Outcome::Synced:
			case Outcome::Linked:
			case Outcome::NoChange:
			case Outcome::Shadow:
				++$this->synced;
				break;
			case Outcome::Retrying:
				++$this->retrying;
				break;
			case Outcome::Failed:
				++$this->failed;
				break;
			case Outcome::Skipped:
				++$this->skipped;
				break;
			default:
				++$this->held;
		}
	}

	/**
	 * The report as an array.
	 *
	 * @return array<string,int|float|string>
	 */
	public function toArray(): array {
		return array(
			'claimed'         => $this->claimed,
			'synced'          => $this->synced,
			'retrying'        => $this->retrying,
			'failed'          => $this->failed,
			'held'            => $this->held,
			'skipped'         => $this->skipped,
			'seconds'         => round( $this->seconds, 3 ),
			'stopped_because' => $this->stoppedBecause,
		);
	}
}
