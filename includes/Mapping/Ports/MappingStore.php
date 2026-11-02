<?php
/**
 * Where mapping versions are kept.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * The part of Core\Repositories\MappingRepository that the mapping module uses. Versions are shaped like MappingVersion in src/api/types.ts:
 * version, status (draft, live, retired), note, created_by, created_at, published_at, rules.
 *
 * Rules of the store: exactly one live version per connection and type; a draft is never sent; publishing turns the draft into a new live
 * version and retires the old one; rolling back copies an old version into a new live version and keeps the draft; nothing is deleted.
 */
interface MappingStore {

	/**
	 * The live version, or null when none was published.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         orders, customers, products, refunds, stock, tracking or tax.
	 * @return array<string,mixed>|null
	 */
	public function live( int $connectionId, string $type ): ?array;

	/**
	 * The draft, or null when there is none.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<string,mixed>|null
	 */
	public function draft( int $connectionId, string $type ): ?array;

	/**
	 * Save rules as the draft (replacing the previous draft).
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Rules.
	 * @param string|null                    $note         Note for the team.
	 * @param string                         $by           Display name of the person saving.
	 * @return array<string,mixed> The draft.
	 */
	public function saveDraft( int $connectionId, string $type, array $rules, ?string $note, string $by ): array;

	/**
	 * Promote the draft to a new live version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param string $by           Display name of the person publishing.
	 * @return array<string,mixed> The new live version.
	 */
	public function publish( int $connectionId, string $type, string $by ): array;

	/**
	 * Throw the draft away.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return bool Whether a draft existed.
	 */
	public function discardDraft( int $connectionId, string $type ): bool;

	/**
	 * Make an old version live again as a new version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version to copy.
	 * @param string $by           Display name of the person rolling back.
	 * @return array<string,mixed> The new live version.
	 */
	public function rollback( int $connectionId, string $type, int $version, string $by ): array;

	/**
	 * Replace the rules of the live version in place, with no draft and no new version (the tax mapping).
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        New rules.
	 * @param string                         $by           Display name of the person saving.
	 * @return array<string,mixed> The live version.
	 */
	public function replaceLive( int $connectionId, string $type, array $rules, string $by ): array;

	/**
	 * All versions, newest first, without their rules.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( int $connectionId, string $type ): array;

	/**
	 * One version with its rules, or null.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version number.
	 * @return array<string,mixed>|null
	 */
	public function find( int $connectionId, string $type, int $version ): ?array;
}
