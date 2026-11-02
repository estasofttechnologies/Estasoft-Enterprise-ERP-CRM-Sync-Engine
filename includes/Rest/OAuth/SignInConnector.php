<?php
/**
 * Optional contract for connectors that connect with a sign-in page instead of a key.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * A connector that connects through the system's own sign-in page (OAuth 2 with the merchant's own application) implements this
 * next to ConnectorInterface. The first release ships no such connector; this is the seam the REST routes use, so one can be
 * added without changing them. Key-style connectors (Odoo) do not implement it, and the sign-in routes answer SYNC-CFG-004.
 */
interface SignInConnector {

	/**
	 * The address of the system's sign-in page.
	 *
	 * @param Connection $connection    The connection being set up (client id and other non-secret fields are in its config).
	 * @param string     $state          One-time value to send along and get back unchanged.
	 * @param string     $redirectUri    The callback address of this site.
	 * @param string     $codeChallenge  PKCE challenge (S256) for providers that support it.
	 */
	public function authorizeUrl( Connection $connection, string $state, string $redirectUri, string $codeChallenge ): string;

	/**
	 * Trade the code from the callback for tokens and store them encrypted on the connection.
	 *
	 * @param Connection $connection   The connection with its secrets.
	 * @param string     $code         Code from the callback.
	 * @param string     $redirectUri  The callback address used in authorizeUrl().
	 * @param string     $codeVerifier PKCE verifier that belongs to the challenge.
	 * @return bool|ConnectorError True when the tokens were stored.
	 */
	public function completeSignIn( Connection $connection, string $code, string $redirectUri, string $codeVerifier ): bool|ConnectorError;

	/**
	 * The organisations the signed-in person can choose between.
	 *
	 * @param Connection $connection The connection with its tokens.
	 * @return array<int,array{id:string,name:string}>|ConnectorError
	 */
	public function organisations( Connection $connection ): array|ConnectorError;
}
