<?php
/**
 * The routes that hand work to the Jobs services: parameters in, answers out, with the services replaced by recorders.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestFake;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class JobsRoutesTest extends RestIntegrationCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
			),
			array(),
			array()
		);
		$this->actAs( 'administrator' );
	}

	/**
	 * Replace a service by a recorder.
	 *
	 * @param string              $key     Container key.
	 * @param array<string,mixed> $returns Answers by method.
	 */
	private function fake( string $key, array $returns = array() ): RestFake {
		$fake = new RestFake( $returns );
		$this->replace( $key, $fake );
		return $fake;
	}

	public function test_status_passes_the_optional_connection_and_returns_the_status_unchanged(): void {
		$status = $this->fake( JobServices::DASHBOARD, array( 'status' => array( 'mode' => 'normal', 'waiting' => 14 ) ) );
		$this->assertSame( array( 'mode' => 'normal', 'waiting' => 14 ), $this->call( 'GET', '/status' )->get_data() );
		$this->call( 'GET', '/status', array( 'connection_id' => $this->conn ) );
		$this->assertSame( array( array( null ), array( $this->conn ) ), $status->called( 'status' ) );
	}

	public function test_stock_rules_are_read_and_saved_for_the_default_connection_and_only_the_fields_sent(): void {
		$stock = $this->fake( JobServices::STOCK, array( 'rules' => array( 'enabled' => true ), 'saveRules' => array( 'enabled' => false ) ) );
		$this->assertSame( array( 'enabled' => true ), $this->call( 'GET', '/stock/rules' )->get_data() );
		$this->assertSame( array( array( $this->conn ) ), $stock->called( 'rules' ) );

		$saved = $this->call( 'PUT', '/stock/rules', array( 'safety_buffer' => 3, 'subtract_held' => false, 'delivered_becomes' => 'wc-Completed', 'tracking_enabled' => true ) );
		$this->assertSame( array( 'enabled' => false ), $saved->get_data() );
		$this->assertSame(
			array( $this->conn, array( 'safety_buffer' => 3, 'subtract_held' => false, 'tracking_enabled' => true, 'delivered_becomes' => 'wc-completed' ) ),
			$stock->called( 'saveRules' )[0],
			'only the fields that were sent, with the status slug cleaned'
		);
	}

	public function test_stock_rules_refuse_numbers_outside_the_documented_ranges_before_calling_the_service(): void {
		$stock = $this->fake( JobServices::STOCK );
		$this->assertSame( 400, $this->call( 'PUT', '/stock/rules', array( 'safety_buffer' => 1000 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/stock/rules', array( 'safety_buffer' => -1 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/stock/rules', array( 'enabled' => 'maybe' ) )->get_status() );
		$this->assertSame( array(), $stock->calls );
	}

	public function test_stock_overview_and_conflicts(): void {
		$stock = $this->fake( JobServices::STOCK, array( 'overview' => array( 'matched' => 4 ) ) );
		$this->actAs( 'shop_manager' );
		$this->assertSame( array( 'matched' => 4 ), $this->call( 'GET', '/stock/overview' )->get_data() );
		$this->assertSame( array( 'ok' => true ), $this->call( 'POST', '/stock/conflicts/12/accept' )->get_data() );
		$this->assertSame( array( 'ok' => true ), $this->call( 'POST', '/stock/conflicts/13/hold' )->get_data() );
		$this->assertSame( array( array( '12' ) ), $stock->called( 'acceptConflict' ) );
		$this->assertSame( array( array( '13' ) ), $stock->called( 'holdConflict' ) );
		$this->assertSame( array( array( null ) ), $stock->called( 'overview' ) );
	}

	public function test_reconciliation_routes(): void {
		$recon = $this->fake(
			JobServices::RECONCILIATION,
			array(
				'overview'     => array( 'runs' => array() ),
				'run'          => array( 'id' => 5, 'status' => 'running' ),
				'repair'       => array( 'id' => 8, 'status' => 'repaired' ),
				'ignore'       => array( 'id' => 8, 'status' => 'ignored' ),
				'saveSettings' => array( 'window_days' => 14 ),
			)
		);
		$this->actAs( 'shop_manager' );
		$this->assertSame( array( 'runs' => array() ), $this->call( 'GET', '/reconciliation' )->get_data() );
		$this->assertSame( 5, $this->call( 'POST', '/reconciliation/run', array( 'window_days' => 7 ) )->get_data()['id'] );
		$this->assertSame( 'repaired', $this->call( 'POST', '/reconciliation/differences/8/repair', array( 'direction' => 'wc_to_system' ) )->get_data()['status'] );
		$this->assertSame( 'ignored', $this->call( 'POST', '/reconciliation/differences/8/ignore', array( 'reason' => 'Test order' ) )->get_data()['status'] );
		$this->assertSame( array( 7, null, 'manual' ), $recon->called( 'run' )[0] );
		$this->assertSame( array( 8, 'wc_to_system', $this->userId( 'shop_manager' ) ), $recon->called( 'repair' )[0], 'who acted is recorded' );
		$this->assertSame( array( 8, 'Test order', $this->userId( 'shop_manager' ) ), $recon->called( 'ignore' )[0] );

		$this->assertSame( 403, $this->call( 'PUT', '/reconciliation/settings', array( 'window_days' => 14 ) )->get_status(), 'the schedule is configuration' );
		$this->actAs( 'administrator' );
		$this->call( 'PUT', '/reconciliation/settings', array( 'window_days' => 14, 'schedule_time' => '03:30', 'auto_repair_missing' => true ) );
		$this->assertSame( array( array( array( 'schedule_time' => '03:30', 'window_days' => 14, 'auto_repair_missing' => true ) ) ), $recon->called( 'saveSettings' ) );
	}

	public function test_reconciliation_refuses_a_wrong_direction_an_empty_reason_and_a_bad_time(): void {
		$this->fake( JobServices::RECONCILIATION );
		$this->assertSame( 400, $this->call( 'POST', '/reconciliation/differences/8/repair', array( 'direction' => 'sideways' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/reconciliation/differences/8/ignore', array() )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/reconciliation/run', array( 'window_days' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/reconciliation/settings', array( 'schedule_time' => '25:99' ) )->get_status() );
	}

	public function test_invoices_are_a_page_with_headers_and_the_filters_reach_the_service(): void {
		$invoices = $this->fake( JobServices::INVOICES, array( 'list' => array( 'items' => array(), 'total' => 45, 'total_pages' => 2, 'page' => 1, 'per_page' => 25 ) ) );
		$response = $this->call( 'GET', '/invoices', array( 'status' => 'rejected', 'search' => ' 10490 ', 'page' => 2 ) );
		$this->assertSame( '45', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( array( array( array( 'status' => 'rejected', 'search' => '10490', 'page' => 2, 'per_page' => 25 ) ) ), $invoices->called( 'list' ) );
		$this->assertSame( 400, $this->call( 'GET', '/invoices', array( 'status' => 'paid' ) )->get_status() );
	}

	public function test_readiness_and_the_support_report(): void {
		$readiness = $this->fake( JobServices::READINESS, array( 'last' => array( 'checks' => array() ), 'run' => array( 'blocked' => false ) ) );
		$report    = $this->fake( JobServices::SUPPORT_REPORT, array( 'build' => array( 'text' => 'report' ) ) );
		$this->actAs( 'shop_manager' );
		$this->assertSame( array( 'checks' => array() ), $this->call( 'GET', '/readiness' )->get_data() );
		$this->assertSame( array( 'blocked' => false ), $this->call( 'POST', '/readiness/run' )->get_data() );
		$this->call( 'GET', '/support-report', array( 'order_numbers' => '1' ) );
		$this->call( 'GET', '/support-report', array( 'order_numbers' => '0' ) );
		$this->call( 'GET', '/support-report' );
		$this->assertSame( array( array( true ), array( false ), array( false ) ), $report->called( 'build' ) );
		$this->assertSame( 1, count( $readiness->called( 'run' ) ) );
	}

	public function test_the_test_email_answers_with_the_addresses_or_a_sync_error(): void {
		$sent = $this->fake( JobServices::NOTIFICATIONS, array( 'sendTest' => array( 'sent' => true, 'to' => 'owner@example.test, ops@example.test' ) ) );
		$ok   = $this->call( 'POST', '/notifications/test' );
		$this->assertSame( array( 'sent_to' => array( 'owner@example.test', 'ops@example.test' ) ), $ok->get_data() );
		$this->assertSame( array( array( '' ) ), $sent->called( 'sendTest' ) );
		$this->call( 'POST', '/notifications/test', array( 'recipient' => 'me@example.test' ) );
		$this->assertSame( array( 'me@example.test' ), $sent->called( 'sendTest' )[1] );

		$this->fake( JobServices::NOTIFICATIONS, array( 'sendTest' => array( 'sent' => false, 'to' => 'owner@example.test' ) ) );
		$failed = $this->call( 'POST', '/notifications/test' );
		$this->assertSame( 502, $failed->get_status() );
		$this->assertSame( 'SYNC-REST-002', $failed->get_data()['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/notifications/test', array( 'recipient' => 'not an address' ) )->get_status() );
	}

	public function test_tools_export_import_preview_and_apply(): void {
		$porter = $this->fake(
			JobServices::PORTER,
			array(
				'export'  => array( 'filename' => 'estasoft-sync-settings.json', 'json' => '{}' ),
				'preview' => array( 'preview' => array( 'Log retention: 14 days to 30 days' ) ),
				'apply'   => array( 'applied' => true, 'drafts' => 2, 'skipped' => array( 'xero', 'odoo:orders' ), 'internal' => 'not part of the answer' ),
			)
		);
		$this->assertSame( 'estasoft-sync-settings.json', $this->call( 'POST', '/tools/export' )->get_data()['filename'] );

		$file = "{\n  \"estasoft_sync\": 1,\n  \"settings\": {\"redaction\": true}\n}";
		$this->assertSame( array( 'preview' => array( 'Log retention: 14 days to 30 days' ) ), $this->call( 'POST', '/tools/import', array( 'json' => $file ) )->get_data() );
		$this->assertSame( array( 'applied' => true, 'drafts' => 2, 'skipped' => array( 'xero', 'odoo:orders' ) ), $this->call( 'POST', '/tools/import', array( 'json' => $file, 'confirm' => true ) )->get_data(), 'exactly the keys of the type' );
		$this->assertSame( array( array( $file ) ), $porter->called( 'preview' ), 'the file reaches the porter exactly as sent' );
		$this->assertSame( array( array( $file, $this->userId( 'administrator' ) ) ), $porter->called( 'apply' ) );
		$this->assertSame( 400, $this->call( 'POST', '/tools/import', array() )->get_status() );
	}

	public function test_delete_all_data_needs_the_typed_word_and_leaves_the_capabilities(): void {
		$eraser = $this->fake( Services::DATA_ERASER );
		$wrong  = $this->call( 'POST', '/tools/delete-data', array( 'word' => 'delete' ) );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertArrayHasKey( 'word', $wrong->get_data()['data']['fields'] );
		$this->assertSame( array(), $eraser->calls, 'nothing was erased' );

		$done = $this->call( 'POST', '/tools/delete-data', array( 'word' => 'DELETE' ) );
		$this->assertSame( array( 'deleted' => true ), $done->get_data() );
		$this->assertSame( array( array( false ) ), $eraser->called( 'erase' ), 'erase( false ): the capabilities stay so the person can set up again' );
	}

	public function test_capacity_onboarding_shadow_orders_and_catalog_pass_through(): void {
		$this->fake( JobServices::CAPACITY, array( 'run' => array( 'items_per_minute' => 900 ) ) );
		$onboarding = $this->fake( JobServices::ONBOARDING, array( 'get' => array( 'step' => 2 ), 'save' => array( 'step' => 3 ), 'sampleOrders' => array( array( 'id' => 10490 ) ) ) );
		$goLive     = $this->fake( JobServices::GO_LIVE, array( 'goLive' => array( 'completed' => true ) ) );
		$shadow     = $this->fake( JobServices::SHADOW, array( 'start' => array( 'step' => 5 ), 'report' => array( 'rows' => array() ), 'end' => array( 'step' => 5 ) ) );
		$this->fake( JobServices::CONNECTIONS, array( 'catalog' => array( array( 'name' => 'Odoo' ) ) ) );

		$this->assertSame( 900, $this->call( 'POST', '/capacity-test' )->get_data()['items_per_minute'] );
		$this->assertSame( array( 'step' => 2 ), $this->call( 'GET', '/onboarding' )->get_data() );
		$this->assertSame( array( array( 'id' => 10490 ) ), $this->call( 'GET', '/orders/sample' )->get_data() );
		$this->assertSame( array( array( 'name' => 'Odoo', 'logo' => '' ) ), $this->call( 'GET', '/catalog' )->get_data() );

		$this->call( 'PUT', '/onboarding', array( 'step' => 3, 'connector' => 'Odoo<b>', 'answers' => array( 'stock' => true ) ) );
		$this->call( 'PUT', '/onboarding', array( 'connector' => null ) );
		$this->assertSame( array( array( array( 'step' => 3, 'connector' => 'odoob', 'answers' => array( 'stock' => true ) ) ), array( array( 'connector' => null ) ) ), $onboarding->called( 'save' ) );
		$this->assertSame( 400, $this->call( 'PUT', '/onboarding', array( 'step' => 9 ) )->get_status() );

		$this->call( 'POST', '/go-live', array( 'mode' => 'live' ) );
		$this->assertSame( array( array( 'live', $this->userId( 'administrator' ) ) ), $goLive->called( 'goLive' ) );
		$this->assertSame( 400, $this->call( 'POST', '/go-live', array( 'mode' => 'maybe' ) )->get_status() );

		$this->call( 'POST', '/shadow/start' );
		$this->call( 'POST', '/shadow/start', array( 'days' => 3 ) );
		$this->assertSame( array( array( $this->conn, 7 ), array( $this->conn, 3 ) ), $shadow->called( 'start' ) );
		$this->assertSame( 400, $this->call( 'POST', '/shadow/start', array( 'days' => 31 ) )->get_status() );
		$this->call( 'GET', '/shadow/report' );
		$this->call( 'POST', '/shadow/end' );
		$this->assertSame( array( array( null ) ), $shadow->called( 'report' ) );
		$this->assertSame( array( array( $this->userId( 'administrator' ) ) ), $shadow->called( 'end' ) );
	}

	public function test_a_shop_manager_can_read_the_shadow_report_but_not_start_or_end_shadow_mode_or_go_live(): void {
		$this->fake( JobServices::SHADOW, array( 'report' => array( 'rows' => array() ) ) );
		$this->fake( JobServices::GO_LIVE );
		$this->actAs( 'shop_manager' );
		$this->assertSame( 200, $this->call( 'GET', '/shadow/report' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/shadow/end' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/shadow/start' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/go-live', array( 'mode' => 'live' ) )->get_status() );
	}

	public function test_a_refusal_from_a_jobs_service_keeps_its_status_and_message(): void {
		$this->replace(
			JobServices::RECONCILIATION,
			new RestFake( array(), array( 'repair' => new \Estasoft\SyncEngine\Jobs\JobException( 'This difference is already closed.', 409 ) ) )
		);
		$response = $this->call( 'POST', '/reconciliation/differences/8/repair', array( 'direction' => 'wc_to_system' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'estasoft_sync_conflict', $response->get_data()['code'] );
		$this->assertSame( 'This difference is already closed.', $response->get_data()['message'] );
	}
}
