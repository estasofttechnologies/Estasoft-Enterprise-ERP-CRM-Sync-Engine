<?php
/**
 * A short-lived store that can hand a value out exactly once.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Support;

defined( 'ABSPATH' ) || exit;

/**
 * For values that must be used once (the sign-in state): `take()` reads and deletes in one step, and of two callers that ask at the same time
 * only one gets the value.
 */
interface AtomicKeyValueStore extends KeyValueStore {

	/**
	 * Read a value and delete it, so that no other caller can have it.
	 *
	 * @param string $key Key.
	 * @return mixed The value, or null when it is missing, expired or somebody else took it first.
	 */
	public function take( string $key ): mixed;
}
