<?php
/**
 * Shadow mode: what would have been sent, compared with what the system already has.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Shadow;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingView;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconSettings;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;

/**
 * SHD-1 to SHD-3.
 *
 * While the shadow window is open the queue builds every payload with the live mapping and stores it with mode `shadow`;
 * nothing is sent, and the stock and tracking jobs only count. This service owns the window (start and end) and the report:
 * for each shadow order it asks the connector, read-only, for the system's own record under the same client reference and marks
 * the order Matches, Differs (listing the fields) or Not found. Fields compared are the total (within the reconciliation
 * tolerance), the currency and the customer's e-mail, where the system reports them.
 *
 * The window never closes by itself. Day 7 is when the owner is asked; `end()` or going live closes it.
 * Verdicts are kept in the option `estasoft_sync_shadow_verdicts` (at most 500) so opening the report does not ask the system
 * about every order again; each call to `report()` or `compare()` refreshes a limited number of orders within a time budget, and
 * verdicts are final once the window is closed.
 */
final class ShadowService {

	/**
	 * Option: verdict per queue item.
	 */
	public const VERDICTS = 'estasoft_sync_shadow_verdicts';

	/**
	 * Most verdicts kept.
	 */
	public const VERDICT_CAP = 500;

	/**
	 * Seconds a verdict is trusted before the order is compared again.
	 */
	public const VERDICT_TTL = 21600;

	/**
	 * Orders the report asks the system about in one call.
	 */
	public const REPORT_BATCH = 25;

	/**
	 * Seconds one `compare()` call may spend asking the system (the same budget as a reconciliation run).
	 */
	public const BUDGET_SECONDS = 15.0;

