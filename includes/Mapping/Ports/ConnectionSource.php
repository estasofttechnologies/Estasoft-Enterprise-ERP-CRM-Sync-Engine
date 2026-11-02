<?php
/**
 * Where connections are loaded from.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

use Estasoft\SyncEngine\Domain\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * The part of Core\Repositories\ConnectionRepository that the mapping module uses.
 */
interface ConnectionSource {

	/**
	 * A connection with its decrypted secrets (used only to ask the connected system), or null.
	 *
	 * @param int $connectionId Connection id.
	 */
	public function find( int $connectionId ): ?Connection;
}
