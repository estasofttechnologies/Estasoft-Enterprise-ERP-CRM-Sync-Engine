<?php
/**
 * Refunds as ERPNext credit notes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ErpnextRefundTest extends ErpnextTestCase {

	/**
	 * Send an order with an invoice so that a refund has something to return against.
	 *
	 * @param array<string,mixed> $options Options.
	 */
	private function invoicedOrder( array $options = array() ): void {
		$result = $this->sendOrder( array( 'documents' => 'order_invoice' ) + $options, array( 'total' => 43800 ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function sendRefund( array $options = array(), array $payload = array(), string $id = '200' ) {
		return $this->connector()->send( $this->connection( $options ), $this->item( EntityType::Refund, $id ), $this->refund( $payload ) );
	}

	public function test_a_refund_becomes_a_return_invoice_against_the_orders_invoice(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array( 'documents' => 'order_invoice' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$notes = array_values( array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) );
		$this->assertCount( 1, $notes );
		$note = $notes[0];
		$this->assertSame( 'WC-200', $note['po_no'] );
		$this->assertSame( $this->server->all( 'Sales Invoice' )[0]['name'], $note['return_against'] );
		$this->assertSame( -1.0, $note['items'][0]['qty'] );
		$this->assertSame( 219.0, $note['items'][0]['rate'], 'Quantities are negative, prices stay positive.' );
		$this->assertSame( '2026-11-03', $note['posting_date'] );
		$this->assertSame( 1, $note['set_posting_time'] );
		$this->assertSame( 0, $note['docstatus'], 'A draft by default: the person posts it.' );
		$this->assertStringContainsString( 'WC-100', $note['remarks'] );
		$this->assertStringContainsString( 'Damaged', $note['remarks'] );
		$this->assertSame( 21900, $result->ref->extra['total_minor'] );
	}

	public function test_the_same_refund_is_never_made_twice(): void {
		$this->invoicedOrder();
		$first  = $this->sendRefund();
		$second = $this->sendRefund();

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) );
		$this->assertNotNull( $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Refund, '200' ) ) );
	}

	public function test_refund_state_post_submits_the_note_and_a_draft_is_submitted_later(): void {
		$this->invoicedOrder();
		$this->server->failNext( 'PUT', '#/api/resource/Sales Invoice/ACC-SINV-2026-\d+$#', FakeHttpClient::timeout() );
		$first = $this->sendRefund( array( 'refund_state' => 'post' ) );
		$this->assertFalse( $first->isSuccess() );
		$item = $this->item( EntityType::Refund, '200' );
		$this->assertNull( $this->connector()->findExisting( $this->connection( array( 'refund_state' => 'post' ) ), $item ), 'A draft is not what the options ask for.' );

		$second = $this->sendRefund( array( 'refund_state' => 'post' ) );
		$this->assertTrue( $second->isSuccess() );
		$notes = array_values( array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 1, $notes[0]['docstatus'] );
	}

	public function test_an_order_without_an_invoice_is_refunded_without_return_against(): void {
		$this->assertTrue( $this->sendOrder( array(), array( 'total' => 43800 ) )->isSuccess() );
		$result = $this->sendRefund();
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertArrayNotHasKey( 'return_against', $this->server->all( 'Sales Invoice' )[0] );
	}

	public function test_a_refund_of_an_unknown_order_is_refused_before_anything_is_made(): void {
		$result = $this->sendRefund( array(), array( 'order_ref' => 'WC-999' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertSame( array(), $this->server->all( 'Sales Invoice' ) );
	}

	public function test_a_refund_that_does_not_name_its_order_is_refused(): void {
		$result = $this->sendRefund( array(), array( 'order_ref' => '' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_an_unknown_sku_on_a_refund_line_is_map_001(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array( array( 'product' => 'NOPE', 'quantity' => 1, 'amount' => 100 ) ) ) );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_returning_more_than_was_invoiced_is_reported_from_the_servers_message(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 5, 'amount' => 109500 ) ), 'amount' => 109500 ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'returned more than invoiced', (string) $result->error->context['reason'] );
	}

	public function test_a_refund_with_tax_uses_the_orders_template_and_agrees_with_the_refund_amount(): void {
		$line = array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => array( 'UAE VAT 5% - CS' ) ) );
		$this->assertTrue( $this->sendOrder( array( 'documents' => 'order_invoice' ), array( 'order_line' => $line, 'total' => 45990 ) )->isSuccess() );

		$result = $this->sendRefund( array(), array( 'amount' => 22995, 'refund_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900 ) ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$note = array_values( array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) )[0];
		$this->assertSame( 'UAE VAT 5% - CS', $note['taxes_and_charges'] );
		$this->assertSame( 22995, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_total_that_differs_stops_with_a_draft_left(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array(), array( 'amount' => 30000 ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertCount( 1, array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) );
	}

	public function test_a_refund_without_lines_needs_the_refund_item(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array(), 'amount' => 1500 ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'refund_line', $result->error->context['field'] );

		$this->server->put( 'Item', array( 'name' => 'REFUND', 'item_code' => 'REFUND', 'item_name' => 'Refund', 'is_stock_item' => 0, 'disabled' => 0 ) );
		$result = $this->sendRefund( array( 'refund_item' => 'REFUND' ), array( 'refund_line' => array(), 'amount' => 1500 ), '201' );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
	}

	public function test_refunded_shipping_needs_the_shipping_item(): void {
		$this->invoicedOrder();
		$result = $this->sendRefund( array(), array( 'shipping_amount' => 2500, 'amount' => 24400 ) );
		$this->assertSame( 'shipping_item', $result->error->context['field'] );
	}

	public function test_a_refund_of_shipping_alone_is_one_shipping_row_not_two(): void {
		$result = $this->sendOrder( array( 'documents' => 'order_invoice', 'shipping_item' => 'SHIPPING', 'refund_item' => 'FEE' ), array( 'shipping' => array( 'total' => 2500 ), 'total' => 46300 ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );

		$refund = $this->sendRefund( array( 'shipping_item' => 'SHIPPING', 'refund_item' => 'FEE', 'documents' => 'order_invoice' ), array( 'refund_line' => array(), 'shipping_amount' => 2500, 'amount' => 2500 ), '202' );
		$this->assertTrue( $refund->isSuccess(), (string) $refund->error?->message() . (string) ( $refund->error->context['reason'] ?? '' ) );
		$notes = array_values( array_filter( $this->server->all( 'Sales Invoice' ), static fn( array $doc ): bool => 1 === (int) $doc['is_return'] ) );
		$this->assertCount( 1, $notes[0]['items'], 'The amount is not added a second time as a row without a product.' );
		$this->assertSame( 'SHIPPING', $notes[0]['items'][0]['item_code'] );
		$this->assertSame( 2500, $refund->ref->extra['total_minor'] );
	}
}
