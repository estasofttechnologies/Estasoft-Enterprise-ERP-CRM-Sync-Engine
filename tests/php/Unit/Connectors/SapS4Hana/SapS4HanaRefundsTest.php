<?php
/**
 * Refunds as credit memo requests against the fake tenant.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class SapS4HanaRefundsTest extends SapS4HanaTestCase {

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
			'amount'      => 4200,
			'refund_line' => array(
				array(
					'product'  => 'CS-MUG-01',
					'quantity' => 2,
					'amount'   => 4200,
				),
			),
		);
	}

	/**
	 * The requests of one method that went to the credit memo service.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function posts(): array {
		return array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'POST' === $request['method'] && str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), '/A_CreditMemoRequest' ) ) );
	}

	public function test_a_refund_is_a_credit_memo_request_that_refers_to_the_order_and_carries_the_unit_price(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$order  = array_values( $this->server->all( 'A_SalesOrder' ) )[0];
		$refund = $this->item( '501', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $refund ) );
		$result = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->posts()[0]['body'], true );
		$this->assertSame( 'CR', $body['CreditMemoRequestType'] );
		$this->assertSame( 'WC-501', $body['PurchaseOrderByCustomer'] );
		$this->assertSame( $order['SalesOrder'], $body['ReferenceSDDocument'] );
		$this->assertSame( $order['SoldToParty'], $body['SoldToParty'], 'The credit goes to the customer of the order.' );
		$this->assertSame( 'AED', $body['TransactionCurrency'], 'The currency of the order.' );
		$this->assertSame( 'CS-MUG-01', $body['to_Item'][0]['Material'] );
		$this->assertSame( '2', $body['to_Item'][0]['RequestedQuantity'] );
		$this->assertSame( '21.00', $body['to_Item'][0]['to_PricingElement'][0]['ConditionRateValue'], '42.00 for two is 21.00 each.' );
		$this->assertSame( array_values( $this->server->all( 'A_CreditMemoRequest' ) )[0]['CreditMemoRequest'], $result->ref->id );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $refund ) );
	}

	public function test_sending_the_refund_again_finds_the_request_and_makes_no_second_one(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$refund = $this->item( '502', EntityType::Refund );
		$first  = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$again  = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'A_CreditMemoRequest' ) );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_a_refund_for_an_order_that_is_not_in_s4hana_is_refused_before_anything_is_made(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '503', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-9999' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'order_ref', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->posts() );
	}

	public function test_a_refund_of_an_amount_only_is_refused_because_s4hana_needs_a_product_on_every_line(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$payload = $this->refundPayload();
		unset( $payload['refund_line'] );
		$result = $this->connector->send( $this->connection(), $this->item( '504', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'refund_line', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->posts() );
	}

	public function test_a_refund_that_does_not_name_the_order_is_val_002(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '505', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => '' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->posts() );
	}

	public function test_a_refund_of_two_different_products_sends_one_item_for_each(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload( array( 'order_line' => array(
			array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'price_unit' => 4200 ),
			array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21000 ),
		) ) ) );
		$payload                = $this->refundPayload( array( 'amount' => 25200 ) );
		$payload['refund_line'] = array(
			array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 4200 ),
			array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21000 ),
		);
		$result = $this->connector->send( $this->connection(), $this->item( '506', EntityType::Refund ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->posts()[0]['body'], true );
		$this->assertSame( array( 'CS-MUG-01', 'CS-BOWL-02' ), array_column( $body['to_Item'], 'Material' ) );
		$this->assertSame( '210.00', $body['to_Item'][1]['to_PricingElement'][0]['ConditionRateValue'] );
	}

	public function test_a_request_whose_net_amount_differs_from_the_refund_is_deleted_and_never_reported_as_synced(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$refund = $this->item( '510', EntityType::Refund );
		$result = $this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99' ) ), $refund, $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( '80.00', $context, 'What S/4HANA priced: two mugs at the list price of 40.00.' );
		$this->assertStringContainsString( '42.00', $context, 'What the shop refunded.' );
		$this->assertStringContainsString( 'was deleted', $context );
		$this->assertSame( array(), $this->server->all( 'A_CreditMemoRequest' ) );
	}

	public function test_a_request_that_cannot_be_deleted_stays_and_the_message_says_so(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->refuseDelete = true;
		$result                     = $this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99' ) ), $this->item( '511', EntityType::Refund ), $this->refundPayload() );
		$this->assertStringContainsString( 'could not be deleted', json_encode( $result->error->context ) );
		$this->assertCount( 1, $this->server->all( 'A_CreditMemoRequest' ) );
	}

	public function test_a_request_that_was_only_found_is_checked_but_not_deleted(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->refuseDelete = true;
		$refund                     = $this->item( '512', EntityType::Refund );
		$this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99' ) ), $refund, $this->refundPayload() );
		$this->server->refuseDelete = false;
		$again                      = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertFalse( $again->isSuccess() );
		$this->assertStringContainsString( 'not made by this pass', json_encode( $again->error->context ) );
		$this->assertCount( 1, $this->server->all( 'A_CreditMemoRequest' ) );
	}

	public function test_a_service_that_has_no_arrangement_is_auth_004_on_the_refund(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$this->server->blocked[] = 'API_CREDIT_MEMO_REQUEST_SRV';
		$result                  = $this->connector->send( $this->connection(), $this->item( '507', EntityType::Refund ), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'A_CreditMemoRequest' ) );
	}
}
