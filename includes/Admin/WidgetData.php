<?php
/**
 * The numbers for the dashboard box.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Starts from the dashboard service's small `widget()` array (connected, mode, synced_today, waiting, failed) and adds only what the
 * current mode shows: the connection card in the normal and paused modes (the system's name and the times), and the shadow figures in
 * shadow mode. So the WordPress dashboard stays cheap when there is nothing to say.
 */
final class WidgetData {

	/**
	 * Create the widget data source.
	 *
	 * @param \Closure $dashboard   Returns the dashboard service (`widget()` and `status()`).
	 * @param \Closure $connections Returns the connection service (`summaries()`).
	 *
	 * @phpstan-param \Closure():object $dashboard
	 * @phpstan-param \Closure():object $connections
	 */
	public function __construct(
		private readonly \Closure $dashboard,
		private readonly \Closure $connections
	) {
	}

	/**
	 * The data for the box.
	 *
	 * @return array<string,mixed>
	 */
	public function __invoke(): array {
		$dashboard = ( $this->dashboard )();
		$data      = (array) $dashboard->widget();
		$mode      = (string) ( $data['mode'] ?? '' );

		if ( 'shadow' === $mode ) {
			$status             = (array) $dashboard->status();
			$data['shadow']     = $status['shadow'] ?? null;
			$data['connection'] = $status['connection'] ?? null;
			return $data;
		}
		if ( in_array( $mode, array( 'normal', 'paused' ), true ) ) {
			foreach ( (array) ( $this->connections )()->summaries() as $summary ) {
				if ( is_array( $summary ) && 'disconnected' !== ( $summary['status'] ?? '' ) ) {
					$data['connection'] = $summary;
					break;
				}
			}
		}
		return $data;
	}
}
