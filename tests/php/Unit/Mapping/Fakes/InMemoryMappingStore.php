<?php
/**
 * In-memory mapping store with the same history rules as the core repository.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

use Estasoft\SyncEngine\Mapping\Ports\MappingStore;

/**
 * Draft numbered after the newest version; publish retires the live version and promotes the draft with the same number; rollback copies
 * an old version into a new live version and keeps the draft; nothing is deleted except a discarded draft.
 */
final class InMemoryMappingStore implements MappingStore {

	/**
	 * Versions by "connection|type", each a list of version arrays.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $versions = array();

	/**
	 * Calls made, in order, for tests that check what happened.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();

	public function live( int $connectionId, string $type ): ?array {
		return $this->byStatus( $connectionId, $type, 'live' );
	}

	public function draft( int $connectionId, string $type ): ?array {
		return $this->byStatus( $connectionId, $type, 'draft' );
	}

	public function saveDraft( int $connectionId, string $type, array $rules, ?string $note, string $by ): array {
		$this->calls[] = 'saveDraft';
		$key           = $this->key( $connectionId, $type );
		foreach ( $this->versions[ $key ] ?? array() as $position => $version ) {
			if ( 'draft' === $version['status'] ) {
				$this->versions[ $key ][ $position ]['rules']      = $rules;
				$this->versions[ $key ][ $position ]['note']       = $note;
				$this->versions[ $key ][ $position ]['created_by'] = $by;
				return $this->versions[ $key ][ $position ];
			}
		}
		return $this->add( $connectionId, $type, 'draft', $rules, $note, $by );
	}

	public function publish( int $connectionId, string $type, string $by ): array {
		$this->calls[] = 'publish';
		$key           = $this->key( $connectionId, $type );
		$draft         = $this->draft( $connectionId, $type );
		if ( null === $draft ) {
			throw new \LogicException( 'There is no draft to publish.' );
		}
		foreach ( $this->versions[ $key ] as $position => $version ) {
			if ( 'live' === $version['status'] ) {
				$this->versions[ $key ][ $position ]['status'] = 'retired';
			}
			if ( 'draft' === $version['status'] ) {
				$this->versions[ $key ][ $position ]['status']       = 'live';
				$this->versions[ $key ][ $position ]['published_at'] = '2026-11-02T10:00:00Z';
				$this->versions[ $key ][ $position ]['created_by']   = $by;
				$live                                                 = $this->versions[ $key ][ $position ];
			}
		}
		return $live;
	}

	public function discardDraft( int $connectionId, string $type ): bool {
		$key   = $this->key( $connectionId, $type );
		$count = count( $this->versions[ $key ] ?? array() );
		$this->versions[ $key ] = array_values(
			array_filter( $this->versions[ $key ] ?? array(), static fn( array $version ): bool => 'draft' !== $version['status'] )
		);
		return count( $this->versions[ $key ] ) < $count;
	}

	public function rollback( int $connectionId, string $type, int $version, string $by ): array {
		$this->calls[] = 'rollback';
		$source        = $this->find( $connectionId, $type, $version );
		if ( null === $source ) {
			throw new \OutOfBoundsException( 'That mapping version does not exist.' );
		}
		$key = $this->key( $connectionId, $type );
		foreach ( $this->versions[ $key ] as $position => $existing ) {
			if ( 'live' === $existing['status'] ) {
				$this->versions[ $key ][ $position ]['status'] = 'retired';
			}
		}
		$new = $this->add( $connectionId, $type, 'live', $source['rules'], 'Rolled back to version ' . $version, $by );
		$this->versions[ $key ][ count( $this->versions[ $key ] ) - 1 ]['based_on'] = $version;
		return $this->find( $connectionId, $type, $new['version'] ) ?? $new;
	}

	public function replaceLive( int $connectionId, string $type, array $rules, string $by ): array {
		$this->calls[] = 'replaceLive';
		$key           = $this->key( $connectionId, $type );
		foreach ( $this->versions[ $key ] ?? array() as $position => $existing ) {
			if ( 'live' === $existing['status'] ) {
				$this->versions[ $key ][ $position ]['rules']      = $rules;
				$this->versions[ $key ][ $position ]['created_by'] = $by;
				return $this->versions[ $key ][ $position ];
			}
		}
		return $this->add( $connectionId, $type, 'live', $rules, null, $by );
	}

	public function versions( int $connectionId, string $type ): array {
		$list = array();
		foreach ( array_reverse( $this->versions[ $this->key( $connectionId, $type ) ] ?? array() ) as $version ) {
			unset( $version['rules'] );
			$list[] = $version;
		}
		return $list;
	}

	public function find( int $connectionId, string $type, int $version ): ?array {
		foreach ( $this->versions[ $this->key( $connectionId, $type ) ] ?? array() as $existing ) {
			if ( $existing['version'] === $version ) {
				return $existing;
			}
		}
		return null;
	}

	/**
	 * Put a live version in place without going through a draft (test set-up).
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Type.
	 * @param array<int,array<string,mixed>> $rules        Rules.
	 */
	public function seedLive( int $connectionId, string $type, array $rules ): void {
		$this->add( $connectionId, $type, 'live', $rules, null, 'Seed' );
	}

	/**
	 * Statuses of all versions in order, for assertions such as "1 retired, 2 live".
	 *
	 * @return array<int,string>
	 */
	public function statuses( int $connectionId, string $type ): array {
		$out = array();
		foreach ( $this->versions[ $this->key( $connectionId, $type ) ] ?? array() as $version ) {
			$out[ $version['version'] ] = $version['status'];
		}
		return $out;
	}

	private function key( int $connectionId, string $type ): string {
		return $connectionId . '|' . $type;
	}

	private function byStatus( int $connectionId, string $type, string $status ): ?array {
		foreach ( $this->versions[ $this->key( $connectionId, $type ) ] ?? array() as $version ) {
			if ( $version['status'] === $status ) {
				return $version;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $rules Rules.
	 * @return array<string,mixed>
	 */
	private function add( int $connectionId, string $type, string $status, array $rules, ?string $note, string $by ): array {
		$key     = $this->key( $connectionId, $type );
		$version = array(
			'version'      => 1 + count( $this->versions[ $key ] ?? array() ),
			'status'       => $status,
			'note'         => $note,
			'created_by'   => $by,
			'created_at'   => '2026-11-02T09:00:00Z',
			'published_at' => 'live' === $status ? '2026-11-02T09:00:00Z' : null,
			'rules'        => $rules,
			'based_on'     => null,
		);
		$this->versions[ $key ][] = $version;
		return $version;
	}
}
