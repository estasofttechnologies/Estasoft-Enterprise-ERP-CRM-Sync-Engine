<?php
/**
 * Signed assertions for the JWT bearer grant.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\OAuth\JwtAssertion;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class JwtAssertionTest extends TestCase {

	/**
	 * Make a key pair.
	 *
	 * @param string $type rsa or ec.
	 * @return array{0:string,1:\OpenSSLAsymmetricKey}
	 */
	private function keyPair( string $type = 'rsa' ): array {
		$options = 'ec' === $type ? array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) : array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 );
		$key     = openssl_pkey_new( $options );
		$this->assertNotFalse( $key );
		openssl_pkey_export( $key, $pem );
		return array( $pem, $key );
	}

	/**
	 * Split a token.
	 *
	 * @param string $jwt Token.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string}
	 */
	private function parts( string $jwt ): array {
		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );
		$decode = static fn( string $part ): string => (string) base64_decode( strtr( $part, '-_', '+/' ) ); // phpcs:ignore
		return array( json_decode( $decode( $parts[0] ), true ), json_decode( $decode( $parts[1] ), true ), $decode( $parts[2] ), $parts[0] . '.' . $parts[1] );
	}

	public function test_rs256_is_a_valid_pkcs1_signature_with_the_claims_given(): void {
		list( $pem, $key ) = $this->keyPair();
		$jwt = JwtAssertion::sign( array( 'iss' => 'client-1', 'sub' => 'user', 'aud' => 'https://login.example.test', 'exp' => 2000000000, 'scope' => array( 'a', 'b' ) ), $pem, array( 'kid' => 'key-7' ) );
		list( $header, $claims, $signature, $input ) = $this->parts( $jwt );

		$this->assertSame( array( 'kid' => 'key-7', 'alg' => 'RS256', 'typ' => 'JWT' ), $header );
		$this->assertSame( 'client-1', $claims['iss'] );
		$this->assertSame( array( 'a', 'b' ), $claims['scope'] );
		$details = openssl_pkey_get_details( $key );
		$this->assertSame( 1, openssl_verify( $input, $signature, $details['key'], OPENSSL_ALGO_SHA256 ) );
	}

	public function test_a_vendor_alg_in_the_extra_header_cannot_override_the_algorithm(): void {
		list( $pem ) = $this->keyPair();
		list( $header ) = $this->parts( JwtAssertion::sign( array( 'iss' => 'x' ), $pem, array( 'alg' => 'none' ) ) );
		$this->assertSame( 'RS256', $header['alg'] );
	}

	public function test_a_key_pasted_on_one_line_still_works(): void {
		list( $pem, $key ) = $this->keyPair();
		$oneLine = str_replace( array( "\n" ), array( '\n' ), trim( $pem ) );
		$jwt     = JwtAssertion::sign( array( 'iss' => 'x' ), $oneLine );
		list( , , $signature, $input ) = $this->parts( $jwt );
		$this->assertSame( 1, openssl_verify( $input, $signature, openssl_pkey_get_details( $key )['key'], OPENSSL_ALGO_SHA256 ) );
	}

	public function test_es256_signature_is_raw_r_and_s_of_64_bytes_and_verifies(): void {
		list( $pem, $key ) = $this->keyPair( 'ec' );
		for ( $i = 0; $i < 5; ++$i ) {
			$jwt = JwtAssertion::sign( array( 'iss' => 'client-' . $i ), $pem, array(), 'ES256' );
			list( $header, , $raw, $input ) = $this->parts( $jwt );
			$this->assertSame( 'ES256', $header['alg'] );
			$this->assertSame( 64, strlen( $raw ) );
			// Back to DER to verify with openssl.
			$r   = ltrim( substr( $raw, 0, 32 ), "\x00" );
			$s   = ltrim( substr( $raw, 32 ), "\x00" );
			$r   = ( ord( $r[0] ) > 127 ? "\x00" : '' ) . $r;
			$s   = ( ord( $s[0] ) > 127 ? "\x00" : '' ) . $s;
			$der = "\x02" . chr( strlen( $r ) ) . $r . "\x02" . chr( strlen( $s ) ) . $s;
			$der = "\x30" . chr( strlen( $der ) ) . $der;
			$this->assertSame( 1, openssl_verify( $input, $der, openssl_pkey_get_details( $key )['key'], OPENSSL_ALGO_SHA256 ) );
		}
	}

	public function test_ps256_is_a_valid_rsassa_pss_signature(): void {
		list( $pem, $key ) = $this->keyPair();
		$jwt = JwtAssertion::sign( array( 'iss' => 'client-1', 'aud' => 'https://x.example.test' ), $pem, array( 'kid' => 'k' ), 'PS256' );
		list( $header, , $signature, $input ) = $this->parts( $jwt );
		$this->assertSame( 'PS256', $header['alg'] );
		$this->assertSame( 256, strlen( $signature ) );

		// Verify: RSA public operation, then check the EMSA-PSS structure (RFC 8017 section 9.1.2).
		$em = '';
		$this->assertTrue( openssl_public_decrypt( $signature, $em, openssl_pkey_get_details( $key )['key'], OPENSSL_NO_PADDING ) );
		$emBits = 2047;
		$emLen  = 256;
		$em     = substr( $em, -$emLen );
		$this->assertSame( "\xbc", $em[ $emLen - 1 ] );
		$hLen   = 32;
		$masked = substr( $em, 0, $emLen - $hLen - 1 );
		$h      = substr( $em, $emLen - $hLen - 1, $hLen );
		$this->assertSame( 0, ord( $masked[0] ) & 0x80, 'The leftmost bit is cleared.' );
		$mask = '';
		for ( $c = 0; strlen( $mask ) < strlen( $masked ); ++$c ) {
			$mask .= hash( 'sha256', $h . pack( 'N', $c ), true );
		}
		$db       = $masked ^ substr( $mask, 0, strlen( $masked ) );
		$db[0]    = chr( ord( $db[0] ) & 0x7F );
		$this->assertSame( "\x01", $db[ strlen( $db ) - 33 ], 'Zeros, then 01, then the salt.' );
		$salt     = substr( $db, -32 );
		$expected = hash( 'sha256', str_repeat( "\x00", 8 ) . hash( 'sha256', $input, true ) . $salt, true );
		$this->assertSame( bin2hex( $expected ), bin2hex( $h ) );
		unset( $emBits );
	}

	public function test_an_unreadable_key_a_wrong_algorithm_and_a_mismatched_key_are_sign_in_refusals(): void {
		list( $rsa )      = $this->keyPair();
		list( $ec )       = $this->keyPair( 'ec' );
		$cases = array(
			'empty key'        => array( '', 'RS256' ),
			'garbage'          => array( 'not a key', 'RS256' ),
			'unknown alg'      => array( $rsa, 'HS256' ),
			'rsa key for ES'   => array( $rsa, 'ES256' ),
			'ec key for RS'    => array( $ec, 'RS256' ),
			'ec key for PS'    => array( $ec, 'PS256' ),
		);
		foreach ( $cases as $name => $case ) {
			try {
				JwtAssertion::sign( array( 'iss' => 'x' ), $case[0], array(), $case[1] );
				$this->fail( $name . ' should fail.' );
			} catch ( OAuthException $exception ) {
				$this->assertSame( ErrorCode::Auth001, $exception->error()->code, $name );
				$this->assertStringNotContainsString( 'PRIVATE', $exception->getMessage() );
			}
		}
	}
}
