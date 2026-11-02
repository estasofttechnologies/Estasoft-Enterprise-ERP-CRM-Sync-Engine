<?php
/**
 * Orders, customers and duplicate protection against the fake NetSuite.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class NetSuiteOrderFlowTest extends NetSuiteTestCase {

	public function test_an_order_becomes_a_sales_order_with_the_client_reference_everywhere_it_is_searched(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1001' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$orders = array_values( $this->server->all( 'salesOrder' ) );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WC-1001', $order['externalId'] );
		$this->assertSame( 'WC-1001', $order['otherRefNum'] );
		$this->assertSame( '2026-11-02', $order['tranDate'], 'The order date, not the day it was synced.' );
		$this->assertSame( '2', $order['currency']['id'] );
		$this->assertSame( 3, $order['item']['items'][0]['quantity'] );
		$this->assertSame( 19.99, $order['item']['items'][0]['rate'] );
		$this->assertSame( $this->itemId( 'CS-MUG-01' ), $order['item']['items'][0]['item']['id'] );
		$this->assertStringContainsString( 'Gift wrap please', $order['memo'] );
		$this->assertStringEndsWith( 'Shop total 59.97 AED', $order['memo'] );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
		$this->assertSame( 'SO' . $order['id'], $result->ref->ref );
		$this->assertStringContainsString( 'salesord.nl?id=' . $order['id'], (string) $result->ref->url );
	}

	public function test_a_customer_is_found_by_email_in_any_case_and_not_created_again(): void {
		$payload                     = $this->payload();
		$payload['partner']['email'] = 'EXISTING@Example.Test';
		$result                      = $this->connector->send( $this->connection(), $this->item( '1002' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->customers, 'The customer that was there is used.' );
		$order = array_values( $this->server->all( 'salesOrder' ) )[0];
		$this->assertSame( (string) array_key_first( $this->server->customers ), $order['entity']['id'] );
	}

	public function test_a_new_customer_is_created_once_by_external_id_and_the_name_is_split_for_a_person(): void {
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1003' ), $this->payload() )->isSuccess() );
		$customers = $this->server->customers;
		$this->assertCount( 2, $customers );
		$new = end( $customers );
		$this->assertSame( 'WC-C-' . substr( sha1( 'noor@example.test' ), 0, 20 ), $new['externalId'] );
		$this->assertTrue( $new['isPerson'] );
		$this->assertSame( 'Noor', $new['firstName'] );
		$this->assertSame( 'Al-Mansouri', $new['lastName'] );
		$this->assertSame( '2', $new['currency']['id'] );
		$this->assertSame( 'noor@example.test', $new['email'] );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1004' ), $this->payload() )->isSuccess() );
		$this->assertCount( 2, $this->server->customers, 'The second order finds the customer.' );
	}

	public function test_a_company_name_makes_a_company_customer_and_the_subsidiary_is_sent(): void {
		$payload                             = $this->payload();
		$payload['partner']['company_name']  = 'Al Noor Trading LLC';
		$result                              = $this->connector->send( $this->connection( array(), array( 'subsidiary' => '7' ) ), $this->item( '1005' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$new = end( $this->server->customers );
		$this->assertFalse( $new['isPerson'] );
		$this->assertSame( 'Al Noor Trading LLC', $new['companyName'] );
		$this->assertSame( array( 'id' => '7' ), $new['subsidiary'] );
		$this->assertArrayNotHasKey( 'firstName', $new );
	}

	public function test_match_only_refuses_an_unknown_customer_before_any_order_exists(): void {
		$result = $this->connector->send( $this->connection( array( 'customer_policy' => 'match_only' ) ), $this->item( '1006' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'salesOrder' ) );
		$this->assertCount( 1, $this->server->customers );
	}

	public function test_sending_again_links_to_the_first_order_and_makes_no_second_one(): void {
		$first  = $this->connector->send( $this->connection(), $this->item( '1007' ), $this->payload() );
		$puts   = count( $this->puts( 'salesOrder' ) );
		$second = $this->connector->send( $this->connection(), $this->item( '1007' ), $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( $puts, $this->puts( 'salesOrder' ), 'No second write.' );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ) );
	}

	public function test_an_order_made_by_a_first_request_whose_answer_was_lost_is_found_not_duplicated(): void {
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1008' ), $this->payload() )->isSuccess() );
		$found = $this->connector->findExisting( $this->connection(), $this->item( '1008' ) );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $this->item( '1009' ) ) );
	}

	public function test_a_wrong_total_fails_by_default_and_keeps_failing_on_the_next_pass(): void {
		$this->server->taxRate = 5.0;
		$result                = $this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '62.97', json_encode( $result->error->context ) );
		$this->assertStringContainsString( '59.97', json_encode( $result->error->context ) );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ), 'Nothing is deleted behind the merchant\'s back.' );
		$found = $this->connector->findExisting( $this->connection(), $this->item( '1010' ) );
		$this->assertInstanceOf( ConnectorError::class, $found, 'The next pass does not link a wrong order silently.' );
		$again = $this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ) );
	}

	public function test_a_difference_is_accepted_with_a_warning_when_the_merchant_chose_warn(): void {
		$this->server->taxRate = 5.0;
		$result                = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item( '1011' ), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item( '1011' ) ) );
	}

	public function test_a_rounding_difference_of_one_minor_unit_is_accepted(): void {
		$payload          = $this->payload();
		$payload['total'] = 5998;
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1012' ), $payload )->isSuccess() );
	}

	public function test_an_order_that_comes_back_in_another_currency_fails_loudly(): void {
		$this->server->forceCurrency = '1';
		$result                      = $this->connector->send( $this->connection(), $this->item( '1013' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'currency', json_encode( $result->error->context ) );
	}

	public function test_prices_with_tax_are_refused_before_any_request_is_made(): void {
		$payload                       = $this->payload();
		$payload['prices_include_tax'] = true;
		$before                        = $this->kit->http->count();
		$result                        = $this->connector->send( $this->connection(), $this->item( '1014' ), $payload );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( $before, $this->kit->http->count(), 'Refused before the network is touched.' );
		$this->assertSame( array(), $this->server->all( 'salesOrder' ) );
	}

	public function test_an_unknown_sku_stops_the_order_before_a_customer_or_order_is_created(): void {
		$payload                         = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH-SKU';
		$result                          = $this->connector->send( $this->connection(), $this->item( '1015' ), $payload );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'salesOrder' ) );
		$this->assertCount( 1, $this->server->customers );
	}

	public function test_a_sku_text_is_looked_up_when_the_mapping_did_not_resolve_it(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'cs-mug-01';
		$result                              = $this->connector->send( $this->connection(), $this->item( '1016' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $this->itemId( 'CS-MUG-01' ), array_values( $this->server->all( 'salesOrder' ) )[0]['item']['items'][0]['item']['id'] );
	}

	public function test_a_missing_email_is_map_003_and_a_missing_currency_is_val_002(): void {
		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection(), $this->item( '1017' ), $payload )->error->code );
		$payload = $this->payload();
		unset( $payload['currency'] );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection(), $this->item( '1018' ), $payload )->error->code );
	}

	public function test_shipping_goes_to_the_shipping_cost_and_fees_and_discounts_need_their_items(): void {
		$payload             = $this->payload();
		$payload['total']    = 5997 + 2500;
		$payload['shipping'] = array( 'total' => 2500 );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1019' ), $payload )->isSuccess() );
		$this->assertSame( 25.0, array_values( $this->server->all( 'salesOrder' ) )[0]['shippingCost'] );
		$withFee          = $this->payload();
		$withFee['fee']   = array( array( 'name' => 'Gift wrap', 'total' => 500 ) );
		$withFee['total'] = 6497;
		$refused          = $this->connector->send( $this->connection(), $this->item( '1020' ), $withFee );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'fee_item', json_encode( $refused->error->context ) );
		$fee = $this->server->addItem( 'FEE', 'Fee' );
		$ok  = $this->connector->send( $this->connection( array( 'fee_item' => $fee ) ), $this->item( '1020' ), $withFee );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$lines = array_values( $this->server->all( 'salesOrder' ) )[1]['item']['items'];
		$this->assertSame( $fee, $lines[1]['item']['id'] );
		$this->assertSame( 5.0, $lines[1]['rate'] );
	}

	public function test_a_discount_is_a_negative_line_on_the_discount_item(): void {
		$discount           = $this->server->addItem( 'DISC', 'Discount' );
		$payload            = $this->payload();
		$payload['total']   = 5997 - 600;
		$payload['discount'] = array( 'name' => 'SAVE10', 'total' => 600 );
		$result             = $this->connector->send( $this->connection( array( 'discount_item' => $discount ) ), $this->item( '1021' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = array_values( $this->server->all( 'salesOrder' ) )[0]['item']['items'][1];
		$this->assertSame( -6.0, $line['rate'] );
		$this->assertSame( 'SAVE10', $line['description'] );
	}

	public function test_addresses_are_sent_unless_the_merchant_turns_them_off(): void {
		$this->connector->send( $this->connection(), $this->item( '1022' ), $this->payload() );
		$order = array_values( $this->server->all( 'salesOrder' ) )[0];
		$this->assertSame( 'Sheikh Zayed Road', $order['billingAddress']['addr1'] );
		$this->assertSame( array( 'id' => 'AE' ), $order['shippingAddress']['country'] );
		$this->assertSame( 'Marina Walk 4', $order['shippingAddress']['addr1'] );
		$this->connector->send( $this->connection( array( 'send_addresses' => 'no' ) ), $this->item( '1023' ), $this->payload() );
		$second = array_values( $this->server->all( 'salesOrder' ) )[1];
		$this->assertArrayNotHasKey( 'billingAddress', $second );
		$this->assertArrayNotHasKey( 'shippingAddress', $second );
	}

	public function test_a_long_note_is_cut_with_an_ellipsis_and_a_warning_and_the_reference_is_never_cut(): void {
		$payload         = $this->payload();
		$payload['note'] = str_repeat( 'ن', 1200 );
		$result          = $this->connector->send( $this->connection(), $this->item( '1024' ), $payload );
		$this->assertTrue( $result->isSuccess() );
		$order = array_values( $this->server->all( 'salesOrder' ) )[0];
		$this->assertLessThanOrEqual( 999, mb_strlen( $order['memo'], 'UTF-8' ) );
		$this->assertStringContainsString( "\u{2026}", $order['memo'] );
		$this->assertStringEndsWith( 'Shop total 59.97 AED', $order['memo'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'WC-1024', $order['externalId'] );
	}

	public function test_a_customer_item_is_matched_or_created(): void {
		$payload = array(
			'partner' => array(
				'name'  => 'Layla Haddad',
				'email' => 'layla@example.test',
			),
		);
		$item    = $this->item( '77', \Estasoft\SyncEngine\Domain\EntityType::Customer );
		$first   = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$second = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
	}

	public function test_a_rate_limit_error_code_carries_a_wait_even_without_retry_after(): void {
		$this->server->failNext(
			FakeHttpClient::json(
				array(
					'status'         => 429,
					'o:errorDetails' => array( array( 'o:errorCode' => 'CONCURRENCY_LIMIT_EXCEEDED', 'detail' => 'Request limit exceeded.' ) ),
				),
				429
			)
		);
		$result = $this->connector->send( $this->connection(), $this->item( '1025' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 10, $result->error->retryAfter );
	}

	/**
	 * PUT requests to a record type.
	 *
	 * @param string $type Record type.
	 * @return array<int,array<string,mixed>>
	 */
	private function puts( string $type ): array {
		return array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'PUT' === $request['method'] && str_contains( $request['url'], '/record/v1/' . $type . '/' ) ) );
	}
}
