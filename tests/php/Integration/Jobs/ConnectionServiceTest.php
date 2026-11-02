<?php
/**
 * CON-1 to CON-4, CON-9, CON-10, MAP-1, ONB-5.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\Connections\CatalogForms;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\JobException;

final class ConnectionServiceTest extends JobsTestCase {

	/**
	 * A connection input.
	 *
	 * @param array<string,mixed> $over Values over the defaults.
	 * @return array<string,mixed>
	 */
	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'connector' => 'fake',
				'label'     => 'Main Odoo',
				'config'    => array(
					'address' => 'https://erp.example.test',
					'user'    => 'sync@example.test',
				),
				'secrets'   => array( 'api_key' => 'sk-live-abcdefghijklmnopqrstu' ),
			),
			$over
		);
	}

	private function countRows(): int {
		return $this->h->count( $this->h->tables->connections() );
	}

	public function test_create_saves_encrypted_keeps_the_facts_of_the_test_and_seeds_version_one_mappings(): void {
		$this->j->connector->defaults = array(
			'orders'    => array( array( 'id' => 'r1', 'target' => 'partner.name', 'source' => 'billing.first_name', 'transforms' => array(), 'required' => true ) ),
			'customers' => array( array( 'id' => 'c1', 'target' => 'name', 'source' => 'billing.first_name', 'transforms' => array(), 'required' => false ) ),
		);
		$created = $this->listen( 'estasoft_sync_connection_created', 2 );

		$connection = $this->j->connectionService->create( $this->input() );

		$this->assertSame( 'Main Odoo', $connection['label'] );
		$this->assertSame( 'active', $connection['status'] );
		$this->assertSame( 'Fakesystem 18.0', $connection['version'] );
		$this->assertSame( 'Cedarstone Home', $connection['company'] );
		$this->assertSame( 'https://erp.example.test', $connection['address'] );
		$this->assertSame( 4, $connection['settings']['requests_per_second'], 'starts from the connector\'s hints' );
		$this->assertCount( 2, $connection['warehouses'] );
		$this->assertSame( '2027-02-01T00:00:00Z', $connection['credential_expires_at'] );
		$this->assertSame( array( 'address', 'user', 'api_key' ), array_column( $connection['credentials'], 'name' ) );
		$this->assertCount( 1, $created );

		$id  = $connection['id'];
		$row = $this->h->connectionRow( $id );
		$this->assertStringNotContainsString( 'sk-live', (string) $row['secrets_enc'] . (string) $row['config_json'] . (string) $row['secret_hints_json'] );
		$config = json_decode( (string) $row['config_json'], true );
		$this->assertSame( '1.0.0', $config['contract_version'], 'the contract version is recorded' );
		$this->assertTrue( $config['capabilities']['stock_in'] );
		$this->assertSame( 2, $config['detected']['currency_decimals'] );
		$this->assertSame( 1, $this->h->mappings->live( $id, 'orders' )['version'] );
		$this->assertSame( 1, $this->h->mappings->live( $id, 'customers' )['version'] );
		$this->assertNull( $this->h->mappings->live( $id, 'refunds' ), 'no defaults, no version' );
	}

	public function test_the_connection_never_returns_a_secret(): void {
		$connection = $this->j->connectionService->create( $this->input() );
		$encoded    = (string) json_encode( $this->j->connectionService->get( $connection['id'] ) );

		$this->assertStringNotContainsString( 'sk-live', $encoded );
		$credentials = array_column( $connection['credentials'], null, 'name' );
		$this->assertNull( $credentials['api_key']['value'] );
		$this->assertTrue( $credentials['api_key']['has_value'] );
		$this->assertTrue( $credentials['api_key']['secret'] );
		$this->assertSame( 'https://erp.example.test', $credentials['address']['value'] );
	}

	public function test_a_failed_test_saves_nothing_and_carries_the_result(): void {
		$this->j->connector->testResult = new TestResult( false, '', '', array(), array(), new ConnectorError( ErrorCode::Auth001, array( 'system' => 'Fakesystem' ), 401 ) );

		try {
			$this->j->connectionService->create( $this->input() );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 401, $e->httpStatus() );
			$this->assertSame( ErrorCode::Auth001, $e->errorCode() );
			$this->assertFalse( $e->context()['test']['ok'] );
			$this->assertSame( 'SYNC-AUTH-001', $e->context()['test']['error']['code'] );
		}
		$this->assertSame( 0, $this->countRows() );
		$this->assertSame( 0, $this->h->count( $this->h->tables->mappings() ) );
	}

	public function test_a_connector_that_throws_during_the_test_saves_nothing_and_leaks_nothing(): void {
		$this->j->connector->fail = array( 'test' => new \RuntimeException( 'boom sk-live-abcdefghijklmnopqrstu' ) );
		try {
			$this->j->connectionService->create( $this->input() );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertStringNotContainsString( 'sk-live', $e->getMessage() . json_encode( $e->context() ) );
			$this->assertSame( ErrorCode::Val002, $e->errorCode() );
		}
		$this->assertSame( 0, $this->countRows() );
	}

	public function test_without_an_encryption_key_create_is_refused_with_the_line_to_add(): void {
		$service = new ConnectionService( $this->h->connections, $this->h->connectionSettings, $this->h->mappings, fn() => $this->h->registry, new SecretBox( null ), $this->h->logger, $this->h->clock, $this->h->pause, $this->j->signIns );
		try {
			$service->create( $this->input() );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
			$this->assertStringStartsWith( "define( 'ESTASOFT_SYNC_KEY'", $e->context()['key_line'] );
		}
		$this->assertSame( 0, $this->countRows() );
		$this->assertSame( 0, $this->j->connector->count( 'test' ), 'the connector was not even asked' );
	}

	public function test_required_fields_and_the_connector_are_checked(): void {
		try {
			$this->j->connectionService->create( $this->input( array( 'config' => array(), 'secrets' => array() ) ) );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'address', 'api_key' ), array_keys( $e->errors() ) );
		}
		$this->expectException( ValidationException::class );
		$this->j->connectionService->create( $this->input( array( 'connector' => 'nope' ) ) );
	}

	public function test_one_connection_per_connector_until_it_is_disconnected(): void {
		$first = $this->j->connectionService->create( $this->input() );
		try {
			$this->j->connectionService->create( $this->input() );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		$this->j->connectionService->disconnect( $first['id'] );
		$second = $this->j->connectionService->create( $this->input() );
		$this->assertNotSame( $first['id'], $second['id'] );
	}

	public function test_only_the_connectors_own_fields_are_stored(): void {
		$connection = $this->j->connectionService->create(
			$this->input(
				array(
					'config' => array(
						'address' => 'https://erp.example.test',
						'jobs'    => array( 'stock' => array( 'enabled' => true ) ),
						'status'  => 'paused',
						'options' => array( 'documents' => 'order_invoice', 'Bad Key' => 'x', 'nested' => array( 'a' ) ),
					),
				)
			)
		);

		$config = json_decode( (string) $this->h->connectionRow( $connection['id'] )['config_json'], true );
		$this->assertArrayNotHasKey( 'jobs', $config );
		$this->assertArrayNotHasKey( 'status', $config );
		$this->assertSame( array( 'documents' => 'order_invoice' ), $config['options'] );
	}

	public function test_update_changes_a_label_without_a_test_and_a_new_key_with_one(): void {
		$connection = $this->j->connectionService->create( $this->input() );
		$id         = $connection['id'];
		$tests      = $this->j->connector->count( 'test' );

		$this->j->connectionService->update( $id, array( 'label' => 'Renamed', 'settings' => array( 'concurrency' => 1 ) ) );
		$this->assertSame( $tests, $this->j->connector->count( 'test' ) );
		$this->assertSame( 'Renamed', $this->j->connectionService->get( $id )['label'] );
		$this->assertSame( 1, $this->j->connectionService->get( $id )['settings']['concurrency'] );

		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );
		$this->assertSame( $tests + 1, $this->j->connector->count( 'test' ) );
		$this->assertSame( 'sk-new-0123456789abcdefghijk', $this->h->connections->find( $id )->secret( 'api_key' ) );

		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => '' ), 'label' => 'Again' ) );
		$this->assertSame( 'sk-new-0123456789abcdefghijk', $this->h->connections->find( $id )->secret( 'api_key' ), 'an empty secret keeps the saved one' );
	}

	public function test_a_new_key_that_fails_the_test_changes_nothing(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->j->connector->testResult = new TestResult( false, '', '', array(), array(), new ConnectorError( ErrorCode::Auth001, array( 'system' => 'Fakesystem' ), 401 ) );

		try {
			$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-bad-0123456789abcdefghijk' ) ) );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 401, $e->httpStatus() );
		}
		$this->assertSame( 'sk-live-abcdefghijklmnopqrstu', $this->h->connections->find( $id )->secret( 'api_key' ) );
	}

	public function test_fixing_the_credentials_of_a_connection_that_needs_reconnecting_activates_it(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->connections->setStatus( $id, ConnectionStatus::NeedsReconnect, null, 'auth' );

		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );

		$this->assertSame( 'active', $this->j->connectionService->get( $id )['status'] );
	}

	public function test_new_credentials_that_pass_the_test_resume_a_connection_paused_by_a_refused_sign_in(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->assertTrue( $this->h->pause->pauseForRefusedSignIn( $id, 'Fakesystem' ) );
		$this->assertSame( 'paused', $this->j->connectionService->get( $id )['status'] );
		$this->assertTrue( $this->j->signIns->has( $id ), 'the sign-in pause is remembered' );
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );
		$tests   = $this->j->connector->count( 'test' );

		$connection = $this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );

		$this->assertSame( $tests + 1, $this->j->connector->count( 'test' ), 'the new credentials were tested first' );
		$this->assertSame( 'active', $connection['status'] );
		$this->assertSame( 'sk-new-0123456789abcdefghijk', $this->h->connections->find( $id )->secret( 'api_key' ) );
		$this->assertSame( array( array( $id, 'person' ) ), array_values( (array) $resumed->getArrayCopy() ) );
		$this->assertFalse( $this->j->signIns->has( $id ), 'the reminder goes with the pause' );
		$messages = implode( ' | ', array_column( $this->h->logs->latest( 10 ), 'message' ) );
		$this->assertStringContainsString( 'passed the test. Syncing resumed.', $messages );
	}

	public function test_new_credentials_that_fail_the_test_leave_a_sign_in_pause_and_the_old_credentials_alone(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->pause->pauseForRefusedSignIn( $id, 'Fakesystem' );
		$this->j->connector->testResult = new TestResult( false, '', '', array(), array(), new ConnectorError( ErrorCode::Auth001, array( 'system' => 'Fakesystem' ), 401 ) );
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );

		try {
			$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-bad-0123456789abcdefghijk' ) ) );
			$this->fail( 'expected the test error' );
		} catch ( JobException $e ) {
			$this->assertSame( 401, $e->httpStatus() );
			$this->assertSame( ErrorCode::Auth001, $e->errorCode() );
			$this->assertFalse( $e->context()['test']['ok'] );
		}

		$this->assertSame( 'paused', $this->j->connectionService->get( $id )['status'], 'still paused' );
		$this->assertSame( 'sk-live-abcdefghijklmnopqrstu', $this->h->connections->find( $id )->secret( 'api_key' ), 'the credentials were not replaced' );
		$this->assertCount( 0, $resumed );
		$this->assertTrue( $this->j->signIns->has( $id ), 'the next good attempt can still resume it' );

		// A second try with working credentials then resumes it.
		$this->j->connector->testResult = null;
		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-good-0123456789abcdefghij' ) ) );
		$this->assertSame( 'active', $this->j->connectionService->get( $id )['status'] );
	}

	public function test_a_pause_a_person_asked_for_is_not_lifted_by_new_credentials(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->pause->pause( $id );
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );

		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );

		$this->assertSame( 'paused', $this->j->connectionService->get( $id )['status'] );
		$this->assertSame( 'sk-new-0123456789abcdefghijk', $this->h->connections->find( $id )->secret( 'api_key' ), 'the credentials are still saved' );
		$this->assertCount( 0, $resumed );
	}

	public function test_a_person_pausing_by_hand_after_a_sign_in_pause_forgets_the_sign_in(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->pause->pauseForRefusedSignIn( $id, 'Fakesystem' );
		$this->assertTrue( $this->j->signIns->has( $id ) );

		$this->h->pause->pause( $id );

		$this->assertFalse( $this->j->signIns->has( $id ) );
		$this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );
		$this->assertSame( 'paused', $this->j->connectionService->get( $id )['status'] );
	}

	public function test_changing_only_the_label_of_a_sign_in_paused_connection_does_not_resume_it(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->pause->pauseForRefusedSignIn( $id, 'Fakesystem' );
		$tests = $this->j->connector->count( 'test' );

		$this->j->connectionService->update( $id, array( 'label' => 'Renamed' ) );

		$this->assertSame( $tests, $this->j->connector->count( 'test' ) );
		$this->assertSame( 'paused', $this->j->connectionService->get( $id )['status'] );
	}

	public function test_testing_a_saved_connection_uses_the_saved_secret_when_the_address_is_the_same(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->j->connector->testedWith = array();

		$result = $this->j->connectionService->test( array( 'config' => array( 'address' => 'https://erp.example.test' ), 'secrets' => array( 'api_key' => '' ) ), $id );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Cedarstone Home', $result['company'] );
		$this->assertSame( array( array( 'id' => '1', 'name' => 'Dubai warehouse' ), array( 'id' => '2', 'name' => 'Riyadh warehouse' ) ), $result['warehouses'] );
		$this->assertSame( 1204, $result['product_count'] );
		$this->assertSame( array(), $result['missing_permissions'] );
		$this->assertSame( array( array( 'address' => 'https://erp.example.test', 'api_key' => 'sk-live-abcdefghijklmnopqrstu' ) ), $this->j->connector->testedWith );
		$this->assertSame( 'https://erp.example.test', json_decode( (string) $this->h->connectionRow( $id )['config_json'], true )['address'], 'testing saves nothing' );
	}

	public function test_a_test_that_names_a_new_address_never_sends_the_saved_secret_there(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->j->connector->testedWith = array();

		try {
			$this->j->connectionService->test( array( 'connection_id' => $id, 'config' => array( 'address' => 'https://evil.example' ), 'secrets' => array() ), $id );
			$this->fail( 'expected the key to be asked for again' );
		} catch ( ValidationException $e ) {
			$this->assertSame( array( 'api_key' ), array_keys( $e->errors() ) );
			$this->assertSame( 'Enter the API key again because the address changed.', $e->errors()['api_key'] );
		}
		$this->assertSame( array(), $this->j->connector->testedWith, 'the connector was never asked, so nothing was sent anywhere' );

		// An empty secret is the same as none.
		try {
			$this->j->connectionService->test( array( 'config' => array( 'address' => 'https://evil.example' ), 'secrets' => array( 'api_key' => '' ) ), $id );
			$this->fail( 'expected the key to be asked for again' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'api_key', $e->errors() );
		}
		$this->assertSame( array(), $this->j->connector->testedWith );

		// With the key typed again the test runs, with the typed key.
		$result = $this->j->connectionService->test( array( 'config' => array( 'address' => 'https://new.example.test' ), 'secrets' => array( 'api_key' => 'sk-typed-0123456789abcdefghij' ) ), $id );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( array( 'address' => 'https://new.example.test', 'api_key' => 'sk-typed-0123456789abcdefghij' ) ), $this->j->connector->testedWith );
	}

	public function test_any_changed_identity_field_asks_for_the_secrets_again(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];

		try {
			$this->j->connectionService->test( array( 'config' => array( 'user' => 'someone.else@example.test' ) ), $id );
			$this->fail( 'expected the key to be asked for again' );
		} catch ( ValidationException $e ) {
			$this->assertSame( 'Enter the API key again because the user changed.', $e->errors()['api_key'] );
		}
		// The same user, spelled the same, is no change.
		$this->assertTrue( $this->j->connectionService->test( array( 'config' => array( 'user' => 'sync@example.test' ) ), $id )['ok'] );
	}

	public function test_update_never_saves_a_new_address_with_the_old_key(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->j->connector->testedWith = array();

		try {
			$this->j->connectionService->update( $id, array( 'config' => array( 'address' => 'https://evil.example' ) ) );
			$this->fail( 'expected the key to be asked for again' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'api_key', $e->errors() );
		}
		$this->assertSame( array(), $this->j->connector->testedWith );
		$this->assertSame( 'https://erp.example.test', json_decode( (string) $this->h->connectionRow( $id )['config_json'], true )['address'] );
		$this->assertSame( 'sk-live-abcdefghijklmnopqrstu', $this->h->connections->find( $id )->secret( 'api_key' ) );

		$this->j->connectionService->update( $id, array( 'config' => array( 'address' => 'https://new.example.test' ), 'secrets' => array( 'api_key' => 'sk-typed-0123456789abcdefghij' ) ) );
		$this->assertSame( 'https://new.example.test', $this->j->connectionService->get( $id )['address'] );
		$this->assertSame( 'sk-typed-0123456789abcdefghij', $this->h->connections->find( $id )->secret( 'api_key' ) );
	}

	/**
	 * Make the saved secrets unreadable, as if the key in wp-config.php had been changed.
	 *
	 * @param int $id Connection id.
	 */
	private function changeTheKey( int $id ): void {
		global $wpdb;
		$other = new SecretBox( 'another-key-0123456789abcdef-0123456' );
		$wpdb->update(
			$this->h->tables->connections(),
			array(
				'secrets_enc'     => $other->encrypt( array( 'api_key' => 'sk-old-0123456789abcdefghijkl', 'inbound_secret' => str_repeat( 'a', 64 ) ) ),
				'key_fingerprint' => $other->fingerprint(),
				'inbound_enabled' => 1,
				'credential_expires_at' => '2026-12-01 00:00:00',
			),
			array( 'id' => $id )
		);
	}

	public function test_after_an_encryption_key_change_complete_secrets_test_and_save_and_inbound_is_switched_off(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->changeTheKey( $id );
		$this->assertFalse( $this->h->connections->findPublic( $id )['secrets_readable'] );
		$this->j->connector->testedWith = array();

		$connection = $this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) );

		$this->assertSame( array( array( 'address' => 'https://erp.example.test', 'api_key' => 'sk-new-0123456789abcdefghijk' ) ), $this->j->connector->testedWith );
		$this->assertTrue( $this->h->connections->findPublic( $id )['secrets_readable'], 'the secrets are readable again' );
		$this->assertSame( 'sk-new-0123456789abcdefghijk', $this->h->connections->find( $id )->secret( 'api_key' ) );
		$this->assertSame( '', $this->h->connections->find( $id )->secret( 'inbound_secret' ), 'the unreadable inbound secret is gone' );
		$this->assertFalse( $this->h->connections->findPublic( $id )['inbound_enabled'], 'and inbound is off until a person enables it again' );
		$this->assertSame( '2027-02-01T00:00:00Z', $connection['credential_expires_at'], 'the end date comes from the new test' );
	}

	public function test_after_an_encryption_key_change_a_test_with_complete_secrets_works_and_one_without_them_says_why(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->changeTheKey( $id );

		$this->assertTrue( $this->j->connectionService->test( array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ), $id )['ok'] );

		try {
			$this->j->connectionService->test( array( 'config' => array( 'address' => 'https://erp.example.test' ) ), $id );
			$this->fail( 'expected the unreadable secrets to be reported' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
			$this->assertStringContainsString( 'cannot be read', $e->getMessage() );
		}
	}

	public function test_update_refreshes_the_credential_end_date_and_clears_it_when_the_new_test_finds_none(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];
		$this->h->sql( "UPDATE {$this->h->tables->connections()} SET credential_expires_at = '2026-12-01 00:00:00' WHERE id = {$id}" );

		$this->assertSame( '2027-02-01T00:00:00Z', $this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdefghijk' ) ) )['credential_expires_at'] );

		$this->j->connector->testResult = new TestResult( true, 'Fakesystem 19', 'Cedarstone Home', array(), array( 'create_orders' => true ), null, array() );
		$this->assertNull( $this->j->connectionService->update( $id, array( 'secrets' => array( 'api_key' => 'sk-newer-0123456789abcdefghi' ) ) )['credential_expires_at'] );
	}

	public function test_a_test_reports_missing_permissions_by_name(): void {
		$this->j->connector->testResult = new TestResult( false, 'X 18', 'Co', array(), array( 'create_orders' => true, 'create_credit_notes' => false ), new ConnectorError( ErrorCode::Auth004, array( 'system' => 'Fakesystem', 'action' => 'create credit notes' ), 403 ) );

		$result = $this->j->connectionService->test( $this->input() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'create_credit_notes' ), $result['missing_permissions'] );
		$this->assertSame( 'SYNC-AUTH-004', $result['error']['code'] );
		$this->assertSame( 403, $result['error']['data']['status'] );
		$this->assertSame( 'needs_person', $result['error']['data']['class'] );
	}

	public function test_disconnect_wipes_the_secrets_and_keeps_the_history(): void {
		$this->j->connector->defaults = array( 'orders' => array( array( 'id' => 'r1', 'target' => 'partner.name', 'source' => 'billing.first_name', 'transforms' => array(), 'required' => true ) ) );
		$id   = $this->j->connectionService->create( $this->input() )['id'];
		$item = $this->h->item( $id, '1' );

		$this->j->connectionService->disconnect( $id );

		$row = $this->h->connectionRow( $id );
		$this->assertSame( 'disconnected', $row['status'] );
		$this->assertSame( '', (string) $row['secrets_enc'] );
		$this->assertNotNull( $this->h->queue->find( $item ), 'queued items are kept' );
		$this->assertNotNull( $this->h->mappings->live( $id, 'orders' ), 'mapping versions are kept' );
		$this->expectException( JobException::class );
		$this->j->connectionService->disconnect( 99999 );
	}

	public function test_summaries_and_get(): void {
		$id = $this->j->connectionService->create( $this->input() )['id'];

		$summaries = $this->j->connectionService->summaries();

		$this->assertCount( 1, $summaries );
		$this->assertSame( $id, $summaries[0]['id'] );
		$this->assertTrue( $summaries[0]['capabilities']['invoice_readback'] );
		$this->expectException( JobException::class );
		$this->j->connectionService->get( 12345 );
	}

	public function test_the_catalog_marks_installed_connectors_available(): void {
		$catalog = $this->j->connectionService->catalog();

		$this->assertSame( 'Odoo', $catalog[0]['name'] );
		$this->assertTrue( $catalog[0]['available'], 'its connector is in the registry' );
		$this->assertFalse( $catalog[1]['available'] );
		$this->assertNull( $catalog[1]['connector'] );
		$this->assertSame( array( 'name', 'type', 'regions', 'connector', 'connection_method', 'needs', 'available', 'unavailable_reason', 'verified', 'variants', 'hosts', 'terms_url', 'privacy_url', 'developer_url', 'steps', 'wave', 'credential_fields', 'can_sign_in', 'auth_style' ), array_keys( $catalog[0] ) );

		add_filter(
			'estasoft_sync_catalog',
			static function ( array $rows ): array {
				$rows[] = array( 'name' => 'Extra', 'type' => 'crm', 'connector' => null );
				return $rows;
			}
		);
		$this->assertSame( 'Extra', $this->j->connectionService->catalog()[2]['name'] );
		remove_all_filters( 'estasoft_sync_catalog' );
	}

	/**
	 * A service over chosen catalog rows.
	 *
	 * @param array<int,array<string,mixed>> $rows  Catalog rows.
	 * @param CatalogForms|null              $forms Form builder; none (nothing kept) by default.
	 */
	private function serviceWith( array $rows, ?CatalogForms $forms = null ): ConnectionService {
		return new ConnectionService( $this->h->connections, $this->h->connectionSettings, $this->h->mappings, fn() => $this->h->registry, $this->h->box, $this->h->logger, $this->h->clock, $this->h->pause, $this->j->signIns, static fn(): array => $rows, $forms );
	}

	public function test_the_reason_a_system_cannot_be_chosen_is_sent_apart_from_the_hand_written_needs(): void {
		$service = $this->serviceWith(
			array(
				array( 'name' => 'SMACC', 'type' => 'accounting', 'connector' => 'fake', 'connection_method' => 'none', 'needs' => 'Hand-written text.', 'selectable' => false, 'unavailable_reason' => 'No API is published yet.' ),
				array( 'name' => 'Other', 'type' => 'erp', 'connector' => 'fake', 'connection_method' => 'key', 'needs' => 'An API key.' ),
				array( 'name' => 'Family', 'type' => 'erp', 'connector' => null, 'connection_method' => 'key', 'variants' => array( array( 'id' => 'fake', 'label' => 'A', 'unavailable_reason' => 'Edition A is not ready.' ), array( 'id' => 'nope', 'label' => 'B' ) ) ),
			)
		);
		$catalog = $service->catalog();
		$this->assertFalse( $catalog[0]['available'] );
		$this->assertSame( 'Hand-written text.', $catalog[0]['needs'], 'The hand-written text is untouched.' );
		$this->assertSame( 'No API is published yet.', $catalog[0]['unavailable_reason'] );
		$this->assertSame( '', $catalog[1]['unavailable_reason'], 'Always a string; empty when there is nothing to say.' );
		$this->assertSame( 'Edition A is not ready.', $catalog[2]['variants'][0]['unavailable_reason'] );
		$this->assertArrayNotHasKey( 'unavailable_reason', $catalog[2]['variants'][1], 'A variant carries one only when its document has one.' );
	}

	public function test_an_available_row_carries_the_connect_form_and_a_missing_one_has_none(): void {
		$catalog = $this->j->connectionService->catalog();

		$fields = $catalog[0]['credential_fields'];
		$this->assertSame( array( 'address', 'user', 'api_key' ), array_column( $fields, 'name' ) );
		$this->assertSame( array( 'name', 'label', 'secret', 'required', 'hint', 'default', 'type', 'options', 'group' ), array_keys( $fields[0] ) );
		$this->assertSame( array( false, false, true ), array_column( $fields, 'secret' ) );
		$this->assertFalse( $catalog[0]['can_sign_in'] );
		$this->assertSame( 'key', $catalog[0]['auth_style'], 'A connector that names no style gets the one of its connection method.' );

		$this->assertSame( array(), $catalog[1]['credential_fields'], 'No connector, no fields.' );
		$this->assertFalse( $catalog[1]['can_sign_in'] );
		$this->assertSame( CatalogForms::styleFromMethod( $catalog[1]['connection_method'] ), $catalog[1]['auth_style'] );
	}

	public function test_each_variant_carries_its_own_form_and_a_system_that_cannot_be_chosen_has_none(): void {
		$service = $this->serviceWith(
			array(
				array( 'name' => 'Sage family', 'type' => 'erp', 'connector' => null, 'connection_method' => 'cloud_app', 'variants' => array( array( 'id' => 'fake', 'label' => 'Installed' ), array( 'id' => 'not-built', 'label' => 'Later' ) ) ),
				array( 'name' => 'SMACC', 'type' => 'accounting', 'connector' => 'fake', 'connection_method' => 'export_file', 'selectable' => false ),
			)
		);
		$catalog = $service->catalog();

		$this->assertTrue( $catalog[0]['available'] );
		$this->assertSame( array(), $catalog[0]['credential_fields'], 'A row that holds variants has no form of its own.' );
		$this->assertSame( 'oauth_client_credentials', $catalog[0]['auth_style'] );
		$this->assertFalse( $catalog[0]['can_sign_in'] );
		$installed = $catalog[0]['variants'][0];
		$this->assertTrue( $installed['available'] );
		$this->assertSame( array( 'address', 'user', 'api_key' ), array_column( $installed['credential_fields'], 'name' ) );
		$this->assertFalse( $installed['can_sign_in'] );
		$this->assertSame( 'oauth_client_credentials', $installed['auth_style'], 'The fake connector names no style, so the row connection method decides.' );
		$later = $catalog[0]['variants'][1];
		$this->assertFalse( $later['available'] );
		$this->assertSame( array(), $later['credential_fields'] );

		$this->assertFalse( $catalog[1]['available'] );
		$this->assertSame( array(), $catalog[1]['credential_fields'], 'Even with a connector installed, a row marked not selectable shows no form.' );
		$this->assertSame( 'export_file', $catalog[1]['auth_style'] );
	}

	public function test_a_variant_carries_its_own_way_to_connect_steps_hosts_and_links(): void {
		$service = $this->serviceWith(
			array(
				array(
					'name'              => 'Family',
					'type'              => 'erp',
					'connector'         => null,
					'connection_method' => 'own_app',
					'hosts'             => array( 'first.example' ),
					'steps'             => array( 'Sign in at the first product.' ),
					'variants'          => array(
						array(
							'id'                => 'fake',
							'label'             => 'Second product',
							'connection_method' => 'server_login',
							'hosts'             => array(),
							'terms_url'         => '',
							'privacy_url'       => 'https://example.test/privacy',
							'developer_url'     => 'https://example.test/dev',
							'steps'             => array( 'Ask for the address.', 'Enter the user.' ),
						),
						array( 'id' => 'nope', 'label' => 'Bare' ),
					),
				),
			)
		);
		$variants = $service->catalog()[0]['variants'];

		$this->assertSame( 'server_login', $variants[0]['connection_method'] );
		$this->assertSame( array(), $variants[0]['hosts'], 'An empty list stays empty: the product talks only to the address the person types.' );
		$this->assertSame( array( 'Ask for the address.', 'Enter the user.' ), $variants[0]['steps'] );
		$this->assertSame( '', $variants[0]['terms_url'] );
		$this->assertSame( 'https://example.test/privacy', $variants[0]['privacy_url'] );
		$this->assertSame( 'https://example.test/dev', $variants[0]['developer_url'] );
		$this->assertSame( 'server_login', $variants[0]['auth_style'], 'The style follows the product, not the family row.' );

		foreach ( array( 'connection_method', 'hosts', 'steps', 'terms_url', 'privacy_url', 'developer_url' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $variants[1], 'Nothing is made up for a product whose document says nothing.' );
		}
	}

	public function test_the_forms_are_kept_in_a_transient_for_ten_minutes_and_never_hold_a_secret(): void {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_estasoft_sync_catalog_forms_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $wpdb->esc_like( '_transient_timeout_estasoft_sync_catalog_forms_' ) . '%' ) );

		$rows    = array( array( 'name' => 'Odoo', 'type' => 'erp', 'connector' => 'fake', 'connection_method' => 'key' ) );
		$service = $this->serviceWith( $rows, CatalogForms::cached( fn() => $this->h->registry ) );
		$first   = $service->catalog();
		$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$this->assertCount( 1, $names, 'One transient holds every connector form.' );
		$timeout = (int) get_option( str_replace( '_transient_', '_transient_timeout_', (string) $names[0] ) );
		$this->assertEqualsWithDelta( time() + CatalogForms::TTL, $timeout, 5 );
		$this->assertSame( 600, CatalogForms::TTL );

		$this->assertSame( $first, $service->catalog(), 'The second request reads the kept answer.' );
		$kept = (string) wp_json_encode( get_transient( substr( (string) $names[0], strlen( '_transient_' ) ) ) );
		$this->assertStringContainsString( 'api_key', $kept );
		$this->assertStringNotContainsString( 'secret_value', $kept );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $wpdb->esc_like( '_transient_timeout_estasoft_sync_catalog_forms_' ) . '%' ) );
	}

	public function test_the_static_catalog_file_loads_with_all_34_systems(): void {
		$service = new ConnectionService( $this->h->connections, $this->h->connectionSettings, $this->h->mappings, fn() => $this->h->registry, $this->h->box, $this->h->logger, $this->h->clock, $this->h->pause, $this->j->signIns );
		$catalog = $service->catalog();
		$this->assertCount( 34, $catalog );
		$this->assertSame( array(), array_filter( $catalog, static fn( array $row ): bool => $row['available'] ), 'no installed connector matches (the test connector is called fake)' );
	}
}
