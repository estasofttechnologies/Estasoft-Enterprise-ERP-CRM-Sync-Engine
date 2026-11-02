<?php
/**
 * PRF-7 and ONB-4.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Capacity\CapacityStore;
use Estasoft\SyncEngine\Jobs\Capacity\CapacityTest;
use Estasoft\SyncEngine\Jobs\Capacity\MemoryCapacityStore;
use Estasoft\SyncEngine\Jobs\Capacity\TemporaryTableCapacityStore;

final class CapacityAndOnboardingTest extends JobsTestCase {

	/**
	 * Rows in the plugin's real tables.
	 */
	private function realRows(): int {
		$total = 0;
		foreach ( $this->h->tables->all() as $table ) {
			$total += $this->h->count( $table );
		}
		return $total;
	}

	public function test_the_capacity_test_in_a_temporary_table_leaves_no_row_and_no_table_behind(): void {
		$id = $this->j->connect();
		$this->h->item( $id, '1' );
		$rows     = $this->realRows();
		$capacity = new CapacityTest( $this->h->options, $this->h->clock, fn() => new TemporaryTableCapacityStore( $this->h->wpdb ) );

		$result = $capacity->run();

		$this->assertSame( CapacityTest::ITEMS, $result['items'] );
		$this->assertGreaterThan( 0, $result['items_per_minute'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $result['ran_at'] );
		$this->assertNotSame( '', $result['verdict'] );
		$this->assertStringNotContainsString( 'could not be part of the test', $result['verdict'], 'the database was part of it' );
		$this->assertSame( $rows, $this->realRows(), 'the real tables are untouched' );
		$this->assertSame( array(), $this->h->wpdb->get_col( "SHOW TABLES LIKE '%estasoft_sync_cap_%'" ) );
		$this->assertSame( $result, $capacity->last() );
	}

	public function test_the_capacity_test_never_calls_a_connector_or_changes_a_connection(): void {
		$id = $this->j->connect();
		$this->j->capacity->run();
		$this->assertSame( array(), $this->j->connector->calls );
		$this->assertSame( 'active', $this->h->connections->findPublic( $id )['status'] );
		$this->assertSame( array(), $this->h->rateStore->values, 'the real limiter counters are untouched' );
	}

	public function test_when_no_temporary_table_is_possible_the_test_falls_back_to_memory_and_says_so(): void {
		$broken = new class() implements CapacityStore {
			public bool $dropped = false;
			public function prepare( int $items ): void {
				throw new \RuntimeException( 'no privilege' );
			}
			public function claim( int $limit, int $dueAt ): array {
				return array();
			}
			public function finish( array $ids ): void {}
			public function retryLater( int $id, int $nextAt ): void {}
			public function remaining(): int {
				return 0;
			}
			public function drop(): void {
				$this->dropped = true;
			}
			public function usesDatabase(): bool {
				return true;
			}
		};
		$capacity = new CapacityTest( $this->h->options, $this->h->clock, static fn() => $broken );

		$result = $capacity->run();

		$this->assertTrue( $broken->dropped );
		$this->assertSame( CapacityTest::ITEMS, $result['items'] );
		$this->assertStringContainsString( 'database could not be part of the test', $result['verdict'] );
	}

	public function test_the_store_is_dropped_even_when_the_simulation_fails(): void {
		$store = new class() implements CapacityStore {
			public bool $dropped = false;
			public function prepare( int $items ): void {}
			public function claim( int $limit, int $dueAt ): array {
				throw new \LogicException( 'broken' );
			}
			public function finish( array $ids ): void {}
			public function retryLater( int $id, int $nextAt ): void {}
			public function remaining(): int {
				return 1;
			}
			public function drop(): void {
				$this->dropped = true;
			}
			public function usesDatabase(): bool {
				return false;
			}
		};
		$capacity = new CapacityTest( $this->h->options, $this->h->clock, static fn() => $store );
		try {
			$capacity->run();
			$this->fail( 'expected the failure to surface' );
		} catch ( \LogicException $e ) {
			$this->assertTrue( $store->dropped, 'dropped in finally' );
		}
	}

	public function test_the_memory_store_claims_in_order_and_retries_later(): void {
		$store = new MemoryCapacityStore();
		$store->prepare( 5 );
		$this->assertSame( array( 1, 2 ), $store->claim( 2, 0 ) );
		$store->retryLater( 1, 3 );
		$this->assertSame( array( 3, 4 ), $store->claim( 2, 0 ) );
		$store->finish( array( 2, 3, 4 ) );
		$this->assertSame( array( 5 ), $store->claim( 5, 0 ) );
		$this->assertSame( array( 1 ), $store->claim( 5, 3 ) );
		$this->assertSame( 2, $store->remaining() );
	}

	public function test_the_wizard_is_resumable_and_keeps_the_answers(): void {
		$this->assertSame( 1, $this->j->onboardingService->get()['step'] );

		$saved = $this->j->onboardingService->save( array( 'step' => 3, 'connector' => 'odoo', 'answers' => array( 'sync_stock' => true, 'warehouse' => 'Dubai' ) ) );
		$this->assertSame( 3, $saved['step'] );
		$this->assertSame( 'odoo', $saved['connector'] );

		$again = $this->j->onboardingService->save( array( 'answers' => array( 'sync_tracking' => false ) ) );
		$this->assertSame( 3, $again['step'] );
		$this->assertSame( array( 'sync_stock' => true, 'warehouse' => 'Dubai', 'sync_tracking' => false ), $again['answers'] );
		$this->assertSame( $again, $this->j->onboardingService->get() );
		$this->assertSame( 0, $this->j->connector->count( 'send' ), 'nothing is sent before step 5' );
	}

	public function test_the_wizard_cannot_set_the_shadow_window_or_the_reserved_answers(): void {
		$this->j->onboardingService->save( array( 'answers' => array( 'went_live_at' => '2020-01-01T00:00:00Z', 'shadow_days' => 99, 'ok' => 'yes' ), 'shadow_started_at' => '2020-01-01T00:00:00Z', 'completed' => true ) );

		$state = $this->h->onboarding->get();
		$this->assertSame( array( 'ok' => 'yes' ), $state['answers'] );
		$this->assertNull( $state['shadow_started_at'] );
		$this->assertFalse( $state['completed'] );
	}

	public function test_wizard_input_is_validated_and_cleaned(): void {
		try {
			$this->j->onboardingService->save( array( 'step' => 9, 'connector' => 'Bad Id!', 'answers' => 'text' ) );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'step', 'connector', 'answers' ), array_keys( $e->errors() ) );
		}
		$saved = $this->j->onboardingService->save( array( 'answers' => array( 'name' => '<b>Layla</b>', 'nested' => array( 'a' => array( 'b' => array( 'c' => 1 ) ) ), 'obj' => new \stdClass() ) ) );
		$this->assertSame( 'Layla', $saved['answers']['name'] );
		$this->assertArrayNotHasKey( 'obj', $saved['answers'] );
	}

	public function test_sample_orders_come_from_the_shop(): void {
		$this->j->orders->add( 10486, array( 'customer' => 'Layla K.', 'total_minor' => 21200 ) );
		$this->j->orders->add( 10490, array( 'customer' => 'Noor', 'created_at' => '2026-11-02 07:21:00' ) );

		$orders = $this->j->onboardingService->sampleOrders();

		$this->assertSame( 10490, $orders[0]['id'] );
		$this->assertSame( '#10490', $orders[0]['label'] );
		$this->assertSame( array( 'minor' => 48615, 'currency' => 'AED' ), $orders[0]['total'] );
		$this->assertSame( '2026-11-02T07:21:00Z', $orders[0]['created_at'] );
	}
}
