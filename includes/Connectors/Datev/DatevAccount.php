<?php
/**
 * DATEV account numbers and tax codes of the form "account" or "account:BU key".
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

/**
 * An account in a DATEV file is a number of at most as many digits as the Sachkontenlänge (G/L accounts), or exactly one digit
 * more (personal accounts: debtors and creditors). The tax mapping holds, for every tax rate, the revenue account and, when the
 * account is not an automatic account, the BU key (Buchungsschlüssel) the tax adviser names.
 */
final class DatevAccount {

	/**
	 * Read a tax code.
	 *
	 * @param string $code   "8400" or "8200:3".
	 * @param int    $length Account length (4 to 8).
	 * @return array{account:string,bu:string}|null Null when the code is not an account.
	 */
	public static function parse( string $code, int $length ): ?array {
		if ( 1 !== preg_match( '/^(\d{1,9})(?::(\d{1,4}))?$/', trim( $code ), $found ) ) {
			return null;
		}
		if ( ! self::isGeneral( $found[1], $length ) ) {
			return null;
		}
		return array(
			'account' => ltrim( $found[1], '0' ),
			'bu'      => $found[2] ?? '',
		);
	}

	/**
	 * Whether a text is a G/L account for this account length.
	 *
	 * @param string $account Digits.
	 * @param int    $length  Account length.
	 */
	public static function isGeneral( string $account, int $length ): bool {
		$digits = ltrim( $account, '0' );
		return 1 === preg_match( '/^\d+$/', $account ) && '' !== $digits && strlen( $digits ) <= $length;
	}

	/**
	 * Whether a text is a personal account (debtor or creditor) for this account length.
	 *
	 * @param string $account Digits.
	 * @param int    $length  Account length.
	 */
	public static function isPersonal( string $account, int $length ): bool {
		return 1 === preg_match( '/^[1-9]\d*$/', $account ) && strlen( $account ) === $length + 1;
	}
}
