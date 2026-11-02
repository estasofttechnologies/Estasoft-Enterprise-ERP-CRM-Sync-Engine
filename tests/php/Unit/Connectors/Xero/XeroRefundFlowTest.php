<?php
/**
 * The refund flow of the Xero connector: a credit note for the order's invoice.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class XeroRefundFlowTest extends XeroTestCase {

	private function order( string $id, array $options = array() ): void {
		$result = $this->connector->send( $this->connect( $options ), $this->item( $id ), $this->payload( $id ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function refund( string $orderId, array $merge = array() ): array {
		return array_replace_recursive(
			array(
				'order_ref'     => 'WC-' . $orderId,
				'date'          => '2026-11-03',
				'reason'        => 'Cracked in the post',
				'amount'        => 4410,
				'refund_line'   => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'amount' => 4200, 'tax' => 'OUTPUT' ) ),
			),
			$merge
		);
	}

	private function send( string $refundId, string $orderId, array $options = array(), array $merge = array() ) {
		return $this->connector->send( $this->connect( $options ), $this->item( $refundId, EntityType::Refund ), $this->refund( $orderId, $merge ) );
	}

	public function test_a_refund_is_a_draft_credit_note_for_the_contact_and_currency_of_the_invoice(): void {
		$this->order( '2001' );
		$result = $this->send( '2101', '2001' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$note    = array_values( $this->xero->creditNotes )[0];
		$invoice = $this->xero->invoiceByReference( 'WC-2001' );
		$this->assertSame( 'ACCRECCREDIT', $note['Type'] );
		$this->assertSame( 'DRAFT', $note['Status'] );
		$this->assertSame( 'WC-2101', $note['Reference'] );
		$this->assertSame( $invoice['Contact']['ContactID'], $note['Contact']['ContactID'] );
		$this->assertSame( 'AED', $note['CurrencyCode'] );
		$this->assertSame( '2026-11-03T00:00:00', $note['DateString'] );
		$this->assertSame( 4410, $result->ref->extra['total_minor'] );
		$body = $this->xero->calls( 'PUT', '/CreditNotes' )[0]->json()['CreditNotes'][0];
		$this->assertSame( 42.0, $body['LineItems'][0]['LineAmount'] );
		$this->assertStringStartsWith( 'Cracked in the post: ', $body['LineItems'][0]['Description'] );
	}

	public function test_a_repeated_refund_makes_no_second_credit_note(): void {
		$this->order( '2002' );
		$first  = $this->send( '2102', '2002' );
		$second = $this->send( '2102', '2002' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->xero->creditNotes );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '2102', EntityType::Refund ) ) );
	}

	public function test_a_retry_of_a_refund_after_a_time_out_sends_the_same_key_and_gets_the_first_credit_note(): void {
		$this->order( '2020' );
		$this->xero->loseNextAnswer = true;
		$this->assertFalse( $this->send( '2120', '2020' )->isSuccess() );
		$this->xero->hideDocuments = 1;

		$retry = $this->send( '2120', '2020' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->xero->creditNotes );
		$keys = array_map( static fn( $r ): string => (string) $r->header( 'Idempotency-Key' ), $this->xero->calls( 'PUT', '/CreditNotes' ) );
		$this->assertCount( 2, $keys );
		$this->assertSame( $keys[0], $keys[1] );
	}

	public function test_a_refund_for_an_order_xero_does_not_have_fails_before_anything_is_made(): void {
		$result = $this->send( '2103', '2999' );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'WC-2999', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->xero->calls( 'PUT', '/CreditNotes' ) );
	}

	public function test_a_refund_total_that_differs_deletes_the_draft(): void {
		$this->order( '2004' );
		$result = $this->send( '2104', '2004', array(), array( 'amount' => 9999 ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'DELETED', array_values( $this->xero->creditNotes )[0]['Status'] );
	}

	public function test_authorised_approves_and_authorised_allocate_applies_the_credit_once(): void {
		$this->order( '2005' );
		$result = $this->send( '2105', '2005', array( 'refund_state' => 'authorised_allocate' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$note    = array_values( $this->xero->creditNotes )[0];
		$invoice = $this->xero->invoiceByReference( 'WC-2005' );
		$this->assertContains( $note['Status'], array( 'AUTHORISED', 'PAID' ) );
		$this->assertCount( 1, $note['Allocations'] );
		$this->assertSame( 44.1, $note['Allocations'][0]['Amount'] );
		$this->assertSame( 44.1, $invoice['AmountDue'], '88.20 less the credit of 44.10.' );

		$again = $this->send( '2105', '2005', array( 'refund_state' => 'authorised_allocate' ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->xero->calls( 'PUT', '/CreditNotes/[^/]+/Allocations' ), 'Applied once.' );
	}

	public function test_a_credit_note_that_was_made_but_not_applied_is_finished_on_the_next_pass(): void {
		$this->order( '2006' );
		$this->send( '2106', '2006' );
		$this->assertSame( 'DRAFT', array_values( $this->xero->creditNotes )[0]['Status'] );

		$options = array( 'refund_state' => 'authorised_allocate' );
		$this->assertNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '2106', EntityType::Refund ) ) );
		$result = $this->send( '2106', '2006', $options );

		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 1, $this->xero->creditNotes );
		$this->assertCount( 1, array_values( $this->xero->creditNotes )[0]['Allocations'] );
	}

	public function test_nothing_is_applied_to_an_invoice_that_is_already_paid(): void {
		$this->order( '2007', array( 'documents' => 'invoice_paid', 'payment_account' => '090' ) );
		$invoice = $this->xero->invoiceByReference( 'WC-2007' );
		$this->xero->invoices[ $invoice['InvoiceID'] ]['AmountDue'] = 0.0;
		$this->xero->invoices[ $invoice['InvoiceID'] ]['Status']    = 'PAID';

		$result = $this->send( '2107', '2007', array( 'refund_state' => 'authorised_allocate' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->xero->calls( 'PUT', '/CreditNotes/[^/]+/Allocations' ) );
	}

	public function test_shipping_is_refunded_as_its_own_line_and_a_refund_without_lines_uses_the_amount(): void {
		$this->order( '2008', array( 'sales_account' => '200', 'shipping_account' => '260' ) );
		$options = array( 'sales_account' => '200', 'shipping_account' => '260' );
		$this->send( '2108', '2008', $options, array( 'refund_line' => array( array( 'tax' => 'NONE' ) ), 'shipping' => array( 'total' => 500, 'tax' => 'NONE' ), 'amount' => 4910 ) );
		$lines = $this->xero->calls( 'PUT', '/CreditNotes' )[0]->json()['CreditNotes'][0]['LineItems'];
		$this->assertCount( 2, $lines );
		$this->assertSame( 5.0, $lines[1]['LineAmount'] );

		$only = $this->connector->send( $this->connect( $options ), $this->item( '2109', EntityType::Refund ), array( 'order_ref' => 'WC-2008', 'amount' => 1500 ) );
		$this->assertTrue( $only->isSuccess(), (string) $only->error?->message() );
		$this->assertSame( 1500, $only->ref->extra['total_minor'] );
	}

	public function test_a_refund_with_no_lines_and_no_amount_is_refused(): void {
		$result = $this->connector->send( $this->connection, $this->item( '2110', EntityType::Refund ), array( 'order_ref' => 'WC-2008' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}
}
