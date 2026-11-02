<?php
/**
 * Sending a refund to Daftra as a credit note.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Daftra;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

final class DaftraRefundFlowTest extends DaftraTestCase {

	/**
	 * Sync the order the refunds belong to (net 79.98 plus 15 percent, 91.98).
	 */
	private function syncOrder( array $config = array() ): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection( $config ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function refundItem(): Item {
		return $this->item( EntityType::Refund, '501', 'refunded', '10490' );
	}

	/**
	 * A refund of one mug: 39.99 net and 6.00 tax.
	 *
	 * @param array<string,mixed> $override Values that replace the defaults.
	 * @return array<string,mixed>
	 */
	private function oneMug( array $override = array() ): array {
		return $this->refundPayload(
			array_replace(
				array(
					'amount' => 4599,
					'lines'  => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 600 ) ),
				),
				$override
			)
		);
	}

	public function test_a_refund_becomes_a_credit_note_with_the_tax_that_explains_the_refunded_tax(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 4599, $result->ref->extra['total_minor'] );
		$this->assertSame( 'SAR', $result->ref->extra['currency'] );

		$posts = $this->requests( 'POST', '#/api2/credit_notes\.json$#' );
		$this->assertCount( 1, $posts );
		$body = $this->bodyOf( $posts[0] );
		$this->assertSame( 'WC-R-501', $body['Invoice']['po_number'] );
		$this->assertSame( '2026-11-03', $body['Invoice']['date'] );
		$this->assertSame( 'SAR', $body['Invoice']['currency_code'] );
		$this->assertStringContainsString( 'WC-10490', $body['Invoice']['notes'] );
		$this->assertStringContainsString( 'Damaged in transit', $body['Invoice']['notes'] );
		$this->assertSame( 11, $body['InvoiceItem'][0]['product_id'] );
		$this->assertSame( 1, $body['InvoiceItem'][0]['tax1'] );
		$this->assertSame( 39.99, $body['InvoiceItem'][0]['unit_price'] );
		$this->assertSame( $this->server->all( 'invoices' )[0]['client_id'], $body['Invoice']['client_id'], 'The credit note is for the client of the invoice.' );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
	}

	public function test_a_second_send_of_the_same_refund_changes_nothing(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$writes    = count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'DELETE', '#.#' ) );
		$second    = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'DELETE', '#.#' ) ) );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_find_existing_looks_only_at_credit_notes_of_the_last_seven_days(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$before = count( $this->kit->http->requests );
		$this->assertNotNull( $connector->findExisting( $this->connection(), $this->refundItem() ) );
		$lists = array_values( array_filter( array_slice( $this->kit->http->requests, $before ), static fn( array $request ): bool => str_contains( $request['url'], 'credit_notes.json' ) ) );
		$this->assertNotEmpty( $lists );
		$this->assertStringContainsString( 'created_from=2026-10-26', $lists[0]['url'] );
	}

	public function test_a_lost_answer_does_not_create_a_second_credit_note(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );

		$retry = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
	}

	public function test_the_credit_note_can_be_linked_to_the_invoice_when_the_option_is_on(): void {
		$this->syncOrder();
		$this->connector()->send( $this->connection( array( 'link_credit_notes' => 'yes' ) ), $this->refundItem(), $this->oneMug() );
		$body = $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] );
		$this->assertSame( (int) $this->server->all( 'invoices' )[0]['id'], $body['Invoice']['subscription_id'] );
	}

	public function test_without_the_option_no_link_is_sent(): void {
		$this->syncOrder();
		$this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$body = $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] );
		$this->assertArrayNotHasKey( 'subscription_id', $body['Invoice'] );
	}

	public function test_shipping_and_other_amounts_are_text_lines_without_tax_and_the_total_matches(): void {
		$this->syncOrder();
		$payload = $this->oneMug( array( 'amount' => 4599 + 2875 + 100, 'shipping_amount' => 2875, 'other_amount' => 100 ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] )['InvoiceItem'];
		$this->assertCount( 3, $items );
		$this->assertSame( 'Shipping', $items[1]['item'] );
		$this->assertArrayNotHasKey( 'tax1', $items[1] );
		$this->assertSame( 7574, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_without_lines_is_one_line_for_the_amount_and_left_out_products_are_not_refunded(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 3000, 'excluded_amount' => 500, 'lines' => array() ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] )['InvoiceItem'];
		$this->assertCount( 1, $items );
		$this->assertSame( 25.0, $items[0]['unit_price'] );
		$this->assertSame( 2500, $result->ref->extra['total_minor'] );
	}

	public function test_a_sku_is_looked_up_when_the_refund_carries_no_product_id(): void {
		$this->syncOrder();
		$payload = $this->oneMug();
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->refundItem(), $payload )->isSuccess() );
		$this->assertSame( 11, $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] )['InvoiceItem'][0]['product_id'] );
	}

	public function test_a_refund_for_an_order_daftra_does_not_have_is_refused_before_anything_is_written(): void {
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order', $result->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_refund_of_an_invoice_still_a_draft_waits_for_the_invoice_to_be_issued(): void {
		$this->syncOrder( array( 'invoice_state' => 'draft' ) );
		$result = $this->connector()->send( $this->connection( array( 'invoice_state' => 'draft' ) ), $this->refundItem(), $this->oneMug() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'draft', $result->error->context['reason'] );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
	}

	public function test_a_tax_that_no_rate_explains_is_map_002(): void {
		$this->syncOrder();
		$payload = $this->oneMug( array( 'amount' => 4399, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 400 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
	}

	public function test_a_refund_without_an_order_reference_is_refused_with_the_field_name(): void {
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug( array( 'order_ref' => '' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_a_total_that_differs_deletes_the_credit_note_and_says_why(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$result    = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug( array( 'amount' => 5000 ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '45.99', $result->error->context['reason'] );
		$this->assertStringContainsString( '50.00', $result->error->context['reason'] );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->refundItem() ) );

		$again = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$this->assertTrue( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
	}

	public function test_a_credit_note_that_cannot_be_deleted_says_so(): void {
		$this->syncOrder();
		$this->server->refuseDelete = true;
		$result                     = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug( array( 'amount' => 5000 ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'Delete it in Daftra', $result->error->context['reason'] );
	}

	public function test_the_warn_option_keeps_a_credit_note_with_another_total_and_says_so(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->refundItem(), $this->oneMug( array( 'amount' => 5000 ) ) );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertCount( 1, $this->server->all( 'credit_notes' ) );
	}

	public function test_two_refunds_of_one_order_make_two_credit_notes(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$one       = $connector->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$two       = $connector->send( $this->connection(), $this->item( EntityType::Refund, '502', 'refunded', '10490' ), $this->oneMug() );
		$this->assertTrue( $one->isSuccess() );
		$this->assertTrue( $two->isSuccess() );
		$this->assertFalse( $two->alreadyExisted );
		$this->assertNotSame( $one->ref->id, $two->ref->id );
		$this->assertCount( 2, $this->server->all( 'credit_notes' ) );
	}

	public function test_the_currency_of_the_invoice_is_used_for_the_credit_note(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug( array( 'currency' => '' ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'SAR', $this->bodyOf( $this->requests( 'POST', '#/api2/credit_notes\.json$#' )[0] )['Invoice']['currency_code'] );
	}

	public function test_a_failure_from_daftra_is_reported_and_leaves_no_credit_note(): void {
		$this->syncOrder();
		$this->server->failNextWrite( 'server_error' );
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug() );
		$this->assertSame( ErrorCode::Net002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'credit_notes' ) );
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->refundItem(), $this->oneMug() )->isSuccess() );
	}
}
