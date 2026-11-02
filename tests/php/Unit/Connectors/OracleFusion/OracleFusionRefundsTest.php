<?php
/**
 * Refunds as on-account credit memos against the fake pod.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class OracleFusionRefundsTest extends OracleFusionTestCase {

	/**
	 * A refund payload.
	 *
	 * @param array<string,mixed> $override Replacements.
	 * @return array<string,mixed>
	 */
	private function refundPayload( array $override = array() ): array {
		return $override + array(
			'order_ref'   => 'WC-2004',
			'date'        => '2026-11-03 09:00:00',
			'reason'      => 'Damaged',
			'amount'      => 3998,
			'refund_line' => array(
				array(
					'product'  => 'CS-MUG-01',
					'quantity' => 2,
					'amount'   => 3998,
				),
			),
		);
	}

	public function test_a_refund_is_an_on_account_credit_memo_with_its_own_reference_and_the_order_in_the_comments(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$refund = $this->item( '501', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $refund ) );
		$result = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$posts = $this->calls( 'POST', '/receivablesCreditMemos' );
		$this->assertCount( 1, $posts );
		$body = json_decode( (string) $posts[0]['body'], true );
		$this->assertSame( 'Credit Memo', $body['TransactionType'] );
		$this->assertSame( 'Shop Business Unit', $body['BusinessUnit'] );
		$this->assertSame( 'Web Orders', $body['TransactionSource'] );
		$this->assertSame( '1001', $body['BillToCustomerNumber'], 'The account of the order.' );
		$this->assertSame( 'AED', $body['InvoiceCurrencyCode'], 'The currency of the order.' );
		$this->assertSame( 'WC-501', $body['PurchaseOrder'] );
		$this->assertSame( '2026-11-03', $body['TransactionDate'] );
		$this->assertSame( "Refund WC-501 of order WC-2004: Damaged\nShop total 39.98 AED", $body['Comments'], 'The refund total is the last line, so findExisting() can check it.' );
		$this->assertSame( 'Incomplete', $body['InvoiceStatus'], 'A credit memo is made as a draft first.' );
		$line = $body['receivablesCreditMemoLines'][0];
		$this->assertSame( '[CS-MUG-01] Refund', $line['Description'] );
		$this->assertSame( 2, $line['Quantity'] );
		$this->assertEquals( 19.99, $line['UnitSellingPrice'], '39.98 for two is 19.99 each.' );
		$memo = array_values( $this->server->all( 'receivablesCreditMemos' ) )[0];
		$this->assertSame( (string) $memo['CustomerTransactionId'], $result->ref->id );
		$this->assertSame( $memo['TransactionNumber'], $result->ref->ref );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $refund ) );
	}

	public function test_sending_the_refund_again_finds_the_credit_memo_and_makes_no_second_one(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$refund = $this->item( '502', EntityType::Refund );
		$first  = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$again  = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'receivablesCreditMemos' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/receivablesCreditMemos' ) );
	}

	public function test_an_amount_only_refund_is_one_line_for_the_amount(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$payload = $this->refundPayload( array( 'amount' => 1500 ) );
		unset( $payload['refund_line'] );
		$result = $this->connector->send( $this->connection(), $this->item( '503', EntityType::Refund ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesCreditMemos' )[0]['body'], true );
		$this->assertCount( 1, $body['receivablesCreditMemoLines'] );
		$this->assertSame( 'Refund of order WC-2004', $body['receivablesCreditMemoLines'][0]['Description'] );
		$this->assertEquals( 15.00, $body['receivablesCreditMemoLines'][0]['UnitSellingPrice'] );
	}

	public function test_a_refund_with_no_lines_and_no_amount_is_val_002_and_sends_nothing(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$payload = $this->refundPayload( array( 'amount' => 0 ) );
		unset( $payload['refund_line'] );
		$result = $this->connector->send( $this->connection(), $this->item( '504', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesCreditMemos' ) );
	}

	public function test_a_refund_for_an_order_that_is_not_in_fusion_is_refused_before_anything_is_made(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '505', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-9999' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'order_ref', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesCreditMemos' ) );
	}

	public function test_a_refund_that_does_not_name_the_order_is_val_002(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '506', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => '' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->kit->http->requests );
	}

	public function test_the_credit_memo_type_is_an_option(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$result = $this->connector->send( $this->connection( array( 'credit_memo_type' => 'Web Credit' ) ), $this->item( '507', EntityType::Refund ), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'Web Credit', array_values( $this->server->all( 'receivablesCreditMemos' ) )[0]['TransactionType'] );
	}

	public function test_the_draft_is_completed_only_after_its_sign_and_currency_were_read_back(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$result = $this->connector->send( $this->connection(), $this->item( '520', EntityType::Refund ), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$order = array_map( static fn( array $request ): string => $request['method'], array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/receivablesCreditMemos' ) && 'GET' !== $request['method'] ) ) );
		$this->assertSame( array( 'POST', 'PATCH' ), $order );
		$patch = array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'PATCH' === $request['method'] ) )[0];
		$this->assertSame( array( 'InvoiceStatus' => 'Complete' ), json_decode( (string) $patch['body'], true ) );
		$memo = array_values( $this->server->all( 'receivablesCreditMemos' ) )[0];
		$this->assertSame( 'Complete', $memo['InvoiceStatus'] );
		$this->assertLessThan( 0, $memo['TotalAmount'], 'The credit memo credits the customer.' );
	}

	public function test_a_pod_that_would_book_the_memo_as_a_debit_gets_the_draft_deleted_and_a_clear_message_and_nothing_is_completed(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->creditLinesNegative = true;
		$result                            = $this->connector->send( $this->connection(), $this->item( '521', EntityType::Refund ), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( 'debit', $context );
		$this->assertStringContainsString( 'credit_line_sign', $context );
		$this->assertStringContainsString( 'was deleted', $context );
		$this->assertSame( array(), $this->server->all( 'receivablesCreditMemos' ) );
		$this->assertSame( array(), array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'PATCH' === $request['method'] ), 'Nothing was completed.' );
	}

	public function test_with_credit_line_sign_negative_the_same_pod_gets_a_credit(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->creditLinesNegative = true;
		$result                            = $this->connector->send( $this->connection( array( 'credit_line_sign' => 'negative' ) ), $this->item( '522', EntityType::Refund ), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesCreditMemos' )[0]['body'], true );
		$this->assertSame( -2, $body['receivablesCreditMemoLines'][0]['Quantity'] );
		$this->assertEquals( 19.99, $body['receivablesCreditMemoLines'][0]['UnitSellingPrice'] );
		$this->assertLessThan( 0, array_values( $this->server->all( 'receivablesCreditMemos' ) )[0]['TotalAmount'] );
	}

	public function test_a_draft_without_a_total_cannot_be_checked_so_it_is_deleted(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->showsTotal = false;
		$result                   = $this->connector->send( $this->connection(), $this->item( '523', EntityType::Refund ), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'shows no total', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->server->all( 'receivablesCreditMemos' ) );
	}

	public function test_a_completion_fusion_refuses_deletes_the_draft_and_says_why(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->refuseCompletion = true;
		$result                         = $this->connector->send( $this->connection(), $this->item( '524', EntityType::Refund ), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( 'cannot be completed', $context );
		$this->assertStringContainsString( 'was deleted', $context );
		$this->assertSame( array(), $this->server->all( 'receivablesCreditMemos' ) );
	}

	public function test_a_draft_that_cannot_be_deleted_stays_incomplete_and_findexisting_reports_it_for_a_person(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->refuseCompletion = true;
		$this->server->refuseMemoDelete = true;
		$refund                         = $this->item( '525', EntityType::Refund );
		$result                         = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertStringContainsString( 'stays in Oracle Fusion as Incomplete', json_encode( $result->error->context ) );
		$found = $this->connector->findExisting( $this->connection(), $refund );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $found );
		$this->assertStringContainsString( 'Incomplete', json_encode( $found->context ) );
	}

	public function test_tax_added_at_completion_is_accepted_when_the_refund_amount_includes_it_and_caught_when_it_does_not(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->taxRate = 5.0;
		$right                 = $this->connector->send( $this->connection(), $this->item( '526', EntityType::Refund ), $this->refundPayload( array( 'amount' => 4198 ) ) );
		$this->assertTrue( $right->isSuccess(), (string) $right->error?->message() );
		$wrongItem = $this->item( '527', EntityType::Refund );
		$wrong     = $this->connector->send( $this->connection(), $wrongItem, $this->refundPayload() );
		$this->assertFalse( $wrong->isSuccess() );
		$context = json_encode( $wrong->error->context );
		$this->assertStringContainsString( '41.98', $context, 'What Fusion shows after the tax.' );
		$this->assertStringContainsString( '39.98', $context, 'What the shop refunded.' );
		$this->assertCount( 2, $this->server->all( 'receivablesCreditMemos' ), 'A completed credit memo is not deleted by the connector.' );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $this->connector->findExisting( $this->connection(), $wrongItem ), 'The engine would link a reference; it must get the error.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $this->item( '526', EntityType::Refund ) ) );
	}

	public function test_a_missing_role_for_credit_memos_is_auth_004(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->blocked[] = 'receivablesCreditMemos';
		$result                  = $this->connector->send( $this->connection(), $this->item( '508', EntityType::Refund ), $this->refundPayload() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}
}
