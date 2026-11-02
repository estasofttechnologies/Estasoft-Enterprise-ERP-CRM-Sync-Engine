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
	private const CLAIM_ATTEMPTS = 3;

	/**
	 * @param \wpdb         $wpdb          Database.
	 * @param Tables        $tables        Table names.
	 * @param Clock         $clock         Time source.
	 * @param \DateTimeZone $storeTimezone Store time zone, for the day filters.
	 */
	public function __construct(
		\wpdb $wpdb,
		Tables $tables,
		Clock $clock,
		private readonly \DateTimeZone $storeTimezone
	) {
		parent::__construct( $wpdb, $tables, $clock );
	}

	/**
	 * Add an item. Meant to run inside the order's own database transaction: it only inserts one row.
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
	 * @return int The new id, or 0 when the row could not be written.
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
		array $display = array()
	): int {
		$now = $this->now();
		return $this->insert(
			$this->tables->queue(),
			array_merge(
				array(
					'connection_id'  => $connectionId,
					'entity_type'    => $type->value,
					'entity_id'      => mb_substr( $entityId, 0, 64 ),
					'entity_label'   => mb_substr( (string) ( $display['label'] ?? $entityId ), 0, 191 ),
					'event'          => mb_substr( $event, 0, 32 ),
					'partition_key'  => mb_substr( $partitionKey, 0, 100 ),
					'idempotency_key' => $idempotencyKey,
					'state'          => ItemState::Queued->value,
					'mode'           => $mode->value,
					'attempt'        => 0,
					'max_attempts'   => max( 1, $maxAttempts ),
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				$this->displayColumns( $display )
			)
		);
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
	 * @param int      $limit        Most items to claim.
	 * @param int      $lockSeconds  How long the lock lasts before the reaper may take it back.
	 * @param string   $workerId     Unique id of the caller (a UUID per run).
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
		$sql   .= " AND NOT EXISTS (
				SELECT 1 FROM {$this->tables->queue()} e
				WHERE e.connection_id = q.connection_id AND e.partition_key = q.partition_key AND e.id < q.id AND e.state IN ($block)
			)
			ORDER BY q.id ASC LIMIT %d";
		$args[] = $limit;

		// Another worker may take every candidate between our SELECT and our UPDATE. Look again a few times before giving up.
		$ids          = array();
		$placeholders = '';
		$updated      = 0;
		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS && 0 === $updated; ++$attempt ) {
			$ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->results( $sql, $args ) );
			if ( array() === $ids ) {
				return array();
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
	 * Record a successful send (or a link to a record that already existed).
	 *
	 * @param int         $id             Item id.
	 * @param string      $externalId     The system's id.
	 * @param string      $externalRef    The system's human number.
	 * @param string|null $externalUrl    Link to the record.
	 * @param int|null    $mappingVersion Mapping version used.
	 * @param string|null $noteCode       A handled code to keep on the row, for example SYNC-DUP-001.
	 * @param bool        $countTry       Whether this counts as a try.
	 * @param string|null $noteMessage    The message for that code with its placeholders filled in; the catalog text when null.
	 */
	public function markSynced( int $id, string $externalId, string $externalRef, ?string $externalUrl = null, ?int $mappingVersion = null, ?string $noteCode = null, bool $countTry = true, ?string $noteMessage = null ): void {
		$now  = $this->now();
		$note = null === $noteCode ? null : ErrorCode::tryFrom( $noteCode );
		$this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Synced->value,
				'attempt'         => $countTry ? $this->attemptPlusOne() : $this->attemptSame(),
				'external_id'     => '' === $externalId ? null : $externalId,
				'external_ref'    => '' === $externalRef ? null : $externalRef,
				'external_url'    => $externalUrl,
				'mapping_version' => $mappingVersion,
				'error_code'      => $noteCode,
				'error_message'   => $noteMessage ?? $note?->template(),
				'error_cause'     => null,
				'next_attempt_at' => null,
				'locked_by'       => null,
				'locked_until'    => null,
				'finished_at'     => $now,
				'updated_at'      => $now,
			)
		);
	}

	/**
	 * Record a retryable failure: the item waits for its next try.
	 *
	 * @param int    $id            Item id.
	 * @param string $nextAttemptAt MySQL datetime (UTC).
	 * @param string $errorCode     SYNC-... code.
	 * @param string $message       Message with placeholders filled (no secrets, no personal data).
	 * @param string $cause         Short cause such as "SKU CS-LAMP-07".
	 */
	public function markRetrying( int $id, string $nextAttemptAt, string $errorCode, string $message, string $cause = '' ): void {
		$this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Retrying->value,
				'attempt'         => $this->attemptPlusOne(),
				'next_attempt_at' => $nextAttemptAt,
				'error_code'      => $errorCode,
				'error_message'   => $message,
				'error_cause'     => '' === $cause ? null : $cause,
				'locked_by'       => null,
				'locked_until'    => null,
				'updated_at'      => $this->now(),
			)
		);
	}

	/**
	 * Record a failure that retrying cannot fix: a person must act.
	 *
	 * @param int    $id        Item id.
	 * @param string $errorCode SYNC-... code.
	 * @param string $message   Message with placeholders filled.
	 * @param string $cause     Short cause such as "SKU CS-LAMP-07".
	 * @param bool   $countTry  Whether this counts as a try (errors found before sending do not).
	 */
	public function markFailed( int $id, string $errorCode, string $message, string $cause = '', bool $countTry = true ): void {
		$now = $this->now();
		$this->updateRaw(
			$id,
			array(
				'state'           => ItemState::Failed->value,
				'attempt'         => $countTry ? $this->attemptPlusOne() : $this->attemptSame(),
				'next_attempt_at' => null,
				'error_code'      => $errorCode,
				'error_message'   => $message,
				'error_cause'     => '' === $cause ? null : $cause,
				'failed_before'   => 1,
				'locked_by'       => null,
				'locked_until'    => null,
				'finished_at'     => $now,
				'updated_at'      => $now,
			)
		);
	}

	/**
	 * Give a claimed item back without counting a try (paused connection, missing connector, rate limit window).
	 *
	 * @param int         $id            Item id.
	 * @param string|null $nextAttemptAt Earliest next claim, or null for now.
	 * @param string|null $errorCode     Code to show, or null to clear.
	 * @param string|null $message       Message to show.
	 * @param bool        $keepError     Leave the error columns as they are.
	 */
	public function release( int $id, ?string $nextAttemptAt = null, ?string $errorCode = null, ?string $message = null, bool $keepError = false ): void {
		$data = array(
			'state'           => ItemState::Queued->value,
			'next_attempt_at' => $nextAttemptAt,
			'locked_by'       => null,
			'locked_until'    => null,
			'updated_at'      => $this->now(),
		);
		if ( ! $keepError ) {
			$data['error_code']    = $errorCode;
			$data['error_message'] = $message;
			$data['error_cause']   = null;
		}
		$this->updateRaw( $id, $data );
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
			"UPDATE {$this->tables->queue()} SET state = %s, attempt = 0, skip_reason = NULL, next_attempt_at = NULL, error_code = NULL, error_message = NULL, error_cause = NULL, finished_at = NULL, updated_at = %s
			WHERE id = %d AND state = %s",
			array( ItemState::Queued->value, $this->now(), $id, ItemState::Skipped->value )
		) > 0;
	}

	/**
	 * Retry an item now. A failed item gets a fresh set of tries; a retrying item keeps its count.
	 *
	 * @param int $id Item id.
	 * @return bool Whether the item was moved to queued.
	 */
	public function retry( int $id ): bool {
		// The attempt assignment comes first: MySQL applies assignments left to right, so it still sees the old state.
		return $this->run(
			"UPDATE {$this->tables->queue()} SET attempt = CASE WHEN state = %s THEN 0 ELSE attempt END, state = %s, next_attempt_at = NULL, error_code = NULL, error_message = NULL, error_cause = NULL, finished_at = NULL, updated_at = %s
			WHERE id = %d AND state IN (%s,%s)",
			array( ItemState::Failed->value, ItemState::Queued->value, $this->now(), $id, ItemState::Failed->value, ItemState::Retrying->value )
		) > 0;
	}

	/**
	 * Ids of failed items with one error code and, optionally, one cause (a group on Needs attention).
	 *
	 * @param string      $errorCode SYNC-... code.
	 * @param string|null $cause     Cause text, or null for every cause.
	 * @return array<int,int>
	 */
	public function failedIdsByGroup( string $errorCode, ?string $cause = null ): array {
		$sql  = "SELECT id FROM {$this->tables->queue()} WHERE state = %s AND error_code = %s";
		$args = array( ItemState::Failed->value, $errorCode );
		if ( null !== $cause ) {
			$sql   .= ' AND COALESCE(error_cause, %s) = %s';
			$args[] = '';
			$args[] = $cause;
		}
		$rows = $this->results( $sql . ' ORDER BY id ASC LIMIT 5000', $args );
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
			"SELECT q.*, " . $this->waitingExpression() . " AS waiting_for_earlier FROM {$this->tables->queue()} q WHERE q.id = %d",
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
			"SELECT q.*, " . $this->waitingExpression() . " AS waiting_for_earlier FROM {$this->tables->queue()} q WHERE $where ORDER BY q.id DESC LIMIT %d OFFSET %d",
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
	 * @param int $id      Item id.
	 * @param int $seconds New lock length from now.
	 */
	public function extendLock( int $id, int $seconds ): void {
		$until = Time::sql( $this->clock->now()->modify( sprintf( '+%d seconds', max( 1, $seconds ) ) ) );
		$this->run(
			"UPDATE {$this->tables->queue()} SET locked_until = %s WHERE id = %d AND state = %s",
			array( $until, $id, ItemState::Syncing->value )
		);
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

		$rows = $this->results(
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
	 * Take back items whose lock ran out because the worker died. They return to queued without counting a try
	 * and carry SYNC-LOCK-001.
	 *
	 * @return array<int,int> Ids of the items taken back.
	 */
	public function reapStaleLockIds(): array {
		$now  = $this->now();
		$rows = $this->results(
			"SELECT id FROM {$this->tables->queue()} WHERE state = %s AND locked_until IS NOT NULL AND locked_until < %s ORDER BY id ASC LIMIT 500",
			array( ItemState::Syncing->value, $now )
		);
		$ids  = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = $this->placeholders( $ids );
		$this->run(
			"UPDATE {$this->tables->queue()} SET state = %s, locked_by = NULL, locked_until = NULL, next_attempt_at = NULL, error_code = %s, error_message = %s, updated_at = %s
			WHERE id IN ($placeholders) AND state = %s AND locked_until < %s",
			array_merge(
				array( ItemState::Queued->value, ErrorCode::Lock001->value, ErrorCode::Lock001->template(), $now ),
				$ids,
				array( ItemState::Syncing->value, $now )
			)
		);
		return $ids;
	}

	/**
	 * Take back items whose lock ran out. See reapStaleLockIds().
	 *
	 * @return int How many.
	 */
	public function reapStaleLocks(): int {
		return count( $this->reapStaleLockIds() );
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
			array( "state = %s AND failed_before = 0 AND finished_at < %s", array( ItemState::Synced->value, $short ) ),
			array( "state = %s AND failed_before = 1 AND finished_at < %s", array( ItemState::Synced->value, $long ) ),
			array( "state = %s AND updated_at < %s", array( ItemState::Skipped->value, $long ) ),
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
	 * @param int                 $id   Item id.
	 * @param array<string,mixed> $data Column to value; null becomes NULL.
	 */
	private function updateRaw( int $id, array $data ): void {
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
		$args[] = $id;
		$this->run( "UPDATE {$this->tables->queue()} SET " . implode( ', ', $sets ) . ' WHERE id = %d', $args );
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
