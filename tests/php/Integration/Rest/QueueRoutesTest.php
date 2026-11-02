<?php
/**
 * The queue, sync and pause routes against the real queue service and database.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class QueueRoutesTest extends RestIntegrationCase {

	private int $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
		$this->actAs( 'shop_manager' );
	}

	/**
	 * A failed item, the way a missing product leaves one.
	 *
	 * @param string $order Order id.
	 * @param string $sku   SKU.
	 */
	private function failed( string $order, string $sku = 'CS-LAMP-07' ): int {
		$id = $this->h->item( $this->conn, $order );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', "Product $sku was not found in Fake ERP.", "SKU $sku" );
		return $id;
	}

	public function test_the_list_is_a_page_with_the_wordpress_pagination_headers(): void {
		for ( $i = 1; $i <= 30; ++$i ) {
			$this->h->item( $this->conn, (string) ( 20000 + $i ) );
		}
		$response = $this->call( 'GET', '/queue', array( 'per_page' => 25 ) );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 25, $data['items'] );
		$this->assertSame( 30, $data['total'] );
		$this->assertSame( 2, $data['total_pages'] );
		$this->assertSame( 1, $data['page'] );
		$headers = $response->get_headers();
		$this->assertSame( '30', $headers['X-WP-Total'] );
		$this->assertSame( '2', $headers['X-WP-TotalPages'] );
		$this->assertCount( 5, $this->call( 'GET', '/queue', array( 'per_page' => 25, 'page' => 2 ) )->get_data()['items'] );
	}

	public function test_the_list_shows_live_items_only_unless_asked(): void {
		$this->h->item( $this->conn, '1' );
		$this->h->item( $this->conn, '2', 'created', null, ItemMode::Shadow );
		$this->assertCount( 1, $this->call( 'GET', '/queue' )->get_data()['items'] );
		$this->assertCount( 1, $this->call( 'GET', '/queue', array( 'mode' => 'shadow' ) )->get_data()['items'] );
		$this->assertCount( 2, $this->call( 'GET', '/queue', array( 'mode' => 'all' ) )->get_data()['items'] );
	}

	public function test_the_list_filters_by_state_and_error_code_and_search(): void {
		$this->failed( '10485' );
		$this->h->item( $this->conn, '10490' );
		$this->assertCount( 1, $this->call( 'GET', '/queue', array( 'state' => 'failed' ) )->get_data()['items'] );
		$this->assertCount( 1, $this->call( 'GET', '/queue', array( 'state' => 'waiting' ) )->get_data()['items'] );
		$this->assertCount( 1, $this->call( 'GET', '/queue', array( 'error_code' => 'SYNC-MAP-001' ) )->get_data()['items'] );
		$this->assertCount( 1, $this->call( 'GET', '/queue', array( 'search' => '10490' ) )->get_data()['items'] );
	}

	public function test_a_page_size_outside_25_50_100_and_a_bad_state_are_refused_with_the_error_body(): void {
		$response = $this->call( 'GET', '/queue', array( 'per_page' => 7 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 400, $this->call( 'GET', '/queue', array( 'state' => 'nonsense' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/queue', array( 'error_code' => "SYNC-MAP-001'; DROP" ) )->get_status() );
	}

	public function test_the_screen_option_sets_the_page_size_when_the_request_names_none(): void {
		for ( $i = 1; $i <= 60; ++$i ) {
			$this->h->item( $this->conn, (string) ( 30000 + $i ) );
		}
		update_user_meta( $this->userId( 'shop_manager' ), 'estasoft_sync_per_page', '50' );
		$this->assertSame( 50, $this->call( 'GET', '/queue' )->get_data()['per_page'] );
		$this->assertSame( 25, $this->call( 'GET', '/queue', array( 'per_page' => 25 ) )->get_data()['per_page'] );
		delete_user_meta( $this->userId( 'shop_manager' ), 'estasoft_sync_per_page' );
		$this->assertSame( 25, $this->call( 'GET', '/queue' )->get_data()['per_page'] );
	}

	public function test_attention_groups_and_item_detail(): void {
		$one = $this->failed( '1' );
		$this->failed( '2' );
		$groups = $this->call( 'GET', '/queue/attention' )->get_data();
		$this->assertCount( 1, $groups );
		$this->assertSame( 'SYNC-MAP-001', $groups[0]['error_code'] );
		$this->assertSame( 'SKU CS-LAMP-07', $groups[0]['cause'] );
		$this->assertSame( 2, $groups[0]['count'] );

		$detail = $this->call( 'GET', '/queue/' . $one )->get_data();
		$this->assertSame( $one, $detail['id'] );
		$this->assertSame( 'failed', $detail['state'] );
		$this->assertArrayHasKey( 'timeline', $detail );
		$this->assertArrayHasKey( 'siblings', $detail );

		$missing = $this->call( 'GET', '/queue/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'rest_not_found', $missing->get_data()['code'] );
	}

	public function test_retry_one_moves_a_failed_item_to_the_queue_and_refuses_one_that_is_synced(): void {
		$failed = $this->failed( '1' );
		$synced = $this->h->item( $this->conn, '2' );
		$this->h->queue->markSynced( $synced, '2', 'S2' );

		$ok = $this->call( 'POST', "/queue/$failed/retry" );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'queued', $ok->get_data()['state'] );

		$refused = $this->call( 'POST', "/queue/$synced/retry" );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'needs_person', $refused->get_data()['data']['class'] );
		$this->assertSame( 409, $refused->get_data()['data']['status'] );
	}

	public function test_skip_needs_nothing_but_records_the_reason_and_restore_brings_it_back(): void {
		$id = $this->h->item( $this->conn, '1' );
		$skipped = $this->call( 'POST', "/queue/$id/skip", array( 'reason' => 'Test order <b>bold</b>' ) )->get_data();
		$this->assertSame( 'skipped', $skipped['state'] );
		$this->assertSame( 'Test order bold', $skipped['skip_reason'], 'tags are removed from the reason' );
		$this->assertSame( 'queued', $this->call( 'POST', "/queue/$id/restore" )->get_data()['state'] );
		$this->assertSame( 409, $this->call( 'POST', "/queue/$id/restore" )->get_status(), 'it is no longer skipped' );
	}

	public function test_retry_a_whole_group_by_error_code_and_cause(): void {
		$this->failed( '1' );
		$this->failed( '2' );
		$this->failed( '3', 'OTHER-SKU' );
		$this->assertSame( 2, $this->call( 'POST', '/queue/retry', array( 'error_code' => 'SYNC-MAP-001', 'cause' => 'SKU CS-LAMP-07' ) )->get_data()['retried'] );
		$this->assertSame( 1, $this->call( 'POST', '/queue/retry', array( 'error_code' => 'SYNC-MAP-001' ) )->get_data()['retried'] );
	}

	public function test_retry_by_ids_and_a_request_with_neither_ids_nor_code_is_refused(): void {
		$one = $this->failed( '1' );
		$two = $this->failed( '2' );
		$this->assertSame( 2, $this->call( 'POST', '/queue/retry', array( 'ids' => array( $one, $two, 424242 ) ) )->get_data()['retried'] );
		$empty = $this->call( 'POST', '/queue/retry', array() );
		$this->assertSame( 422, $empty->get_status() );
		$this->assertArrayHasKey( 'ids', $empty->get_data()['data']['fields'] );
	}

	public function test_bulk_skip_retry_and_restore(): void {
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$this->assertSame( 2, $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $a, $b ), 'action' => 'skip', 'reason' => 'tests' ) )->get_data()['done'] );
		$this->assertSame( 2, $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $a, $b ), 'action' => 'restore' ) )->get_data()['done'] );
		$this->assertSame( 400, $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $a ), 'action' => 'explode' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/queue/bulk', array( 'action' => 'retry' ) )->get_status(), 'ids are required' );
	}

	public function test_the_same_idempotency_key_replays_the_first_answer_instead_of_acting_again(): void {
		$id      = $this->failed( '1' );
		$headers = array( 'Idempotency-Key' => 'retry-' . $id );

		$first = $this->call( 'POST', "/queue/$id/retry", array(), $headers );
		$this->assertSame( 200, $first->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $first->get_headers() );

		// The item is queued now, so a second real retry would be a 409. The replay returns the first answer.
		$second = $this->call( 'POST', "/queue/$id/retry", array(), $headers );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 'true', $second->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( $first->get_data(), $second->get_data() );

		$other = $this->call( 'POST', "/queue/$id/retry", array(), array( 'Idempotency-Key' => 'another-key' ) );
		$this->assertSame( 409, $other->get_status(), 'a new key acts again' );
	}

	public function test_a_key_used_for_a_different_request_body_is_a_conflict_and_a_bad_key_is_refused(): void {
		$a       = $this->failed( '1' );
		$b       = $this->failed( '2' );
		$headers = array( 'Idempotency-Key' => 'shared-key' );
		$this->assertSame( 200, $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $a ), 'action' => 'retry' ), $headers )->get_status() );
		$mismatch = $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $b ), 'action' => 'retry' ), $headers );
		$this->assertSame( 409, $mismatch->get_status() );
		$this->assertSame( 'estasoft_sync_idempotency_conflict', $mismatch->get_data()['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/queue/bulk', array( 'ids' => array( $b ), 'action' => 'retry' ), array( 'Idempotency-Key' => 'not valid!' ) )->get_status() );
	}

	public function test_an_error_answer_is_not_replayed(): void {
		$id      = $this->h->item( $this->conn, '1' );
		$headers = array( 'Idempotency-Key' => 'only-after-success' );
		$this->assertSame( 409, $this->call( 'POST', "/queue/$id/retry", array(), $headers )->get_status(), 'a queued item cannot be retried' );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', 'x', 'SKU A' );
		$this->assertSame( 200, $this->call( 'POST', "/queue/$id/retry", array(), $headers )->get_status(), 'the error was not kept, so the same key runs again' );
	}

	public function test_sync_run_asks_for_a_run(): void {
		$response = $this->call( 'POST', '/sync/run' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'started' => true ), $response->get_data() );
	}

	public function test_pause_and_resume_a_connection_and_all_connections(): void {
		$summaries = $this->replaceSummaries();
		$response  = $this->call( 'POST', '/pause', array( 'connection_id' => $this->conn, 'minutes' => 60 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->conn, $response->get_data()['id'] );
		$this->assertSame( 'paused', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertNotNull( $this->h->connectionRow( $this->conn )['paused_until'] );

		$this->assertSame( 200, $this->call( 'POST', '/resume', array( 'connection_id' => $this->conn ) )->get_status() );
		$this->assertSame( 'active', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertSame( 409, $this->call( 'POST', '/resume', array( 'connection_id' => $this->conn ) )->get_status(), 'it is not paused' );

		$this->assertSame( 200, $this->call( 'POST', '/pause' )->get_status(), 'no id pauses every connection' );
		$this->assertSame( 'paused', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertSame( 200, $this->call( 'POST', "/connections/{$this->conn}/resume" )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', "/connections/{$this->conn}/pause", array( 'minutes' => 5 ) )->get_status() );
		$this->assertNotEmpty( $summaries->calls );
	}

	public function test_pause_refuses_minutes_outside_the_range_and_an_unknown_connection(): void {
		$this->replaceSummaries();
		$this->assertSame( 400, $this->call( 'POST', '/pause', array( 'minutes' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/pause', array( 'minutes' => 999999 ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', '/pause', array( 'connection_id' => 424242 ) )->get_status() );
	}

	/**
	 * The connection service answers with a summary of the harness connection (its connector is not installed).
	 */
	private function replaceSummaries(): \Estasoft\SyncEngine\Tests\Support\RestFake {
		$fake = new \Estasoft\SyncEngine\Tests\Support\RestFake(
			array(
				'summaries' => fn(): array => array(
					array(
						'id'     => $this->conn,
						'status' => (string) $this->h->connectionRow( $this->conn )['status'],
					),
				),
			)
		);
		$this->replace( \Estasoft\SyncEngine\Jobs\JobServices::CONNECTIONS, $fake );
		return $fake;
	}
}
