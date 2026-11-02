<?php
/**
 * Sending a refund to Wafeq as a credit note.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class WafeqRefundFlowTest extends WafeqTestCase {

	/**
	 * Sync the order the refunds belong to (net 79.98 plus 15 percent, 91.98).
	 */
	private function syncOrder(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = 'tax_15';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function refundItem(): \Estasoft\SyncEngine\Domain\Item {
		return $this->item( EntityType::Refund, '501', 'refunded', '10490' );
	}

	public function test_a_refund_becomes_a_finalized_credit_note_applied_to_the_invoice(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 4599, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 600 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 4599, $result->ref->extra['total_minor'] );
		$notes = $this->server->all( 'credit-notes' );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'FINALIZED', $notes[0]['status'] );
		$this->assertSame( 'WC-R-501', $notes[0]['external_id'] );
		$this->assertSame( 'WC-R-501', $notes[0]['reference'], 'The mark is gone once every step is done.' );
		$this->assertSame( 'cnt_000001', $notes[0]['contact'] );
		$this->assertSame( '2026-11-03', $notes[0]['credit_note_date'] );
		$this->assertStringContainsString( 'WC-10490', $notes[0]['notes'] );
		$this->assertStringContainsString( 'Damaged in transit', $notes[0]['notes'] );
		$this->assertSame( 'tax_15', $notes[0]['line_items'][0]['tax_rate'], 'The rate is the one that gives the refunded tax.' );
		$this->assertSame( 'itm_mug', $notes[0]['line_items'][0]['item'] );

		$invoice = $this->server->all( 'invoices' )[0];
		$this->assertCount( 1, $invoice['credit_notes'] );
		$this->assertSame( $notes[0]['id'], $invoice['credit_notes'][0]['credit_note'] );
		$this->assertSame( '45.99', $invoice['credit_notes'][0]['amount'] );
	}

	public function test_a_second_send_of_the_same_refund_changes_nothing(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$payload   = $this->refundPayload( array( 'amount' => 4599, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 600 ) ) ) );
		$first     = $connector->send( $this->connection(), $this->refundItem(), $payload );
		$writes    = count( $this->requests( 'POST', '#/v1/#' ) ) + count( $this->requests( 'PATCH', '#/v1/#' ) );
		$second    = $connector->send( $this->connection(), $this->refundItem(), $payload );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#/v1/#' ) ) + count( $this->requests( 'PATCH', '#/v1/#' ) ) );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['credit_notes'], 'Applied once, although Wafeq would allow it twice.' );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_crash_between_finalizing_and_applying_is_finished_by_the_next_pass_and_applied_once(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$payload   = $this->refundPayload( array( 'amount' => 3999 ) );
		$this->server->failWriteAfter( 2, 'timeout' );
		$first = $connector->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'FINALIZED', $this->server->all( 'credit-notes' )[0]['status'] );
		$this->assertCount( 0, $this->server->all( 'invoices' )[0]['credit_notes'] );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->refundItem() ), 'Not finished: the mark is still on the reference.' );

		$retry = $connector->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'credit-notes' ) );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['credit_notes'] );
		$this->assertNotNull( $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_shipping_and_other_amounts_are_lines_without_tax_and_the_total_matches_the_refund(): void {
		$this->syncOrder();
		$payload = $this->refundPayload(
			array(
				'amount'          => 4599 + 2875 + 100,
				'shipping_amount' => 2875,
				'other_amount'    => 100,
				'lines'           => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 600 ) ),
			)
		);
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->server->all( 'credit-notes' )[0]['line_items'];
		$this->assertCount( 3, $lines );
		$this->assertSame( 'Shipping', $lines[1]['description'] );
		$this->assertArrayNotHasKey( 'tax_rate', $lines[1] );
		$this->assertSame( 7574, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_without_lines_is_one_line_for_the_amount_and_products_left_out_are_not_refunded(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 3000, 'excluded_amount' => 500, 'lines' => array() ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->server->all( 'credit-notes' )[0]['line_items'];
		$this->assertCount( 1, $lines );
		$this->assertSame( 25.0, $lines[0]['unit_amount'] );
	}

	public function test_a_refund_for_an_order_wafeq_does_not_have_is_refused_before_anything_is_written(): void {
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_tax_that_no_rate_explains_is_map_002(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 4399, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 400 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'credit-notes' ) );
	}

	public function test_a_total_that_differs_leaves_the_credit_note_a_draft(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 5000 ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'DRAFT', $this->server->all( 'credit-notes' )[0]['status'] );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_refused_application_is_a_warning_and_the_credit_note_stays_posted(): void {
		$this->syncOrder();
		$this->server->refuseApplications = true;
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'FINALIZED', $this->server->all( 'credit-notes' )[0]['status'] );
		$this->assertNotNull( $this->connector()->findExisting( $this->connection(), $this->refundItem() ), 'The refused application is final, it is not tried on every pass.' );
	}

	public function test_a_refund_never_stops_on_an_unknown_sku(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'lines' => array( array( 'sku' => 'GONE-SKU', 'quantity' => 1, 'amount' => 3999 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->server->all( 'credit-notes' )[0]['line_items'][0];
		$this->assertArrayNotHasKey( 'item', $line );
		$this->assertSame( 'GONE-SKU', $line['description'] );
	}
}
