<?php
/**
 * The delivery pipeline for one item.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Queue\ItemProcessor;
use Estasoft\SyncEngine\Core\Queue\Outcome;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Tests\Support\CoreHarness;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class ItemProcessorTest extends CoreIntegrationTestCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
	}

	/**
	 * Claim one item and run it through the pipeline.
	 *
	 * @param int          $id Queue id.
	 * @param CoreHarness|null $h  Harness to use; the default one when null.
	 */
	private function process( int $id, ?CoreHarness $h = null ): Outcome {
		$h     = $h ?? $this->h;
		$items = $h->queue->claim( 20, 60, 'test-worker' );
		foreach ( $items as $item ) {
			if ( $item->id === $id ) {
				return $h->processor->process( $item );
			}
			$h->queue->release( $item->id );
		}
		$this->fail( "Item $id was not claimable." );
	}

	public function test_a_new_order_is_sent_and_everything_is_recorded(): void {
		$id     = $this->h->item( $this->conn, '10490' );
		$before = $this->listen( 'estasoft_sync_before_send', 3 );
		$after  = $this->listen( 'estasoft_sync_after_send', 3 );
		$synced = $this->listen( 'estasoft_sync_item_synced', 3 );

		$this->assertSame( Outcome::Synced, $this->process( $id ) );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( '1001', $row['external_id'] );
		$this->assertSame( 'S90001', $row['external_ref'] );
		$this->assertSame( 'https://erp.example.test/o/1001', $row['external_url'] );
		$this->assertSame( 1, $row['attempt'] );
		$this->assertSame( 3, $row['mapping_version'] );
		$this->assertNull( $row['error_code'] );
		$this->assertSame( 1, $this->h->connector->count( 'send' ) );

		$link = $this->h->idMap->get( $this->conn, EntityType::Order, '10490' );
		$this->assertSame( '1001', $link['external_id'] );
		$state = $this->h->state->get( $this->conn, EntityType::Order, '10490' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $state['data_hash'] );
		$this->assertSame( 3, $state['mapping_version'] );
		$key = $this->h->row( $id )['idempotency_key'];
		$this->assertSame( array( 'result' => 'done', 'external_id' => '1001' ), $this->h->idempotency->get( $key ) );

		$this->assertCount( 1, $before );
		$this->assertSame( $id, $before[0][0]->id );
		$this->assertSame( 'WC-10490', $before[0][1]['client_order_ref'] );
		$this->assertSame( $this->conn, $before[0][2] );
		$this->assertCount( 1, $after );
		$this->assertTrue( $after[0][1]->isSuccess() );
		$this->assertCount( 1, $synced );
		$this->assertFalse( $synced[0][2] );

		$connection = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( '2026-11-02T12:00:00Z', $connection['last_success_at'] );
		$this->assertNotNull( $connection['typical_response_ms'] );
		$this->assertSame( 0, $connection['failures_in_a_row'] );
	}

	public function test_the_attempt_is_logged_with_a_redacted_copy_of_what_was_sent(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->process( $id );
		$timeline = $this->h->logs->timeline( $id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'Sent to Fakesystem.', $timeline[0]['message'] );
		$detail = $this->h->service->detail( $id );
		$this->assertStringContainsString( 'WC-10490', (string) $detail['request_excerpt'] );
		$this->assertStringNotContainsString( 'noor@example.com', (string) $detail['request_excerpt'] );
		$this->assertNotNull( $detail['log_id'] );
	}

	public function test_secrets_never_reach_the_log_or_the_queue(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Val002, array( 'field' => 'x', 'reason' => 'bad' ), 400, null, null, 'Authorization: Bearer sk-test-abcdefghijklmnop was refused' );
		$this->process( $id );
		$dump = json_encode( $this->h->logs->latest( 20 ) ) . json_encode( $this->h->queue->findRow( $id ) ) . json_encode( $this->h->logs->find( $this->h->queue->findRow( $id )['log_id'] ) );
		$this->assertStringNotContainsString( 'sk-test-abcdefghijklmnop', (string) $dump );
	}

	public function test_an_update_that_changes_nothing_is_not_sent(): void {
		$first = $this->h->item( $this->conn, '10490', 'created' );
		$this->process( $first );
		$second = $this->h->item( $this->conn, '10490', 'updated' );
		$this->assertSame( Outcome::NoChange, $this->process( $second ) );

		$this->assertSame( 1, $this->h->connector->count( 'send' ), 'sent once' );
		$row = $this->h->queue->findRow( $second );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( '1001', $row['external_id'] );
		$this->assertSame( 0, $row['attempt'], 'nothing was tried' );
		$this->assertStringContainsString( 'Nothing changed', $this->h->logs->timeline( $second )[0]['message'] );
	}

	public function test_an_update_that_changes_something_is_sent_and_the_hash_moves_on(): void {
		$first = $this->h->item( $this->conn, '10490', 'created' );
		$this->process( $first );
		$hash = $this->h->state->get( $this->conn, EntityType::Order, '10490' )['data_hash'];

		$this->h->builder->payloads['10490'] = array( 'client_order_ref' => 'WC-10490', 'amount_minor' => 50000 );
		$second = $this->h->item( $this->conn, '10490', 'updated' );
		$this->assertSame( Outcome::Synced, $this->process( $second ) );

		$this->assertSame( 2, $this->h->connector->count( 'send' ) );
		$this->assertNotSame( $hash, $this->h->state->get( $this->conn, EntityType::Order, '10490' )['data_hash'] );
		$this->assertSame( 1, $this->h->connector->count( 'findExisting' ), 'only the first send looked for an existing record' );
	}

	public function test_a_payload_with_the_same_content_in_another_key_order_counts_as_unchanged(): void {
		$this->h->builder->payloads['9'] = array( 'a' => 1, 'b' => array( 'x' => 1, 'y' => 2 ) );
		$this->process( $this->h->item( $this->conn, '9', 'created' ) );
		$this->h->builder->payloads['9'] = array( 'b' => array( 'y' => 2, 'x' => 1 ), 'a' => 1 );
		$this->assertSame( Outcome::NoChange, $this->process( $this->h->item( $this->conn, '9', 'updated' ) ) );
	}

	public function test_a_record_the_system_already_has_is_linked_not_created_again(): void {
		$this->h->connector->documents['WC-10490'] = new ExternalRef( '555', 'S00555', 'https://erp.example.test/o/555' );
		$id     = $this->h->item( $this->conn, '10490' );
		$synced = $this->listen( 'estasoft_sync_item_synced', 3 );

		$this->assertSame( Outcome::Linked, $this->process( $id ) );

		$this->assertSame( 0, $this->h->connector->count( 'send' ), 'nothing was sent' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( '555', $row['external_id'] );
		$this->assertSame( 'S00555', $row['external_ref'] );
		$this->assertSame( 'SYNC-DUP-001', $row['error_code'] );
		$this->assertSame( 'Fakesystem already has this order. Linked to S00555.', $row['error_message'] );
		$this->assertSame( '555', $this->h->idMap->get( $this->conn, EntityType::Order, '10490' )['external_id'] );
		$this->assertTrue( $synced[0][2], 'the hook says it already existed' );
		$this->assertNull( $this->h->state->get( $this->conn, EntityType::Order, '10490' ), 'we did not send our data, so no hash is claimed' );
		$this->assertSame( 'SYNC-DUP-001', $this->h->logs->timeline( $id )[0]['code'] );
	}

	public function test_a_lost_answer_is_not_a_duplicate_on_the_retry(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = array( 'create_then_fail', new ConnectorError( ErrorCode::Net001, array(), null, null, null, 'timeout: the system did not answer in time' ) );

		$this->assertSame( Outcome::Retrying, $this->process( $id ) );
		$this->assertCount( 1, $this->h->connector->documents, 'the system did create the order' );

		$this->h->clock->advance( 61 );
		$this->assertSame( Outcome::Linked, $this->process( $id ) );

		$this->assertCount( 1, $this->h->connector->documents, 'still only one order in the system' );
		$this->assertSame( 1, $this->h->connector->count( 'send' ), 'the retry did not send again' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( 'SYNC-DUP-001', $row['error_code'] );
		$this->assertSame( 2, $row['attempt'] );
	}

	public function test_a_connector_that_finds_the_record_itself_is_treated_the_same(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = SendResult::existing( new ExternalRef( '77', 'S77' ) );
		$this->assertSame( Outcome::Linked, $this->process( $id ) );
		$this->assertSame( 'SYNC-DUP-001', $this->h->queue->findRow( $id )['error_code'] );
		$this->assertNull( $this->h->state->get( $this->conn, EntityType::Order, '10490' ) );
	}

	public function test_a_retryable_error_schedules_the_next_try_and_counts_toward_the_breaker(): void {
		$id      = $this->h->item( $this->conn, '10490' );
		$failed  = $this->listen( 'estasoft_sync_item_failed', 3 );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Net002, array( 'status' => 503 ), 503 );

		$this->assertSame( Outcome::Retrying, $this->process( $id ) );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'retrying', $row['state'] );
		$this->assertSame( 1, $row['attempt'] );
		$this->assertSame( '2026-11-02T12:01:00Z', $row['next_attempt_at'] );
		$this->assertSame( 'SYNC-NET-002', $row['error_code'] );
		$this->assertSame( 'Fakesystem answered with a server error (503).', $row['error_message'] );
		$this->assertSame( 1, $this->h->connections->findPublic( $this->conn )['failures_in_a_row'] );
		$this->assertNotNull( $this->h->connections->findPublic( $this->conn )['last_failure_at'] );
		$this->assertTrue( $failed[0][2], 'will retry' );
		$this->assertNull( $this->h->idMap->get( $this->conn, EntityType::Order, '10490' ) );
		$timeline = $this->h->logs->timeline( $id );
		$this->assertSame( 'warning', $timeline[0]['level'] );
		$this->assertSame( 'SYNC-NET-002', $timeline[0]['code'] );
	}

	public function test_an_item_is_tried_five_times_with_growing_delays_and_then_fails(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$expected = array( 60, 300, 3600, 86400 );
		for ( $try = 1; $try <= 4; ++$try ) {
			$this->h->connector->script[] = new ConnectorError( ErrorCode::Net001 );
			$now = $this->h->clock->now()->getTimestamp();
			$this->assertSame( Outcome::Retrying, $this->process( $id ), "try $try" );
			$row = $this->h->queue->findRow( $id );
			$this->assertSame( $try, $row['attempt'] );
			$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $now + $expected[ $try - 1 ] ), $row['next_attempt_at'], "delay after try $try" );
			$this->h->clock->advance( $expected[ $try - 1 ] );
		}
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Net001 );
		$failed = $this->listen( 'estasoft_sync_item_failed', 3 );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 5, $row['attempt'] );
		$this->assertNull( $row['next_attempt_at'] );
		$this->assertFalse( $failed[0][2], 'no more tries' );
		$this->h->connections->setStatus( $this->conn, ConnectionStatus::Active );
		$this->h->clock->advance( 10 * 86400 );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ), 'failed items are never retried automatically' );
	}

	public function test_an_item_with_fewer_allowed_tries_gives_up_sooner(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET max_attempts = 2 WHERE id = {$id}" ); // phpcs:ignore
		$this->h->connector->script = array( new ConnectorError( ErrorCode::Net001 ), new ConnectorError( ErrorCode::Net001 ) );
		$this->assertSame( Outcome::Retrying, $this->process( $id ) );
		$this->h->clock->advance( 61 );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
	}

	public function test_an_error_a_person_must_fix_fails_at_once_with_its_cause(): void {
		$id = $this->h->item( $this->conn, '10485' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Map001, array( 'sku' => 'CS-BOWL-04' ), 200, null, null, 'No product with the reference CS-BOWL-04' );

		$this->assertSame( Outcome::Failed, $this->process( $id ) );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 1, $row['attempt'] );
		$this->assertSame( 'SYNC-MAP-001', $row['error_code'] );
		$this->assertSame( 'Product CS-BOWL-04 was not found in Fakesystem.', $row['error_message'] );
		$this->assertSame( 'SKU CS-BOWL-04', $this->h->row( $id )['error_cause'] );
		$this->assertSame( 'SKU CS-BOWL-04', $this->h->queue->attentionGroups()[0]['cause'] );
		$this->assertSame( 1, $this->h->connector->count( 'send' ), 'no retry' );
		$entry = $this->h->logs->find( (int) $row['log_id'] );
		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 200, $entry['http_status'] );
		$this->assertSame( 'No product with the reference CS-BOWL-04', $entry['response_excerpt'] );
	}

	public function test_an_answer_that_needs_a_person_does_not_count_as_the_system_being_down(): void {
		$this->h->connections->incrementFailures( $this->conn );
		$this->h->connections->incrementFailures( $this->conn );
		$id = $this->h->item( $this->conn, '10485' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Val002, array( 'field' => 'vat', 'reason' => 'bad' ), 422 );
		$this->process( $id );
		$this->assertSame( 0, $this->h->connections->findPublic( $this->conn )['failures_in_a_row'], 'the system answered, so the run of failures is over' );
	}

	public function test_slow_down_answers_wait_for_retry_after_and_never_pause_the_connection(): void {
		for ( $i = 1; $i <= 8; ++$i ) {
			$id = $this->h->item( $this->conn, (string) ( 200 + $i ) );
			$this->h->connector->script[] = new ConnectorError( ErrorCode::Rate001, array( 'n' => 600 ), 429, 600 );
			$this->assertSame( Outcome::Retrying, $this->process( $id ) );
			$this->assertSame( '2026-11-02T12:10:00Z', $this->h->queue->findRow( $id )['next_attempt_at'], 'the longer Retry-After wins' );
		}
		$connection = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'active', $connection['status'], 'eight 429s are more than the threshold of five' );
		$this->assertSame( 0, $connection['failures_in_a_row'] );
	}

	public function test_a_refused_sign_in_pauses_the_connection_and_leaves_the_item_queued(): void {
		$id     = $this->h->item( $this->conn, '10490' );
		$paused = $this->listen( 'estasoft_sync_connection_paused', 2 );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Auth001, array(), 401 );

		$this->assertSame( Outcome::Held, $this->process( $id ) );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 'SYNC-AUTH-001', $row['error_code'] );
		$this->assertSame( 'Fakesystem refused the sign-in. Check the user and the API key.', $row['error_message'] );
		$connection = $this->h->connections->findPublic( $this->conn );
		$this->assertSame( 'paused', $connection['status'] );
		$this->assertSame( 'person', $connection['paused_reason'] );
		$this->assertSame( array( array( $this->conn, 'sign_in' ) ), $paused->getArrayCopy() );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ), 'workers leave the item alone while the connection is paused' );

		$this->assertTrue( $this->h->pause->resume( $this->conn ) );
		$this->assertCount( 1, $this->h->queue->claim( 10, 60, 'w' ) );
	}

	public function test_an_expired_sign_in_asks_for_a_reconnect_and_the_item_waits(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Auth002, array(), 401 );
		$this->assertSame( Outcome::Held, $this->process( $id ) );
		$this->assertSame( 'needs_reconnect', $this->h->connections->findPublic( $this->conn )['status'] );
		$this->assertSame( 'queued', $this->h->queue->findRow( $id )['state'] );
		$this->assertSame( 'SYNC-AUTH-002', $this->h->queue->findRow( $id )['error_code'] );
	}

	public function test_a_connector_that_throws_fails_only_its_own_item_and_leaks_nothing(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = new \RuntimeException( 'cURL said token=sk-test-abcdefghijklmnop at /var/www/secret.php' );

		$this->assertSame( Outcome::Failed, $this->process( $id ) );

		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 'SYNC-VAL-002', $row['error_code'] );
		$this->assertStringContainsString( 'RuntimeException', (string) $row['error_message'] );
		$this->assertStringNotContainsString( 'sk-test', (string) $row['error_message'] );
		$this->assertStringNotContainsString( 'secret.php', json_encode( $this->h->logs->latest( 10 ) ) );
		$this->assertSame( 'active', $this->h->connections->findPublic( $this->conn )['status'] );
	}

	public function test_a_lookup_that_cannot_reach_the_system_is_retried_and_nothing_is_created_blind(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->existingError = new ConnectorError( ErrorCode::Net001 );
		$this->assertSame( Outcome::Retrying, $this->process( $id ) );
		$this->assertSame( 0, $this->h->connector->count( 'send' ) );
	}

	public function test_a_connector_that_throws_while_looking_up_fails_the_item_too(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->existingThrow = new \RuntimeException( 'password=hunter2' );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
		$this->assertSame( 0, $this->h->connector->count( 'send' ) );
		$this->assertStringNotContainsString( 'hunter2', json_encode( $this->h->logs->latest( 5 ) ) . json_encode( $this->h->queue->findRow( $id ) ) );
	}

	public function test_without_a_payload_builder_the_item_fails_with_sync_cfg_001(): void {
		$this->h->builder = null;
		$id               = $this->h->item( $this->conn, '10490' );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'SYNC-CFG-001', $row['error_code'] );
		$this->assertSame( 'There is no live mapping version for order.', $row['error_message'] );
		$this->assertSame( 0, $row['attempt'], 'not a try' );
		$this->assertSame( 0, $this->h->connector->count( 'send' ) );
	}

	public function test_an_error_found_while_building_the_payload_never_reaches_the_system(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->builder->errors['10490'] = new ConnectorError( ErrorCode::Map002, array( 'country' => 'AE', 'rate' => '5%' ) );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'SYNC-MAP-002', $row['error_code'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 'Tax AE 5%', $this->h->row( $id )['error_cause'] );
		$this->assertSame( 0, $this->h->connector->count( 'send' ) );
		$this->assertSame( 0, $this->h->connector->count( 'findExisting' ) );
		$this->assertNull( $this->h->logs->find( (int) $row['log_id'] )['request_excerpt'], 'nothing was sent, so there is no request to show' );
	}

	public function test_a_payload_builder_that_crashes_fails_the_item_without_leaking_the_message(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->builder->throw = new \LogicException( 'secret internal detail' );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
		$this->assertSame( 'SYNC-VAL-002', $this->h->queue->findRow( $id )['error_code'] );
		$this->assertStringNotContainsString( 'secret internal detail', json_encode( $this->h->logs->latest( 10 ) ) . json_encode( $this->h->queue->findRow( $id ) ) );
	}

	public function test_a_missing_encryption_key_pauses_the_connection_and_the_item_waits(): void {
		$keyless = new CoreHarness( '2026-11-02 12:00:00', null );
		$keyless->reset();
		$conn = $keyless->connection( array(), array() );
		$keyless->sql( "UPDATE `{$keyless->tables->connections()}` SET secrets_enc = 'v1.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' WHERE id = {$conn}" ); // phpcs:ignore
		$id = $keyless->item( $conn, '10490' );

		$this->assertSame( Outcome::Held, $this->process( $id, $keyless ) );

		$row = $keyless->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 'SYNC-CFG-002', $row['error_code'] );
		$this->assertSame( 'The encryption key is missing from wp-config.php.', $row['error_message'] );
		$this->assertSame( 'paused', $keyless->connections->findPublic( $conn )['status'] );
		$this->assertSame( 0, $keyless->connector->count( 'send' ) );
		$this->assertSame( 'SYNC-CFG-002', $keyless->logs->latest( 5 )[0]['code'] );
	}

	public function test_a_changed_key_asks_for_a_reconnect_instead_of_calling_it_a_refused_sign_in(): void {
		$other = new CoreHarness( '2026-11-02 12:00:00', 'a-completely-different-key-9876' );
		$id    = $this->h->item( $this->conn, '10490' );

		$this->assertSame( Outcome::Held, $this->process( $id, $other ) );

		$this->assertSame( 'needs_reconnect', $this->h->connections->findPublic( $this->conn )['status'] );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertNull( $row['error_code'], 'not an AUTH code' );
		$this->assertStringContainsString( 'key', (string) $row['error_message'] );
		$this->assertStringNotContainsString( 'sk-test', json_encode( $this->h->logs->latest( 5 ) ) );
	}

	public function test_items_of_a_connector_that_is_not_installed_wait_quietly(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->registry = new \Estasoft\SyncEngine\Contract\ConnectorRegistry( array() );

		$this->assertSame( Outcome::Held, $this->process( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 'SYNC-PAUSE-001', $row['error_code'] );
		$this->assertSame( '2026-11-02T12:05:00Z', $row['next_attempt_at'] );
		$this->assertCount( 1, $this->h->logs->timeline( $id ) );

		$this->h->clock->advance( 301 );
		$this->assertSame( Outcome::Held, $this->process( $id ) );
		$this->assertCount( 1, $this->h->logs->timeline( $id ), 'logged once, not every five minutes' );

		$this->h->registry = new \Estasoft\SyncEngine\Contract\ConnectorRegistry( array( $this->h->connector ) );
		$this->h->clock->advance( 301 );
		$this->assertSame( Outcome::Synced, $this->process( $id ), 'the add-on is back' );
	}

	public function test_an_item_whose_connection_was_deleted_is_skipped(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$items = $this->h->queue->claim( 5, 60, 'w' );
		$this->h->sql( "DELETE FROM `{$this->h->tables->connections()}` WHERE id = {$this->conn}" ); // phpcs:ignore
		$this->assertSame( Outcome::Skipped, $this->h->processor->process( $items[0] ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'skipped', $row['state'] );
		$this->assertSame( 'The connection was removed.', $row['skip_reason'] );
	}

	public function test_a_connection_paused_after_the_claim_gives_the_item_back_untouched(): void {
		$id    = $this->h->item( $this->conn, '10490' );
		$items = $this->h->queue->claim( 5, 60, 'w' );
		$this->h->pause->pause( $this->conn );
		$this->assertSame( Outcome::Held, $this->h->processor->process( $items[0] ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( 0, $this->h->connector->count( 'send' ) );
		$this->assertSame( 0, $this->h->connector->count( 'findExisting' ) );
	}

	public function test_shadow_mode_builds_the_payload_and_changes_nothing_in_either_system(): void {
		$this->h->connector->documents['WC-10490'] = new ExternalRef( '555', 'S00555' );
		$id     = $this->h->item( $this->conn, '10490', 'created', null, ItemMode::Shadow );
		$shadow = $this->listen( 'estasoft_sync_shadow_processed', 3 );

		$this->assertSame( Outcome::Shadow, $this->process( $id ) );

		$this->assertSame( 0, $this->h->connector->count( 'send' ), 'nothing is sent' );
		$this->assertNull( $this->h->idMap->get( $this->conn, EntityType::Order, '10490' ), 'no link is written' );
		$this->assertNull( $this->h->state->get( $this->conn, EntityType::Order, '10490' ), 'no hash is written' );
		$this->assertFalse( $this->h->idempotency->get( $this->h->row( $id )['idempotency_key'] )['result'] === 'done' );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'shadow', $row['mode'] );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( 'S00555', $row['external_ref'], 'the record found by the read-only lookup' );
		$this->assertCount( 1, $shadow );
		$this->assertSame( 'WC-10490', $shadow[0][1]->payload['client_order_ref'] );
		$this->assertInstanceOf( ExternalRef::class, $shadow[0][2] );
		$this->assertCount( 1, $this->h->connector->documents );
	}

	public function test_shadow_mode_without_a_lookup_capability_does_not_even_look(): void {
		$this->h->connector->capabilityFlags = array( 'orders' => true );
		$id = $this->h->item( $this->conn, '10490', 'created', null, ItemMode::Shadow );
		$this->assertSame( Outcome::Shadow, $this->process( $id ) );
		$this->assertSame( 0, $this->h->connector->count( 'findExisting' ) );
		$this->assertNull( $this->h->queue->findRow( $id )['external_id'] );
	}

	public function test_an_item_that_changed_while_waiting_says_the_latest_version_was_sent(): void {
		$id = $this->h->item( $this->conn, '10490', 'updated' );
		$this->h->queue->noteChangedWhileWaiting( $id );
		$this->assertSame( Outcome::Synced, $this->process( $id ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertNull( $row['error_code'], 'it is not an error and does not stay on the row' );
		$codes = array_column( $this->h->logs->timeline( $id ), 'code' );
		$this->assertContains( 'SYNC-STATE-001', $codes );
	}

	public function test_the_lock_is_extended_while_the_system_is_being_called(): void {
		$id   = $this->h->item( $this->conn, '10490' );
		$seen = array();
		$this->h->connector->script[] = function () use ( $id, &$seen ) {
			$seen = $this->h->row( $id );
			return null;
		};
		$this->process( $id );
		$this->assertSame( 'syncing', $seen['state'] );
		$this->assertSame( '2026-11-02 12:01:30', $seen['locked_until'], 'pushed out to 90 seconds so a slow system cannot lose the lock' );
	}

	public function test_the_connector_can_decide_that_an_error_is_worth_retrying(): void {
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->classOverride = ErrorClass::Retry;
		$this->h->connector->script[]      = new ConnectorError( ErrorCode::Val002, array( 'field' => 'x', 'reason' => 'deadlock' ), 500 );
		$this->assertSame( Outcome::Retrying, $this->process( $id ) );
	}

	public function test_a_site_can_change_the_class_of_an_error_with_a_filter(): void {
		add_filter( 'estasoft_sync_classify_error', static fn( $class, $error ) => ErrorCode::Net001 === $error->code ? ErrorClass::NeedsPerson : $class, 10, 2 );
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->connector->script[] = new ConnectorError( ErrorCode::Net001 );
		$this->assertSame( Outcome::Failed, $this->process( $id ) );
	}

	public function test_a_site_can_change_the_retry_schedule_with_a_filter(): void {
		add_filter( 'estasoft_sync_retry_schedule', static fn() => array( 5 ) );
		$id = $this->h->item( $this->conn, '10490' );
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET max_attempts = 9 WHERE id = {$id}" ); // phpcs:ignore
		$this->h->connector->script = array( new ConnectorError( ErrorCode::Net001 ), new ConnectorError( ErrorCode::Net001 ) );
		$this->assertSame( Outcome::Retrying, $this->process( $id ) );
		$this->assertSame( '2026-11-02T12:00:05Z', $this->h->queue->findRow( $id )['next_attempt_at'] );
		$this->h->clock->advance( 6 );
		$this->assertSame( Outcome::Failed, $this->process( $id ), 'the filter allowed one retry only' );
	}

	public function test_a_bug_in_the_plugin_puts_the_item_back_instead_of_losing_it(): void {
		$id        = $this->h->item( $this->conn, '10490' );
		$processor = new ItemProcessor(
			$this->h->connections,
			$this->h->queue,
			$this->h->idMap,
			$this->h->state,
			$this->h->idempotency,
			$this->h->logger,
			$this->h->retry,
			$this->h->classifier,
			$this->h->breaker,
			$this->h->pause,
			$this->h->clock,
			static function (): never {
				throw new \Error( 'boom' );
			},
			static fn() => null
		);
		$items = $this->h->queue->claim( 5, 60, 'w' );
		$this->assertSame( Outcome::Held, $processor->process( $items[0] ) );
		$row = $this->h->queue->findRow( $id );
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 0, $row['attempt'] );
		$this->assertSame( '2026-11-02T12:05:00Z', $row['next_attempt_at'] );
		$this->assertSame( 'error', $this->h->logs->latest( 1 )[0]['level'] );
	}

	public function test_events_of_one_order_are_sent_in_order(): void {
		$created = $this->h->item( $this->conn, '10490', 'created' );
		$paid    = $this->h->item( $this->conn, '10490', 'paid' );
		$this->assertSame( Outcome::Synced, $this->process( $created ) );
		$this->h->builder->payloads['10490'] = array( 'client_order_ref' => 'WC-10490', 'paid' => true );
		$this->assertSame( Outcome::Synced, $this->process( $paid ) );
		$this->assertSame( array( $created, $paid ), $this->h->builder->built );
	}
}
