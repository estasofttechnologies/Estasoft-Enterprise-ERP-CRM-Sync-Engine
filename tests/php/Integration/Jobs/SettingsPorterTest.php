<?php
/**
 * SET-4 and SET-5.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Porter\SettingsPorter;

final class SettingsPorterTest extends JobsTestCase {

	private int $connection = 0;

	/**
	 * Two rules for the orders mapping.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rules(): array {
		return array(
			array(
				'id'         => 'r1',
				'target'     => 'partner.name',
				'source'     => 'billing.first_name',
				'transforms' => array(),
				'required'   => true,
			),
			array(
				'id'         => 'r2',
				'target'     => 'partner.email',
				'source'     => 'billing.email',
				'transforms' => array(),
				'required'   => false,
			),
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect( array( 'requests_per_second' => 3 ) );
		$this->h->mappings->seedLive( $this->connection, 'orders', $this->rules(), 'system' );
		$this->h->settings->update( array( 'log_retention_days' => 30, 'notifications' => array( 'recipients' => array( 'ops@example.test' ) ) ) );
		$this->j->stockService->saveRules( $this->connection, array( 'enabled' => true, 'safety_buffer' => 7, 'tracking_enabled' => true ) );
	}

	public function test_the_export_has_settings_mappings_and_limits_and_no_credentials_or_order_data(): void {
		$export = $this->j->porter->export();

		$this->assertMatchesRegularExpression( '/^estasoft-sync-settings-2026-11-02\.json$/', $export['filename'] );
		$data = json_decode( $export['json'], true );
		$this->assertSame( 1, $data['estasoft_sync'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $data['exported_at'] );
		$this->assertSame( 30, $data['settings']['log_retention_days'] );
		$this->assertSame( array( 'ops@example.test' ), $data['settings']['notifications']['recipients'] );
		$this->assertCount( 2, $data['mappings']['fake']['orders'] );
		$this->assertSame( 3, $data['connection_settings']['fake']['reliability']['requests_per_second'] );
		$this->assertSame( 7, $data['connection_settings']['fake']['stock']['safety_buffer'] );
		$this->assertTrue( $data['connection_settings']['fake']['tracking']['tracking_enabled'] );
		foreach ( array( 'sk-test', 'api_key', 'address', 'erp.example.test', 'secret', 'password' ) as $forbidden ) {
			$this->assertStringNotContainsStringIgnoringCase( $forbidden, $export['json'], $forbidden . ' must not be exported' );
		}
	}

	public function test_preview_says_what_would_change_in_words_and_changes_nothing(): void {
		$json = $this->j->porter->export()['json'];
		$data = json_decode( $json, true );
		$data['settings']['log_retention_days']                     = 7;
		$data['settings']['notifications']['on_backlog']            = false;
		$data['connection_settings']['fake']['stock']['safety_buffer'] = 3;
		$data['mappings']['ghost']['orders']                         = $this->rules();

		$preview = $this->j->porter->preview( (string) json_encode( $data ) )['preview'];

		$text = implode( "\n", $preview );
		$this->assertStringContainsString( 'Days to keep logs: from 30 to 7.', $text );
		$this->assertStringContainsString( 'Notifications, when the queue backs up: from on to off.', $text );
		$this->assertStringContainsString( 'fake, Safety buffer: from 7 to 3.', $text );
		$this->assertStringContainsString( 'orders mapping for ghost is skipped because it is not connected here', $text );
		$this->assertSame( 30, $this->h->settings->logRetentionDays() );
		$this->assertSame( 7, $this->j->stockService->rules( $this->connection )['safety_buffer'] );
		$this->assertSame( array(), $this->j->drafts );
	}

	public function test_a_file_that_changes_nothing_says_so(): void {
		$this->assertSame( array( 'Nothing would change.' ), $this->j->porter->preview( $this->j->porter->export()['json'] )['preview'] );
	}

	public function test_apply_updates_settings_and_limits_and_creates_drafts_never_live_versions(): void {
		$data = json_decode( $this->j->porter->export()['json'], true );
		$data['settings']['log_retention_days']                        = 7;
		$data['connection_settings']['fake']['reliability']['concurrency'] = 1;
		$data['connection_settings']['fake']['stock']['safety_buffer']  = 3;
		$data['mappings']['fake']['orders'][0]['target']               = 'partner.display_name';

		$result = $this->j->porter->apply( (string) json_encode( $data ), 5 );

		$this->assertTrue( $result['applied'] );
		$this->assertSame( 1, $result['drafts'] );
		$this->assertSame( 7, $this->h->settings->logRetentionDays() );
		$this->assertSame( 3, $this->j->stockService->rules( $this->connection )['safety_buffer'] );
		$this->assertSame( 1, $this->h->connections->findPublic( $this->connection )['settings']['concurrency'] );
		$draft = $this->h->mappings->draft( $this->connection, 'orders' );
		$this->assertNotNull( $draft );
		$this->assertSame( 'partner.display_name', $draft['rules'][0]['target'] );
		$live = $this->h->mappings->live( $this->connection, 'orders' );
		$this->assertSame( 'partner.name', $live['rules'][0]['target'], 'the live version is untouched' );
		$this->assertSame( 1, $live['version'] );
	}

	public function test_a_round_trip_restores_what_was_changed(): void {
		$json = $this->j->porter->export()['json'];
		$this->h->settings->update( array( 'log_retention_days' => 7, 'admin_bar' => false ) );
		$this->j->stockService->saveRules( $this->connection, array( 'safety_buffer' => 1 ) );

		$this->j->porter->apply( $json, 1 );

		$this->assertSame( 30, $this->h->settings->logRetentionDays() );
		$this->assertTrue( $this->h->settings->adminBar() );
		$this->assertSame( 7, $this->j->stockService->rules( $this->connection )['safety_buffer'] );
	}

	/**
	 * Files that must be refused.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function badFiles(): array {
		return array(
			'not json'            => array( 'this is not json' ),
			'json but a list'     => array( '"hello"' ),
			'other schema'        => array( '{"estasoft_sync":2,"settings":{}}' ),
			'no schema'           => array( '{"settings":{}}' ),
			'unknown key'         => array( '{"estasoft_sync":1,"extra":{}}' ),
			'unknown setting'     => array( '{"estasoft_sync":1,"settings":{"license_key":"x"}}' ),
			'bad mapping type'    => array( '{"estasoft_sync":1,"mappings":{"fake":{"bogus":[]}}}' ),
			'mapping not a list'  => array( '{"estasoft_sync":1,"mappings":{"fake":{"orders":{"a":1}}}}' ),
			'bad connector id'    => array( '{"estasoft_sync":1,"mappings":{"../x":{"orders":[]}}}' ),
			'unknown limit group' => array( '{"estasoft_sync":1,"connection_settings":{"fake":{"secrets":{"a":"b"}}}}' ),
			'unknown limit key'   => array( '{"estasoft_sync":1,"connection_settings":{"fake":{"stock":{"api_key":"b"}}}}' ),
		);
	}

	/**
	 * @dataProvider badFiles
	 *
	 * @param string $json File contents.
	 */
	public function test_bad_files_are_refused_by_preview_and_apply(string $json): void {
		foreach ( array( 'preview', 'apply' ) as $method ) {
			try {
				'apply' === $method ? $this->j->porter->apply( $json, 1 ) : $this->j->porter->preview( $json );
				$this->fail( 'expected a refusal from ' . $method );
			} catch ( ValidationException $e ) {
				$this->assertArrayHasKey( 'json', $e->errors() );
			}
		}
	}

	public function test_a_file_over_one_megabyte_is_refused(): void {
		$this->expectException( ValidationException::class );
		$this->j->porter->preview( '{"estasoft_sync":1,"pad":"' . str_repeat( 'a', SettingsPorter::MAX_BYTES ) . '"}' );
	}

	public function test_a_value_out_of_range_is_refused_before_anything_is_written(): void {
		$data = json_decode( $this->j->porter->export()['json'], true );
		$data['settings']['admin_bar']                                       = false;
		$data['connection_settings']['fake']['reliability']['concurrency'] = 99;

		try {
			$this->j->porter->apply( (string) json_encode( $data ), 1 );
			$this->fail( 'expected a refusal' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'json', $e->errors() );
		}
		$this->assertTrue( $this->h->settings->adminBar(), 'nothing was imported' );
	}

	/**
	 * Files with one bad value in the group named, and good changes everywhere else.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function oneBadGroup(): array {
		return array(
			'stock interval'      => array( 'stock', 'interval_minutes', 2 ),
			'stock buffer'        => array( 'stock', 'safety_buffer', -5 ),
			'stock flag'          => array( 'stock', 'enabled', 'perhaps' ),
			'tracking status'     => array( 'tracking', 'delivered_becomes', 'no-such-status' ),
			'tracking flag'       => array( 'tracking', 'tracking_enabled', 'sometimes' ),
			'reliability'         => array( 'reliability', 'requests_per_second', 9999 ),
			'plugin setting'      => array( 'settings', 'log_retention_days', 100000 ),
		);
	}

	/**
	 * @dataProvider oneBadGroup
	 *
	 * @param string $group Group of the bad value.
	 * @param string $key   Field.
	 * @param mixed  $value The bad value.
	 */
	public function test_one_bad_value_in_any_group_writes_nothing_at_all(string $group, string $key, mixed $value): void {
		$data = json_decode( $this->j->porter->export()['json'], true );
		// Good changes everywhere, so a half-applied import would show.
		$data['settings']['admin_bar']                                      = false;
		$data['connection_settings']['fake']['reliability']['concurrency'] = 1;
		$data['connection_settings']['fake']['stock']['safety_buffer']     = 3;
		$data['connection_settings']['fake']['tracking']['tracking_enabled'] = false;
		$data['mappings']['fake']['orders'][0]['target']                   = 'partner.display_name';
		if ( 'settings' === $group ) {
			$data['settings'][ $key ] = $value;
		} else {
			$data['connection_settings']['fake'][ $group ][ $key ] = $value;
		}
		$before = array(
			'settings' => $this->h->settings->get(),
			'limits'   => $this->h->connections->findPublic( $this->connection )['settings'],
			'rules'    => $this->j->stockService->rules( $this->connection ),
		);

		try {
			$this->j->porter->apply( (string) json_encode( $data ), 1 );
			$this->fail( 'expected a refusal' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'json', $e->errors() );
			$this->assertStringContainsString( 'Nothing was imported', $e->errors()['json'] );
		}

		$this->assertSame( $before['settings'], $this->h->settings->get() );
		$this->assertSame( $before['limits'], $this->h->connections->findPublic( $this->connection )['settings'] );
		$this->assertSame( $before['rules'], $this->j->stockService->rules( $this->connection ) );
		$this->assertNull( $this->h->mappings->draft( $this->connection, 'orders' ), 'no draft was created' );
		$this->assertSame( array(), $this->j->drafts );
	}

	public function test_mapping_rules_the_validator_refuses_stop_the_whole_import(): void {
		$data = json_decode( $this->j->porter->export()['json'], true );
		$data['settings']['admin_bar']                                   = false;
		$data['connection_settings']['fake']['stock']['safety_buffer']  = 3;
		$data['mappings']['fake']['orders'][0]['target']                = 'partner.nowhere';
		$this->j->ruleProblems = array( new \Estasoft\SyncEngine\Mapping\RuleError( 'r1', 'target', 'target_unknown', 'The target partner.nowhere does not exist in the system.' ) );

		try {
			$this->j->porter->apply( (string) json_encode( $data ), 1 );
			$this->fail( 'expected a refusal' );
		} catch ( ValidationException $e ) {
			$this->assertStringContainsString( 'orders mapping for fake has a problem: The target partner.nowhere does not exist in the system.', $e->errors()['json'] );
			$this->assertStringContainsString( 'Nothing was imported', $e->errors()['json'] );
		}

		$this->assertTrue( $this->h->settings->adminBar(), 'the settings were not written' );
		$this->assertSame( 7, $this->j->stockService->rules( $this->connection )['safety_buffer'], 'the limits were not written' );
		$this->assertNull( $this->h->mappings->draft( $this->connection, 'orders' ) );

		// The same file with rules the validator accepts imports in full.
		$this->j->ruleProblems = array();
		$result = $this->j->porter->apply( (string) json_encode( $data ), 1 );
		$this->assertSame( 1, $result['drafts'] );
		$this->assertFalse( $this->h->settings->adminBar() );
		$this->assertSame( 3, $this->j->stockService->rules( $this->connection )['safety_buffer'] );
	}

	public function test_mappings_that_would_change_nothing_or_that_have_no_connection_are_not_validated(): void {
		$data = json_decode( $this->j->porter->export()['json'], true );
		$data['mappings']['ghost']['orders'] = $this->rules();
		$this->j->ruleProblems = array( new \Estasoft\SyncEngine\Mapping\RuleError( '', 'rules', 'broken', 'This would be refused.' ) );

		$result = $this->j->porter->apply( (string) json_encode( $data ), 1 );

		$this->assertTrue( $result['applied'], 'the live rules are unchanged and the ghost connector is skipped, so nothing needed checking' );
		$this->assertSame( array( 'ghost' ), $result['skipped'] );
	}
}
