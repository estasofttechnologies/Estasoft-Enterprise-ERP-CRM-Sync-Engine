<?php
/**
 * The queue (the outbox and the queue are one table).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\DayRange;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Domain\ItemState;

/**
 * Reads and writes `estasoft_sync_queue`. State changes are single statements guarded by the state they
 * expect, so two workers (or a worker and a person) can never both win the same change.
 */
final class QueueRepository extends Repository {

	/**
	 * Days a synced item is kept.
	 */
	public const KEEP_SYNCED_DAYS = 30;

	/**
	 * Days a skipped item, or a synced item that once failed, is kept.
	 */
	public const KEEP_LONG_DAYS = 90;

	/**
	 * Rows deleted per statement when pruning.
	 */
	private const PRUNE_BATCH = 1000;

	/**
	 * How many times claim() looks again when another worker took every candidate first.
	 */
	private const CLAIM_ATTEMPTS = 8;

	/**
	 * Longest stored error message, in characters (spec 5.3).
	 */
	public const MESSAGE_LIMIT = 500;

	/**
	 * Longest stored error cause, in characters.
	 */
	public const CAUSE_LIMIT = 191;

	/**
	 * Times the stale lock reaper may take back the same item before it is stopped and left to a person (poison item).
	 */
	public const MAX_REAPS = 3;

	/**
	 * Priority of an ordinary item. A lower number is claimed first.
	 */
	public const DEFAULT_PRIORITY = 5;

	/**
	 * @param \wpdb         $wpdb          Database.
	 * @param Tables        $tables        Table names.
	 * @param Clock         $clock         Time source.
	 * @param \DateTimeZone $storeTimezone Store time zone, for the day filters.
	 * @param Redactor|null $redactor      Cleans messages before they are stored; a default one is used when null.
	 * @param Settings|null $settings      Tells whether personal data is removed too; it is removed when null.
	 */
	public function __construct(
		\wpdb $wpdb,
		Tables $tables,
		Clock $clock,
		private readonly \DateTimeZone $storeTimezone,
		private readonly ?Redactor $redactor = null,
		private readonly ?Settings $settings = null
	) {
		parent::__construct( $wpdb, $tables, $clock );
	}

	/**
	 * Redact and cap a message that is about to be stored. Error messages can carry text the other system sent back, so
	 * they are cleaned like log messages: secrets always, personal data while that setting is on.
	 *
	 * @param string|null $message Message.
	 * @param int         $limit   Most characters kept.
	 * @return string|null
	 */
	public function clean( ?string $message, int $limit = self::MESSAGE_LIMIT ): ?string {
		if ( null === $message ) {
			return null;
		}
		$personal = null === $this->settings || $this->settings->redactionEnabled();
		return mb_substr( ( $this->redactor ?? new Redactor() )->message( $message, $personal ), 0, $limit );
	}

	/**
	 * Add an item. Meant to run inside the order's own database transaction: it only inserts one row.
	 *
	 * The pair (connection, idempotency key) is unique. If a row with the same key already exists (two requests raced, or
	 * the ledger was cleaned but the row stayed), no second row is added and the id of the existing one is returned.
	 * enqueueOnce() says which of the two happened.
	 *
	 * @param int                 $connectionId   Connection.
	 * @param EntityType          $type           Entity type.
	 * @param string              $entityId       WooCommerce id (or SKU for stock).
	 * @param string              $event          created, paid, updated, refunded, cancelled, delivered.
	 * @param string              $partitionKey   Ordering key, for example order:10490.
	 * @param string              $idempotencyKey sha256 hex.
	 * @param ItemMode            $mode           live or shadow.
	 * @param int                 $maxAttempts    Tries allowed.
	 * @param array<string,mixed> $display        Optional label, customer, total_minor, currency, search for the lists.
	 * @param int                 $priority       0 to 9, claimed lowest first among items that are due; 5 is ordinary.
	 * @return int The id (new, or the existing item with this key), or 0 when the row could not be written.
	 */
	public function enqueue(
		int $connectionId,
		EntityType $type,
		string $entityId,
		string $event,
		string $partitionKey,
		string $idempotencyKey,
		ItemMode $mode = ItemMode::Live,
		int $maxAttempts = 5,
		array $display = array(),
		int $priority = self::DEFAULT_PRIORITY
	): int {
		return $this->enqueueOnce( $connectionId, $type, $entityId, $event, $partitionKey, $idempotencyKey, $mode, $maxAttempts, $display, $priority )['id'];
	}

