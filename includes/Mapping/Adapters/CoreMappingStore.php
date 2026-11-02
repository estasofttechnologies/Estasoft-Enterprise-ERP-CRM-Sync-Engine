<?php
/**
 * Mapping store backed by the core mapping repository.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Adapters;

use Estasoft\SyncEngine\Core\Repositories\MappingRepository;
use Estasoft\SyncEngine\Mapping\Ports\MappingStore;

defined( 'ABSPATH' ) || exit;

/**
 * Passes every call to Core\Repositories\MappingRepository, which keeps the history linear and enforces one live version.
 */
final class CoreMappingStore implements MappingStore {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MappingRepository $repository Core repository.
	 */
	public function __construct( private readonly MappingRepository $repository ) {
	}

	/**
	 * The live version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<string,mixed>|null
	 */
	public function live( int $connectionId, string $type ): ?array {
		return $this->repository->live( $connectionId, $type );
	}

	/**
	 * The draft.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<string,mixed>|null
	 */
	public function draft( int $connectionId, string $type ): ?array {
		return $this->repository->draft( $connectionId, $type );
	}

	/**
	 * Save the draft.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Rules.
	 * @param string|null                    $note         Note.
	 * @param string                         $by           Person.
	 * @return array<string,mixed>
	 */
	public function saveDraft( int $connectionId, string $type, array $rules, ?string $note, string $by ): array {
		return $this->repository->saveDraft( $connectionId, $type, $rules, $note, $by );
	}

	/**
	 * Publish the draft.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param string $by           Person.
	 * @return array<string,mixed>
	 */
	public function publish( int $connectionId, string $type, string $by ): array {
		return $this->repository->publish( $connectionId, $type, $by );
	}

	/**
	 * Discard the draft.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 */
	public function discardDraft( int $connectionId, string $type ): bool {
		return $this->repository->discardDraft( $connectionId, $type );
	}

	/**
	 * Roll back to an older version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version to copy.
	 * @param string $by           Person.
	 * @return array<string,mixed>
	 */
	public function rollback( int $connectionId, string $type, int $version, string $by ): array {
		return $this->repository->rollback( $connectionId, $type, $version, $by );
	}

	/**
	 * Replace the live rules in place.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Rules.
	 * @param string                         $by           Person.
	 * @return array<string,mixed>
	 */
	public function replaceLive( int $connectionId, string $type, array $rules, string $by ): array {
		return $this->repository->replaceLive( $connectionId, $type, $rules, $by );
	}

	/**
	 * All versions without rules.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( int $connectionId, string $type ): array {
		return $this->repository->versions( $connectionId, $type );
	}

	/**
	 * One version with rules.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version.
	 * @return array<string,mixed>|null
	 */
	public function find( int $connectionId, string $type, int $version ): ?array {
		return $this->repository->find( $connectionId, $type, $version );
	}
}
