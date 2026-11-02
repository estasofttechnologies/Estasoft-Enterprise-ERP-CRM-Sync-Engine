<?php
/**
 * The refund flow of the QuickBooks connector: a credit memo for the order's customer and currency.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeQuickBooksServer;

final class QuickBooksRefundFlowTest extends QuickBooksTestCase {

	private function order( string $id, array $merge = array() ): void {
		$result = $this->connector->send( $this->connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function send( string $refundId, string $orderId, array $merge = array(), ?array $options = null, int $attempt = 0 ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $refundId, EntityType::Refund, $attempt ), $this->refundPayload( $orderId, $merge ) );
	}

	public function test_a_refund_is_a_credit_memo_for_the_customer_and_currency_of_the_invoice(): void {
		$this->order( '2001', array( 'currency' => 'USD' ) );
		$result = $this->send( '2101', '2001' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$memo    = $this->qbo->byNumber( 'CreditMemo', 'WC-2101' );
		$invoice = $this->qbo->byNumber( 'Invoice', 'WC-2001' );
		$this->assertSame( $invoice['CustomerRef']['value'], $memo['CustomerRef']['value'] );
		$this->assertSame( 'USD', $memo['CurrencyRef']['value'] );
		$this->assertSame( '2026-11-02', $memo['TxnDate'] );
		$this->assertSame( 5040, $result->ref->extra['total_minor'] );
		$this->assertSame( 'Refund of WC-2001: Damaged in transit', $memo['PrivateNote'], 'The marker is gone and the order is named, so the nightly check can link them.' );
		$this->assertSame( $memo['Id'], $result->ref->id );
		$this->assertSame( 'WC-2101', $result->ref->ref );
		$create = array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => '/v3/company/' . FakeQuickBooksServer::REALM . '/creditmemo' === $r->path ) )[0]->json();
		$this->assertSame( 42.0, $create['Line'][0]['SalesItemLineDetail']['UnitPrice'] );
		$this->assertStringStartsWith( 'Damaged in transit: ', $create['Line'][0]['Description'] );
		$this->assertStringContainsString( '[unchecked]', $create['PrivateNote'] );
	}

	public function test_a_repeated_refund_makes_no_second_credit_memo(): void {
		$this->order( '2002' );
		$first  = $this->send( '2102', '2002' );
		$second = $this->send( '2102', '2002' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->qbo->store['CreditMemo'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '2102', EntityType::Refund ) ) );
	}

	public function test_a_refund_for_an_order_quickbooks_does_not_have_fails_before_anything_is_made(): void {
		$result = $this->send( '2103', '2999' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'WC-2999', $result->error->message() );
		$this->assertCount( 0, $this->qbo->store['CreditMemo'] );
		$this->assertSame( array(), $this->qbo->writes() );
	}

	public function test_a_refund_total_that_differs_deletes_the_credit_memo_it_made(): void {
		$this->order( '2004' );
		$result = $this->send( '2104', '2004', array( 'amount' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->qbo->store['CreditMemo'] );
	}

	public function test_a_credit_memo_with_the_marker_is_not_finished_and_the_next_pass_checks_it(): void {
		$this->order( '2005' );
		$this->qbo->loseNextAnswer = true;
		$lost                      = $this->send( '2105', '2005' );
		$this->assertFalse( $lost->isSuccess() );
		$this->assertCount( 1, $this->qbo->store['CreditMemo'] );
		$this->assertNull( $this->connector->findExisting( $this->connection, $this->item( '2105', EntityType::Refund ) ) );

		$retry = $this->send( '2105', '2005' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->qbo->store['CreditMemo'] );
		$this->assertStringNotContainsString( '[unchecked]', array_values( $this->qbo->store['CreditMemo'] )[0]['PrivateNote'] );
	}

	public function test_shipping_is_refunded_on_the_shipping_item_and_a_refund_without_lines_uses_the_amount_and_the_generic_item(): void {
		$this->order( '2006' );
		$this->qbo->addItem( 'WC-SHIPPING', array( 'Name' => 'Shipping', 'Type' => 'Service' ) );
		$shipping = $this->send( '2106', '2006', array( 'shipping' => array( 'total' => 1000, 'tax' => '5' ), 'amount' => 6240 ), array( 'shipping_item' => 'WC-SHIPPING' ) );
		$this->assertTrue( $shipping->isSuccess(), (string) $shipping->error?->message() );
		$memo = $this->qbo->byNumber( 'CreditMemo', 'WC-2106' );
		$this->assertCount( 2, $memo['Line'] );
		$this->assertSame( 10.0, $memo['Line'][1]['Amount'] );

		$plain = $this->connector->send( $this->connect( array( 'generic_item' => 'WC-SERVICE' ), 3 ), $this->item( '2107', EntityType::Refund ), array( 'order_ref' => 'WC-2006', 'amount' => 2500, 'date' => '2026-11-02' ) );
		$this->assertTrue( $plain->isSuccess(), (string) $plain->error?->message() );
		$this->assertSame( 2500, $plain->ref->extra['total_minor'] );
		$this->assertSame( 'Refund', $this->qbo->byNumber( 'CreditMemo', 'WC-2107' )['Line'][0]['Description'] );
	}

	public function test_a_refund_with_no_lines_and_no_amount_is_refused(): void {
		$this->order( '2008' );
		$result = $this->connector->send( $this->connection, $this->item( '2108', EntityType::Refund ), array( 'order_ref' => 'WC-2008' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'no lines', $result->error->message() );
	}

	public function test_a_refund_without_the_order_it_belongs_to_is_refused_before_any_request(): void {
		$result = $this->connector->send( $this->connection, $this->item( '2109', EntityType::Refund ), array( 'amount' => 100 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'order', $result->error->message() );
		$this->assertSame( array(), $this->qbo->server->received );
	}

	public function test_a_try_after_a_deleted_credit_memo_gets_a_new_request_id(): void {
		$this->order( '2010' );
		$this->send( '2110', '2010', array( 'amount' => 9999 ) );

		$retry = $this->send( '2110', '2010', array(), null, 1 );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->qbo->store['CreditMemo'] );
	}
}
