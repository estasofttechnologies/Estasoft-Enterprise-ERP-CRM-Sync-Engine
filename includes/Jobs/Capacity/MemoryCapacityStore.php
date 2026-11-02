<?php
/**
 * The capacity test queue in memory.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Capacity;

defined( 'ABSPATH' ) || exit;

/**
 * Used when the database user cannot create a temporary table. It measures PHP and the plugin's own code, not the database.
 */
final class MemoryCapacityStore implements CapacityStore {

	/**
	 * Items: id to array( state, next ).
	 *
	 * @var array<int,array{state:string,next:int}>
	 */
	private array $rows = array();

	/**
	 * Fill with synthetic items.
	 *
	 * @param int $items How many.
	 */
	public function prepare( int $items ): void {
		$this->rows = array();
		for ( $id = 1; $id <= $items; ++$id ) {
			$this->rows[ $id ] = array(
				'state' => 'queued',
				'next'  => 0,
			);
		}
	}

	/**
	 * Claim due items.
	 *
	 * @param int $limit Most items.
	 * @param int $dueAt Due time.
	 * @return array<int,int>
	 */
	public function claim( int $limit, int $dueAt ): array {
		$claimed = array();
		foreach ( $this->rows as $id => $row ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			if ( 'queued' === $row['state'] && $row['next'] <= $dueAt ) {
				$this->rows[ $id ]['state'] = 'syncing';
				$claimed[]                  = $id;
			}
		}
		return $claimed;
	}

	/**
	 * Finish items.
	 *
	 * @param array<int,int> $ids Item ids.
	 */
	public function finish( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ]['state'] = 'synced';
			}
		}
	}

	/**
	 * Wait until a time.
	 *
	 * @param int $id     Item id.
	 * @param int $nextAt Time.
	 */
	public function retryLater( int $id, int $nextAt ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ] = array(
				'state' => 'queued',
				'next'  => $nextAt,
			);
		}
	}

	/**
	 * Items not finished.
	 */
	public function remaining(): int {
		return count( array_filter( $this->rows, static fn( array $row ): bool => 'synced' !== $row['state'] ) );
	}

	/**
	 * Forget everything.
	 */
	public function drop(): void {
		$this->rows = array();
	}

	/**
	 * Memory, not a table.
	 */
	public function usesDatabase(): bool {
		return false;
	}
}
