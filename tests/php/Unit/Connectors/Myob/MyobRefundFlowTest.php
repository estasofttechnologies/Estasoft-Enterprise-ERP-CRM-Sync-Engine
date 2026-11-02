<?php
/**
 * The refund flow of the MYOB connector: a credit invoice with negative quantities.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class MyobRefundFlowTest extends MyobTestCase {

	private function order( string $id, array $merge = array() ): void {
		$result = $this->connector->send( $this->connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function send( string $refundId, string $orderId, array $merge = array(), ?array $options = null ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $refundId, EntityType::Refund ), $this->refundPayload( $orderId, $merge ) );
	}

	public function test_a_refund_is_a_credit_invoice_for_the_customer_of_the_order(): void {
		$this->order( '2001' );
		$result = $this->send( '2101', '2001' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$credit  = $this->myob->invoiceByReference( 'WC-2101' );
		$invoice = $this->myob->invoiceByReference( 'WC-2001' );
		$this->assertSame( 'Credit', $credit['Status'] );
		$this->assertSame( $invoice['Customer']['UID'], $credit['Customer']['UID'] );
		$this->assertSame( '2026-11-02T00:00:00', $credit['Date'] );
		$this->assertSame( 'Refund of WC-2001', $credit['JournalMemo'], 'The order is named, so the nightly check can link them.' );
		$this->assertSame( 'Damaged in transit', $credit['Comment'] );
		$this->assertSame( 4620, $result->ref->extra['total_minor'] );
		$this->assertSame( $credit['UID'], $result->ref->id );
		$this->assertSame( 'Credit', $result->ref->extra['status'] );
		$line = $this->myob->calls( 'POST', '/Sale/Invoice/Item' )[1]->json()['Lines'][0];
		$this->assertSame( -1.0, $line['ShipQuantity'] );
		$this->assertSame( 42.0, $line['UnitPrice'], 'A negative quantity with a positive price.' );
		$this->assertSame( -42.0, $line['Total'] );
		$this->assertStringStartsWith( 'Damaged in transit: ', $line['Description'] );
	}

	public function test_a_repeated_refund_makes_no_second_credit(): void {
		$this->order( '2002' );
		$first  = $this->send( '2102', '2002' );
		$second = $this->send( '2102', '2002' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 2, $this->myob->store['Invoice'], 'The order and one credit.' );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '2102', EntityType::Refund ) ) );
	}

	public function test_a_refund_for_an_order_myob_does_not_have_fails_before_anything_is_made(): void {
		$result = $this->send( '2103', '2999' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'WC-2999', $result->error->message() );
		$this->assertSame( array(), $this->myob->writes() );
	}

	public function test_a_refund_total_that_differs_deletes_the_credit_it_made(): void {
		$this->order( '2004' );
		$result = $this->send( '2104', '2004', array( 'amount' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 1, $this->myob->store['Invoice'], 'Only the order is left.' );
	}

	public function test_a_document_that_myob_does_not_report_as_a_credit_is_removed_and_the_person_is_told(): void {
		$this->order( '2005' );
		$this->myob->creditStatus = false;

		$result = $this->send( '2105', '2005' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Open', $result->error->message() );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
	}

	public function test_shipping_is_refunded_as_negative_freight_and_an_amount_alone_uses_the_generic_item(): void {
		$this->order( '2006' );
		$shipping = $this->send( '2106', '2006', array( 'shipping' => array( 'total' => 1000, 'tax' => 'GST' ), 'amount' => 5720 ) );
		$this->assertTrue( $shipping->isSuccess(), (string) $shipping->error?->message() );
		$credit = $this->myob->invoiceByReference( 'WC-2106' );
		$this->assertSame( -10.0, $credit['Freight'] );
		$this->assertCount( 1, $credit['Lines'] );

		$plain = $this->connector->send( $this->connect( array( 'generic_item' => 'WC-GENERAL' ), 3 ), $this->item( '2107', EntityType::Refund ), array( 'order_ref' => 'WC-2006', 'amount' => 2500, 'date' => '2026-11-02' ) );
		$this->assertTrue( $plain->isSuccess(), (string) $plain->error?->message() );
		$this->assertSame( 2500, $plain->ref->extra['total_minor'] );
		$this->assertSame( 'Refund', $this->myob->invoiceByReference( 'WC-2107' )['Lines'][0]['Description'] );
	}

	public function test_an_amount_alone_without_a_generic_item_names_the_option(): void {
		$this->order( '2008' );
		$result = $this->connector->send( $this->connection, $this->item( '2108', EntityType::Refund ), array( 'order_ref' => 'WC-2008', 'amount' => 2500, 'date' => '2026-11-02' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'generic_item', $result->error->message() );
	}

	public function test_a_refund_with_no_lines_and_no_amount_or_no_order_is_refused_before_any_request(): void {
		$none = $this->connector->send( $this->connection, $this->item( '2109', EntityType::Refund ), array( 'order_ref' => 'WC-2008' ) );
		$this->assertFalse( $none->isSuccess() );
		$this->assertStringContainsString( 'no lines', $none->error->message() );

		$noOrder = $this->connector->send( $this->connection, $this->item( '2110', EntityType::Refund ), array( 'amount' => 100 ) );
		$this->assertFalse( $noOrder->isSuccess() );
		$this->assertSame( array(), $this->myob->server->received );
	}

	public function test_a_refund_of_an_unknown_product_is_a_missing_product(): void {
		$this->order( '2011' );

		$result = $this->send( '2111', '2011', array( 'refund_line' => array( array( 'product' => 'NO-SUCH' ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
	}
}
