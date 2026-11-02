<?php
/**
 * The two places the rate limiter can keep its counters.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Queue\ObjectCacheRateStore;
use Estasoft\SyncEngine\Core\Queue\RateLimiter;
use Estasoft\SyncEngine\Core\Queue\RateStore;
use Estasoft\SyncEngine\Core\Repositories\RateStateRepository;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class RateStoresTest extends CoreIntegrationTestCase {

	/**
	 * Both implementations, built the way CoreModule builds them.
	 *
	 * @return array<string,array{0:RateStore}>
	 */
	private function stores(): array {
		return array(
			'object cache' => new ObjectCacheRateStore(),
			'database'     => new RateStateRepository( $this->h->wpdb, $this->h->tables, $this->h->clock ),
		);
	}

	public function test_compare_and_set_only_succeeds_when_the_caller_saw_the_current_value(): void {
		foreach ( $this->stores() as $name => $store ) {
			$key = 'test:' . $name . ':' . wp_rand();
			$this->assertNull( $store->get( $key ), $name );
			$this->assertTrue( $store->compareAndSet( $key, null, 5.25 ), "$name: first write" );
			$this->assertEqualsWithDelta( 5.25, (float) $store->get( $key ), 0.000001, $name );
			$this->assertFalse( $store->compareAndSet( $key, null, 6.0 ), "$name: another worker already wrote it" );
			$this->assertFalse( $store->compareAndSet( $key, 4.0, 7.0 ), "$name: stale value" );
			$this->assertTrue( $store->compareAndSet( $key, 5.25, 6.5 ), "$name: current value" );
			$this->assertEqualsWithDelta( 6.5, (float) $store->get( $key ), 0.000001, $name );
		}
	}

	public function test_values_keep_micro_second_precision(): void {
		foreach ( $this->stores() as $name => $store ) {
			$key   = 'precision:' . $name . ':' . wp_rand();
			$epoch = 1793534400.123456;
			$store->compareAndSet( $key, null, $epoch );
			$this->assertEqualsWithDelta( $epoch, (float) $store->get( $key ), 0.0000011, $name );
		}
	}

	public function test_the_object_cache_store_gives_way_while_another_worker_holds_the_short_lock(): void {
		$store = new ObjectCacheRateStore();
		$key   = 'locked:' . wp_rand();
		wp_cache_add( $key . ':lock', 1, 'estasoft_sync_rate', 2 );
		$this->assertFalse( $store->compareAndSet( $key, null, 1.0 ) );
		wp_cache_delete( $key . ':lock', 'estasoft_sync_rate' );
		$this->assertTrue( $store->compareAndSet( $key, null, 1.0 ) );
		$this->assertFalse( wp_cache_get( $key . ':lock', 'estasoft_sync_rate' ), 'the lock is always released' );
	}

	public function test_the_database_store_keeps_one_hidden_option_row_per_counter(): void {
		$store = new RateStateRepository( $this->h->wpdb, $this->h->tables, $this->h->clock );
		$store->compareAndSet( 'conn:1', null, 1.0 );
		$store->compareAndSet( 'conn:2', null, 1.0 );
		$store->compareAndSet( 'conn:1', 1.0, 2.0 );
		$rows = $this->h->wpdb->get_results( "SELECT option_name, autoload FROM {$this->h->wpdb->options} WHERE option_name LIKE 'estasoft\\_sync\\_rl\\_%'" ); // phpcs:ignore
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'no', $row->autoload );
		}
	}

	public function test_the_limiter_works_the_same_on_either_store(): void {
		foreach ( $this->stores() as $name => $store ) {
			$limiter = new RateLimiter( $store, $this->h->clock );
			$id      = wp_rand( 1000, 90000 );
			$this->assertEqualsWithDelta( 0.0, $limiter->acquire( $id, 4.0 ), 0.0001, $name );
			$this->assertEqualsWithDelta( 0.25, $limiter->acquire( $id, 4.0 ), 0.0001, $name );
			$this->assertEqualsWithDelta( 0.5, $limiter->acquire( $id, 4.0 ), 0.0001, $name );
		}
	}
}
