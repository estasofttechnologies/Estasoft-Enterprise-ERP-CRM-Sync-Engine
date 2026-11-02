<?php
/**
 * The delivery pipeline for one claimed item.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdempotencyRepository;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;
use Estasoft\SyncEngine\Sync\PayloadResult;

/**
 * For a claimed item: load the connection, build the payload from the latest order data and the live mapping, skip
 * the send when nothing changed, look for a record that already exists (SYNC-DUP-001), send, classify the result,
 * write it, and update the id map, the state hash and the idempotency ledger.
 *
 * A connector that throws is caught and the item fails on its own (CMP-6); no other item is affected. Nothing in this
 * class throws. Shadow items are built and, when the connector can look things up read-only, compared, but nothing is
 * sent and nothing is changed in either system.
 *
 * Warnings. A connector can accept a record and still have something to say (the Odoo connector reports a total that differs
 * from the shop's when it is set to go on): it puts a list in `extra['warnings']` of the reference it returns. Each one is
 * written to the log once for that send, at warning level, with the order reference, and redacted like every other message.
 *
 * The lock token. `process()` is given the `workerId` that claimed the item. Every write to the queue row is guarded by it
 * (the row must still be `syncing` and held by that token), and the lock is refreshed before each connector call. If the
 * lock was lost (it ran out and the reaper handed the item to someone else), the result is discarded: the row is left alone,
 * a warning is logged, the outcome is `Outcome::LockLost`, and no hook fires. The facts the other system now holds are still
 * recorded (id map, state hash, idempotency ledger), because they are true and keep the next worker from sending twice.
 *
 * Hooks, all actions:
 * - `estasoft_sync_before_send( Item $item, array $payload, int $connectionId )`
 * - `estasoft_sync_after_send( Item $item, SendResult $result, int $milliseconds )`
 * - `estasoft_sync_item_synced( Item $item, ExternalRef $ref, bool $alreadyExisted )`
 * - `estasoft_sync_item_failed( Item $item, ConnectorError $error, bool $willRetry )`
 * - `estasoft_sync_shadow_processed( Item $item, PayloadResult $built, ExternalRef|ConnectorError|null $found )`
 */
final class ItemProcessor {

	/**
	 * Seconds the item lock is pushed out to before a send, so a slow system cannot let the lock run out.
	 */
	private const SEND_LOCK_SECONDS = 90;

	/**
	 * Seconds an item waits when its connector is not installed.
	 */
	private const MISSING_CONNECTOR_WAIT = 300;

	/**
	 * Most warnings of one send that are written to the log.
	 */
	private const MAX_WARNINGS = 10;

	/**
	 * The lock token of the item being processed; null when the caller did not give one.
	 */
	private ?string $token = null;

