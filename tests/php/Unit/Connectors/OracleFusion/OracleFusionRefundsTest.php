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
		$this->assertSame( 'Refund WC-501 of order WC-2004: Damaged', $body['Comments'] );
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

	public function test_a_missing_role_for_credit_memos_is_auth_004(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->blocked[] = 'receivablesCreditMemos';
		$result                  = $this->connector->send( $this->connection(), $this->item( '508', EntityType::Refund ), $this->refundPayload() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}
}
