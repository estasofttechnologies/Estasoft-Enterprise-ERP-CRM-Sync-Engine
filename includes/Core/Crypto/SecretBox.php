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
 * from key material, with a fixed context string in front of it so that this key can never equal a key another feature
 * derives from the same material. The material is the constant ESTASOFT_SYNC_KEY in wp-config.php when the site owner
 * defined one. When no such constant exists, it is derived from the WordPress security keys and salts of the same file
 * (AUTH_KEY, SECURE_AUTH_KEY, AUTH_SALT and SECURE_AUTH_SALT), so the plugin works at once and nobody has to edit
 * wp-config.php. Both live in the same place, so the protection against a stolen database dump is the same; the constant
 * only adds that changing the WordPress security keys (a common way to log everybody out) leaves the saved credentials
 * readable. A site that defines the constant after it has saved credentials keeps them: they are read with the
 * WordPress-derived key too, and written with the constant from then on. A random 24 byte nonce is prepended to every
 * ciphertext. The stored text is `v2.` plus base64 (sodium's constant-time encoder) of nonce and ciphertext. Key
 * material and plaintext are wiped with `sodium_memzero` as soon as they are no longer needed.
 *
 * Why there is no work-factor KDF (Argon2, scrypt, PBKDF2): those exist to slow down guessing of a password a person
 * chose. This key is not a password. The plugin generates the constant (see generateKeyLine(): 256 random bits), WordPress
 * generates its security keys, and both live in wp-config.php, readable only by someone who already has the server. The attacker model is a stolen database dump with
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
	 * The key material came from the constant ESTASOFT_SYNC_KEY.
	 */
	public const SOURCE_CONSTANT = 'constant';

	/**
	 * The key material was derived from the WordPress security keys and salts.
	 */
	public const SOURCE_WORDPRESS = 'wordpress';

	/**
	 * Placeholder text of a wp-config.php sample that was never filled in.
	 */
	private const SAMPLE_KEY = 'put your unique phrase here';

	/**
	 * The key material, kept only for deriving the key.
	 *
	 * @var string
	 */
	private string $material;

	/**
	 * Where the key material came from: SOURCE_CONSTANT or SOURCE_WORDPRESS.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Material derived from the WordPress security keys while the constant is the main key, so credentials saved before the
	 * constant was added can still be read. Empty when there is none.
	 *
	 * @var string
	 */
	private string $alternate;

	/**
	 * Set up the secret box.
	 *
	 * @param string|null $keyMaterial       The key material, or null/empty when there is none.
	 * @param string      $source            SOURCE_CONSTANT or SOURCE_WORDPRESS.
	 * @param string|null $alternateMaterial Another key whose credentials can still be read (see the class description).
	 */
	public function __construct( ?string $keyMaterial, string $source = self::SOURCE_CONSTANT, ?string $alternateMaterial = null ) {
		$this->material  = (string) $keyMaterial;
		$this->source    = self::SOURCE_WORDPRESS === $source ? self::SOURCE_WORDPRESS : self::SOURCE_CONSTANT;
		$this->alternate = strlen( (string) $alternateMaterial ) >= self::MIN_KEY_LENGTH ? (string) $alternateMaterial : '';
	}

	/**
	 * Build from wp-config.php: the constant ESTASOFT_SYNC_KEY when it is defined, otherwise the WordPress security keys.
	 *
	 * A constant that is defined but too short is not replaced by the WordPress keys: the owner tried to set one, and the
	 * message that says it is too short is more useful than silently ignoring it.
	 */
	public static function fromConstant(): self {
		$constant  = defined( 'ESTASOFT_SYNC_KEY' ) ? (string) constant( 'ESTASOFT_SYNC_KEY' ) : '';
		$wordpress = self::materialFromWordPressKeys(
			array(
				defined( 'AUTH_KEY' ) ? constant( 'AUTH_KEY' ) : null,
				defined( 'SECURE_AUTH_KEY' ) ? constant( 'SECURE_AUTH_KEY' ) : null,
				defined( 'AUTH_SALT' ) ? constant( 'AUTH_SALT' ) : null,
				defined( 'SECURE_AUTH_SALT' ) ? constant( 'SECURE_AUTH_SALT' ) : null,
			)
		);
		if ( '' === $constant ) {
			return null === $wordpress ? new self( null ) : new self( $wordpress, self::SOURCE_WORDPRESS );
		}
		return new self( $constant, self::SOURCE_CONSTANT, $wordpress );
	}

	/**
	 * Key material from the four WordPress security values, or null when any of them is missing, short or still the sample text.
	 *
	 * WordPress generates 64 random characters for each; the sample file has "put your unique phrase here". Without real
	 * values WordPress keeps its own keys in the database, which is no protection against a copy of the database, so those
	 * are not used.
	 *
	 * @param array<int,mixed> $values AUTH_KEY, SECURE_AUTH_KEY, AUTH_SALT and SECURE_AUTH_SALT, in that order.
	 */
	public static function materialFromWordPressKeys( array $values ): ?string {
		if ( 4 !== count( $values ) ) {
			return null;
		}
		$parts = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) < self::MIN_KEY_LENGTH || false !== stripos( $value, self::SAMPLE_KEY ) ) {
				return null;
			}
			$parts[] = $value;
		}
		if ( 1 === count( array_unique( $parts ) ) ) {
			return null;
		}
		return hash_hmac( 'sha256', implode( "\n", $parts ), 'estasoft-sync-engine/wordpress-security-keys/v1' );
	}

	/**
	 * Where the key in use comes from: `constant`, `wordpress`, or `none` when there is no usable key.
	 */
	public function keySource(): string {
		return $this->keyAvailable() ? $this->source : 'none';
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
		return $this->keyAvailable() ? $this->fingerprintOf( $this->material ) : '';
	}

	/**
	 * Whether credentials saved with this fingerprint can be read: they were written with the key in use, or with the
	 * WordPress-derived key that stays readable beside a constant added later.
	 *
	 * @param string $stored Fingerprint saved beside the ciphertext.
	 */
	public function reads( string $stored ): bool {
		if ( ! $this->keyAvailable() || '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $this->fingerprint() ) || ( '' !== $this->alternate && hash_equals( $stored, $this->fingerprintOf( $this->alternate ) ) );
	}

	/**
	 * Fingerprint of some key material.
	 *
	 * @param string $material Key material.
	 */
	private function fingerprintOf( string $material ): string {
		$key  = $this->deriveKeyFrom( $material, self::PREFIX_V1 );
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
		if ( false === $plain && '' !== $this->alternate ) {
			// Saved before the constant was added: the WordPress-derived key still opens it.
			$key   = $this->deriveKeyFrom( $this->alternate, $prefix );
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			sodium_memzero( $key );
		}
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
		return $this->deriveKeyFrom( $this->material, $format );
	}

	/**
	 * Derive the cipher key from some key material.
	 *
	 * @param string $material Key material.
	 * @param string $format   Format marker, as for deriveKey().
	 */
	private function deriveKeyFrom( string $material, string $format ): string {
		if ( self::PREFIX_V1 === $format ) {
			return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return sodium_crypto_generichash( self::CONTEXT . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
