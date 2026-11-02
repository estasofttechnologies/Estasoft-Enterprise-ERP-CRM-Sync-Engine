<?php
/**
 * A throwaway queue for the capacity test.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Capacity;

defined( 'ABSPATH' ) || exit;

/**
 * Where the synthetic items of the capacity test live. Nothing here is the real queue: an implementation holds its items in memory
 * or in a table that belongs to the current database connection alone and disappears with it.
 */
interface CapacityStore {

	/**
	 * Create the store and fill it with synthetic items.
	 *
	 * @param int $items How many.
	 *
	 * @throws \RuntimeException When the store cannot be created.
	 */
	public function prepare( int $items ): void;

	/**
	 * Claim up to a number of items that are due, the way the real queue claims: select the candidates, then take them in one
	 * statement that only changes rows still waiting.
	 *
	 * @param int $limit  Most items.
	 * @param int $dueAt  Items with a time at or before this are due (seconds since the start of the test).
	 * @return array<int,int> Item ids.
	 */
	public function claim( int $limit, int $dueAt ): array;

	/**
	 * Mark items finished.
	 *
	 * @param array<int,int> $ids Item ids.
	 */
	public function finish( array $ids ): void;

	/**
	 * Put an item back to wait until a time (a simulated retryable failure).
	 *
	 * @param int $id     Item id.
	 * @param int $nextAt Seconds since the start of the test.
	 */
	public function retryLater( int $id, int $nextAt ): void;

	/**
	 * Items that are not finished.
	 */
	public function remaining(): int;

	/**
	 * Remove the store. Safe to call twice.
	 */
	public function drop(): void;

	/**
	 * Whether the store is a database table (true) or memory (false).
	 */
	public function usesDatabase(): bool;
}
