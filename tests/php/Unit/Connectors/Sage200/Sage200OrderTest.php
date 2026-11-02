<?php
/**
 * Sending orders to Sage 200: the SOP order, the customer, the checks and the replays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage200;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSage200Server;

final class Sage200OrderTest extends Sage200TestCase {

	public function test_an_order_becomes_a_sop_order_with_the_shops_reference_date_and_lines(): void {
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->server->all( 'sop_orders' );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WC-100', $order['customer_document_no'] );
		$this->assertSame( '2026-11-02T00:00:00Z', $order['document_date'] );
		$this->assertSame( 102, $order['lines'][0]['product_id'] );
		$this->assertSame( 2.0, (float) $order['lines'][0]['line_quantity'] );
		$this->assertSame( 21.9, (float) $order['lines'][0]['selling_unit_price'] );
		$this->assertSame( 1, (int) $order['lines'][0]['tax_code_id'] );
		$this->assertSame( 'EnumLineTypeStandard', $order['lines'][0]['line_type'] );
		$this->assertSame( 52.56, (float) $order['total_gross_value'] );
		$this->assertSame( 5256, $result->ref->extra['total_minor'] );
		$this->assertSame( 'GBP', $result->ref->extra['currency'] );
		$this->assertSame( 'SOP000001', $result->ref->ref );
	}

	public function test_the_site_company_and_subscription_key_are_sent_on_every_call(): void {
		$this->sendOrder();

		foreach ( $this->server->calls as $call ) {
			if ( 'api.columbus.sage.com' !== $call['host'] ) {
				continue;
			}
			$headers = array_change_key_case( $call['headers'], CASE_LOWER );
			$this->assertSame( FakeSage200Server::SUBSCRIPTION_KEY, $headers['ocp-apim-subscription-key'], $call['path'] );
			$this->assertSame( FakeSage200Server::SITE, $headers['x-site'] );
			$this->assertSame( FakeSage200Server::COMPANY, $headers['x-company'] );
			$this->assertStringStartsWith( 'Bearer ', $headers['authorization'] );
		}
	}

	public function test_the_post_answer_without_totals_is_followed_by_a_read_and_one_with_totals_is_not(): void {
		$this->sendOrder();
		$this->assertSame( 1, $this->server->count( 'GET', '#/sop_orders/\d+$#' ) );

		$this->server->postAnswerHasTotals = true;
		$this->server->calls               = array();
		$this->sendOrder( array(), array(), '101' );
		$this->assertSame( 0, $this->server->count( 'GET', '#/sop_orders/\d+$#' ) );
	}

	public function test_the_buyer_becomes_a_customer_with_a_contact_and_an_eight_character_reference(): void {
		$this->sendOrder();

		$customers = $this->server->all( 'customers' );
		$this->assertCount( 1, $customers );
		$this->assertSame( 'WEB0002S', $customers[0]['reference'], 'WEB and the order number in base 36.' );
		$this->assertLessThanOrEqual( 8, strlen( $customers[0]['reference'] ) );
		$this->assertSame( 'Amelia Hart', $customers[0]['name'] );
		$this->assertSame( 1, $customers[0]['currency_id'], 'The base currency, read once from the currencies.' );
		$this->assertSame( '4 Mill Lane', $customers[0]['address_1'] );
		$contacts = $this->server->all( 'customer_contacts' );
		$this->assertCount( 1, $contacts );
		$this->assertSame( 'amelia@example.test', $contacts[0]['default_email'] );
		$this->assertSame( $customers[0]['id'], $contacts[0]['customer_id'] );
		$this->assertSame( $customers[0]['id'], $this->server->all( 'sop_orders' )[0]['customer_id'] );
	}

	public function test_the_prefix_option_and_the_default_tax_code_go_to_a_new_customer(): void {
		$this->sendOrder( array( 'customer_reference_prefix' => 'shop', 'default_tax_code' => '1' ) );

		$customer = $this->server->all( 'customers' )[0];
		$this->assertStringStartsWith( 'SHO', $customer['reference'] );
		$this->assertSame( '1', (string) $customer['default_tax_code_id'] );
	}

	public function test_an_unknown_default_tax_code_is_map_002_before_anything_is_created(): void {
		$result = $this->sendOrder( array( 'default_tax_code' => '99' ) );

		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'customers' ) );
	}

	public function test_a_customer_with_the_same_contact_email_is_used_whatever_the_case(): void {
		$existing = $this->server->addCustomer( 'ALPHA001', 'Amelia existing', 'AMELIA@example.test' );
		$result   = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $this->server->all( 'customers' ) );
		$this->assertSame( $existing['id'], $this->server->all( 'sop_orders' )[0]['customer_id'] );
	}

	public function test_an_inactive_customer_is_not_used(): void {
		$this->server->addCustomer( 'ALPHA001', 'Amelia existing', 'amelia@example.test', array( 'account_status_type' => 'EnumAccountStatusTypeInactive' ) );
		$this->sendOrder();

		$this->assertCount( 2, $this->server->all( 'customers' ) );
	}

	public function test_match_only_refuses_an_unknown_buyer_and_creates_nothing(): void {
		$result = $this->sendOrder( array( 'customer_policy' => 'match_only' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'customers' ) );
		$this->assertCount( 0, $this->server->all( 'sop_orders' ) );
	}

	public function test_a_pass_that_stopped_after_the_customer_finds_it_again_by_its_reference(): void {
		$connector  = $this->connector();
		$connection = $this->connection();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->failNext( 'POST', '#/customer_contacts$#', FakeHttpClient::timeout() );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'customers' ) );
		$this->assertCount( 0, $this->server->all( 'customer_contacts' ) );

		$second = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $second->isSuccess(), $this->why( $second ) );
		$this->assertCount( 1, $this->server->all( 'customers' ), 'The customer the first pass made is used, not made again.' );
		$this->assertCount( 1, $this->server->all( 'customer_contacts' ) );
	}

	public function test_a_different_delivery_address_becomes_a_delivery_address_of_the_customer(): void {
		$this->sendOrder( array(), array( 'delivery' => array( 'name' => 'Ben Hart', 'street' => '9 High Street', 'city' => 'York', 'zip' => 'YO1 7HH', 'country' => 'GB' ) ) );

		$addresses = $this->server->all( 'customer_delivery_addresses' );
		$this->assertCount( 1, $addresses );
		$this->assertSame( '9 High Street', $addresses[0]['address_1'] );
		$this->assertSame( $addresses[0]['id'], $this->server->all( 'sop_orders' )[0]['customer_delivery_address_id'] );

		$this->sendOrder( array(), array( 'delivery' => array( 'name' => 'Ben Hart', 'street' => '9 High Street', 'city' => 'York', 'zip' => 'YO1 7HH', 'country' => 'GB' ) ), '101' );
		$this->assertCount( 1, $this->server->all( 'customer_delivery_addresses' ), 'The same address is reused.' );
	}

	public function test_the_same_order_is_never_made_twice_and_the_second_send_links_to_the_first(): void {
		$first  = $this->sendOrder();
		$second = $this->sendOrder();

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'sop_orders' ) );
		$this->assertCount( 1, $this->server->all( 'customers' ) );
	}

	public function test_a_lost_answer_after_the_create_is_continued_not_repeated(): void {
		$connection = $this->connection();
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->loseAnswerOf( 'POST', '#/sop_orders$#' );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'sop_orders' ) );

		$again = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'sop_orders' ) );
		$this->assertSame( 'SOP000001', $again->ref->ref );
	}

	public function test_findExisting_answers_for_an_order_that_was_made(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Order, '100' );
		$this->assertNull( $connector->findExisting( $this->connection(), $item ) );

		$this->sendOrder();
		$this->assertSame( 'SOP000001', $connector->findExisting( $this->connection(), $item )->ref );
	}

	public function test_an_unknown_sku_is_map_001_and_nothing_is_created(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'customers' ) );
		$this->assertCount( 0, $this->server->all( 'sop_orders' ) );
	}

	public function test_a_sku_is_matched_without_regard_to_case_and_one_request_is_made_per_code(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'cs-bowl-02', 'quantity' => 1, 'price_unit' => 2190, 'tax' => '1' ), array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'price_unit' => 1000, 'tax' => '1' ) ), 'total' => 3828 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 2, $this->server->count( 'GET', '#/products$#' ), 'The In filter is not supported, so one request per code.' );
	}

	public function test_an_unknown_tax_code_is_map_002(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 2190, 'tax' => 'T9' ) ) ) );

		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'sop_orders' ) );
	}

	public function test_a_buyer_without_an_email_is_map_003(): void {
		$result = $this->sendOrder( array(), array( 'partner' => array( 'email' => '' ) ) );

		$this->assertSame( ErrorCode::Map003, $result->error->code );
	}

	public function test_an_order_in_another_currency_is_refused_naming_both_currencies_before_anything_is_made(): void {
		$result = $this->sendOrder( array(), array( 'currency' => 'EUR' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'EUR', $this->why( $result ) );
		$this->assertStringContainsString( 'GBP', $this->why( $result ) );
		$this->assertCount( 0, $this->server->all( 'customers' ) );
	}

	public function test_the_base_currency_can_be_set_and_its_decimals_are_used(): void {
		$this->server->places = 3;
		$result               = $this->sendOrder(
			array( 'base_currency' => 'kwd' ),
			array(
				'currency'   => 'KWD',
				'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 3, 'price_unit' => 12345, 'tax' => '0' ) ),
				'total'      => 37035,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 12.345, (float) $this->server->all( 'sop_orders' )[0]['lines'][0]['selling_unit_price'] );
		$this->assertSame( 37035, $result->ref->extra['total_minor'], '3 x 12.345 in three decimals.' );
	}

	public function test_a_customer_in_another_currency_stops_the_order_with_both_currencies_named(): void {
		$this->server->addCustomer( 'EURO0001', 'Amelia Euro', 'amelia@example.test', array( 'currency_id' => 2 ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'EUR', $this->why( $result ) );
		$this->assertCount( 1, $this->server->all( 'sop_orders' ), 'The order is in Sage 200 for the person.' );
	}

	public function test_a_total_that_differs_is_reported_every_pass_and_no_second_order_is_made(): void {
		$payload = array( 'total' => 4380 );
		$first   = $this->sendOrder( array(), $payload );
		$second  = $this->sendOrder( array(), $payload );

		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '52.56', $this->why( $first ) );
		$this->assertFalse( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'sop_orders' ) );
	}

	public function test_a_total_that_differs_can_be_accepted_with_a_warning(): void {
		$result = $this->sendOrder( array( 'total_mismatch' => 'warn' ), array( 'total' => 4380 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
	}

	public function test_prices_with_tax_are_converted_with_the_rate_of_the_tax_code(): void {
		$result = $this->sendOrder(
			array(),
			array(
				'prices_include_tax' => true,
				'order_line'         => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 2628, 'tax' => '1' ) ),
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 21.9, (float) $this->server->all( 'sop_orders' )[0]['lines'][0]['selling_unit_price'], '26.28 with 20 percent is 21.90 without.' );
		$this->assertSame( 5256, $result->ref->extra['total_minor'] );
	}

	public function test_a_line_discount_goes_in_the_discount_field(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 2000, 'tax' => '0', 'discount' => 10 ) ), 'total' => 1800 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 10.0, (float) $this->server->all( 'sop_orders' )[0]['lines'][0]['unit_discount_percent'] );
	}

	public function test_shipping_fees_and_a_discount_become_lines_with_their_products(): void {
		$result = $this->sendOrder(
			array( 'shipping_product' => 'shipping', 'fee_product' => 'FEE', 'discount_product' => 'DISCOUNT' ),
			array(
				'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 2000, 'tax' => '0' ) ),
				'shipping'   => array( 'total' => 500, 'tax' => '0' ),
				'fee'        => array( array( 'name' => 'COD fee', 'total' => 100, 'tax' => '0' ) ),
				'discount'   => array( 'total' => 200, 'tax' => '0' ),
				'total'      => 2400,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$lines = $this->server->all( 'sop_orders' )[0]['lines'];
		$this->assertSame( array( 102, 103, 104, 105 ), array_column( $lines, 'product_id' ) );
		$this->assertSame( -2.0, (float) $lines[3]['selling_unit_price'] );
	}

	public function test_shipping_without_its_product_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'shipping' => array( 'total' => 500 ) ) );

		$this->assertSame( 'shipping_product', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'sop_orders' ) );
	}

	public function test_a_user_who_may_not_change_prices_gets_the_permission_named(): void {
		$this->server->canOverridePrices = false;
		$result                          = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Unit Selling Price', $this->why( $result ) );
		$this->assertStringContainsString( 'permission to override prices', $this->why( $result ) );
	}

	public function test_a_line_with_two_tax_codes_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 2190, 'tax' => array( '1', '2' ) ) ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_a_customer_item_creates_or_matches_a_customer(): void {
		$connector  = $this->connector();
		$connection = $this->connection();
		$item       = $this->item( EntityType::Customer, '5' );
		$payload    = array( 'partner' => array( 'name' => 'Layla Haddad', 'email' => 'layla@example.test', 'country' => 'GB' ) );

		$first = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $first->isSuccess(), $this->why( $first ) );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $connector->send( $connection, $item, $payload )->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'customers' ) );
	}
}
