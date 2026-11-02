<?php
/**
 * Settings validation and defaults.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core;

use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/CoreWpStubs.php';

final class SettingsTest extends TestCase {

	private CoreArrayOptionStore $store;

	private Settings $settings;

	protected function setUp(): void {
		$this->store    = new CoreArrayOptionStore();
		$this->settings = new Settings( $this->store, 'owner@example.test' );
	}

	public function test_defaults_match_the_shape_of_types_ts(): void {
		$settings = $this->settings->get();
		$this->assertSame(
			array( 'display_time', 'log_retention_days', 'log_excerpt_bytes', 'redaction', 'payload_cap_bytes', 'delete_on_uninstall', 'runtime_profile', 'global_concurrency', 'backlog_threshold', 'admin_bar', 'notifications' ),
			array_keys( $settings )
		);
		$this->assertSame( 'store', $settings['display_time'] );
		$this->assertSame( 14, $settings['log_retention_days'] );
		$this->assertSame( 2048, $settings['log_excerpt_bytes'] );
		$this->assertTrue( $settings['redaction'] );
		$this->assertFalse( $settings['delete_on_uninstall'] );
		$this->assertSame( 'standard', $settings['runtime_profile'] );
		$this->assertSame( 2, $settings['global_concurrency'] );
		$this->assertSame( 200, $settings['backlog_threshold'] );
		$this->assertSame( array( 'owner@example.test' ), $settings['notifications']['recipients'] );
		$this->assertSame( '08:00', $settings['notifications']['digest_time'] );
	}

	public function test_typed_getters(): void {
		$this->assertSame( 14, $this->settings->logRetentionDays() );
		$this->assertSame( 2048, $this->settings->logExcerptBytes() );
		$this->assertTrue( $this->settings->redactionEnabled() );
		$this->assertFalse( $this->settings->deleteOnUninstall() );
		$this->assertSame( 2, $this->settings->globalConcurrency() );
		$this->assertSame( 200, $this->settings->backlogThreshold() );
		$this->assertSame( 'standard', $this->settings->runtimeProfile() );
		$this->assertSame( 'store', $this->settings->displayTime() );
		$this->assertTrue( $this->settings->adminBar() );
		$this->assertSame( 262144, $this->settings->payloadCapBytes() );
		$this->assertTrue( $this->settings->notifications()['on_paused'] );
	}

	public function test_valid_changes_are_saved_and_returned(): void {
		$saved = $this->settings->update(
			array(
				'log_retention_days'  => 30,
				'log_excerpt_bytes'   => 8192,
				'redaction'           => false,
				'delete_on_uninstall' => true,
				'global_concurrency'  => '5',
				'backlog_threshold'   => 10000,
				'display_time'        => 'utc',
			)
		);
		$this->assertSame( 30, $saved['log_retention_days'] );
		$this->assertSame( 5, $saved['global_concurrency'] );
		$this->assertFalse( $saved['redaction'] );
		$this->assertSame( $saved, $this->settings->get() );
		$this->assertFalse( $this->store->autoloaded[ Settings::OPTION ], 'settings must not be autoloaded' );
	}

	/**
	 * @dataProvider outOfRange
	 *
	 * @param mixed $value Bad value.
	 */
	public function test_numbers_outside_their_range_are_refused_with_a_field_message( string $field, mixed $value, string $message ): void {
		try {
			$this->settings->update( array( $field => $value ) );
			$this->fail( 'Expected a validation error for ' . $field );
		} catch ( ValidationException $e ) {
			$this->assertSame( array( $field => $message ), $e->errors() );
		}
		$this->assertSame( array(), $this->store->values, 'nothing may be saved' );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:string}>
	 */
	public static function outOfRange(): array {
		return array(
			'concurrency zero'     => array( 'global_concurrency', 0, 'Enter a number from 1 to 10.' ),
			'concurrency eleven'   => array( 'global_concurrency', 11, 'Enter a number from 1 to 10.' ),
			'concurrency text'     => array( 'global_concurrency', 'many', 'Enter a number from 1 to 10.' ),
			'backlog nine'         => array( 'backlog_threshold', 9, 'Enter a number from 10 to 10000.' ),
			'backlog too big'      => array( 'backlog_threshold', 10001, 'Enter a number from 10 to 10000.' ),
			'retention 10 days'    => array( 'log_retention_days', 10, 'Choose 7, 14 or 30 days.' ),
			'excerpt 4 kb'         => array( 'log_excerpt_bytes', 4096, 'Choose nothing, 2 KB or 8 KB.' ),
			'bad display time'     => array( 'display_time', 'browser', 'Choose store time or UTC.' ),
			'bad profile'          => array( 'runtime_profile', 'turbo', 'Choose Standard or High volume.' ),
			'payload cap too small' => array( 'payload_cap_bytes', 10, 'Enter a number from 1024 to 10485760.' ),
		);
	}

	public function test_valid_fields_are_not_saved_when_another_field_is_invalid(): void {
		try {
			$this->settings->update( array( 'log_retention_days' => 30, 'global_concurrency' => 99 ) );
			$this->fail( 'Expected a validation error.' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'global_concurrency', $e->errors() );
			$this->assertArrayNotHasKey( 'log_retention_days', $e->errors() );
		}
		$this->assertSame( 14, $this->settings->logRetentionDays() );
	}

	public function test_validate_reports_errors_without_saving(): void {
		$errors = $this->settings->validate( array( 'backlog_threshold' => 5, 'redaction' => 'maybe' ) );
		$this->assertSame( array( 'backlog_threshold', 'redaction' ), array_keys( $errors ) );
		$this->assertSame( array(), $this->store->values );
	}

	public function test_booleans_accept_the_usual_spellings(): void {
		$saved = $this->settings->update( array( 'redaction' => '0', 'admin_bar' => 'false', 'delete_on_uninstall' => 1 ) );
		$this->assertFalse( $saved['redaction'] );
		$this->assertFalse( $saved['admin_bar'] );
		$this->assertTrue( $saved['delete_on_uninstall'] );
	}

	public function test_notification_recipients_are_validated_deduplicated_and_capped(): void {
		$saved = $this->settings->update( array( 'notifications' => array( 'recipients' => array( 'a@example.test', 'A@example.test', ' b@example.test ' ) ) ) );
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), $saved['notifications']['recipients'] );

		$ten = array_map( static fn( int $i ): string => "user{$i}@example.test", range( 1, 10 ) );
		$this->assertCount( 10, $this->settings->update( array( 'notifications' => array( 'recipients' => $ten ) ) )['notifications']['recipients'] );

		$eleven = array_map( static fn( int $i ): string => "user{$i}@example.test", range( 1, 11 ) );
		$errors = $this->settings->validate( array( 'notifications' => array( 'recipients' => $eleven ) ) );
		$this->assertSame( 'Use at most 10 email addresses.', $errors['notifications.recipients'] );

		$this->assertArrayHasKey( 'notifications.recipients', $this->settings->validate( array( 'notifications' => array( 'recipients' => array() ) ) ) );
		$this->assertArrayHasKey( 'notifications.recipients', $this->settings->validate( array( 'notifications' => array( 'recipients' => array( 'not an email' ) ) ) ) );
	}

	public function test_notification_changes_can_be_partial_and_the_digest_time_is_checked(): void {
		$saved = $this->settings->update( array( 'notifications' => array( 'daily_digest' => false, 'digest_time' => '23:59' ) ) );
		$this->assertFalse( $saved['notifications']['daily_digest'] );
		$this->assertSame( '23:59', $saved['notifications']['digest_time'] );
		$this->assertSame( array( 'owner@example.test' ), $saved['notifications']['recipients'] );
		$this->assertTrue( $saved['notifications']['on_paused'] );

		foreach ( array( '24:00', '8:00', '08:60', 'noon' ) as $bad ) {
			$this->assertSame( 'Enter a time from 00:00 to 23:59.', $this->settings->validate( array( 'notifications' => array( 'digest_time' => $bad ) ) )['notifications.digest_time'], $bad );
		}
	}

	public function test_unknown_keys_are_ignored(): void {
		$saved = $this->settings->update( array( 'made_up' => 1, 'redaction' => false ) );
		$this->assertArrayNotHasKey( 'made_up', $saved );
		$this->assertFalse( $saved['redaction'] );
	}

	public function test_a_stored_value_that_is_no_longer_valid_falls_back_to_the_default(): void {
		$this->store->values[ Settings::OPTION ] = array(
			'global_concurrency' => 500,
			'log_retention_days' => 30,
			'notifications'      => array( 'digest_time' => 'bad', 'on_paused' => false ),
		);
		$settings = $this->settings->get();
		$this->assertSame( 2, $settings['global_concurrency'] );
		$this->assertSame( 30, $settings['log_retention_days'] );
		$this->assertSame( '08:00', $settings['notifications']['digest_time'] );
		$this->assertFalse( $settings['notifications']['on_paused'] );
	}
}
