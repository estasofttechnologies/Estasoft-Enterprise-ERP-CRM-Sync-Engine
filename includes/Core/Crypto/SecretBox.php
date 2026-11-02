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
 * `crypto_secretbox` (XSalsa20-Poly1305). The 32 byte key is derived with `sodium_crypto_generichash` (BLAKE2b)
 * from the constant ESTASOFT_SYNC_KEY in wp-config.php, with a fixed context string in front of the key material so that
 * this key can never equal a key another feature derives from the same constant. A random 24 byte nonce is prepended to
 * every ciphertext. The stored text is `v2.` plus base64 (sodium's constant-time encoder) of nonce and ciphertext. Key
 * material and plaintext are wiped with `sodium_memzero` as soon as they are no longer needed.
 *
 * Why there is no work-factor KDF (Argon2, scrypt, PBKDF2): those exist to slow down guessing of a password a person
 * chose. This key is not a password. The plugin generates it (see generateKeyLine(): 256 random bits) and it lives in
 * wp-config.php, readable only by someone who already has the server. The attacker model is a stolen database dump with
 * no wp-config.php; against that, the strength comes from the entropy of the key, not from the cost of deriving the
 * cipher key. The 32 character minimum is the guard for that: a short or guessable key is refused with SYNC-CFG-002 rather
 * than stretched. A hash that is fast is also right here because the key is derived on every request that reads a
 * secret.
 *
 * Text written by the first development builds (`v1.`) was keyed without the context string. It can still be read, and
 * is written again as `v2.` the next time the credentials are saved.
 */
final class SecretBox {

	/**
	 * Format marker of the stored text written now.
	 */
	private const PREFIX = 'v2.';

	/**
	 * Format marker of text written by the first development builds. Read only.
	 */
	private const PREFIX_V1 = 'v1.';

	/**
	 * Context string put in front of the key material when deriving the secretbox key.
	 */
	private const CONTEXT = 'estasoft-sync-engine/secretbox/v2';

	/**
	 * Shortest key material accepted, in characters. 32 random hexadecimal characters carry 128 bits.
	 */
	public const MIN_KEY_LENGTH = 32;

	/**
	 * The key material, kept only for deriving the key.
	 *
	 * @var string
	 */
	private string $material;

	/**
	 * Set up the secret box.
	 *
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
	 * Whether a key is defined but shorter than the minimum. Used to give a clearer message than "missing".
	 */
	public function keyTooShort(): bool {
		return '' !== $this->material && strlen( $this->material ) < self::MIN_KEY_LENGTH;
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
		$key  = $this->deriveKey( self::PREFIX_V1 );
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
	 * @throws CryptoException With SYNC-CFG-002 when the key is missing or shorter than 32 characters.
	 */
	public function encrypt( array $secrets ): string {
		if ( array() === $secrets ) {
			return '';
		}
		$this->requireKey();
		$key       = $this->deriveKey( self::PREFIX );
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
	 * @throws CryptoException With SYNC-CFG-002 when the key is missing or too short; without a code when the key changed or the text is damaged.
	 */
	public function decrypt( string $stored ): array {
		if ( '' === $stored ) {
			return array();
		}
		$this->requireKey();
		if ( str_starts_with( $stored, self::PREFIX ) ) {
			$prefix = self::PREFIX;
		} elseif ( str_starts_with( $stored, self::PREFIX_V1 ) ) {
			$prefix = self::PREFIX_V1;
		} else {
			throw CryptoException::malformed();
		}
		try {
			$raw = sodium_base642bin( substr( $stored, strlen( $prefix ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			unset( $e );
			throw CryptoException::malformed();
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw CryptoException::malformed();
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = $this->deriveKey( $prefix );
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
	 * Refuse a missing or too short key.
	 *
	 * @throws CryptoException With SYNC-CFG-002.
	 */
	private function requireKey(): void {
		if ( $this->keyAvailable() ) {
			return;
		}
		throw $this->keyTooShort() ? CryptoException::keyTooShort( self::MIN_KEY_LENGTH ) : CryptoException::keyMissing();
	}

	/**
	 * Derive the 32 byte secretbox key from the key material.
	 *
	 * @param string $format The format marker of the text the key is for; the first development builds (`v1.`) did not
	 *                       use the context string.
	 */
	private function deriveKey( string $format ): string {
		if ( self::PREFIX_V1 === $format ) {
			return sodium_crypto_generichash( $this->material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return sodium_crypto_generichash( self::CONTEXT . "\0" . $this->material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
