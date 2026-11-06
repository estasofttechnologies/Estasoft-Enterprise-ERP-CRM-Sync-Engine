<?php
/**
 * Go live: start sending, with or without a shadow run first.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Shadow;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingView;

/**
 * ONB-9 and SHD-3.
 *
 * Going live marks the setup complete and records the moment. Every item created before that moment and still waiting in the
 * queue is skipped with the reason "Placed before go-live": orders that arrived while the person was still setting up are never
 * sent, and nothing is back-filled. Mode `shadow` then opens a shadow window (7 days, owner decides at the end); mode `live` closes
 * any open window (`OnboardingState::endShadow()`, reason `go_live`). Only orders placed after this moment are sent. A second call
 * changes nothing.
 *
 * The two inbound choices of the "what to sync" step are applied here too: when the person left "Update WooCommerce stock" or "Update
 * order status and tracking numbers" on, the stock rules or the tracking setting of the connection are switched on (they are off
 * until then). Nothing is written to the shop during a shadow run, so this is safe in either mode. Choosing "off" changes nothing: the
 * Stock and Tracking screens stay the place to switch them on later.
 */
final class GoLiveService {

	/**
	 * The reason shown on skipped items.
	 */
	public const SKIP_REASON = 'Placed before go-live';

	/**
	 * Most items skipped in one call (a safety stop).
	 */
	private const MAX_ITEMS = 20000;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OnboardingState      $onboarding  Wizard state.
	 * @param QueueRepository      $queue       Queue.
	 * @param ConnectionRepository $connections Connections.
	 * @param ShadowService        $shadow      Shadow window.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $inbound     Switches on stock and tracking for a connection: `fn( int $connectionId, bool $stock, bool $tracking ): void`.
	 */
	public function __construct(
		private readonly OnboardingState $onboarding,
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly ShadowService $shadow,
		private readonly Logger $logger,
		private readonly Clock $clock,
		private readonly ?\Closure $inbound = null
	) {
	}

	/**
	 * Go live. Safe to call twice: once the setup is complete and the moment is recorded, a second call returns the state as it is and
	 * does not touch the queue (orders placed after the first call are real and must not be skipped).
	 *
	 * @param string $mode   `shadow` (a 7-day shadow run first) or `live`.
	 * @param int    $userId Who does it.
	 * @return array<string,mixed> The `Onboarding` after the change.
	 *
	 * @throws ValidationException When the mode is not shadow or live. With no connection to go live with it is refused with a
	 *                             `JobException` (status 409).
	 */
	public function goLive( string $mode, int $userId ): array {
		if ( ! in_array( $mode, array( 'shadow', 'live' ), true ) ) {
			throw new ValidationException( array( 'mode' => esc_html__( 'Choose shadow or live.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		// Before anything else: an order created after this moment is a real order, whatever happens below.
		$now   = $this->clock->now();
		$state = $this->onboarding->get();
		if ( ! empty( $state['completed'] ) && ! empty( $state['answers']['went_live_at'] ) ) {
			return OnboardingView::present( $state );
		}
		$connectionId = $this->connectionId();
		$skipped      = $this->skipWaiting( $now->getTimestamp() );
		$this->onboarding->save(
			array(
				'step'      => 5,
				'completed' => true,
				'answers'   => array_replace(
					$this->onboarding->get()['answers'],
					array(
						'went_live_at' => $now->format( Time::ISO ),
						'live_mode'    => $mode,
					)
				),
			)
		);
		if ( 'shadow' === $mode ) {
			$this->shadow->start( $connectionId, 7 );
		} else {
			$this->shadow->end( $userId, 'go_live' );
		}
		$this->applyInbound( $connectionId );
		$this->logger->info(
			sprintf(
				/* translators: 1: number of waiting items skipped, 2: user id. */
				_n( 'Setup finished by user #%2$d. %1$d order placed before go-live will not be sent.', 'Setup finished by user #%2$d. %1$d orders placed before go-live will not be sent.', $skipped, 'estasoft-enterprise-erpcrm-sync-engine' ),
				$skipped,
				$userId
			),
			array( 'connection_id' => $connectionId )
		);
		return OnboardingView::present( $this->onboarding->get() );
	}

	/**
	 * Switch on stock and tracking when the person left them on in setup. A failure here never stops going live.
	 *
	 * @param int $connectionId Connection.
	 */
	private function applyInbound( int $connectionId ): void {
		$answers = $this->onboarding->get()['answers'];
		$what    = is_array( $answers ) && is_array( $answers['what'] ?? null ) ? $answers['what'] : array();
		$stock   = true === ( $what['stock_in'] ?? null );
		$track   = true === ( $what['tracking_in'] ?? null );
		if ( null === $this->inbound || ( ! $stock && ! $track ) ) {
			return;
		}
		try {
			( $this->inbound )( $connectionId, $stock, $track );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				__( 'Stock or tracking could not be switched on. Switch them on from the Stock and Tracking screens.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'connection_id' => $connectionId,
					'error'         => get_class( $e ),
				)
			);
		}
	}

	/**
	 * Skip the items that were waiting when go-live began. An item created after that moment is a real order and is left alone.
	 * The ids are collected first and skipped afterwards, and the pass repeats until it finds nothing new (an item that left the
	 * queue while the pages were read can move the others).
	 *
	 * @param int $before Unix time captured when go-live began; items created at or before it are skipped.
	 * @return int How many were skipped.
	 */
	private function skipWaiting( int $before ): int {
		$skipped = 0;
		for ( $pass = 0; $pass < 5 && $skipped < self::MAX_ITEMS; ++$pass ) {
			$ids = array();
			foreach ( array( 'queued', 'retrying' ) as $state ) {
				$page      = 1;
				$collected = count( $ids );
				while ( $collected + $skipped < self::MAX_ITEMS ) {
					$result = $this->queue->query( new QueueQuery( $state, null, null, null, null, null, null, $page, 100 ) );
					foreach ( $result->items as $item ) {
						$created = strtotime( (string) ( $item['created_at'] ?? '' ) );
						if ( false !== $created && $created <= $before ) {
							$ids[ (int) $item['id'] ] = true;
						}
					}
					$collected = count( $ids );
					if ( $page >= $result->totalPages() ) {
						break;
					}
					++$page;
				}
			}
			$progress = 0;
			foreach ( array_keys( $ids ) as $id ) {
				if ( $this->queue->skip( $id, self::SKIP_REASON ) ) {
					++$progress;
				}
			}
			if ( 0 === $progress ) {
				break;
			}
			$skipped += $progress;
		}
		return $skipped;
	}

	/**
	 * The connection the person is going live with: the one chosen in the wizard, otherwise the first connected one.
	 *
	 * @throws JobException With status 409 when there is none.
	 */
	private function connectionId(): int {
		$chosen = $this->onboarding->get()['connector'];
		$first  = null;
		foreach ( $this->connections->allWithoutSecrets() as $connection ) {
			if ( ConnectionStatus::Disconnected === $connection->status ) {
				continue;
			}
			if ( null === $first ) {
				$first = $connection->id;
			}
			if ( null !== $chosen && $connection->connector === $chosen ) {
				return $connection->id;
			}
		}
		if ( null === $first ) {
			throw new JobException( esc_html__( 'Connect a system before you go live.', 'estasoft-enterprise-erpcrm-sync-engine' ), 409 );
		}
		return $first;
	}
}
