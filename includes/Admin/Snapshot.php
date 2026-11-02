<?php
/**
 * A cheap picture of the queue for the places that show on every admin page.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Rest\Support\KeyValueStore;

/**
 * One grouped count over the queue table and one read of the connections, kept for 30 seconds so the admin bar adds no work to a
 * page that is loaded twice in a row. The state is one of: not_connected, paused (a connection is paused or needs reconnecting),
 * attention (an order failed), waiting, ok.
 */
final class Snapshot {

	/**
	 * Seconds the picture is kept.
	 */
	public const TTL = 30;

	/**
	 * Storage key.
	 */
	private const KEY = 'admin_snapshot';

	/**
	 * Create the snapshot.
	 *
	 * @param QueueRepository      $queue       Queue.
	 * @param ConnectionRepository $connections Connections.
	 * @param OnboardingState      $onboarding  Shadow window.
	 * @param Clock                $clock       Time source.
	 * @param KeyValueStore        $cache       Short-lived storage.
	 * @param int                  $ttl         Seconds to keep the picture; 0 to read every time.
	 */
	public function __construct(
		private readonly QueueRepository $queue,
		private readonly ConnectionRepository $connections,
		private readonly OnboardingState $onboarding,
		private readonly Clock $clock,
		private readonly KeyValueStore $cache,
		private readonly int $ttl = self::TTL
	) {
	}

	/**
	 * The picture now.
	 *
	 * @return array{state:string,waiting:int,failed:int,synced_today:int,shadow:bool,shadow_ends_at:?string,reconnect:array<int,array{id:int,label:string}>}
	 */
	public function current(): array {
		if ( $this->ttl > 0 ) {
			$cached = $this->cache->get( self::KEY );
			if ( is_array( $cached ) && isset( $cached['state'], $cached['waiting'], $cached['failed'] ) ) {
				return $cached;
			}
		}
		$picture = $this->read();
		if ( $this->ttl > 0 ) {
			$this->cache->set( self::KEY, $picture, $this->ttl );
		}
		return $picture;
	}

	/**
	 * Count and classify.
	 *
	 * @return array{state:string,waiting:int,failed:int,synced_today:int,shadow:bool,shadow_ends_at:?string,reconnect:array<int,array{id:int,label:string}>}
	 */
	private function read(): array {
		$counts  = $this->queue->countsByState( null, 'live' );
		$waiting = (int) ( $counts['queued'] ?? 0 ) + (int) ( $counts['syncing'] ?? 0 ) + (int) ( $counts['retrying'] ?? 0 );
		$failed  = (int) ( $counts['failed'] ?? 0 );

		$connected = 0;
		$paused    = false;
		$reconnect = array();
		foreach ( $this->connections->allWithoutSecrets() as $connection ) {
			if ( ConnectionStatus::Disconnected === $connection->status ) {
				continue;
			}
			++$connected;
			if ( ConnectionStatus::Active !== $connection->status ) {
				$paused = true;
			}
			if ( ConnectionStatus::NeedsReconnect === $connection->status ) {
				$reconnect[] = array(
					'id'    => $connection->id,
					'label' => $connection->label,
				);
			}
		}

		$state = match ( true ) {
			0 === $connected => 'not_connected',
			$paused          => 'paused',
			$failed > 0      => 'attention',
			$waiting > 0     => 'waiting',
			default          => 'ok',
		};
		$onboarding = $this->onboarding->get();

		return array(
			'state'          => $state,
			'waiting'        => $waiting,
			'failed'         => $failed,
			'synced_today'   => $this->queue->syncedToday(),
			'shadow'         => $this->onboarding->isShadowActive( $this->clock->now() ),
			'shadow_ends_at' => empty( $onboarding['shadow_ends_at'] ) ? null : (string) $onboarding['shadow_ends_at'],
			'reconnect'      => $reconnect,
		);
	}
}
