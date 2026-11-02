<?php
/**
 * Sending a refund to Qoyod as a credit note.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class QoyodRefundFlowTest extends QoyodTestCase {

	/**
	 * Sync the order the refunds belong to (net 79.98 plus 15 percent, 91.98).
	 */
	private function syncOrder(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function refundItem(): \Estasoft\SyncEngine\Domain\Item {
		return $this->item( EntityType::Refund, '501', 'refunded', '10490' );
	}

	private function taxedRefund( array $override = array() ): array {
		return $this->refundPayload( array_replace( array( 'amount' => 4599, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 600 ) ) ), $override ) );
	}

	public function test_a_refund_becomes_an_approved_credit_note_allocated_to_the_invoice(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 4599, $result->ref->extra['total_minor'] );
		$this->assertSame( 'WC-R-501', $result->ref->ref );
		$notes = $this->server->all( 'credit_notes' );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Approved', $notes[0]['status'] );
		$this->assertSame( "WC-R-501\nRefund of WC-10490: Damaged in transit", $notes[0]['description'], 'The mark is gone once every step is done.' );
		$this->assertSame( '2026-11-03', $notes[0]['issue_date'] );
		$this->assertSame( '15.00', $notes[0]['line_items'][0]['tax_percent'], 'The code is the one that gives the refunded tax.' );

		$invoice = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'Partially Paid', $invoice['status'] );
		$this->assertSame( '45.99', $invoice['due_amount'] );
		$this->assertSame( 'CreditNote', $invoice['payments'][0]['source_type'] );
		$this->assertSame( $notes[0]['id'], $invoice['payments'][0]['source_id'] );

		$body = $this->bodyOf( $this->requests( 'POST', '#/allocations$#' )[0] );
		$this->assertSame( array( 'amount' => '45.99', 'date' => '2026-11-03', 'source_id' => $notes[0]['id'], 'source_type' => 'CreditNote' ), $body['invoice']['allocations_attributes'][0] );
	}

	public function test_a_second_send_of_the_same_refund_changes_nothing(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$writes    = count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'PATCH', '#.#' ) );
		$second    = $connector->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'PATCH', '#.#' ) ) );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['payments'], 'Allocated once.' );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_crash_between_approving_and_allocating_is_finished_by_the_next_pass_and_allocated_once(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$this->server->failWriteAfter( 2, 'timeout' );
		$first = $connector->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'Approved', $this->server->all( 'credit_notes' )[0]['status'] );
		$this->assertCount( 0, $this->server->all( 'invoices' )[0]['payments'] );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->refundItem() ), 'Not finished: the mark is still on the description.' );

		$retry = $connector->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['payments'] );
		$this->assertNotNull( $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_paid_invoice_leaves_the_credit_note_unallocated_with_a_warning(): void {
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_account_id' => '7' ) );
		$payload    = $this->payload( array( 'total' => 9198, 'payment' => array( 'date' => '2026-11-02' ) ) );
		$payload['lines'][0]['tax_code'] = '1';
		$this->assertTrue( $this->connector()->send( $connection, $this->item(), $payload )->isSuccess() );
		$this->assertSame( 'Paid', $this->server->all( 'invoices' )[0]['status'] );

		$result = $this->connector()->send( $connection, $this->refundItem(), $this->taxedRefund() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'Approved', $this->server->all( 'credit_notes' )[0]['status'] );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['payments'], 'Only the receipt.' );
		$this->assertCount( 0, $this->requests( 'POST', '#/allocations$#' ) );
	}

	public function test_a_refused_allocation_is_a_warning_and_the_credit_note_stays_approved(): void {
		$this->syncOrder();
		$this->server->refuseAllocations = true;
		$result                          = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertNotNull( $this->connector()->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_server_that_will_not_edit_an_approved_credit_note_does_not_undo_the_refund(): void {
		$this->syncOrder();
		$this->server->lockApproved = true;
		$result                     = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$this->assertFalse( $result->isSuccess(), 'The mark cannot be removed, so the pass reports it and the next pass finds everything done.' );
		$this->server->lockApproved = false;
		$retry                      = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund() );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
		$this->assertCount( 1, $this->server->all( 'invoices' )[0]['payments'] );
	}

	public function test_a_refund_without_lines_needs_the_fees_product_and_then_is_one_line(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 3000, 'excluded_amount' => 500, 'lines' => array() ) );
		$refused = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( 'fee_product_sku', $refused->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );

		$result = $this->connector()->send( $this->connection( array( 'fee_product_sku' => 'SRV-FEE' ) ), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->server->all( 'credit_notes' )[0]['line_items'];
		$this->assertCount( 1, $lines );
		$this->assertSame( '25.00', $lines[0]['unit_price'] );
	}

	public function test_a_refund_for_an_order_qoyod_does_not_have_or_has_only_as_a_draft_is_refused(): void {
		$none = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $none->error->code );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );

		$connection = $this->connection( array( 'approval' => 'draft' ) );
		$this->connector()->send( $connection, $this->item(), $this->payload() );
		$draft = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $draft->error->code );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
	}

	public function test_a_tax_that_no_standard_rate_explains_is_map_002_and_a_total_that_differs_stays_a_draft(): void {
		$this->syncOrder();
		$odd = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund( array( 'amount' => 4399, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 400 ) ) ) ) );
		$this->assertSame( ErrorCode::Map002, $odd->error->code );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );

		$wrong = $this->connector()->send( $this->connection(), $this->refundItem(), $this->taxedRefund( array( 'amount' => 5000 ) ) );
		$this->assertSame( ErrorCode::Val002, $wrong->error->code );
		$this->assertSame( 'Draft', $this->server->all( 'credit_notes' )[0]['status'] );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_refund_of_a_product_qoyod_no_longer_has_is_map_001(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'lines' => array( array( 'sku' => 'GONE-SKU', 'quantity' => 1, 'amount' => 3999 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
	}
}
