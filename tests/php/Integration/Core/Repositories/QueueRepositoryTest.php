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

	public function test_retry_resets_tries_of_a_failed_item_but_not_of_a_retrying_one(): void {
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
		$this->assertSame( 4, $r['attempt'] );
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
}
