<?php
/**
 * Builds the payload for a queue item at send time.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Sync;

use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by the mapping module. The queue calls it for every claimed item, so the latest
 * order data and the live mapping are always used (QUE-2).
 */
interface PayloadBuilderInterface {

	/**
	 * Read the entity through the WooCommerce CRUD layer and apply the live mapping.
	 *
	 * @param Item       $item       Claimed item.
	 * @param Connection $connection Its connection.
	 */
	public function build( Item $item, Connection $connection ): PayloadResult;
}
