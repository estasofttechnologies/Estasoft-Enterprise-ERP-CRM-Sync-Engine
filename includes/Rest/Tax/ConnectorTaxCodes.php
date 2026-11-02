<?php
/**
 * Asks the connected system for its sale tax codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Tax;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;

/**
 * `listTaxes()` is an optional method of a connector (the Odoo connector has it); connectors without it, a system that cannot be
 * reached and unreadable credentials all give an empty list, so the tax screen still opens and shows the rows it has.
 */
final class ConnectorTaxCodes {

	/**
	 * Called with the class of an exception that stopped the list.
	 *
	 * @var \Closure(string):void
	 */
	private \Closure $report;

	/**
	 * Create the tax code lister.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param ConnectorRegistry    $registry    Installed connectors.
	 * @param \Closure|null        $report      Receives the class name of an unexpected exception (and nothing else of it); ignores it by default.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly ConnectorRegistry $registry,
		?\Closure $report = null
	) {
		$this->report = $report ?? static function ( string $exceptionClass ): void {
			unset( $exceptionClass );
		};
	}

	/**
	 * The codes of one connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<int,array{code:string,label:string,rate_percent:int|float}>
	 */
	public function __invoke( int $connectionId ): array {
		try {
			$connection = $this->connections->find( $connectionId );
			$connector  = null === $connection ? null : $this->registry->get( $connection->connector );
			if ( null === $connection || null === $connector || ! method_exists( $connector, 'listTaxes' ) ) {
				return array();
			}
			$taxes = $connector->listTaxes( $connection );
		} catch ( \Throwable $error ) {
			( $this->report )( get_class( $error ) ); // The screen still opens without the list; the log says what stopped it.
			return array();
		}
		if ( $taxes instanceof ConnectorError || ! is_array( $taxes ) ) {
			return array();
		}
		$codes = array();
		foreach ( $taxes as $tax ) {
			if ( ! is_array( $tax ) || ! isset( $tax['code'] ) ) {
				continue;
			}
			$rate    = $tax['rate_percent'] ?? 0;
			$codes[] = array(
				'code'         => (string) $tax['code'],
				'label'        => (string) ( $tax['label'] ?? $tax['code'] ),
				'rate_percent' => is_numeric( $rate ) ? $rate + 0 : 0,
			);
		}
		return $codes;
	}
}
