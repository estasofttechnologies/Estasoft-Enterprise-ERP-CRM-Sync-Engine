<?php
/**
 * Sending refunds to Acumatica as credit memos.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Acumatica;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class AcumaticaRefundTest extends AcumaticaTestCase {

	/**
	 * Send the order the refunds refer to.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Order override.
	 */
	private function placeOrder( array $config = array(), array $payload = array() ): void {
		$result = $this->sendOrder( $config, $payload );
		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	/**
	 * Send a refund.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Refund override.
	 * @param string              $id      Refund id.
	 */
	private function sendRefund( array $config = array(), array $payload = array(), string $id = '501' ) {
		return $this->connector()->send( $this->connection( $config ), $this->item( EntityType::Refund, $id ), $this->refund( $payload ) );
	}

	public function test_a_refund_becomes_a_credit_memo_on_hold_with_the_refund_and_order_in_its_description(): void {
		$this->placeOrder();
		$result = $this->sendRefund();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$memos = $this->server->all( 'SalesInvoice' );
		$this->assertCount( 1, $memos );
		$memo = $memos[0];
		$this->assertSame( 'Credit Memo', $memo['Type'] );
		$this->assertSame( 'WC-501 refund of WC-100: Damaged', $memo['Description'] );
		$this->assertSame( 'AED', $memo['Currency'] );
		$this->assertSame( '2026-11-03T00:00:00', $memo['Date'] );
		$this->assertSame( $this->server->all( 'SalesOrder' )[0]['CustomerID'], $memo['CustomerID'] );
		$this->assertSame( 'Hold', $memo['Status'], 'A person reviews the credit memo unless the setting releases it.' );
		$this->assertSame( 'CS-BOWL-02', $memo['Details'][0]['InventoryID'] );
		$this->assertSame( 1.0, $memo['Details'][0]['Qty'], 'The quantity is positive; the document type makes it a credit.' );
		$this->assertSame( 219.0, $memo['Details'][0]['UnitPrice'] );
		$this->assertSame( 21900, $result->ref->extra['total_minor'] );
		$this->assertSame( 'Credit Memo/CM000001', $result->ref->id );
	}

	public function test_the_same_refund_is_never_made_twice(): void {
		$this->placeOrder();
		$first  = $this->sendRefund();
		$second = $this->sendRefund();

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'SalesInvoice' ) );
	}

	public function test_a_refund_whose_number_starts_the_same_is_another_refund(): void {
		$this->placeOrder();
		$this->sendRefund( array(), array(), '50' );
		$other = $this->sendRefund( array(), array(), '501' );

		$this->assertFalse( $other->alreadyExisted );
		$this->assertCount( 2, $this->server->all( 'SalesInvoice' ) );
	}

	public function test_findExisting_answers_for_a_refund_that_was_made(): void {
		$this->placeOrder();
		$connector = $this->connector();
		$item      = $this->item( EntityType::Refund, '501' );
		$this->assertNull( $connector->findExisting( $this->connection(), $item ) );

		$this->sendRefund();
		$this->assertSame( 'Credit Memo/CM000001', $connector->findExisting( $this->connection(), $item )->id );
	}

	public function test_the_setting_release_takes_the_hold_off_and_releases(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array( 'refund_state' => 'release' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'Open', $this->server->all( 'SalesInvoice' )[0]['Status'] );
		$this->assertSame( 'Open', $result->ref->extra['status'] );
		$this->assertSame( 1, $this->server->count( 'POST', '#/ReleaseSalesInvoice$#' ) );
		$this->assertSame( 1, $this->server->count( 'GET', '#/ReleaseSalesInvoice/status/#' ), 'The Location is polled until the action is done.' );
	}

	public function test_a_release_that_is_still_running_is_noted_and_finished_on_the_next_pass(): void {
		$this->placeOrder();
		$this->server->releaseDelay = 10;
		$result                     = $this->sendRefund( array( 'refund_state' => 'release' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
		$this->assertSame( 3, $this->server->count( 'GET', '#/ReleaseSalesInvoice/status/#' ), 'Three polls, then the connector goes on.' );
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection( array( 'refund_state' => 'release' ) ), $this->item( EntityType::Refund, '501' ) ), 'Not released yet: not finished.' );

		$this->server->releaseDelay = 0;
		$again                      = $this->sendRefund( array( 'refund_state' => 'release' ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( 'Open', $this->server->all( 'SalesInvoice' )[0]['Status'] );
		$this->assertCount( 1, $this->server->all( 'SalesInvoice' ) );
	}

	public function test_the_line_carries_the_tax_category_of_the_order_line_and_the_total_matches_the_shops_refund(): void {
		$this->placeOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'VAT5' ) ) ) );
		$result = $this->sendRefund( array(), array( 'amount' => 22995 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'VAT5', $this->server->all( 'SalesInvoice' )[0]['Details'][0]['TaxCategory'] );
		$this->assertSame( 22995, $result->ref->extra['total_minor'] );
	}

	public function test_a_total_that_differs_stops_the_credit_memo_on_hold(): void {
		$this->placeOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'VAT5' ) ) ) );
		$result = $this->sendRefund( array( 'refund_state' => 'release' ), array( 'amount' => 21900 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertSame( 'Hold', $this->server->all( 'SalesInvoice' )[0]['Status'], 'It was not released.' );
	}

	public function test_the_original_invoice_is_named_on_the_lines_when_the_order_has_one(): void {
		$this->placeOrder();
		$this->server->put( 'SalesInvoice', array( 'Type' => 'Invoice', 'ReferenceNbr' => 'INV0001', 'Status' => 'Open', 'Amount' => 438.0 ) );
		$this->server->addShipment( 'SO/000001', 'SH0001', 'TRK-1', 'Confirmed', 'INV0001' );
		$result = $this->sendRefund();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$line = $this->server->all( 'SalesInvoice' )[1]['Details'][0];
		$this->assertSame( 'INV0001', $line['OrigInvNbr'] );
		$this->assertSame( 'Invoice', $line['OrigInvType'] );
	}

	public function test_an_order_that_is_not_in_acumatica_cannot_be_refunded_there(): void {
		$result = $this->sendRefund();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'SalesInvoice' ) );
	}

	public function test_an_unknown_product_is_map_001(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'amount' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_a_refund_without_lines_needs_the_refund_item(): void {
		$this->placeOrder();
		$refused = $this->sendRefund( array(), array( 'refund_line' => array(), 'amount' => 5000 ) );
		$this->assertSame( 'refund_line', $refused->error->context['field'] );

		$sent = $this->sendRefund( array( 'refund_item' => 'REFUND' ), array( 'refund_line' => array(), 'amount' => 5000 ) );
		$this->assertTrue( $sent->isSuccess(), $this->why( $sent ) );
		$line = $this->server->all( 'SalesInvoice' )[0]['Details'][0];
		$this->assertSame( 'REFUND', $line['InventoryID'] );
		$this->assertSame( 50.0, $line['UnitPrice'] );
		$this->assertSame( 5000, $sent->ref->extra['total_minor'] );
	}

	public function test_refunded_shipping_needs_the_shipping_item_and_becomes_a_line(): void {
		$this->placeOrder();
		$payload = array( 'shipping_amount' => 2500, 'amount' => 24400 );
		$refused = $this->sendRefund( array(), $payload );
		$this->assertSame( 'shipping_item', $refused->error->context['field'] );

		$sent = $this->sendRefund( array( 'shipping_item' => 'SHIPPING' ), $payload );
		$this->assertTrue( $sent->isSuccess(), $this->why( $sent ) );
		$details = $this->server->all( 'SalesInvoice' )[0]['Details'];
		$this->assertCount( 2, $details );
		$this->assertSame( 'SHIPPING', $details[1]['InventoryID'] );
		$this->assertSame( 25.0, $details[1]['UnitPrice'] );
	}

	public function test_a_refund_of_shipping_alone_is_one_shipping_line_not_two(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array( 'shipping_item' => 'SHIPPING', 'refund_item' => 'REFUND' ), array( 'refund_line' => array(), 'shipping_amount' => 2500, 'amount' => 2500 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$details = $this->server->all( 'SalesInvoice' )[0]['Details'];
		$this->assertCount( 1, $details, 'The amount is not added a second time as a line without a product.' );
		$this->assertSame( 'SHIPPING', $details[0]['InventoryID'] );
		$this->assertSame( 2500, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_that_names_no_order_is_refused(): void {
		$result = $this->sendRefund( array(), array( 'order_ref' => 'bogus' ) );

		$this->assertSame( 'order_ref', $result->error->context['field'] );
	}
}
