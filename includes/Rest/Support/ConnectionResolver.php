<?php
/**
 * Picks the connection a route works on.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Rest\ApiException;

/**
 * Routes such as `/mappings/{type}` and `/stock/rules` do not name a connection, because the first release has one connection
 * per connector and the screens work on the one that is set up. They accept an optional `connection_id`; without it the first
 * connection that is not disconnected is used.
 */
final class ConnectionResolver {

	/**
	 * Returns the connections shaped for the screens (each has `id` and `status`).
	 *
	 * @var \Closure():array<int,array<string,mixed>>
	 */
	private \Closure $connections;

	/**
	 * Create the resolver.
	 *
	 * @param \Closure $connections Lists every connection, oldest first, shaped for the screens.
	 * @phpstan-param \Closure():array<int,array<string,mixed>> $connections
	 */
	public function __construct( \Closure $connections ) {
		$this->connections = $connections;
	}

	/**
	 * The connection id to work on.
	 *
	 * @param int|null $requested The id asked for, or null for the default.
	 * @return int
	 *
	 * @throws ApiException 404 when the id asked for does not exist; 409 when there is no connection yet.
	 */
	public function resolve( ?int $requested = null ): int {
		$all = ( $this->connections )();
		if ( null !== $requested && $requested > 0 ) {
			foreach ( $all as $connection ) {
				if ( (int) ( $connection['id'] ?? 0 ) === $requested ) {
					return $requested;
				}
			}
			throw ApiException::notFound( esc_html__( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		foreach ( $all as $connection ) {
			if ( ConnectionStatus::Disconnected->value !== (string) ( $connection['status'] ?? '' ) ) {
				return (int) $connection['id'];
			}
		}
		throw ApiException::conflict( esc_html__( 'Connect a system first. This needs a connection.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_no_connection' );
	}
}
