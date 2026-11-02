<?php
/**
 * What the dashboard, the WordPress dashboard widget and the menu bubble show.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Dashboard;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionPresenter;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Ports\Transients;
use Estasoft\SyncEngine\Jobs\Shadow\ShadowService;

/**
 * UI-1, UI-4, UI-11. Counts come from `QueueRepository::countsByState()` (live items only; shadow items are counted in the shadow
 * summary, never in the queue numbers). The failed count behind the menu bubble is cached for 60 seconds in a transient and
 * dropped whenever an item syncs or fails, or a connection resumes, so it is never more than a minute out of date.
 */
final class DashboardService {

	/**
	 * Transient that caches the failed count.
	 */
	public const FAILED_KEY = 'estasoft_sync_failed_count';

	/**
	 * Seconds the failed count is cached.
	 */
	public const FAILED_TTL = 60;

	/**
	 * Events shown on the dashboard.
	 */
	private const EVENTS = 10;

	/**
	 * Set up with what it depends on.
	 *
	 * @param QueueRepository               $queue       Queue counts.
	 * @param ConnectionRepository          $connections Connections.
	 * @param \Closure():ConnectorRegistry  $registry    Returns the connector registry.
	 * @param LogRepository                 $logs        Latest events.
	 * @param OnboardingState               $onboarding  Setup and shadow state.
	 * @param SecretBox                     $box         Encryption key.
	 * @param ShopEnvironment               $environment Time zone, currency, cron mode.
	 * @param ShadowService                 $shadow      Shadow summary.
	 * @param Transients                    $transients  The failed-count cache.
	 * @param Clock                         $clock       Time source.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly \Closure $registry,
		private readonly LogRepository $logs,
		private readonly OnboardingState $onboarding,
		private readonly SecretBox $box,
		private readonly ShopEnvironment $environment,
		private readonly ShadowService $shadow,
		private readonly Transients $transients,
		private readonly Clock $clock
	) {
	}

	/**
	 * The dashboard (the `Status` of src/api/types.ts).
	 *
	 * @param int|null $connectionId One connection, or null for the first one that is not disconnected.
	 * @return array<string,mixed>
	 */
	public function status( ?int $connectionId = null ): array {
		$now        = $this->clock->now();
		$counts     = $this->queue->countsByState( $connectionId, 'live' );
		$connection = $this->connection( $connectionId );
		$oldest     = $this->queue->oldestWaiting();
		$shadow     = $this->onboarding->isShadowActive( $now );
		return array(
			'mode'                  => $this->mode( $connection, $shadow, array_sum( $counts ) ),
			'now'                   => $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Time::ISO ),
			'store_timezone'        => $this->environment->timezone()->getName(),
			'currency'              => $this->environment->currency(),
			'synced_today'          => $this->queue->syncedToday( $connectionId ),
			'waiting'               => $counts['queued'] + $counts['syncing'] + $counts['retrying'],
			'needs_attention'       => $counts['failed'],
			'skipped'               => $counts['skipped'],
			'oldest_waiting_at'     => $oldest['at'] ?? null,
			'oldest_waiting_order'  => $oldest['order'] ?? null,
			'by_state'              => $counts,
			'hourly'                => $this->queue->hourly(),
			'events'                => $this->logs->latest( self::EVENTS ),
			'connection'            => $connection,
			'shadow'                => $shadow ? $this->shadow->info( $connectionId ) : null,
			'encryption_key_missing' => ! $this->box->keyAvailable(),
			'cron_on_visits'        => $this->environment->cronRunsOnVisits(),
		);
	}

	/**
	 * A few counts for the WordPress dashboard widget.
	 *
	 * @return array{connected:bool,mode:string,synced_today:int,waiting:int,failed:int}
	 */
	public function widget(): array {
		$counts     = $this->queue->countsByState( null, 'live' );
		$connection = $this->connection( null );
		return array(
			'connected'    => null !== $connection,
			'mode'         => $this->mode( $connection, $this->onboarding->isShadowActive( $this->clock->now() ), array_sum( $counts ) ),
			'synced_today' => $this->queue->syncedToday( null ),
			'waiting'      => $counts['queued'] + $counts['syncing'] + $counts['retrying'],
			'failed'       => $counts['failed'],
		);
	}

	/**
	 * The number of failed items (the menu bubble), cached for 60 seconds.
	 */
	public function failedCount(): int {
		$cached = $this->transients->get( self::FAILED_KEY );
		if ( is_int( $cached ) || ( is_string( $cached ) && ctype_digit( $cached ) ) ) {
			return (int) $cached;
		}
		$failed = $this->queue->countsByState( null, 'live' )['failed'];
		$this->transients->set( self::FAILED_KEY, $failed, self::FAILED_TTL );
		return $failed;
	}

	/**
	 * Drop the cached failed count (hooked to the actions that change it).
	 */
	public function bustFailedCount(): void {
		$this->transients->delete( self::FAILED_KEY );
	}

	/**
	 * The dashboard layout to show.
	 *
	 * @param array<string,mixed>|null $connection Connection summary, or null when none is connected.
	 * @param bool                     $shadow     Whether the shadow window is open.
	 * @param int                      $items      Live items in the queue.
	 */
	private function mode( ?array $connection, bool $shadow, int $items ): string {
		if ( null === $connection ) {
			return 'not_connected';
		}
		if ( in_array( $connection['status'], array( ConnectionStatus::Paused->value, ConnectionStatus::PausedCircuit->value, ConnectionStatus::NeedsReconnect->value ), true ) ) {
			return 'paused';
		}
		if ( $shadow ) {
			return 'shadow';
		}
		if ( ! $this->onboarding->get()['completed'] && 0 === $items ) {
			return 'first_run';
		}
		return 'normal';
	}

	/**
	 * The connection card.
	 *
	 * @param int|null $connectionId A connection, or null for the first that is not disconnected.
	 * @return array<string,mixed>|null A `ConnectionSummary`.
	 */
	private function connection( ?int $connectionId ): ?array {
		$found = null;
		if ( null !== $connectionId ) {
			$found = $this->connections->findPublic( $connectionId );
		} else {
			foreach ( $this->connections->all() as $candidate ) {
				if ( ConnectionStatus::Disconnected->value !== $candidate['status'] ) {
					$found = $candidate;
					break;
				}
			}
		}
		if ( null === $found || ConnectionStatus::Disconnected->value === $found['status'] ) {
			return null;
		}
		return ConnectionPresenter::summary( $found, ( $this->registry )()->get( (string) $found['connector'] ) );
	}
}
