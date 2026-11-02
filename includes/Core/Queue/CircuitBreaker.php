<?php
/**
 * Stops sending to a system that has stopped answering.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * After N retryable failures in a row (default 5) the connection moves to "paused, circuit open" and workers stop
 * claiming its items. A rate-limit answer (429) never counts. Every M minutes (default 2) the connector's cheap `ping`
 * runs; the first success, from the probe or from any send, closes the circuit and the connection resumes.
 *
 * `paused_until` holds the time of the next probe while the circuit is open.
 *
 * Hooks: `estasoft_sync_connection_paused( $connectionId, 'circuit' )` and `estasoft_sync_connection_resumed( $connectionId, 'probe'|'success' )`.
 */
final class CircuitBreaker {

	/**
	 * @param ConnectionRepository         $connections Connections.
	 * @param \Closure():ConnectorRegistry $registry Returns the ConnectorRegistry (resolved late so add-ons are registered).
	 * @param Logger                       $logger      Log.
	 * @param Clock                        $clock       Time source.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly \Closure $registry,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * A send worked. Resets the failure count and closes the circuit if it was open.
	 *
	 * @param Connection $connection Connection.
	 * @param int        $ms         Response time in milliseconds.
	 */
	public function recordSuccess( Connection $connection, int $ms ): void {
		$this->connections->recordSuccess( $connection->id, $ms );
		$this->connections->resetFailures( $connection->id );
		if ( $this->connections->resumeFromCircuit( $connection->id ) ) {
			$this->announceResume( $connection->id, 'success' );
		}
	}

	/**
	 * The system answered (even with a refusal that needs a person), so it is reachable: the count of failures in a row restarts.
	 *
	 * @param Connection $connection Connection.
	 */
	public function recordReachable( Connection $connection ): void {
		$this->connections->resetFailures( $connection->id );
	}

	/**
	 * A retryable failure. Counts toward the breaker unless it was rate limiting.
	 *
	 * @param Connection $connection Connection.
	 * @param bool       $counts     False for a 429.
	 * @return bool True when this failure opened the circuit.
	 */
	public function recordRetryableFailure( Connection $connection, bool $counts = true ): bool {
		$this->connections->recordFailure( $connection->id, 0 );
		if ( ! $counts ) {
			return false;
		}
		$failures  = $this->connections->incrementFailures( $connection->id );
		$threshold = (int) ( $connection->settings['breaker_threshold'] ?? 5 );
		if ( $failures < $threshold ) {
			return false;
		}
		if ( ! $this->connections->tripCircuit( $connection->id, $this->nextProbeAt( $connection ) ) ) {
			return false;
		}
		$this->logger->error(
			ErrorCode::Pause001->template(),
			array(
				'connection_id' => $connection->id,
				'code'          => ErrorCode::Pause001,
			)
		);
		/** This action is documented in PauseController. */
		do_action( 'estasoft_sync_connection_paused', $connection->id, 'circuit' );
		return true;
	}

	/**
	 * Probe every connection whose circuit is open and whose next probe time has come.
	 *
	 * @return int How many connections resumed.
	 */
	public function probeDue(): int {
		$resumed = 0;
		foreach ( $this->connections->pausesDue() as $due ) {
			if ( ConnectionStatus::PausedCircuit->value === $due['status'] && $this->probe( $due['id'] ) ) {
				++$resumed;
			}
		}
		return $resumed;
	}

	/**
	 * Run the connector's `ping` for one connection with an open circuit.
	 *
	 * @param int $connectionId Connection.
	 * @return bool True when the probe succeeded and the connection resumed.
	 */
	public function probe( int $connectionId ): bool {
		try {
			$connection = $this->connections->find( $connectionId );
		} catch ( CryptoException $e ) {
			unset( $e );
			$connection = null;
		}
		if ( null === $connection || ConnectionStatus::PausedCircuit !== $connection->status ) {
			return false;
		}
		$connector = ( $this->registry )()->get( $connection->connector );
		$alive     = false;
		if ( null !== $connector ) {
			try {
				$alive = $connector->ping( $connection );
			} catch ( \Throwable $e ) {
				unset( $e );
				$alive = false;
			}
		}
		if ( $alive ) {
			if ( $this->connections->resumeFromCircuit( $connectionId ) ) {
				$this->announceResume( $connectionId, 'probe' );
			}
			return true;
		}
		$this->connections->scheduleProbe( $connectionId, $this->nextProbeAt( $connection ) );
		$this->logger->warning( __( 'The system still does not answer. The next check is scheduled.', 'estasoft-erp-crm-sync-engine' ), array( 'connection_id' => $connectionId ) );
		return false;
	}

	/**
	 * Log and announce a resume.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $how          probe or success.
	 */
	private function announceResume( int $connectionId, string $how ): void {
		$this->logger->info( __( 'The system answers again. Syncing resumed.', 'estasoft-erp-crm-sync-engine' ), array( 'connection_id' => $connectionId ) );
		/** This action is documented in PauseController. */
		do_action( 'estasoft_sync_connection_resumed', $connectionId, $how );
	}

	/**
	 * When the next probe is due.
	 *
	 * @param Connection $connection Connection.
	 */
	private function nextProbeAt( Connection $connection ): string {
		$minutes = max( 1, (int) ( $connection->settings['probe_interval_minutes'] ?? 2 ) );
		return Time::sql( Time::plus( $this->clock->now(), $minutes * 60 ) );
	}
}
