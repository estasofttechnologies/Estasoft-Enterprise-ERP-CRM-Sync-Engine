<?php
/**
 * The logger: redaction, size cap, never throwing.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Logging;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Logging\LogPruner;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class LoggerTest extends CoreIntegrationTestCase {

	public function test_levels_are_written_with_their_context(): void {
		$info    = $this->h->logger->info( 'Sent to Fakesystem.', array( 'connection_id' => 1, 'queue_id' => 5, 'entity_ref' => 'order:10490', 'duration_ms' => 120, 'attempt' => 1 ) );
		$warning = $this->h->logger->warning( 'Slow answer', array( 'code' => ErrorCode::Net001 ) );
		$error   = $this->h->logger->error( 'Rejected', array( 'code' => 'SYNC-VAL-002', 'http_status' => 422 ) );

		$this->assertSame( 'info', $this->h->logs->find( (int) $info )['level'] );
		$this->assertSame( 'warning', $this->h->logs->find( (int) $warning )['level'] );
		$this->assertSame( 'SYNC-NET-001', $this->h->logs->find( (int) $warning )['code'], 'an ErrorCode is stored as its text' );
		$this->assertSame( 422, $this->h->logs->find( (int) $error )['http_status'] );
		$this->assertSame( 'order:10490', $this->h->logs->find( (int) $info )['entity_ref'] );
	}

	public function test_personal_data_in_a_message_is_removed_before_it_is_stored(): void {
		$id    = (int) $this->h->logger->error( 'Order from noor@example.com (+971 50 123 4567) rejected; VAT 310122393500003', array( 'entity_ref' => 'noor@example.com' ) );
		$entry = $this->h->logs->find( $id );
		$raw   = (string) $this->h->wpdb->get_var( $this->h->wpdb->prepare( "SELECT CONCAT_WS('|', message, entity_ref, request_excerpt) FROM `{$this->h->tables->logs()}` WHERE id = %d", $id ) ); // phpcs:ignore
		foreach ( array( 'noor@example.com', '50 123 4567', '310122393500003' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, $entry['message'] );
			$this->assertStringNotContainsString( $pii, (string) $entry['entity_ref'] );
		}
		$this->assertStringNotContainsString( 'noor@example.com', $raw );
		$this->assertStringContainsString( 'rejected', $entry['message'] );
	}

	public function test_excerpts_are_redacted_by_field_and_by_pattern(): void {
		$id = (int) $this->h->logger->info(
			'Sent',
			array(
				'request'  => array(
					'client_order_ref' => 'WC-10485',
					'partner'          => array( 'name' => 'Noor', 'email' => 'noor@example.com' ),
					'lines'            => array( array( 'default_code' => 'CS-BOWL-04' ) ),
					'note'             => 'call +971501234567',
				),
				'response' => '{"error":{"message":"Bearer abc123def456ghi789jkl012 rejected for noor@example.com"}}',
			)
		);
		$entry = $this->h->logs->find( $id );
		$this->assertStringContainsString( 'WC-10485', (string) $entry['request_excerpt'] );
		$this->assertStringContainsString( 'CS-BOWL-04', (string) $entry['request_excerpt'] );
		foreach ( array( 'Noor', 'noor@example.com', '971501234567' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, (string) $entry['request_excerpt'] );
			$this->assertStringNotContainsString( $pii, (string) $entry['response_excerpt'] );
		}
		$this->assertStringNotContainsString( 'abc123def456ghi789jkl012', (string) $entry['response_excerpt'] );
	}

	public function test_excerpts_are_cut_to_the_size_in_settings(): void {
		$big = array( 'text' => str_repeat( 'x', 5000 ) );
		$this->h->settings->update( array( 'log_excerpt_bytes' => 2048 ) );
		$small = $this->h->logs->find( (int) $this->h->logger->info( 'a', array( 'request' => $big ) ) );
		$this->assertLessThanOrEqual( 2048, strlen( (string) $small['request_excerpt'] ) );
		$this->assertGreaterThan( 2000, strlen( (string) $small['request_excerpt'] ) );

		$this->h->settings->update( array( 'log_excerpt_bytes' => 8192 ) );
		$large = $this->h->logs->find( (int) $this->h->logger->info( 'a', array( 'request' => $big ) ) );
		$this->assertLessThanOrEqual( 8192, strlen( (string) $large['request_excerpt'] ) );
		$this->assertGreaterThan( 5000, strlen( (string) $large['request_excerpt'] ) );

		$this->h->settings->update( array( 'log_excerpt_bytes' => 0 ) );
		$none = $this->h->logs->find( (int) $this->h->logger->info( 'a', array( 'request' => $big, 'response' => 'text' ) ) );
		$this->assertNull( $none['request_excerpt'] );
		$this->assertNull( $none['response_excerpt'] );
	}

	public function test_a_huge_payload_is_noted_not_stored(): void {
		$this->h->settings->update( array( 'payload_cap_bytes' => 1024 ) );
		$id = (int) $this->h->logger->info( 'Sent', array( 'request' => array( 'text' => str_repeat( 'x', 3000 ) ) ) );
		$this->assertSame( '[The payload is larger than 1 KB and was not kept.]', $this->h->logs->find( $id )['request_excerpt'] );
	}

	public function test_with_redaction_off_names_are_kept_but_secrets_never(): void {
		$this->h->settings->update( array( 'redaction' => false ) );
		$id    = (int) $this->h->logger->info( 'noor@example.com used Authorization: Bearer abc123def456ghi789jkl012', array( 'request' => array( 'email' => 'noor@example.com', 'password' => 'hunter2' ) ) );
		$entry = $this->h->logs->find( $id );
		$this->assertStringContainsString( 'noor@example.com', $entry['message'] );
		$this->assertStringContainsString( 'noor@example.com', (string) $entry['request_excerpt'] );
		$this->assertStringNotContainsString( 'abc123def456ghi789jkl012', $entry['message'] );
		$this->assertStringNotContainsString( 'hunter2', (string) $entry['request_excerpt'] );
	}

	public function test_the_extra_redaction_filter_runs_last(): void {
		add_filter(
			'estasoft_sync_log_redact',
			static fn( string $text ): string => str_replace( 'CUSTOMER-CODE-77', '[code]', $text )
		);
		$id = (int) $this->h->logger->info( 'Customer CUSTOMER-CODE-77 synced' );
		$this->assertSame( 'Customer [code] synced', $this->h->logs->find( $id )['message'] );
	}

	public function test_logging_never_throws_even_when_the_table_is_gone(): void {
		$table = $this->h->tables->logs();
		$this->h->sql( "RENAME TABLE `{$table}` TO `{$table}_away`" ); // phpcs:ignore
		try {
			$this->h->wpdb->suppress_errors( true );
			$this->assertNull( $this->h->logger->error( 'This cannot be saved', array( 'request' => array( 'a' => 'b' ) ) ) );
		} finally {
			$this->h->wpdb->suppress_errors( false );
			$this->h->sql( "RENAME TABLE `{$table}_away` TO `{$table}`" ); // phpcs:ignore
		}
		$this->assertNotNull( $this->h->logger->info( 'Works again' ) );
	}

	public function test_the_pruner_uses_the_retention_setting(): void {
		$pruner = new LogPruner( $this->h->logs, $this->h->settings );
		$this->h->logger->info( 'old' );
		$this->h->clock->advance( 10 * 86400 );
		$this->assertSame( 0, $pruner->run(), '14 days by default' );
		$this->h->settings->update( array( 'log_retention_days' => 7 ) );
		$this->assertSame( 1, $pruner->run() );
	}

	public function test_a_logger_can_be_built_without_the_container(): void {
		$logger = new Logger( $this->h->logs, $this->h->settings, $this->h->redactor );
		$this->assertNotNull( $logger->info( 'standalone' ) );
	}
}