	/**
	 * @param ConnectionRepository  $connections Connections.
	 * @param QueueRepository       $queue       Queue.
	 * @param IdMapRepository       $idMap       Id map.
	 * @param StateRepository       $state       Data hashes.
	 * @param IdempotencyRepository $idempotency Idempotency ledger.
	 * @param Logger                $logger      Log.
	 * @param RetryPolicy           $retry       Retry schedule.
	 * @param ErrorClassifier       $classifier  Error classification.
	 * @param CircuitBreaker        $breaker     Circuit breaker.
	 * @param PauseController       $pause       Pause control.
	 * @param Clock                 $clock       Time source.
	 * @param \Closure():ConnectorRegistry $connectors Returns the connector registry.
	 * @param \Closure():(PayloadBuilderInterface|null) $payloadBuilder Returns the payload builder, or null when the mapping module is absent.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly QueueRepository $queue,
		private readonly IdMapRepository $idMap,
		private readonly StateRepository $state,
		private readonly IdempotencyRepository $idempotency,
		private readonly Logger $logger,
		private readonly RetryPolicy $retry,
		private readonly ErrorClassifier $classifier,
		private readonly CircuitBreaker $breaker,
		private readonly PauseController $pause,
		private readonly Clock $clock,
		private readonly \Closure $connectors,
		private readonly \Closure $payloadBuilder
	) {
	}

	/**
	 * Process one claimed item to its next resting state. Never throws.
	 *
	 * @param Item        $item      An item returned by QueueRepository::claim().
	 * @param string|null $lockToken The `workerId` that was given to claim(). Without it the queue row is written unguarded.
	 * @return Outcome How it ended.
	 */
	public function process( Item $item, ?string $lockToken = null ): Outcome {
		$previous    = $this->token;
		$this->token = $lockToken;
		try {
			return $this->run( $item );
		} catch ( \Throwable $e ) {
			// Something unexpected in the plugin itself. Put the item back without a try and say so; never lose it.
			$this->queue->release( $item->id, Time::sql( Time::plus( $this->clock->now(), self::MISSING_CONNECTOR_WAIT ) ), null, null, true, $this->token );
			$this->logger->error(
				sprintf(
					/* translators: %s: PHP error class name. */
					__( 'The queue hit an unexpected error and will try this item again later (%s).', 'estasoft-erp-crm-sync-engine' ),
					get_class( $e )
				),
				array(
					'connection_id' => $item->connectionId,
					'queue_id'      => $item->id,
					'entity_ref'    => $this->reference( $item ),
				)
			);
			return Outcome::Held;
		} finally {
			$this->token = $previous;
		}
	}

	/**
	 * The result of an item could not be written because its lock was lost. Say so and leave the row alone.
	 *
	 * @param Item $item Item.
	 */
	private function lost( Item $item ): Outcome {
		$this->logger->warning(
			__( 'The result of this item was thrown away because its lock ran out and another worker may have taken it over. The item was left as it is.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id' => $item->connectionId,
				'queue_id'      => $item->id,
				'entity_ref'    => $this->reference( $item ),
				'code'          => ErrorCode::Lock001,
			)
		);
		return Outcome::LockLost;
	}

	/**
	 * Push the lock out before a call to a system, and say whether this worker still holds it.
	 *
	 * @param Item $item Item.
	 */
	private function holdsLock( Item $item ): bool {
		if ( null === $this->token ) {
			return true;
		}
		return $this->queue->extendLock( $item->id, self::SEND_LOCK_SECONDS, $this->token );
	}

	/**
	 * Turn a queue write into an outcome: the given one when the row was written, LockLost when it was not.
	 *
	 * @param bool    $written Whether the guarded write changed the row.
	 * @param Item    $item    Item.
	 * @param Outcome $outcome Outcome to report when written.
	 */
	private function settle( bool $written, Item $item, Outcome $outcome ): Outcome {
		return $written ? $outcome : $this->lost( $item );
	}

