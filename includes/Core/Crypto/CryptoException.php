<?php
/**
 * Failure while encrypting or decrypting credentials.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Crypto;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The message is safe to show to a person: it never contains a key, a secret or a ciphertext.
 */
final class CryptoException extends \RuntimeException {

	/**
	 * Catalog code, or null when the failure has no catalog entry.
	 */
	private ?ErrorCode $errorCode;

	/**
	 * @param string         $message   Plain sentence for the person.
	 * @param ErrorCode|null $errorCode Catalog code, if any.
	 */
	private function __construct( string $message, ?ErrorCode $errorCode = null ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
	}

	/**
	 * The encryption key constant is not defined in wp-config.php (SYNC-CFG-002).
	 */
	public static function keyMissing(): self {
		return new self( ErrorCode::Cfg002->template(), ErrorCode::Cfg002 );
	}

	/**
	 * The encryption key constant is defined but shorter than the minimum (SYNC-CFG-002).
	 *
	 * @param int $minimum Shortest accepted length in characters.
	 */
	public static function keyTooShort( int $minimum ): self {
		return new self(
			sprintf(
				/* translators: %d: the shortest accepted length of the encryption key, in characters. */
				esc_html__( 'The encryption key in wp-config.php is too short. It needs at least %d characters. Replace it with a longer random value and reload.', 'estasoft-erp-crm-sync-engine' ),
				$minimum
			),
			ErrorCode::Cfg002
		);
	}

	/**
	 * Only some credentials were sent while the saved ones belong to another key. The rest would stay unreadable.
	 */
	public static function partialSaveRefused(): self {
		return new self( esc_html__( 'The encryption key was changed after these credentials were saved, so they cannot be read. Enter all the credentials again; saving only some of them would leave the others unreadable.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The saved credentials cannot be opened with the current key. The key was changed, or the data is damaged.
	 * This is not a refused sign-in, so it carries no AUTH code.
	 */
	public static function keyChanged(): self {
		return new self( esc_html__( 'The saved credentials cannot be read with the key in wp-config.php. The key may have been changed. Enter the credentials again.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The stored value is not in the format this version writes.
	 */
	public static function malformed(): self {
		return new self( esc_html__( 'The saved credentials are damaged. Enter the credentials again.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The catalog code for this failure, or null.
	 */
	public function errorCode(): ?ErrorCode {
		return $this->errorCode;
	}
}
