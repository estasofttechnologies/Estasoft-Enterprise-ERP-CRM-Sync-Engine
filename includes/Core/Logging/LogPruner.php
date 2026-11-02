<?php
/**
 * Deletes log entries past their retention time.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Logging;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Settings;

/**
 * Runs daily. Keeps 7, 14 or 30 days of entries, as set in Settings.
 */
final class LogPruner {

	/**
	 * @param LogRepository $logs     Storage.
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		private readonly LogRepository $logs,
		private readonly Settings $settings
	) {
	}

	/**
	 * Delete entries older than the retention time.
	 *
	 * @return int Rows deleted.
	 */
	public function run(): int {
		return $this->logs->prune( $this->settings->logRetentionDays() );
	}
}
