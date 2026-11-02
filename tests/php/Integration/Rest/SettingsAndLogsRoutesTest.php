<?php
/**
 * Settings, tools and logs against the real services.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Rest\Controllers\LogsController;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class SettingsAndLogsRoutesTest extends RestIntegrationCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Settings::OPTION );
		$this->actAs( 'administrator' );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_settings_are_read_by_a_shop_manager_and_changed_only_by_an_administrator(): void {
		$this->actAs( 'shop_manager' );
		$settings = $this->call( 'GET', '/settings' )->get_data();
		$this->assertSame( 14, $settings['log_retention_days'] );
		$this->assertSame( 'standard', $settings['runtime_profile'] );
		$this->assertTrue( $settings['redaction'] );
		$this->assertSame( 403, $this->call( 'PUT', '/settings', array( 'redaction' => false ) )->get_status() );
		$this->assertTrue( $this->call( 'GET', '/settings' )->get_data()['redaction'], 'nothing changed' );
	}

	public function test_settings_save_the_fields_sent_and_return_all_of_them(): void {
		$saved = $this->call(
			'PUT',
			'/settings',
			array(
				'log_retention_days' => 30,
				'admin_bar'          => false,
				'notifications'      => array( 'recipients' => array( 'ops@example.test' ), 'digest_time' => '07:30' ),
			)
		);
		$data = $saved->get_data();
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 30, $data['log_retention_days'] );
		$this->assertFalse( $data['admin_bar'] );
		$this->assertSame( array( 'ops@example.test' ), $data['notifications']['recipients'] );
		$this->assertSame( '07:30', $data['notifications']['digest_time'] );
		$this->assertTrue( $data['notifications']['on_paused'], 'what was not sent is unchanged' );
		$this->assertSame( $data, $this->call( 'GET', '/settings' )->get_data() );
	}

	public function test_numbers_outside_the_ranges_are_refused_with_a_message_for_the_field(): void {
		foreach ( array( array( 'log_retention_days', 10 ), array( 'backlog_threshold', 5 ), array( 'global_concurrency', 11 ), array( 'log_excerpt_bytes', 1000 ), array( 'runtime_profile', 'turbo' ) ) as $case ) {
			$response = $this->call( 'PUT', '/settings', array( $case[0] => $case[1] ) );
			$this->assertSame( 400, $response->get_status(), $case[0] );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
		$this->assertSame( 14, $this->call( 'GET', '/settings' )->get_data()['log_retention_days'] );
	}

	public function test_a_bad_recipient_is_a_422_with_the_field_and_nothing_is_saved(): void {
		$response = $this->call( 'PUT', '/settings', array( 'admin_bar' => false, 'notifications' => array( 'recipients' => array( 'not an address' ) ) ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'notifications.recipients', $response->get_data()['data']['fields'] );
		$this->assertTrue( $this->call( 'GET', '/settings' )->get_data()['admin_bar'], 'all or nothing' );
	}

	public function test_turning_redaction_off_is_written_to_the_log(): void {
		$this->call( 'PUT', '/settings', array( 'redaction' => false ) );
		$logged = (string) wp_json_encode( $this->call( 'GET', '/logs', array( 'level' => 'warning' ) )->get_data() );
		$this->assertStringContainsString( 'Redaction was turned off', $logged );
	}

	public function test_clear_logs_empties_the_log_and_says_how_many_entries_went(): void {
		$logger = $this->container->get( Services::LOGGER );
		$logger->info( 'one' );
		$logger->info( 'two' );
		$cleared = $this->call( 'POST', '/tools/clear-logs' )->get_data()['cleared'];
		$this->assertGreaterThanOrEqual( 2, $cleared );
		$this->assertSame( 0, $this->call( 'GET', '/logs' )->get_data()['total'] );
	}

	public function test_logs_are_filtered_paged_and_one_entry_has_its_detail(): void {
		$logger = $this->container->get( Services::LOGGER );
		$logger->info( 'Everything fine' );
		$logger->warning( 'Slow answer', array( 'code' => 'SYNC-NET-001' ) );
		$logger->error( 'Order rejected', array( 'code' => 'SYNC-VAL-002', 'entity_ref' => '#10491' ) );

		$response = $this->call( 'GET', '/logs', array( 'level' => 'error' ) );
		$data     = $response->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'Order rejected', $data['items'][0]['message'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertCount( 1, $this->call( 'GET', '/logs', array( 'code' => 'SYNC-NET-001' ) )->get_data()['items'] );
		$this->assertCount( 1, $this->call( 'GET', '/logs', array( 'search' => '10491' ) )->get_data()['items'] );
		$this->assertSame( 400, $this->call( 'GET', '/logs', array( 'per_page' => 5 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/logs', array( 'level' => 'debug' ) )->get_status() );

		$id     = $data['items'][0]['id'];
		$detail = $this->call( 'GET', "/logs/$id" )->get_data();
		$this->assertSame( $id, $detail['id'] );
		$this->assertArrayHasKey( 'request_excerpt', $detail );
		$this->assertSame( 404, $this->call( 'GET', '/logs/999999' )->get_status() );
	}

	public function test_a_request_that_accepts_csv_gets_a_download_that_matches_the_screen_and_is_safe_in_a_spreadsheet(): void {
		$logger = $this->container->get( Services::LOGGER );
		$logger->error( '=HYPERLINK("http://evil.example","click")', array( 'code' => 'SYNC-VAL-002' ) );
		$logger->info( 'A plain entry' );

		$response = $this->call( 'GET', '/logs', array( 'level' => 'error' ), array( 'Accept' => 'text/csv' ) );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertStringStartsWith( 'text/csv', $headers['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^attachment; filename="estasoft-sync-logs-\d{8}-\d{6}\.csv"$/', $headers['Content-Disposition'] );
		$csv = $response->get_data();
		$this->assertIsString( $csv );
		$this->assertStringStartsWith( '"time","level","code","message"', $csv );
		$this->assertStringNotContainsString( 'A plain entry', $csv, 'the filter applies to the download' );
		$this->assertStringContainsString( '"\'=HYPERLINK', $csv, 'a cell that starts with = is made harmless' );
		$this->assertStringNotContainsString( '"=HYPERLINK', $csv );

		// The filter that sends it as plain text.
		$controller = new LogsController( $this->container, $this->container->get( 'rest.errors' ), $this->container->get( 'rest.idempotency' ), $this->container->get( 'rest.resolver' ) );
		ob_start();
		$served = $controller->serveCsv( false, $response );
		$output = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( $csv, $output );
		$json = $this->call( 'GET', '/logs' );
		ob_start();
		$this->assertFalse( $controller->serveCsv( false, $json ), 'a JSON answer is left to WordPress' );
		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertTrue( $controller->serveCsv( true, $json ), 'something else already sent the answer' );
	}
}
