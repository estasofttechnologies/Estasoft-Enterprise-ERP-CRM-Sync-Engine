<?php
/**
 * Builds a signed JWT for the JWT bearer grant.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Signs `header.payload` with openssl from the PEM private key the merchant pasted. Claims are generic (iss, sub, aud, exp, iat, jti and
 * whatever the vendor adds). Algorithms: RS256 (RSASSA-PKCS1-v1_5, Salesforce), PS256/PS384/PS512 (RSASSA-PSS, NetSuite) and ES256/ES384/ES512
 * (ECDSA, NetSuite). PHP's openssl_sign has no PSS padding, so PSS is encoded here (RFC 8017 section 9.1.1) and signed with the raw RSA operation.
 */
final class JwtAssertion {

	/**
	 * Algorithms and their hash, family and (for ECDSA) signature part length.
	 *
	 * @var array<string,array{hash:string,family:string,part:int}>
	 */
	private const ALGORITHMS = array(
		'RS256' => array(
			'hash'   => 'sha256',
			'family' => 'rsa',
			'part'   => 0,
		),
		'RS384' => array(
			'hash'   => 'sha384',
			'family' => 'rsa',
			'part'   => 0,
		),
		'RS512' => array(
			'hash'   => 'sha512',
			'family' => 'rsa',
			'part'   => 0,
		),
		'PS256' => array(
			'hash'   => 'sha256',
			'family' => 'pss',
			'part'   => 0,
		),
		'PS384' => array(
			'hash'   => 'sha384',
			'family' => 'pss',
			'part'   => 0,
		),
		'PS512' => array(
			'hash'   => 'sha512',
			'family' => 'pss',
			'part'   => 0,
		),
		'ES256' => array(
			'hash'   => 'sha256',
			'family' => 'ec',
			'part'   => 32,
		),
		'ES384' => array(
			'hash'   => 'sha384',
			'family' => 'ec',
			'part'   => 48,
		),
		'ES512' => array(
			'hash'   => 'sha512',
			'family' => 'ec',
			'part'   => 66,
		),
	);

	/**
	 * Build and sign a token.
	 *
	 * @param array<string,mixed> $claims        Claims.
	 * @param string              $privateKeyPem PEM text of the private key.
	 * @param array<string,mixed> $headerExtra   More header fields (`kid`). `alg` and `typ` cannot be changed here.
	 * @param string              $algorithm     RS256 (default), RS384, RS512, PS256, PS384, PS512, ES256, ES384 or ES512.
	 * @return string The compact JWT.
	 *
	 * @throws OAuthException With SYNC-AUTH-001 when the key cannot be read, does not fit the algorithm, or signing fails.
	 */
	public static function sign( array $claims, string $privateKeyPem, array $headerExtra = array(), string $algorithm = 'RS256' ): string {
		$spec = self::ALGORITHMS[ $algorithm ] ?? null;
		$key  = '' === trim( $privateKeyPem ) || null === $spec ? false : openssl_pkey_get_private( self::normalise( $privateKeyPem ) );
		if ( false === $key || null === $spec ) {
			throw self::failure( null === $spec ? 'unsupported algorithm' : 'private key unreadable' );
		}
		$details = openssl_pkey_get_details( $key );
		$type    = is_array( $details ) ? (int) $details['type'] : -1;
		if ( ( 'ec' === $spec['family'] ) !== ( OPENSSL_KEYTYPE_EC === $type ) || ( 'ec' !== $spec['family'] && OPENSSL_KEYTYPE_RSA !== $type ) ) {
			throw self::failure( 'the key does not fit the algorithm' );
		}
		$header = array_merge(
			$headerExtra,
			array(
				'alg' => $algorithm,
				'typ' => 'JWT',
			)
		);
		$input  = self::encode( (string) wp_json_encode( $header ) ) . '.' . self::encode( (string) wp_json_encode( $claims ) );

		$signature = match ( $spec['family'] ) {
			'pss'   => self::signPss( $input, $key, (int) ( $details['bits'] ?? 0 ), $spec['hash'] ),
			'ec'    => self::signEc( $input, $key, $spec['hash'], $spec['part'] ),
			default => self::signRsa( $input, $key, $spec['hash'] ),
		};
		return $input . '.' . self::encode( $signature );
	}

