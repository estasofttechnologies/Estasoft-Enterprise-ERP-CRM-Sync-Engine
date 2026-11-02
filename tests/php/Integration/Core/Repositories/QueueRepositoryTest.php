<?php
/**
 * Queue states, lists, counts, the reaper query and retention.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class QueueRepositoryTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
	}

	/**
	 * Set columns of a queue row.
	 *
	 * @param int                 $id      Queue id.
	 * @param array<string,mixed> $columns Column to value (null for NULL).
	 */
	private function set( int $id, array $columns ): void {
		$sets = array();
		foreach ( $columns as $name => $value ) {
			$sets[] = "`$name` = " . ( null === $value ? 'NULL' : $this->h->wpdb->prepare( '%s', $value ) );
		}
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET " . implode( ', ', $sets ) . " WHERE id = {$id}" ); // phpcs:ignore
	}

	/**
	 * Run a query and return the ids.
	 *
	 * @param array<string,mixed> $args QueueQuery arguments.
	 * @return array<int,int>
	 */
	private function ids( array $args ): array {
		return array_column( $this->h->queue->query( QueueQuery::fromArray( $args ) )->items, 'id' );
	}

	public function test_a_new_item_is_queued_with_its_display_values(): void {
		$id = $this->h->item(
			$this->conn,
			'10490',
			'created',
			null,
			ItemMode::Live,
			array(
				'label'       => '10490',
				'customer'    => 'Noor Al-Mansouri',
				'total_minor' => 43800,
				'currency'    => 'aed',
				'search'      => array( 'CS-BOWL-04', 'noor@example.com' ),
			)
		);
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 'live', $row['mode'] );
		$this->assertSame( '10490', $row['entity_label'] );
		$this->assertSame( 'Noor Al-Mansouri', $row['customer_name'] );
		$this->assertSame( array( 'minor' => 43800, 'currency' => 'AED' ), $row['total'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 5, $row['max_attempts'] );
		$this->assertSame( 'order:10490', $row['partition_key'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $row['idempotency_key'] );
		$this->assertFalse( $row['waiting_for_earlier'] );
		$this->assertNull( $row['order_edit_url'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $row['created_at'] );
		$this->assertNull( $row['finished_at'] );
	}

	public function test_a_list_row_has_every_field_of_queue_item_in_types_ts(): void {
		$id   = $this->h->item( $this->conn );
		$keys = array( 'id', 'connection_id', 'entity_type', 'entity_id', 'entity_label', 'event', 'state', 'mode', 'attempt', 'max_attempts', 'next_attempt_at', 'error_code', 'error_message', 'external_id', 'external_ref', 'external_url', 'skip_reason', 'customer_name', 'total', 'waiting_for_earlier', 'created_at', 'updated_at', 'finished_at', 'order_edit_url' );
		$row  = $this->h->queue->query( QueueQuery::fromArray( array() ) )->items[0];
		$this->assertSame( $id, $row['id'] );
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $row, $key );
		}
		$this->assertSame( array(), array_diff( array_keys( $row ), $keys ) );
	}

	public function test_marking_synced_records_the_system_numbers_and_clears_the_error(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->markRetrying( $id, '2026-11-02 12:01:00', 'SYNC-NET-001', 'Couldn’t reach Fake.', '' );
		$this->h->clock->advance( 61 );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->clock->advance( 2 );
		$this->h->queue->markSynced( $id, '1001', 'S90001', 'https://erp.example.test/o/1001', 7 );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( 2, $row['attempt'] );
		$this->assertSame( '1001', $row['external_id'] );
		$this->assertSame( 'S90001', $row['external_ref'] );
		$this->assertSame( 'https://erp.example.test/o/1001', $row['external_url'] );
		$this->assertSame( 7, $row['mapping_version'] );
		$this->assertNull( $row['error_code'] );
		$this->assertNull( $row['next_attempt_at'] );
		$this->assertSame( '2026-11-02T12:01:03Z', $row['finished_at'] );
		$this->assertNull( $this->h->row( $id )['locked_by'] );
		$this->assertNull( $this->h->row( $id )['locked_until'] );
	}

	public function test_linking_an_existing_record_keeps_the_handled_code(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->markSynced( $id, '5', 'S5', null, 3, 'SYNC-DUP-001' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'SYNC-DUP-001', $row['error_code'] );
		$this->assertStringContainsString( 'already has this order', (string) $row['error_message'] );
	}

	public function test_marking_synced_without_counting_a_try(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->markSynced( $id, '5', 'S5', null, null, null, false );
		$this->assertSame( 0, $this->h->queue->findRow( $id )['attempt'] );
	}

	public function test_a_retryable_failure_sets_the_next_try_and_counts_the_attempt(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->markRetrying( $id, '2026-11-02 12:01:10', 'SYNC-NET-001', 'Couldn’t reach Fake, or it didn’t answer within 30 seconds.', 'x' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'retrying', $row['state'] );
		$this->assertSame( 1, $row['attempt'] );
		$this->assertSame( '2026-11-02T12:01:10Z', $row['next_attempt_at'] );
		$this->assertSame( 'SYNC-NET-001', $row['error_code'] );
		$this->assertNull( $row['finished_at'] );
		$this->assertNull( $this->h->row( $id )['locked_by'] );
	}

	public function test_a_failure_that_needs_a_person_is_final_and_remembered_as_having_failed(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', 'Product CS-LAMP-07 was not found in Fake.', 'SKU CS-LAMP-07' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 1, $row['attempt'] );
		$this->assertNotNull( $row['finished_at'] );
		$this->assertSame( '1', (string) $this->h->row( $id )['failed_before'] );
		$this->assertSame( 'SKU CS-LAMP-07', $this->h->row( $id )['error_cause'] );

		$other = $this->h->item( $this->conn, '2' );
		$this->h->queue->markFailed( $other, 'SYNC-MAP-003', 'x', '', false );
		$this->assertSame( 0, $this->h->queue->findRow( $other )['attempt'], 'an error found before sending is not a try' );
	}

	public function test_releasing_gives_the_item_back_without_a_try(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->h->queue->release( $id, '2026-11-02 12:05:00', 'SYNC-PAUSE-001', 'Syncing is paused. Items wait in the queue.' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 'SYNC-PAUSE-001', $row['error_code'] );
		$this->assertSame( '2026-11-02T12:05:00Z', $row['next_attempt_at'] );

		$this->h->queue->release( $id, null, null, null, true );
		$this->assertSame( 'SYNC-PAUSE-001', $this->h->queue->findRow( $id )['error_code'], 'keepError leaves the message' );
		$this->h->queue->release( $id );
		$this->assertNull( $this->h->queue->findRow( $id )['error_code'] );
	}

	public function test_skip_works_from_queued_retrying_and_failed_only(): void {
		$queued   = $this->h->item( $this->conn, '1' );
		$retrying = $this->h->item( $this->conn, '2' );
		$failed   = $this->h->item( $this->conn, '3' );
		$synced   = $this->h->item( $this->conn, '4' );
		$syncing  = $this->h->item( $this->conn, '5' );
		$this->h->queue->markRetrying( $retrying, '2026-11-02 13:00:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->markFailed( $failed, 'SYNC-VAL-002', 'x' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$this->set( $syncing, array( 'state' => 'syncing' ) );

		$this->assertTrue( $this->h->queue->skip( $queued, 'Entered by hand' ) );
		$this->assertTrue( $this->h->queue->skip( $retrying, 'Entered by hand' ) );
		$this->assertTrue( $this->h->queue->skip( $failed, 'Entered by hand' ) );
		$this->assertFalse( $this->h->queue->skip( $synced, 'no' ) );
		$this->assertFalse( $this->h->queue->skip( $syncing, 'no' ) );
		$this->assertFalse( $this->h->queue->skip( $queued, 'twice' ), 'already skipped' );

		$row = $this->h->queue->findRow( $queued );
		$this->assertSame( 'skipped', $row['state'] );
		$this->assertSame( 'Entered by hand', $row['skip_reason'] );
		$this->assertNotNull( $row['finished_at'] );
	}

	public function test_restore_puts_a_skipped_item_back_with_fresh_tries(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->markFailed( $id, 'SYNC-VAL-002', 'x' );
		$this->h->queue->skip( $id, 'later' );
		$this->assertTrue( $this->h->queue->restore( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertNull( $row['skip_reason'] );
		$this->assertNull( $row['error_code'] );
		$this->assertNull( $row['finished_at'] );
		$this->assertFalse( $this->h->queue->restore( $id ), 'only a skipped item can be restored' );
	}

	public function test_retry_resets_tries_of_a_failed_item_and_of_a_retrying_one(): void {
		$failed   = $this->h->item( $this->conn, '1' );
		$retrying = $this->h->item( $this->conn, '2' );
		$synced   = $this->h->item( $this->conn, '3' );
		$queued   = $this->h->item( $this->conn, '4' );
		$this->set( $failed, array( 'attempt' => 5 ) );
		$this->h->queue->markFailed( $failed, 'SYNC-VAL-002', 'x', 'c', false );
		$this->set( $retrying, array( 'attempt' => 3 ) );
		$this->h->queue->markRetrying( $retrying, '2026-11-02 18:00:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );

		$this->assertTrue( $this->h->queue->retry( $failed ) );
		$this->assertTrue( $this->h->queue->retry( $retrying ) );
		$this->assertFalse( $this->h->queue->retry( $synced ) );
		$this->assertFalse( $this->h->queue->retry( $queued ) );

		$f = $this->h->queue->findRow( $failed );
		$this->assertSame( 'queued', $f['state'] );
		$this->assertSame( 0, $f['attempt'] );
		$this->assertNull( $f['error_code'] );
		$this->assertNull( $f['finished_at'] );
		$r = $this->h->queue->findRow( $retrying );
		$this->assertSame( 'queued', $r['state'] );
		$this->assertSame( 0, $r['attempt'], 'QUE-11: a person\'s retry gives a fresh set of tries, also for an item that was waiting for its next try' );
		$this->assertNull( $r['next_attempt_at'], 'retry now means now' );
	}

	public function test_failed_ids_by_group(): void {
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$c = $this->h->item( $this->conn, '3' );
		$d = $this->h->item( $this->conn, '4' );
		$this->h->queue->markFailed( $a, 'SYNC-MAP-001', 'x', 'SKU A' );
		$this->h->queue->markFailed( $b, 'SYNC-MAP-001', 'x', 'SKU A' );
		$this->h->queue->markFailed( $c, 'SYNC-MAP-001', 'x', 'SKU B' );
		$this->h->queue->markFailed( $d, 'SYNC-VAL-002', 'x', 'SKU A' );
		$this->assertSame( array( $a, $b ), $this->h->queue->failedIdsByGroup( 'SYNC-MAP-001', 'SKU A' ) );
		$this->assertSame( array( $a, $b, $c ), $this->h->queue->failedIdsByGroup( 'SYNC-MAP-001' ) );
		$this->assertSame( array(), $this->h->queue->failedIdsByGroup( 'SYNC-MAP-002' ) );
	}

	public function test_filter_by_state_including_waiting(): void {
		$queued   = $this->h->item( $this->conn, '1' );
		$retrying = $this->h->item( $this->conn, '2' );
		$failed   = $this->h->item( $this->conn, '3' );
		$synced   = $this->h->item( $this->conn, '4' );
		$skipped  = $this->h->item( $this->conn, '5' );
		$syncing  = $this->h->item( $this->conn, '6' );
		$this->h->queue->markRetrying( $retrying, '2026-11-02 13:00:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->markFailed( $failed, 'SYNC-VAL-002', 'x' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$this->h->queue->skip( $skipped, 'r' );
		$this->set( $syncing, array( 'state' => 'syncing' ) );

		$this->assertSame( array( $syncing, $retrying, $queued ), $this->ids( array( 'state' => 'waiting' ) ) );
		$this->assertSame( array( $failed ), $this->ids( array( 'state' => 'failed' ) ) );
		$this->assertSame( array( $synced ), $this->ids( array( 'state' => 'synced' ) ) );
		$this->assertSame( array( $skipped ), $this->ids( array( 'state' => 'skipped' ) ) );
		$this->assertCount( 6, $this->ids( array( 'state' => 'all' ) ) );
		$this->assertCount( 6, $this->ids( array() ) );
		$this->assertSame( array( $failed ), $this->ids( array( 'error_code' => 'SYNC-VAL-002' ) ) );
	}

	public function test_search_finds_order_number_sku_email_customer_and_system_number(): void {
		$a = $this->h->item( $this->conn, '10490', 'created', null, ItemMode::Live, array( 'label' => '10490', 'customer' => 'Noor Al-Mansouri', 'search' => 'CS-BOWL-04 noor@example.com' ) );
		$b = $this->h->item( $this->conn, '10491', 'created', null, ItemMode::Live, array( 'label' => '10491', 'customer' => 'Sam Carter', 'search' => 'CS-LAMP-07 sam@example.org' ) );
		$this->h->queue->markSynced( $b, '77', 'S00491' );

		$this->assertSame( array( $a ), $this->ids( array( 'search' => '10490' ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'search' => '#10490' ) ) );
		$this->assertSame( array( $b ), $this->ids( array( 'search' => 'CS-LAMP' ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'search' => 'noor@example.com' ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'search' => 'Mansouri' ) ) );
		$this->assertSame( array( $b ), $this->ids( array( 'search' => 'S00491' ) ) );
		$this->assertSame( array(), $this->ids( array( 'search' => 'nothing like this' ) ) );
		$this->assertCount( 2, $this->ids( array( 'search' => '1049' ) ) );
	}

	public function test_search_treats_percent_and_underscore_as_plain_text(): void {
		$this->h->item( $this->conn, '10490', 'created', null, ItemMode::Live, array( 'label' => '10490' ) );
		$this->assertSame( array(), $this->ids( array( 'search' => '%' ) ) );
		$this->assertSame( array(), $this->ids( array( 'search' => '1049_' ) ) );
		$this->assertSame( array(), $this->ids( array( 'search' => "x' OR '1'='1" ) ) );
	}

	public function test_day_filters_use_store_time(): void {
		// The harness clock is 12:00 UTC on 2 Nov, which is 16:00 in Dubai (UTC+4). Dubai's day began at 20:00 UTC on 1 Nov.
		$today      = $this->h->item( $this->conn, '1' );
		$early      = $this->h->item( $this->conn, '2' );
		$yesterday  = $this->h->item( $this->conn, '3' );
		$lastweek   = $this->h->item( $this->conn, '4' );
		$old        = $this->h->item( $this->conn, '5' );
		$this->set( $early, array( 'created_at' => '2026-11-01 20:30:00' ) );
		$this->set( $yesterday, array( 'created_at' => '2026-11-01 19:30:00' ) );
		$this->set( $lastweek, array( 'created_at' => '2026-10-28 10:00:00' ) );
		$this->set( $old, array( 'created_at' => '2026-09-01 10:00:00' ) );

		$this->assertEqualsCanonicalizing( array( $today, $early ), $this->ids( array( 'day' => 'today' ) ) );
		$this->assertSame( array( $yesterday ), $this->ids( array( 'day' => 'yesterday' ) ) );
		$this->assertEqualsCanonicalizing( array( $today, $early, $yesterday, $lastweek ), $this->ids( array( 'day' => '7d' ) ) );
		$this->assertEqualsCanonicalizing( array( $today, $early, $yesterday, $lastweek ), $this->ids( array( 'day' => '30d' ) ) );
	}

	public function test_paging_and_page_sizes(): void {
		for ( $i = 1; $i <= 60; ++$i ) {
			$this->h->item( $this->conn, (string) $i );
		}
		$page = $this->h->queue->query( QueueQuery::fromArray( array( 'per_page' => 25, 'page' => 3 ) ) );
		$this->assertSame( 60, $page->total );
		$this->assertSame( 3, $page->totalPages() );
		$this->assertCount( 10, $page->items );
		$this->assertSame( 3, $page->page );
		$this->assertCount( 25, $this->h->queue->query( QueueQuery::fromArray( array( 'per_page' => 25 ) ) )->items );
		$this->assertCount( 50, $this->h->queue->query( QueueQuery::fromArray( array( 'per_page' => 50 ) ) )->items );
		$this->assertCount( 60, $this->h->queue->query( QueueQuery::fromArray( array( 'per_page' => 100 ) ) )->items );
		$this->assertSame( array(), $this->h->queue->query( QueueQuery::fromArray( array( 'page' => 9 ) ) )->items );
		$first = $this->h->queue->query( QueueQuery::fromArray( array() ) )->items;
		$this->assertGreaterThan( $first[1]['id'], $first[0]['id'], 'newest first' );
	}

	public function test_filter_by_entity_type_connection_and_mode(): void {
		$other  = $this->h->connection();
		$a      = $this->h->item( $this->conn, '1' );
		$b      = $this->h->item( $other, '2' );
		$shadow = $this->h->item( $this->conn, '3', 'created', null, ItemMode::Shadow );
		$this->assertSame( array( $b ), $this->ids( array( 'connection_id' => $other ) ) );
		$this->assertSame( array( $shadow ), $this->ids( array( 'mode' => 'shadow' ) ) );
		$this->assertSame( array( $b, $a ), $this->ids( array( 'mode' => 'live' ) ) );
		$this->assertCount( 3, $this->ids( array( 'entity_type' => 'order' ) ) );
		$this->assertSame( array(), $this->ids( array( 'entity_type' => 'refund' ) ) );
	}

	public function test_waiting_for_an_earlier_step_is_flagged_on_the_later_one(): void {
		$created = $this->h->item( $this->conn, '7', 'created' );
		$paid    = $this->h->item( $this->conn, '7', 'paid' );
		$rows    = array_column( $this->h->queue->query( QueueQuery::fromArray( array() ) )->items, 'waiting_for_earlier', 'id' );
		$this->assertFalse( $rows[ $created ] );
		$this->assertTrue( $rows[ $paid ] );
		$this->h->queue->markSynced( $created, '1', 'S1' );
		$this->assertFalse( $this->h->queue->findRow( $paid )['waiting_for_earlier'] );
	}

	public function test_counts_by_state_are_complete_and_can_be_limited(): void {
		$other = $this->h->connection();
		$a     = $this->h->item( $this->conn, '1' );
		$this->h->item( $this->conn, '2' );
		$this->h->item( $other, '3' );
		$this->h->item( $this->conn, '4', 'created', null, ItemMode::Shadow );
		$this->h->queue->markFailed( $a, 'SYNC-VAL-002', 'x' );

		$all = $this->h->queue->countsByState();
		$this->assertSame( array( 'queued', 'syncing', 'synced', 'retrying', 'failed', 'skipped' ), array_keys( $all ) );
		$this->assertSame( 2, $all['queued'] );
		$this->assertSame( 1, $all['failed'] );
		$this->assertSame( 0, $all['synced'] );
		$this->assertSame( 1, $this->h->queue->countsByState( $other )['queued'] );
		$this->assertSame( 1, $this->h->queue->countsByState( null, 'shadow' )['queued'] );
		$this->assertSame( 3, $this->h->queue->countsByState( null, null )['queued'] );
	}

	public function test_synced_today_counts_the_store_day(): void {
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$c = $this->h->item( $this->conn, '3' );
		$this->h->queue->markSynced( $a, '1', 'S1' );
		$this->h->queue->markSynced( $b, '2', 'S2' );
		$this->h->queue->markSynced( $c, '3', 'S3' );
		$this->set( $b, array( 'finished_at' => '2026-11-01 20:10:00' ) ); // 00:10 in Dubai: today.
		$this->set( $c, array( 'finished_at' => '2026-11-01 19:50:00' ) ); // 23:50 in Dubai yesterday.
		$this->assertSame( 2, $this->h->queue->syncedToday() );
	}

	public function test_hourly_gives_24_buckets_ending_with_this_hour(): void {
		$this->h->clock->set( '2026-11-02 12:40:00' );
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$c = $this->h->item( $this->conn, '3' );
		$d = $this->h->item( $this->conn, '4' );
		$e = $this->h->item( $this->conn, '5', 'created', null, ItemMode::Shadow );
		$this->h->queue->markSynced( $a, '1', 'S1' );
		$this->h->queue->markSynced( $b, '2', 'S2' );
		$this->h->queue->markFailed( $c, 'SYNC-VAL-002', 'x' );
		$this->h->queue->markSynced( $d, '4', 'S4' );
		$this->h->queue->markSynced( $e, '', '' );
		$this->set( $b, array( 'finished_at' => '2026-11-02 11:59:59' ) );
		$this->set( $d, array( 'finished_at' => '2026-11-01 13:00:00' ) ); // Exactly 23 hours back: the first bucket.

		$hourly = $this->h->queue->hourly();
		$this->assertCount( 24, $hourly );
		$this->assertSame( '2026-11-01T13:00:00Z', $hourly[0]['hour'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $hourly[23]['hour'] );
		$this->assertSame( 1, $hourly[0]['synced'] );
		$this->assertSame( 1, $hourly[22]['synced'], 'finished at 11:59:59' );
		$this->assertSame( 1, $hourly[23]['synced'], 'shadow items are not counted' );
		$this->assertSame( 1, $hourly[23]['failed'] );
		$this->assertSame( 3, array_sum( array_column( $hourly, 'synced' ) ) );
		$this->assertSame( array( 'hour', 'synced', 'failed' ), array_keys( $hourly[5] ) );
	}

	public function test_oldest_waiting_item(): void {
		$this->assertNull( $this->h->queue->oldestWaiting() );
		$a = $this->h->item( $this->conn, '1', 'created', null, ItemMode::Live, array( 'label' => '10001' ) );
		$this->h->clock->advance( 60 );
		$this->h->item( $this->conn, '2', 'created', null, ItemMode::Live, array( 'label' => '10002' ) );
		$this->assertSame( array( 'at' => '2026-11-02T12:00:00Z', 'order' => '10001' ), $this->h->queue->oldestWaiting() );
		$this->h->queue->markSynced( $a, '1', 'S1' );
		$this->assertSame( '10002', $this->h->queue->oldestWaiting()['order'] );
	}

	public function test_failed_items_are_grouped_by_code_and_cause_with_the_biggest_first(): void {
		$ids = array();
		foreach ( array( '1' => 'SKU A', '2' => 'SKU B', '3' => 'SKU B', '4' => 'SKU B' ) as $order => $cause ) {
			$ids[ $order ] = $this->h->item( $this->conn, (string) $order );
			$this->h->queue->markFailed( $ids[ $order ], 'SYNC-MAP-001', 'x', $cause );
			$this->h->clock->advance( 10 );
		}
		$tax = $this->h->item( $this->conn, '5' );
		$this->h->queue->markFailed( $tax, 'SYNC-MAP-002', 'x', 'Tax AE 5%' );

		$groups = $this->h->queue->attentionGroups();
		$this->assertCount( 3, $groups );
		$this->assertSame( 'SYNC-MAP-001', $groups[0]['error_code'] );
		$this->assertSame( 'SKU B', $groups[0]['cause'] );
		$this->assertSame( 3, $groups[0]['count'] );
		$this->assertSame( array( $ids['2'], $ids['3'], $ids['4'] ), $groups[0]['item_ids'] );
		$this->assertSame( '2026-11-02T12:00:10Z', $groups[0]['oldest_at'] );
		$this->assertSame( array( 'error_code', 'cause', 'count', 'oldest_at', 'item_ids' ), array_keys( $groups[0] ) );
		$this->assertSame( 1, $groups[1]['count'] );
	}

	public function test_dead_locks_are_taken_back_without_counting_a_try(): void {
		$dead    = $this->h->item( $this->conn, '1' );
		$alive   = $this->h->item( $this->conn, '2' );
		$this->h->queue->claim( 2, 60, 'w' );
		$this->h->clock->advance( 30 );
		$this->assertSame( array(), $this->h->queue->reapStaleLockIds(), 'locks have not run out yet' );

		$this->h->queue->extendLock( $alive, 60 );
		$this->h->clock->advance( 31 );
		$this->assertSame( array( $dead ), $this->h->queue->reapStaleLockIds() );

		$row = $this->h->queue->findRow( $dead );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( ErrorCode::Lock001->value, $row['error_code'] );
		$this->assertSame( ErrorCode::Lock001->template(), $row['error_message'] );
		$this->assertNull( $this->h->row( $dead )['locked_by'] );
		$this->assertSame( 'syncing', $this->h->queue->findRow( $alive )['state'] );
		$this->assertSame( 0, $this->h->queue->reapStaleLocks() );
		$this->assertSame( array( $dead ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 5, 60, 'w2' ) ), 'the item can be sent again' );
	}

	public function test_syncing_counts_ignore_expired_locks(): void {
		$this->h->item( $this->conn, '1' );
		$this->h->queue->claim( 1, 60, 'w' );
		$this->assertSame( 1, $this->h->queue->countSyncing() );
		$this->assertSame( 1, $this->h->queue->countSyncing( $this->conn ) );
		$this->assertSame( 0, $this->h->queue->countSyncing( $this->conn + 99 ) );
		$this->h->clock->advance( 61 );
		$this->assertSame( 0, $this->h->queue->countSyncing() );
	}

	public function test_retention_follows_spec_5_2(): void {
		$make = function ( string $order, string $state, string $when, int $failedBefore = 0 ): int {
			$id = $this->h->item( $this->conn, $order );
			$this->set( $id, array( 'state' => $state, 'finished_at' => $when, 'updated_at' => $when, 'failed_before' => (string) $failedBefore ) );
			return $id;
		};
		$syncedOld      = $make( '1', 'synced', '2026-09-30 10:00:00' );  // 33 days: deleted.
		$syncedRecent   = $make( '2', 'synced', '2026-10-10 10:00:00' );  // 23 days: kept.
		$resolvedOld    = $make( '3', 'synced', '2026-09-30 10:00:00', 1 ); // 33 days but once failed: kept.
		$resolvedOlder  = $make( '4', 'synced', '2026-07-01 10:00:00', 1 ); // 124 days: deleted.
		$skippedOld     = $make( '5', 'skipped', '2026-07-01 10:00:00' );   // deleted.
		$skippedRecent  = $make( '6', 'skipped', '2026-09-20 10:00:00' );   // 43 days: kept.
		$failedAncient  = $make( '7', 'failed', '2026-01-01 10:00:00', 1 ); // kept until resolved.
		$queuedAncient  = $make( '8', 'queued', '2026-01-01 10:00:00' );    // kept.

		$this->assertSame( 3, $this->h->queue->prune() );
		$left = array_column( $this->h->wpdb->get_results( "SELECT id FROM `{$this->h->tables->queue()}`", ARRAY_A ), 'id' ); // phpcs:ignore
		$this->assertEqualsCanonicalizing( array( $syncedRecent, $resolvedOld, $skippedRecent, $failedAncient, $queuedAncient ), array_map( 'intval', $left ) );
		$this->assertNotContains( (string) $syncedOld, $left );
		$this->assertNotContains( (string) $resolvedOlder, $left );
		$this->assertNotContains( (string) $skippedOld, $left );
		$this->assertSame( 0, $this->h->queue->prune(), 'nothing more to remove' );
	}

	public function test_siblings_of_the_same_order_come_oldest_first(): void {
		$a = $this->h->item( $this->conn, '7', 'created' );
		$b = $this->h->item( $this->conn, '7', 'paid' );
		$this->h->item( $this->conn, '8', 'created' );
		$siblings = $this->h->queue->siblings( $b );
		$this->assertSame( array( $a, $b ), array_column( $siblings, 'id' ) );
		$this->assertSame( array( 'id', 'event', 'state', 'error_code', 'created_at' ), array_keys( $siblings[0] ) );
	}

	public function test_waiting_for_an_event_that_can_be_joined(): void {
		$id = $this->h->item( $this->conn, '7', 'updated' );
		$this->assertSame( $id, $this->h->queue->findUnfinished( $this->conn, \Estasoft\SyncEngine\Domain\EntityType::Order, '7', 'updated' ) );
		$this->assertNull( $this->h->queue->findUnfinished( $this->conn, \Estasoft\SyncEngine\Domain\EntityType::Order, '7', 'paid' ) );
		$this->set( $id, array( 'state' => 'syncing' ) );
		$this->assertNull( $this->h->queue->findUnfinished( $this->conn, \Estasoft\SyncEngine\Domain\EntityType::Order, '7', 'updated' ), 'an item being sent cannot be joined' );
		$this->h->queue->markSynced( $id, '1', 'S1' );
		$this->assertNull( $this->h->queue->findUnfinished( $this->conn, \Estasoft\SyncEngine\Domain\EntityType::Order, '7', 'updated' ) );
	}

	public function test_a_change_while_waiting_is_noted_once_and_never_hides_a_real_error(): void {
		$id = $this->h->item( $this->conn, '7', 'updated' );
		$this->h->queue->noteChangedWhileWaiting( $id );
		$this->assertSame( 'SYNC-STATE-001', $this->h->queue->findRow( $id )['error_code'] );

		$failing = $this->h->item( $this->conn, '8', 'updated' );
		$this->h->queue->markRetrying( $failing, '2026-11-02 13:00:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->noteChangedWhileWaiting( $failing );
		$this->assertSame( 'SYNC-NET-001', $this->h->queue->findRow( $failing )['error_code'] );
	}

	public function test_display_values_can_be_filled_in_later(): void {
		$id = $this->h->item( $this->conn );
		$this->h->queue->setDisplay( $id, array( 'label' => '10490', 'customer' => 'Noor', 'total_minor' => 1050, 'currency' => 'USD' ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( '10490', $row['entity_label'] );
		$this->assertSame( 'Noor', $row['customer_name'] );
		$this->assertSame( array( 'minor' => 1050, 'currency' => 'USD' ), $row['total'] );
	}

	public function test_the_last_log_entry_is_linked(): void {
		$id  = $this->h->item( $this->conn );
		$log = $this->h->logs->write( array( 'level' => 'info', 'message' => 'x', 'queue_id' => $id ) );
		$this->h->queue->setLastLog( $id, $log );
		$this->assertSame( $log, $this->h->queue->findRow( $id )['log_id'] );
	}

	/**
	 * Claim one item as a worker and return its id.
	 *
	 * @param string $worker Worker id (the lock token).
	 */
	private function claimed( string $worker, string $order = '1' ): int {
		$id = $this->h->item( $this->conn, $order );
		$this->assertSame( array( $id ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 1, 60, $worker ) ) );
		return $id;
	}

	public function test_a_result_is_written_only_by_the_holder_of_the_lock(): void {
		$id = $this->claimed( 'worker-a' );

		$this->assertFalse( $this->h->queue->markSynced( $id, '9', 'S9', null, null, null, true, null, 'worker-b' ), 'the wrong token' );
		$this->assertFalse( $this->h->queue->markRetrying( $id, '2026-11-02 13:00:00', 'SYNC-NET-001', 'x', '', 'worker-b' ) );
		$this->assertFalse( $this->h->queue->markFailed( $id, 'SYNC-VAL-002', 'x', '', true, 'worker-b' ) );
		$this->assertFalse( $this->h->queue->release( $id, null, null, null, true, 'worker-b' ) );
		$row = $this->h->row( $id );
		$this->assertSame( 'syncing', $row['state'], 'the row is exactly as it was' );
		$this->assertSame( 'worker-a', $row['locked_by'] );
		$this->assertSame( '0', $row['attempt'] );
		$this->assertNull( $row['external_id'] );

		$this->assertTrue( $this->h->queue->markSynced( $id, '9', 'S9', null, null, null, true, null, 'worker-a' ), 'the right token' );
		$this->assertSame( 'synced', $this->h->row( $id )['state'] );
		$this->assertNull( $this->h->row( $id )['locked_by'] );
		$this->assertFalse( $this->h->queue->markFailed( $id, 'SYNC-VAL-002', 'late', '', true, 'worker-a' ), 'and only while the item is still being sent' );
		$this->assertSame( 'synced', $this->h->row( $id )['state'] );
	}

	public function test_each_result_writer_accepts_the_right_token(): void {
		$retry = $this->claimed( 'w', '1' );
		$this->assertTrue( $this->h->queue->markRetrying( $retry, '2026-11-02 13:00:00', 'SYNC-NET-001', 'x', 'c', 'w' ) );
		$this->assertSame( 'retrying', $this->h->row( $retry )['state'] );

		$fail = $this->claimed( 'w', '2' );
		$this->assertTrue( $this->h->queue->markFailed( $fail, 'SYNC-VAL-002', 'x', 'c', true, 'w' ) );
		$this->assertSame( 'failed', $this->h->row( $fail )['state'] );

		$back = $this->claimed( 'w', '3' );
		$this->assertTrue( $this->h->queue->release( $back, null, null, null, true, 'w' ) );
		$this->assertSame( 'queued', $this->h->row( $back )['state'] );
	}

	public function test_a_lock_that_ran_out_and_was_taken_over_cannot_write(): void {
		$id = $this->claimed( 'worker-a' );
		$this->h->clock->advance( 61 );
		$this->assertSame( array( $id ), $this->h->queue->reapStaleLockIds() );
		$this->h->queue->claim( 1, 60, 'worker-b' );

		$this->assertFalse( $this->h->queue->markSynced( $id, '9', 'S9', null, null, null, true, null, 'worker-a' ) );
		$this->assertSame( 'worker-b', $this->h->row( $id )['locked_by'] );
		$this->assertTrue( $this->h->queue->markSynced( $id, '9', 'S9', null, null, null, true, null, 'worker-b' ) );
	}

	public function test_extending_a_lock_says_whether_it_is_still_held(): void {
		$id = $this->claimed( 'worker-a' );
		$this->assertTrue( $this->h->queue->extendLock( $id, 90, 'worker-a' ) );
		$this->assertSame( '2026-11-02 12:01:30', $this->h->row( $id )['locked_until'] );
		$this->assertTrue( $this->h->queue->extendLock( $id, 90, 'worker-a' ), 'a second push to the same moment is still a yes' );
		$this->assertFalse( $this->h->queue->extendLock( $id, 90, 'worker-b' ), 'someone else' );
		$this->assertTrue( $this->h->queue->extendLock( $id, 120 ), 'without a token it works as before' );

		$this->h->queue->markSynced( $id, '1', 'S1' );
		$this->assertFalse( $this->h->queue->extendLock( $id, 90, 'worker-a' ), 'the item is no longer being sent' );
		$this->assertFalse( $this->h->queue->extendLock( $id + 999, 90 ) );
	}

	public function test_the_reaper_returns_only_rows_it_really_changed(): void {
		$a = $this->claimed( 'w', '1' );
		$b = $this->claimed( 'w', '2' );
		$this->h->clock->advance( 61 );
		// Between a reaper's look and its change, a worker finishes one of the items. Here the same thing is done one step earlier.
		$this->h->queue->markSynced( $b, '1', 'S1', null, null, null, true, null, 'w' );
		$taken = $this->h->queue->reapStale();
		$this->assertSame( array( $a ), array_column( $taken, 'id' ) );
		$this->assertSame( array(), $this->h->queue->reapStale(), 'a second reaper finds nothing to report' );
		$this->assertSame( 'synced', $this->h->row( $b )['state'] );
	}

	public function test_error_messages_are_redacted_and_cut_before_they_are_stored(): void {
		$long = 'The system said: ' . str_repeat( 'no good reason. ', 80 );
		$dirty = 'Rejected for noor@example.com, phone 971501234567, header Authorization: Bearer abcdef1234567890abcdef, api_key=sk_live_123456789abcdef';

		$a = $this->claimed( 'w', '1' );
		$this->h->queue->markFailed( $a, 'SYNC-VAL-002', $dirty . ' ' . $long, 'SKU X for noor@example.com', true, 'w' );
		$row = $this->h->queue->findRow( $a );
		$this->assertLessThanOrEqual( 500, mb_strlen( (string) $row['error_message'] ) );
		foreach ( array( 'noor@example.com', '971501234567', 'abcdef1234567890abcdef', 'sk_live_123456789abcdef' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, (string) $row['error_message'] );
		}
		$this->assertStringContainsString( 'Rejected for', (string) $row['error_message'] );
		$this->assertStringNotContainsString( 'noor@example.com', (string) $this->h->row( $a )['error_cause'] );

		$b = $this->claimed( 'w', '2' );
		$this->h->queue->markRetrying( $b, '2026-11-02 13:00:00', 'SYNC-NET-001', $dirty, '', 'w' );
		$this->assertStringNotContainsString( 'noor@example.com', (string) $this->h->queue->findRow( $b )['error_message'] );

		$d = $this->claimed( 'w', '4' );
		$this->h->queue->markSynced( $d, '1', 'S1', null, null, 'SYNC-DUP-001', true, $dirty, 'w' );
		$this->assertStringNotContainsString( 'noor@example.com', (string) $this->h->queue->findRow( $d )['error_message'] );

		$c = $this->claimed( 'w', '3' );
		$this->h->queue->release( $c, null, 'SYNC-CFG-002', $dirty . $long, false, 'w' );
		$msg = (string) $this->h->queue->findRow( $c )['error_message'];
		$this->assertStringNotContainsString( 'sk_live', $msg );
		$this->assertLessThanOrEqual( 500, mb_strlen( $msg ) );
	}

	public function test_with_personal_redaction_off_only_secrets_are_removed_from_stored_messages(): void {
		$this->h->settings->update( array( 'redaction' => false ) );
		$this->assertFalse( $this->h->settings->redactionEnabled(), 'the setting is off for this test' );
		$id = $this->claimed( 'w', '1' );
		$this->h->queue->markFailed( $id, 'SYNC-VAL-002', 'Customer noor@example.com rejected, api_key=sk_live_123456789abcdef', '', true, 'w' );
		$msg = (string) $this->h->queue->findRow( $id )['error_message'];
		$this->assertStringContainsString( 'noor@example.com', $msg );
		$this->assertStringNotContainsString( 'sk_live', $msg );
	}

	public function test_a_message_in_arabic_is_cut_by_characters_not_bytes(): void {
		$id = $this->claimed( 'w', '1' );
		$this->h->queue->markFailed( $id, 'SYNC-VAL-002', str_repeat( 'نور', 400 ), '', true, 'w' );
		$msg = (string) $this->h->queue->findRow( $id )['error_message'];
		$this->assertSame( 500, mb_strlen( $msg ) );
		$this->assertSame( 1, preg_match( '//u', $msg ), 'still valid UTF-8' );
	}

	public function test_a_second_row_with_the_same_key_returns_the_first(): void {
		$key   = str_repeat( 'c', 64 );
		$first = $this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', $key );
		$this->assertGreaterThan( 0, $first );

		$once = $this->h->queue->enqueueOnce( $this->conn, EntityType::Order, '1', 'created', 'order:1', $key );
		$this->assertSame( array( 'id' => $first, 'created' => false ), $once );
		$this->assertSame( $first, $this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', $key ) );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
		$this->assertSame( $first, $this->h->queue->idByKey( $this->conn, $key ) );
		$this->assertNull( $this->h->queue->idByKey( $this->conn + 1, $key ) );

		$fresh = $this->h->queue->enqueueOnce( $this->conn, EntityType::Order, '1', 'paid', 'order:1', str_repeat( 'd', 64 ) );
		$this->assertTrue( $fresh['created'] );
		$this->assertNotSame( $first, $fresh['id'] );
	}

	public function test_a_duplicate_key_is_not_reported_as_a_failed_query(): void {
		$key = str_repeat( 'e', 64 );
		$this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', $key );
		$this->h->wpdb->last_error = '';
		ob_start();
		$this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', $key );
		$this->assertSame( '', ob_get_clean(), 'nothing is printed for the expected duplicate' );
	}

	public function test_items_are_claimed_by_priority_then_by_age(): void {
		$normal_old = $this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', str_repeat( '1', 64 ) );
		$urgent     = $this->h->queue->enqueue( $this->conn, EntityType::Order, '2', 'created', 'order:2', str_repeat( '2', 64 ), ItemMode::Live, 5, array(), 1 );
		$normal_new = $this->h->queue->enqueue( $this->conn, EntityType::Order, '3', 'created', 'order:3', str_repeat( '3', 64 ) );
		$later      = $this->h->queue->enqueue( $this->conn, EntityType::Order, '4', 'created', 'order:4', str_repeat( '4', 64 ), ItemMode::Live, 5, array(), 9 );

		$claimed = array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 10, 60, 'w' ) );
		$this->assertSame( array( $urgent, $normal_old, $normal_new, $later ), array_values( array_unique( array_merge( array( $urgent ), $claimed ) ) ), 'the claimed set is the same' );
		$this->assertCount( 4, $claimed );

		// The order inside a limited claim follows priority.
		$this->h->reset();
		$conn = $this->h->connection();
		$low  = $this->h->queue->enqueue( $conn, EntityType::Order, '1', 'created', 'order:1', str_repeat( '5', 64 ), ItemMode::Live, 5, array(), 9 );
		$mid  = $this->h->queue->enqueue( $conn, EntityType::Order, '2', 'created', 'order:2', str_repeat( '6', 64 ) );
		$top  = $this->h->queue->enqueue( $conn, EntityType::Order, '3', 'created', 'order:3', str_repeat( '7', 64 ), ItemMode::Live, 5, array(), 0 );
		$this->assertSame( array( $top ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 1, 60, 'w' ) ) );
		$this->assertSame( array( $mid ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 1, 60, 'w' ) ) );
		$this->assertSame( array( $low ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 1, 60, 'w' ) ) );
	}

	public function test_priority_never_lets_a_later_step_of_an_order_jump_ahead(): void {
		$first  = $this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'created', 'order:1', str_repeat( 'a', 64 ), ItemMode::Live, 5, array(), 9 );
		$second = $this->h->queue->enqueue( $this->conn, EntityType::Order, '1', 'paid', 'order:1', str_repeat( 'b', 64 ), ItemMode::Live, 5, array(), 0 );
		$this->assertSame( array( $first ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 5, 60, 'w' ) ), 'the urgent step waits for the earlier one' );
		$this->assertNotSame( $second, $first );
	}

	public function test_the_claim_lock_runs_the_work_once_and_hands_the_result_back(): void {
		$ran = 0;
		$out = $this->h->queue->withClaimLock(
			function () use ( &$ran ): string {
				++$ran;
				return 'done';
			}
		);
		$this->assertSame( 'done', $out );
		$this->assertSame( 1, $ran );
	}

	public function test_the_claim_lock_is_released_when_the_work_throws(): void {
		try {
			$this->h->queue->withClaimLock(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Expected the exception to pass through.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( 'again', $this->h->queue->withClaimLock( static fn(): string => 'again', 1 ), 'the lock was released' );
	}

	public function test_the_claim_lock_is_a_named_lock_that_another_connection_holds_off(): void {
		$other = $this->databaseConnection();
		$name  = $this->h->queue->claimLockName();
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('$name', 0)" )->fetch_row()[0] );
		try {
			$ran = false;
			$out = $this->h->queue->withClaimLock(
				function () use ( &$ran ): string {
					$ran = true;
					return 'ran';
				},
				1,
				'busy'
			);
			$this->assertSame( 'busy', $out, 'it gave up after a short wait' );
			$this->assertFalse( $ran, 'and the work did not run' );
		} finally {
			$other->query( "SELECT RELEASE_LOCK('$name')" );
			$other->close();
		}
		$this->assertSame( 'ran', $this->h->queue->withClaimLock( static fn(): string => 'ran', 1, 'busy' ) );
	}

	public function test_the_claim_lock_name_belongs_to_this_database_not_just_this_table_name(): void {
		$name = $this->h->queue->claimLockName();
		$this->assertNotSame( 'estasoft_sync_claim_' . substr( hash( 'sha256', $this->h->tables->queue() ), 0, 16 ), $name, 'the database name is part of it' );
		$this->assertSame( 'estasoft_sync_claim_' . substr( hash( 'sha256', $this->h->wpdb->dbname . '|' . $this->h->tables->queue() ), 0, 16 ), $name );

		// A site with the same prefix in another database holds "its" lock: this one is not held up by it.
		$elsewhere = 'estasoft_sync_claim_' . substr( hash( 'sha256', 'some_other_database|' . $this->h->tables->queue() ), 0, 16 );
		$other     = $this->databaseConnection();
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('$elsewhere', 0)" )->fetch_row()[0] );
		try {
			$this->assertSame( 'ran', $this->h->queue->withClaimLock( static fn(): string => 'ran', 1, 'busy' ) );
		} finally {
			$other->query( "SELECT RELEASE_LOCK('$elsewhere')" );
			$other->close();
		}
	}

	public function test_waiting_items_of_a_connection_disconnected_30_days_ago_are_deleted(): void {
		$gone  = $this->h->connection();
		$stays = $this->h->connection();
		$queued   = $this->h->item( $gone, '1' );
		$retrying = $this->h->item( $gone, '2' );
		$failed   = $this->h->item( $gone, '3' );
		$synced   = $this->h->item( $gone, '4' );
		$other    = $this->h->item( $stays, '5' );
		$this->h->queue->markRetrying( $retrying, '2026-11-02 18:00:00', 'SYNC-NET-001', 'x' );
		$this->h->queue->markFailed( $failed, 'SYNC-VAL-002', 'x' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$this->h->mappings->seedLive( $gone, 'orders', array( array( 'source' => 'order.number', 'target' => 'x' ) ), 'seed' );
		$this->h->idMap->link( $gone, EntityType::Order, '4', '1', 'S1', null );
		$this->h->connections->delete( $gone );

		$this->h->clock->advance( 29 * 86400 );
		$this->assertSame( 0, $this->h->queue->pruneDisconnected(), 'not yet' );

		$this->h->clock->advance( 2 * 86400 );
		$this->assertSame( 2, $this->h->queue->pruneDisconnected(), 'the waiting items of the disconnected connection' );
		$this->assertNull( $this->h->queue->find( $queued ) );
		$this->assertNull( $this->h->queue->find( $retrying ) );
		$this->assertNotNull( $this->h->queue->find( $failed ), 'failed items wait for a person' );
		$this->assertNotNull( $this->h->queue->find( $synced ), 'finished items follow their own retention' );
		$this->assertNotNull( $this->h->queue->find( $other ), 'another connection is untouched' );
		$this->assertNotNull( $this->h->idMap->get( $gone, EntityType::Order, '4' ), 'the id map stays' );
		$this->assertNotNull( $this->h->mappings->live( $gone, 'orders' ), 'and so do the mapping versions' );
		$this->assertNotNull( $this->h->connections->findPublic( $gone ), 'and so does the connection' );
		$this->assertSame( 0, $this->h->queue->pruneDisconnected(), 'a second run has nothing to do' );
	}

	public function test_coming_back_before_30_days_keeps_the_items(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->connections->delete( $conn );
		$this->h->clock->advance( 20 * 86400 );
		$this->h->connections->setStatus( $conn, ConnectionStatus::Active );
		$this->h->clock->advance( 20 * 86400 );
		$this->assertSame( 0, $this->h->queue->pruneDisconnected() );
		$this->assertNotNull( $this->h->queue->find( $id ) );
	}

	public function test_a_connection_disconnected_before_the_upgrade_is_dated_by_its_last_change(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->connections->delete( $conn );
		$this->h->sql( "UPDATE `{$this->h->tables->connections()}` SET disconnected_at = NULL, updated_at = '2026-09-01 00:00:00' WHERE id = {$conn}" ); // phpcs:ignore
		$this->assertSame( 1, $this->h->queue->pruneDisconnected() );
		$this->assertNull( $this->h->queue->find( $id ) );
	}

	/**
	 * A second, separate database connection, to hold a lock the way another process would.
	 */
	private function databaseConnection(): \mysqli {
		$host = (string) ( getenv( 'WP_DB_HOST' ) ?: 'localhost' );
		$link = new \mysqli( $host, (string) ( getenv( 'WP_DB_USER' ) ?: 'root' ), (string) ( getenv( 'WP_DB_PASSWORD' ) ?: '' ), (string) ( getenv( 'WP_DB_NAME' ) ?: 'estasoft_test' ) );
		$this->assertSame( 0, $link->connect_errno, (string) $link->connect_error );
		return $link;
	}

	public function test_the_items_of_one_object_are_found_by_exact_type_and_id_newest_first(): void {
		$other_conn = $this->h->connection();
		$created    = $this->h->item( $this->conn, '10490', 'created' );
		$similar    = $this->h->item( $this->conn, '104900', 'created' );
		$prefix     = $this->h->item( $this->conn, '1049', 'created' );
		$paid       = $this->h->item( $this->conn, '10490', 'paid' );
		$elsewhere  = $this->h->item( $other_conn, '10490', 'created' );
		$updated    = $this->h->item( $this->conn, '10490', 'updated' );
		$this->h->queue->markSynced( $created, '1', 'S1' );
		$this->h->queue->markFailed( $paid, 'SYNC-VAL-002', 'x' );

		$rows = $this->h->queue->forEntity( EntityType::Order, '10490' );

		$this->assertSame( array( $updated, $elsewhere, $paid, $created ), array_column( $rows, 'id' ), 'newest id first, every connection and every state, only this object' );
		$this->assertNotContains( $similar, array_column( $rows, 'id' ), 'a text search for 10490 would also find 104900' );
		$this->assertNotContains( $prefix, array_column( $rows, 'id' ) );
		$this->assertSame( array( 'synced', 'failed', 'queued', 'queued' ), array( $rows[3]['state'], $rows[2]['state'], $rows[1]['state'], $rows[0]['state'] ) );
	}

	public function test_entity_rows_have_the_same_shape_as_the_rows_of_the_queue_list(): void {
		$id    = $this->h->item( $this->conn, '10490', 'created', null, ItemMode::Live, array( 'label' => '10490', 'customer' => 'Noor', 'total_minor' => 43800, 'currency' => 'AED' ) );
		$list  = $this->h->queue->query( QueueQuery::fromArray( array() ) )->items[0];
		$entry = $this->h->queue->forEntity( EntityType::Order, '10490' )[0];
		$this->assertSame( array_keys( $list ), array_keys( $entry ) );
		$this->assertSame( $list, $entry );
		$this->assertSame( $id, $entry['id'] );
		$this->assertSame( array( 'minor' => 43800, 'currency' => 'AED' ), $entry['total'] );
	}

	public function test_the_latest_item_of_an_object_and_nothing_for_an_unknown_one(): void {
		$first  = $this->h->item( $this->conn, '10490', 'created' );
		$second = $this->h->item( $this->conn, '10490', 'paid' );
		$latest = $this->h->queue->latestForEntity( EntityType::Order, '10490' );
		$this->assertNotNull( $latest );
		$this->assertSame( $second, $latest['id'] );
		$this->assertNotSame( $first, $latest['id'] );
		$this->assertNull( $this->h->queue->latestForEntity( EntityType::Order, '99999' ) );
		$this->assertNull( $this->h->queue->latestForEntity( EntityType::Refund, '10490' ), 'another type' );
		$this->assertSame( array(), $this->h->queue->forEntity( EntityType::Order, '99999' ) );
	}

	public function test_an_item_waiting_for_an_earlier_step_says_so_in_the_entity_rows(): void {
		$this->h->item( $this->conn, '10490', 'created' );
		$this->h->item( $this->conn, '10490', 'paid' );
		$rows = $this->h->queue->forEntity( EntityType::Order, '10490' );
		$this->assertTrue( $rows[0]['waiting_for_earlier'] );
		$this->assertFalse( $rows[1]['waiting_for_earlier'] );
	}

	public function test_the_entity_list_is_limited_and_a_long_id_is_cut_the_way_it_was_stored(): void {
		for ( $i = 0; $i < 25; ++$i ) {
			$this->h->item( $this->conn, '777', 'updated' );
		}
		$this->assertCount( 20, $this->h->queue->forEntity( EntityType::Order, '777' ), 'default limit 20' );
		$this->assertCount( 3, $this->h->queue->forEntity( EntityType::Order, '777', 3 ) );
		$this->assertCount( 25, $this->h->queue->forEntity( EntityType::Order, '777', 500 ), 'at most 200, here all 25' );
		$this->assertCount( 1, $this->h->queue->forEntity( EntityType::Order, '777', 0 ), 'at least one' );

		$long = str_repeat( 'A', 80 );
		$id   = $this->h->queue->enqueue( $this->conn, EntityType::Stock, $long, 'updated', 'stock:x', str_repeat( 'f', 64 ) );
		$this->assertSame( $id, $this->h->queue->latestForEntity( EntityType::Stock, $long )['id'] );
	}

	public function test_the_entity_lookup_uses_the_entity_index(): void {
		$this->h->item( $this->conn, '10490' );
		$plan = $this->h->wpdb->get_results( "EXPLAIN SELECT id FROM `{$this->h->tables->queue()}` FORCE INDEX (entity) WHERE entity_type = 'order' AND entity_id = '10490' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'entity', $plan[0]['key'], 'the index exists and answers the query' );
	}
}
