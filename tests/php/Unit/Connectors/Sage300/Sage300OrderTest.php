<?php
/**
 * Sending orders to Sage 300: the Order Entry order, the checks and the replays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage300;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSage300Server;

final class Sage300OrderTest extends Sage300TestCase {

	public function test_an_order_becomes_an_order_entry_order_on_the_web_customer_with_the_shops_reference(): void {
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->server->all( 'OE/OEOrders' );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( '1200', $order['CustomerNumber'] );
		$this->assertSame( 'WC-100', $order['PONumber'] );
		$this->assertSame( '2026-11-02', $order['OrderDate'] );
		$this->assertSame( 'Gift wrap please', $order['OrderDescription'] );
		$this->assertSame( 'Item', $order['OrderDetails'][0]['LineType'] );
		$this->assertSame( 'CS-BOWL-02', $order['OrderDetails'][0]['Item'] );
		$this->assertSame( 2.0, (float) $order['OrderDetails'][0]['QuantityOrdered'] );
		$this->assertSame( 219.0, (float) $order['OrderDetails'][0]['UnitPrice'] );
		$this->assertSame( '1001', $result->ref->id );
		$this->assertSame( 'OE000001', $result->ref->ref );
		$this->assertSame( 43800, $result->ref->extra['total_minor'] );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
	}

	public function test_the_properties_are_sent_in_the_documented_order(): void {
		$this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'UAEVAT' ) ), 'total' => 45990 ) );
		$post = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => 'POST' === $call['method'] ) )[0];

		$this->assertSame( array( 'CustomerNumber', 'OrderDate', 'PONumber', 'OrderDescription', 'TaxGroup', 'OrderDetails' ), array_keys( $post['body'] ) );
		$this->assertSame( 'application/json', $post['headers']['Content-Type'] ?? $post['headers']['content-type'] ?? '' );
	}

	public function test_a_request_is_signed_with_basic_authentication_every_time(): void {
		$this->sendOrder();

		$this->assertGreaterThan( 3, count( $this->server->calls ) );
		foreach ( $this->server->calls as $call ) {
			$headers = array_change_key_case( $call['headers'], CASE_LOWER );
			$this->assertSame( 'Basic ' . base64_encode( FakeSage300Server::USER . ':' . FakeSage300Server::PASSWORD ), $headers['authorization'], $call['path'] );
		}
	}

	public function test_the_same_order_is_never_made_twice_and_the_second_send_links_to_the_first(): void {
		$first  = $this->sendOrder();
		$second = $this->sendOrder();

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_a_lost_answer_after_the_create_is_continued_not_repeated(): void {
		$connection = $this->connection();
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->loseAnswerOf( 'POST', '#/OE/OEOrders$#' );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );

		$again = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_findExisting_answers_for_an_order_that_was_made(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Order, '100' );
		$this->assertNull( $connector->findExisting( $this->connection(), $item ) );

		$this->sendOrder();
		$this->assertSame( 'OE000001', $connector->findExisting( $this->connection(), $item )->ref );
	}

	public function test_the_reference_property_option_chooses_another_property(): void {
		$result = $this->sendOrder( array( 'reference_property' => 'Reference' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'WC-100', $this->server->all( 'OE/OEOrders' )[0]['Reference'] );
		$this->assertArrayNotHasKey( 'PONumber', $this->server->all( 'OE/OEOrders' )[0] );
	}

	public function test_a_server_whose_order_has_no_po_number_uses_reference(): void {
		$this->server->orderProps = array( 'OrderUniquifier', 'OrderNumber', 'CustomerNumber', 'OrderDate', 'Reference', 'OrderTotal' );
		$result                   = $this->sendOrder( array(), array( 'note' => '' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'WC-100', $this->server->all( 'OE/OEOrders' )[0]['Reference'] );
	}

	public function test_a_server_whose_order_has_no_reference_property_is_refused_before_anything_is_sent(): void {
		$this->server->orderProps = array( 'OrderUniquifier', 'OrderNumber', 'CustomerNumber', 'OrderDate' );
		$result                   = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'reference_property', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_order_lines_without_a_unit_price_property_are_refused(): void {
		$this->server->detailProps = array( 'LineType', 'Item', 'QuantityOrdered' );
		$result                    = $this->sendOrder();

		$this->assertSame( 'order_line[].price_unit', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_a_server_that_does_not_describe_itself_is_tried_with_the_documented_names(): void {
		$this->server->metadataAvailable = false;
		$result                          = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	public function test_an_unknown_sku_is_map_001_and_nothing_is_created(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_a_sku_is_matched_without_regard_to_case_and_the_item_number_is_the_servers(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'cs-bowl-02', 'quantity' => 1, 'price_unit' => 21900 ) ), 'total' => 21900 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'CS-BOWL-02', $this->server->all( 'OE/OEOrders' )[0]['OrderDetails'][0]['Item'] );
	}

	public function test_the_customer_number_is_required_and_must_exist(): void {
		$none = $this->connector()->send( $this->connection( array( 'customer_number' => '' ) ), $this->item( EntityType::Order, '100' ), $this->order() );
		$this->assertSame( 'customer_number', $none->error->context['field'] );

		$unknown = $this->sendOrder( array( 'customer_number' => '9999' ) );
		$this->assertSame( 'customer_number', $unknown->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_an_order_in_another_currency_than_the_customer_is_refused_naming_both(): void {
		$result = $this->sendOrder( array( 'customer_number' => '1400' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'USD', $this->why( $result ) );
		$this->assertStringContainsString( 'AED', $this->why( $result ) );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_match_only_finds_the_customer_by_the_email_property_and_refuses_an_unknown_buyer(): void {
		$result = $this->sendOrder( array( 'customer_policy' => 'match_only' ) );
		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( '1300', $this->server->all( 'OE/OEOrders' )[0]['CustomerNumber'] );

		$unknown = $this->sendOrder( array( 'customer_policy' => 'match_only' ), array( 'partner' => array( 'email' => 'nobody@example.test' ) ), '101' );
		$this->assertSame( 'partner.email', $unknown->error->context['field'] );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_match_only_without_an_email_property_on_the_customers_says_so(): void {
		$this->server->customerProps = array( 'CustomerNumber', 'CustomerName' );
		$result                      = $this->sendOrder( array( 'customer_policy' => 'match_only' ) );

		$this->assertSame( 'customer_policy', $result->error->context['field'] );
	}

	public function test_a_tax_group_goes_on_the_order_and_the_total_is_checked(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'uaevat' ) ), 'total' => 45990 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'UAEVAT', $this->server->all( 'OE/OEOrders' )[0]['TaxGroup'], 'The key as the server spells it.' );
		$this->assertSame( 45990, $result->ref->extra['total_minor'] );
	}

	public function test_an_unknown_tax_group_is_map_002(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => 'NOPE' ) ) ) );

		$this->assertSame( ErrorCode::Map002, $result->error->code );
	}

	public function test_two_tax_groups_or_a_group_and_none_are_refused(): void {
		$lines = array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => 'UAEVAT' ), array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'price_unit' => 100, 'tax' => 'NOTAX' ) );
		$two   = $this->sendOrder( array(), array( 'order_line' => $lines ) );
		$this->assertSame( 'order_line[].tax', $two->error->context['field'] );

		$lines[1]['tax'] = '';
		$mixed           = $this->sendOrder( array(), array( 'order_line' => $lines ) );
		$this->assertSame( 'order_line[].tax', $mixed->error->context['field'] );
	}

	public function test_a_server_without_a_tax_group_property_refuses_a_tax_group(): void {
		$this->server->orderProps = array( 'OrderUniquifier', 'OrderNumber', 'CustomerNumber', 'OrderDate', 'PONumber', 'OrderTotal' );
		$result                   = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => 'UAEVAT' ) ) ) );

		$this->assertSame( 'order_line[].tax', $result->error->context['field'] );
	}

	public function test_prices_with_tax_are_refused_naming_the_setting(): void {
		$result = $this->sendOrder( array(), array( 'prices_include_tax' => true ) );

		$this->assertSame( 'prices_include_tax', $result->error->context['field'] );
	}

	public function test_a_total_that_differs_is_reported_every_pass_and_no_second_order_is_made(): void {
		$payload = array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'UAEVAT' ) ), 'total' => 43800 );
		$first   = $this->sendOrder( array(), $payload );
		$second  = $this->sendOrder( array(), $payload );

		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '459.90', $this->why( $first ) );
		$this->assertFalse( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_a_total_that_differs_can_be_accepted_with_a_warning(): void {
		$result = $this->sendOrder( array( 'total_mismatch' => 'warn' ), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'UAEVAT' ) ), 'total' => 43800 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
	}

	public function test_an_answer_without_a_total_is_refused_so_price_lists_cannot_change_prices_unnoticed(): void {
		$this->server->totalInAnswer = false;
		$refused                     = $this->sendOrder();

		$this->assertFalse( $refused->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'total_property', $refused->error->context['field'] );
		$this->assertStringContainsString( 'stays in Sage 300', $this->why( $refused ) );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );

		$again = $this->sendOrder();
		$this->assertFalse( $again->isSuccess(), 'The order that was made is checked again, not trusted.' );
		$this->assertCount( 1, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_an_answer_without_a_total_is_accepted_with_a_note_when_the_person_chose_to_continue(): void {
		$this->server->totalInAnswer = false;
		$result                      = $this->sendOrder( array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertStringContainsString( 'not verified', $result->ref->extra['warnings'][0] );
		$this->assertArrayNotHasKey( 'total_minor', $result->ref->extra );
	}

	public function test_the_total_property_option_names_the_property_to_read(): void {
		$this->server->orderProps    = array_merge( $this->server->orderProps, array( 'GrandTotal' ) );
		$this->server->totalInAnswer = false;
		$this->server->failNext(
			'POST',
			'#/OE/OEOrders$#',
			static fn() => FakeHttpClient::json( array( 'OrderNumber' => 'OE000099', 'OrderUniquifier' => '5', 'PONumber' => 'WC-100', 'GrandTotal' => 438.0 ), 201 )
		);
		$result = $this->sendOrder( array( 'total_property' => 'GrandTotal' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 43800, $result->ref->extra['total_minor'] );
	}

	public function test_shipping_fees_and_a_discount_become_miscellaneous_lines_with_their_codes(): void {
		$result = $this->sendOrder(
			array( 'shipping_charge_code' => 'SHIP', 'fee_charge_code' => 'COD', 'discount_charge_code' => 'DISC', 'location' => 'MAIN' ),
			array(
				'shipping' => array( 'total' => 2500 ),
				'fee'      => array( array( 'name' => 'COD fee', 'total' => 1000 ) ),
				'discount' => array( 'total' => 2000 ),
				'total'    => 45300,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$details = $this->server->all( 'OE/OEOrders' )[0]['OrderDetails'];
		$this->assertSame( 'MAIN', $details[0]['Location'] );
		$this->assertSame( array( 'Miscellaneous', 'SHIP', 25.0 ), array( $details[1]['LineType'], $details[1]['MiscellaneousChargesCode'], (float) $details[1]['ExtendedAmount'] ) );
		$this->assertSame( 'COD', $details[2]['MiscellaneousChargesCode'] );
		$this->assertSame( -20.0, (float) $details[3]['ExtendedAmount'] );
	}

	public function test_shipping_without_its_charge_code_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'shipping' => array( 'total' => 2500 ) ) );

		$this->assertSame( 'shipping_charge_code', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'OE/OEOrders' ) );
	}

	public function test_a_line_discount_lowers_the_unit_price_in_whole_numbers(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'discount' => 10 ) ), 'total' => 19710 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 197.1, (float) $this->server->all( 'OE/OEOrders' )[0]['OrderDetails'][0]['UnitPrice'] );
	}

	public function test_a_buyer_without_an_email_is_fine_with_the_fixed_customer_and_map_003_when_matching(): void {
		$fixed = $this->sendOrder( array(), array( 'partner' => array( 'email' => '', 'name' => '' ) ) );
		$this->assertTrue( $fixed->isSuccess(), $this->why( $fixed ) );

		$matching = $this->sendOrder( array( 'customer_policy' => 'match_only' ), array( 'partner' => array( 'email' => '' ) ), '101' );
		$this->assertSame( ErrorCode::Map003, $matching->error->code );
	}

	public function test_an_order_reference_longer_than_the_po_number_is_refused(): void {
		$result = $this->connector()->send( $this->connection(), $this->item( EntityType::Order, str_repeat( '9', 25 ) ), $this->order() );

		$this->assertSame( 'client_ref', $result->error->context['field'] );
	}

	public function test_a_refused_payload_comes_back_as_val_002_with_the_servers_words(): void {
		$this->server->failNext( 'POST', '#/OE/OEOrders$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'RecordInvalid', 'message' => array( 'lang' => 'en', 'value' => 'Item <b>X</b> is on hold' ) ) ), 400 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'Item X is on hold', $result->error->context['reason'] );
	}
}
