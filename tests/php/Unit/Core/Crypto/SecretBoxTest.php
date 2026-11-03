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
		$this->assertStringStartsWith( 'v2.', $stored );
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

	public function test_a_key_needs_at_least_32_characters(): void {
		$this->assertFalse( ( new SecretBox( 'short' ) )->keyAvailable() );
		$this->assertFalse( ( new SecretBox( '' ) )->keyAvailable() );
		$this->assertFalse( ( new SecretBox( str_repeat( 'k', 31 ) ) )->keyAvailable() );
		$this->assertTrue( ( new SecretBox( str_repeat( 'k', 32 ) ) )->keyAvailable() );
		$this->assertTrue( ( new SecretBox( 'short' ) )->keyTooShort() );
		$this->assertFalse( ( new SecretBox( '' ) )->keyTooShort(), 'No key at all is "missing", not "too short".' );
		$this->assertFalse( ( new SecretBox( str_repeat( 'k', 32 ) ) )->keyTooShort() );
	}

	public function test_a_short_key_is_refused_with_a_clear_message_and_sync_cfg_002(): void {
		$short = new SecretBox( str_repeat( 'k', 31 ) );
		try {
			$short->encrypt( array( 'a' => 'b' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertSame( ErrorCode::Cfg002, $e->errorCode() );
			$this->assertStringContainsString( 'too short', $e->getMessage() );
			$this->assertStringContainsString( '32', $e->getMessage() );
			$this->assertStringNotContainsString( 'kkkk', $e->getMessage() );
		}
		$stored = ( new SecretBox( self::KEY ) )->encrypt( array( 'a' => 'b' ) );
		$this->expectException( CryptoException::class );
		$short->decrypt( $stored );
	}

	public function test_the_cipher_key_is_derived_with_a_context_string(): void {
		// A v2 text must not open with the plain hash of the key material (the key of the first development builds).
		$plain = sodium_crypto_generichash( self::KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$forged = 'v2.' . sodium_bin2base64( $nonce . sodium_crypto_secretbox( '{"a":"b"}', $nonce, $plain ), SODIUM_BASE64_VARIANT_ORIGINAL );
		try {
			( new SecretBox( self::KEY ) )->decrypt( $forged );
			$this->fail( 'Expected an exception.' );
		} catch ( CryptoException $e ) {
			$this->assertNull( $e->errorCode() );
		}
		$stored = ( new SecretBox( self::KEY ) )->encrypt( array( 'a' => 'b' ) );
		$raw    = sodium_base642bin( substr( $stored, 3 ), SODIUM_BASE64_VARIANT_ORIGINAL );
		$this->assertFalse( sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $plain ) );
	}

	public function test_text_from_the_first_development_builds_can_still_be_read(): void {
		$legacy = sodium_crypto_generichash( self::KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$text   = 'v1.' . sodium_bin2base64( $nonce . sodium_crypto_secretbox( '{"api_key":"old-secret"}', $nonce, $legacy ), SODIUM_BASE64_VARIANT_ORIGINAL );
		$box    = new SecretBox( self::KEY );
		$this->assertSame( array( 'api_key' => 'old-secret' ), $box->decrypt( $text ) );
		$this->assertStringStartsWith( 'v2.', $box->encrypt( $box->decrypt( $text ) ), 'It is written again in the new format.' );
	}

	public function test_wrong_key_fails_with_a_clear_message_and_no_auth_code(): void {
		$stored = ( new SecretBox( self::KEY ) )->encrypt( array( 'api_key' => 'sk_live_123456789abcdef' ) );
		try {
			( new SecretBox( 'a-completely-different-key-98765432' ) )->decrypt( $stored );
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
		foreach ( array( 'v2.' . base64_encode( $raw ), 'v2.@@@not-base64@@@', 'v2.' . base64_encode( 'tiny' ), 'plain text', 'v3.' . substr( $stored, 3 ) ) as $bad ) {
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
		$c = new SecretBox( 'another-key-another-key-1234567890' );
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

	/**
	 * Four values of the shape WordPress generates.
	 *
	 * @return array<int,string>
	 */
	private static function wordpressKeys(): array {
		return array(
			str_repeat( 'a1', 32 ),
			str_repeat( 'b2', 32 ),
			str_repeat( 'c3', 32 ),
			str_repeat( 'd4', 32 ),
		);
	}

	public function test_the_wordpress_security_keys_give_a_key_when_there_is_no_constant(): void {
		$material = SecretBox::materialFromWordPressKeys( self::wordpressKeys() );
		$this->assertNotNull( $material );
		$this->assertSame( 64, strlen( (string) $material ) );
		$this->assertSame( $material, SecretBox::materialFromWordPressKeys( self::wordpressKeys() ), 'the same keys always give the same material' );
		$box = new SecretBox( $material, SecretBox::SOURCE_WORDPRESS );
		$this->assertTrue( $box->keyAvailable() );
		$this->assertSame( 'wordpress', $box->keySource() );
		$this->assertSame( array( 'api_key' => 'k' ), $box->decrypt( $box->encrypt( array( 'api_key' => 'k' ) ) ) );
	}

	public function test_changing_any_wordpress_security_key_changes_the_material(): void {
		$keys    = self::wordpressKeys();
		$changed = $keys;
		$changed[2] = str_repeat( 'e5', 32 );
		$this->assertNotSame( SecretBox::materialFromWordPressKeys( $keys ), SecretBox::materialFromWordPressKeys( $changed ) );
	}

	/**
	 * @return array<string,array{0:array<int,mixed>}>
	 */
	public static function unusable_wordpress_keys(): array {
		$good = self::wordpressKeys();
		return array(
			'none defined'            => array( array( null, null, null, null ) ),
			'one missing'             => array( array( $good[0], $good[1], null, $good[3] ) ),
			'too few values'          => array( array( $good[0], $good[1], $good[2] ) ),
			'one too short'           => array( array( $good[0], 'short', $good[2], $good[3] ) ),
			'one is not text'         => array( array( $good[0], 12345678901234567890123456789012, $good[2], $good[3] ) ),
			'the sample text'         => array( array( $good[0], 'put your unique phrase here', $good[2], $good[3] ) ),
			'the sample text, padded' => array( array( $good[0], 'put your unique phrase here, again and again', $good[2], $good[3] ) ),
			'all four the same'       => array( array( $good[0], $good[0], $good[0], $good[0] ) ),
		);
	}

	/**
	 * @dataProvider unusable_wordpress_keys
	 *
	 * @param array<int,mixed> $values The four values.
	 */
	public function test_wordpress_security_keys_that_are_missing_short_or_the_sample_are_not_used( array $values ): void {
		$this->assertNull( SecretBox::materialFromWordPressKeys( $values ) );
	}

	public function test_without_any_key_the_source_is_none(): void {
		$this->assertSame( 'none', ( new SecretBox( null ) )->keySource() );
		$this->assertSame( 'none', ( new SecretBox( 'too short' ) )->keySource() );
		$this->assertSame( 'constant', ( new SecretBox( self::KEY ) )->keySource() );
	}

	public function test_credentials_saved_with_the_wordpress_keys_stay_readable_after_a_constant_is_added(): void {
		$material = (string) SecretBox::materialFromWordPressKeys( self::wordpressKeys() );
		$before   = new SecretBox( $material, SecretBox::SOURCE_WORDPRESS );
		$stored   = $before->encrypt( array( 'api_key' => 'sk_live_1' ) );
		$print    = $before->fingerprint();

		$after = new SecretBox( self::KEY, SecretBox::SOURCE_CONSTANT, $material );
		$this->assertSame( 'constant', $after->keySource() );
		$this->assertSame( array( 'api_key' => 'sk_live_1' ), $after->decrypt( $stored ), 'read with the WordPress-derived key' );
		$this->assertTrue( $after->reads( $print ), 'the saved fingerprint is still accepted' );
		$this->assertTrue( $after->reads( $after->fingerprint() ) );

		// What is saved from now on uses the constant, and the WordPress keys alone cannot read it.
		$restored = $after->encrypt( array( 'api_key' => 'sk_live_2' ) );
		$this->assertSame( array( 'api_key' => 'sk_live_2' ), $after->decrypt( $restored ) );
		$this->expectException( CryptoException::class );
		$before->decrypt( $restored );
	}

	public function test_a_key_that_is_neither_the_main_key_nor_the_alternate_is_refused(): void {
		$material = (string) SecretBox::materialFromWordPressKeys( self::wordpressKeys() );
		$stranger = new SecretBox( 'another-key-another-key-another-key-123' );
		$stored   = $stranger->encrypt( array( 'a' => 'b' ) );
		$box      = new SecretBox( self::KEY, SecretBox::SOURCE_CONSTANT, $material );
		$this->assertFalse( $box->reads( $stranger->fingerprint() ) );
		try {
			$box->decrypt( $stored );
			$this->fail( 'A third key must not open the text.' );
		} catch ( CryptoException $e ) {
			$this->assertNull( $e->errorCode(), 'a changed key has no catalog code' );
		}
	}

	public function test_a_short_alternate_is_ignored(): void {
		$box = new SecretBox( self::KEY, SecretBox::SOURCE_CONSTANT, 'short' );
		$this->assertFalse( $box->reads( ( new SecretBox( 'short' ) )->fingerprint() ) );
		$this->assertTrue( $box->reads( $box->fingerprint() ) );
	}

	/**
	 * Define the four WordPress security constants in this process.
	 */
	private function defineWordPressKeys(): void {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $i => $name ) {
			define( $name, str_repeat( dechex( 10 + $i ) . '7', 32 ) );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constant_uses_the_wordpress_keys_when_the_site_defines_no_key_of_its_own(): void {
		$this->defineWordPressKeys();
		$box = SecretBox::fromConstant();
		$this->assertTrue( $box->keyAvailable() );
		$this->assertSame( 'wordpress', $box->keySource() );
		$this->assertSame( array( 'a' => 'b' ), $box->decrypt( $box->encrypt( array( 'a' => 'b' ) ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constant_has_no_key_when_there_is_no_constant_and_the_wordpress_keys_are_the_sample(): void {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $name ) {
			define( $name, 'put your unique phrase here' );
		}
		$box = SecretBox::fromConstant();
		$this->assertFalse( $box->keyAvailable() );
		$this->assertSame( 'none', $box->keySource() );
		$this->expectException( CryptoException::class );
		$box->encrypt( array( 'a' => 'b' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constant_has_no_key_when_nothing_is_defined(): void {
		$box = SecretBox::fromConstant();
		$this->assertFalse( $box->keyAvailable() );
		$this->assertFalse( $box->keyTooShort() );
		$this->assertSame( 'none', $box->keySource() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constant_prefers_the_constant_and_does_not_hide_a_short_one_behind_the_wordpress_keys(): void {
		$this->defineWordPressKeys();
		define( 'ESTASOFT_SYNC_KEY', 'too-short' );
		$box = SecretBox::fromConstant();
		$this->assertFalse( $box->keyAvailable(), 'a short constant is refused, not replaced' );
		$this->assertTrue( $box->keyTooShort() );
		$this->assertSame( 'none', $box->keySource() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constant_uses_the_constant_when_it_is_long_enough_and_still_reads_what_the_wordpress_keys_saved(): void {
		$this->defineWordPressKeys();
		define( 'ESTASOFT_SYNC_KEY', self::KEY );
		$box = SecretBox::fromConstant();
		$this->assertSame( 'constant', $box->keySource() );
		$wordpress = new SecretBox( (string) SecretBox::materialFromWordPressKeys( array( AUTH_KEY, SECURE_AUTH_KEY, AUTH_SALT, SECURE_AUTH_SALT ) ), SecretBox::SOURCE_WORDPRESS );
		$this->assertSame( array( 'x' => 'y' ), $box->decrypt( $wordpress->encrypt( array( 'x' => 'y' ) ) ) );
	}
}
