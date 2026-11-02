<?php
/**
 * Connections and their encrypted secrets.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class ConnectionRepositoryTest extends CoreIntegrationTestCase {

	private const SECRET = 'sk-test-abcdefghijklmnop';

	public function test_a_connection_is_saved_and_read_back_with_decrypted_secrets(): void {
		$id         = $this->h->connection( array(), array( 'api_key' => self::SECRET, 'password' => 'p@ss' ), array( 'address' => 'https://erp.example.test', 'database' => 'prod' ) );
		$connection = $this->h->connections->find( $id );

		$this->assertNotNull( $connection );
		$this->assertSame( 'fake', $connection->connector );
		$this->assertSame( ConnectorType::Erp, $connection->type );
		$this->assertSame( 'Fake ERP', $connection->label );
		$this->assertSame( ConnectionStatus::Active, $connection->status );
		$this->assertSame( self::SECRET, $connection->secret( 'api_key' ) );
		$this->assertSame( 'p@ss', $connection->secret( 'password' ) );
		$this->assertSame( '', $connection->secret( 'missing' ) );
		$this->assertSame( 'prod', $connection->config( 'database' ) );
		$this->assertSame( 4, $connection->settings['requests_per_second'] );
		$this->assertNull( $this->h->connections->find( $id + 1000 ) );
	}

	public function test_no_secret_is_stored_in_the_clear_anywhere_in_the_row(): void {
		$id  = $this->h->connection( array(), array( 'api_key' => self::SECRET ) );
		$row = $this->h->connectionRow( $id );
		$this->assertStringNotContainsString( self::SECRET, implode( '|', array_map( 'strval', $row ) ) );
		$this->assertStringStartsWith( 'v2.', (string) $row['secrets_enc'] );
		$this->assertSame( ( new SecretBox( 'unit-test-key-0123456789abcdef-core' ) )->fingerprint(), $row['key_fingerprint'] );
	}

	public function test_screens_never_get_a_secret(): void {
		$id     = $this->h->connection( array(), array( 'api_key' => self::SECRET ) );
		$public = $this->h->connections->findPublic( $id );
		$this->assertNotNull( $public );
		$json = json_encode( $public );
		$this->assertStringNotContainsString( self::SECRET, (string) $json );
		$this->assertSame( array( 'api_key' => true ), $public['secret_hints'], 'only that the secret exists, not a character of it' );
		$this->assertStringNotContainsString( 'mnop', (string) $json );
		$this->assertStringNotContainsString( 'last4', (string) $json );
		$this->assertTrue( $public['secrets_readable'] );
		$this->assertStringNotContainsString( self::SECRET, (string) json_encode( $this->h->connections->all() ) );
		$this->assertStringNotContainsString( self::SECRET, print_r( $this->h->connections->find( $id ), true ) ); // phpcs:ignore
	}

	public function test_no_character_of_a_secret_is_stored_beside_it_or_returned(): void {
		foreach ( array( 'short', self::SECRET, str_repeat( 'Z', 80 ) ) as $secret ) {
			$id  = $this->h->connection( array(), array( 'api_key' => $secret, 'password' => 'p@ss-' . $secret ) );
			$row = $this->h->connectionRow( $id );
			$this->assertSame( '{"api_key":true,"password":true}', $row['secret_hints_json'], 'presence flags only' );
			$this->assertSame( array( 'api_key' => true, 'password' => true ), $this->h->connections->findPublic( $id )['secret_hints'] );
			foreach ( array( $this->h->connections->all(), $this->h->connections->findPublic( $id ), $this->h->connections->findWithoutSecrets( $id ) ) as $shown ) {
				$text = print_r( $shown, true ); // phpcs:ignore
				$this->assertStringNotContainsString( substr( $secret, -4 ), $text );
				$this->assertStringNotContainsString( 'last4', $text );
			}
		}
	}

	public function test_a_secret_that_is_removed_leaves_the_hints(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET, 'password' => 'p@ss' ) );
		$this->h->connections->update( $id, array( 'secrets' => array( 'password' => null ) ) );
		$this->assertSame( array( 'api_key' => true ), $this->h->connections->findPublic( $id )['secret_hints'] );
	}

	public function test_a_partial_secret_save_is_refused_when_the_key_changed(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET, 'password' => 'p@ss' ) );
		$other = new ConnectionRepository( $this->h->wpdb, $this->h->tables, $this->h->clock, new SecretBox( 'a-completely-different-key-98765432' ), new ConnectionSettings() );
		$before = $this->h->connectionRow( $id );

		foreach ( array(
			'one of two'                   => array( 'api_key' => 'sk-new-0123456789abcdef' ),
			'one new, the other kept'      => array( 'api_key' => 'sk-new-0123456789abcdef', 'password' => '' ),
			'nothing but keep'             => array( 'api_key' => '', 'password' => '' ),
			'an unrelated secret only'     => array( 'token' => 'abc' ),
		) as $label => $change ) {
			try {
				$other->update( $id, array( 'secrets' => $change ) );
				$this->fail( "Expected the partial save to be refused: $label" );
			} catch ( CryptoException $e ) {
				$this->assertNull( $e->errorCode(), $label );
				$this->assertStringContainsString( 'all the credentials', $e->getMessage(), $label );
			}
			$after = $this->h->connectionRow( $id );
			$this->assertSame( $before['secrets_enc'], $after['secrets_enc'], "$label: nothing was written" );
			$this->assertSame( $before['secret_hints_json'], $after['secret_hints_json'], $label );
			$this->assertSame( $before['key_fingerprint'], $after['key_fingerprint'], $label );
		}
		$this->assertFalse( $other->findPublic( $id )['secrets_readable'], 'still unreadable, and still honest about it' );

		// All of them given again: fine. A removal counts as an answer too.
		$other->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdef', 'password' => null ) ) );
		$this->assertTrue( $other->findPublic( $id )['secrets_readable'] );
		$this->assertSame( 'sk-new-0123456789abcdef', $other->find( $id )->secret( 'api_key' ) );
		$this->assertSame( array( 'api_key' => true ), $other->findPublic( $id )['secret_hints'] );
	}

	public function test_other_changes_to_a_connection_with_unreadable_secrets_still_work(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET ) );
		$other = new ConnectionRepository( $this->h->wpdb, $this->h->tables, $this->h->clock, new SecretBox( 'a-completely-different-key-98765432' ), new ConnectionSettings() );
		$other->update( $id, array( 'label' => 'Renamed', 'config' => array( 'address' => 'https://new.example.test' ) ) );
		$this->assertSame( 'Renamed', $other->findPublic( $id )['label'] );
		$this->assertFalse( $other->findPublic( $id )['secrets_readable'] );
	}

	public function test_a_short_key_is_refused_when_saving_secrets(): void {
		$repo = new ConnectionRepository( $this->h->wpdb, $this->h->tables, $this->h->clock, new SecretBox( str_repeat( 'k', 31 ) ), new ConnectionSettings() );
		try {
			$repo->create( array( 'connector' => 'fake' ), array( 'api_key' => self::SECRET ) );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
			$this->assertStringContainsString( 'too short', $e->getMessage() );
		}
		$this->assertSame( 0, $this->h->count( $this->h->tables->connections() ) );
	}

	public function test_disconnecting_stamps_the_time_and_coming_back_clears_it(): void {
		$id = $this->h->connection();
		$this->assertNull( $this->h->connectionRow( $id )['disconnected_at'] );
		$this->h->connections->delete( $id );
		$this->assertSame( '2026-11-02 12:00:00', $this->h->connectionRow( $id )['disconnected_at'] );
		$this->h->clock->advance( 3600 );
		$this->h->connections->setStatus( $id, ConnectionStatus::Active );
		$this->assertNull( $this->h->connectionRow( $id )['disconnected_at'] );
		$this->h->connections->setStatus( $id, ConnectionStatus::Disconnected );
		$this->assertSame( '2026-11-02 13:00:00', $this->h->connectionRow( $id )['disconnected_at'] );
	}

	public function test_public_shape_matches_the_rest_contract(): void {
		$id     = $this->h->connection( array(), array(), array( 'version' => 'Odoo 18 Enterprise', 'company' => 'Cedar & Stone', 'capabilities' => array( 'orders' => true ), 'warehouses' => array( array( 'id' => '1', 'name' => 'Main' ) ) ) );
		$public = $this->h->connections->findPublic( $id );
		foreach ( array( 'id', 'connector', 'label', 'type', 'status', 'version', 'address', 'paused_until', 'paused_reason', 'last_success_at', 'last_failure_at', 'failures_in_a_row', 'typical_response_ms', 'credential_expires_at', 'capabilities', 'company', 'warehouses', 'settings', 'inbound_enabled', 'created_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $public, $key );
		}
		$this->assertSame( 'Odoo 18 Enterprise', $public['version'] );
		$this->assertSame( 'https://erp.example.test', $public['address'] );
		$this->assertSame( 'Cedar & Stone', $public['company'] );
		$this->assertSame( 'active', $public['status'] );
		$this->assertSame( 0, $public['failures_in_a_row'] );
		$this->assertNull( $public['paused_until'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $public['created_at'] );
		$this->assertSame( array( 'orders' => true ), $public['capabilities'] );
	}

	public function test_missing_key_blocks_saving_secrets_with_sync_cfg_002(): void {
		$repo = new ConnectionRepository( $this->h->wpdb, $this->h->tables, $this->h->clock, new SecretBox( null ), new ConnectionSettings() );
		try {
			$repo->create( array( 'connector' => 'fake' ), array( 'api_key' => self::SECRET ) );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
		}
		$this->assertSame( 0, $this->h->count( $this->h->tables->connections() ), 'nothing is saved' );

		$id = $repo->create( array( 'connector' => 'export', 'label' => 'File export' ) );
		$this->assertGreaterThan( 0, $id, 'a connection without secrets needs no key' );
	}

	public function test_a_changed_key_makes_the_secrets_unreadable_but_not_the_screens(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET ) );
		$other = new ConnectionRepository( $this->h->wpdb, $this->h->tables, $this->h->clock, new SecretBox( 'a-completely-different-key-98765432' ), new ConnectionSettings() );
		try {
			$other->find( $id );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertNull( $e->errorCode() );
		}
		$this->assertNotNull( $other->findWithoutSecrets( $id ) );
		$this->assertFalse( $other->findPublic( $id )['secrets_readable'] );

		$other->update( $id, array( 'secrets' => array( 'api_key' => 'sk-new-0123456789abcdef' ) ) );
		$this->assertSame( 'sk-new-0123456789abcdef', $other->find( $id )->secret( 'api_key' ), 'entering the credentials again repairs it' );
		$this->assertTrue( $other->findPublic( $id )['secrets_readable'] );
	}

	public function test_replacing_one_secret_keeps_the_others_and_an_empty_value_keeps_the_saved_one(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET, 'password' => 'p@ss' ) );
		$this->h->connections->update( $id, array( 'secrets' => array( 'api_key' => '', 'password' => 'newpass' ) ) );
		$connection = $this->h->connections->find( $id );
		$this->assertSame( self::SECRET, $connection->secret( 'api_key' ) );
		$this->assertSame( 'newpass', $connection->secret( 'password' ) );

		$this->h->connections->update( $id, array( 'secrets' => array( 'password' => null ) ) );
		$this->assertSame( '', $this->h->connections->find( $id )->secret( 'password' ) );
		$this->assertArrayNotHasKey( 'password', $this->h->connections->findPublic( $id )['secret_hints'] );
	}

	public function test_config_is_merged_and_a_null_removes_a_key(): void {
		$id = $this->h->connection( array(), array(), array( 'database' => 'prod', 'company' => 'A' ) );
		$this->h->connections->update( $id, array( 'label' => 'Main ERP', 'config' => array( 'company' => 'B', 'database' => null, 'version' => 'Odoo 19' ) ) );
		$connection = $this->h->connections->findWithoutSecrets( $id );
		$this->assertSame( 'Main ERP', $connection->label );
		$this->assertSame( 'B', $connection->config( 'company' ) );
		$this->assertNull( $connection->config( 'database' ) );
		$this->assertSame( 'Odoo 19', $connection->config( 'version' ) );
		$this->assertSame( 'https://erp.example.test', $connection->config( 'address' ) );
	}

	public function test_reliability_settings_are_validated_on_create_and_update(): void {
		$id = $this->h->connection( array( 'breaker_threshold' => 8, 'requests_per_second' => 1 ) );
		$this->assertSame( 8, $this->h->connections->findWithoutSecrets( $id )->settings['breaker_threshold'] );

		$this->h->connections->update( $id, array( 'settings' => array( 'probe_interval_minutes' => 10 ) ) );
		$settings = $this->h->connections->findWithoutSecrets( $id )->settings;
		$this->assertSame( 10, $settings['probe_interval_minutes'] );
		$this->assertSame( 8, $settings['breaker_threshold'], 'unchanged settings stay' );

		try {
			$this->h->connections->update( $id, array( 'settings' => array( 'breaker_threshold' => 2 ) ) );
			$this->fail( 'Expected a validation error.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( array( 'breaker_threshold' ), array_keys( $e->errors() ) );
		}
		$this->assertSame( 8, $this->h->connections->findWithoutSecrets( $id )->settings['breaker_threshold'] );

		$this->expectException( ValidationException::class );
		$this->h->connection( array( 'concurrency' => 50 ) );
	}

	public function test_a_connection_needs_a_connector_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->h->connections->create( array( 'label' => 'x' ) );
	}

	public function test_updating_an_unknown_connection_is_an_error(): void {
		$this->expectException( \OutOfBoundsException::class );
		$this->h->connections->update( 999999, array( 'label' => 'x' ) );
	}

	public function test_the_reason_is_in_the_list_of_connections_too(): void {
		$a = $this->h->connection();
		$b = $this->h->connection();
		$this->h->connections->setStatus( $a, ConnectionStatus::Paused, null, 'sign_in' );
		$this->h->connections->tripCircuit( $b, '2026-11-02 12:02:00' );
		$byId = array_column( $this->h->connections->all(), 'paused_reason', 'id' );
		$this->assertSame( 'sign_in', $byId[ $a ] );
		$this->assertSame( 'circuit', $byId[ $b ] );
	}

	public function test_status_changes_and_pause_reasons(): void {
		$id = $this->h->connection();
		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'person' );
		$public = $this->h->connections->findPublic( $id );
		$this->assertSame( 'paused', $public['status'] );
		$this->assertSame( 'person', $public['paused_reason'] );

		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'sign_in' );
		$this->assertSame( 'sign_in', $this->h->connections->findPublic( $id )['paused_reason'], 'a refused sign-in is told apart from a pause by a person' );

		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'key_missing' );
		$this->assertSame( 'key_missing', $this->h->connections->findPublic( $id )['paused_reason'] );

		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'auth' );
		$this->assertSame( 'sign_in', $this->h->connections->findPublic( $id )['paused_reason'], 'the value the first development builds stored' );

		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'something-else' );
		$this->assertSame( 'person', $this->h->connections->findPublic( $id )['paused_reason'], 'an unknown value is shown as a person\'s pause' );

		$this->h->connections->setStatus( $id, ConnectionStatus::PausedCircuit, '2026-11-02 12:02:00', 'circuit' );
		$public = $this->h->connections->findPublic( $id );
		$this->assertSame( 'circuit', $public['paused_reason'] );
		$this->assertSame( '2026-11-02T12:02:00Z', $public['paused_until'] );

		$this->h->connections->incrementFailures( $id );
		$this->h->connections->setStatus( $id, ConnectionStatus::Active );
		$public = $this->h->connections->findPublic( $id );
		$this->assertNull( $public['paused_until'] );
		$this->assertNull( $public['paused_reason'] );
		$this->assertSame( 0, $public['failures_in_a_row'], 'becoming active clears the failure count' );
	}

	public function test_failure_counter_is_one_atomic_statement(): void {
		$id = $this->h->connection();
		$this->assertSame( 1, $this->h->connections->incrementFailures( $id ) );
		$this->assertSame( 2, $this->h->connections->incrementFailures( $id ) );
		$this->assertSame( 3, $this->h->connections->incrementFailures( $id ) );
		$this->h->connections->resetFailures( $id );
		$this->assertSame( 0, $this->h->connections->findPublic( $id )['failures_in_a_row'] );
	}

	public function test_only_one_caller_can_trip_or_close_the_circuit(): void {
		$id = $this->h->connection();
		$this->assertTrue( $this->h->connections->tripCircuit( $id, '2026-11-02 12:02:00' ) );
		$this->assertFalse( $this->h->connections->tripCircuit( $id, '2026-11-02 12:02:00' ), 'already open' );
		$this->assertSame( 'paused_circuit', $this->h->connections->findPublic( $id )['status'] );

		$this->h->connections->scheduleProbe( $id, '2026-11-02 12:10:00' );
		$this->assertSame( '2026-11-02T12:10:00Z', $this->h->connections->findPublic( $id )['paused_until'] );

		$this->assertTrue( $this->h->connections->resumeFromCircuit( $id ) );
		$this->assertFalse( $this->h->connections->resumeFromCircuit( $id ), 'already closed' );
		$this->assertSame( 'active', $this->h->connections->findPublic( $id )['status'] );
	}

	public function test_a_manual_pause_is_not_undone_by_the_circuit_calls(): void {
		$id = $this->h->connection();
		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'person' );
		$this->assertFalse( $this->h->connections->tripCircuit( $id, '2026-11-02 12:02:00' ) );
		$this->assertFalse( $this->h->connections->resumeFromCircuit( $id ) );
		$this->assertSame( 'paused', $this->h->connections->findPublic( $id )['status'] );
	}

	public function test_response_time_is_a_moving_average(): void {
		$id = $this->h->connection();
		$this->assertNull( $this->h->connections->findPublic( $id )['typical_response_ms'] );
		$this->h->connections->recordSuccess( $id, 100 );
		$this->assertSame( 100, $this->h->connections->findPublic( $id )['typical_response_ms'] );
		$this->h->connections->recordSuccess( $id, 200 );
		$this->assertSame( 120, $this->h->connections->findPublic( $id )['typical_response_ms'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $this->h->connections->findPublic( $id )['last_success_at'] );

		$this->h->clock->advance( 30 );
		$this->h->connections->recordFailure( $id, 0 );
		$this->assertSame( '2026-11-02T12:00:30Z', $this->h->connections->findPublic( $id )['last_failure_at'] );
	}

	public function test_only_active_and_not_timed_paused_connections_may_send(): void {
		$active = $this->h->connection();
		$paused = $this->h->connection();
		$timed  = $this->h->connection();
		$gone   = $this->h->connection();
		$this->h->connections->setStatus( $paused, ConnectionStatus::Paused );
		$this->h->sql( "UPDATE `{$this->h->tables->connections()}` SET paused_until = '2026-11-02 12:30:00' WHERE id = {$timed}" ); // phpcs:ignore
		$this->h->connections->delete( $gone );

		$this->assertSame( array( $active ), $this->h->connections->activeIds() );

		$this->h->clock->advance( 31 * 60 );
		$this->assertSame( array( $active, $timed ), $this->h->connections->activeIds(), 'an ended pause lets it send again' );
	}

	public function test_pauses_that_are_due_are_listed(): void {
		$timed   = $this->h->connection();
		$circuit = $this->h->connection();
		$this->h->connections->setStatus( $timed, ConnectionStatus::Paused, '2026-11-02 12:10:00', 'person' );
		$this->h->connections->setStatus( $circuit, ConnectionStatus::PausedCircuit, '2026-11-02 12:02:00', 'circuit' );
		$this->assertSame( array(), $this->h->connections->pausesDue() );
		$this->h->clock->advance( 180 );
		$this->assertSame( array( $circuit ), array_column( $this->h->connections->pausesDue(), 'id' ) );
		$this->h->clock->advance( 600 );
		$this->assertSame( array( $timed, $circuit ), array_column( $this->h->connections->pausesDue(), 'id' ) );
	}

	public function test_disconnect_wipes_the_secrets_and_keeps_the_row(): void {
		$id = $this->h->connection( array(), array( 'api_key' => self::SECRET ) );
		$this->h->connections->incrementFailures( $id );
		$this->h->connections->delete( $id );

		$row = $this->h->connectionRow( $id );
		$this->assertSame( '', $row['secrets_enc'] );
		$this->assertSame( '{}', $row['secret_hints_json'] );
		$this->assertSame( 'disconnected', $row['status'] );
		$this->assertSame( '', $this->h->connections->find( $id )->secret( 'api_key' ) );
		$this->assertNull( $this->h->connections->forConnector( 'fake' ), 'a disconnected connection is not used for sending' );
		$this->assertSame( 'disconnected', $this->h->connections->findPublic( $id )['status'] );
	}

	public function test_lists_come_oldest_first(): void {
		$a = $this->h->connection();
		$b = $this->h->connection();
		$this->assertSame( array( $a, $b ), array_column( $this->h->connections->all(), 'id' ) );
		$this->assertSame( array( $a, $b ), array_map( static fn( $c ) => $c->id, $this->h->connections->allWithoutSecrets() ) );
		$this->assertSame( $a, $this->h->connections->forConnector( 'fake' )->id );
	}
}
