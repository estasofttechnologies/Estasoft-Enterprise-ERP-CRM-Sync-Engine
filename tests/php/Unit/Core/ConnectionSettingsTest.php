<?php
/**
 * Reliability settings of one connection.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core;

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class ConnectionSettingsTest extends TestCase {

	private ConnectionSettings $settings;

	protected function setUp(): void {
		$this->settings = new ConnectionSettings();
	}

	public function test_defaults_match_the_spec(): void {
		$defaults = $this->settings->defaults();
		$this->assertSame( array( 60, 300, 3600, 86400 ), $defaults['retry_delays_seconds'] );
		$this->assertTrue( $defaults['jitter'] );
		$this->assertSame( 5, $defaults['breaker_threshold'] );
		$this->assertSame( 2, $defaults['probe_interval_minutes'] );
		$this->assertSame( 4, $defaults['requests_per_second'] );
		$this->assertSame( 2, $defaults['concurrency'] );
		$this->assertTrue( $defaults['obey_retry_after'] );
	}

	public function test_connector_hints_set_the_starting_rate_inside_the_limits(): void {
		$odoo = $this->settings->defaults( array( 'requests_per_second' => 1.0, 'concurrency' => 1 ) );
		$this->assertSame( 1, $odoo['requests_per_second'] );
		$this->assertSame( 1, $odoo['concurrency'] );
		$slow = $this->settings->defaults( array( 'requests_per_second' => 0.2, 'concurrency' => 0 ) );
		$this->assertSame( 1, $slow['requests_per_second'] );
		$this->assertSame( 1, $slow['concurrency'] );
		$fast = $this->settings->defaults( array( 'requests_per_second' => 900.0, 'concurrency' => 99 ) );
		$this->assertSame( 50, $fast['requests_per_second'] );
		$this->assertSame( 10, $fast['concurrency'] );
	}

	/**
	 * @dataProvider boundaries
	 *
	 * @param mixed $value Value.
	 */
	public function test_ranges( string $field, mixed $value, bool $valid ): void {
		$errors = $this->settings->validate( array( $field => $value ) );
		$this->assertSame( $valid, array() === $errors, $field . ' = ' . var_export( $value, true ) );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:bool}>
	 */
	public static function boundaries(): array {
		return array(
			'breaker 2'      => array( 'breaker_threshold', 2, false ),
			'breaker 3'      => array( 'breaker_threshold', 3, true ),
			'breaker 20'     => array( 'breaker_threshold', 20, true ),
			'breaker 21'     => array( 'breaker_threshold', 21, false ),
			'probe 0'        => array( 'probe_interval_minutes', 0, false ),
			'probe 1'        => array( 'probe_interval_minutes', 1, true ),
			'probe 60'       => array( 'probe_interval_minutes', 60, true ),
			'probe 61'       => array( 'probe_interval_minutes', 61, false ),
			'rate 0'         => array( 'requests_per_second', 0, false ),
			'rate 50'        => array( 'requests_per_second', 50, true ),
			'rate 51'        => array( 'requests_per_second', 51, false ),
			'items 10'       => array( 'concurrency', 10, true ),
			'items 11'       => array( 'concurrency', 11, false ),
			'delays ok'      => array( 'retry_delays_seconds', array( 30, 120 ), true ),
			'delays empty'   => array( 'retry_delays_seconds', array(), false ),
			'delay zero'     => array( 'retry_delays_seconds', array( 0, 60 ), false ),
			'delay too long' => array( 'retry_delays_seconds', array( 60, 90000 ), false ),
			'delays not list' => array( 'retry_delays_seconds', '60,300', false ),
			'too many delays' => array( 'retry_delays_seconds', array_fill( 0, 11, 60 ), false ),
			'jitter off'     => array( 'jitter', false, true ),
			'jitter maybe'   => array( 'jitter', 'maybe', false ),
		);
	}

	public function test_merge_keeps_unchanged_fields_and_refuses_bad_ones_whole(): void {
		$current = $this->settings->defaults();
		$merged  = $this->settings->merge( $current, array( 'breaker_threshold' => '8', 'jitter' => false ) );
		$this->assertSame( 8, $merged['breaker_threshold'] );
		$this->assertFalse( $merged['jitter'] );
		$this->assertSame( 4, $merged['requests_per_second'] );

		try {
			$this->settings->merge( $current, array( 'breaker_threshold' => 8, 'concurrency' => 50 ) );
			$this->fail( 'Expected a validation error.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( array( 'concurrency' ), array_keys( $e->errors() ) );
		}
	}

	public function test_normalise_replaces_invalid_stored_values_with_defaults(): void {
		$normal = $this->settings->normalise( array( 'breaker_threshold' => 999, 'probe_interval_minutes' => 7, 'unknown' => 1 ) );
		$this->assertSame( 5, $normal['breaker_threshold'] );
		$this->assertSame( 7, $normal['probe_interval_minutes'] );
		$this->assertArrayNotHasKey( 'unknown', $normal );
	}
}
