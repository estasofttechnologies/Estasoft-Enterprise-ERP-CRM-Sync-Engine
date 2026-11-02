<?php
/**
 * An error raised inside the fake Odoo server.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Carries the Python exception name and the HTTP status the JSON-2 API would use for it.
 */
final class FakeOdooFault extends \RuntimeException {

	/**
	 * @param string $faultName  Python exception name, for example odoo.exceptions.AccessError.
	 * @param string $faultText  Message.
	 * @param int    $httpStatus Status used by the JSON-2 API.
	 */
	public function __construct( public readonly string $faultName, public readonly string $faultText, public readonly int $httpStatus ) {
		parent::__construct( $faultText );
	}

	/**
	 * A rights error (403 in JSON-2).
	 *
	 * @param string $text Message.
	 */
	public static function access( string $text ): self {
		return new self( 'odoo.exceptions.AccessError', $text, 403 );
	}

	/**
	 * A refused value (422 in JSON-2).
	 *
	 * @param string $text Message.
	 */
	public static function validation( string $text ): self {
		return new self( 'odoo.exceptions.ValidationError', $text, 422 );
	}

	/**
	 * A user error (422 in JSON-2).
	 *
	 * @param string $text Message.
	 */
	public static function user( string $text ): self {
		return new self( 'odoo.exceptions.UserError', $text, 422 );
	}

	/**
	 * A programming error such as an unknown field (500 in JSON-2).
	 *
	 * @param string $text Message.
	 */
	public static function invalidField( string $text ): self {
		return new self( 'builtins.ValueError', $text, 500 );
	}
}