	/**
	 * Add an item, or find the one that already has this idempotency key. Same arguments as enqueue().
	 *
	 * @param int                 $connectionId   Connection.
	 * @param EntityType          $type           Entity type.
	 * @param string              $entityId       WooCommerce id.
	 * @param string              $event          Event.
	 * @param string              $partitionKey   Ordering key.
	 * @param string              $idempotencyKey sha256 hex.
	 * @param ItemMode            $mode           live or shadow.
	 * @param int                 $maxAttempts    Tries allowed.
	 * @param array<string,mixed> $display        Display values.
	 * @param int                 $priority       0 to 9.
	 * @return array{id:int,created:bool} `created` is false when the key was already in the queue; `id` is 0 when nothing could be written.
	 */
	public function enqueueOnce(
		int $connectionId,
		EntityType $type,
		string $entityId,
		string $event,
		string $partitionKey,
		string $idempotencyKey,
		ItemMode $mode = ItemMode::Live,
		int $maxAttempts = 5,
		array $display = array(),
		int $priority = self::DEFAULT_PRIORITY
	): array {
		$now  = $this->now();
		$data = array_merge(
			array(
				'connection_id'   => $connectionId,
				'entity_type'     => $type->value,
				'entity_id'       => mb_substr( $entityId, 0, 64 ),
				'entity_label'    => mb_substr( (string) ( $display['label'] ?? $entityId ), 0, 191 ),
				'event'           => mb_substr( $event, 0, 32 ),
				'partition_key'   => mb_substr( $partitionKey, 0, 100 ),
				'idempotency_key' => $idempotencyKey,
				'priority'        => max( 0, min( 9, $priority ) ),
				'state'           => ItemState::Queued->value,
				'mode'            => $mode->value,
				'attempt'         => 0,
				'max_attempts'    => max( 1, $maxAttempts ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			$this->displayColumns( $display )
		);

		// A duplicate key is an expected answer here, not an error to print or to remember.
		$failed    = $this->queryFailed;
		$lastError = $this->lastError;
		$previous  = $this->wpdb->suppress_errors( true );
		$id        = $this->insert( $this->tables->queue(), $data );
		$this->wpdb->suppress_errors( $previous );
		if ( $id > 0 ) {
			return array(
				'id'      => $id,
				'created' => true,
			);
		}
		$existing = $this->idByKey( $connectionId, $idempotencyKey );
		if ( null !== $existing ) {
			$this->queryFailed = $failed;
			$this->lastError   = $lastError;
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}
		return array(
			'id'      => 0,
			'created' => false,
		);
	}

	/**
	 * Run work that writes the queue row and the rows that belong with it (the idempotency ledger) as one unit: all of it is
	 * kept or none of it is. If the connection already has a transaction open (the order's own) the unit is a savepoint in
	 * it and nothing is committed here; otherwise it is a transaction of its own. See Repository::unit().
	 *
	 * @param \Closure $work Work to do; return false to undo it.
	 * @return mixed What the work returned, or false when it was undone.
	 *
	 * @throws \Throwable Whatever the work throws, after the rollback.
	 */
	public function atomically( \Closure $work ): mixed {
		return $this->unit( $work );
	}

	/**
	 * The item that has an idempotency key on a connection.
	 *
	 * @param int    $connectionId   Connection.
	 * @param string $idempotencyKey sha256 hex.
	 * @return int|null Item id.
	 */
	public function idByKey( int $connectionId, string $idempotencyKey ): ?int {
		$id = $this->value(
			"SELECT id FROM {$this->tables->queue()} WHERE connection_id = %d AND idempotency_key = %s LIMIT 1",
			array( $connectionId, $idempotencyKey )
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * Set the list display values of an item (label, customer name, total, search text).
	 *
	 * @param int                 $id      Item id.
	 * @param array<string,mixed> $display label, customer, total_minor, currency, search.
	 */
	public function setDisplay( int $id, array $display ): void {
		$data = $this->displayColumns( $display );
		if ( isset( $display['label'] ) ) {
			$data['entity_label'] = mb_substr( (string) $display['label'], 0, 191 );
		}
		if ( array() !== $data ) {
			$this->updateById( $this->tables->queue(), $data, $id );
		}
	}

	/**
	 * The newest item of an entity and event that has not finished and is not being sent: queued, retrying or failed.
	 * A new event of the same kind joins it instead of adding a second item, because the payload is built at send time.
	 *
	 * @param int        $connectionId Connection.
	 * @param EntityType $type         Entity type.
	 * @param string     $entityId     WooCommerce id.
	 * @param string     $event        Event.
	 * @return int|null Item id.
	 */
	public function findUnfinished( int $connectionId, EntityType $type, string $entityId, string $event ): ?int {
		$id = $this->value(
			"SELECT id FROM {$this->tables->queue()} WHERE connection_id = %d AND entity_type = %s AND entity_id = %s AND event = %s AND state IN (%s,%s,%s) ORDER BY id DESC LIMIT 1",
			array( $connectionId, $type->value, $entityId, $event, ItemState::Queued->value, ItemState::Retrying->value, ItemState::Failed->value )
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * Mark a waiting item as changed while it waited (SYNC-STATE-001, "the latest version will be sent").
	 * Does nothing when the item already carries an error.
	 *
	 * @param int $id Item id.
	 */
	public function noteChangedWhileWaiting( int $id ): void {
		$this->run(
			"UPDATE {$this->tables->queue()} SET error_code = %s, error_message = %s WHERE id = %d AND state IN (%s,%s) AND error_code IS NULL",
			array( ErrorCode::State001->value, ErrorCode::State001->template(), $id, ItemState::Queued->value, ItemState::Retrying->value )
		);
	}

	/**
	 * Claim items for a worker, atomically.
	 *
	 * First the candidates are selected in id order: state queued or retrying, due, not locked, on an active
	 * connection that is not paused, and with no earlier item of the same partition that is queued, syncing,
	 * retrying or failed (skipped and synced do not block). Then one UPDATE moves exactly those rows that are
	 * still queued or retrying and unlocked to syncing, stamped with this worker. The rows this worker really got
	 * are read back by that stamp. Two workers can select the same candidate; only one UPDATE changes the row.
	 *
	 * Among the items that are due, a lower `priority` number goes first, then the older item. Ordering inside one
	 * partition does not depend on priority: a later item of the same order still waits for the earlier one.
	 *
	 * @param int      $limit        Most items to claim.
	 * @param int      $lockSeconds  How long the lock lasts before the reaper may take it back.
	 * @param string   $workerId     Unique id of the caller (a UUID per run). It is the lock token: only the holder of it can write the result.
	 * @param int|null $connectionId Claim for one connection only.
	 * @return array<int,Item> The claimed items, in id order.
	 */
	public function claim( int $limit, int $lockSeconds, string $workerId, ?int $connectionId = null ): array {
		$limit = max( 1, $limit );
		$now   = $this->now();
		$until = Time::sql( $this->clock->now()->modify( sprintf( '+%d seconds', max( 1, $lockSeconds ) ) ) );
		$block = $this->blockingStates();

		$args = array( ItemState::Queued->value, ItemState::Retrying->value, $now, $now, ConnectionStatus::Active->value, $now );
		$sql  = "SELECT q.id FROM {$this->tables->queue()} q
			INNER JOIN {$this->tables->connections()} c ON c.id = q.connection_id
			WHERE q.state IN (%s,%s)
			AND (q.next_attempt_at IS NULL OR q.next_attempt_at <= %s)
			AND (q.locked_until IS NULL OR q.locked_until < %s)
			AND c.status = %s
			AND (c.paused_until IS NULL OR c.paused_until <= %s)";
		if ( null !== $connectionId ) {
			$sql   .= ' AND q.connection_id = %d';
			$args[] = $connectionId;
		}
		$sql .= " AND NOT EXISTS (
				SELECT 1 FROM {$this->tables->queue()} e
				WHERE e.connection_id = q.connection_id AND e.partition_key = q.partition_key AND e.id < q.id AND e.state IN ($block)
			)
			ORDER BY q.priority ASC, q.id ASC LIMIT %d OFFSET %d";

		// Workers that look at the same moment all pick the same oldest items, and only one UPDATE can win them. A worker that
		// lost every candidate looks again, a little later and a little further down the list, so that two workers stop
		// fighting over the same rows. An empty answer therefore means that nothing is waiting, not that the race was lost.
		$ids          = array();
		$placeholders = '';
		$updated      = 0;
		$offset       = 0;
		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS && 0 === $updated; ++$attempt ) {
			$ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->results( $sql, array_merge( $args, array( $limit, $offset ) ) ) );
			if ( array() === $ids ) {
				if ( 0 === $offset ) {
					return array();
				}
				// The offset skipped past the end of the list: look from the start again.
				$offset = 0;
				continue;
			}
			$placeholders = $this->placeholders( $ids );
			$updated      = $this->run(
				"UPDATE {$this->tables->queue()} SET state = %s, locked_by = %s, locked_until = %s, updated_at = %s
				WHERE id IN ($placeholders) AND state IN (%s,%s) AND (locked_until IS NULL OR locked_until < %s)",
				array_merge(
					array( ItemState::Syncing->value, $workerId, $until, $now ),
					$ids,
					array( ItemState::Queued->value, ItemState::Retrying->value, $now )
				)
			);
			if ( 0 === $updated ) {
				$offset = random_int( 0, $limit * ( $attempt + 1 ) );
				usleep( random_int( 300, 2500 ) * ( $attempt + 1 ) );
			}
		}
		if ( 0 === $updated ) {
			return array();
		}

		$rows = $this->results(
			"SELECT * FROM {$this->tables->queue()} WHERE id IN ($placeholders) AND locked_by = %s AND state = %s ORDER BY id ASC",
			array_merge( $ids, array( $workerId, ItemState::Syncing->value ) )
		);
		return array_map( array( $this, 'item' ), $rows );
	}

	/**
	 * Name of the MySQL named lock that makes "count what is running, then claim" one step for the whole site.
	 *
	 * Named locks belong to the whole database server, not to one database. Two sites with the same table prefix in two
	 * databases on one server would share a lock named after the table alone and hold each other up, so the name is made from
	 * the database name as well as the table name (at most 64 characters, the server's limit).
	 */
	public function claimLockName(): string {
		return 'estasoft_sync_claim_' . substr( hash( 'sha256', (string) $this->wpdb->dbname . '|' . $this->tables->queue() ), 0, 16 );
	}

	/**
	 * Run work while holding the claim lock, a MySQL named lock (`GET_LOCK` / `RELEASE_LOCK`).
	 *
	 * The global limit on items sent at once is checked by counting the items that are syncing and then claiming up to
	 * the room that is left. Two workers doing that at the same moment would both see the same room and together go over
	 * the limit. Holding this lock across the count and the claim makes the pair atomic. The wait is short; if the lock is
	 * not free in time (another worker is claiming), the work is not run and `$fallback` is returned, so a worker never
	 * waits long on another. The lock is always released, also when the work throws, and it is also released by MySQL if
	 * the process dies.
	 *
	 * @param \Closure $work        Work to do while the lock is held.
	 * @param int      $waitSeconds Seconds to wait for the lock.
	 * @param mixed    $fallback    Returned when the lock could not be taken.
	 * @return mixed What the work returned, or the fallback.
	 */
	public function withClaimLock( \Closure $work, int $waitSeconds = 2, mixed $fallback = null ): mixed {
		$name = $this->claimLockName();
		$got  = $this->value( 'SELECT GET_LOCK(%s, %d)', array( $name, max( 0, $waitSeconds ) ) );
		if ( '1' !== $got ) {
			return $fallback;
		}
		try {
			return $work();
		} finally {
			$this->value( 'SELECT RELEASE_LOCK(%s)', array( $name ) );
		}
	}

	/**
	 * Record a successful send (or a link to a record that already existed).
	 *
	 * With `$lockToken` the write is guarded: it changes the row only while the row is still `syncing` and locked by that
	 * token. If the lock was lost (it ran out, the reaper took the item back and another worker may now hold it), nothing is
	 * written and false is returned, and the caller must discard the result. Without a token the row is updated as it is
	 * (for code that did not claim the item).
	 *
	 * @param int         $id             Item id.
	 * @param string      $externalId     The system's id.
	 * @param string      $externalRef    The system's human number.
	 * @param string|null $externalUrl    Link to the record.
	 * @param int|null    $mappingVersion Mapping version used.
	 * @param string|null $noteCode       A handled code to keep on the row, for example SYNC-DUP-001.
	 * @param bool        $countTry       Whether this counts as a try.
	 * @param string|null $noteMessage    The message for that code with its placeholders filled in; the catalog text when null.
	 * @param string|null $lockToken      The `workerId` given to claim().
	 * @return bool Whether the row was written.
	 */
	public function markSynced( int $id, string $externalId, string $externalRef, ?string $externalUrl = null, ?int $mappingVersion = null, ?string $noteCode = null, bool $countTry = true, ?string $noteMessage = null, ?string $lockToken = null ): bool {
		$now  = $this->now();
		$note = null === $noteCode ? null : ErrorCode::tryFrom( $noteCode );
		return $this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Synced->value,
				'attempt'         => $countTry ? $this->attemptPlusOne() : $this->attemptSame(),
				'external_id'     => '' === $externalId ? null : $externalId,
				'external_ref'    => '' === $externalRef ? null : $externalRef,
				'external_url'    => $externalUrl,
				'mapping_version' => $mappingVersion,
				'error_code'      => $noteCode,
				'error_message'   => $this->clean( $noteMessage ?? $note?->template() ),
				'error_cause'     => null,
				'next_attempt_at' => null,
				'locked_by'       => null,
				'locked_until'    => null,
				'finished_at'     => $now,
				'updated_at'      => $now,
			),
			$lockToken
		);
	}

	/**
	 * Record a retryable failure: the item waits for its next try. See markSynced() for `$lockToken`.
	 *
	 * @param int         $id            Item id.
	 * @param string      $nextAttemptAt MySQL datetime (UTC).
	 * @param string      $errorCode     SYNC-... code.
	 * @param string      $message       Message with placeholders filled (redacted and cut to 500 characters before it is stored).
	 * @param string      $cause         Short cause such as "SKU CS-LAMP-07".
	 * @param string|null $lockToken     The `workerId` given to claim().
	 * @return bool Whether the row was written.
	 */
	public function markRetrying( int $id, string $nextAttemptAt, string $errorCode, string $message, string $cause = '', ?string $lockToken = null ): bool {
		return $this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Retrying->value,
				'attempt'         => $this->attemptPlusOne(),
				'reaps'           => 0,
				'next_attempt_at' => $nextAttemptAt,
				'error_code'      => $errorCode,
				'error_message'   => $this->clean( $message ),
				'error_cause'     => '' === $cause ? null : $this->clean( $cause, self::CAUSE_LIMIT ),
				'locked_by'       => null,
				'locked_until'    => null,
				'updated_at'      => $this->now(),
			),
			$lockToken
		);
	}

	/**
	 * Record a failure that retrying cannot fix: a person must act. See markSynced() for `$lockToken`.
	 *
	 * @param int         $id        Item id.
	 * @param string      $errorCode SYNC-... code.
	 * @param string      $message   Message with placeholders filled (redacted and cut to 500 characters before it is stored).
	 * @param string      $cause     Short cause such as "SKU CS-LAMP-07".
	 * @param bool        $countTry  Whether this counts as a try (errors found before sending do not).
	 * @param string|null $lockToken The `workerId` given to claim().
	 * @return bool Whether the row was written.
	 */
	public function markFailed( int $id, string $errorCode, string $message, string $cause = '', bool $countTry = true, ?string $lockToken = null ): bool {
		$now = $this->now();
		return $this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Failed->value,
				'attempt'         => $countTry ? $this->attemptPlusOne() : $this->attemptSame(),
				'reaps'           => 0,
				'next_attempt_at' => null,
				'error_code'      => $errorCode,
				'error_message'   => $this->clean( $message ),
				'error_cause'     => '' === $cause ? null : $this->clean( $cause, self::CAUSE_LIMIT ),
				'failed_before'   => 1,
				'locked_by'       => null,
				'locked_until'    => null,
				'finished_at'     => $now,
				'updated_at'      => $now,
			),
			$lockToken
		);
	}

	/**
	 * Give a claimed item back without counting a try (paused connection, missing connector, rate limit window).
	 * See markSynced() for `$lockToken`.
	 *
	 * @param int         $id            Item id.
	 * @param string|null $nextAttemptAt Earliest next claim, or null for now.
	 * @param string|null $errorCode     Code to show, or null to clear.
	 * @param string|null $message       Message to show.
	 * @param bool        $keepError     Leave the error columns as they are.
	 * @param string|null $lockToken     The `workerId` given to claim().
	 * @return bool Whether the row was written.
	 */
	public function release( int $id, ?string $nextAttemptAt = null, ?string $errorCode = null, ?string $message = null, bool $keepError = false, ?string $lockToken = null ): bool {
		$data = array(
			'state'           => ItemState::Queued->value,
			'next_attempt_at' => $nextAttemptAt,
			'locked_by'       => null,
			'locked_until'    => null,
			'updated_at'      => $this->now(),
		);
		if ( ! $keepError ) {
			$data['error_code']    = $errorCode;
			$data['error_message'] = $this->clean( $message );
			$data['error_cause']   = null;
		}
		return $this->updateRaw( $id, $data, $lockToken );
	}

	/**
	 * Skip an item that is queued, retrying or failed. It leaves the flow and can be restored.
	 *
	 * @param int    $id     Item id.
	 * @param string $reason Why (shown on hover and in the detail).
	 * @return bool Whether the item was skipped.
	 */
	public function skip( int $id, string $reason ): bool {
		$now = $this->now();
		return $this->run(
			"UPDATE {$this->tables->queue()} SET state = %s, skip_reason = %s, next_attempt_at = NULL, locked_by = NULL, locked_until = NULL, finished_at = %s, updated_at = %s
			WHERE id = %d AND state IN (%s,%s,%s)",
			array( ItemState::Skipped->value, mb_substr( $reason, 0, 191 ), $now, $now, $id, ItemState::Queued->value, ItemState::Retrying->value, ItemState::Failed->value )
		) > 0;
	}

	/**
	 * Put a skipped item back in the queue with a fresh set of tries.
	 *
	 * @param int $id Item id.
	 * @return bool Whether the item was restored.
	 */
	public function restore( int $id ): bool {
		return $this->run(
			"UPDATE {$this->tables->queue()} SET state = %s, attempt = 0, reaps = 0, skip_reason = NULL, next_attempt_at = NULL, error_code = NULL, error_message = NULL, error_cause = NULL, finished_at = NULL, updated_at = %s
			WHERE id = %d AND state = %s",
			array( ItemState::Queued->value, $this->now(), $id, ItemState::Skipped->value )
		) > 0;
	}

	/**
	 * Retry an item now: it returns to queued with a fresh set of tries (QUE-11), whether it was failed or retrying, and
	 * with the count of times the stale lock reaper took it back reset.
	 *
	 * @param int $id Item id.
	 * @return bool Whether the item was moved to queued.
	 */
	public function retry( int $id ): bool {
		return $this->run(
			"UPDATE {$this->tables->queue()} SET attempt = 0, reaps = 0, state = %s, next_attempt_at = NULL, error_code = NULL, error_message = NULL, error_cause = NULL, finished_at = NULL, updated_at = %s
			WHERE id = %d AND state IN (%s,%s)",
			array( ItemState::Queued->value, $this->now(), $id, ItemState::Failed->value, ItemState::Retrying->value )
		) > 0;
	}

	/**
	 * Ids of failed items with one error code and, optionally, one cause (a group on Needs attention), oldest first.
	 *
	 * @param string      $errorCode SYNC-... code.
	 * @param string|null $cause     Cause text, or null for every cause.
	 * @param int         $limit     Most ids in one call.
	 * @param int         $afterId   Only ids above this one, to read a large group in batches.
	 * @return array<int,int>
	 */
	public function failedIdsByGroup( string $errorCode, ?string $cause = null, int $limit = 5000, int $afterId = 0 ): array {
		$sql  = "SELECT id FROM {$this->tables->queue()} WHERE state = %s AND error_code = %s AND id > %d";
		$args = array( ItemState::Failed->value, $errorCode, max( 0, $afterId ) );
		if ( null !== $cause ) {
			$sql   .= ' AND COALESCE(error_cause, %s) = %s';
			$args[] = '';
			$args[] = $cause;
		}
		$args[] = max( 1, $limit );
		$rows   = $this->results( $sql . ' ORDER BY id ASC LIMIT %d', $args );
		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * A domain item by id.
	 *
	 * @param int $id Item id.
	 */
	public function find( int $id ): ?Item {
		$row = $this->row( "SELECT * FROM {$this->tables->queue()} WHERE id = %d", array( $id ) );
		return null === $row ? null : $this->item( $row );
	}

	/**
	 * An item as the `QueueItemDetail` fields that live in the table (the timeline, excerpts and siblings are added by QueueService).
	 *
	 * @param int $id Item id.
	 * @return array<string,mixed>|null
	 */
	public function findRow( int $id ): ?array {
		$row = $this->row(
			'SELECT q.*, ' . $this->waitingExpression() . " AS waiting_for_earlier FROM {$this->tables->queue()} q WHERE q.id = %d",
			array( $id )
		);
		if ( null === $row ) {
			return null;
		}
		return array_merge(
			$this->listShape( $row ),
			array(
				'idempotency_key' => (string) $row['idempotency_key'],
				'partition_key'   => (string) $row['partition_key'],
				'mapping_version' => self::intOrNull( $row['mapping_version'] ),
				'log_id'          => self::intOrNull( $row['last_log_id'] ),
			)
		);
	}

	/**
	 * The newest item of one WooCommerce object, in the `QueueItem` shape (the rows of `query()`).
	 *
	 * Matches `entity_type` and `entity_id` exactly, so it uses the entity index and never misses an item the way a search
	 * on text does. Items of every connection and every state are considered.
	 *
	 * @param EntityType $type     Entity type.
	 * @param string     $entityId WooCommerce id (the SKU for stock).
	 * @return array<string,mixed>|null
	 */
	public function latestForEntity( EntityType $type, string $entityId ): ?array {
		$rows = $this->forEntity( $type, $entityId, 1 );
		return array() === $rows ? null : $rows[0];
	}

	/**
	 * The items of one WooCommerce object, newest id first, in the `QueueItem` shape (the rows of `query()`).
	 *
	 * @param EntityType $type     Entity type.
	 * @param string     $entityId WooCommerce id (the SKU for stock).
	 * @param int        $limit    Most rows, 1 to 200.
	 * @return array<int,array<string,mixed>>
	 */
	public function forEntity( EntityType $type, string $entityId, int $limit = 20 ): array {
		$rows = $this->results(
			'SELECT q.*, ' . $this->waitingExpression() . " AS waiting_for_earlier FROM {$this->tables->queue()} q WHERE q.entity_type = %s AND q.entity_id = %s ORDER BY q.id DESC LIMIT %d",
			array( $type->value, mb_substr( $entityId, 0, 64 ), max( 1, min( 200, $limit ) ) )
		);
		return array_map( array( $this, 'listShape' ), $rows );
	}

	/**
	 * Items of the same order, oldest first (`siblings` of the item detail).
	 *
	 * @param int $id Item id.
	 * @return array<int,array{id:int,event:string,state:string,error_code:?string,created_at:string}>
	 */
	public function siblings( int $id ): array {
		$rows = $this->results(
			"SELECT s.id, s.event, s.state, s.error_code, s.created_at FROM {$this->tables->queue()} s
			INNER JOIN {$this->tables->queue()} q ON q.connection_id = s.connection_id AND q.partition_key = s.partition_key
			WHERE q.id = %d ORDER BY s.id ASC LIMIT 100",
			array( $id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'event'      => (string) $row['event'],
				'state'      => (string) $row['state'],
				'error_code' => self::stringOrNull( $row['error_code'] ),
				'created_at' => (string) Time::iso( (string) $row['created_at'] ),
			),
			$rows
		);
	}

	/**
	 * Record which log entry describes the latest attempt.
	 *
	 * @param int $id    Item id.
	 * @param int $logId Log entry id.
	 */
	public function setLastLog( int $id, int $logId ): void {
		$this->run( "UPDATE {$this->tables->queue()} SET last_log_id = %d WHERE id = %d", array( $logId, $id ) );
	}

	/**
	 * One page of items for the queue screen, newest first.
	 *
	 * @param QueueQuery $query Filters.
	 */
	public function query( QueueQuery $query ): Paged {
		list( $where, $args ) = $this->where( $query );
		$total                = (int) $this->value( "SELECT COUNT(*) FROM {$this->tables->queue()} q WHERE $where", $args );
		$offset               = ( $query->page - 1 ) * $query->perPage;
		$rows                 = $this->results(
			'SELECT q.*, ' . $this->waitingExpression() . " AS waiting_for_earlier FROM {$this->tables->queue()} q WHERE $where ORDER BY q.id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( $query->perPage, $offset ) )
		);
		return new Paged( array_map( array( $this, 'listShape' ), $rows ), $total, $query->page, $query->perPage );
	}

	/**
	 * How many items are in each state.
	 *
	 * @param int|null    $connectionId One connection, or null for all.
	 * @param string|null $mode         live, shadow, or null for both.
	 * @return array<string,int> Every state is present, zero when empty.
	 */
	public function countsByState( ?int $connectionId = null, ?string $mode = 'live' ): array {
		$counts = array();
		foreach ( ItemState::cases() as $state ) {
			$counts[ $state->value ] = 0;
		}
		$parts = array( '1=1' );
		$args  = array();
		if ( null !== $connectionId ) {
			$parts[] = 'connection_id = %d';
			$args[]  = $connectionId;
		}
		if ( null !== $mode ) {
			$parts[] = 'mode = %s';
			$args[]  = $mode;
		}
		$rows = $this->results( "SELECT state, COUNT(*) AS n FROM {$this->tables->queue()} WHERE " . implode( ' AND ', $parts ) . ' GROUP BY state', $args );
		foreach ( $rows as $row ) {
			if ( isset( $counts[ (string) $row['state'] ] ) ) {
				$counts[ (string) $row['state'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Items synced since the start of the store's day.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 */
	public function syncedToday( ?int $connectionId = null ): int {
		$from = Time::sql( DayRange::startOfDay( $this->clock->now(), $this->storeTimezone ) );
		$sql  = "SELECT COUNT(*) FROM {$this->tables->queue()} WHERE state = %s AND mode = %s AND finished_at >= %s";
		$args = array( ItemState::Synced->value, ItemMode::Live->value, $from );
		if ( null !== $connectionId ) {
			$sql   .= ' AND connection_id = %d';
			$args[] = $connectionId;
		}
		return (int) $this->value( $sql, $args );
	}

	/**
	 * Items being sent right now (with a lock that has not run out).
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 */
	public function countSyncing( ?int $connectionId = null ): int {
		$sql  = "SELECT COUNT(*) FROM {$this->tables->queue()} WHERE state = %s AND locked_until >= %s";
		$args = array( ItemState::Syncing->value, $this->now() );
		if ( null !== $connectionId ) {
			$sql   .= ' AND connection_id = %d';
			$args[] = $connectionId;
		}
		return (int) $this->value( $sql, $args );
	}

	/**
	 * Push the lock of an item being sent further out, for work that takes longer than the first lock.
	 *
	 * With `$lockToken` the lock is only pushed while the item is still `syncing` and held by that token, so false means
	 * the lock was lost and the caller must stop working on the item.
	 *
	 * @param int         $id        Item id.
	 * @param int         $seconds   New lock length from now.
	 * @param string|null $lockToken The `workerId` given to claim().
	 * @return bool Whether the lock was extended.
	 */
	public function extendLock( int $id, int $seconds, ?string $lockToken = null ): bool {
		$until = Time::sql( $this->clock->now()->modify( sprintf( '+%d seconds', max( 1, $seconds ) ) ) );
		$sql   = "UPDATE {$this->tables->queue()} SET locked_until = %s WHERE id = %d AND state = %s";
		$args  = array( $until, $id, ItemState::Syncing->value );
		if ( null !== $lockToken ) {
			$sql   .= ' AND locked_by = %s';
			$args[] = $lockToken;
		}
		// An update that sets the value it already has reports 0 rows, so ask the row itself when nothing changed.
		if ( $this->run( $sql, $args ) > 0 ) {
			return true;
		}
		$held = $this->row( "SELECT state, locked_by FROM {$this->tables->queue()} WHERE id = %d", array( $id ) );
		return null !== $held && ItemState::Syncing->value === $held['state'] && ( null === $lockToken || $lockToken === (string) $held['locked_by'] );
	}

	/**
	 * The last 24 hours in hourly buckets for the dashboard chart, oldest first, ending with the current hour.
	 *
	 * @return array<int,array{hour:string,synced:int,failed:int}>
	 */
	public function hourly(): array {
		$now   = $this->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) );
		$last  = $now->setTime( (int) $now->format( 'G' ), 0, 0 );
		$first = $last->modify( '-23 hours' );

		$rows  = $this->results(
			"SELECT DATE_FORMAT(finished_at, '%%Y-%%m-%%d %%H:00:00') AS hour_start, state, COUNT(*) AS n
			FROM {$this->tables->queue()}
			WHERE finished_at >= %s AND state IN (%s,%s) AND mode = %s
			GROUP BY hour_start, state",
			array( Time::sql( $first ), ItemState::Synced->value, ItemState::Failed->value, ItemMode::Live->value )
		);
		$found = array();
		foreach ( $rows as $row ) {
			$found[ (string) $row['hour_start'] ][ (string) $row['state'] ] = (int) $row['n'];
		}

		$buckets = array();
		for ( $i = 0; $i < 24; ++$i ) {
			$hour      = $first->modify( sprintf( '+%d hours', $i ) );
			$key       = $hour->format( Time::SQL );
			$buckets[] = array(
				'hour'   => $hour->format( Time::ISO ),
				'synced' => $found[ $key ][ ItemState::Synced->value ] ?? 0,
				'failed' => $found[ $key ][ ItemState::Failed->value ] ?? 0,
			);
		}
		return $buckets;
	}

	/**
	 * The oldest item still waiting to be sent.
	 *
	 * @return array{at:string,order:string}|null
	 */
	public function oldestWaiting(): ?array {
		$row = $this->row(
			"SELECT created_at, entity_label FROM {$this->tables->queue()} WHERE state IN (%s,%s) AND mode = %s ORDER BY created_at ASC, id ASC LIMIT 1",
			array( ItemState::Queued->value, ItemState::Retrying->value, ItemMode::Live->value )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'at'    => (string) Time::iso( (string) $row['created_at'] ),
			'order' => (string) $row['entity_label'],
		);
	}

	/**
	 * Failed items grouped by error code and cause (`AttentionGroup[]`), biggest group first.
	 *
	 * @return array<int,array{error_code:string,cause:string,count:int,oldest_at:string,item_ids:array<int,int>}>
	 */
	public function attentionGroups(): array {
		$rows   = $this->results(
			"SELECT id, error_code, error_cause, created_at FROM {$this->tables->queue()} WHERE state = %s AND mode = %s ORDER BY id ASC LIMIT 5000",
			array( ItemState::Failed->value, ItemMode::Live->value )
		);
		$groups = array();
		foreach ( $rows as $row ) {
			$code  = (string) ( $row['error_code'] ?? '' );
			$cause = (string) ( $row['error_cause'] ?? '' );
			$key   = $code . "\n" . $cause;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'error_code' => $code,
					'cause'      => $cause,
					'count'      => 0,
					'oldest_at'  => (string) Time::iso( (string) $row['created_at'] ),
					'item_ids'   => array(),
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['item_ids'][] = (int) $row['id'];
		}
		$groups = array_values( $groups );
		usort( $groups, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );
		return $groups;
	}

	/**
	 * Take back items whose lock ran out because the worker died. Each returns to queued without counting a try and
	 * carries SYNC-LOCK-001. An item taken back MAX_REAPS times is a poison item (it keeps killing its worker): it is
	 * stopped as Failed with SYNC-LOCK-001 so a person looks at it, and a person's retry starts the count again.
	 *
	 * Every row is changed by its own guarded statement, and only rows that statement really changed are returned, so two
	 * reapers running together never report (or act on) the same item twice.
	 *
	 * @return array<int,array{id:int,reaps:int,failed:bool}> The items taken back, in id order.
	 */
	public function reapStale(): array {
		$now   = $this->now();
		$rows  = $this->results(
			"SELECT id, reaps, locked_by FROM {$this->tables->queue()} WHERE state = %s AND locked_until IS NOT NULL AND locked_until < %s ORDER BY id ASC LIMIT 500",
			array( ItemState::Syncing->value, $now )
		);
		$taken = array();
		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$reaps  = (int) $row['reaps'] + 1;
			$failed = $reaps >= self::MAX_REAPS;
			$guard  = 'WHERE id = %d AND state = %s AND locked_until < %s AND reaps = %d AND locked_by <=> %s';
			$args   = array( $id, ItemState::Syncing->value, $now, (int) $row['reaps'], null === $row['locked_by'] ? null : (string) $row['locked_by'] );
			if ( null === $args[4] ) {
				$guard = 'WHERE id = %d AND state = %s AND locked_until < %s AND reaps = %d AND locked_by IS NULL';
				array_pop( $args );
			}
			if ( $failed ) {
				$changed = $this->run(
					"UPDATE {$this->tables->queue()} SET state = %s, reaps = %d, locked_by = NULL, locked_until = NULL, next_attempt_at = NULL, error_code = %s, error_message = %s, error_cause = %s, failed_before = 1, finished_at = %s, updated_at = %s $guard",
					array_merge(
						array( ItemState::Failed->value, $reaps, ErrorCode::Lock001->value, $this->clean( self::poisonMessage( $reaps ) ), $this->clean( self::poisonCause( $reaps ), self::CAUSE_LIMIT ), $now, $now ),
						$args
					)
				);
			} else {
				$changed = $this->run(
					"UPDATE {$this->tables->queue()} SET state = %s, reaps = %d, locked_by = NULL, locked_until = NULL, next_attempt_at = NULL, error_code = %s, error_message = %s, updated_at = %s $guard",
					array_merge(
						array( ItemState::Queued->value, $reaps, ErrorCode::Lock001->value, $this->clean( ErrorCode::Lock001->template() ), $now ),
						$args
					)
				);
			}
			if ( $changed > 0 ) {
				$taken[] = array(
					'id'     => $id,
					'reaps'  => $reaps,
					'failed' => $failed,
				);
			}
		}
		return $taken;
	}

	/**
	 * Take back items whose lock ran out. See reapStale().
	 *
	 * @return array<int,int> Ids of the items this call took back.
	 */
	public function reapStaleLockIds(): array {
		return array_map( static fn( array $taken ): int => $taken['id'], $this->reapStale() );
	}

	/**
	 * Take back items whose lock ran out. See reapStale().
	 *
	 * @return int How many.
	 */
	public function reapStaleLocks(): int {
		return count( $this->reapStale() );
	}

	/**
	 * Message of an item stopped as a poison item.
	 *
	 * @param int $reaps How many times it was taken back.
	 */
	public static function poisonMessage( int $reaps ): string {
		return sprintf(
			/* translators: %d: how many times the worker stopped while sending this item. */
			__( 'The sync worker stopped %d times while sending this item, so it was set aside. A person needs to look at it, then retry.', 'estasoft-erp-crm-sync-engine' ),
			$reaps
		);
	}

	/**
	 * Cause text of an item stopped as a poison item (what Needs attention groups by).
	 *
	 * @param int $reaps How many times it was taken back.
	 */
	public static function poisonCause( int $reaps ): string {
		/* translators: %d: how many times the worker stopped. */
		return sprintf( __( 'Worker stopped %d times', 'estasoft-erp-crm-sync-engine' ), $reaps );
	}

	/**
	 * Delete finished items after their retention time (spec 5.2): synced after 30 days, skipped after 90, synced
	 * items that once failed after 90 days. Failed items stay until someone resolves them.
	 *
	 * @return int Rows deleted.
	 */
	public function prune(): int {
		$now    = $this->clock->now();
		$short  = Time::sql( $now->modify( sprintf( '-%d days', self::KEEP_SYNCED_DAYS ) ) );
		$long   = Time::sql( $now->modify( sprintf( '-%d days', self::KEEP_LONG_DAYS ) ) );
		$total  = 0;
		$passes = array(
			array( 'state = %s AND failed_before = 0 AND finished_at < %s', array( ItemState::Synced->value, $short ) ),
			array( 'state = %s AND failed_before = 1 AND finished_at < %s', array( ItemState::Synced->value, $long ) ),
			array( 'state = %s AND updated_at < %s', array( ItemState::Skipped->value, $long ) ),
		);
		foreach ( $passes as $pass ) {
			for ( $i = 0; $i < 200; ++$i ) {
				$deleted = $this->run( "DELETE FROM {$this->tables->queue()} WHERE {$pass[0]} LIMIT %d", array_merge( $pass[1], array( self::PRUNE_BATCH ) ) );
				$total  += $deleted;
				if ( $deleted < self::PRUNE_BATCH ) {
					break;
				}
			}
		}
		return $total;
	}

	/**
	 * Delete the waiting items (queued and retrying) of connections that were disconnected more than `$days` ago (CON-9).
	 * Mapping versions and the id map are not touched; the connection row stays too. Items that finished, failed or were
	 * skipped follow their usual retention times.
	 *
	 * @param int $days Days after the disconnect.
	 * @return int Rows deleted.
	 */
	public function pruneDisconnected( int $days = 30 ): int {
		$cutoff = Time::sql( $this->clock->now()->modify( sprintf( '-%d days', max( 1, $days ) ) ) );
		$total  = 0;
		for ( $i = 0; $i < 200; ++$i ) {
			$rows = $this->results(
				"SELECT q.id FROM {$this->tables->queue()} q INNER JOIN {$this->tables->connections()} c ON c.id = q.connection_id
				WHERE c.status = %s AND COALESCE(c.disconnected_at, c.updated_at) < %s AND q.state IN (%s,%s) ORDER BY q.id ASC LIMIT %d",
				array( ConnectionStatus::Disconnected->value, $cutoff, ItemState::Queued->value, ItemState::Retrying->value, self::PRUNE_BATCH )
			);
			if ( array() === $rows ) {
				break;
			}
			$ids     = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
			$deleted = $this->run(
				"DELETE FROM {$this->tables->queue()} WHERE id IN (" . $this->placeholders( $ids ) . ') AND state IN (%s,%s)',
				array_merge( $ids, array( ItemState::Queued->value, ItemState::Retrying->value ) )
			);
			$total  += $deleted;
			if ( count( $ids ) < self::PRUNE_BATCH || 0 === $deleted ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * SQL for the states that block later items of the same partition. Built from the enum, never from input.
	 */
	private function blockingStates(): string {
		$values = array();
		foreach ( ItemState::cases() as $state ) {
			if ( $state->blocksLaterItems() ) {
				$values[] = "'" . $state->value . "'";
			}
		}
		return implode( ',', $values );
	}

	/**
	 * SQL expression: is an earlier item of the same order unfinished or failed? Alias q is the outer row.
	 */
	private function waitingExpression(): string {
		return "EXISTS (SELECT 1 FROM {$this->tables->queue()} w WHERE w.connection_id = q.connection_id AND w.partition_key = q.partition_key AND w.id < q.id AND w.state IN (" . $this->blockingStates() . '))';
	}

	/**
	 * Expressions for the attempt column.
	 */
	private function attemptPlusOne(): \stdClass {
		return (object) array( 'raw' => 'attempt + 1' );
	}

	/**
	 * Keep the attempt column as it is.
	 */
	private function attemptSame(): \stdClass {
		return (object) array( 'raw' => 'attempt' );
	}

	/**
	 * Update one row where some values are raw SQL expressions (objects with a `raw` property).
	 *
	 * @param int                 $id        Item id.
	 * @param array<string,mixed> $data      Column to value; null becomes NULL.
	 * @param string|null         $lockToken When given, the row is changed only while it is `syncing` and held by this token.
	 * @return bool Whether a row was changed.
	 */
	private function updateRaw( int $id, array $data, ?string $lockToken = null ): bool {
		$sets = array();
		$args = array();
		foreach ( $data as $column => $value ) {
			if ( $value instanceof \stdClass ) {
				$sets[] = "$column = " . $value->raw;
			} elseif ( null === $value ) {
				$sets[] = "$column = NULL";
			} else {
				$sets[] = "$column = %s";
				$args[] = (string) $value;
			}
		}
		$where  = 'WHERE id = %d';
		$args[] = $id;
		if ( null !== $lockToken ) {
			$where .= ' AND state = %s AND locked_by = %s';
			array_push( $args, ItemState::Syncing->value, $lockToken );
		}
		return $this->run( "UPDATE {$this->tables->queue()} SET " . implode( ', ', $sets ) . " $where", $args ) > 0;
	}

	/**
	 * Columns for the display values.
	 *
	 * @param array<string,mixed> $display label, customer, total_minor, currency, search.
	 * @return array<string,mixed>
	 */
	private function displayColumns( array $display ): array {
		$columns = array();
		if ( isset( $display['customer'] ) ) {
			$columns['customer_name'] = mb_substr( (string) $display['customer'], 0, 191 );
		}
		if ( isset( $display['total_minor'] ) ) {
			$columns['total_minor'] = (int) $display['total_minor'];
			$columns['currency']    = isset( $display['currency'] ) ? strtoupper( substr( (string) $display['currency'], 0, 3 ) ) : null;
		}
		if ( isset( $display['search'] ) ) {
			$columns['search_text'] = mb_substr( is_array( $display['search'] ) ? implode( ' ', array_map( 'strval', $display['search'] ) ) : (string) $display['search'], 0, 500 );
		}
		return $columns;
	}

	/**
	 * WHERE clause and arguments for a list query. The outer table alias is q.
	 *
	 * @param QueueQuery $query Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function where( QueueQuery $query ): array {
		$parts = array( '1=1' );
		$args  = array();

		if ( null !== $query->state && 'all' !== $query->state ) {
			if ( 'waiting' === $query->state ) {
				$parts[] = 'q.state IN (%s,%s,%s)';
				array_push( $args, ItemState::Queued->value, ItemState::Syncing->value, ItemState::Retrying->value );
			} else {
				$parts[] = 'q.state = %s';
				$args[]  = $query->state;
			}
		}
		if ( null !== $query->entityType ) {
			$parts[] = 'q.entity_type = %s';
			$args[]  = $query->entityType;
		}
		if ( null !== $query->errorCode ) {
			$parts[] = 'q.error_code = %s';
			$args[]  = $query->errorCode;
		}
		if ( null !== $query->connectionId ) {
			$parts[] = 'q.connection_id = %d';
			$args[]  = $query->connectionId;
		}
		if ( null !== $query->mode ) {
			$parts[] = 'q.mode = %s';
			$args[]  = $query->mode;
		}
		if ( null !== $query->day ) {
			$bounds = DayRange::bounds( $query->day, $this->clock->now(), $this->storeTimezone );
			if ( null !== $bounds ) {
				$parts[] = 'q.created_at >= %s';
				$args[]  = $bounds[0];
				if ( null !== $bounds[1] ) {
					$parts[] = 'q.created_at < %s';
					$args[]  = $bounds[1];
				}
			}
		}
		if ( null !== $query->search && '' !== ltrim( $query->search, '#' ) ) {
			$like    = $this->like( ltrim( $query->search, '#' ) );
			$parts[] = '(q.entity_label LIKE %s OR q.entity_id LIKE %s OR q.external_ref LIKE %s OR q.external_id LIKE %s OR q.customer_name LIKE %s OR q.search_text LIKE %s OR q.error_cause LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like, $like, $like );
		}
		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * The `QueueItem` shape of a row.
	 *
	 * @param array<string,mixed> $row Raw row with waiting_for_earlier.
	 * @return array<string,mixed>
	 */
	private function listShape( array $row ): array {
		$total = null;
		if ( null !== $row['total_minor'] ) {
			$total = array(
				'minor'    => (int) $row['total_minor'],
				'currency' => (string) ( $row['currency'] ?? '' ),
			);
		}
		return array(
			'id'                  => (int) $row['id'],
			'connection_id'       => (int) $row['connection_id'],
			'entity_type'         => (string) $row['entity_type'],
			'entity_id'           => (string) $row['entity_id'],
			'entity_label'        => (string) $row['entity_label'],
			'event'               => (string) $row['event'],
			'state'               => (string) $row['state'],
			'mode'                => (string) $row['mode'],
			'attempt'             => (int) $row['attempt'],
			'max_attempts'        => (int) $row['max_attempts'],
			'next_attempt_at'     => Time::iso( self::stringOrNull( $row['next_attempt_at'] ) ),
			'error_code'          => self::stringOrNull( $row['error_code'] ),
			'error_message'       => self::stringOrNull( $row['error_message'] ),
			'external_id'         => self::stringOrNull( $row['external_id'] ),
			'external_ref'        => self::stringOrNull( $row['external_ref'] ),
			'external_url'        => self::stringOrNull( $row['external_url'] ),
			'skip_reason'         => self::stringOrNull( $row['skip_reason'] ),
			'customer_name'       => self::stringOrNull( $row['customer_name'] ),
			'total'               => $total,
			'waiting_for_earlier' => (bool) ( $row['waiting_for_earlier'] ?? false ),
			'created_at'          => (string) Time::iso( (string) $row['created_at'] ),
			'updated_at'          => (string) Time::iso( (string) $row['updated_at'] ),
			'finished_at'         => Time::iso( self::stringOrNull( $row['finished_at'] ) ),
			'order_edit_url'      => null,
		);
	}

	/**
	 * Build the domain object.
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	private function item( array $row ): Item {
		return new Item(
			(int) $row['id'],
			(int) $row['connection_id'],
			EntityType::tryFrom( (string) $row['entity_type'] ) ?? EntityType::Order,
			(string) $row['entity_id'],
			(string) $row['event'],
			(string) $row['partition_key'],
			(string) $row['idempotency_key'],
			ItemState::tryFrom( (string) $row['state'] ) ?? ItemState::Queued,
			ItemMode::tryFrom( (string) $row['mode'] ) ?? ItemMode::Live,
			(int) $row['attempt'],
			(int) $row['max_attempts'],
			self::intOrNull( $row['mapping_version'] ),
			self::stringOrNull( $row['error_code'] ),
			self::stringOrNull( $row['external_id'] ),
			self::stringOrNull( $row['external_ref'] )
		);
	}
}
