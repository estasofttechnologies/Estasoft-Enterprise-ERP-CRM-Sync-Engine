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
	 * @param Item $item An item returned by QueueRepository::claim().
	 * @return Outcome How it ended.
	 */
	public function process( Item $item ): Outcome {
		try {
			return $this->run( $item );
		} catch ( \Throwable $e ) {
			// Something unexpected in the plugin itself. Put the item back without a try and say so; never lose it.
			$this->queue->release( $item->id, Time::sql( Time::plus( $this->clock->now(), self::MISSING_CONNECTOR_WAIT ) ), null, null, true );
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
		}
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
			$this->queue->release( $item->id, null, null, null, true );
			$this->queue->skip( $item->id, __( 'The connection was removed.', 'estasoft-erp-crm-sync-engine' ) );
			return Outcome::Skipped;
		}
		if ( ! $connection->status->allowsClaiming() ) {
			$this->queue->release( $item->id, null, null, null, true );
			return Outcome::Held;
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
			return $this->handleFailure( $item, $connection, $connector, $built->error, 0, null, $built->mappingVersion, false );
		}

		if ( ItemMode::Shadow === $item->mode ) {
			return $this->shadow( $item, $connection, $connector, $built );
		}

		$payload = $built->payload;
		$hash    = hash( 'sha256', Json::canonical( $payload ) );
		$mapped  = $this->idMap->get( $item->connectionId, $item->entityType, $item->entityId );

		// Nothing changed since the last sync: do not send.
		if ( null !== $mapped && $this->state->isUnchanged( $item->connectionId, $item->entityType, $item->entityId, $hash ) ) {
			$this->queue->markSynced( $item->id, $mapped['external_id'], (string) $mapped['external_ref'], $mapped['external_url'], $built->mappingVersion, null, false );
			$this->idempotency->markDone( $item->idempotencyKey, $mapped['external_id'] );
			$this->note( $item, __( 'Nothing changed since the last sync, so nothing was sent.', 'estasoft-erp-crm-sync-engine' ), $built->mappingVersion );
			return Outcome::NoChange;
		}

		// A first send: the system may already have the record (a retry after a lost answer, or a manual entry).
		if ( null === $mapped ) {
			$found = $this->guard( fn() => $connector->findExisting( $connection, $item ), $connector );
			if ( $found instanceof ConnectorError ) {
				return $this->handleFailure( $item, $connection, $connector, $found, 0, $payload, $built->mappingVersion, true );
			}
			if ( $found instanceof ExternalRef ) {
				return $this->linkExisting( $item, $connection, $connector, $found, $built->mappingVersion );
			}
		}

		$this->queue->extendLock( $item->id, self::SEND_LOCK_SECONDS );

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
			return $this->succeed( $item, $connection, $connector, $result->ref, $result->alreadyExisted, $hash, $payload, $built->mappingVersion, $ms );
		}
		$error = $result->error ?? new ConnectorError( ErrorCode::Val002, array( 'field' => 'response', 'reason' => __( 'The connector returned nothing.', 'estasoft-erp-crm-sync-engine' ) ) );
		return $this->handleFailure( $item, $connection, $connector, $error, $ms, $payload, $built->mappingVersion, true );
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
		$this->queue->markSynced( $item->id, $found->id, $found->ref, $found->url, $mappingVersion, ErrorCode::Dup001->value, true, $message );
		$this->connections->recordSuccess( $connection->id, 0 );
		$this->breaker->recordReachable( $connection );
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
	 */
	private function succeed( Item $item, Connection $connection, ConnectorInterface $connector, ExternalRef $ref, bool $alreadyExisted, string $hash, array $payload, ?int $mappingVersion, int $ms ): Outcome {
		$this->idMap->link( $item->connectionId, $item->entityType, $item->entityId, $ref->id, $ref->ref, $ref->url );
		if ( ! $alreadyExisted ) {
			$this->state->save( $item->connectionId, $item->entityType, $item->entityId, $hash, $mappingVersion );
		}
		$this->idempotency->markDone( $item->idempotencyKey, $ref->id );
		$duplicate = str_replace( array( '{system}', '{ref}' ), array( $connector->label(), $ref->ref ), ErrorCode::Dup001->template() );
		$this->queue->markSynced( $item->id, $ref->id, $ref->ref, $ref->url, $mappingVersion, $alreadyExisted ? ErrorCode::Dup001->value : null, true, $alreadyExisted ? $duplicate : null );
		$this->breaker->recordSuccess( $connection, $ms );

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
			)
		);
		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
		}
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
	 */
	private function handleFailure( Item $item, Connection $connection, ConnectorInterface $connector, ConnectorError $error, int $ms, ?array $payload, ?int $mappingVersion, bool $sent ): Outcome {
		$decision = $this->classifier->classify( $error, $connector, $connector->label() );
		$failure  = $decision->error;
		$message  = $failure->message();
		$cause    = $this->classifier->cause( $failure );

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
			)
		);

		switch ( $decision->action ) {
			case FailureAction::PauseSignIn:
				$this->pause->pauseForRefusedSignIn( $connection->id, $connector->label() );
				$this->queue->release( $item->id, null, $failure->code->value, $message );
				$outcome = Outcome::Held;
				break;
			case FailureAction::NeedsReconnect:
				$this->pause->markNeedsReconnect( $connection->id, $failure->code, $connector->label() );
				$this->queue->release( $item->id, null, $failure->code->value, $message );
				$outcome = Outcome::Held;
				break;
			case FailureAction::Retry:
				$tries = $item->attempt + 1;
				$next  = $tries >= $item->maxAttempts ? null : $this->retry->nextAttemptAt( $tries, $connection->settings, $decision->retryAfter );
				$this->breaker->recordRetryableFailure( $connection, $decision->countsForBreaker );
				if ( null === $next ) {
					$this->queue->markFailed( $item->id, $failure->code->value, $message, $cause );
					do_action( 'estasoft_sync_item_failed', $item, $failure, false );
					$outcome = Outcome::Failed;
				} else {
					$this->queue->markRetrying( $item->id, $next, $failure->code->value, $message, $cause );
					do_action( 'estasoft_sync_item_failed', $item, $failure, true );
					$outcome = Outcome::Retrying;
				}
				break;
			default:
				$this->queue->markFailed( $item->id, $failure->code->value, $message, $cause, $sent );
				if ( $sent ) {
					$this->connections->recordFailure( $connection->id, $ms );
					$this->breaker->recordReachable( $connection );
				}
				do_action( 'estasoft_sync_item_failed', $item, $failure, false );
				$outcome = Outcome::Failed;
		}

		if ( null !== $log_id ) {
			$this->queue->setLastLog( $item->id, $log_id );
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
			$found = $this->guard( fn() => $connector->findExisting( $connection, $item ), $connector );
		}
		$ref = $found instanceof ExternalRef ? $found : null;
		$this->queue->markSynced( $item->id, $ref?->id ?? '', $ref?->ref ?? '', $ref?->url, $built->mappingVersion, null, true );
		$log_id = $this->logger->info(
			__( 'Shadow check: the payload was built and nothing was sent.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'connection_id'   => $item->connectionId,
				'queue_id'        => $item->id,
				'entity_ref'      => $this->reference( $item ),
				'mapping_version' => $built->mappingVersion,
				'request'         => $built->payload,
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
			$this->queue->release( $item->id, null, ErrorCode::Cfg002->value, ErrorCode::Cfg002->template() );
		} else {
			$this->pause->holdForPerson( $item->connectionId, ErrorCode::Auth002, ConnectionStatus::NeedsReconnect, '', $e->getMessage() );
			$this->queue->release( $item->id, null, null, $e->getMessage() );
		}
		return Outcome::Held;
	}

	/**
	 * The connector of this connection is not installed (an add-on was deactivated). The item waits.
	 *
	 * @param Item       $item       Item.
	 * @param Connection $connection Connection.
	 */
	private function holdWithoutConnector( Item $item, Connection $connection ): Outcome {
		$next = Time::sql( Time::plus( $this->clock->now(), self::MISSING_CONNECTOR_WAIT ) );
		$this->queue->release( $item->id, $next, ErrorCode::Pause001->value, ErrorCode::Pause001->template() );
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
