<?php
/**
 * What the export service tells the queue.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the export service and the queue repository.
 */
interface ExportQueue {

	/**
	 * The order is in a file: keep the item as synced and show the file name as its reference.
	 *
	 * @param int    $itemId   Queue item id.
	 * @param string $fileId   Export file id.
	 * @param string $filename File name.
	 */
	public function markExported( int $itemId, string $fileId, string $filename ): void;

	/**
	 * The order was left out of the file: the item needs a person (SYNC-EXP-002).
	 *
	 * @param int    $itemId  Queue item id.
	 * @param string $message Message with the code's placeholders filled in.
	 * @param string $cause   Short reason.
	 */
	public function markLeftOut( int $itemId, string $message, string $cause ): void;
}
