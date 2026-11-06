<?php
/**
 * Mapping versions.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Reads and writes `estasoft_sync_mappings`. For one connection and type there is a linear history of
 * versions: at most one draft, exactly one live version once something has been published, and the rest
 * retired. Publishing and rolling back run in a transaction that locks the rows of that connection and type,
 * so two people cannot create two live versions. Versions are never deleted, only a draft can be discarded.
 */
final class MappingRepository extends Repository {

	/**
	 * Status of a version being edited.
	 */
	public const DRAFT = 'draft';

	/**
	 * Status of the version in use.
	 */
	public const LIVE = 'live';

	/**
	 * Status of an older version.
	 */
	public const RETIRED = 'retired';

	/**
	 * Tries of a transaction that failed on a deadlock.
	 */
	private const TRANSACTION_ATTEMPTS = 3;

	/**
	 * The live version.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         orders, customers, products, refunds, stock, tracking or tax.
	 * @return array<string,mixed>|null A `MappingVersion` (version, status, note, created_by, created_at, published_at, rules).
	 */
	public function live( int $connectionId, string $type ): ?array {
		return $this->byStatus( $connectionId, $type, self::LIVE );
	}

	/**
	 * The draft, if there is one.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<string,mixed>|null
	 */
	public function draft( int $connectionId, string $type ): ?array {
		return $this->byStatus( $connectionId, $type, self::DRAFT );
	}

	/**
	 * One version by number.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      Version number.
	 * @return array<string,mixed>|null
	 */
	public function find( int $connectionId, string $type, int $version ): ?array {
		$row = $this->row(
			"SELECT * FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s AND version = %d",
			array( $connectionId, $type, $version )
		);
		return null === $row ? null : $this->shape( $row );
	}

	/**
	 * Every version, newest first, without the rules.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( int $connectionId, string $type ): array {
		$rows = $this->results(
			"SELECT * FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s ORDER BY version DESC",
			array( $connectionId, $type )
		);
		return array_map(
			function ( array $row ): array {
				$shape = $this->shape( $row );
				unset( $shape['rules'] );
				return $shape;
			},
			$rows
		);
	}

	/**
	 * Save the draft: creates it when there is none, otherwise replaces its rules. The draft takes the next version number.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Ordered rule list.
	 * @param string|null                    $note         Note for the history.
	 * @param string                         $by           Who saved it (display name).
	 * @return array<string,mixed> The draft.
	 */
	public function saveDraft( int $connectionId, string $type, array $rules, ?string $note, string $by ): array {
		return $this->transaction(
			$connectionId,
			$type,
			function () use ( $connectionId, $type, $rules, $note, $by ): array {
				$existing = $this->byStatus( $connectionId, $type, self::DRAFT );
				if ( null !== $existing ) {
					$this->run(
						"UPDATE {$this->tables->mappings()} SET rules_json = %s, note = %s, created_by = %s WHERE connection_id = %d AND type = %s AND version = %d",
						array( Json::encode( $rules ), (string) $note, mb_substr( $by, 0, 100 ), $connectionId, $type, (int) $existing['version'] )
					);
					return (array) $this->find( $connectionId, $type, (int) $existing['version'] );
				}
				$version = $this->nextVersion( $connectionId, $type );
				$this->insertVersion( $connectionId, $type, $version, self::DRAFT, $rules, $note, $by, null );
				return (array) $this->find( $connectionId, $type, $version );
			}
		);
	}

	/**
	 * Throw the draft away.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return bool Whether a draft existed.
	 */
	public function discardDraft( int $connectionId, string $type ): bool {
		return $this->run(
			"DELETE FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s AND status = %s",
			array( $connectionId, $type, self::DRAFT )
		) > 0;
	}

