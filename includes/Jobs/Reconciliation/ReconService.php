<?php
/**
 * The nightly check: WooCommerce against the connected system, read-only.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Reconciliation;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Repositories\ReconRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Money;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;
use Estasoft\SyncEngine\Jobs\Support\StoreTime;

/**
 * REC-1 to REC-6.
 *
 * A run compares paid orders created in the window with what the system holds under the client reference `WC-{id}`:
 * existence, total (within the tolerance, in minor units of the order currency), refunds and cancelled status. Nothing is written
 * to either system by a run; each difference is stored (REC-3) and a person repairs or ignores it, except orders missing in the
 * system, which are queued again by themselves when the setting is on (REC-5). Amounts and refunds always wait for a person.
 *
 * Only orders the plugin should have sent are compared: those synced, and (after go-live) those it has not touched yet. Queued,
 * retrying, failed, skipped and shadow orders are left out, and so is everything placed before go-live.
 *
 * Bounded work: the system is read once per run; the comparison then walks the orders in chunks of 100 and stops after the time
 * budget (15 seconds), keeping its place in the option `estasoft_sync_recon_progress`. `run()` returns the progress; the same
 * run continues from the single event `estasoft_sync_recon_continue` and from the hourly tick, so no request exceeds the budget.
 * The system's answer for the run is kept in the option `estasoft_sync_recon_system` until the run ends.
 *
 * Only one request advances a run at a time: the tick, the continue event and a manual run take a short lock (the option
 * `estasoft_sync_recon_lock`, which expires), and a request that finds it taken returns the run as it stands. Each difference
 * carries the connection it was found in (`connection_id`), and a repair goes there, never to "the first
 * connection". No repair, manual or automatic, happens while a shadow window is open on that connection. Copying a cancellation
 * into WooCommerce first checks that the order still has the status the check saw.
 */
final class ReconService {

	/**
	 * Option: place of the run in progress.
	 */
	public const PROGRESS = 'estasoft_sync_recon_progress';

	/**
	 * Option: the system's answer for the run in progress.
	 */
	public const SYSTEM = 'estasoft_sync_recon_system';

	/**
	 * Option: the run lock (a token and the time it expires).
	 */
	public const LOCK = 'estasoft_sync_recon_lock';

	/**
	 * Orders compared per chunk.
	 */
	public const CHUNK = 100;

	/**
	 * Seconds one call may spend.
	 */
	public const BUDGET_SECONDS = 15;

	/**
	 * Most orders in one run, and most records kept from the system.
	 */
	public const MAX_ORDERS = 20000;

	/**
	 * Hours after which a run that never finished is given up.
	 */
	private const STALE_HOURS = 3;

	/**
	 * Names the person who acted: function( int $userId ): string.
	 *
	 * @var \Closure
	 */
	private \Closure $userName;

	/**
	 * Schedules the continuation of a run: function(): void.
	 *
	 * @var \Closure
	 */
	private \Closure $scheduleContinue;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ReconRepository      $recon       Runs and differences.
	 * @param ReconSettings        $settings    Nightly check settings.
	 * @param ConnectionRepository $connections Connections.
	 * @param ConnectorGateway     $gateway     The door to connectors.
	 * @param ShopOrders           $orders      WooCommerce orders.
	 * @param ShopEnvironment      $environment Store time zone.
	 * @param Enqueuer             $enqueuer    Queue entry point (repairs).
	 * @param IdMapRepository      $idMap       Links to the system's records.
	 * @param StateRepository      $state       Hashes of what was sent.
	 * @param OnboardingState      $onboarding  Go-live time.
	 * @param OptionStore          $options     Options (progress).
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $userName    Names a user id; WordPress by default.
	 * @param \Closure|null        $scheduleContinue Schedules the continuation; a single WordPress event by default.
	 * @param int                  $budget      Seconds one call may spend.
	 */
	public function __construct(
		private readonly ReconRepository $recon,
		private readonly ReconSettings $settings,
		private readonly ConnectionRepository $connections,
		private readonly ConnectorGateway $gateway,
		private readonly ShopOrders $orders,
		private readonly ShopEnvironment $environment,
		private readonly Enqueuer $enqueuer,
		private readonly IdMapRepository $idMap,
		private readonly StateRepository $state,
		private readonly OnboardingState $onboarding,
		private readonly OptionStore $options,
		private readonly Logger $logger,
		private readonly Clock $clock,
		?\Closure $userName = null,
		?\Closure $scheduleContinue = null,
		private readonly int $budget = self::BUDGET_SECONDS
	) {
		$this->userName         = $userName ?? static function ( int $userId ): string {
			$user = get_userdata( $userId );
			/* translators: %d: user id. */
			return false === $user ? sprintf( __( 'User #%d', 'estasoft-erp-crm-sync-engine' ), $userId ) : (string) $user->display_name;
		};
		$this->scheduleContinue = $scheduleContinue ?? static function (): void {
			wp_schedule_single_event( time() + 15, 'estasoft_sync_recon_continue' );
		};
	}

