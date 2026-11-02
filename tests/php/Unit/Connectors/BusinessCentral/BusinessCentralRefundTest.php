<?php
/**
 * Refunds as credit memos in Business Central.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class BusinessCentralRefundTest extends BusinessCentralTestCase {

	/**
	 * A refund of one mug and its tax.
	 *
	 * @param array<string,mixed> $override Values to replace.
	 * @return array<string,mixed>
	 */
	private function refund( array $override = array() ): array {
		return $override + array(
			'order_ref'   => 'WC-3001',
			'date'        => '2026-11-03',
			'reason'      => 'Damaged on arrival',
			'amount'      => 2099,
			'refund_line' => array(
				array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'amount' => 1999 ),
			),
		);
	}

	private function sendOrder(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '3001' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	public function test_a_refund_becomes_a_draft_credit_memo_for_the_same_customer_and_currency(): void {
		$this->sendOrder();
		$item   = $this->orderItem( '3101', EntityType::Refund );
		$result = $this->connector->send( $this->connection, $item, $this->refund() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$memos = $this->bc->all( 'salesCreditMemos' );
		$this->assertCount( 1, $memos );
		$this->assertSame( 'WC-3101', $memos[0]['externalDocumentNumber'] );
		$this->assertSame( 'AED', $memos[0]['currencyCode'] );
		$this->assertSame( $this->bc->all( 'salesOrders' )[0]['customerNumber'], $memos[0]['customerNumber'] );
		$this->assertSame( 'Draft', $memos[0]['status'] );
		$lines = $this->linesOfOnly( 'salesCreditMemos' );
		$this->assertSame( 'Refund of WC-3001', $lines[0]['description'] );
		$this->assertSame( 'Reason: Damaged on arrival', $lines[1]['description'] );
		$this->assertSame( 19.99, $lines[2]['unitPrice'] );
		$this->assertSame( 'WC-3101 synced, 3 lines', $lines[3]['description'] );
		$this->assertSame( 2099, $result->ref->extra['total_minor'], '19.99 plus the item\'s 5 percent tax.' );
	}

	public function test_a_refund_sent_twice_makes_one_credit_memo_and_is_found_before_sending(): void {
		$this->sendOrder();
		$item = $this->orderItem( '3102', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$first  = $this->connector->send( $this->connection, $item, $this->refund() );
		$second = $this->connector->send( $this->connection, $item, $this->refund() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->bc->all( 'salesCreditMemos' ) );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_refund_of_an_order_that_is_not_in_business_central_fails_before_anything_is_created(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '3103', EntityType::Refund ), $this->refund( array( 'order_ref' => 'WC-9999' ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'WC-9999', $result->error->message() );
		$this->assertCount( 0, $this->bc->all( 'salesCreditMemos' ) );
	}

	public function test_the_post_option_posts_the_memo_once_and_finds_it_afterwards(): void {
		$this->sendOrder();
		$connection = $this->connection( array( 'refund_state' => 'post' ) );
		$item       = $this->orderItem( '3104', EntityType::Refund );
		$this->bc->failAfterWrites = array( 'Microsoft.NAV.post' => 0 );
		$cut = $this->connector->send( $connection, $item, $this->refund() );
		$this->assertFalse( $cut->isSuccess() );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'A draft is not finished when posting is wanted.' );

		$result = $this->connector->send( $connection, $item, $this->refund() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'Open', $this->bc->all( 'salesCreditMemos' )[0]['status'] );
		$this->assertStringStartsWith( 'PCM-', $result->ref->ref );
		$again = $this->connector->send( $connection, $item, $this->refund() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, array_filter( $this->bc->actions, static fn( array $a ): bool => 'salesCreditMemos.post' === $a['action'] ), 'Never posted twice.' );
	}

	public function test_shipping_and_a_remaining_amount_need_their_targets(): void {
		$this->sendOrder();
		$payload   = $this->refund( array( 'shipping' => array( 'total' => 500 ), 'other' => array( 'total' => 100 ), 'amount' => 2699 ) );
		$refused   = $this->connector->send( $this->connection, $this->orderItem( '3105', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'shipping_item', $refused->error->message() );
		$this->assertCount( 0, $this->bc->all( 'salesCreditMemos' ) );

		$connection = $this->connection( array( 'shipping_item' => 'SHIP', 'refund_item' => 'SHIP', 'total_mismatch' => 'warn' ) );
		$result     = $this->connector->send( $connection, $this->orderItem( '3105', EntityType::Refund ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 6, $this->linesOfOnly( 'salesCreditMemos' ) );
	}

	public function test_a_line_amount_that_does_not_divide_by_the_quantity_keeps_the_amount(): void {
		$this->sendOrder();
		$payload                          = $this->refund();
		$payload['refund_line'][0]['quantity'] = 3;
		$payload['refund_line'][0]['amount']   = 1000;
		$this->connector->send( $this->connection, $this->orderItem( '3106', EntityType::Refund ), $payload + array() );
		$lines = $this->linesOfOnly( 'salesCreditMemos' );
		$this->assertSame( 1, $lines[2]['quantity'] );
		$this->assertSame( 10.0, $lines[2]['unitPrice'] );
		$this->assertStringContainsString( 'quantity 3', $lines[2]['description'] );
	}

	public function test_an_unknown_product_in_a_refund_is_map_001_and_nothing_is_created(): void {
		$this->sendOrder();
		$payload                              = $this->refund();
		$payload['refund_line'][0]['product'] = 'NO-SUCH';
		$result                               = $this->connector->send( $this->connection, $this->orderItem( '3107', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->bc->all( 'salesCreditMemos' ) );
	}

	public function test_a_refund_of_an_invoiced_order_finds_the_customer_on_the_posted_invoice(): void {
		$connection = $this->connection( array( 'documents' => 'order_ship_invoice' ) );
		$this->connector->send( $connection, $this->orderItem( '3001' ), $this->payload() );
		$result = $this->connector->send( $connection, $this->orderItem( '3108', EntityType::Refund ), $this->refund() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->bc->all( 'salesCreditMemos' ) );
	}

	public function test_a_refund_with_nothing_in_it_is_refused_on_its_face(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '3109', EntityType::Refund ), array( 'order_ref' => 'WC-3001', 'amount' => 0 ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}
}
