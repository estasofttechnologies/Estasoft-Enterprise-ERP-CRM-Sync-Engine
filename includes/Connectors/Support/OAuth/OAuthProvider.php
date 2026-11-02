<?php
/**
 * Source of the OAuth settings of a connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Usually the connector itself (`oauthProvider()` returns `$this`) or a small class next to it.
 */
interface OAuthProvider {

	/**
	 * The OAuth settings for a connection. Data-centre, tenant or account specific addresses come from `$connection->config()`.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig;
}