	/**
	 * Longest shadow window a person can ask for, in days.
	 */
	public const MAX_DAYS = 30;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OnboardingState      $onboarding  Window state.
	 * @param QueueRepository      $queue       Shadow items.
	 * @param ConnectionRepository $connections Connections.
	 * @param ConnectorGateway     $gateway     The door to connectors.
	 * @param ShopOrders           $orders      WooCommerce orders.
	 * @param ReconSettings        $recon       Tolerance.
	 * @param OptionStore          $options     Options (verdicts).
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 */
	public function __construct(
		private readonly OnboardingState $onboarding,
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly ConnectorGateway $gateway,
		private readonly ShopOrders $orders,
		private readonly ReconSettings $recon,
		private readonly OptionStore $options,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * Open the shadow window.
	 *
	 * @param int $connectionId The connection being tried out.
	 * @param int $days         Planned length in days (1 to 30); only shown, the window does not close by itself.
	 * @return array<string,mixed> The `Onboarding` after the change.
	 *
	 * @throws ValidationException When the length is out of range.
	 * @throws JobException        With status 404 when the connection does not exist or is disconnected.
	 */
	public function start( int $connectionId, int $days = 7 ): array {
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			throw new ValidationException(
				array(
					'days' => esc_html(
						sprintf(
							/* translators: %d: longest shadow window in days. */
							__( 'Enter a number of days from 1 to %d.', 'estasoft-enterprise-erpcrm-sync-engine' ),
							self::MAX_DAYS
						)
					),
				)
			);
		}
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Disconnected === $connection->status ) {
			throw new JobException( esc_html__( 'That connection does not exist or is disconnected.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		$now     = $this->clock->now();
		$answers = $this->onboarding->get()['answers'];
		$this->onboarding->save(
			array(
				'connector'         => $connection->connector,
				'shadow_started_at' => $now->format( Time::ISO ),
				'shadow_ends_at'    => null,
				'answers'           => array_replace(
					$answers,
					array(
						'shadow_days'       => $days,
						'shadow_connection' => $connectionId,
					)
				),
			)
		);
		$this->logger->info(
			sprintf(
				/* translators: %d: planned length of the shadow window in days. */
				_n( 'Shadow mode started for %d day. Nothing is sent to your system.', 'Shadow mode started for %d days. Nothing is sent to your system.', $days, 'estasoft-enterprise-erpcrm-sync-engine' ),
				$days
			),
			array( 'connection_id' => $connectionId )
		);
		return OnboardingView::present( $this->onboarding->get() );
	}

	/**
	 * Close the shadow window now. New orders are sent from this moment; shadow items are not sent afterwards.
	 *
	 * @param int    $userId Who ends it.
	 * @param string $reason Why, kept in the state (`person` or `go_live`).
	 * @return array<string,mixed> The `Onboarding` after the change.
	 */
	public function end( int $userId, string $reason = 'person' ): array {
		$state = $this->onboarding->get();
		if ( ! empty( $state['shadow_started_at'] ) && $this->onboarding->isShadowActive( $this->clock->now() ) ) {
			$this->onboarding->endShadow( $this->clock->now(), $reason );
			$this->logger->info(
				sprintf(
					/* translators: %d: user id. */
					__( 'Shadow mode ended by user #%d. New orders are sent to your system.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$userId
				)
			);
		}
		return OnboardingView::present( $this->onboarding->get() );
	}

	/**
	 * The report (the `ShadowReport` of src/api/types.ts).
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed>
	 */
	public function report( ?int $connectionId = null ): array {
		$this->compare( $connectionId, self::REPORT_BATCH );
		$state    = $this->onboarding->get();
		$view     = OnboardingView::present( $state );
		$verdicts = $this->verdicts();
		$rows     = array();
		$counts   = array(
			'matches'   => 0,
			'differs'   => 0,
			'not_found' => 0,
		);
		$total    = 0;
		foreach ( $this->shadowItems( $connectionId ) as $item ) {
			++$total;
			$verdict = $verdicts[ (string) $item['id'] ] ?? null;
			if ( null === $verdict ) {
				continue;
			}
			++$counts[ $verdict['result'] ];
			$rows[] = array(
				'order_id'    => (int) $item['entity_id'],
				'order_label' => '' !== (string) ( $item['entity_label'] ?? '' ) ? (string) $item['entity_label'] : (string) $item['entity_id'],
				'result'      => $verdict['result'],
				'fields'      => $verdict['fields'],
				'queue_id'    => (int) $item['id'],
			);
		}
		$order = array(
			'differs'   => 0,
			'not_found' => 1,
			'matches'   => 2,
		);
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				$byResult = $order[ $a['result'] ] <=> $order[ $b['result'] ];
				return 0 !== $byResult ? $byResult : ( $b['queue_id'] <=> $a['queue_id'] );
			}
		);

		return array(
			'info' => $this->buildInfo( $state, $view, $counts, $total ),
			'rows' => array_slice( $rows, 0, 200 ),
		);
	}

	/**
	 * The summary for the dashboard, from the verdicts already kept (the system is not asked).
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<string,mixed>|null A `ShadowInfo`, or null when no shadow window was ever started.
	 */
	public function info( ?int $connectionId = null ): ?array {
		$state = $this->onboarding->get();
		if ( empty( $state['shadow_started_at'] ) ) {
			return null;
		}
		$verdicts = $this->verdicts();
		$counts   = array(
			'matches'   => 0,
			'differs'   => 0,
			'not_found' => 0,
		);
		$total    = 0;
		foreach ( $this->shadowItems( $connectionId ) as $item ) {
			++$total;
			$verdict = $verdicts[ (string) $item['id'] ] ?? null;
			if ( null !== $verdict ) {
				++$counts[ $verdict['result'] ];
			}
		}
		return $this->buildInfo( $state, OnboardingView::present( $state ), $counts, $total );
	}

	/**
	 * The `ShadowInfo` shape.
	 *
	 * @param array<string,mixed> $state  Stored state.
	 * @param array<string,mixed> $view   Shaped state.
	 * @param array<string,int>   $counts matches, differs, not_found.
	 * @param int                 $total  Shadow orders.
	 * @return array<string,mixed>
	 */
	private function buildInfo( array $state, array $view, array $counts, int $total ): array {
		$started = (string) ( $view['shadow_started_at'] ?? '' );
		$day     = 1;
		if ( '' !== $started ) {
			$day = max( 1, (int) floor( ( $this->clock->now()->getTimestamp() - (int) strtotime( $started ) ) / 86400 ) + 1 );
		}
		$nowIso = $this->clock->now()->format( Time::ISO );
		return array(
			'started_at' => '' === $started ? $nowIso : $started,
			'ends_at'    => (string) ( $view['shadow_ends_at'] ?? ( '' === $started ? $nowIso : $started ) ),
			'day'        => $day,
			'of_days'    => max( 1, (int) ( $state['answers']['shadow_days'] ?? 7 ) ),
			'items'      => $total,
			'matches'    => $counts['matches'],
			'differs'    => $counts['differs'],
			'not_found'  => $counts['not_found'],
		);
	}

	/**
	 * Compare shadow orders with the system's records (read-only), newest first, skipping verdicts that are still fresh.
	 *
	 * It stops when the order limit or the time budget is reached, and keeps each verdict as soon as the system answers, so a
	 * request that is cut off loses nothing. Once the shadow window is closed the verdicts that exist are final: they are not
	 * refreshed, so the system is no longer asked about orders it has already been asked about (orders that never got a verdict
	 * are still compared, a few at a time).
	 *
	 * @param int|null $connectionId   One connection, or null for all.
	 * @param int      $limit          Most orders to ask the system about.
	 * @param float    $budgetSeconds  Most seconds to spend; 0 or less asks about nothing.
	 * @return array{compared:int,errors:int,timed_out:bool}
	 */
	public function compare( ?int $connectionId = null, int $limit = self::REPORT_BATCH, float $budgetSeconds = self::BUDGET_SECONDS ): array {
		$result   = array(
			'compared'  => 0,
			'errors'    => 0,
			'timed_out' => false,
		);
		$started  = $this->clock->now();
		$verdicts = $this->verdicts();
		$now      = $started->getTimestamp();
		$final    = ! $this->onboarding->isShadowActive( $started );
		$tolerate = (int) $this->recon->get()['tolerance_minor'];
		foreach ( $this->gateway->targets( Capability::ShadowLookup, $connectionId ) as $target ) {
			$connection = $target['connection'];
			$connector  = $target['connector'];
			foreach ( $this->shadowItems( $connection->id ) as $row ) {
				if ( $result['compared'] >= $limit ) {
					break 2;
				}
				$key = (string) $row['id'];
				if ( isset( $verdicts[ $key ] ) && ( $final || ( $now - (int) $verdicts[ $key ]['at'] ) < self::VERDICT_TTL ) ) {
					continue;
				}
				if ( $this->elapsed( $started ) >= $budgetSeconds ) {
					$result['timed_out'] = true;
					break 2;
				}
				$item = $this->queue->find( (int) $row['id'] );
				if ( null === $item ) {
					continue;
				}
				$found = $this->gateway->call( $connection, $connector, fn() => $connector->findExisting( $connection, $item ) );
				if ( $found instanceof ConnectorError ) {
					++$result['errors'];
					break;
				}
				++$result['compared'];
				$verdicts[ $key ] = $this->verdict( $found instanceof ExternalRef ? $found : null, $this->orders->find( (int) $row['entity_id'] ), $tolerate, $this->clock->now()->getTimestamp() );
				$this->remember( $key, $verdicts[ $key ] );
			}
		}
		return $result;
	}

	/**
	 * Seconds since a moment.
	 *
	 * @param \DateTimeImmutable $started Start.
	 */
	private function elapsed( \DateTimeImmutable $started ): float {
		return max( 0.0, (float) $this->clock->now()->format( 'U.u' ) - (float) $started->format( 'U.u' ) );
	}

	/**
	 * Keep one verdict right away (read again first, so another request's verdicts are not overwritten).
	 *
	 * @param string                                               $key     Queue item id.
	 * @param array{result:string,fields:array<int,string>,at:int} $verdict Verdict.
	 */
	private function remember( string $key, array $verdict ): void {
		$stored         = $this->verdicts();
		$stored[ $key ] = $verdict;
		$this->saveVerdicts( $stored );
	}

	/**
	 * Decide Matches, Differs or Not found.
	 *
	 * @param ExternalRef|null         $found     The system's record, or null.
	 * @param array<string,mixed>|null $order     The order summary.
	 * @param int                      $tolerance Minor units that count as equal.
	 * @param int                      $at        Unix time of the comparison.
	 * @return array{result:string,fields:array<int,string>,at:int}
	 */
	private function verdict( ?ExternalRef $found, ?array $order, int $tolerance, int $at ): array {
		if ( null === $found ) {
			return array(
				'result' => 'not_found',
				'fields' => array(),
				'at'     => $at,
			);
		}
		$fields = array();
		if ( null !== $order ) {
			$extra = $found->extra;
			if ( isset( $extra['currency'] ) && '' !== (string) $extra['currency'] && strtoupper( (string) $extra['currency'] ) !== $order['currency'] ) {
				$fields[] = 'currency';
			} elseif ( isset( $extra['total_minor'] ) && abs( (int) $extra['total_minor'] - (int) $order['total_minor'] ) > $tolerance ) {
				$fields[] = 'total';
			}
			if ( isset( $extra['email'] ) && '' !== (string) $extra['email'] && '' !== (string) $order['email'] && 0 !== strcasecmp( (string) $extra['email'], (string) $order['email'] ) ) {
				$fields[] = 'email';
			}
		}
		return array(
			'result' => array() === $fields ? 'matches' : 'differs',
			'fields' => $fields,
			'at'     => $at,
		);
	}

	/**
	 * Shadow order items, newest first (the first 500).
	 *
	 * @param int|null $connectionId One connection, or null.
	 * @return array<int,array<string,mixed>> `QueueItem` arrays.
	 */
	private function shadowItems( ?int $connectionId ): array {
		$items = array();
		for ( $page = 1; $page <= 5; ++$page ) {
			$result = $this->queue->query( new QueueQuery( 'synced', EntityType::Order->value, null, null, null, $connectionId, 'shadow', $page, 100 ) );
			foreach ( $result->items as $item ) {
				$items[] = $item;
			}
			if ( $page >= $result->totalPages() ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * The stored verdicts.
	 *
	 * @return array<string,array{result:string,fields:array<int,string>,at:int}>
	 */
	private function verdicts(): array {
		$stored = $this->options->get( self::VERDICTS, array() );
		$clean  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $verdict ) {
			if ( is_array( $verdict ) && in_array( $verdict['result'] ?? '', array( 'matches', 'differs', 'not_found' ), true ) ) {
				$clean[ (string) $id ] = array(
					'result' => (string) $verdict['result'],
					'fields' => array_values( array_map( 'strval', (array) ( $verdict['fields'] ?? array() ) ) ),
					'at'     => (int) ( $verdict['at'] ?? 0 ),
				);
			}
		}
		return $clean;
	}

	/**
	 * Save verdicts, keeping the newest.
	 *
	 * @param array<string,array{result:string,fields:array<int,string>,at:int}> $verdicts Verdicts.
	 */
	private function saveVerdicts( array $verdicts ): void {
		if ( count( $verdicts ) > self::VERDICT_CAP ) {
			uasort( $verdicts, static fn( array $a, array $b ): int => $b['at'] <=> $a['at'] );
			$verdicts = array_slice( $verdicts, 0, self::VERDICT_CAP, true );
		}
		$this->options->set( self::VERDICTS, $verdicts );
	}
}
