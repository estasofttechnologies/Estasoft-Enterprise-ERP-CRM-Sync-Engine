<?php
/**
 * Scheduled refresh of tokens that are about to run out.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Runs every five minutes (hook `estasoft_sync_refresh_tokens`). A token that expires within ten minutes is refreshed, so rolling refresh
 * tokens (Xero, QuickBooks) keep moving even when no order is sent. A refusal that only a person can fix is held by the token manager
 * (needs reconnect, SYNC-AUTH-002, with the existing notification); a failure that may pass is left for the next run.
 */
final class RefreshTask {

	/**
	 * Hook name of the task.
	 */
	public const HOOK = 'estasoft_sync_refresh_tokens';

	/**
	 * Seconds between runs.
	 */
	public const INTERVAL = 300;

	/**
	 * Refresh tokens that expire within this many seconds.
	 */
	public const WINDOW = 600;

	/**
	 * For a connector with `refreshOnDemand`: refresh when the refresh token runs out within this many days.
	 */
	public const KEEP_ALIVE_DAYS = 7;

	/**
	 * Most connections refreshed in one run (the ones that are not due do not count).
	 */
	private const LIMIT = 25;

	/**
	 * Set up with what it depends on.
	 *
	 * @param TokenStorage      $storage  Connections.
	 * @param OAuthTokenManager $tokens   Token manager.
	 * @param OAuthResolver     $resolver Finds providers.
	 * @param Logger|null       $logger   Log.
	 * @param Clock|null        $clock    Time source; the system time by default.
	 */
	public function __construct(
		private readonly TokenStorage $storage,
		private readonly OAuthTokenManager $tokens,
		private readonly OAuthResolver $resolver,
		private readonly ?Logger $logger = null,
		private readonly ?Clock $clock = null,
	) {
	}

	/**
	 * Refresh what is due.
	 *
	 * @return array{checked:int,refreshed:int,failed:int}
	 */
	public function run(): array {
		$report = array(
			'checked'   => 0,
			'refreshed' => 0,
			'failed'    => 0,
		);
		$now    = null === $this->clock ? time() : $this->clock->now()->getTimestamp();
		$listed = array_values( $this->storage->activeConnections() );
		// Start one run's worth further down the list every run, so connections that keep failing cannot keep the others waiting: every
		// connection comes first within ceil( n / LIMIT ) runs.
		if ( count( $listed ) > self::LIMIT ) {
			$offset = ( intdiv( $now, self::INTERVAL ) * self::LIMIT ) % count( $listed );
			$listed = array_merge( array_slice( $listed, $offset ), array_slice( $listed, 0, $offset ) );
		}
		foreach ( $listed as $item ) {
			if ( $report['checked'] >= self::LIMIT ) {
				break;
			}
			$provider = $this->resolver->providerFor( $item );
			if ( null === $provider ) {
				continue;
			}
			$connection = $this->storage->load( $item->id );
			if ( null === $connection || '' === $connection->secret( OAuthTokenManager::ACCESS_TOKEN ) ) {
				continue; // Never signed in: nothing to refresh.
			}
			if ( ! $this->isDue( $connection, $provider, $now ) ) {
				continue; // Only the connections that need a refresh count towards the limit of one run.
			}
			++$report['checked'];
			try {
				$this->tokens->refreshNow( $connection, $provider );
				++$report['refreshed'];
			} catch ( OAuthException $exception ) {
				++$report['failed'];
				$this->logger?->warning(
					'Scheduled token refresh failed.',
					array(
						'connection_id' => $connection->id,
						'code'          => $exception->error()->code,
					)
				);
			}
		}
		return $report;
	}

	/**
	 * Whether a connection needs a refresh in this run.
	 *
	 * @param Connection    $connection Connection with its tokens.
	 * @param OAuthProvider $provider   Its provider.
	 * @param int           $now        Epoch seconds.
	 */
	private function isDue( Connection $connection, OAuthProvider $provider, int $now ): bool {
		if ( $provider->config( $connection )->refreshOnDemand ) {
			// Tokens that live minutes are refreshed by the next call. The task only keeps the refresh token from running out.
			$refreshEnds = (int) $connection->secret( OAuthTokenManager::REFRESH_EXPIRES );
			return $refreshEnds > 0 && $refreshEnds - $now <= self::KEEP_ALIVE_DAYS * 86400;
		}
		// Ten minutes before the end, or half of the life of a token that lives less than twenty minutes.
		$lifetime = $this->tokens->lifetime( $connection );
		$window   = $lifetime > 0 ? min( self::WINDOW, intdiv( $lifetime, 2 ) ) : self::WINDOW;
		return $this->tokens->secondsLeft( $connection ) <= $window;
	}
}
