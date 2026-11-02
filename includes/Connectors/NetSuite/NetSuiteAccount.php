<?php
/**
 * The account id of a NetSuite connection and the host it stands for.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * The host of an account is `<account id>.suitetalk.api.netsuite.com` with the id in lower case and underscores written as hyphens
 * (a sandbox such as 1234567_SB1 is 1234567-sb1). Only letters, digits, hyphens and underscores are accepted, so the id cannot name another host.
 */
final class NetSuiteAccount {

	/**
	 * The first label of the host, or an empty string when the account id is not usable.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function label( Connection $connection ): string {
		$id = trim( (string) $connection->config( 'account_id', '' ) );
		return 1 === preg_match( '/^[A-Za-z0-9_-]{3,40}$/', $id ) ? strtolower( str_replace( '_', '-', $id ) ) : '';
	}

	/**
	 * The full host name, or an empty string.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function host( Connection $connection ): string {
		$label = self::label( $connection );
		return '' === $label ? '' : $label . '.suitetalk.api.netsuite.com';
	}
}
