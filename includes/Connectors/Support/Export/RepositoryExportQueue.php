<?php
/**
 * ExportQueue over the queue repository.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Updates the rows of the items that were in a batch. The rows are already synced (send() reported them created); this changes the reference
 * to the file name, or fails the items that were left out so they appear under Needs attention.
 */
final class RepositoryExportQueue implements ExportQueue {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QueueRepository $queue Queue.
	 */
	public function __construct( private readonly QueueRepository $queue ) {
	}

	/**
	 * Show the file name as the reference of an exported order.
	 *
	 * @param int    $itemId   Queue item id.
	 * @param string $fileId   Export file id.
	 * @param string $filename File name.
	 */
	public function markExported( int $itemId, string $fileId, string $filename ): void {
		$this->queue->markSynced( $itemId, $fileId, $filename, null, null, null, false );
	}

	/**
	 * Fail an item that was left out of the file.
	 *
	 * @param int    $itemId  Queue item id.
	 * @param string $message Message.
	 * @param string $cause   Short reason.
	 */
	public function markLeftOut( int $itemId, string $message, string $cause ): void {
		$this->queue->markFailed( $itemId, ErrorCode::Exp002->value, $message, $cause, false );
	}
}
