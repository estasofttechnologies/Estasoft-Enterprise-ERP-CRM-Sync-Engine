<?php
/**
 * The address the vendor sends the person back to after sign-in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Vendors want the redirect address registered in the app BEFORE the connection exists, so the plugin has one stable address for the
 * whole site: `rest_url( 'estasoft-sync/v1/oauth/callback' )`. The state value (kept ten minutes, bound to the person and the connection)
 * says which connection the answer belongs to. The older address with the connection id in it keeps working for apps registered with it.
 */
final class OAuthRedirect {

	/**
	 * REST route (under the plugin's namespace) of the stable callback.
	 */
	public const ROUTE = '/oauth/callback';

	/**
	 * The stable redirect address of this site. Register it in the vendor's app settings.
	 */
	public static function uri(): string {
		return rest_url( 'estasoft-sync/v1' . self::ROUTE );
	}

	/**
	 * The redirect address that carries the connection id (kept so an app registered with it still works).
	 *
	 * @param int $connectionId Connection id.
	 */
	public static function uriFor( int $connectionId ): string {
		return rest_url( 'estasoft-sync/v1/connections/' . $connectionId . '/oauth/callback' );
	}
}
