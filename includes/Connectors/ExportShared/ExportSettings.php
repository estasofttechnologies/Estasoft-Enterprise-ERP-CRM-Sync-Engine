<?php
/**
 * What a ledger export connector needs from its settings.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by TallySettings, DatevSettings and Sage50Settings.
 */
interface ExportSettings {

	/**
	 * Currency of the books (ISO code). Documents in another currency are left out.
	 */
	public function currency(): string;

	/**
	 * What must be fixed before any file can be made. Empty when the settings are complete.
	 *
	 * @return array<int,string> Translated sentences.
	 */
	public function problems(): array;

	/**
	 * The settings a file needs later, copied into each order's row so the file can be built without the connection.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array;
}
