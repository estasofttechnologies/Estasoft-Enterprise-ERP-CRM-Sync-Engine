<?php
/**
 * Pause and resume connections.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A paused connection keeps accepting items, but workers do not claim them. A pause can have a duration, after which the
 * connection resumes by itself. The same class records the two automatic pauses that wait for a person: a refused
 * sign-in (SYNC-AUTH-001, paused) and an expired or unfinished one (needs reconnect).
 *
 * Hooks: `estasoft_sync_connection_paused( int $connectionId, string $reason )` with reason person or sign_in, and
 * `estasoft_sync_connection_resumed( int $connectionId, string $how )`.
 */
final class PauseController {

	/**
	 * @param ConnectionRepository $connections Connections.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * Pause one connection by hand.
	 *
	 * @param int      $connectionId Connection.
	 * @param int|null $minutes      Minutes to pause; null pauses until someone resumes.
	 * @return bool False when the connection does not exist or is disconnected.
	 */
	public function pause( int $connectionId, ?int $minutes = null ): bool {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Disconnected === $connection->status ) {
			return false;
		}
		$until = ( null !== $minutes && $minutes > 0 ) ? Time::sql( Time::plus( $this->clock->now(), $minutes * 60 ) ) : null;
		$this->connections->setStatus( $connectionId, ConnectionStatus::Paused, $until, 'person' );
		$this->logger->info(
			ErrorCode::Pause001->template(),
			array(
				'connection_id' => $connectionId,
				'code'          => ErrorCode::Pause001,
			)
		);
		/**
		 * Fires when a connection is paused.
		 *
		 * @param int    $connectionId Connection id.
		 * @param string $reason       person, circuit or sign_in.
		 */
		do_action( 'estasoft_sync_connection_paused', $connectionId, 'person' );
		return true;
	}

	/**
	 * Resume one connection. Also clears a circuit pause and a "needs reconnect" state, because the person has acted.
	 *
	 * @param int $connectionId Connection.
	 * @return bool False when the connection does not exist, is disconnected, or is already active.
	 */
	public function resume( int $connectionId ): bool {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || in_array( $connection->status, array( ConnectionStatus::Disconnected, ConnectionStatus::Active ), true ) ) {
			return false;
		}
		$this->connections->setStatus( $connectionId, ConnectionStatus::Active );
		$this->logger->info( __( 'Syncing resumed.', 'estasoft-erp-crm-sync-engine' ), array( 'connection_id' => $connectionId ) );
		do_action( 'estasoft_sync_connection_resumed', $connectionId, 'person' );
		return true;
	}

	/**
	 * Pause every connection that is not disconnected.
	 *
	 * @param int|null $minutes Minutes, or null for no end.
	 * @return int How many were paused.
	 */
	public function pauseAll( ?int $minutes = null ): int {
		$count = 0;
		foreach ( $this->connections->allWithoutSecrets() as $connection ) {
			if ( $this->pause( $connection->id, $minutes ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Resume every connection that is paused or needs reconnecting.
	 *
	 * @return int How many were resumed.
	 */
	public function resumeAll(): int {
		$count = 0;
		foreach ( $this->connections->allWithoutSecrets() as $connection ) {
			if ( $this->resume( $connection->id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Resume connections whose timed pause has ended.
	 *
	 * @return int How many were resumed.
	 */
	public function resumeDue(): int {
		$count = 0;
		foreach ( $this->connections->pausesDue() as $due ) {
			if ( ConnectionStatus::Paused->value === $due['status'] ) {
				$this->connections->setStatus( $due['id'], ConnectionStatus::Active );
				$this->logger->info( __( 'Syncing resumed after the pause ended.', 'estasoft-erp-crm-sync-engine' ), array( 'connection_id' => $due['id'] ) );
				do_action( 'estasoft_sync_connection_resumed', $due['id'], 'timer' );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The system refused the sign-in (SYNC-AUTH-001): pause the connection. Items stay Queued.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $system       Name of the system, for the message.
	 * @return bool Whether this call paused it (false when it was not active).
	 */
	public function pauseForRefusedSignIn( int $connectionId, string $system = '' ): bool {
		return $this->holdForPerson( $connectionId, ErrorCode::Auth001, ConnectionStatus::Paused, $system );
	}

	/**
	 * The sign-in expired or was not completed (SYNC-AUTH-002, 003, 005): the connection needs the person to reconnect.
	 * Items stay Queued.
	 *
	 * @param int       $connectionId Connection.
	 * @param ErrorCode $code         The code to record in the log.
	 * @param string    $system       Name of the system, for the message.
	 * @return bool Whether this call changed the status.
	 */
	public function markNeedsReconnect( int $connectionId, ErrorCode $code = ErrorCode::Auth002, string $system = '' ): bool {
		return $this->holdForPerson( $connectionId, $code, ConnectionStatus::NeedsReconnect, $system );
	}

	/**
	 * Stop an active connection until a person fixes something (sign-in, encryption key). Items stay Queued.
	 *
	 * @param int              $connectionId Connection.
	 * @param ErrorCode        $code         The catalog code to log.
	 * @param ConnectionStatus $status       Paused or NeedsReconnect.
	 * @param string           $system       Name of the system, for the message.
	 * @param string|null      $message      A message to log instead of the catalog text.
	 * @return bool Whether this call changed the status (false when the connection was not active).
	 */
	public function holdForPerson( int $connectionId, ErrorCode $code, ConnectionStatus $status, string $system = '', ?string $message = null ): bool {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Active !== $connection->status ) {
			return false;
		}
		$this->connections->setStatus( $connectionId, $status, null, 'auth' );
		$this->logger->error(
			$message ?? str_replace( '{system}', '' !== $system ? $system : $connection->label, $code->template() ),
			array(
				'connection_id' => $connectionId,
				'code'          => $code,
			)
		);
		do_action( 'estasoft_sync_connection_paused', $connectionId, 'sign_in' );
		return true;
	}
}
