<?php
/**
 * The scheduler when Action Scheduler is available.
 *
 * Each test runs in its own process because it defines the as_* functions and the ActionScheduler class, which must not
 * exist in the processes of other tests. WordPress itself is not loaded: the few WordPress functions are defined here.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use PHPUnit\Framework\TestCase;

final class SchedulerActionSchedulerTest extends TestCase {

	/**
	 * Define fakes for Action Scheduler and the WordPress scheduling functions. Calls are recorded in $GLOBALS['a1_log'].
	 */
	private function fakeEnvironment(): void {
		$GLOBALS['a1_log']       = array();
		$GLOBALS['a1_scheduled'] = array();
		$GLOBALS['a1_transient'] = false;
		$GLOBALS['a1_actions']   = array( 'complete' => array( 11, 12 ), 'canceled' => array( 13 ), 'failed' => array() );

		define( 'MINUTE_IN_SECONDS', 60 );
		define( 'DAY_IN_SECONDS', 86400 );

		eval( <<<'PHP'
namespace {
	class ActionScheduler {
		public static function is_initialized() { return true; }
		public static function store() { return new ActionScheduler_FakeStore(); }
	}
	class ActionScheduler_FakeStore {
		public function delete_action( $id ) { $GLOBALS['a1_log'][] = array( 'delete_action', $id ); }
	}
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		return $GLOBALS['a1_scheduled'][ $hook ] ?? false;
	}
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
		$GLOBALS['a1_scheduled'][ $hook ] = $timestamp;
		$GLOBALS['a1_log'][] = array( 'recurring', $hook, $interval, $group );
		return 1;
	}
	function as_unschedule_all_actions( $hook = '', $args = array(), $group = '' ) {
		unset( $GLOBALS['a1_scheduled'][ $hook ] );
		$GLOBALS['a1_log'][] = array( 'unschedule', $hook, $group );
	}
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
		$GLOBALS['a1_log'][] = array( 'async', $hook, $group );
		return 42;
	}
	function as_get_scheduled_actions( $args = array(), $return = 'ids' ) {
		$GLOBALS['a1_log'][] = array( 'query', $args['status'], $args['group'], $args['date_compare'], $args['date'], $args['per_page'] );
		if ( ! isset( $GLOBALS['a1_actions'][ $args['status'] ] ) ) {
			return array();
		}
		return array_splice( $GLOBALS['a1_actions'][ $args['status'] ], 0, (int) $args['per_page'] );
	}
	function wp_next_scheduled( $hook ) { return false; }
	function wp_schedule_event( ...$args ) { $GLOBALS['a1_log'][] = array( 'WP-CRON-USED', $args[2] ); return true; }
	function wp_unschedule_hook( $hook ) { $GLOBALS['a1_log'][] = array( 'wp_unschedule_hook', $hook ); }
	function wp_schedule_single_event( ...$args ) { $GLOBALS['a1_log'][] = array( 'WP-CRON-USED', $args[1] ); return true; }
	function add_filter( ...$args ) { return true; }
	function is_admin() { return true; }
	function wp_doing_cron() { return false; }
	function wp_doing_ajax() { return false; }
	function get_transient( $name ) { return $GLOBALS['a1_transient']; }
	function set_transient( $name, $value, $ttl ) { $GLOBALS['a1_transient'] = $value; $GLOBALS['a1_log'][] = array( 'set_transient', $name, $ttl ); return true; }
	function delete_transient( $name ) { $GLOBALS['a1_transient'] = false; return true; }
}
PHP
		); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}

	/**
	 * Calls of a kind that were recorded.
	 *
	 * @param string $kind First element of a log entry.
	 * @return array<int,array<int,mixed>>
	 */
	private function logged( string $kind ): array {
		return array_values( array_filter( $GLOBALS['a1_log'], static fn( array $entry ): bool => $entry[0] === $kind ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_all_five_tasks_go_to_action_scheduler_in_the_estasoft_group(): void {
		$this->fakeEnvironment();
		$scheduler = \Estasoft\SyncEngine\Core\Queue\Scheduler::class;
		$this->assertTrue( $scheduler::usesActionScheduler() );

		$scheduler::schedule();
		$recurring = $this->logged( 'recurring' );
		$this->assertCount( 5, $recurring );
		$byHook = array();
		foreach ( $recurring as $entry ) {
			$byHook[ $entry[1] ] = array( $entry[2], $entry[3] );
		}
		$this->assertSame(
			array(
				'estasoft_sync_run_queue'         => array( 60, 'estasoft-sync' ),
				'estasoft_sync_reap_locks'        => array( 60, 'estasoft-sync' ),
				'estasoft_sync_probe_connections' => array( 60, 'estasoft-sync' ),
				'estasoft_sync_prune_data'        => array( 86400, 'estasoft-sync' ),
				'estasoft_sync_purge_actions'     => array( 86400, 'estasoft-sync' ),
			),
			$byHook
		);
		$this->assertSame( array(), $this->logged( 'WP-CRON-USED' ), 'WP-Cron is not used when Action Scheduler is there' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_scheduling_twice_does_not_duplicate_a_task(): void {
		$this->fakeEnvironment();
		$scheduler = \Estasoft\SyncEngine\Core\Queue\Scheduler::class;
		$scheduler::schedule();
		$scheduler::schedule();
		$this->assertCount( 5, $this->logged( 'recurring' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unscheduling_removes_every_task_of_the_group(): void {
		$this->fakeEnvironment();
		$scheduler = \Estasoft\SyncEngine\Core\Queue\Scheduler::class;
		$scheduler::schedule();
		$scheduler::unschedule();
		$removed = array_column( $this->logged( 'unschedule' ), 1 );
		$this->assertEqualsCanonicalizing(
			array( 'estasoft_sync_run_queue', 'estasoft_sync_reap_locks', 'estasoft_sync_probe_connections', 'estasoft_sync_prune_data', 'estasoft_sync_purge_actions' ),
			$removed
		);
		foreach ( $this->logged( 'unschedule' ) as $entry ) {
			$this->assertSame( 'estasoft-sync', $entry[2] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_cron_copies_are_removed_once_action_scheduler_is_available(): void {
		$this->fakeEnvironment();
		$scheduler = \Estasoft\SyncEngine\Core\Queue\Scheduler::class;
		$scheduler::ensureScheduled();
		$this->assertCount( 5, $this->logged( 'wp_unschedule_hook' ), 'so no task runs twice' );
		$this->assertCount( 5, $this->logged( 'recurring' ) );
		$this->assertCount( 1, $this->logged( 'set_transient' ) );
		$scheduler::ensureScheduled();
		$this->assertCount( 5, $this->logged( 'recurring' ), 'checked at most once per 15 minutes' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_now_uses_an_async_action(): void {
		$this->fakeEnvironment();
		$scheduler = $this->newScheduler();
		$this->assertTrue( $scheduler->runNow() );
		$this->assertSame( array( array( 'async', 'estasoft_sync_run_queue', 'estasoft-sync' ) ), $this->logged( 'async' ) );
		$this->assertSame( array(), $this->logged( 'WP-CRON-USED' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_finished_rows_of_the_group_are_purged(): void {
		$this->fakeEnvironment();
		$scheduler = $this->newScheduler();
		$this->assertSame( 3, $scheduler->handlePurge() );
		$queries = $this->logged( 'query' );
		$this->assertSame( array( 'complete', 'canceled', 'failed' ), array_column( $queries, 1 ) );
		foreach ( $queries as $query ) {
			$this->assertSame( 'estasoft-sync', $query[2] );
			$this->assertSame( '<', $query[3] );
		}
		$this->assertSame( array( '11', '12', '13' ), array_column( $this->logged( 'delete_action' ), 1 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_rows_older_than_7_days_are_purged(): void {
		$this->fakeEnvironment();
		$this->assertSame( 7, \Estasoft\SyncEngine\Core\Queue\Scheduler::PURGE_AFTER_DAYS );
		$before = time();
		$this->newScheduler()->handlePurge();
		$after = time();
		foreach ( $this->logged( 'query' ) as $query ) {
			$this->assertGreaterThanOrEqual( $before - 7 * 86400, $query[4] );
			$this->assertLessThanOrEqual( $after - 7 * 86400, $query[4], 'seven days back, not one' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_large_backlog_is_purged_in_batches(): void {
		$this->fakeEnvironment();
		$GLOBALS['a1_actions']['complete'] = range( 1, 1200 );
		$this->assertSame( 1201, $this->newScheduler()->handlePurge(), '1,200 complete and one canceled' );
		$this->assertSame( array( 'complete', 'complete', 'complete', 'canceled', 'failed' ), array_column( $this->logged( 'query' ), 1 ) );
		$this->assertSame( array( 500, 500, 500, 500, 500 ), array_column( $this->logged( 'query' ), 5 ) );
	}

	/**
	 * A scheduler whose collaborators are empty shells: these tests never run a task handler.
	 */
	private function newScheduler(): \Estasoft\SyncEngine\Core\Queue\Scheduler {
		$ns   = 'Estasoft\\SyncEngine\\Core\\';
		$bare = static fn( string $class ): object => ( new \ReflectionClass( $ns . $class ) )->newInstanceWithoutConstructor();
		return new \Estasoft\SyncEngine\Core\Queue\Scheduler(
			$bare( 'Queue\\Worker' ),
			$bare( 'Queue\\Reaper' ),
			$bare( 'Queue\\CircuitBreaker' ),
			$bare( 'Queue\\PauseController' ),
			$bare( 'Database\\RetentionJob' ),
			$bare( 'Settings' ),
			new \Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore(),
			new \Estasoft\SyncEngine\Tests\Support\CoreFakeClock()
		);
	}
}
