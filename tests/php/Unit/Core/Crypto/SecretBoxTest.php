<?php
/**
 * Encryption of credentials.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Crypto;

use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase {

	private const KEY = 'correct-horse-battery-staple-0123';

	public function test_round_trip_returns_the_same_secrets(): void {
		$box     = new SecretBox( self::KEY );
		$secrets = array(
			'api_key' => 'sk_live_123456789abcdef',
			'password' => 'pässwörd نور',
		);
		$stored  = $box->encrypt( $secrets );
		$this->assertSame( $secrets, $box->decrypt( $stored ) );
	}

	public function test_the_stored_text_hides_the_secret_and_has_a_version_prefix(): void {
		$box    = new SecretBox( self::KEY );
		$stored = $box->encrypt( array( 'api_key' => 'sk_live_123456789abcdef' ) );
		$this->assertStringStartsWith( 'v1.', $stored );
		$this->assertStringNotContainsString( 'sk_live', $stored );
		$this->assertStringNotContainsString( 'sk_live', (string) base64_decode( substr( $stored, 3 ), true ) );
	}

	public function test_every_encryption_uses_a_new_nonce(): void {
		$box = new SecretBox( self::KEY );
		$this->assertNotSame( $box->encrypt( array( 'a' => 'b' ) ), $box->encrypt( array( 'a' => 'b' ) ) );
	}

	public function test_nothing_to_encrypt_gives_an_empty_string_and_needs_no_key(): void {
		$box = new SecretBox( null );
		$this->assertSame( '', $box->encrypt( array() ) );
		$this->assertSame( array(), $box->decrypt( '' ) );
	}

	public function test_missing_key_is_reported_with_sync_cfg_002(): void {
		$box = new SecretBox( null );
		$this->assertFalse( $box->keyAvailable() );
		try {
			$box->encrypt( array( 'a' => 'b' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
			$this->assertSame( ErrorCode::Cfg002->template(), $e->getMessage() );
		}
		$stored = ( new SecretBox( self::KEY ) )->encrypt( array( 'a' => 'b' ) );
		try {
			$box->decrypt( $stored );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
		}
	}

	public function test_a_short_key_counts_as_missing(): void {
		$this->assertFalse( ( new SecretBox( 'short' ) )->keyAvailable() );
		$this->assertFalse( ( new SecretBox( '' ) )->keyAvailable() );
	}

	public function test_wrong_key_fails_with_a_clear_message_and_no_auth_code(): void {
		$stored = ( new SecretBox( self::KEY ) )->encrypt( array( 'api_key' => 'sk_live_123456789abcdef' ) );
		try {
			( new SecretBox( 'a-completely-different-key-9876' ) )->decrypt( $stored );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertNull( $e->errorCode() );
			$this->assertStringContainsString( 'key', $e->getMessage() );
			$this->assertStringNotContainsString( 'sk_live', $e->getMessage() );
			$this->assertStringNotContainsString( 'AUTH', $e->getMessage() );
		}
	}

	public function test_tampered_or_damaged_text_is_refused(): void {
		$box    = new SecretBox( self::KEY );
		$stored = $box->encrypt( array( 'a' => 'b' ) );
		$raw    = (string) base64_decode( substr( $stored, 3 ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		foreach ( array( 'v1.' . base64_encode( $raw ), 'v1.@@@not-base64@@@', 'v1.' . base64_encode( 'tiny' ), 'plain text', 'v2.' . substr( $stored, 3 ) ) as $bad ) {
			try {
				$box->decrypt( $bad );
				$this->fail( 'Expected an exception for ' . $bad );
			} catch ( CryptoException $e ) {
				$this->assertNull( $e->errorCode() );
			}
		}
	}

	public function test_fingerprint_is_stable_per_key_and_reveals_nothing_of_it(): void {
		$a = new SecretBox( self::KEY );
		$b = new SecretBox( self::KEY );
		$c = new SecretBox( 'another-key-another-key-123456' );
		$this->assertSame( $a->fingerprint(), $b->fingerprint() );
		$this->assertNotSame( $a->fingerprint(), $c->fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a->fingerprint() );
		$this->assertStringNotContainsString( 'correct', $a->fingerprint() );
		$this->assertSame( '', ( new SecretBox( null ) )->fingerprint() );
	}

	public function test_generated_key_line_is_ready_to_paste_and_random(): void {
		$box  = new SecretBox( null );
		$line = $box->generateKeyLine();
		$this->assertMatchesRegularExpression( "/^define\( 'ESTASOFT_SYNC_KEY', '[0-9a-f]{64}' \);$/", $line );
		$this->assertNotSame( $line, $box->generateKeyLine() );

		preg_match( "/'([0-9a-f]{64})' \);$/", $line, $match );
		$generated = new SecretBox( $match[1] );
		$this->assertTrue( $generated->keyAvailable() );
		$this->assertSame( array( 'a' => 'b' ), $generated->decrypt( $generated->encrypt( array( 'a' => 'b' ) ) ) );
	}
}
