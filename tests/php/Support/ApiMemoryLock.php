<?php
/**
 * A refresh lock in memory, for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshLock;

/**
 * Atomic within one process. `$busy` makes the next acquire() calls fail, as if another worker held the lock.
 */
final class ApiMemoryLock implements RefreshLock {

	/**
	 * Owner tokens by key.
	 *
	 * @var array<string,string>
	 */
	public array $held = array();

	/**
	 * Acquire attempts to refuse before granting again.
	 */
	public int $busy = 0;

	/**
	 * Number of successful acquisitions.
	 */
	public int $acquired = 0;

	/**
	 * Try to take the lock.
	 *
	 * @param string $key Lock name.
	 * @param int    $ttl Seconds (ignored).
	 */
	public function acquire( string $key, int $ttl ): ?string {
		unset( $ttl );
		if ( $this->busy > 0 ) {
			--$this->busy;
			return null;
		}
		if ( isset( $this->held[ $key ] ) ) {
			return null;
		}
		++$this->acquired;
		$this->held[ $key ] = bin2hex( random_bytes( 4 ) );
		return $this->held[ $key ];
	}

	/**
	 * Free a lock.
	 *
	 * @param string $key   Lock name.
	 * @param string $token Owner token.
	 */
	public function release( string $key, string $token ): void {
		if ( ( $this->held[ $key ] ?? null ) === $token ) {
			unset( $this->held[ $key ] );
		}
	}
}
