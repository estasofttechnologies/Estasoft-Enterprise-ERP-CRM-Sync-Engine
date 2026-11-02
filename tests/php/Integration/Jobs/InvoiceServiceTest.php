<?php
/**
 * INV-1 to INV-6, AS-23.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\Invoices\InvoiceService;

final class InvoiceServiceTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
	}

	/**
	 * A synced order the system knows as sale order SO-{id}.
	 *
	 * @param int $orderId Order id.
	 */
	private function syncedOrder( int $orderId ): int {
		$this->j->orders->add( $orderId, array( 'sync_state' => 'synced' ) );
		$item = $this->h->item( $this->connection, (string) $orderId, 'created', null, \Estasoft\SyncEngine\Domain\ItemMode::Live, array( 'label' => (string) $orderId ) );
		$this->h->queue->markSynced( $item, 'SO-' . $orderId, 'S' . $orderId, 'https://erp.example.test/so/' . $orderId );
		$this->h->idMap->link( $this->connection, 'order', (string) $orderId, 'SO-' . $orderId, 'S' . $orderId, 'https://erp.example.test/so/' . $orderId );
		return $item;
	}

	/**
	 * Facts as the connector returns them.
	 *
	 * @param array<string,string> $over Values over the defaults.
	 * @return array<string,string>
	 */
	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'number'           => 'INV/2026/1201',
				'status'           => 'posted',
				'authority_status' => 'waiting',
				'pdf_url'          => 'https://erp.example.test/pdf/1',
				'qr'               => 'QRDATA',
				'issued_at'        => '2026-11-02T07:00:00Z',
			),
			$over
		);
	}

	public function test_open_invoices_are_read_saved_and_shown_with_time_left(): void {
		$this->syncedOrder( 501 );
		$updated                                 = $this->listen( 'estasoft_sync_invoice_updated', 2 );
		$this->j->connector->invoiceFor['SO-501'] = $this->facts();

		$summary = $this->j->invoiceService->run();

		$this->assertSame( 1, $summary['checked'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( 'INV/2026/1201', $this->j->orders->invoices[501]['number'] );
		$this->assertCount( 1, $updated );
		$list = $this->j->invoiceService->list( array( 'status' => 'waiting' ) );
		$this->assertSame( 1, $list['total'] );
		$row = $list['items'][0];
		$this->assertSame( 'waiting', $row['status'] );
		$this->assertSame( '2026-11-03T07:00:00Z', $row['due_at'] );
		$this->assertSame( 19 * 3600, $row['seconds_left'], 'issued 07:00, due 07:00 tomorrow, now 12:00' );
		$this->assertSame( 48615, $row['total']['minor'] );
		$this->assertSame( 'https://erp.example.test/pdf/1', $row['pdf_url'] );
	}

	public function test_five_hours_left_and_a_rejected_invoice(): void {
		$this->syncedOrder( 501 );
		$this->syncedOrder( 502 );
		$this->j->connector->invoiceFor['SO-501'] = $this->facts( array( 'issued_at' => '2026-11-01T17:00:00Z' ) );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts(
			array(
				'number'           => 'INV/2026/1202',
				'authority_status' => 'rejected',
				'reject_reason'    => 'Buyer VAT number invalid',
			)
		);

		$summary = $this->j->invoiceService->run();

		$this->assertSame( 1, $summary['rejected'] );
		$rows = $this->j->invoiceService->list( array() )['items'];
		$byOrder = array_column( $rows, null, 'order_id' );
		$this->assertSame( 5 * 3600, $byOrder[501]['seconds_left'] );
		$this->assertSame( 'rejected', $byOrder[502]['status'] );
		$this->assertSame( 'Buyer VAT number invalid', $byOrder[502]['reject_reason'] );

		$groups = $this->h->queue->attentionGroups();
		$this->assertSame( 'SYNC-COMP-001', $groups[0]['error_code'] );
		$this->assertSame( 1, $groups[0]['count'] );
		$failed = $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( 'failed', 'invoice' ) )->items;
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'INV/2026/1202', (string) $failed[0]['error_message'] );
		$this->assertStringContainsString( 'Buyer VAT number invalid', (string) $failed[0]['error_message'] );
		$this->assertSame( 'invoice:502', $this->h->row( (int) $failed[0]['id'] )['partition_key'], 'the failed invoice sits in its own partition so it cannot block the order' );
	}

	public function test_a_rejection_is_resolved_when_the_invoice_is_reported_and_final_invoices_are_not_polled_again(): void {
		$this->syncedOrder( 502 );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts( array( 'authority_status' => 'rejected', 'reject_reason' => 'Bad' ) );
		$this->j->invoiceService->run();
		$this->assertCount( 1, $this->h->queue->attentionGroups() );

		$this->h->clock->advance( 600 );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts( array( 'authority_status' => 'reported' ) );
		$this->j->invoiceService->run();

		$this->assertCount( 0, $this->h->queue->attentionGroups() );
		$this->assertSame( 'reported', $this->j->orders->invoices[502]['status'] );
		$this->assertNull( $this->j->invoiceService->list( array( 'status' => 'reported' ) )['items'][0]['due_at'] );

		$before = $this->j->connector->count( 'fetchInvoice' );
		$this->h->clock->advance( 3600 );
		$this->j->invoiceService->run();
		$this->assertSame( $before, $this->j->connector->count( 'fetchInvoice' ), 'a reported invoice is final' );
	}

	/**
	 * How many times a rejection was written to the log.
	 */
	private function rejectionLogs(): int {
		return count( array_filter( $this->h->logs->latest( 100 ), static fn( array $entry ): bool => 'SYNC-COMP-001' === $entry['code'] ) );
	}

	public function test_a_rejection_that_does_not_change_is_noted_once_not_on_every_poll(): void {
		$this->syncedOrder( 502 );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts( array( 'authority_status' => 'rejected', 'reject_reason' => 'Buyer VAT number invalid' ) );

		$first = $this->j->invoiceService->run();
		$this->assertSame( 1, $first['rejected'] );
		$this->assertSame( 1, $this->rejectionLogs() );
		$failedAt = $this->h->row( (int) $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( 'failed', 'invoice' ) )->items[0]['id'] )['updated_at'];

		foreach ( range( 1, 5 ) as $round ) {
			$this->h->clock->advance( 600 );
			$again = $this->j->invoiceService->run();
			$this->assertSame( 1, $again['checked'], 'it is still asked about, a rejected invoice is not final' );
			$this->assertSame( 0, $again['rejected'], 'but nothing new is reported in round ' . $round );
		}

		$this->assertSame( 1, $this->rejectionLogs(), 'one error in the log, not one every ten minutes' );
		$items = $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( 'failed', 'invoice' ) )->items;
		$this->assertCount( 1, $items );
		$this->assertSame( $failedAt, $this->h->row( (int) $items[0]['id'] )['updated_at'], 'the failed item was not rewritten' );
	}

	public function test_a_rejection_with_a_new_reason_or_an_item_that_is_no_longer_failed_is_noted_again(): void {
		$this->syncedOrder( 502 );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts( array( 'authority_status' => 'rejected', 'reject_reason' => 'Buyer VAT number invalid' ) );
		$this->j->invoiceService->run();

		$this->h->clock->advance( 600 );
		$this->j->connector->invoiceFor['SO-502'] = $this->facts( array( 'authority_status' => 'rejected', 'reject_reason' => 'Seller address missing' ) );
		$changed = $this->j->invoiceService->run();
		$this->assertSame( 1, $changed['rejected'], 'a new reason is news' );
		$this->assertSame( 2, $this->rejectionLogs() );
		$failed = $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( 'failed', 'invoice' ) )->items;
		$this->assertStringContainsString( 'Seller address missing', (string) $failed[0]['error_message'] );

		// A person sends the item back to the queue; the next poll finds the same rejection and fails it again.
		$this->assertTrue( $this->h->queue->retry( (int) $failed[0]['id'] ) );
		$this->h->clock->advance( 600 );
		$third = $this->j->invoiceService->run();
		$this->assertSame( 1, $third['rejected'] );
		$this->assertSame( 3, $this->rejectionLogs() );
		$this->assertCount( 1, $this->h->queue->query( new \Estasoft\SyncEngine\Core\Repositories\QueueQuery( 'failed', 'invoice' ) )->items );
	}

	public function test_an_old_open_invoice_is_still_polled_behind_many_newer_orders(): void {
		$ids = range( 601, 607 );
		foreach ( $ids as $id ) {
			$this->syncedOrder( $id );
		}
		// The six newest are settled; only the oldest still waits for its invoice.
		foreach ( array_slice( $ids, 1 ) as $id ) {
			$this->h->state->mergeExtra( $this->connection, 'order', (string) $id, array( 'invoice_final' => true ) );
		}
		$this->j->connector->invoiceFor['SO-601'] = $this->facts();
		$service = new InvoiceService( $this->j->orders, $this->h->state, $this->h->idMap, $this->h->queue, $this->h->enqueuer, $this->j->gateway, $this->h->redactor, $this->h->logger, $this->h->options, $this->h->clock, 20, 2 );

		$summary = $service->run();

		$this->assertSame( 1, $summary['checked'], 'seven synced orders over four pages of two: the oldest was reached' );
		$this->assertArrayHasKey( 601, $this->j->orders->invoices );
		$this->assertTrue( $summary['complete'] );
	}

	public function test_the_longest_unchecked_open_invoice_goes_first(): void {
		$this->syncedOrder( 701 );
		$this->syncedOrder( 702 );
		$this->j->connector->invoiceFor['SO-701'] = $this->facts();
		$this->j->connector->invoiceFor['SO-702'] = $this->facts( array( 'number' => 'INV/2026/1202' ) );
		$this->h->state->mergeExtra( $this->connection, 'order', '702', array( 'invoice_checked_at' => '2026-11-02T10:00:00Z' ) );

		$this->j->invoiceService->run();

		$asked = array_values( array_map( static fn( array $call ) => $call[1], array_filter( $this->j->connector->calls, static fn( array $call ): bool => 'fetchInvoice' === $call[0] ) ) );
		$this->assertCount( 2, $asked );
		$this->assertSame( 'SO-701', (string) $asked[0], 'never checked goes before checked two hours ago' );
	}

	public function test_an_order_is_not_asked_about_again_within_five_minutes_and_unchanged_facts_save_nothing(): void {
		$this->syncedOrder( 501 );
		$this->j->connector->invoiceFor['SO-501'] = $this->facts();
		$this->j->invoiceService->run();
		$this->j->invoiceService->run();
		$this->assertSame( 1, $this->j->connector->count( 'fetchInvoice' ) );

		$updated = $this->listen( 'estasoft_sync_invoice_updated', 2 );
		$this->h->clock->advance( 700 );
		$again = $this->j->invoiceService->run();
		$this->assertSame( 1, $again['checked'] );
		$this->assertSame( 0, $again['updated'] );
		$this->assertCount( 0, $updated );
	}

	public function test_an_order_with_no_invoice_yet_is_asked_about_later(): void {
		$this->syncedOrder( 501 );
		$this->j->invoiceService->run();
		$this->assertArrayNotHasKey( 501, $this->j->orders->invoices );
		$this->h->clock->advance( 700 );
		$this->j->connector->invoiceFor['SO-501'] = $this->facts();
		$this->j->invoiceService->run();
		$this->assertArrayHasKey( 501, $this->j->orders->invoices );
	}

	public function test_a_connector_failure_stops_that_connection_and_is_counted(): void {
		$this->syncedOrder( 501 );
		$this->syncedOrder( 502 );
		$this->j->connector->fail = array( 'fetchInvoice' => new ConnectorError( ErrorCode::Net002, array( 'system' => 'Fakesystem', 'status' => 503 ), 503 ) );

		$summary = $this->j->invoiceService->run();

		$this->assertSame( 1, $summary['errors'] );
		$this->assertSame( 1, $this->j->connector->count( 'fetchInvoice' ) );
	}

	public function test_the_window_is_editable_validated_and_changes_the_due_time(): void {
		$this->syncedOrder( 501 );
		$this->j->connector->invoiceFor['SO-501'] = $this->facts();
		$this->j->invoiceService->run();
		$this->assertSame( array( 'window_hours' => 24 ), $this->j->invoiceService->settings() );

		$this->assertSame( array( 'window_hours' => 12 ), $this->j->invoiceService->saveWindow( 12 ) );

		$this->assertSame( array( 'window_hours' => 12 ), $this->j->invoiceService->settings() );
		$this->assertSame( 12, $this->j->invoiceService->window() );
		$this->assertSame( 7 * 3600, $this->j->invoiceService->list( array() )['items'][0]['seconds_left'] );
		$this->assertSame( array( 'window_hours' => 1 ), $this->j->invoiceService->saveWindow( 1 ), 'the lowest allowed' );
		$this->assertSame( array( 'window_hours' => 168 ), $this->j->invoiceService->saveWindow( 168 ), 'the highest allowed' );
	}

	/**
	 * Hours that must be refused.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function badWindows(): array {
		return array(
			'zero'          => array( 0 ),
			'negative'      => array( -24 ),
			'just too many' => array( 169 ),
			'a year'        => array( 8760 ),
		);
	}

	/**
	 * @dataProvider badWindows
	 *
	 * @param int $hours Hours.
	 */
	public function test_a_window_outside_one_to_168_hours_is_refused_and_nothing_changes(int $hours): void {
		$this->j->invoiceService->saveWindow( 30 );
		try {
			$this->j->invoiceService->saveWindow( $hours );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'window_hours', $e->errors() );
			$this->assertStringContainsString( '1 to 168', $e->errors()['window_hours'] );
		}
		$this->assertSame( array( 'window_hours' => 30 ), $this->j->invoiceService->settings() );
	}

	public function test_the_list_filters_search_and_pages(): void {
		foreach ( array( 501, 502, 503 ) as $id ) {
			$this->syncedOrder( $id );
			$this->j->connector->invoiceFor[ 'SO-' . $id ] = $this->facts( array( 'number' => 'INV/' . $id, 'authority_status' => 503 === $id ? 'reported' : 'waiting' ) );
		}
		$this->j->invoiceService->run();

		$this->assertSame( 2, $this->j->invoiceService->list( array( 'status' => 'waiting' ) )['total'] );
		$this->assertSame( 1, $this->j->invoiceService->list( array( 'status' => 'reported' ) )['total'] );
		$this->assertSame( 3, $this->j->invoiceService->list( array( 'status' => 'bogus' ) )['total'], 'an unknown status means all' );
		$this->assertSame( 1, $this->j->invoiceService->list( array( 'search' => 'INV/502' ) )['total'] );
		$page = $this->j->invoiceService->list( array( 'per_page' => 25, 'page' => 2 ) );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( 25, $page['per_page'] );
		$this->assertSame( array(), $page['items'] );
	}

	public function test_the_plugin_has_no_signing_or_submission_code(): void {
		$files = array_merge( glob( dirname( __DIR__, 4 ) . '/includes/Jobs/Invoices/*.php' ) ?: array(), array( dirname( __DIR__, 4 ) . '/includes/Jobs/Tracking/TrackingService.php' ) );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			foreach ( array( 'openssl_sign', 'sodium_crypto_sign', 'hash_hmac', 'wp_remote_', 'curl_' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $code, basename( $file ) . ' must not contain ' . $forbidden );
			}
		}
	}
}