	/**
	 * RSASSA-PKCS1-v1_5.
	 *
	 * @param string                $input Signing input.
	 * @param \OpenSSLAsymmetricKey $key   Key.
	 * @param string                $hash  Hash name.
	 *
	 * @throws OAuthException With SYNC-AUTH-001 when signing fails.
	 */
	private static function signRsa( string $input, \OpenSSLAsymmetricKey $key, string $hash ): string {
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $key, $hash ) ) {
			throw self::failure( 'signing failed' );
		}
		return $signature;
	}

	/**
	 * ECDSA with the signature as raw R and S, each padded to the curve size.
	 *
	 * @param string                $input Signing input.
	 * @param \OpenSSLAsymmetricKey $key   Key.
	 * @param string                $hash  Hash name.
	 * @param int                   $part  Bytes of R and of S.
	 *
	 * @throws OAuthException With SYNC-AUTH-001 when signing fails.
	 */
	private static function signEc( string $input, \OpenSSLAsymmetricKey $key, string $hash, int $part ): string {
		$der = '';
		if ( ! openssl_sign( $input, $der, $key, $hash ) ) {
			throw self::failure( 'signing failed' );
		}
		// DER: 30 len 02 rlen r 02 slen s (len may use the long form 81 xx).
		$offset = 2 + ( ord( $der[1] ) > 127 ? ord( $der[1] ) - 128 : 0 );
		if ( ! isset( $der[ $offset + 1 ] ) || 2 !== ord( $der[ $offset ] ) ) {
			throw self::failure( 'unexpected signature format' );
		}
		$rLength = ord( $der[ $offset + 1 ] );
		$r       = substr( $der, $offset + 2, $rLength );
		$offset += 2 + $rLength;
		if ( ! isset( $der[ $offset + 1 ] ) || 2 !== ord( $der[ $offset ] ) ) {
			throw self::failure( 'unexpected signature format' );
		}
		$s = substr( $der, $offset + 2, ord( $der[ $offset + 1 ] ) );
		$r = str_pad( ltrim( $r, "\x00" ), $part, "\x00", STR_PAD_LEFT );
		$s = str_pad( ltrim( $s, "\x00" ), $part, "\x00", STR_PAD_LEFT );
		return $r . $s;
	}

	/**
	 * RSASSA-PSS with MGF1 and a salt as long as the hash (RFC 8017 section 8.1.1 and 9.1.1).
	 *
	 * @param string                $input   Signing input.
	 * @param \OpenSSLAsymmetricKey $key     Key.
	 * @param int                   $modBits Bits of the RSA modulus.
	 * @param string                $hash    Hash name.
	 *
	 * @throws OAuthException With SYNC-AUTH-001 when signing fails.
	 */
	private static function signPss( string $input, \OpenSSLAsymmetricKey $key, int $modBits, string $hash ): string {
		$hLen   = strlen( hash( $hash, '', true ) );
		$sLen   = $hLen;
		$emBits = $modBits - 1;
		$emLen  = (int) ceil( $emBits / 8 );
		if ( $emLen < $hLen + $sLen + 2 ) {
			throw self::failure( 'the key is too small for this algorithm' );
		}
		$salt      = random_bytes( $sLen );
		$digest    = hash( $hash, "\x00\x00\x00\x00\x00\x00\x00\x00" . hash( $hash, $input, true ) . $salt, true );
		$db        = str_repeat( "\x00", $emLen - $sLen - $hLen - 2 ) . "\x01" . $salt;
		$masked    = $db ^ self::mgf1( $digest, $emLen - $hLen - 1, $hash );
		$clear     = 8 * $emLen - $emBits;
		$masked[0] = chr( ord( $masked[0] ) & ( 0xFF >> $clear ) );
		$em        = str_pad( $masked . $digest . "\xbc", (int) ceil( $modBits / 8 ), "\x00", STR_PAD_LEFT );
		$signature = '';
		if ( ! openssl_private_encrypt( $em, $signature, $key, OPENSSL_NO_PADDING ) ) {
			throw self::failure( 'signing failed' );
		}
		return $signature;
	}

	/**
	 * The MGF1 mask generation function.
	 *
	 * @param string $seed   Seed.
	 * @param int    $length Bytes wanted.
	 * @param string $hash   Hash name.
	 */
	private static function mgf1( string $seed, int $length, string $hash ): string {
		$mask  = '';
		$block = 0;
		$size  = strlen( hash( $hash, '', true ) );
		while ( $block * $size < $length ) {
			$mask .= hash( $hash, $seed . pack( 'N', $block ), true );
			++$block;
		}
		return substr( $mask, 0, $length );
	}

	/**
	 * The failure the token manager reports: the key or the signing is wrong.
	 *
	 * @param string $detail Short reason for the log.
	 */
	private static function failure( string $detail ): OAuthException {
		return new OAuthException( new ConnectorError( ErrorCode::Auth001, array( 'system' => '' ), null, null, null, $detail ) );
	}

	/**
	 * Restore the line breaks of a key pasted on one line.
	 *
	 * @param string $pem PEM text.
	 */
	private static function normalise( string $pem ): string {
		$pem = trim( str_replace( array( "\r\n", "\r", '\n' ), "\n", $pem ) );
		if ( 1 === preg_match( '/^(-----BEGIN [A-Z ]+-----)\s*(.+?)\s*(-----END [A-Z ]+-----)$/s', $pem, $m ) && false === strpos( $m[2], "\n" ) ) {
			$pem = $m[1] . "\n" . chunk_split( str_replace( ' ', '', $m[2] ), 64, "\n" ) . $m[3];
		}
		return $pem . "\n";
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $binary Bytes.
	 */
	private static function encode( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes a signature for a token, not code.
	}
}
