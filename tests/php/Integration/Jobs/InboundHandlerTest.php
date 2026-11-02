<?php
/**
 * The signed inbound route.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\Adapters\WpTransients;
use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\Inbound\InboundRefused;
use Estasoft\SyncEngine\Jobs\JobException;

final class InboundHandlerTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
	}

	/**
	 * Headers for a body: the signature covers "{timestamp}.{rawbody}".
	 *
	 * @param string      $secret Secret.
	 * @param string      $body   Body.
	 * @param int         $offset Seconds from now for the timestamp.
	 * @param string|null $stamp  The timestamp text to send, instead of Unix seconds.
	 * @return array<string,string>
	 */
	private function headers( string $secret, string $body, int $offset = 0, ?string $stamp = null ): array {
		$stamp ??= (string) ( $this->h->clock->now()->getTimestamp() + $offset );
		return array(
			'X-Estasoft-Signature' => hash_hmac( 'sha256', $stamp . '.' . $body, $secret ),
			'X-Estasoft-Timestamp' => $stamp,
		);
	}

	public function test_enable_returns_a_long_random_secret_once_and_stores_it_encrypted(): void {
		$secret = $this->j->inbound->enable( $this->connection );

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $secret ) );
		$row = $this->h->connectionRow( $this->connection );
		$this->assertStringNotContainsString( $secret, (string) $row['secrets_enc'] );
		$this->assertStringNotContainsString( $secret, (string) $row['config_json'] );
		$this->assertSame( '1', (string) $row['inbound_enabled'] );
		$this->assertNotSame( $secret, $this->j->inbound->enable( $this->connection ), 'enabling again makes a new secret' );
		$public = $this->h->connections->findPublic( $this->connection );
		$this->assertTrue( $public['inbound_enabled'] );
	}

	public function test_a_signed_message_in_time_is_accepted_and_schedules_one_refresh(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{"entity":"stock","id":"CS-LAMP-07"}';

		$this->assertSame( array( 'accepted' => true ), $this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ) ) );

		$this->assertSame( array( array( 'inbound', $this->connection, 'stock' ) ), $this->j->scheduled );
	}

	public function test_header_names_are_case_insensitive_the_prefix_is_allowed_and_an_unknown_hint_refreshes_everything(): void {
		$secret  = $this->j->inbound->enable( $this->connection );
		$body    = 'not json';
		$stamp   = $this->h->clock->now()->format( 'c' );
		$headers = array(
			'x-estasoft-signature' => 'sha256=' . strtoupper( hash_hmac( 'sha256', $stamp . '.' . $body, $secret ) ),
			'x-estasoft-timestamp' => $stamp,
		);

		$this->j->inbound->handle( $this->connection, $body, $headers );

		$this->assertSame( 'all', $this->j->scheduled[0][2], 'an ISO time is signed exactly as it was sent' );
	}

	public function test_the_signature_covers_the_timestamp_so_a_captured_message_cannot_be_replayed_with_a_fresh_one(): void {
		$secret   = $this->j->inbound->enable( $this->connection );
		$body     = '{"entity":"stock"}';
		$captured = $this->headers( $secret, $body, -400 );

		// The attacker keeps the captured signature and sets a time inside the window.
		$forged = array(
			'X-Estasoft-Signature' => $captured['X-Estasoft-Signature'],
			'X-Estasoft-Timestamp' => (string) $this->h->clock->now()->getTimestamp(),
		);
		try {
			$this->j->inbound->handle( $this->connection, $body, $forged );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 401, $e->httpStatus() );
		}
		$this->assertSame( array(), $this->j->scheduled );
	}

	public function test_the_old_signature_of_the_body_alone_is_no_longer_accepted(): void {
		$secret  = $this->j->inbound->enable( $this->connection );
		$body    = '{}';
		$headers = array(
			'X-Estasoft-Signature' => hash_hmac( 'sha256', $body, $secret ),
			'X-Estasoft-Timestamp' => (string) $this->h->clock->now()->getTimestamp(),
		);
		$this->expectException( InboundRefused::class );
		$this->j->inbound->handle( $this->connection, $body, $headers );
	}

	public function test_a_wrong_signature_is_refused_with_401_and_logged_as_sync_inb_001(): void {
		$this->j->inbound->enable( $this->connection );
		$body = '{}';
		try {
			$this->j->inbound->handle( $this->connection, $body, $this->headers( 'wrong-secret', $body ) );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 401, $e->httpStatus() );
			$this->assertSame( ErrorCode::Inb001, $e->errorCode() );
		}
		$entries = array_filter( $this->h->logs->latest( 5 ), static fn( array $entry ): bool => 'SYNC-INB-001' === $entry['code'] );
		$this->assertCount( 1, $entries );
		$this->assertSame( array(), $this->j->scheduled );
	}

	public function test_a_body_changed_after_signing_is_refused(): void {
		$secret  = $this->j->inbound->enable( $this->connection );
		$headers = $this->headers( $secret, '{"entity":"stock"}' );
		$this->expectException( InboundRefused::class );
		$this->j->inbound->handle( $this->connection, '{"entity":"tracking"}', $headers );
	}

	public function test_a_timestamp_outside_five_minutes_is_refused_even_with_a_valid_signature(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{}';
		foreach ( array( -301, 301 ) as $offset ) {
			try {
				$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body, $offset ) );
				$this->fail( 'expected a refusal' );
			} catch ( InboundRefused $e ) {
				$this->assertSame( 401, $e->httpStatus() );
			}
		}
		$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body, -299 ) );
		$this->assertCount( 1, $this->j->scheduled );
	}

	public function test_a_replay_is_refused_for_ten_minutes(): void {
		$secret  = $this->j->inbound->enable( $this->connection );
		$body    = '{}';
		$headers = $this->headers( $secret, $body );
		$this->j->inbound->handle( $this->connection, $body, $headers );

		try {
			$this->j->inbound->handle( $this->connection, $body, $headers );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		$this->assertCount( 1, $this->j->scheduled );
	}

	public function test_requests_are_limited_per_connection_per_minute(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{}';
		$last   = 0;
		for ( $i = 1; $i <= InboundHandler::RATE_LIMIT + 1; ++$i ) {
			try {
				$this->j->inbound->handle( $this->connection, $body, $this->headers( 'guess-' . $i, $body ) );
			} catch ( InboundRefused $e ) {
				$last = $e->httpStatus();
			}
		}
		$this->assertSame( 429, $last );
		try {
			$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ) );
			$this->fail( 'still limited' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 429, $e->httpStatus() );
		}
		$this->h->clock->advance( 61 );
		$this->assertSame( array( 'accepted' => true ), $this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ) ) );
	}

	/**
	 * Texts that are not a time, however well they are signed.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function notATime(): array {
		return array(
			'now'                  => array( 'now' ),
			'relative plus'        => array( '+1 minute' ),
			'relative word'        => array( 'tomorrow' ),
			'at sign'              => array( '@1793620800' ),
			'space not T'          => array( '2026-11-02 12:00:00' ),
			'a date only'          => array( '2026-11-02' ),
			'no zone'              => array( '2026-11-02T12:00:00' ),
			'a day that is not'    => array( '2026-02-30T12:00:00Z' ),
			'a month that is not'  => array( '2026-13-02T12:00:00Z' ),
			'digits too short'     => array( '12345' ),
			'digits with a dot'    => array( '1793620800.5' ),
			'trailing text'        => array( '2026-11-02T12:00:00Z next week' ),
			'empty'                => array( '' ),
		);
	}

	/**
	 * @dataProvider notATime
	 *
	 * @param string $stamp The header value.
	 */
	public function test_a_timestamp_that_is_not_digits_or_strict_iso_is_refused_even_when_signed(string $stamp): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{}';
		try {
			$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body, 0, $stamp ) );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 401, $e->httpStatus() );
		}
		$this->assertSame( array(), $this->j->scheduled );
	}

	/**
	 * Times that are accepted.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function goodTimes(): array {
		return array(
			'zulu'          => array( '2026-11-02T12:00:00Z' ),
			'offset'        => array( '2026-11-02T16:00:00+04:00' ),
			'negative zone' => array( '2026-11-02T07:00:00-05:00' ),
			'fractions'     => array( '2026-11-02T12:00:00.250Z' ),
			'micro'         => array( '2026-11-02T12:00:00.123456+00:00' ),
		);
	}

	/**
	 * @dataProvider goodTimes
	 *
	 * @param string $stamp The header value (all name 12:00:00 UTC, the harness clock).
	 */
	public function test_strict_iso_times_are_accepted_and_signed_as_sent(string $stamp): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{"entity":"stock"}';

		$this->assertSame( array( 'accepted' => true ), $this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body, 0, $stamp ) ) );
	}

	public function test_an_iso_time_outside_the_window_is_refused(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{}';
		$this->expectException( InboundRefused::class );
		$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body, 0, '2026-11-02T11:00:00Z' ) );
	}

	public function test_the_limit_is_per_source_address_so_one_noisy_address_cannot_lock_the_sender_out(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$body   = '{}';
		$noisy  = '198.51.100.9';
		$status = 0;
		for ( $i = 1; $i <= 400; ++$i ) {
			try {
				$this->j->inbound->handle( $this->connection, $body, $this->headers( 'guess-' . $i, $body ), $noisy );
			} catch ( InboundRefused $e ) {
				$status = $e->httpStatus();
			}
		}
		$this->assertSame( 429, $status, 'the noisy address is limited' );

		$this->assertSame( array( 'accepted' => true ), $this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ), '203.0.113.7' ), 'another address is not' );
		$this->assertSame( array( 'accepted' => true ), $this->j->inbound->handle( $this->connection, '{"entity":"stock"}', $this->headers( $secret, '{"entity":"stock"}' ) ), 'nor is the default address of the request' );
	}

	public function test_all_addresses_together_are_limited_per_connection(): void {
		$this->j->inbound->enable( $this->connection );
		$body   = '{}';
		$status = array();
		for ( $source = 1; $source <= 11; ++$source ) {
			for ( $i = 1; $i <= 28; ++$i ) {
				try {
					$this->j->inbound->handle( $this->connection, $body, $this->headers( 'guess', $body ), '192.0.2.' . $source );
				} catch ( InboundRefused $e ) {
					$status[ $e->httpStatus() ] = ( $status[ $e->httpStatus() ] ?? 0 ) + 1;
				}
			}
		}
		$this->assertSame( InboundHandler::CONNECTION_RATE_LIMIT, 11 * 28 - $status[429], '300 got an answer, the rest were told to wait' );
		$this->assertSame( 8, $status[429] );
	}

	public function test_not_found_and_too_many_requests_are_logged_but_not_on_every_repeat(): void {
		$logs = fn(): int => count( array_filter( $this->h->logs->latest( 100 ), static fn( array $entry ): bool => 'SYNC-INB-001' === $entry['code'] ) );
		foreach ( range( 1, 6 ) as $guess ) {
			try {
				$this->j->inbound->handle( 90000 + $guess, '{}', array() );
			} catch ( InboundRefused $e ) {
				$this->assertSame( 404, $e->httpStatus() );
			}
		}
		$this->assertSame( 1, $logs(), 'six unknown connections in a minute: one line' );
		$this->h->clock->advance( 61 );
		try {
			$this->j->inbound->handle( 90100, '{}', array() );
		} catch ( InboundRefused $e ) {
			unset( $e );
		}
		$this->assertSame( 2, $logs(), 'and another a minute later' );

		$secret = $this->j->inbound->enable( $this->connection );
		$before = $logs();
		for ( $i = 1; $i <= InboundHandler::RATE_LIMIT + 5; ++$i ) {
			$body = '{"n":' . $i . '}';
			try {
				$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ) );
			} catch ( InboundRefused $e ) {
				$this->assertSame( 429, $e->httpStatus() );
			}
		}
		$this->assertSame( $before + 1, $logs(), 'five refusals with 429, one log line; the 30 accepted ones logged nothing' );
	}

	public function test_the_counter_is_atomic_with_an_object_cache_and_still_counts_without_one(): void {
		$transients = new WpTransients();
		delete_transient( 'estasoft_sync_test_counter' );
		$this->assertSame( 1, $transients->increment( 'estasoft_sync_test_counter', 60 ) );
		$this->assertSame( 2, $transients->increment( 'estasoft_sync_test_counter', 60 ) );
		$this->assertSame( 3, $transients->increment( 'estasoft_sync_test_counter', 60 ) );
		delete_transient( 'estasoft_sync_test_counter' );

		// With an external object cache the count goes through wp_cache_incr.
		$was = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		try {
			$this->assertSame( 1, $transients->increment( 'estasoft_sync_test_atomic', 60 ) );
			$this->assertSame( 2, $transients->increment( 'estasoft_sync_test_atomic', 60 ) );
			$this->assertSame( 2, (int) wp_cache_get( 'estasoft_sync_test_atomic', 'estasoft_sync' ) );
		} finally {
			wp_cache_delete( 'estasoft_sync_test_atomic', 'estasoft_sync' );
			wp_using_ext_object_cache( $was );
		}
	}

	public function test_a_large_body_a_disabled_connection_and_an_unknown_connection_are_refused(): void {
		$secret = $this->j->inbound->enable( $this->connection );
		$big    = str_repeat( 'a', InboundHandler::MAX_BODY + 1 );
		try {
			$this->j->inbound->handle( $this->connection, $big, $this->headers( $secret, $big ) );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 413, $e->httpStatus() );
		}

		$this->j->inbound->disable( $this->connection );
		$body = '{}';
		try {
			$this->j->inbound->handle( $this->connection, $body, $this->headers( $secret, $body ) );
			$this->fail( 'expected a refusal' );
		} catch ( InboundRefused $e ) {
			$this->assertSame( 404, $e->httpStatus() );
		}
		$this->assertSame( '0', (string) $this->h->connectionRow( $this->connection )['inbound_enabled'] );

		$this->expectException( InboundRefused::class );
		$this->j->inbound->handle( 99999, $body, array() );
	}

	public function test_missing_headers_are_refused(): void {
		$this->j->inbound->enable( $this->connection );
		$this->expectException( InboundRefused::class );
		$this->j->inbound->handle( $this->connection, '{}', array() );
	}

	public function test_enabling_without_an_encryption_key_is_refused_with_the_line_to_add(): void {
		$handler = new InboundHandler( $this->h->connections, new SecretBox( null ), $this->j->transients, $this->h->logger, $this->h->clock );
		try {
			$handler->enable( $this->connection );
			$this->fail( 'expected a refusal' );
		} catch ( JobException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
			$this->assertStringContainsString( "define( 'ESTASOFT_SYNC_KEY'", $e->context()['key_line'] );
		}
	}

	public function test_the_signature_is_compared_in_constant_time(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Jobs/Inbound/InboundHandler.php' );
		$this->assertStringContainsString( 'hash_equals', $code );
		$this->assertStringNotContainsString( '$signature ===', $code );
	}
}
