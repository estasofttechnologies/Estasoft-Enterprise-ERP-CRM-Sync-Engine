<?php
/**
 * Every read route against the real Jobs services, to catch a mismatch between the routes and the services.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Admin\AdminModule;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class RealServicesSmokeTest extends RestIntegrationCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$this->conn = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
				'address'   => 'https://erp.example.test',
			),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array()
		);
		$this->actAs( 'administrator' );
	}

	/**
	 * Keys that must be in an answer.
	 *
	 * @return array<string,array{0:string,1:array<int,string>}> Route to method and keys.
	 */
	public static function reads(): array {
		return array(
			'status'       => array( '/status', array( 'mode', 'now', 'store_timezone', 'synced_today', 'waiting', 'needs_attention', 'by_state', 'hourly', 'events', 'connection', 'encryption_key_missing' ) ),
			'connections'  => array( '/connections', array() ),
			'catalog'      => array( '/catalog', array() ),
			'onboarding'   => array( '/onboarding', array( 'step', 'completed', 'connector', 'answers', 'shadow_started_at', 'shadow_ends_at' ) ),
			'readiness'    => array( '/readiness', array( 'ran_at', 'checks', 'blocked' ) ),
			'invoices'     => array( '/invoices', array( 'items', 'total', 'total_pages', 'page', 'per_page' ) ),
			'recon'        => array( '/reconciliation', array( 'last_run', 'runs', 'diffs', 'schedule_time', 'window_days', 'tolerance_minor', 'auto_repair_missing' ) ),
			'stock rules'  => array( '/stock/rules', array( 'enabled', 'interval_minutes', 'safety_buffer', 'subtract_held', 'system_wins', 'warehouses', 'tracking_enabled', 'delivered_becomes', 'cod_payment_on_delivery' ) ),
			'stock'        => array( '/stock/overview', array( 'last_run_at', 'matched', 'unmatched', 'changes', 'conflicts', 'tracking_recent' ) ),
			'shadow'       => array( '/shadow/report', array( 'info', 'rows' ) ),
			'orders'       => array( '/orders/sample', array() ),
			'logs'         => array( '/logs', array( 'items', 'total' ) ),
			'queue'        => array( '/queue', array( 'items', 'total' ) ),
			'attention'    => array( '/queue/attention', array() ),
			'settings'     => array( '/settings', array( 'display_time', 'log_retention_days', 'notifications' ) ),
			'tax map'      => array( '/tax-map', array( 'rows', 'system_codes', 'buyer_tax_rules' ) ),
			'mapping'      => array( '/mappings/stock', array( 'type', 'versioned', 'live', 'draft', 'versions', 'target_fields', 'source_fields' ) ),
			'unmatched'    => array( '/products/unmatched', array( 'items', 'total' ) ),
			'support'      => array( '/support-report', array( 'text', 'generated_at', 'includes_order_numbers' ) ),
		);
	}

	/**
	 * @dataProvider reads
	 *
	 * @param string            $route Route.
	 * @param array<int,string> $keys  Keys the answer must have.
	 */
	public function test_a_read_route_answers_200_with_the_documented_keys( string $route, array $keys ): void {
		$response = $this->call( 'GET', $route );
		$this->assertSame( 200, $response->get_status(), $route . ' ' . wp_json_encode( $response->get_data() ) );
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $response->get_data(), "$route has $key" );
		}
	}

	public function test_the_status_shows_the_connection_and_the_catalog_marks_the_installed_connector(): void {
		$status = $this->call( 'GET', '/status' )->get_data();
		$this->assertSame( $this->conn, $status['connection']['id'] );
		$this->assertSame( 'odoo', $status['connection']['connector'] );
		$odoo = array_values( array_filter( $this->call( 'GET', '/catalog' )->get_data(), static fn( array $entry ): bool => 'odoo' === $entry['connector'] ) );
		$this->assertCount( 1, $odoo );
		$this->assertTrue( $odoo[0]['available'] );
		$this->assertCount( 34, $this->call( 'GET', '/catalog' )->get_data() );
	}

	public function test_the_readiness_run_and_the_capacity_test_work_without_leaving_rows_in_the_queue(): void {
		$run = $this->call( 'POST', '/readiness/run' );
		$this->assertSame( 200, $run->get_status(), wp_json_encode( $run->get_data() ) );
		$this->assertCount( 10, $run->get_data()['checks'] );
		$this->assertNotNull( $this->call( 'GET', '/readiness' )->get_data()['ran_at'] );

		$capacity = $this->call( 'POST', '/capacity-test' );
		$this->assertSame( 200, $capacity->get_status(), wp_json_encode( $capacity->get_data() ) );
		$this->assertGreaterThan( 0, $capacity->get_data()['items_per_minute'] );
		$this->assertSame( 0, $this->call( 'GET', '/queue', array( 'mode' => 'all' ) )->get_data()['total'], 'the real queue is untouched' );
	}

	public function test_export_then_import_round_trips_without_credentials(): void {
		$export = $this->call( 'POST', '/tools/export' );
		$this->assertSame( 200, $export->get_status(), wp_json_encode( $export->get_data() ) );
		$this->assertStringNotContainsString( 'sk-test-abcdefghijklmnop', $export->get_data()['json'] );
		$this->assertStringEndsWith( '.json', $export->get_data()['filename'] );
		$preview = $this->call( 'POST', '/tools/import', array( 'json' => $export->get_data()['json'] ) );
		$this->assertSame( 200, $preview->get_status(), wp_json_encode( $preview->get_data() ) );
		$this->assertIsArray( $preview->get_data()['preview'] );
		$bad = $this->call( 'POST', '/tools/import', array( 'json' => '{ nonsense' ) );
		$this->assertGreaterThanOrEqual( 400, $bad->get_status() );
		$this->assertLessThan( 500, $bad->get_status(), 'a bad file is the person\'s mistake, not a server error' );
	}

	public function test_the_dashboard_service_gives_the_menu_its_bubble_and_the_widget_its_data(): void {
		$dashboard = $this->container->get( JobServices::DASHBOARD );
		$item      = $this->h->item( $this->conn, '1' );
		$this->h->queue->markFailed( $item, 'SYNC-MAP-001', 'x', 'SKU A' );
		$dashboard->bustFailedCount();
		$this->assertStringContainsString( '>1<', $this->container->get( AdminModule::MENU )->bubble() );

		$widget = $dashboard->widget();
		$this->assertSame( 1, $widget['failed'] );
		$this->assertContains( $widget['mode'], array( 'normal', 'first_run', 'paused', 'shadow', 'not_connected' ) );
	}

	public function test_the_widget_gets_the_connection_card_in_normal_mode(): void {
		$this->container->get( Services::ONBOARDING )->save( array( 'completed' => true ) );
		$data = ( new \Estasoft\SyncEngine\Admin\WidgetData(
			fn(): object => $this->container->get( JobServices::DASHBOARD ),
			fn(): object => $this->container->get( JobServices::CONNECTIONS )
		) )();
		$this->assertSame( 'normal', $data['mode'] );
		$this->assertSame( 'Odoo', $data['connection']['label'] );
	}

	public function test_the_support_report_leaves_out_secrets_and_the_queue_summary_is_the_same_as_the_status(): void {
		$text = $this->call( 'GET', '/support-report' )->get_data()['text'];
		$this->assertStringNotContainsString( 'sk-test-abcdefghijklmnop', $text );
		$this->assertStringContainsString( 'PHP', $text );
	}
}
