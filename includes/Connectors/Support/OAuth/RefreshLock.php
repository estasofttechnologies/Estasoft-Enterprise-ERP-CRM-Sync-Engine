<?php
/**
 * A short lock so two workers never refresh one connection at once.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic: exactly one caller gets the lock for a key until it releases it or the time to live runs out.
 */
interface RefreshLock {

	/**
	 * Try to take the lock.
	 *
	 * @param string $key Lock name.
	 * @param int    $ttl Seconds until the lock frees itself.
	 * @return string|null An owner token, or null when somebody else holds the lock.
	 */
	public function acquire( string $key, int $ttl ): ?string;

	/**
	 * Free a lock, only when the owner token is still the owner.
	 *
	 * @param string $key   Lock name.
	 * @param string $token Token from acquire().
	 */
	public function release( string $key, string $token ): void;
}
