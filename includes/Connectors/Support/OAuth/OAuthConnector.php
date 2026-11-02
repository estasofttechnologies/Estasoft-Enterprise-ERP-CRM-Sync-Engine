<?php
/**
 * Marks a connector that signs in with OAuth 2.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * A connector that extends AbstractApiConnector and also implements this interface gets the sign-in routes
 * (`POST /connections/{id}/oauth/start`, the callback, `GET .../oauth/organisations`, `POST .../oauth/organisation`) and the scheduled
 * token refresh from the base. For the client-credentials and JWT styles the sign-in routes are not used; the interface still tells the
 * refresh task which provider to ask.
 */
interface OAuthConnector {

	/**
	 * The provider of the OAuth settings.
	 */
	public function oauthProvider(): OAuthProvider;

	/**
	 * The organisations (tenants, companies, realms) the signed-in person can choose between. The connection already holds its tokens.
	 * Throws ApiException on failure.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array;

	/**
	 * Remember the organisation the person chose.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         The id from organisations().
	 * @return array<string,mixed> Config keys to store on the connection (for example tenant_id, company name).
	 */
	public function selectOrganisation( Connection $connection, string $id ): array;
}
