<?php
/**
 * Connection source backed by the core connection repository.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Adapters;

use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Mapping\Ports\ConnectionSource;

defined( 'ABSPATH' ) || exit;

/**
 * Loads connections with their decrypted secrets, for the length of one request.
 */
final class CoreConnectionSource implements ConnectionSource {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $repository Core repository.
	 */
	public function __construct( private readonly ConnectionRepository $repository ) {
	}

	/**
	 * A connection with its secrets, or null.
	 *
	 * @param int $connectionId Connection id.
	 *
	 * @throws \Estasoft\SyncEngine\Core\Crypto\CryptoException When the secrets cannot be decrypted.
	 */
	public function find( int $connectionId ): ?Connection {
		return $this->repository->find( $connectionId );
	}
}