	/**
	 * Publish the draft: the live version becomes retired and the draft becomes live.
	 *
	 * The history stays strictly linear: version numbers only go up. Normally the draft already holds the highest number
	 * and keeps it. If something was created after the draft was (a rollback makes a new live version with the next number),
	 * the draft's number is lower than the highest one, so it is renumbered to the next free number inside the same
	 * transaction. Without that, publishing would make an older number live above a newer one, and the version stored on queue
	 * items could go backwards.
	 *
	 * @param int         $connectionId Connection.
	 * @param string      $type         Mapping type.
	 * @param string      $by           Who published.
	 * @param string|null $note         Note for the history; keeps the draft's note when null.
	 * @return array<string,mixed> The new live version (its number may differ from the draft's if it was renumbered).
	 *
	 * @throws \LogicException When there is no draft.
	 */
	public function publish( int $connectionId, string $type, string $by, ?string $note = null ): array {
		return $this->transaction(
			$connectionId,
			$type,
			function () use ( $connectionId, $type, $by, $note ): array {
				$draft = $this->byStatus( $connectionId, $type, self::DRAFT );
				if ( null === $draft ) {
					throw new \LogicException( 'There is no draft to publish.' );
				}
				$this->retireLive( $connectionId, $type );
				$version = (int) $draft['version'];
				$next    = $this->nextVersion( $connectionId, $type );
				$number  = $version < $next - 1 ? $next : $version;
				$this->run(
					"UPDATE {$this->tables->mappings()} SET version = %d, status = %s, published_at = %s, created_by = %s, note = %s WHERE connection_id = %d AND type = %s AND version = %d",
					array( $number, self::LIVE, $this->now(), mb_substr( $by, 0, 100 ), (string) ( $note ?? $draft['note'] ?? '' ), $connectionId, $type, $version )
				);
				return (array) $this->find( $connectionId, $type, $number );
			}
		);
	}

	/**
	 * Roll back: copy an older version into a new live version (history stays linear and nothing is rewritten).
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param int    $version      The version to copy.
	 * @param string $by           Who rolled back.
	 * @return array<string,mixed> The new live version.
	 *
	 * @throws \OutOfBoundsException When that version does not exist.
	 */
	public function rollback( int $connectionId, string $type, int $version, string $by ): array {
		return $this->transaction(
			$connectionId,
			$type,
			function () use ( $connectionId, $type, $version, $by ): array {
				$source = $this->find( $connectionId, $type, $version );
				if ( null === $source ) {
					throw new \OutOfBoundsException( 'That mapping version does not exist.' );
				}
				$this->retireLive( $connectionId, $type );
				$new = $this->nextVersion( $connectionId, $type );
				$this->insertVersion(
					$connectionId,
					$type,
					$new,
					self::LIVE,
					(array) $source['rules'],
					/* translators: %d: version number copied. */
					sprintf( __( 'Rolled back to version %d', 'estasoft-enterprise-erpcrm-sync-engine' ), $version ),
					$by,
					$version
				);
				return (array) $this->find( $connectionId, $type, $new );
			}
		);
	}

	/**
	 * Create version 1 as the live version when none exists (the connector's default mapping, MAP-1).
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Default rules.
	 * @param string                         $by           Who or what created it.
	 * @return array<string,mixed> The live version, new or existing.
	 */
	public function seedLive( int $connectionId, string $type, array $rules, string $by ): array {
		return $this->transaction(
			$connectionId,
			$type,
			function () use ( $connectionId, $type, $rules, $by ): array {
				$live = $this->byStatus( $connectionId, $type, self::LIVE );
				if ( null !== $live ) {
					return $live;
				}
				$version = $this->nextVersion( $connectionId, $type );
				$this->insertVersion( $connectionId, $type, $version, self::LIVE, $rules, null, $by, null );
				return (array) $this->find( $connectionId, $type, $version );
			}
		);
	}

	/**
	 * Replace the rules of the live version in place, with no draft and no new version. For mapping types that are not
	 * versioned (the tax map). Creates version 1 when there is none.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        New rules.
	 * @param string                         $by           Who saved.
	 * @return array<string,mixed> The live version.
	 */
	public function replaceLive( int $connectionId, string $type, array $rules, string $by ): array {
		return $this->transaction(
			$connectionId,
			$type,
			function () use ( $connectionId, $type, $rules, $by ): array {
				$live = $this->byStatus( $connectionId, $type, self::LIVE );
				if ( null === $live ) {
					$version = $this->nextVersion( $connectionId, $type );
					$this->insertVersion( $connectionId, $type, $version, self::LIVE, $rules, null, $by, null );
					return (array) $this->find( $connectionId, $type, $version );
				}
				$this->run(
					"UPDATE {$this->tables->mappings()} SET rules_json = %s, created_by = %s WHERE connection_id = %d AND type = %s AND version = %d",
					array( Json::encode( $rules ), mb_substr( $by, 0, 100 ), $connectionId, $type, (int) $live['version'] )
				);
				return (array) $this->find( $connectionId, $type, (int) $live['version'] );
			}
		);
	}

