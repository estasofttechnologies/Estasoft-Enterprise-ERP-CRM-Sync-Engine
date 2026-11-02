<?php
/**
 * Encryption of credentials with libsodium.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Crypto;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;

/**
 * `crypto_secretbox` (XSalsa20-Poly1305). The 32 byte key is derived with `sodium_crypto_generichash`
 * from the constant ESTASOFT_SYNC_KEY in wp-config.php. A random 24 byte nonce is prepended to every
 * ciphertext. The stored text is `v1.` plus base64 (sodium's constant-time encoder) of nonce and ciphertext. Key material and plaintext
 * are wiped with `sodium_memzero` as soon as they are no longer needed.
 */
final class SecretBox {

	/**
	 * Format marker of the stored text.
	 */
	private const PREFIX = 'v1.';

	/**
	 * Shortest key material accepted.
	 */
	private const MIN_KEY_LENGTH = 16;

	/**
	 * The key material, kept only for deriving the key.
	 */
	private string $material;

	/**
	 * @param string|null $keyMaterial The value of ESTASOFT_SYNC_KEY, or null/empty when it is not defined.
	 */
	public function __construct( ?string $keyMaterial ) {
		$this->material = (string) $keyMaterial;
	}

	/**
	 * Build from the constant in wp-config.php.
	 */
	public static function fromConstant(): self {
		return new self( defined( 'ESTASOFT_SYNC_KEY' ) ? (string) constant( 'ESTASOFT_SYNC_KEY' ) : null );
	}

	/**
	 * Whether a usable key is defined.
	 */
	public function keyAvailable(): bool {
		return strlen( $this->material ) >= self::MIN_KEY_LENGTH;
	}

	/**
	 * A short one-way code for the current key. Saved beside the ciphertext to tell "key changed" from "data damaged".
	 * It reveals nothing useful about the key.
	 *
	 * @return string 16 hex characters, or an empty string when no key is defined.
	 */
	public function fingerprint(): string {
		if ( ! $this->keyAvailable() ) {
			return '';
		}
		$key  = $this->deriveKey();
		$hash = substr( bin2hex( sodium_crypto_generichash( 'estasoft-sync-fingerprint' . $key, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN ) ), 0, 16 );
		sodium_memzero( $key );
		return $hash;
	}

	/**
	 * Encrypt a list of secrets.
	 *
	 * @param array<string,string> $secrets Secret name to value.
	 * @return string Stored text; an empty string when there is nothing to encrypt.
	 *
	 * @throws CryptoException With SYNC-CFG-002 when the key is missing.
	 */
	public function encrypt( array $secrets ): string {
		if ( array() === $secrets ) {
			return '';
		}
		if ( ! $this->keyAvailable() ) {
			throw CryptoException::keyMissing();
		}
		$key       = $this->deriveKey();
		$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext = Json::encode( $secrets );
		$cipher    = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		sodium_memzero( $plaintext );
		sodium_memzero( $key );
		return self::PREFIX . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Decrypt stored text.
	 *
	 * @param string $stored Text from encrypt().
	 * @return array<string,string> Secret name to value; empty for an empty input.
	 *
	 * @throws CryptoException With SYNC-CFG-002 when the key is missing; without a code when the key changed or the text is damaged.
	 */
	public function decrypt( string $stored ): array {
		if ( '' === $stored ) {
			return array();
		}
		if ( ! $this->keyAvailable() ) {
			throw CryptoException::keyMissing();
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			throw CryptoException::malformed();
		}
		try {
			$raw = sodium_base642bin( substr( $stored, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			unset( $e );
			throw CryptoException::malformed();
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw CryptoException::malformed();
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = $this->deriveKey();
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		sodium_memzero( $key );
		if ( false === $plain ) {
			throw CryptoException::keyChanged();
		}
		$decoded = json_decode( $plain, true );
		sodium_memzero( $plain );
		if ( ! is_array( $decoded ) ) {
			throw CryptoException::malformed();
		}
		$secrets = array();
		foreach ( $decoded as $name => $value ) {
			$secrets[ (string) $name ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $secrets;
	}

	/**
	 * A ready line to paste into wp-config.php, with a new random key.
	 *
	 * @return string For example: define( 'ESTASOFT_SYNC_KEY', '9f2c...' );
	 */
	public function generateKeyLine(): string {
		return "define( 'ESTASOFT_SYNC_KEY', '" . bin2hex( random_bytes( 32 ) ) . "' );";
	}

	/**
	 * Derive the 32 byte secretbox key from the key material.
	 */
	private function deriveKey(): string {
		return sodium_crypto_generichash( $this->material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
