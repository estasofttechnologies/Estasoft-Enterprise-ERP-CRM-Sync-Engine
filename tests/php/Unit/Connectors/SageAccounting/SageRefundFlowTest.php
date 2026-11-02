<?php
/**
 * The refund flow of the Sage connector: a sales credit note, optionally applied to the invoice.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class SageRefundFlowTest extends SageTestCase {

	private function order( string $id, array $merge = array() ): void {
		$result = $this->connector->send( $this->connection, $this->item( $id ), $this->payload( $id, $merge ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function send( string $refundId, string $orderId, array $merge = array(), ?array $options = null, int $attempt = 0 ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $refundId, EntityType::Refund, $attempt ), $this->refundPayload( $orderId, $merge ) );
	}

	public function test_a_refund_is_a_credit_note_for_the_contact_of_the_invoice_naming_the_order(): void {
		$this->order( '2001' );
		$result = $this->send( '2101', '2001' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$note    = $this->sage->byReference( 'credit', 'WC-2101' );
		$invoice = $this->sage->byReference( 'invoice', 'WC-2001' );
		$this->assertSame( $invoice['contact']['id'], $note['contact']['id'] );
		$this->assertSame( '2026-11-02', $note['date'] );
		$this->assertSame( 'Refund of WC-2001: Damaged in transit', $note['notes'], 'The order is named, so the nightly check can link them.' );
		$this->assertSame( 5040, $result->ref->extra['total_minor'] );
		$this->assertSame( $note['id'], $result->ref->id );
		$this->assertSame( 'SCN-1', $result->ref->ref );
		$body = $this->sage->calls( 'POST', '/sales_credit_notes' )[0]->json()['sales_credit_note'];
		$this->assertSame( '42.00', $body['credit_note_lines'][0]['unit_price'] );
		$this->assertSame( '8.40', $body['credit_note_lines'][0]['tax_amount'] );
		$this->assertStringStartsWith( 'Damaged in transit: ', $body['credit_note_lines'][0]['description'] );
		$this->assertSame( array(), $this->sage->store['allocation'], 'Not applied unless the option asks for it.' );
	}

	public function test_a_repeated_refund_makes_no_second_credit_note(): void {
		$this->order( '2002' );
		$first  = $this->send( '2102', '2002' );
		$second = $this->send( '2102', '2002' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->sage->store['credit'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '2102', EntityType::Refund ) ) );
	}

	public function test_the_credit_is_applied_to_the_invoice_once_when_the_option_asks_for_it(): void {
		$this->order( '2003' );
		$options = array( 'refund_allocation' => 'invoice' );

		$result = $this->send( '2103', '2003', array(), $options );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$allocations = array_values( $this->sage->store['allocation'] );
		$this->assertCount( 1, $allocations );
		$this->assertSame( 'CUSTOMER_ALLOCATION', $allocations[0]['transaction_type_id'] );
		$this->assertSame( '50.40', $allocations[0]['allocated_artefacts'][0]['amount'], 'The invoice, positive.' );
		$this->assertSame( '-50.40', $allocations[0]['allocated_artefacts'][1]['amount'], 'The credit note, negative.' );
		$this->assertSame( 'PART_PAID', $this->sage->byReference( 'invoice', 'WC-2003' )['status']['id'] );
		$this->assertSame( '50.40', $this->sage->byReference( 'invoice', 'WC-2003' )['outstanding_amount'] );
		$this->assertSame( 'PAID', $this->sage->byReference( 'credit', 'WC-2103' )['status']['id'] );

		$again = $this->send( '2103', '2003', array(), $options );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->sage->store['allocation'], 'A repeat does not apply the credit twice.' );
	}

	public function test_a_credit_that_was_made_but_not_applied_is_applied_on_the_next_pass_and_find_existing_says_it_is_not_finished(): void {
		$this->order( '2004' );
		$this->send( '2104', '2004' );
		$options = array( 'refund_allocation' => 'invoice' );

		$this->assertNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '2104', EntityType::Refund ) ) );
		$retry = $this->send( '2104', '2004', array(), $options );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->sage->store['credit'] );
		$this->assertCount( 1, $this->sage->store['allocation'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '2104', EntityType::Refund ) ) );
	}

	public function test_nothing_is_applied_to_an_invoice_that_is_already_paid(): void {
		$this->order( '2005', array( 'payment' => array( 'date' => '2026-11-01' ) ) );
		$paid = $this->connect( array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'bank-1200', 'refund_allocation' => 'invoice' ) );
		$this->connector->send( $paid, $this->item( '2005' ), $this->payload( '2005', array( 'payment' => array( 'date' => '2026-11-01' ) ) ) );
		$this->assertSame( 'PAID', $this->sage->byReference( 'invoice', 'WC-2005' )['status']['id'] );

		$result = $this->connector->send( $paid, $this->item( '2105', EntityType::Refund ), $this->refundPayload( '2005' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->sage->store['allocation'] );
	}

	public function test_a_refund_for_an_order_sage_does_not_have_fails_before_anything_is_made(): void {
		$result = $this->send( '2106', '2999' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'WC-2999', $result->error->message() );
		$this->assertSame( array(), $this->sage->writes() );
	}

	public function test_a_refund_total_that_differs_voids_the_credit_note_it_made_and_a_later_try_is_not_blocked_by_that(): void {
		$this->order( '2007' );
		$result = $this->send( '2107', '2007', array( 'amount' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$note = $this->sage->byReference( 'credit', 'WC-2107' );
		$this->assertSame( 'VOID', $note['status']['id'] );
		$this->assertSame( 'Created with the wrong total by the WooCommerce sync', $note['void_reason'] );

		$retry = $this->send( '2107', '2007', array(), null, 1 );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$this->assertCount( 2, $this->sage->store['credit'], 'The voided one stays in Sage; a new one was made.' );
	}

	public function test_a_credit_note_a_person_voided_stops_the_item(): void {
		$this->order( '2008' );
		$this->send( '2108', '2008' );
		$id                                              = $this->sage->byReference( 'credit', 'WC-2108' )['id'];
		$this->sage->store['credit'][ $id ]['status']      = array( 'id' => 'VOID' );
		$this->sage->store['credit'][ $id ]['void_reason'] = 'Customer changed their mind';

		$result = $this->send( '2108', '2008' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'voided', $result->error->message() );
		$this->assertCount( 1, $this->sage->store['credit'] );
	}

	public function test_when_sage_does_not_say_why_a_credit_note_was_voided_it_counts_as_a_persons_choice(): void {
		$this->order( '2009' );
		$this->sage->showVoidReason = false;
		$this->send( '2109', '2009', array( 'amount' => 9999 ) );

		$result = $this->send( '2109', '2009', array(), null, 1 );

		$this->assertFalse( $result->isSuccess(), 'Safer to stop than to risk a second credit note.' );
		$this->assertStringContainsString( 'voided', $result->error->message() );
	}

	public function test_shipping_is_refunded_in_the_shipping_fields_and_an_amount_alone_is_a_line_without_tax(): void {
		$this->order( '2010' );
		$shipping = $this->send( '2110', '2010', array( 'shipping' => array( 'total' => 1000, 'tax' => 'GB_STANDARD' ), 'amount' => 6240 ) );
		$this->assertTrue( $shipping->isSuccess(), (string) $shipping->error?->message() );
		$body = $this->sage->calls( 'POST', '/sales_credit_notes' )[0]->json()['sales_credit_note'];
		$this->assertSame( '10.00', $body['shipping_net_amount'] );
		$this->assertSame( '2.00', $body['shipping_tax_amount'] );
		$this->assertCount( 1, $body['credit_note_lines'] );

		$plain = $this->connector->send( $this->connection, $this->item( '2111', EntityType::Refund ), array( 'order_ref' => 'WC-2010', 'amount' => 2500, 'date' => '2026-11-02' ) );
		$this->assertTrue( $plain->isSuccess(), (string) $plain->error?->message() );
		$this->assertSame( 2500, $plain->ref->extra['total_minor'] );
		$this->assertSame( 'GB_NO_TAX', $this->sage->calls( 'POST', '/sales_credit_notes' )[1]->json()['sales_credit_note']['credit_note_lines'][0]['tax_rate_id'] );
	}

	public function test_a_refund_with_no_lines_and_no_amount_no_order_or_amounts_with_tax_is_refused_before_any_request(): void {
		$none = $this->connector->send( $this->connection, $this->item( '2112', EntityType::Refund ), array( 'order_ref' => 'WC-2010' ) );
		$this->assertFalse( $none->isSuccess() );
		$this->assertStringContainsString( 'no lines', $none->error->message() );

		$noOrder = $this->connector->send( $this->connection, $this->item( '2113', EntityType::Refund ), array( 'amount' => 100 ) );
		$this->assertFalse( $noOrder->isSuccess() );

		$inclusive = $this->send( '2114', '2010', array( 'prices_include_tax' => true ) );
		$this->assertFalse( $inclusive->isSuccess() );
		$this->assertSame( array(), $this->sage->server->received );
	}
}