	/**
	 * The pipeline.
	 *
	 * @param Item $item Claimed item.
	 */
	private function run( Item $item ): Outcome {
		try {
			$connection = $this->connections->find( $item->connectionId );
		} catch ( CryptoException $e ) {
			return $this->holdForCredentials( $item, $e );
		}
		if ( null === $connection ) {
			if ( ! $this->queue->release( $item->id, null, null, null, true, $this->token ) ) {
				return $this->lost( $item );
			}
			$this->queue->skip( $item->id, __( 'The connection was removed.', 'estasoft-erp-crm-sync-engine' ) );
			return Outcome::Skipped;
		}
		if ( ! $connection->status->allowsClaiming() ) {
			return $this->settle( $this->queue->release( $item->id, null, null, null, true, $this->token ), $item, Outcome::Held );
		}

		$connector = ( $this->connectors )()->get( $connection->connector );
		if ( null === $connector ) {
			return $this->holdWithoutConnector( $item, $connection );
		}

		$builder = ( $this->payloadBuilder )();
		if ( null === $builder ) {
			return $this->handleFailure( $item, $connection, $connector, new ConnectorError( ErrorCode::Cfg001, array( 'type' => $item->entityType->value ) ), 0, null, null, false );
		}
		try {
			$built = $builder->build( $item, $connection );
		} catch ( \Throwable $e ) {
			$built = new PayloadResult(
				array(),
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'field'  => 'payload',
						'reason' => __( 'The payload could not be built.', 'estasoft-erp-crm-sync-engine' ),
					),
					null,
					null,
					null,
					get_class( $e )
				)
			);
		}
		if ( ! $built->isReady() && null !== $built->error ) {
			return $this->handleFailure( $item, $connection, $connector, $built->error, 0, null, $built->mappingVersion, false, $built->sensitivePaths );
		}

		if ( ItemMode::Shadow === $item->mode ) {
			return $this->shadow( $item, $connection, $connector, $built );
		}

		$payload = $built->payload;
		$hash    = hash( 'sha256', Json::canonical( $payload ) );
		$mapped  = $this->idMap->get( $item->connectionId, $item->entityType, $item->entityId );

		// Nothing changed since the last sync: do not send.
		if ( null !== $mapped && $this->state->isUnchanged( $item->connectionId, $item->entityType, $item->entityId, $hash ) ) {
			if ( ! $this->queue->markSynced( $item->id, $mapped['external_id'], (string) $mapped['external_ref'], $mapped['external_url'], $built->mappingVersion, null, false, null, $this->token ) ) {
				return $this->lost( $item );
			}
			$this->idempotency->markDone( $item->idempotencyKey, $mapped['external_id'] );
			$this->note( $item, __( 'Nothing changed since the last sync, so nothing was sent.', 'estasoft-erp-crm-sync-engine' ), $built->mappingVersion );
			return Outcome::NoChange;
		}

		// A first send: the system may already have the record (a retry after a lost answer, or a manual entry).
		if ( null === $mapped ) {
			if ( ! $this->holdsLock( $item ) ) {
				return $this->lost( $item );
			}
			$found = $this->guard( fn() => $connector->findExisting( $connection, $item ), $connector );
			if ( $found instanceof ConnectorError ) {
				return $this->handleFailure( $item, $connection, $connector, $found, 0, $payload, $built->mappingVersion, true, $built->sensitivePaths );
			}
			if ( $found instanceof ExternalRef ) {
				return $this->linkExisting( $item, $connection, $connector, $found, $built->mappingVersion );
			}
		}

		if ( ! $this->holdsLock( $item ) ) {
			return $this->lost( $item );
		}

		/**
		 * Fires just before an item is sent.
		 *
		 * @param Item                $item         The item.
		 * @param array<string,mixed> $payload      The mapped payload.
		 * @param int                 $connectionId Connection id.
		 */
		do_action( 'estasoft_sync_before_send', $item, $payload, $item->connectionId );

		$started = $this->clock->now();
		$sent    = $this->guard( fn() => $connector->send( $connection, $item, $payload ), $connector );
		$ms      = Time::millisecondsBetween( $started, $this->clock->now() );
		$result  = $sent instanceof SendResult ? $sent : SendResult::failure( $sent );

		/**
		 * Fires after a send, whatever the result.
		 *
		 * @param Item       $item   The item.
		 * @param SendResult $result The result.
		 * @param int        $ms     Milliseconds the send took.
		 */
		do_action( 'estasoft_sync_after_send', $item, $result, $ms );

		if ( $result->isSuccess() && null !== $result->ref ) {
			return $this->succeed( $item, $connection, $connector, $result->ref, $result->alreadyExisted, $hash, $payload, $built->mappingVersion, $ms, $built->sensitivePaths );
		}
		$error = $result->error ?? new ConnectorError( ErrorCode::Val002, array( 'field' => 'response', 'reason' => __( 'The connector returned nothing.', 'estasoft-erp-crm-sync-engine' ) ) );
		return $this->handleFailure( $item, $connection, $connector, $error, $ms, $payload, $built->mappingVersion, true, $built->sensitivePaths );
	}

	/**
	 * Run connector code and turn an exception into a ConnectorError, so one broken connector never stops the queue (CMP-6).
	 *
	 * @param callable           $call      The connector call.
	 * @param ConnectorInterface $connector The connector, for its name.
	 * @return mixed What the call returned, or a ConnectorError.
	 */
	private function guard( callable $call, ConnectorInterface $connector ): mixed {
		try {
			return $call();
		} catch ( \Throwable $e ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $connector->label(),
					'field'  => 'connector',
					'reason' => sprintf(
						/* translators: %s: PHP error class name. */
						__( 'The connector stopped unexpectedly (%s).', 'estasoft-erp-crm-sync-engine' ),
						( new \ReflectionClass( $e ) )->getShortName()
					),
				),
				null,
				null,
				null,
				'connector exception'
			);
		}
	}

	/**
	 * The system already has the record: link it, do not send (SYNC-DUP-001).
	 *
	 * @param Item               $item           Item.
	 * @param Connection         $connection     Connection.
	 * @param ConnectorInterface $connector      Connector.
	 * @param ExternalRef        $found          The existing record.
	 * @param int|null           $mappingVersion Mapping version.
	 */
	private function linkExisting( Item $item, Connection $connection, ConnectorInterface $connector, ExternalRef $found, ?int $mappingVersion ): Outcome {
		$message = str_replace( array( '{system}', '{ref}' ), array( $connector->label(), $found->ref ), ErrorCode::Dup001->template() );
		$this->idMap->link( $item->connectionId, $item->entityType, $item->entityId, $found->id, $found->ref, $found->url );
		$this->idempotency->markDone( $item->idempotencyKey, $found->id );
		$written = $this->queue->markSynced( $item->id, $found->id, $found->ref, $found->url, $mappingVersion, ErrorCode::Dup001->value, true, $message, $this->token );
		$this->connections->recordSuccess( $connection->id, 0 );
		$this->breaker->recordReachable( $connection );
		if ( ! $written ) {
			return $this->lost( $item );
		}
		$this->note( $item, $message, $mappingVersion, ErrorCode::Dup001 );
		do_action( 'estasoft_sync_item_synced', $item, $found, true );
		return Outcome::Linked;
	}

	/**
	 * The system accepted the item.
	 *
	 * @param Item                $item           Item.
	 * @param Connection          $connection     Connection.
	 * @param ConnectorInterface  $connector      Connector.
	 * @param ExternalRef         $ref            The record.
	 * @param bool                $alreadyExisted Whether the connector found an existing record instead of creating one.
	 * @param string              $hash           Hash of the payload.
	 * @param array<string,mixed> $payload        Payload, for the log excerpt.
	 * @param int|null            $mappingVersion Mapping version.
	 * @param int                 $ms             Milliseconds the send took.
	 * @param array<int,string>   $sensitivePaths Field paths of the payload that hold personal data, for the log excerpt.
	 */
	private function succeed( Item $item, Connection $connection, ConnectorInterface $connector, ExternalRef $ref, bool $alreadyExisted, string $hash, array $payload, ?int $mappingVersion, int $ms, array $sensitivePaths = array() ): Outcome {
		$this->idMap->link( $item->connectionId, $item->entityType, $item->entityId, $ref->id, $ref->ref, $ref->url );
		if ( ! $alreadyExisted ) {
			$this->state->save( $item->connectionId, $item->entityType, $item->entityId, $hash, $mappingVersion );
		}
		$this->idempotency->markDone( $item->idempotencyKey, $ref->id );
		$duplicate = str_replace( array( '{system}', '{ref}' ), array( $connector->label(), $ref->ref ), ErrorCode::Dup001->template() );
		$written = $this->queue->markSynced( $item->id, $ref->id, $ref->ref, $ref->url, $mappingVersion, $alreadyExisted ? ErrorCode::Dup001->value : null, true, $alreadyExisted ? $duplicate : null, $this->token );
		$this->breaker->recordSuccess( $connection, $ms );
		if ( ! $written ) {
			// The row is not ours to write any more, but the system did accept the record with these warnings.
			$this->logWarnings( $item, $ref, $connector, $mappingVersion );
			return $this->lost( $item );
		}

		if ( ErrorCode::State001->value === $item->errorCode ) {
			$this->note( $item, ErrorCode::State001->template(), $mappingVersion, ErrorCode::State001 );
		}
		if ( $alreadyExisted ) {
			$message = $duplicate;
			$code    = ErrorCode::Dup001;
		} else {
			/* translators: %s: name of the external system, for example Odoo. */
			$message = sprintf( __( 'Sent to %s.', 'estasoft-erp-crm-sync-engine' ), $connector->label() );
			$code    = null;
		}
		$log_id = $this->logger->info(
			$message,
			array(
				'connection_id'   => $item->connectionId,
				'queue_id'        => $item->id,
				'entity_ref'      => $this->reference( $item ),
				'code'            => $code,
				'duration_ms'     => $ms,
				'attempt'         => $item->attempt + 1,
				'mapping_version' => $mappingVersion,
				'request'         => $payload,
				'sensitive_paths' => $sensitivePaths,
			)
		);
		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
		}
		$this->logWarnings( $item, $ref, $connector, $mappingVersion );
		do_action( 'estasoft_sync_item_synced', $item, $ref, $alreadyExisted );
		return $alreadyExisted ? Outcome::Linked : Outcome::Synced;
	}

	/**
	 * Decide what a failed attempt means and write it.
	 *
	 * @param Item                     $item           Item.
	 * @param Connection               $connection     Connection.
	 * @param ConnectorInterface       $connector      Connector.
	 * @param ConnectorError           $error          The failure.
	 * @param int                      $ms             Milliseconds spent (0 when nothing was sent).
	 * @param array<string,mixed>|null $payload        Payload, for the log excerpt.
	 * @param int|null                 $mappingVersion Mapping version.
	 * @param bool                     $sent           Whether the system was contacted (errors found before sending are not tries).
	 * @param array<int,string>        $sensitivePaths Field paths of the payload that hold personal data, for the log excerpt.
	 */
	private function handleFailure( Item $item, Connection $connection, ConnectorInterface $connector, ConnectorError $error, int $ms, ?array $payload, ?int $mappingVersion, bool $sent, array $sensitivePaths = array() ): Outcome {
		$decision = $this->classifier->classify( $error, $connector, $connector->label() );
		$failure  = $decision->error;
		$message  = $failure->message();
		$cause    = $this->classifier->cause( $failure );

		// The row is written first, guarded by the lock token; the log entry and the hooks follow only when it was.
		$written   = true;
		$willRetry = null;
		switch ( $decision->action ) {
			case FailureAction::PauseSignIn:
				$this->pause->pauseForRefusedSignIn( $connection->id, $connector->label() );
				$written = $this->queue->release( $item->id, null, $failure->code->value, $message, false, $this->token );
				$outcome = Outcome::Held;
				break;
			case FailureAction::NeedsReconnect:
				$this->pause->markNeedsReconnect( $connection->id, $failure->code, $connector->label() );
				$written = $this->queue->release( $item->id, null, $failure->code->value, $message, false, $this->token );
				$outcome = Outcome::Held;
				break;
			case FailureAction::Retry:
				$tries = $item->attempt + 1;
				$next  = $tries >= $item->maxAttempts ? null : $this->retry->nextAttemptAt( $tries, $connection->settings, $decision->retryAfter );
				$this->breaker->recordRetryableFailure( $connection, $decision->countsForBreaker );
				if ( null === $next ) {
					$written   = $this->queue->markFailed( $item->id, $failure->code->value, $message, $cause, true, $this->token );
					$willRetry = false;
					$outcome   = Outcome::Failed;
				} else {
					$written   = $this->queue->markRetrying( $item->id, $next, $failure->code->value, $message, $cause, $this->token );
					$willRetry = true;
					$outcome   = Outcome::Retrying;
				}
				break;
			default:
				$written = $this->queue->markFailed( $item->id, $failure->code->value, $message, $cause, $sent, $this->token );
				if ( $sent ) {
					$this->connections->recordFailure( $connection->id, $ms );
					$this->breaker->recordReachable( $connection );
				}
				$willRetry = false;
				$outcome   = Outcome::Failed;
		}
		if ( ! $written ) {
			return $this->lost( $item );
		}

		$log_id = $this->logger->log(
			FailureAction::Retry === $decision->action ? 'warning' : 'error',
			$message,
			array(
				'connection_id'   => $item->connectionId,
				'queue_id'        => $item->id,
				'entity_ref'      => $this->reference( $item ),
				'code'            => $failure->code,
				'http_status'     => $failure->httpStatus,
				'duration_ms'     => $sent ? $ms : null,
				'attempt'         => $item->attempt + 1,
				'mapping_version' => $mappingVersion,
				'request'         => $sent ? $payload : null,
				'response'        => '' !== $failure->detail ? $failure->detail : null,
				'sensitive_paths' => $sensitivePaths,
			)
		);
		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
		}
		if ( null !== $willRetry ) {
			do_action( 'estasoft_sync_item_failed', $item, $failure, $willRetry );
		}
		return $outcome;
	}

	/**
	 * Shadow mode: nothing is sent and nothing is changed in either system. The payload is built; when the connector can look
	 * things up read-only, the existing record is looked up so the shadow report can compare.
	 *
	 * @param Item               $item      Item.
	 * @param Connection         $connection Connection.
	 * @param ConnectorInterface $connector Connector.
	 * @param PayloadResult      $built     Built payload.
	 */
	private function shadow( Item $item, Connection $connection, ConnectorInterface $connector, PayloadResult $built ): Outcome {
		$found = null;
		if ( ! empty( $connector->capabilities()[ Capability::ShadowLookup->value ] ) ) {
			if ( ! $this->holdsLock( $item ) ) {
				return $this->lost( $item );
			}
			$found = $this->guard( fn() => $connector->findExisting( $connection, $item ), $connector );
		}
		$ref = $found instanceof ExternalRef ? $found : null;
		if ( ! $this->queue->markSynced( $item->id, $ref?->id ?? '', $ref?->ref ?? '', $ref?->url, $built->mappingVersion, null, true, null, $this->token ) ) {
			return $this->lost( $item );
		}
		$log_id = $this->logger->info(
			__( 'Shadow check: the payload was built and nothing was sent.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id'   => $item->connectionId,
				'queue_id'        => $item->id,
				'entity_ref'      => $this->reference( $item ),
				'mapping_version' => $built->mappingVersion,
				'request'         => $built->payload,
				'sensitive_paths' => $built->sensitivePaths,
			)
		);
		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
		}
		/**
		 * Fires after a shadow item was built (and looked up read-only). Nothing was sent.
		 *
		 * @param Item                                 $item  Item.
		 * @param PayloadResult                        $built Built payload.
		 * @param ExternalRef|ConnectorError|null      $found The record found in the system, an error, or null.
		 */
		do_action( 'estasoft_sync_shadow_processed', $item, $built, $found );
		return Outcome::Shadow;
	}

	/**
	 * Write what the connector said about a record it accepted: each entry of `extra['warnings']`, as one log entry at warning
	 * level with the order reference. Called once for each send. A warning is an array with a `message` (a sentence) and
	 * whatever facts the connector adds (kept as the redacted response excerpt), or just a string. At most
	 * MAX_WARNINGS are written, so a connector cannot flood the log.
	 *
	 * @param Item               $item           Item.
	 * @param ExternalRef        $ref            The record the connector returned.
	 * @param ConnectorInterface $connector      The connector, for its name.
	 * @param int|null           $mappingVersion Mapping version.
	 */
	private function logWarnings( Item $item, ExternalRef $ref, ConnectorInterface $connector, ?int $mappingVersion ): void {
		$warnings = $ref->extra['warnings'] ?? null;
		if ( ! is_array( $warnings ) || array() === $warnings ) {
			return;
		}
		foreach ( array_slice( array_values( $warnings ), 0, self::MAX_WARNINGS ) as $warning ) {
			$facts = is_array( $warning ) ? $warning : array( 'message' => (string) ( is_scalar( $warning ) ? $warning : '' ) );
			$text  = isset( $facts['message'] ) && is_scalar( $facts['message'] ) ? trim( (string) $facts['message'] ) : '';
			if ( '' === $text ) {
				$text = sprintf(
					/* translators: %s: name of the external system, for example Odoo. */
					__( '%s accepted the order and added a warning.', 'estasoft-erp-crm-sync-engine' ),
					$connector->label()
				);
			}
			unset( $facts['message'] );
			$this->logger->warning(
				$text,
				array(
					'connection_id'   => $item->connectionId,
					'queue_id'        => $item->id,
					'entity_ref'      => $this->reference( $item ),
					'attempt'         => $item->attempt + 1,
					'mapping_version' => $mappingVersion,
					'response'        => array() === $facts ? null : $facts,
				)
			);
		}
	}

	/**
	 * The saved credentials cannot be used. Missing key: pause and wait for the key. Changed key: needs reconnect.
	 * Either way the item goes back to Queued and no try is counted.
	 *
	 * @param Item            $item Item.
	 * @param CryptoException $e    The failure.
	 */
	private function holdForCredentials( Item $item, CryptoException $e ): Outcome {
		$code = $e->errorCode();
		if ( ErrorCode::Cfg002 === $code ) {
			$this->pause->holdForPerson( $item->connectionId, ErrorCode::Cfg002, ConnectionStatus::Paused );
			$written = $this->queue->release( $item->id, null, ErrorCode::Cfg002->value, ErrorCode::Cfg002->template(), false, $this->token );
		} else {
			$this->pause->holdForPerson( $item->connectionId, ErrorCode::Auth002, ConnectionStatus::NeedsReconnect, '', $e->getMessage() );
			$written = $this->queue->release( $item->id, null, null, $e->getMessage(), false, $this->token );
		}
		return $this->settle( $written, $item, Outcome::Held );
	}

	/**
	 * The connector of this connection is not installed (an add-on was deactivated). The item waits.
	 *
	 * @param Item       $item       Item.
	 * @param Connection $connection Connection.
	 */
	private function holdWithoutConnector( Item $item, Connection $connection ): Outcome {
		$next = Time::sql( Time::plus( $this->clock->now(), self::MISSING_CONNECTOR_WAIT ) );
		if ( ! $this->queue->release( $item->id, $next, ErrorCode::Pause001->value, ErrorCode::Pause001->template(), false, $this->token ) ) {
			return $this->lost( $item );
		}
		if ( ErrorCode::Pause001->value !== $item->errorCode ) {
			$this->logger->warning(
				sprintf(
					/* translators: %s: connector id, for example odoo. */
					__( 'The connector "%s" is not installed or not active, so its items wait in the queue.', 'estasoft-erp-crm-sync-engine' ),
					$connection->connector
				),
				array(
					'connection_id' => $connection->id,
					'queue_id'      => $item->id,
					'code'          => ErrorCode::Pause001,
				)
			);
		}
		return Outcome::Held;
	}

	/**
	 * Write an information entry for an item.
	 *
	 * @param Item           $item           Item.
	 * @param string         $message        Message.
	 * @param int|null       $mappingVersion Mapping version.
	 * @param ErrorCode|null $code           Catalog code, if any.
	 */
	private function note( Item $item, string $message, ?int $mappingVersion, ?ErrorCode $code = null ): void {
		$log_id = $this->logger->info(
			$message,
			array(
				'connection_id'   => $item->connectionId,
				'queue_id'        => $item->id,
				'entity_ref'      => $this->reference( $item ),
				'code'            => $code,
				'attempt'         => $item->attempt + 1,
				'mapping_version' => $mappingVersion,
			)
		);
		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
		}
	}

	/**
	 * Short reference to the item for the log.
	 *
	 * @param Item $item Item.
	 */
	private function reference( Item $item ): string {
		return $item->entityType->value . ':' . $item->entityId;
	}
}
