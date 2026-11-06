<?php
/**
 * The one door from a background job to a connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Queue\ErrorClassifier;
use Estasoft\SyncEngine\Core\Queue\FailureAction;
use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Queue\RateLimiter;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Stock, tracking, invoices, reconciliation and the shadow report all read from the connected system. They share these rules,
 * the same ones the queue worker follows:
 *
 * - A job acts only on a connection that is active (not paused, not waiting for a person), whose connector is installed and
 *   declares the capability the job needs.
 * - Every call waits for the connection's slot in the shared rate limiter first.
 * - Whatever the connector throws is caught and turned into a `ConnectorError`; it is classified by the same ErrorClassifier,
 *   logged (redacted) and never stops another connection. A refused sign-in pauses the connection exactly as the queue does.
 * - The 30 second request limit is enforced by the connector's HTTP client.
 */
final class ConnectorGateway {

	/**
	 * Longest wait for a rate limiter slot inside one call, in seconds.
	 */
	private const MAX_WAIT = 5.0;

	/**
	 * Waits for a number of seconds.
	 *
	 * @var \Closure
	 */
	private \Closure $sleep;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param \Closure             $registry    Returns the connector registry.
	 * @param RateLimiter          $limiter     Shared request limiter.
	 * @param ErrorClassifier      $classifier  Error classification.
	 * @param PauseController      $pause       Pauses a connection whose sign-in was refused.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $sleep       Waits for seconds; usleep by default.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly \Closure $registry,
		private readonly RateLimiter $limiter,
		private readonly ErrorClassifier $classifier,
		private readonly PauseController $pause,
		private readonly Logger $logger,
		private readonly Clock $clock,
		?\Closure $sleep = null
	) {
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	/**
	 * Connections a job may act on, with their secrets decrypted.
	 *
	 * @param Capability $capability   The flag the connector must declare.
	 * @param int|null   $connectionId One connection only, or null for all.
	 * @return array<int,array{connection:Connection,connector:ConnectorInterface}>
	 */
	public function targets( Capability $capability, ?int $connectionId = null ): array {
		$registry = ( $this->registry )();
		$targets  = array();
		foreach ( $this->connections->allWithoutSecrets() as $candidate ) {
			if ( null !== $connectionId && $candidate->id !== $connectionId ) {
				continue;
			}
			if ( ! $candidate->status->allowsClaiming() ) {
				continue;
			}
			$connector = $registry->get( $candidate->connector );
			if ( null === $connector || true !== ( $connector->capabilities()[ $capability->value ] ?? false ) ) {
				continue;
			}
			try {
				$connection = $this->connections->find( $candidate->id );
			} catch ( CryptoException $e ) {
				$this->logger->warning(
					null === $e->errorCode() ? __( 'Saved credentials could not be read because the encryption key changed.', 'estasoft-enterprise-erpcrm-sync-engine' ) : ErrorCode::Cfg002->template(),
					array(
						'connection_id' => $candidate->id,
						'code'          => $e->errorCode() ?? ErrorCode::Cfg002,
					)
				);
				continue;
			}
			if ( null !== $connection ) {
				$targets[] = array(
					'connection' => $connection,
					'connector'  => $connector,
				);
			}
		}
		return $targets;
	}

	/**
	 * Make one call to a connector.
	 *
	 * @param Connection         $connection Connection (with secrets).
	 * @param ConnectorInterface $connector  Its connector.
	 * @param callable           $call       The connector call.
	 * @return mixed What the call returned, or a ConnectorError (returned by the connector or made from an exception).
	 */
	public function call( Connection $connection, ConnectorInterface $connector, callable $call ): mixed {
		$rate = (float) ( $connection->settings['requests_per_second'] ?? 4 );
		$wait = $this->limiter->acquire( $connection->id, $rate );
		if ( $wait > 0.0 ) {
			( $this->sleep )( min( $wait, self::MAX_WAIT ) );
		}
		$started = $this->clock->now();
		try {
			$result = $call();
		} catch ( \Throwable $e ) {
			$result = new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $connector->label(),
					'field'  => 'connector',
					'reason' => sprintf(
						/* translators: %s: PHP error class name. */
						__( 'The connector stopped unexpectedly (%s).', 'estasoft-enterprise-erpcrm-sync-engine' ),
						( new \ReflectionClass( $e ) )->getShortName()
					),
				),
				null,
				null,
				null,
				'connector exception'
			);
		}
		if ( $result instanceof ConnectorError ) {
			$this->failed( $connection, $connector, $result );
			return $result;
		}
		$this->connections->recordSuccess( $connection->id, Time::millisecondsBetween( $started, $this->clock->now() ) );
		return $result;
	}

	/**
	 * Log a failure, record it on the connection and pause the connection when the sign-in was refused.
	 *
	 * @param Connection         $connection Connection.
	 * @param ConnectorInterface $connector  Connector.
	 * @param ConnectorError     $error      The failure.
	 */
	private function failed( Connection $connection, ConnectorInterface $connector, ConnectorError $error ): void {
		$decision = $this->classifier->classify( $error, $connector, $connector->label() );
		$failure  = $decision->error;
		$this->logger->log(
			FailureAction::Retry === $decision->action ? 'warning' : 'error',
			$failure->message(),
			array(
				'connection_id' => $connection->id,
				'code'          => $failure->code,
				'http_status'   => $failure->httpStatus,
				'response'      => '' !== $failure->detail ? $failure->detail : null,
			)
		);
		$this->connections->recordFailure( $connection->id, 0 );
		if ( FailureAction::PauseSignIn === $decision->action ) {
			$this->pause->pauseForRefusedSignIn( $connection->id, $connector->label() );
		} elseif ( FailureAction::NeedsReconnect === $decision->action ) {
			$this->pause->markNeedsReconnect( $connection->id, $failure->code, $connector->label() );
		}
	}
}