	/**
	 * The Reconciliation screen (the `ReconOverview` of src/api/types.ts).
	 *
	 * @param int|null $connectionId Reserved: Phase 1 has one connection per system and the lists are not split by connection.
	 * @return array<string,mixed>
	 */
	public function overview( ?int $connectionId = null ): array {
		unset( $connectionId );
		$settings = $this->settings->get();
		return array(
			'last_run'            => $this->recon->lastRun(),
			'runs'                => $this->recon->runs( 30 ),
			'diffs'               => $this->recon->diffs( null, null, 200 ),
			'schedule_time'       => $settings['schedule_time'],
			'window_days'         => $settings['window_days'],
			'tolerance_minor'     => $settings['tolerance_minor'],
			'auto_repair_missing' => $settings['auto_repair_missing'],
		);
	}

	/**
	 * Save the nightly check settings.
	 *
	 * @param array<string,mixed> $settings schedule_time, window_days, tolerance_minor, auto_repair_missing.
	 * @return array<string,mixed> The overview after saving.
	 *
	 * @throws ValidationException With one message per bad field.
	 */
	public function saveSettings( array $settings ): array {
		$this->settings->save( $settings );
		return $this->overview();
	}

	/**
	 * Start a run, or carry on with the one in progress, for up to the time budget.
	 *
	 * @param int      $windowDays   Days to compare (1 to 30).
	 * @param int|null $connectionId The connection, or null for the first one that can.
	 * @param string   $trigger      manual or nightly.
	 * @return array<string,mixed> A `ReconRun`, with `progress` (done, total) added; status is `running` until the run is complete.
	 *
	 * @throws JobException With status 409 when no connected system can be compared.
	 */
	public function run( int $windowDays, ?int $connectionId = null, string $trigger = 'manual' ): array {
		$token = $this->lock();
		if ( null === $token ) {
			// Another request is working on the check right now (the tick, the continue event or a person). It carries on by itself.
			$running = $this->progress();
			if ( null !== $running ) {
				return $this->runShape( $running, 'running' );
			}
			throw new JobException( __( 'A check is starting right now. Wait a minute and open this page again.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		try {
			$progress = $this->progress();
			if ( null !== $progress && $this->stale( $progress ) ) {
				$this->giveUp( $progress );
				$progress = null;
			}
			if ( null === $progress ) {
				$progress = $this->start( max( ReconSettings::WINDOW[0], min( ReconSettings::WINDOW[1], $windowDays ) ), $connectionId, $trigger );
				if ( 'failed' === ( $progress['status'] ?? '' ) ) {
					return (array) $this->recon->findRun( (int) $progress['run_id'] );
				}
			}
			return $this->advance( $progress );
		} finally {
			$this->unlock( $token );
		}
	}

	/**
	 * Carry on with the run in progress (what the single event and the tick call). Only one request advances a run at a time: when
	 * another holds the run lock this returns the run as it stands.
	 *
	 * @return array<string,mixed>|null The run, or null when nothing is in progress.
	 */
	public function continueRun(): ?array {
		$progress = $this->progress();
		if ( null === $progress ) {
			return null;
		}
		$token = $this->lock();
		if ( null === $token ) {
			return $this->runShape( $progress, 'running' );
		}
		try {
			// Read again under the lock: the request that held it may have finished the run.
			$progress = $this->progress();
			if ( null === $progress ) {
				return null;
			}
			if ( $this->stale( $progress ) ) {
				$this->giveUp( $progress );
				return null;
			}
			return $this->advance( $progress );
		} finally {
			$this->unlock( $token );
		}
	}

	/**
	 * The hourly tick: run once a day, after the store-time hour in the settings, unless a run already exists for the date.
	 *
	 * @return array<string,mixed> ran (bool) and, when it ran, the run.
	 */
	public function tick(): array {
		if ( null !== $this->progress() ) {
			$run = $this->continueRun();
			return null === $run ? array( 'ran' => false ) : array(
				'ran' => true,
				'run' => $run,
			);
		}
		$settings = $this->settings->get();
		$zone     = $this->environment->timezone();
		$now      = $this->clock->now();
		$today    = StoreTime::date( $now, $zone );
		if ( ! StoreTime::reached( $now, $zone, $settings['schedule_time'] ) || $settings['last_tick_date'] === $today ) {
			return array( 'ran' => false );
		}
		$last = $this->recon->lastRun();
		if ( null !== $last && StoreTime::date( new \DateTimeImmutable( (string) $last['started_at'] ), $zone ) === $today ) {
			$this->settings->markTick( $today );
			return array( 'ran' => false );
		}
		$this->settings->markTick( $today );
		try {
			return array(
				'ran' => true,
				'run' => $this->run( $settings['window_days'], null, 'nightly' ),
			);
		} catch ( JobException $e ) {
			return array(
				'ran'    => false,
				'reason' => $e->getMessage(),
			);
		}
	}

	/**
	 * Repair a difference in the direction the person chose (REC-4).
	 *
	 * `wc_to_system` queues the order (or its refunds) again so the system gets what WooCommerce has. `system_to_wc` is allowed
	 * for a status difference only: a cancellation in the system cancels the order in WooCommerce. Amounts are never copied
	 * into WooCommerce.
	 *
	 * @param int    $diffId    Difference id.
	 * @param string $direction wc_to_system or system_to_wc.
	 * @param int    $userId    Who acts.
	 * @return array<string,mixed> The `ReconDiff` after the repair.
	 *
	 * @throws ValidationException When the direction is not possible for this kind of difference.
	 * @throws JobException        With status 404 when the difference does not exist, 409 when it is already closed.
	 */
	public function repair( int $diffId, string $direction, int $userId ): array {
		$diff = $this->openDiff( $diffId );
		if ( ! in_array( $direction, array( 'wc_to_system', 'system_to_wc' ), true ) ) {
			throw new ValidationException( array( 'direction' => __( 'Choose wc_to_system or system_to_wc.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$connectionId = $this->diffConnection( $diff );
		if ( $this->shadowOpen( $connectionId ) ) {
			throw new JobException( __( 'Shadow mode is on for this connection, so nothing is repaired. End shadow mode first.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		$orderId = (int) $diff['order_id'];
		if ( 'system_to_wc' === $direction ) {
			$this->copyStatus( $diff, $orderId );
		} else {
			$this->requeue( $connectionId, $orderId, (string) $diff['type'], (string) $diff['order_label'] );
		}
		$by = ( $this->userName )( $userId );
		$this->recon->markRepaired( $diffId, $by );
		$this->logger->info(
			sprintf(
				/* translators: 1: order number, 2: name of the person. */
				__( 'Difference on order #%1$s repaired by %2$s.', 'estasoft-erp-crm-sync-engine' ),
				(string) $diff['order_label'],
				$by
			),
			array(
				'connection_id' => $connectionId,
				'entity_ref'    => '#' . (string) $diff['order_label'],
			)
		);
		return (array) $this->recon->diff( $diffId );
	}

	/**
	 * Ignore a difference, with a reason (REC-4).
	 *
	 * @param int    $diffId Difference id.
	 * @param string $reason Why.
	 * @param int    $userId Who acts.
	 * @return array<string,mixed> The `ReconDiff` after ignoring it.
	 *
	 * @throws ValidationException When there is no reason.
	 * @throws JobException        With status 404 when the difference does not exist, 409 when it is already closed.
	 */
	public function ignore( int $diffId, string $reason, int $userId ): array {
		$this->openDiff( $diffId );
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new ValidationException( array( 'reason' => __( 'Say why you are ignoring this difference.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$this->recon->ignore( $diffId, mb_substr( $reason, 0, 250 ), ( $this->userName )( $userId ) );
		return (array) $this->recon->diff( $diffId );
	}

	/**
	 * Start a run: read the system once and list the orders to compare.
	 *
	 * @param int      $windowDays   Days.
	 * @param int|null $connectionId Connection or null.
	 * @param string   $trigger      manual or nightly.
	 * @return array<string,mixed> The new progress (status `failed` when the system could not be read).
	 *
	 * @throws JobException With status 409 when no connected system can be compared.
	 */
	private function start( int $windowDays, ?int $connectionId, string $trigger ): array {
		$targets = $this->gateway->targets( Capability::Reconciliation, $connectionId );
		if ( array() === $targets ) {
			throw new JobException( __( 'There is no connected system to compare with. Connect one, or resume the paused connection.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		$connection = $targets[0]['connection'];
		$connector  = $targets[0]['connector'];
		$now        = $this->clock->now();
		$from       = $this->windowStart( $windowDays, $now );
		$runId      = $this->recon->startRun( $connection->id, $windowDays );

		$answer = $this->gateway->call( $connection, $connector, fn() => $connector->listForReconcile( $connection, $from ) );
		if ( $answer instanceof ConnectorError || ! is_array( $answer ) ) {
			$code = $answer instanceof ConnectorError ? $answer->code->value : ErrorCode::Val002->value;
			$this->recon->finishRun( $runId, 0, 0, 0, 0, 'failed', $code );
			return array(
				'run_id' => $runId,
				'status' => 'failed',
			);
		}
		$system = array();
		foreach ( $answer as $reference => $ref ) {
			if ( count( $system ) >= self::MAX_ORDERS ) {
				break;
			}
			if ( $ref instanceof ExternalRef ) {
				$system[ (string) $reference ] = array(
					'id'        => $ref->id,
					'ref'       => $ref->ref,
					'total'     => (int) ( $ref->extra['total_minor'] ?? 0 ),
					'has_total' => isset( $ref->extra['total_minor'] ),
					'refunded'  => (int) ( $ref->extra['refunded_minor'] ?? 0 ),
					'currency'  => strtoupper( (string) ( $ref->extra['currency'] ?? '' ) ),
					'state'     => strtolower( (string) ( $ref->extra['state'] ?? '' ) ),
				);
			}
		}
		$ids = array();
		for ( $page = 1; count( $ids ) < self::MAX_ORDERS; ++$page ) {
			$batch = $this->orders->idsSince( $from, $page, 500 );
			$ids   = array_merge( $ids, $batch['ids'] );
			if ( ! $batch['has_more'] || array() === $batch['ids'] ) {
				break;
			}
		}
		$known = array();
		foreach ( $this->recon->diffs( 'open', null, 1000 ) as $open ) {
			$known[ $open['order_id'] . ':' . $open['type'] ] = (int) $open['id'];
		}
		$progress = array(
			'run_id'        => $runId,
			'connection_id' => $connection->id,
			'window_days'   => $windowDays,
			'trigger'       => $trigger,
			'started_at'    => $now->format( Time::ISO ),
			'order_ids'     => array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_ORDERS ),
			'cursor'        => 0,
			'compared'      => 0,
			'matched'       => 0,
			'differences'   => 0,
			'repaired'      => 0,
			'known'         => $known,
			'status'        => 'running',
		);
		$orderIds = $progress['order_ids'];
		unset( $progress['order_ids'] );
		$progress['total'] = count( $orderIds );
		$this->options->set(
			self::SYSTEM,
			array(
				'records' => $system,
				'ids'     => $orderIds,
			)
		);
		$this->options->set( self::PROGRESS, $progress );
		return $progress;
	}

	/**
	 * Compare chunks of orders until the run is complete or the time budget is used.
	 *
	 * @param array<string,mixed> $progress Place of the run.
	 * @return array<string,mixed> The `ReconRun` with `progress`.
	 */
	private function advance( array $progress ): array {
		$started   = $this->clock->now();
		$work      = $this->options->get( self::SYSTEM, array() );
		$system    = is_array( $work ) && is_array( $work['records'] ?? null ) ? $work['records'] : array();
		$orderIds  = is_array( $work ) && is_array( $work['ids'] ?? null ) ? $work['ids'] : array();
		$settings  = $this->settings->get();
		$tolerance = (int) $settings['tolerance_minor'];
		$wentLive  = $this->wentLiveAt();
		$total     = min( (int) $progress['total'], count( $orderIds ) );
		// Automatic repair queues orders; while a shadow window is open on the connection nothing may be queued for real.
		$autoRepair = (bool) $settings['auto_repair_missing'] && ! $this->shadowOpen( (int) $progress['connection_id'] );

		while ( (int) $progress['cursor'] < $total && $this->elapsed( $started ) < $this->budget ) {
			$ids = array_slice( $orderIds, (int) $progress['cursor'], self::CHUNK );
			foreach ( $this->orders->summaries( $ids ) as $order ) {
				if ( ! $this->expected( $order, $wentLive ) ) {
					continue;
				}
				++$progress['compared'];
				$found = $this->differences( $order, $system[ 'WC-' . $order['id'] ] ?? null, $tolerance );
				if ( array() === $found ) {
					++$progress['matched'];
					continue;
				}
				foreach ( $found as $difference ) {
					$this->record( $progress, $order, $difference, $autoRepair );
				}
			}
			$progress['cursor'] = (int) $progress['cursor'] + count( $ids );
			$this->options->set( self::PROGRESS, $progress );
		}

		if ( (int) $progress['cursor'] < $total ) {
			( $this->scheduleContinue )();
			return $this->runShape( $progress, 'running' );
		}
		return $this->finish( $progress );
	}

	/**
	 * Store one difference, and queue the order again when it is missing and automatic repair is on.
	 *
	 * @param array<string,mixed> $progress   Place of the run (updated).
	 * @param array<string,mixed> $order      Order summary.
	 * @param array<string,string> $difference type, wc_value, system_value.
	 * @param bool                $autoRepair Whether orders missing in the system are queued again by themselves.
	 */
	private function record( array &$progress, array $order, array $difference, bool $autoRepair ): void {
		++$progress['differences'];
		$key = $order['id'] . ':' . $difference['type'];
		$id  = $progress['known'][ $key ] ?? null;
		if ( null === $id ) {
			$id                      = $this->recon->addDiff( (int) $progress['run_id'], (int) $progress['connection_id'], (int) $order['id'], (string) $order['label'], $difference['type'], $difference['wc_value'], $difference['system_value'] );
			$progress['known'][ $key ] = $id;
		}
		if ( $autoRepair && 'missing_in_system' === $difference['type'] && $id > 0 ) {
			try {
				$this->requeue( (int) $progress['connection_id'], (int) $order['id'], 'missing_in_system', (string) $order['label'] );
				$this->recon->markRepaired( $id, __( 'Automatic repair', 'estasoft-erp-crm-sync-engine' ) );
				++$progress['repaired'];
			} catch ( JobException $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Close the run: save the counts, drop the work files, announce it.
	 *
	 * @param array<string,mixed> $progress Place of the run.
	 * @return array<string,mixed> The `ReconRun`.
	 */
	private function finish( array $progress ): array {
		$this->recon->finishRun( (int) $progress['run_id'], (int) $progress['compared'], (int) $progress['matched'], (int) $progress['differences'], (int) $progress['repaired'] );
		$this->options->delete( self::PROGRESS );
		$this->options->delete( self::SYSTEM );
		$run = (array) $this->recon->findRun( (int) $progress['run_id'] );
		$n   = (int) $progress['differences'];
		if ( $n > 0 ) {
			$this->logger->info(
				sprintf(
					/* translators: %d: number of differences found. */
					_n( 'The nightly check found %d difference.', 'The nightly check found %d differences.', $n, 'estasoft-erp-crm-sync-engine' ),
					$n
				),
				array(
					'connection_id' => (int) $progress['connection_id'],
					'code'          => ErrorCode::Recon001,
				)
			);
		} else {
			$this->logger->info(
				__( 'The check found no differences.', 'estasoft-erp-crm-sync-engine' ),
				array( 'connection_id' => (int) $progress['connection_id'] )
			);
		}
		/**
		 * Fires when a reconciliation run has finished.
		 *
		 * @param array<string,mixed> $run The `ReconRun` of src/api/types.ts.
		 */
		do_action( 'estasoft_sync_reconciliation_finished', $run );
		return $run + array(
			'progress' => array(
				'done'  => (int) $progress['cursor'],
				'total' => (int) $progress['total'],
			),
		);
	}

	/**
	 * The differences between an order and the system's record.
	 *
	 * @param array<string,mixed>      $order     Order summary.
	 * @param array<string,mixed>|null $system    The system's record, or null when it has none.
	 * @param int                      $tolerance Minor units that count as equal.
	 * @return array<int,array{type:string,wc_value:string,system_value:string}>
	 */
	private function differences( array $order, ?array $system, int $tolerance ): array {
		$currency = (string) $order['currency'];
		$total    = $this->amount( (int) $order['total_minor'], $currency );
		if ( null === $system ) {
			return array(
				array(
					'type'         => 'missing_in_system',
					'wc_value'     => $total,
					'system_value' => __( 'Not found', 'estasoft-erp-crm-sync-engine' ),
				),
			);
		}
		$found = array();
		if ( ! empty( $system['has_total'] ) ) {
			$sameCurrency = '' === $system['currency'] || $system['currency'] === $currency;
			if ( ! $sameCurrency || abs( (int) $order['total_minor'] - (int) $system['total'] ) > $tolerance ) {
				$found[] = array(
					'type'         => 'total_differs',
					'wc_value'     => $total,
					'system_value' => $this->amount( (int) $system['total'], '' === $system['currency'] ? $currency : $system['currency'] ),
				);
			}
		}
		if ( (int) $order['refunded_minor'] > 0 && ( (int) $system['refunded'] + $tolerance ) < (int) $order['refunded_minor'] ) {
			$found[] = array(
				'type'         => 'refund_missing',
				'wc_value'     => $this->amount( (int) $order['refunded_minor'], $currency ),
				'system_value' => $this->amount( (int) $system['refunded'], $currency ),
			);
		}
		if ( in_array( $system['state'], array( 'cancel', 'cancelled', 'canceled' ), true ) && ! in_array( (string) $order['status'], array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$found[] = array(
				'type'         => 'status_differs',
				'wc_value'     => (string) $order['status'],
				'system_value' => 'cancel',
			);
		}
		return $found;
	}

	/**
	 * Whether the plugin should have sent this order, so its absence is a difference.
	 *
	 * @param array<string,mixed> $order    Order summary.
	 * @param string|null         $wentLive When the setup went live (UTC "Y-m-d H:i:s"), or null before that.
	 */
	private function expected( array $order, ?string $wentLive ): bool {
		$state = (string) $order['sync_state'];
		if ( 'synced' === $state ) {
			return true;
		}
		return '' === $state && null !== $wentLive && '' !== (string) $order['created_at'] && (string) $order['created_at'] >= $wentLive;
	}

	/**
	 * Queue an order (or its refunds) again so the system receives it.
	 *
	 * @param int    $connectionId Connection.
	 * @param int    $orderId      Order id.
	 * @param string $type         The kind of difference.
	 * @param string $label        Order number.
	 *
	 * @throws JobException When nothing could be queued.
	 */
	private function requeue( int $connectionId, int $orderId, string $type, string $label ): void {
		$order = $this->orders->find( $orderId );
		if ( null === $order ) {
			throw new JobException( __( 'That order no longer exists in WooCommerce.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		$meta = array(
			'label'       => $label,
			'customer'    => (string) $order['customer'],
			'total_minor' => (int) $order['total_minor'],
			'currency'    => (string) $order['currency'],
			'search'      => (string) $order['email'],
		);
		if ( 'refund_missing' === $type ) {
			$queued = false;
			foreach ( $this->orders->refundIds( $orderId ) as $refundId ) {
				$this->idMap->forget( $connectionId, EntityType::Refund, (string) $refundId );
				$this->state->forget( $connectionId, EntityType::Refund, (string) $refundId );
				$queued = null !== $this->enqueuer->enqueue( EntityType::Refund, (string) $refundId, 'updated', $connectionId, array( 'partition' => 'order:' . $orderId ) + $meta ) || $queued;
			}
			if ( ! $queued ) {
				throw new JobException( __( 'There is no refund to send again.', 'estasoft-erp-crm-sync-engine' ), 409 );
			}
			return;
		}
		if ( 'missing_in_system' === $type ) {
			$this->idMap->forget( $connectionId, EntityType::Order, (string) $orderId );
		}
		$this->state->forget( $connectionId, EntityType::Order, (string) $orderId );
		if ( null === $this->enqueuer->enqueue( EntityType::Order, (string) $orderId, 'updated', $connectionId, $meta ) ) {
			throw new JobException( __( 'The order could not be added to the queue.', 'estasoft-erp-crm-sync-engine' ), 500 );
		}
	}

	/**
	 * Copy the system's status into WooCommerce (a cancellation only).
	 *
	 * @param array<string,mixed> $diff    The difference.
	 * @param int                 $orderId Order id.
	 *
	 * @throws ValidationException When this kind of difference cannot be copied from the system.
	 */
	private function copyStatus( array $diff, int $orderId ): void {
		if ( 'status_differs' !== $diff['type'] || 'cancel' !== strtolower( (string) $diff['system_value'] ) ) {
			throw new ValidationException( array( 'direction' => __( 'Only a cancellation in the system can be copied into WooCommerce. Amounts and refunds are never copied.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		// The check may be hours old. Cancel only an order that still has the status the check saw.
		$order = $this->orders->find( $orderId );
		if ( null === $order ) {
			throw new JobException( __( 'That order no longer exists in WooCommerce.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		$now = strtolower( (string) preg_replace( '/^wc-/', '', (string) $order['status'] ) );
		$was = strtolower( (string) preg_replace( '/^wc-/', '', (string) $diff['wc_value'] ) );
		if ( $now !== $was ) {
			throw new JobException(
				sprintf(
					/* translators: 1: status of the order now, 2: status the check saw. */
					__( 'The order is "%1$s" in WooCommerce now, not "%2$s" as when the check ran, so it was not cancelled. Run the check again.', 'estasoft-erp-crm-sync-engine' ),
					$now,
					$was
				),
				409
			);
		}
		if ( ! $this->orders->updateStatus( $orderId, 'cancelled', __( 'Cancelled to match the connected system (reconciliation).', 'estasoft-erp-crm-sync-engine' ) ) ) {
			throw new JobException( __( 'The order could not be cancelled. It may already be cancelled.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
	}

	/**
	 * An open difference.
	 *
	 * @param int $diffId Difference id.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404 or 409.
	 */
	private function openDiff( int $diffId ): array {
		$diff = $this->recon->diff( $diffId );
		if ( null === $diff ) {
			throw new JobException( __( 'That difference does not exist.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		if ( 'open' !== $diff['status'] ) {
			throw new JobException( __( 'That difference was already dealt with.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		return $diff;
	}

	/**
	 * The connection a difference was found in, which is where its repair goes. Differences found before this was recorded fall back
	 * to the only connected system, and are refused when there are several.
	 *
	 * @param array<string,mixed> $diff The difference.
	 *
	 * @throws JobException 409 when the system is gone, or cannot be told.
	 */
	private function diffConnection( array $diff ): int {
		$stored = (int) ( $diff['connection_id'] ?? 0 );
		if ( $stored > 0 ) {
			$connection = $this->connections->findWithoutSecrets( $stored );
			if ( null === $connection || ConnectionStatus::Disconnected === $connection->status ) {
				throw new JobException( __( 'The system this difference was found in is no longer connected.', 'estasoft-erp-crm-sync-engine' ), 409 );
			}
			return $stored;
		}
		throw new JobException( __( 'It is not known which system this difference belongs to. Run the check again, then repair the new difference.', 'estasoft-erp-crm-sync-engine' ), 409 );
	}

	/**
	 * Whether a shadow window is open on a connection. The window is the plugin's one shadow window; it belongs to the connection
	 * chosen when it was opened (any connection when none was recorded).
	 *
	 * @param int $connectionId Connection.
	 */
	private function shadowOpen( int $connectionId ): bool {
		if ( ! $this->onboarding->isShadowActive( $this->clock->now() ) ) {
			return false;
		}
		$chosen = (int) ( $this->onboarding->get()['answers']['shadow_connection'] ?? 0 );
		return 0 === $chosen || $chosen === $connectionId;
	}

	/**
	 * Take the run lock.
	 *
	 * @return string|null A token to hand back to unlock(), or null when another request holds the lock.
	 */
	private function lock(): ?string {
		$now    = $this->clock->now()->getTimestamp();
		$stored = $this->options->get( self::LOCK, null );
		if ( is_array( $stored ) && (int) ( $stored['until'] ?? 0 ) > $now ) {
			return null;
		}
		$token = bin2hex( random_bytes( 8 ) );
		$this->options->set(
			self::LOCK,
			array(
				'token' => $token,
				'until' => $now + $this->budget + 60,
			)
		);
		// Read it back: if another request wrote at the same moment, only the one whose token is stored goes on.
		$check = $this->options->get( self::LOCK, null );
		return is_array( $check ) && ( $check['token'] ?? '' ) === $token ? $token : null;
	}

	/**
	 * Release the run lock, if it is still ours.
	 *
	 * @param string $token Token from lock().
	 */
	private function unlock( string $token ): void {
		$stored = $this->options->get( self::LOCK, null );
		if ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) {
			$this->options->delete( self::LOCK );
		}
	}

	/**
	 * Start of the window, not before go-live.
	 *
	 * @param int                $windowDays Days.
	 * @param \DateTimeImmutable $now        Current moment.
	 */
	private function windowStart( int $windowDays, \DateTimeImmutable $now ): \DateTimeImmutable {
		$from     = Time::plus( $now, -$windowDays * 86400 );
		$wentLive = $this->wentLiveAt();
		if ( null !== $wentLive ) {
			$live = Time::fromSql( $wentLive );
			if ( null !== $live && $live > $from ) {
				return $live;
			}
		}
		return $from;
	}

	/**
	 * When the setup went live, as a MySQL datetime.
	 */
	private function wentLiveAt(): ?string {
		$answers = $this->onboarding->get()['answers'];
		$at      = isset( $answers['went_live_at'] ) ? date_create_immutable( (string) $answers['went_live_at'] ) : false;
		return false === $at ? null : Time::sql( $at );
	}

	/**
	 * The run in progress.
	 *
	 * @return array<string,mixed>|null
	 */
	private function progress(): ?array {
		$stored = $this->options->get( self::PROGRESS, null );
		return is_array( $stored ) && isset( $stored['run_id'] ) ? $stored : null;
	}

	/**
	 * Whether a run was started so long ago that it will not finish.
	 *
	 * @param array<string,mixed> $progress Place of the run.
	 */
	private function stale( array $progress ): bool {
		$started = date_create_immutable( (string) ( $progress['started_at'] ?? '' ) );
		return false === $started || ( $this->clock->now()->getTimestamp() - $started->getTimestamp() ) > self::STALE_HOURS * 3600;
	}

	/**
	 * Give up a run that never finished.
	 *
	 * @param array<string,mixed> $progress Place of the run.
	 */
	private function giveUp( array $progress ): void {
		$this->recon->finishRun( (int) $progress['run_id'], (int) $progress['compared'], (int) $progress['matched'], (int) $progress['differences'], (int) $progress['repaired'], 'failed' );
		$this->options->delete( self::PROGRESS );
		$this->options->delete( self::SYSTEM );
	}

	/**
	 * A run in progress as a `ReconRun`.
	 *
	 * @param array<string,mixed> $progress Place of the run.
	 * @param string              $status   running.
	 * @return array<string,mixed>
	 */
	private function runShape( array $progress, string $status ): array {
		return array(
			'id'          => (int) $progress['run_id'],
			'started_at'  => (string) $progress['started_at'],
			'finished_at' => null,
			'window_days' => (int) $progress['window_days'],
			'compared'    => (int) $progress['compared'],
			'matched'     => (int) $progress['matched'],
			'differences' => (int) $progress['differences'],
			'repaired'    => (int) $progress['repaired'],
			'status'      => $status,
			'progress'    => array(
				'done'  => (int) $progress['cursor'],
				'total' => (int) $progress['total'],
			),
		);
	}

	/**
	 * An amount with its currency, for the two value columns.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO code.
	 */
	private function amount( int $minor, string $currency ): string {
		return trim( Money::toDecimalString( $minor, $currency ) . ' ' . $currency );
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
