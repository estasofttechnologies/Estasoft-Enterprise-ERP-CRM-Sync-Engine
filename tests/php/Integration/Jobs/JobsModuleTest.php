<?php
/**
 * The module wires itself into the real plugin.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Jobs\Capacity\CapacityTest;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\Dashboard\DashboardService;
use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\Invoices\InvoiceService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\JobsModule;
use Estasoft\SyncEngine\Jobs\Notifications\NotificationService;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingService;
use Estasoft\SyncEngine\Jobs\Porter\SettingsPorter;
use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconService;
use Estasoft\SyncEngine\Jobs\Shadow\GoLiveService;
use Estasoft\SyncEngine\Jobs\Shadow\ShadowService;
use Estasoft\SyncEngine\Jobs\Stock\StockService;
use Estasoft\SyncEngine\Jobs\Support\SupportReportBuilder;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingService;
use Estasoft\SyncEngine\Plugin;
use Estasoft\SyncEngine\Tests\Support\IntegrationTestCase;

/**
 * Runs with the real plugin hooks in place, so it does not use the Core test case (which empties them for the length of a test).
 */
final class JobsModuleTest extends IntegrationTestCase {

	/**
	 * Every contract constant and the class behind it.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function services(): array {
		return array(
			'stock'          => array( JobServices::STOCK, StockService::class ),
			'tracking'       => array( JobServices::TRACKING, TrackingService::class ),
			'invoices'       => array( JobServices::INVOICES, InvoiceService::class ),
			'reconciliation' => array( JobServices::RECONCILIATION, ReconService::class ),
			'shadow'         => array( JobServices::SHADOW, ShadowService::class ),
			'go live'        => array( JobServices::GO_LIVE, GoLiveService::class ),
			'readiness'      => array( JobServices::READINESS, ReadinessService::class ),
			'support report' => array( JobServices::SUPPORT_REPORT, SupportReportBuilder::class ),
			'porter'         => array( JobServices::PORTER, SettingsPorter::class ),
			'notifications'  => array( JobServices::NOTIFICATIONS, NotificationService::class ),
			'capacity'       => array( JobServices::CAPACITY, CapacityTest::class ),
			'inbound'        => array( JobServices::INBOUND, InboundHandler::class ),
			'dashboard'      => array( JobServices::DASHBOARD, DashboardService::class ),
			'connections'    => array( JobServices::CONNECTIONS, ConnectionService::class ),
			'onboarding'     => array( JobServices::ONBOARDING, OnboardingService::class ),
		);
	}

	/**
	 * @dataProvider services
	 *
	 * @param string $key   Container constant.
	 * @param string $class Class behind it.
	 */
	public function test_each_service_of_the_contract_is_registered_and_also_under_its_class_name( string $key, string $class ): void {
		$container = Plugin::instance()->container();

		$this->assertTrue( $container->has( $key ) );
		$this->assertInstanceOf( $class, $container->get( $key ) );
		$this->assertSame( $container->get( $key ), $container->get( $class ) );
	}

	public function test_the_recurring_tasks_are_added_through_the_filter_with_the_contract_intervals(): void {
		$tasks = apply_filters( 'estasoft_sync_scheduled_tasks', array() );

		$this->assertSame( 300, $tasks[ JobsModule::HOOK_STOCK ] );
		$this->assertSame( 600, $tasks[ JobsModule::HOOK_TRACKING ] );
		$this->assertSame( 600, $tasks[ JobsModule::HOOK_INVOICES ] );
		$this->assertSame( 3600, $tasks[ JobsModule::HOOK_RECONCILE ] );
		$this->assertSame( 900, $tasks[ JobsModule::HOOK_DIGEST ] );
		foreach ( $tasks as $interval ) {
			$this->assertGreaterThanOrEqual( 60, $interval );
		}
		$all = Scheduler::tasks();
		$this->assertSame( 300, $all[ JobsModule::HOOK_STOCK ] );
		$this->assertArrayHasKey( Scheduler::HOOK_RUN, $all, 'the core tasks stay' );
	}

	public function test_a_handler_is_registered_for_every_task_and_event(): void {
		foreach ( array_keys( JobsModule::TASKS ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), $hook );
		}
		foreach ( array( JobsModule::HOOK_CONTINUE, JobsModule::HOOK_INBOUND, 'estasoft_sync_connection_paused', 'estasoft_sync_connection_resumed' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), $hook );
		}
	}

	public function test_the_task_handlers_run_without_a_connection_and_without_error(): void {
		foreach ( array_keys( JobsModule::TASKS ) as $hook ) {
			do_action( $hook );
		}
		do_action( JobsModule::HOOK_CONTINUE );
		do_action( JobsModule::HOOK_INBOUND, 0, 'stock' );
		$this->addToAssertionCount( 1 );
	}

	public function test_the_meta_keys_this_module_writes_are_removed_on_uninstall(): void {
		$this->assertContains( '_estasoft_sync_tracking', MetaKeys::order() );
		$this->assertContains( '_estasoft_sync_invoice', MetaKeys::order() );
		$this->assertContains( '_estasoft_sync_stock_buffer', MetaKeys::product() );
	}

	public function test_the_failed_count_cache_is_dropped_when_an_item_fails_or_syncs(): void {
		foreach ( array( 'estasoft_sync_item_failed', 'estasoft_sync_item_synced', 'estasoft_sync_connection_resumed' ) as $hook ) {
			set_transient( DashboardService::FAILED_KEY, 5, 60 );
			do_action( $hook, 1, 2, 3 );
			$this->assertFalse( get_transient( DashboardService::FAILED_KEY ), $hook );
		}
	}

	public function test_the_dashboard_service_of_the_real_container_reads_the_real_tables(): void {
		$dashboard = Plugin::instance()->container()->get( JobServices::DASHBOARD );
		$status    = $dashboard->status();
		$this->assertSame( 'not_connected', $status['mode'] );
		$this->assertSame( 0, $dashboard->failedCount() );
		delete_transient( DashboardService::FAILED_KEY );
	}

	public function test_the_real_readiness_run_has_ten_checks_on_a_real_site(): void {
		$result = Plugin::instance()->container()->get( JobServices::READINESS )->run();
		$this->assertCount( 10, $result['checks'] );
		$this->assertContains( $result['checks'][8]['status'], array( 'passed', 'needs_fixing' ) );
		$this->assertSame( 'passed', $result['checks'][7]['status'], 'utf8mb4 tables on this install' );
		delete_option( ReadinessService::OPTION );
	}

	public function test_the_real_support_report_builds_on_a_real_site(): void {
		$report = Plugin::instance()->container()->get( JobServices::SUPPORT_REPORT )->build();
		$this->assertStringContainsString( 'WordPress: ' . get_bloginfo( 'version' ), $report['text'] );
		$this->assertStringContainsString( 'WooCommerce: ' . WC_VERSION, $report['text'] );
	}

	public function test_the_real_capacity_test_runs_in_a_temporary_table_and_leaves_the_real_tables_alone(): void {
		global $wpdb;
		$tables = Plugin::instance()->container()->get( \Estasoft\SyncEngine\Core\Services::TABLES );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables->queue()}" );
		$result = Plugin::instance()->container()->get( JobServices::CAPACITY )->run();
		$this->assertGreaterThan( 0, $result['items_per_minute'] );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables->queue()}" ) );
		$this->assertSame( array(), $wpdb->get_col( "SHOW TABLES LIKE '%estasoft_sync_cap_%'" ) );
		delete_option( CapacityTest::OPTION );
	}
}
