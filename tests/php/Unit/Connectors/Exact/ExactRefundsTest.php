<?php
/**
 * Refunds of the Exact Online connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A refund is a sales credit note (type 8021) in the credit journal, found again by its own reference.
 */
final class ExactRefundsTest extends ExactTestCase {

	public function test_a_refund_becomes_a_credit_note_linked_by_reference_and_description(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3001' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3002', '3001' );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 2, $this->server->invoices );
		$credit = array_values( $this->server->invoices )[1];
		$this->assertSame( 8021, $credit['Type'] );
		$this->assertSame( 'WC-3002', $credit['YourRef'] );
		$this->assertSame( '71', $credit['Journal'], 'The return journal.' );
		$this->assertSame( 'Refund WC-3002 of WC-3001', $credit['Description'] );
		$this->assertSame( 'Damaged', $credit['Remarks'] );
		$this->assertSame( 2000, $result->ref->extra['total_minor'], 'The reference carries a positive total.' );
		$original = array_values( $this->server->invoices )[0];
		$this->assertSame( $original['OrderedBy'], $credit['OrderedBy'], 'The credit note goes to the customer of the invoice.' );
	}

	public function test_the_same_refund_twice_makes_one_credit_note(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3003' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3004', '3003' );
		$first  = $this->connector->send( $connection, $refund, $refundPayload );
		$second = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 2, $this->server->invoices );
		$found = $this->connector->findExisting( $connection, $refund );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $first->ref->id, $found->id );
	}

	public function test_a_refund_whose_order_is_not_in_exact_fails_before_anything_is_created(): void {
		list( $refund, $payload ) = $this->refund( '3006', '3005' );
		$result = $this->connector->send( $this->connection(), $refund, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_an_invoice_is_not_taken_for_a_credit_note_with_the_same_reference_number(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3007' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund ) = $this->refund( '3007', '3007' );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ), 'WC-3007 is an invoice, not a credit note.' );
	}

	public function test_exact_ignoring_the_credit_type_is_caught_and_the_document_removed(): void {
		$this->server->keepCreditType = false;
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3008' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3009', '3008' );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'credit note', $result->error->context['reason'] );
		$this->assertCount( 1, $this->server->invoices );
	}

	public function test_a_refund_amount_that_exact_computes_differently_fails(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3010' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3011', '3010', array( 'amount' => 2500 ) );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
	}

	public function test_a_partial_line_refund_with_vat_matches_when_the_amount_includes_the_vat(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3012' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3013', '3012', array( 'amount' => 6050, 'refund_line' => array( array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'amount' => 5000, 'tax' => '2' ) ) ) );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 6050, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_without_lines_needs_the_refund_item_option(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3014' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3015', '3014', array( 'refund_line' => array() ) );
		$refused = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertSame( 'refund_item', $refused->error->context['field'] );
		$with = $this->connection( array( 'options' => array( 'refund_item' => 'SHIP-01' ) ), array(), 'access-1' );
		$this->assertTrue( $this->connector->send( $with, $refund, $refundPayload )->isSuccess() );
	}

	public function test_a_refund_with_a_missing_order_reference_is_refused(): void {
		list( $refund, $payload ) = $this->refund( '3016', '3015', array( 'order_ref' => 'order 3015' ) );
		$result = $this->connector->send( $this->connection(), $refund, $payload );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
	}
}
