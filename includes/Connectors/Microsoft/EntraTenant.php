<?php
/**
 * A Microsoft Entra tenant, checked before it becomes part of an address.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * The tenant id is pasted by the merchant and goes into the token address
 * (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`), so only a GUID or a domain name is accepted.
 */
final class EntraTenant {

	/**
	 * Host of the Microsoft identity platform (commercial cloud).
	 */
	public const LOGIN_HOST = 'login.microsoftonline.com';

	/**
	 * The tenant as it may be used in an address, or null when it is not a GUID or a domain name.
	 *
	 * @param string $tenant What the merchant typed.
	 */
	public static function normalise( string $tenant ): ?string {
		$tenant = strtolower( trim( $tenant ) );
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant ) ) {
			return $tenant;
		}
		if ( 1 === preg_match( '/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $tenant ) ) {
			return $tenant;
		}
		return null;
	}

	/**
	 * The token endpoint of a tenant (client credentials, v2.0).
	 *
	 * @param string $tenant A value returned by normalise().
	 */
	public static function tokenUrl( string $tenant ): string {
		return 'https://' . self::LOGIN_HOST . '/' . $tenant . '/oauth2/v2.0/token';
	}
}
