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
