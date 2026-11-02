<?php
/**
 * The capacity test: how many items a minute this server can move through the queue.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Capacity;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Queue\IdempotencyKey;
use Estasoft\SyncEngine\Core\Queue\RateLimiter;
use Estasoft\SyncEngine\Core\Queue\RetryPolicy;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * PRF-7. The design goal is that this can be run on a live shop without any risk:
 *
 * - The items live in a store of their own (`CapacityStore`): a MySQL TEMPORARY table that only this database connection can see
 *   and that vanishes with it, or memory when the database user may not create one. The real queue tables are never read or written.
 * - Nothing here can reach a real connection: there is no connection object, no connector and no HTTP client in this class.
 *   The "null connector" is a closure that accepts every item at once and sends nothing.
 * - The plugin's own pieces do the work they do for real items: the idempotency key, a canonical payload and its hash, the same
 *   token-bucket RateLimiter (over private counters, set so fast that it never waits) and the same RetryPolicy for the one item in
 *   twenty that the simulation fails with a retryable error.
 * - The store is dropped in `finally`. The test is capped at 8 seconds and 400 items, so it also cannot hold a request for long.
 *
 * The figure is the speed of the plugin on this server's PHP and database; the real speed also depends on how fast the connected
 * system answers (Odoo allows about one request a second). The verdict says so.
 */
final class CapacityTest {

	/**
	 * Option name of the last result.
	 */
	public const OPTION = 'estasoft_sync_capacity';

	/**
	 * Synthetic items.
	 */
	public const ITEMS = 400;

	/**
	 * Longest time the test may run, in seconds.
	 */
	public const MAX_SECONDS = 8.0;

	/**
	 * Every Nth item gets a simulated retryable failure.
	 */
	private const FAIL_EVERY = 20;

	/**
	 * Creates a store: function(): CapacityStore. Receives no input.
	 *
	 * @var \Closure
	 */
	private \Closure $storeFactory;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options      Options (last result).
	 * @param Clock       $clock        Time source (the date of the result).
	 * @param \Closure    $storeFactory Returns a temporary-table store; the test falls back to memory when it cannot be prepared.
	 */
	public function __construct( private readonly OptionStore $options, private readonly Clock $clock, \Closure $storeFactory ) {
		$this->storeFactory = $storeFactory;
	}

	/**
	 * Run the test and keep the result.
	 *
	 * @return array{items_per_minute:int,ran_at:string,items:int,seconds:float,verdict:string} A `CapacityResult`.
	 */
	public function run(): array {
		$store    = ( $this->storeFactory )();
		$database = true;
		try {
			try {
				$store->prepare( self::ITEMS );
			} catch ( \RuntimeException $e ) {
				unset( $e );
				$store->drop();
				$store    = new MemoryCapacityStore();
				$database = false;
				$store->prepare( self::ITEMS );
			}
			$measured = $this->simulate( $store );
		} finally {
			$store->drop();
		}
		$perMinute = $measured['seconds'] > 0.0 ? (int) round( $measured['items'] / $measured['seconds'] * 60 ) : 0;
		$result    = array(
			'items_per_minute' => $perMinute,
			'ran_at'           => (string) Time::iso( Time::sql( $this->clock->now() ) ),
			'items'            => $measured['items'],
			'seconds'          => round( $measured['seconds'], 3 ),
			'verdict'          => $this->verdict( $perMinute, $database ),
		);
		$this->options->set( self::OPTION, $result );
		return $result;
	}

	/**
	 * The last result, or null when the test never ran.
	 *
	 * @return array{items_per_minute:int,ran_at:string,items:int,seconds:float,verdict:string}|null
	 */
	public function last(): ?array {
		$stored = $this->options->get( self::OPTION, null );
		return is_array( $stored ) && isset( $stored['items_per_minute'], $stored['ran_at'], $stored['items'], $stored['seconds'], $stored['verdict'] )
			? array(
				'items_per_minute' => (int) $stored['items_per_minute'],
				'ran_at'           => (string) $stored['ran_at'],
				'items'            => (int) $stored['items'],
				'seconds'          => (float) $stored['seconds'],
				'verdict'          => (string) $stored['verdict'],
			)
			: null;
	}

	/**
	 * Move the synthetic items through the store.
	 *
	 * @param CapacityStore $store Store.
	 * @return array{items:int,seconds:float}
	 */
	private function simulate( CapacityStore $store ): array {
		$limiter    = new RateLimiter( new MemoryRateStore(), $this->clock );
		$retry      = new RetryPolicy( $this->clock, static fn(): int => 0 );
		$start      = hrtime( true );
		$processed  = 0;
		$tick       = 0;
		$guard      = 0;
		$failedOnce = array();
		while ( $store->remaining() > 0 && $guard < self::ITEMS * 4 ) {
			++$guard;
			if ( ( hrtime( true ) - $start ) / 1e9 > self::MAX_SECONDS ) {
				break;
			}
			$ids = $store->claim( 20, $tick );
			if ( array() === $ids ) {
				++$tick;
				continue;
			}
			$done = array();
			foreach ( $ids as $id ) {
				$limiter->acquire( 0, 1000000.0 );
				$key = IdempotencyKey::make( 0, EntityType::Order, (string) $id, 'created', 0 );
				// The hash stands for the work a real payload costs; its value is not needed.
				$digest = hash(
					'sha256',
					Json::canonical(
						array(
							'client_ref' => 'WC-' . $id,
							'key'        => $key,
							'lines'      => array(
								array(
									'sku' => 'CAP-' . $id,
									'qty' => 1,
								),
							),
						)
					)
				);
				unset( $digest );
				if ( 0 === $id % self::FAIL_EVERY && ! isset( $failedOnce[ $id ] ) ) {
					// A retryable failure: ask the real retry policy, then wait one tick of the simulated clock.
					$failedOnce[ $id ] = true;
					$retry->delaySeconds( 1, array() );
					$store->retryLater( $id, $tick + 1 );
					continue;
				}
				$done[] = $id;
				++$processed;
			}
			$store->finish( $done );
		}
		return array(
			'items'   => $processed,
			'seconds' => max( 0.000001, ( hrtime( true ) - $start ) / 1e9 ),
		);
	}

	/**
	 * A sentence about the figure.
	 *
	 * @param int  $perMinute Items a minute.
	 * @param bool $database  Whether a database table was part of the test.
	 */
	private function verdict( int $perMinute, bool $database ): string {
		if ( $perMinute >= 6000 ) {
			$text = __( 'Fast. The queue itself is not the limit on this server; the speed of your connected system is.', 'estasoft-erp-crm-sync-engine' );
		} elseif ( $perMinute >= 1200 ) {
			$text = __( 'Good. This server handles the queue comfortably for a busy shop; the speed of your connected system is the limit.', 'estasoft-erp-crm-sync-engine' );
		} elseif ( $perMinute >= 300 ) {
			$text = __( 'Fair. Enough for most shops. If you expect big sales, ask your host about faster database access.', 'estasoft-erp-crm-sync-engine' );
		} else {
			$text = __( 'Slow. This server may fall behind in a busy hour. Check the database speed with your host, or switch to the High volume profile.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( ! $database ) {
			$text .= ' ' . __( 'The database could not be part of the test, so only the plugin\'s own code was measured.', 'estasoft-erp-crm-sync-engine' );
		}
		return $text;
	}
}