	/**
	 * Run work inside a transaction that locks every row of one connection and type first. If any statement fails (a
	 * deadlock with another request, for example) the whole transaction is rolled back, and it is tried again up to three
	 * times when the failure was a deadlock or a lock timeout.
	 *
	 * @param int      $connectionId Connection.
	 * @param string   $type         Mapping type.
	 * @param callable $work         Work to do; its return value is returned.
	 * @return mixed
	 *
	 * @throws \Throwable Whatever the work throws, after rolling back.
	 * @throws \RuntimeException When the database kept failing.
	 */
	private function transaction( int $connectionId, string $type, callable $work ): mixed {
		for ( $attempt = 1; $attempt <= self::TRANSACTION_ATTEMPTS; ++$attempt ) {
			$this->queryFailed = false;
			$this->lastError   = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API.
			$this->wpdb->query( 'START TRANSACTION' );
			try {
				$this->results(
					"SELECT id FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s FOR UPDATE",
					array( $connectionId, $type )
				);
				$result = $this->hasFailed() ? null : $work();
				if ( ! $this->hasFailed() ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API.
					$this->wpdb->query( 'COMMIT' );
					return $result;
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API.
				$this->wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API.
			$this->wpdb->query( 'ROLLBACK' );
			if ( 1 !== preg_match( '/deadlock|lock wait timeout/i', $this->lastError ) ) {
				break;
			}
			usleep( 20000 * $attempt );
		}
		throw new \RuntimeException( 'The mapping could not be saved.' );
	}

	/**
	 * Retire the live version, if any.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 */
	private function retireLive( int $connectionId, string $type ): void {
		$this->run(
			"UPDATE {$this->tables->mappings()} SET status = %s WHERE connection_id = %d AND type = %s AND status = %s",
			array( self::RETIRED, $connectionId, $type, self::LIVE )
		);
	}

	/**
	 * The next free version number.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 */
	private function nextVersion( int $connectionId, string $type ): int {
		return 1 + (int) $this->value(
			"SELECT MAX(version) FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s",
			array( $connectionId, $type )
		);
	}

	/**
	 * Insert a version row.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param int                            $version      Version number.
	 * @param string                         $status       draft, live or retired.
	 * @param array<int,array<string,mixed>> $rules        Rules.
	 * @param string|null                    $note         Note.
	 * @param string                         $by           Author.
	 * @param int|null                       $basedOn      Version copied from (rollback).
	 */
	private function insertVersion( int $connectionId, string $type, int $version, string $status, array $rules, ?string $note, string $by, ?int $basedOn ): void {
		$now = $this->now();
		$this->insert(
			$this->tables->mappings(),
			array(
				'connection_id' => $connectionId,
				'type'          => $type,
				'version'       => $version,
				'status'        => $status,
				'rules_json'    => Json::encode( $rules ),
				'note'          => $note,
				'created_by'    => mb_substr( $by, 0, 100 ),
				'created_at'    => $now,
				'published_at'  => self::LIVE === $status ? $now : null,
				'based_on'      => $basedOn,
			)
		);
	}

	/**
	 * The version with a status.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @param string $status       Status.
	 * @return array<string,mixed>|null
	 */
	private function byStatus( int $connectionId, string $type, string $status ): ?array {
		$row = $this->row(
			"SELECT * FROM {$this->tables->mappings()} WHERE connection_id = %d AND type = %s AND status = %s ORDER BY version DESC LIMIT 1",
			array( $connectionId, $type, $status )
		);
		return null === $row ? null : $this->shape( $row );
	}

	/**
	 * The `MappingVersion` shape of a row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function shape( array $row ): array {
		return array(
			'version'      => (int) $row['version'],
			'status'       => (string) $row['status'],
			'note'         => self::stringOrNull( $row['note'] ),
			'created_by'   => (string) $row['created_by'],
			'created_at'   => (string) Time::iso( (string) $row['created_at'] ),
			'published_at' => Time::iso( self::stringOrNull( $row['published_at'] ) ),
			'rules'        => Json::decode( (string) $row['rules_json'] ),
			'based_on'     => self::intOrNull( $row['based_on'] ),
		);
	}
}
