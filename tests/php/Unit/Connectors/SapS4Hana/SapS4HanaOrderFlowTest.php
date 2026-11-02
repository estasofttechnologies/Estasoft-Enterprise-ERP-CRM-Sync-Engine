<?php
/**
 * Sales orders against the fake S/4HANA tenant: deep insert, CSRF, duplicate protection and the checks.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class SapS4HanaOrderFlowTest extends SapS4HanaTestCase {

	/**
	 * The requests of one method that went to a path.
	 *
	 * @param string $method Method.
	 * @param string $suffix End of the address.
	 * @return array<int,array<string,mixed>>
	 */
	private function calls( string $method, string $suffix ): array {
		return array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => $method === $request['method'] && str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), $suffix ) ) );
	}

	public function test_an_order_is_one_deep_insert_with_the_shop_price_on_every_item(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1001' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$posts = $this->calls( 'POST', '/A_SalesOrder' );
		$this->assertCount( 1, $posts );
		$body = json_decode( (string) $posts[0]['body'], true );
		$this->assertSame( 'OR', $body['SalesOrderType'] );
		$this->assertSame( '1710', $body['SalesOrganization'] );
		$this->assertSame( '10', $body['DistributionChannel'] );
		$this->assertSame( '00', $body['OrganizationDivision'], 'The OData name of the division is OrganizationDivision.' );
		$this->assertSame( '17100001', $body['SoldToParty'] );
		$this->assertSame( 'WC-1001', $body['PurchaseOrderByCustomer'] );
		$this->assertSame( 'AED', $body['TransactionCurrency'] );
		$this->assertSame( '/Date(1793577600000)/', $body['SalesOrderDate'], 'The order date 2026-11-02 as /Date(milliseconds)/ at midnight UTC.' );
		$this->assertSame( 'CS-MUG-01', $body['to_Item'][0]['Material'] );
		$this->assertSame( '3', $body['to_Item'][0]['RequestedQuantity'], 'Edm.Decimal travels as a string in OData V2.' );
		$this->assertSame( array( 'ConditionType' => 'PR00', 'ConditionRateValue' => '21.00', 'ConditionCurrency' => 'AED' ), $body['to_Item'][0]['to_PricingElement'][0] );
		$order = array_values( $this->server->all( 'A_SalesOrder' ) )[0];
		$this->assertSame( $order['SalesOrder'], $result->ref->id );
		$this->assertSame( 6300, $result->ref->extra['net_minor'] );
		$this->assertArrayNotHasKey( 'warnings', $result->ref->extra );
	}

	public function test_the_same_order_is_found_by_its_reference_and_never_made_twice(): void {
		$item = $this->item( '1002' );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $item ) );
		$first = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $item ) );
		$again = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertTrue( $again->isSuccess() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'A_SalesOrder' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/A_SalesOrder' ) );
	}

	public function test_the_search_uses_an_escaped_filter_on_the_customer_reference(): void {
		$this->connector->findExisting( $this->connection(), new Item( 7, 1, EntityType::Order, "7' or 'a'='a", 'created', 'order:7', 'h', ItemState::Syncing ) );
		$search = $this->calls( 'GET', '/A_SalesOrder' )[0];
		parse_str( (string) wp_parse_url( $search['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( "PurchaseOrderByCustomer eq 'WC-7'' or ''a''=''a'", $query['$filter'], 'A quote in the id cannot widen the filter.' );
	}

	public function test_a_csrf_token_that_went_stale_is_fetched_again_once_and_the_write_goes_through(): void {
		$this->server->staleTokens = 1;
		$result                    = $this->connector->send( $this->connection(), $this->item( '1003' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 2, $this->server->csrfFetches );
		$this->assertCount( 2, $this->calls( 'POST', '/A_SalesOrder' ), 'The refused attempt and the good one.' );
		$this->assertCount( 1, $this->server->all( 'A_SalesOrder' ) );
	}

	public function test_a_token_that_stays_invalid_stops_after_one_retry_with_an_error_that_names_no_token(): void {
		$this->server->staleTokens = 2;
		$result                    = $this->connector->send( $this->connection(), $this->item( '1004' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ) );
		$this->assertLessThanOrEqual( 2, $this->server->csrfFetches );
		$this->assertStringNotContainsString( $this->server->csrf, json_encode( array( $result->error->message(), $result->error->detail, $result->error->context ) ) );
	}

	public function test_the_oauth_token_is_requested_once_and_sent_as_a_bearer_header(): void {
		$this->connector->send( $this->connection(), $this->item( '1005' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '1006' ), $this->payload() );
		$this->assertCount( 1, $this->server->tokenCalls );
		$this->assertSame( 'client_credentials', $this->server->tokenCalls[0]['grant_type'] );
		$this->assertSame( $this->server->scope, $this->server->tokenCalls[0]['scope'] );
		$this->assertSame( 'Bearer ' . $this->server->token, $this->calls( 'POST', '/A_SalesOrder' )[0]['headers']['Authorization'] );
	}

	public function test_a_price_the_system_does_not_take_fails_the_order_and_removes_it_with_the_etag(): void {
		$result = $this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99' ) ), $this->item( '1007' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '63.00', json_encode( $result->error->context ) );
		$this->assertStringContainsString( '120.00', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ), 'The wrong order is gone.' );
		$deletes = $this->calls( 'DELETE', ')' );
		$this->assertCount( 1, $deletes );
		$this->assertMatchesRegularExpression( '/^W\/"[0-9a-f]{12}"$/', $deletes[0]['headers']['If-Match'] );
		$this->assertSame( 1, $this->server->csrfFetches, 'The create and the delete share one token.' );
		$next = $this->connector->findExisting( $this->connection(), $this->item( '1007' ) );
		$this->assertNull( $next, 'Nothing is left to be taken for the finished order.' );
	}

	public function test_with_total_mismatch_warn_the_order_stays_and_the_difference_is_a_warning(): void {
		$result = $this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99', 'total_mismatch' => 'warn' ) ), $this->item( '1008' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->all( 'A_SalesOrder' ) );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 12000, $result->ref->extra['net_minor'], 'The reference carries what S/4HANA holds.' );
	}

	public function test_an_order_that_cannot_be_deleted_stays_and_the_message_says_so(): void {
		$this->server->refuseDelete = true;
		$result                     = $this->connector->send( $this->connection( array( 'price_condition' => 'ZZ99' ) ), $this->item( '1009' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'could not be deleted', json_encode( $result->error->context, JSON_UNESCAPED_UNICODE ) );
		$this->assertCount( 1, $this->server->all( 'A_SalesOrder' ) );
	}

	public function test_an_order_saved_in_another_currency_is_refused_and_removed(): void {
		$this->server->forceCurrency = 'USD';
		$result                      = $this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ) );
	}

	public function test_when_s4hana_prices_the_order_no_condition_is_sent_and_the_result_says_the_amount_was_not_compared(): void {
		$result = $this->connector->send( $this->connection( array( 'price_source' => 'system' ) ), $this->item( '1011' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->calls( 'POST', '/A_SalesOrder' )[0]['body'], true );
		$this->assertArrayNotHasKey( 'to_PricingElement', $body['to_Item'][0] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 12000, $result->ref->extra['net_minor'], '3 x 40.00 from the system price list.' );
	}

	public function test_shipping_fees_and_discounts_are_refused_before_anything_is_sent_unless_the_person_chooses_to_leave_them_out(): void {
		$payload             = $this->payload();
		$payload['shipping'] = array( 'name' => 'Shipping', 'total' => 2500 );
		$payload['discount'] = array( 'name' => 'SAVE10', 'total' => 600 );
		$refused             = $this->connector->send( $this->connection(), $this->item( '1012' ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'extra_charges', json_encode( $refused->error->context ) );
		$this->assertSame( array(), $this->calls( 'POST', '/A_SalesOrder' ) );
		$left = $this->connector->send( $this->connection( array( 'extra_charges' => 'ignore' ) ), $this->item( '1012' ), $payload );
		$this->assertTrue( $left->isSuccess(), (string) $left->error?->message() );
		$this->assertNotEmpty( $left->ref->extra['warnings'] );
		$body = json_decode( (string) $this->calls( 'POST', '/A_SalesOrder' )[0]['body'], true );
		$this->assertCount( 1, $body['to_Item'], 'No item for shipping or discount.' );
	}

	public function test_a_reference_longer_than_35_characters_is_refused_before_anything_is_sent(): void {
		$long   = new Item( 5, 1, EntityType::Order, str_repeat( '9', 33 ), 'created', 'order:5', 'h', ItemState::Syncing );
		$result = $this->connector->send( $this->connection(), $long, $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '35', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->calls( 'POST', '/A_SalesOrder' ) );
		$edge = new Item( 6, 1, EntityType::Order, str_repeat( '9', 32 ), 'created', 'order:6', 'h', ItemState::Syncing );
		$this->assertTrue( $this->connector->send( $this->connection(), $edge, $this->payload() )->isSuccess(), 'WC- and 32 digits are exactly 35.' );
	}

	public function test_an_unknown_material_is_map_001_and_nothing_is_created(): void {
		$payload                          = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH-MATERIAL';
		$result                           = $this->connector->send( $this->connection(), $this->item( '1014' ), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertStringContainsString( 'NO-SUCH-MATERIAL', $result->error->message() );
		$this->assertSame( array(), $this->calls( 'POST', '/A_SalesOrder' ) );
		$this->assertSame( 0, $this->server->csrfFetches, 'No write was started.' );
	}

	public function test_an_order_with_two_lines_sends_both_with_their_own_price_and_adds_up(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'CS-MUG-01';
		$payload['order_line'][]             = array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21000 );
		$payload['total']                    = 27300;
		$result                              = $this->connector->send( $this->connection(), $this->item( '1015' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 27300, $result->ref->extra['net_minor'] );
	}

	public function test_missing_settings_are_val_002_that_name_the_setting_and_nothing_is_sent(): void {
		foreach ( array( 'sold_to_party', 'sales_organization', 'distribution_channel', 'division' ) as $index => $key ) {
			$result = $this->connector->send( $this->connection( array(), array( $key => '' ) ), $this->item( (string) ( 1100 + $index ) ), $this->payload() );
			$this->assertSame( ErrorCode::Val002, $result->error->code, $key );
			$this->assertStringContainsString( $key, json_encode( $result->error->context ), $key );
		}
		$this->assertSame( array(), $this->calls( 'POST', '/A_SalesOrder' ) );
	}

	public function test_a_sold_to_party_from_the_mapping_wins_over_the_connection_setting(): void {
		$this->server->customers[] = '17100002';
		$result                    = $this->connector->send( $this->connection(), $this->item( '1016' ), $this->payload( array( 'sold_to_party' => '17100002' ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '17100002', array_values( $this->server->all( 'A_SalesOrder' ) )[0]['SoldToParty'] );
	}

	public function test_a_refusal_from_s4hana_reaches_the_person_as_val_002_with_the_systems_own_words(): void {
		$result = $this->connector->send( $this->connection( array(), array( 'sold_to_party' => '99999999' ) ), $this->item( '1017' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Customer 99999999 does not exist.', json_encode( $result->error->context ) );
	}

	public function test_basic_sign_in_sends_the_communication_user_and_asks_for_no_token(): void {
		$connection = $this->connection( array(), array( 'auth_method' => 'basic', 'user' => $this->server->user ), array( 'client_secret' => '' ) );
		$result     = $this->connector->send( $connection, $this->item( '1018' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->server->tokenCalls );
		$this->assertStringStartsWith( 'Basic ', $this->calls( 'POST', '/A_SalesOrder' )[0]['headers']['Authorization'] );
	}

	public function test_nothing_is_sent_for_a_kind_of_record_the_connector_does_not_take(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1019', EntityType::Customer ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->calls( 'POST', '/A_SalesOrder' ) );
	}

	public function test_a_dropped_connection_while_posting_fails_the_item_and_the_retry_makes_exactly_one_order(): void {
		$this->server->failNext( FakeHttpClient::timeout() );
		$first = $this->connector->send( $this->connection(), $this->item( '1020' ), $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ) );
		$second = $this->connector->send( $this->connection(), $this->item( '1020' ), $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'A_SalesOrder' ) );
	}
}
