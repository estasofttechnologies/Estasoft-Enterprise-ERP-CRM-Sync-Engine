<?php
/**
 * Sending orders to Sage X3: the body, the diagnoses, the checks and the replays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageX3;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSageX3Server;

final class SageX3OrderTest extends SageX3TestCase {

	public function test_an_order_becomes_a_sales_order_with_the_shops_reference_on_the_web_customer(): void {
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->server->all( 'SORDER' );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WEB001', $order['BPCORD'] );
		$this->assertSame( '2026-11-02', $order['ORDDAT'] );
		$this->assertSame( 'WC-100', $order['CUSORDREF'] );
		$this->assertSame( 'AED', $order['CUR'] );
		$this->assertSame( array( 'ITMREF' => 'CS-BOWL-02', 'QTY' => 2.0, 'GROPRI' => 219.0 ), $order['SORDERQ'][0] );
		$this->assertArrayNotHasKey( 'SALFCY', $order );
		$this->assertSame( 'SOH00001', $result->ref->id );
		$this->assertSame( 'SOH00001', $result->ref->ref );
		$this->assertSame( 45990, $result->ref->extra['total_minor'] );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
	}

	public function test_the_create_goes_to_the_create_facet_of_the_representation_with_basic_authentication(): void {
		$this->sendOrder();
		$post = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => 'POST' === $call['method'] ) )[0];

		$this->assertSame( '/api1/x3/erp/SEED/SORDER', $post['path'] );
		$this->assertSame( 'SOH.$create', $post['query']['representation'] );
		$headers = array_change_key_case( $post['headers'], CASE_LOWER );
		$this->assertSame( 'Basic ' . base64_encode( FakeSageX3Server::USER . ':' . FakeSageX3Server::PASSWORD ), $headers['authorization'] );
	}

	public function test_the_number_comes_from_the_location_header_when_the_answer_lacks_it(): void {
		$this->server->failNext(
			'POST',
			'#/SORDER$#',
			FakeHttpClient::json( array( 'CUSORDREF' => 'WC-100', 'ORDINVATI' => 459.9 ), 201, array( 'location' => FakeSageX3Server::ADDRESS . "/api1/x3/erp/SEED/SORDER('SOH00777')" ) )
		);
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'SOH00777', $result->ref->ref );
	}

	public function test_the_site_and_the_property_names_are_settings(): void {
		$this->server->orderProps = array( 'SOHNUM', 'CUSNUM', 'DAT', 'REFX', 'CURX', 'SITEX', 'LINES', 'ORDINVATI' );
		$this->server->failNext( 'POST', '#/SORDER$#', FakeHttpClient::json( array( 'SOHNUM' => 'SOH1', 'REFX' => 'WC-100', 'ORDINVATI' => 459.9 ), 201 ) );
		$result = $this->sendOrder(
			array(
				'site'               => 'DXB1',
				'prop_customer'      => 'CUSNUM',
				'prop_date'          => 'DAT',
				'prop_reference'     => 'REFX',
				'prop_currency'      => 'CURX',
				'prop_site'          => 'SITEX',
				'prop_lines'         => 'LINES',
				'prop_line_item'     => 'ART',
				'prop_line_quantity' => 'NUM',
				'prop_line_price'    => 'PX',
			)
		);
		$post = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => 'POST' === $call['method'] ) )[0];

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( array( 'CUSNUM', 'DAT', 'REFX', 'CURX', 'SITEX', 'LINES' ), array_keys( $post['body'] ) );
		$this->assertSame( 'DXB1', $post['body']['SITEX'] );
		$this->assertSame( array( 'ART' => 'CS-BOWL-02', 'NUM' => 2.0, 'PX' => 219.0 ), $post['body']['LINES'][0] );
	}

	public function test_the_same_order_is_never_made_twice_and_the_second_send_links_to_the_first(): void {
		$first  = $this->sendOrder();
		$second = $this->sendOrder();

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );
	}

	public function test_a_lost_answer_after_the_create_is_continued_not_repeated(): void {
		$connection = $this->connection( array( 'total_property' => 'ORDINVATI' ) );
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->loseAnswerOf( 'POST', '#/SORDER$#' );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );

		$again = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );
	}

	public function test_findExisting_answers_for_an_order_that_was_made_with_its_total_and_currency(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Order, '100' );
		$this->assertNull( $connector->findExisting( $this->connection( array( 'total_property' => 'ORDINVATI' ) ), $item ) );

		$this->sendOrder();
		$found = $connector->findExisting( $this->connection( array( 'total_property' => 'ORDINVATI' ) ), $item );
		$this->assertSame( 'SOH00001', $found->ref );
		$this->assertSame( 45990, $found->extra['total_minor'] );
		$this->assertSame( 'AED', $found->extra['currency'] );
	}

	public function test_an_order_found_on_the_second_page_of_the_answer_is_still_found(): void {
		$this->server->pageSize = 2;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->server->records['SORDER'][] = array( 'SOHNUM' => 'OLD' . $i, 'CUSORDREF' => 'WC-' . $i, 'CUR' => 'AED' );
		}
		$this->server->records['SORDER'][] = array( 'SOHNUM' => 'SOH00006', 'CUSORDREF' => 'WC-100', 'CUR' => 'AED' );
		$found = $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Order, '100' ) );

		$this->assertSame( 'SOH00006', $found->ref );
	}

	public function test_a_diagnosis_in_a_201_answer_is_a_failure_and_nothing_is_stored(): void {
		$result = $this->sendOrder( array(), array(), '100' );
		$this->assertTrue( $result->isSuccess() );

		$bad = $this->sendOrder( array(), array( 'date_order' => '' ), '101' );
		$this->assertTrue( $bad->isSuccess(), 'An empty date is replaced by today.' );

		$this->server->orderProps = array( 'SOHNUM', 'BPCORD', 'ORDDAT', 'CUSORDREF', 'CUR', 'ORDINVATI', 'SORDERQ' );
		$unknown                  = $this->sendOrder( array( 'site' => 'DXB1' ), array(), '102' );
		$this->assertSame( ErrorCode::Val002, $unknown->error->code );
		$this->assertStringContainsString( "'SALFCY' does not exist", $this->why( $unknown ) );
		$this->assertCount( 2, $this->server->all( 'SORDER' ), 'The failed create stored nothing.' );
	}

	public function test_a_warning_diagnosis_does_not_fail_the_order(): void {
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	public function test_an_unknown_sku_is_map_001_before_the_order_is_sent(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 0, $this->server->count( 'POST', '#.*#' ) );
	}

	public function test_the_item_check_can_be_switched_off_and_x3_then_reports_the_item_itself(): void {
		$result = $this->sendOrder( array( 'item_class' => 'none' ), array( 'order_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'NO-SUCH', $this->why( $result ) );
		$this->assertSame( 0, $this->server->count( 'GET', '#/ITMMASTER$#' ) );
	}

	public function test_a_server_without_the_item_class_is_told_so_by_name(): void {
		unset( $this->server->classes['ITMMASTER'] );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_class', $result->error->context['field'] );
		$this->assertStringContainsString( 'ITMMASTER', $this->why( $result ) );
	}

	public function test_the_customer_code_is_required(): void {
		$result = $this->connector()->send( $this->connection( array( 'customer_code' => '' ) ), $this->item( EntityType::Order, '100' ), $this->order() );

		$this->assertSame( 'customer_code', $result->error->context['field'] );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_an_unknown_customer_code_is_reported_by_x3(): void {
		$result = $this->sendOrder( array( 'customer_code' => 'NOBODY' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'customer does not exist', $this->why( $result ) );
	}

	public function test_a_currency_that_x3_changes_to_the_customers_stops_the_order_and_names_both(): void {
		$result = $this->sendOrder( array( 'customer_code' => 'USD001' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'USD', $this->why( $result ) );
		$this->assertStringContainsString( 'AED', $this->why( $result ) );
	}

	public function test_shipping_fees_and_discounts_need_their_items_and_are_refused_naming_the_setting_without_them(): void {
		foreach ( array(
			'shipping_item' => array( 'shipping' => array( 'total' => 2500 ) ),
			'fee_item'      => array( 'fee' => array( array( 'name' => 'COD', 'total' => 100 ) ) ),
			'discount_item' => array( 'discount' => array( 'total' => 100 ) ),
		) as $field => $extra ) {
			$result = $this->sendOrder( array(), $extra );
			$this->assertSame( $field, $result->error->context['field'] );
		}
		$this->assertSame( 0, $this->server->count( 'POST', '#.*#' ) );
	}

	public function test_shipping_a_fee_and_a_discount_become_lines_with_their_items(): void {
		foreach ( array( 'SHIPPING', 'FEE', 'DISCOUNT' ) as $code ) {
			$this->server->records['ITMMASTER'][] = array( 'ITMREF' => $code );
		}
		$result = $this->sendOrder(
			array( 'shipping_item' => 'SHIPPING', 'fee_item' => 'FEE', 'discount_item' => 'DISCOUNT' ),
			array(
				'shipping' => array( 'total' => 2500 ),
				'fee'      => array( array( 'name' => 'COD fee', 'total' => 1000 ) ),
				'discount' => array( 'total' => 2000 ),
				'total'    => 47565,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$lines = $this->server->all( 'SORDER' )[0]['SORDERQ'];
		$this->assertSame( array( 'CS-BOWL-02', 'SHIPPING', 'FEE', 'DISCOUNT' ), array_column( $lines, 'ITMREF' ) );
		$this->assertSame( array( 25.0, 10.0, -20.0 ), array( $lines[1]['GROPRI'], $lines[2]['GROPRI'], $lines[3]['GROPRI'] ) );
		$this->assertSame( 1.0, $lines[1]['QTY'] );
	}

	public function test_an_extra_item_that_is_not_in_x3_is_map_001(): void {
		$result = $this->sendOrder( array( 'shipping_item' => 'NO-SUCH-SHIP' ), array( 'shipping' => array( 'total' => 2500 ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_prices_with_tax_are_refused_naming_the_setting(): void {
		$result = $this->sendOrder( array(), array( 'prices_include_tax' => true ) );

		$this->assertSame( 'prices_include_tax', $result->error->context['field'] );
	}

	public function test_a_total_that_differs_is_reported_every_pass_and_no_second_order_is_made(): void {
		$payload = array( 'total' => 43800 );
		$first   = $this->sendOrder( array(), $payload );
		$second  = $this->sendOrder( array(), $payload );

		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '459.90', $this->why( $first ) );
		$this->assertFalse( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );
	}

	public function test_a_total_that_differs_can_be_accepted_with_a_warning(): void {
		$result = $this->sendOrder( array( 'total_mismatch' => 'warn' ), array( 'total' => 43800 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
	}

	public function test_without_a_total_property_nothing_is_sent_unless_the_person_chose_to_continue_with_a_warning(): void {
		$refused = $this->sendOrder( array( 'total_property' => '' ) );

		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'total_property', $refused->error->context['field'] );
		$this->assertSame( 0, $this->kit->http->count(), 'Refused before any request, because the total could not be compared.' );

		$warned = $this->sendOrder( array( 'total_property' => '', 'total_mismatch' => 'warn' ) );
		$this->assertTrue( $warned->isSuccess(), $this->why( $warned ) );
		$this->assertStringContainsString( 'total_property', $warned->ref->extra['warnings'][0] );
		$this->assertArrayNotHasKey( 'total_minor', $warned->ref->extra );
	}

	public function test_an_answer_without_the_total_is_refused_after_the_create_unless_the_person_chose_to_continue(): void {
		$this->server->totalInAnswer = false;
		$refused                     = $this->sendOrder();
		$this->assertSame( 'total_property', $refused->error->context['field'] );
		$this->assertStringContainsString( 'stays in Sage X3', $this->why( $refused ) );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );

		$again = $this->sendOrder();
		$this->assertFalse( $again->isSuccess(), 'The order that was made is checked again, not trusted.' );
		$this->assertCount( 1, $this->server->all( 'SORDER' ) );

		$warned = $this->sendOrder( array( 'total_mismatch' => 'warn' ), array(), '101' );
		$this->assertTrue( $warned->isSuccess(), $this->why( $warned ) );
		$this->assertCount( 1, $warned->ref->extra['warnings'] );
	}

	public function test_a_next_page_link_on_another_server_is_not_followed(): void {
		$this->server->failNext(
			'GET',
			'#/SORDER$#',
			FakeHttpClient::json( array( '$resources' => array(), '$links' => array( '$next' => array( '$url' => 'https://evil.example.test/api1/x3/erp/SEED/SORDER?page=2' ) ) ) )
		);
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'address', $result->error->context['field'] );
		foreach ( $this->server->calls as $call ) {
			$this->assertSame( 'x3.example.test', $call['host'], 'Nothing was sent to the other server.' );
		}
		$hosts = array_map( static fn( array $request ): string => (string) wp_parse_url( $request['url'], PHP_URL_HOST ), $this->kit->http->requests );
		$this->assertNotContains( 'evil.example.test', $hosts );
	}

	public function test_a_next_page_link_that_is_a_path_on_the_same_server_is_followed(): void {
		$this->server->pageSize = 1;
		$this->server->records['SORDER'][] = array( 'SOHNUM' => 'OLD1', 'CUSORDREF' => 'WC-1', 'CUR' => 'AED' );
		$this->server->records['SORDER'][] = array( 'SOHNUM' => 'OLD2', 'CUSORDREF' => 'WC-100', 'CUR' => 'AED' );
		$found = $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Order, '100' ) );

		$this->assertSame( 'OLD2', $found->ref );
	}

	public function test_a_line_discount_lowers_the_unit_price_in_whole_numbers(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'discount' => 10 ) ), 'total' => 20695 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 197.1, $this->server->all( 'SORDER' )[0]['SORDERQ'][0]['GROPRI'] );
	}

	public function test_a_customer_item_or_a_refund_is_refused_without_throwing(): void {
		foreach ( array( EntityType::Customer, EntityType::Refund ) as $type ) {
			$result = $this->connector()->send( $this->connection(), $this->item( $type, '5' ), array( 'partner' => array( 'email' => 'a@example.test', 'name' => 'A' ) ) );
			$this->assertFalse( $result->isSuccess() );
			$this->assertSame( ErrorCode::Val002, $result->error->code );
		}
	}

	public function test_a_buyer_without_an_email_or_a_name_does_not_matter(): void {
		$result = $this->sendOrder( array(), array( 'partner' => array( 'email' => '', 'name' => '' ) ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}
}
