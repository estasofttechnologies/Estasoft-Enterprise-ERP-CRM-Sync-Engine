<?php
/**
 * The error of a credential that was never entered.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Builds the SYNC-AUTH-001 failure the strategies throw when a required secret is empty. Nothing was sent.
 */
final class MissingSecret {

	/**
	 * The error.
	 *
	 * @param string $name Name of the missing field (a field name, never a value).
	 */
	public static function error( string $name ): ConnectorError {
		return new ConnectorError( ErrorCode::Auth001, array(), null, null, null, 'missing credential ' . $name );
	}
}
