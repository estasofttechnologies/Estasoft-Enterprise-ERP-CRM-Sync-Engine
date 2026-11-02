<?php
/**
 * Background tasks.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class SchedulerTest extends CoreIntegrationTestCase {

	/**
	 * The hooks the plugin schedules.
	 *
	 * @var array<int,string>
	 */
	private const HOOKS = array( Scheduler::HOOK_RUN, Scheduler::HOOK_REAP, Scheduler::HOOK_PROBE, Scheduler::HOOK_PRUNE, Scheduler::HOOK_PURGE );

	protected function setUp(): void {
		parent::setUp();
		Scheduler::unschedule();
	}

	protected function tearDown(): void {
		foreach ( array( 'wp_doing_cron', Scheduler::HOOK_RUN, Scheduler::HOOK_REAP, Scheduler::HOOK_PROBE, Scheduler::HOOK_PRUNE, Scheduler::HOOK_PURGE ) as $hook ) {
			remove_all_filters( $hook );
		}
		Scheduler::unschedule();
		Scheduler::schedule();
		parent::tearDown();
	}

	/**
	 * Recurrence of a hook.
	 *
	 * @param string $hook Hook.
	 */
	private function recurrence( string $hook ): string|false {
		return wp_get_schedule( $hook );
	}

	public function test_all_five_tasks_are_scheduled_once(): void {
		Scheduler::schedule();
		Scheduler::schedule();
		foreach ( self::HOOKS as $hook ) {
			$count = 0;
			foreach ( (array) _get_cron_array() as $events ) {
				$count += isset( $events[ $hook ] ) ? count( $events[ $hook ] ) : 0;
			}
			$this->assertSame( 1, $count, $hook );
		}
		$this->assertSame( Scheduler::CRON_SCHEDULE, $this->recurrence( Scheduler::HOOK_RUN ) );
		$this->assertSame( Scheduler::CRON_SCHEDULE, $this->recurrence( Scheduler::HOOK_REAP ) );
		$this->assertSame( Scheduler::CRON_SCHEDULE, $this->recurrence( Scheduler::HOOK_PROBE ) );
		$this->assertSame( 'daily', $this->recurrence( Scheduler::HOOK_PRUNE ) );
		$this->assertSame( 'daily', $this->recurrence( Scheduler::HOOK_PURGE ) );
	}

	public function test_without_action_scheduler_the_fallback_is_wp_cron(): void {
		$this->assertFalse( Scheduler::usesActionScheduler(), 'the test site has no Action Scheduler' );
		$schedules = wp_get_schedules();
		$this->assertSame( 60, $schedules[ Scheduler::CRON_SCHEDULE ]['interval'] );
	}

	public function test_unscheduling_removes_every_task_and_nothing_else(): void {
		Scheduler::schedule();
		wp_schedule_single_event( time() + 500, 'some_other_plugin_hook' );
		Scheduler::unschedule();
		foreach ( self::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertNotFalse( wp_next_scheduled( 'some_other_plugin_hook' ) );
		wp_clear_scheduled_hook( 'some_other_plugin_hook' );
	}

	public function test_missing_tasks_are_put_back_in_admin_and_cron_requests_only(): void {
		delete_transient( 'estasoft_sync_scheduled' );
		Scheduler::ensureScheduled();
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_RUN ), 'a visitor\'s request does not do this work' );

		add_filter( 'wp_doing_cron', '__return_true' );
		Scheduler::ensureScheduled();
		foreach ( self::HOOKS as $hook ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertNotFalse( get_transient( 'estasoft_sync_scheduled' ) );

		Scheduler::unschedule();
		add_filter( 'wp_doing_cron', '__return_true' );
		Scheduler::ensureScheduled();
		Scheduler::ensureScheduled();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_RUN ) );
		remove_all_filters( 'wp_doing_cron' );
	}

	public function test_background_tasks_wait_for_visits_unless_wp_cron_is_switched_off(): void {
		$this->assertSame( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), Scheduler::cronRunsOnVisits() );
		$this->assertFalse( Scheduler::cronRunsOnVisits(), 'the test bootstrap sets DISABLE_WP_CRON' );
	}

	public function test_sync_now_schedules_a_run_each_time_even_within_ten_minutes(): void {
		$this->assertTrue( $this->h->scheduler->runNow() );
		usleep( 2000 );
		$this->assertTrue( $this->h->scheduler->runNow() );
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			$count += isset( $events[ Scheduler::HOOK_RUN ] ) ? count( $events[ Scheduler::HOOK_RUN ] ) : 0;
		}
		$this->assertSame( 2, $count );
	}

	public function test_the_run_task_sends_items_and_records_when_it_ran(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		$this->assertNull( $this->h->scheduler->lastRunAt() );
		$this->h->scheduler->handleRun();
		$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
		$this->assertSame( '2026-11-02 12:00:00', $this->h->scheduler->lastRunAt() );
	}

	public function test_the_reap_task_takes_back_dead_locks_and_ends_timed_pauses(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->queue->claim( 1, 60, 'dead' );
		$this->h->pause->pause( $conn, 1 );
		$this->h->clock->advance( 120 );
		$this->h->scheduler->handleReap();
		$this->assertSame( 'queued', $this->h->queue->findRow( $id )['state'] );
		$this->assertSame( 'active', $this->h->connections->findPublic( $conn )['status'] );
	}

	public function test_the_probe_task_resumes_a_connection_whose_system_answers_again(): void {
		$conn = $this->h->connection();
		$this->h->connections->tripCircuit( $conn, '2026-11-02 12:02:00' );
		$this->h->clock->advance( 121 );
		$this->h->scheduler->handleProbe();
		$this->assertSame( ConnectionStatus::Active->value, $this->h->connections->findPublic( $conn )['status'] );
	}

	public function test_the_prune_task_applies_retention(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->queue->markSynced( $id, '1', 'S1' );
		$this->h->logger->info( 'old' );
		$this->h->idempotency->reserve( hash( 'sha256', 'x' ), $conn, \Estasoft\SyncEngine\Domain\EntityType::Order, '1', 'created', 0 );
		$this->h->clock->advance( 100 * 86400 );
		$result = $this->h->retention->run();
		$this->assertSame( array( 'queue' => 1, 'disconnected' => 0, 'logs' => 1, 'idempotency' => 2, 'recon' => 0 ), $result );
		$this->h->scheduler->handlePrune();
	}

	public function test_the_purge_task_does_nothing_without_action_scheduler(): void {
		$this->assertSame( 0, $this->h->scheduler->handlePurge() );
	}

	public function test_registered_hooks_run_the_handlers_when_the_task_fires(): void {
		foreach ( self::HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
		Scheduler::registerHooks( fn(): Scheduler => $this->h->scheduler );
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '10490' );
		do_action( Scheduler::HOOK_RUN );
		$this->assertSame( 'synced', $this->h->queue->findRow( $id )['state'] );
		$this->assertNotFalse( has_action( 'init', array( Scheduler::class, 'ensureScheduled' ) ) );
		$this->assertNotFalse( has_filter( 'cron_schedules', array( Scheduler::class, 'cronSchedules' ) ) );
	}

	public function test_other_modules_can_add_tasks_with_their_own_interval(): void {
		$filter = static function ( array $tasks ): array {
			$tasks['estasoft_sync_test_task']   = 300;
			$tasks['estasoft_sync_too_fast']    = 5;
			$tasks['estasoft_sync_bad_interval'] = '300';
			return $tasks;
		};
		add_filter( 'estasoft_sync_scheduled_tasks', $filter );
		try {
			$tasks = Scheduler::tasks();
			$this->assertSame( 300, $tasks['estasoft_sync_test_task'] );
			$this->assertArrayNotHasKey( 'estasoft_sync_too_fast', $tasks, 'less than a minute is refused' );
			$this->assertArrayNotHasKey( 'estasoft_sync_bad_interval', $tasks, 'the interval must be an integer' );
			$this->assertCount( 6, $tasks, 'the five core tasks stay, plus the one accepted task' );

			Scheduler::schedule();
			$this->assertSame( 'estasoft_sync_every_300', $this->recurrence( 'estasoft_sync_test_task' ) );
			$schedules = Scheduler::cronSchedules( array() );
			$this->assertSame( 300, $schedules['estasoft_sync_every_300']['interval'] );

			Scheduler::unschedule();
			$this->assertFalse( wp_next_scheduled( 'estasoft_sync_test_task' ) );
		} finally {
			remove_filter( 'estasoft_sync_scheduled_tasks', $filter );
		}
	}

	public function test_a_filter_cannot_remove_the_core_tasks(): void {
		$filter = static fn(): array => array();
		add_filter( 'estasoft_sync_scheduled_tasks', $filter );
		try {
			$this->assertCount( 5, Scheduler::tasks() );
		} finally {
			remove_filter( 'estasoft_sync_scheduled_tasks', $filter );
		}
	}
}
