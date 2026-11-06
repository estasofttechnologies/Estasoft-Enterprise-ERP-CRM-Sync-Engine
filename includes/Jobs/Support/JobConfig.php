<?php
/**
 * Per-connection settings of the background jobs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Jobs\JobException;

/**
 * Keeps the stock and tracking settings of a connection inside its `config_json` under the key `jobs`, so they travel with the
 * connection, are removed with it, and need no table of their own. The credentials are never part of it.
 */
final class JobConfig {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 */
	public function __construct( private readonly ConnectionRepository $connections ) {
	}

	/**
	 * The settings of one area, stored values over defaults.
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $area         For example stock or tracking.
	 * @param array<string,mixed> $defaults     Defaults.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404 when the connection does not exist.
	 */
	public function get( int $connectionId, string $area, array $defaults ): array {
		$stored = $this->jobs( $connectionId )[ $area ] ?? array();
		return is_array( $stored ) ? array_replace( $defaults, array_intersect_key( $stored, $defaults ) ) : $defaults;
	}

	/**
	 * Save the settings of one area (the whole set for that area).
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $area         For example stock or tracking.
	 * @param array<string,mixed> $values       Values.
	 *
	 * @throws JobException With status 404 when the connection does not exist.
	 */
	public function save( int $connectionId, string $area, array $values ): void {
		$jobs          = $this->jobs( $connectionId );
		$jobs[ $area ] = $values;
		$this->connections->update( $connectionId, array( 'config' => array( 'jobs' => $jobs ) ) );
	}

	/**
	 * All job settings of a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>
	 *
	 * @throws JobException With status 404 when the connection does not exist.
	 */
	private function jobs( int $connectionId ): array {
		$connection = $this->connections->findPublic( $connectionId );
		if ( null === $connection ) {
			throw new JobException( esc_html__( 'That connection does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ), 404 );
		}
		$jobs = $connection['config']['jobs'] ?? array();
		return is_array( $jobs ) ? $jobs : array();
	}
}
