<?php
/**
 * Watches the call quota Holded reports and stops list reads before it runs out.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every Holded answer can carry `X-RateLimit-Remaining` and `X-RateLimit-Window` (`minute` or `month`) [documented]. A plan has a monthly quota
 * of 500 to 100,000 calls, and reading the contact list of a shop costs many of them. The quota is remembered (in memory and in the store), and a
 * list read that would start with fewer than RESERVE calls left stops with SYNC-RATE-001 before it spends them. Creates and read-backs are not
 * held back: Holded answers 429 to those itself.
 *
 * The connection of a call is the one the client was bound to with `bind()`, so the readers need no extra parameter.
 */
final class HoldedQuota {

	/**
	 * Calls kept back for the writes of an order.
	 */
	public const RESERVE = 10;

	/**
	 * Seconds a reading of the quota stays valid, by window.
	 */
	private const FRESH = array(
		'minute' => 60,
		'month'  => 3600,
	);

	/**
	 * Seconds to wait when a minute window is short and Holded did not say when it resets.
	 */
	private const WAIT_MINUTE = 60;

	/**
	 * Seconds to wait when a month window is short and Holded did not say when it resets.
	 */
	private const WAIT_MONTH = 21600;

	/**
	 * Connections the clients belong to.
	 *
	 * @var \WeakMap<ApiClient,int>
	 */
	private \WeakMap $bound;

	/**
	 * The last reading by connection.
	 *
	 * @var array<int,array{remaining:int,window:string,reset:int,at:int}>
	 */
	private array $seen = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param Clock            $clock Time source.
	 * @param HoldedIndexStore $store Where a reading is kept.
	 */
	public function __construct( private readonly Clock $clock, private readonly HoldedIndexStore $store ) {
		$this->bound = new \WeakMap();
	}

	/**
	 * Say which connection a client belongs to.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 */
	public function bind( ApiClient $api, int $connectionId ): void {
		$this->bound[ $api ] = $connectionId;
	}

	/**
	 * Take the quota out of an answer.
	 *
	 * @param ApiClient   $api      Client.
	 * @param ApiResponse $response Answer.
	 */
	public function note( ApiClient $api, ApiResponse $response ): void {
		$remaining = $response->header( 'X-RateLimit-Remaining' );
		if ( null === $remaining || ! is_numeric( $remaining ) ) {
			return;
		}
		$window            = strtolower( (string) $response->header( 'X-RateLimit-Window' ) );
		$now               = $this->clock->now()->getTimestamp();
		$id                = $this->connection( $api );
		$state             = array(
			'remaining' => max( 0, (int) $remaining ),
			'window'    => isset( self::FRESH[ $window ] ) ? $window : 'month',
			'reset'     => $this->resetAt( $response->header( 'X-RateLimit-Reset' ), $now ),
			'at'        => $now,
		);
		$this->seen[ $id ] = $state;
		$this->store->put( $id, 'quota', $state, self::FRESH[ $state['window'] ] );
	}

	/**
	 * Stop before a list read when the quota is nearly spent.
	 *
	 * @param ApiClient $api Client.
	 *
	 * @throws ApiException With SYNC-RATE-001 when fewer than RESERVE calls are left.
	 */
	public function guard( ApiClient $api ): void {
		$id    = $this->connection( $api );
		$state = $this->reading( $id );
		if ( null === $state || $state['remaining'] >= self::RESERVE ) {
			return;
		}
		$now   = $this->clock->now()->getTimestamp();
		$wait  = $state['reset'] > $now ? $state['reset'] - $now : ( 'minute' === $state['window'] ? self::WAIT_MINUTE : self::WAIT_MONTH );
		$error = new ConnectorError(
			ErrorCode::Rate001,
			array(
				'system' => HoldedConnector::LABEL,
				'n'      => $wait,
			),
			429,
			$wait,
			null,
			'quota low'
		);
		throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}

	/**
	 * The connection of a client; 0 when it was never bound.
	 *
	 * @param ApiClient $api Client.
	 */
	private function connection( ApiClient $api ): int {
		return $this->bound[ $api ] ?? 0;
	}

	/**
	 * The last reading of a connection, from memory or the store, while it is fresh.
	 *
	 * @param int $id Connection id.
	 * @return array{remaining:int,window:string,reset:int,at:int}|null
	 */
	private function reading( int $id ): ?array {
		$state = $this->seen[ $id ] ?? null;
		if ( null === $state ) {
			$stored = $this->store->get( $id, 'quota' );
			if ( null !== $stored && isset( $stored['remaining'], $stored['window'], $stored['reset'], $stored['at'] ) && is_int( $stored['remaining'] ) && is_string( $stored['window'] ) && is_int( $stored['reset'] ) && is_int( $stored['at'] ) ) {
				$state = array(
					'remaining' => $stored['remaining'],
					'window'    => $stored['window'],
					'reset'     => $stored['reset'],
					'at'        => $stored['at'],
				);
			}
		}
		if ( null === $state ) {
			return null;
		}
		$now = $this->clock->now()->getTimestamp();
		if ( $now - $state['at'] >= ( self::FRESH[ $state['window'] ] ?? 60 ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * When the window resets, as Unix time, or 0 when the header is missing or unreadable. The header is read either as seconds from now or as
	 * a Unix time, because the documentation does not say which.
	 *
	 * @param string|null $header Header value.
	 * @param int         $now    Now (Unix time).
	 */
	private function resetAt( ?string $header, int $now ): int {
		if ( null === $header || ! is_numeric( $header ) || (float) $header <= 0 ) {
			return 0;
		}
		$value = (int) $header;
		if ( $value > 1000000000 ) {
			return $value;
		}
		return $value <= 2678400 ? $now + $value : 0;
	}
}
