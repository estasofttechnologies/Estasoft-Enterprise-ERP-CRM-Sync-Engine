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
 * Going live marks the setup complete and records the moment. Every item that is still waiting in the queue is skipped with the
 * reason "Placed before go-live": orders that arrived while the person was still setting up are never sent, and nothing is
 * back-filled. Mode `shadow` then opens a shadow window (7 days, owner decides at the end); mode `live` closes any open window.
 * Only orders placed after this moment are sent.
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
	 */
	public function __construct(
		private readonly OnboardingState $onboarding,
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly ShadowService $shadow,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * Go live.
	 *
	 * @param string $mode   `shadow` (a 7-day shadow run first) or `live`.
	 * @param int    $userId Who does it.
	 * @return array<string,mixed> The `Onboarding` after the change.
	 *
	 * @throws ValidationException When the mode is not shadow or live.
	 * @throws JobException        With status 409 when there is no connection to go live with.
	 */
	public function goLive( string $mode, int $userId ): array {
		if ( ! in_array( $mode, array( 'shadow', 'live' ), true ) ) {
			throw new ValidationException( array( 'mode' => __( 'Choose shadow or live.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$connectionId = $this->connectionId();
		$skipped      = $this->skipWaiting();
		$now          = $this->clock->now();
		$state        = $this->onboarding->get();
		$this->onboarding->save(
			array(
				'step'      => 5,
				'completed' => true,
				'answers'   => array_replace(
					$state['answers'],
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
			$this->shadow->end( $userId );
		}
		$this->logger->info(
			sprintf(
				/* translators: 1: number of waiting items skipped, 2: user id. */
				_n( 'Setup finished by user #%2$d. %1$d order placed before go-live will not be sent.', 'Setup finished by user #%2$d. %1$d orders placed before go-live will not be sent.', $skipped, 'estasoft-erp-crm-sync-engine' ),
				$skipped,
				$userId
			),
			array( 'connection_id' => $connectionId )
		);
		return OnboardingView::present( $this->onboarding->get() );
	}

	/**
	 * Skip every item that is waiting.
	 *
	 * @return int How many were skipped.
	 */
	private function skipWaiting(): int {
		$skipped = 0;
		foreach ( array( 'queued', 'retrying' ) as $state ) {
			while ( $skipped < self::MAX_ITEMS ) {
				$page = $this->queue->query( new QueueQuery( $state, null, null, null, null, null, null, 1, 100 ) );
				if ( array() === $page->items ) {
					break;
				}
				$progress = 0;
				foreach ( $page->items as $item ) {
					if ( $this->queue->skip( (int) $item['id'], self::SKIP_REASON ) ) {
						++$skipped;
						++$progress;
					}
				}
				if ( 0 === $progress ) {
					break;
				}
			}
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
			throw new JobException( __( 'Connect a system before you go live.', 'estasoft-erp-crm-sync-engine' ), 409 );
		}
		return $first;
	}
}
