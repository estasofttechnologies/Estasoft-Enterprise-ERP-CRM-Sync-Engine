<?php
/**
 * Orders, customers and duplicate protection against the fake Service Layer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class SapBusinessOneOrderFlowTest extends SapBusinessOneTestCase {

	public function test_an_order_becomes_a_sales_order_with_the_reference_in_customer_ref_no(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1001' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$orders = array_values( $this->server->all( 'Orders' ) );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WC-1001', $order['NumAtCard'] );
		$this->assertSame( '2026-11-02', $order['DocDate'], 'The order date, not the day it was synced.' );
		$this->assertSame( 'AED', $order['DocCurrency'] );
		$this->assertSame( 'CS-MUG-01', $order['DocumentLines'][0]['ItemCode'] );
		$this->assertSame( 3, $order['DocumentLines'][0]['Quantity'] );
		$this->assertSame( 19.99, $order['DocumentLines'][0]['UnitPrice'] );
		$this->assertStringContainsString( 'Gift wrap please', $order['Comments'] );
		$this->assertStringEndsWith( 'Shop total 59.97 AED', $order['Comments'] );
		$this->assertStringContainsString( 'Marina Walk 4', $order['Address2'] );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
		$this->assertSame( (string) $order['DocEntry'], $result->ref->id );
		$this->assertSame( (string) $order['DocNum'], $result->ref->ref );
	}

	public function test_a_customer_is_found_by_email_in_any_case_and_a_new_one_gets_a_card_code_made_from_the_email(): void {
		$payload                     = $this->payload();
		$payload['partner']['email'] = 'EXISTING@Example.Test';
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1002' ), $payload )->isSuccess() );
		$this->assertSame( 'C20000', array_values( $this->server->all( 'Orders' ) )[0]['CardCode'] );
		$this->assertCount( 1, $this->server->data['BusinessPartners'] );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1003' ), $this->payload() )->isSuccess() );
		$code = 'WC' . substr( sha1( 'noor@example.test' ), 0, 13 );
		$this->assertArrayHasKey( $code, $this->server->data['BusinessPartners'] );
		$new = $this->server->data['BusinessPartners'][ $code ];
		$this->assertSame( 'cCustomer', $new['CardType'] );
		$this->assertSame( '##', $new['Currency'], 'A foreign order is accepted.' );
		$this->assertSame( 'bo_BillTo', $new['BPAddresses'][0]['AddressType'] );
		$this->assertSame( 'bo_ShipTo', $new['BPAddresses'][1]['AddressType'] );
		$this->assertLessThanOrEqual( 15, strlen( $code ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1004' ), $this->payload() )->isSuccess() );
		$this->assertCount( 2, $this->server->data['BusinessPartners'], 'The second order finds the customer by its card code.' );
	}

	public function test_a_card_code_that_already_exists_is_read_again_instead_of_failing(): void {
		$server = $this->server;
		$this->kit->http->route(
			'POST',
			'~/BusinessPartners$~',
			static function () use ( $server ): \Estasoft\SyncEngine\Contract\HttpResponse {
				$server->addCustomer( 'WC' . substr( sha1( 'noor@example.test' ), 0, 13 ), 'Noor Al-Mansouri', 'noor@example.test' );
				return FakeHttpClient::json( array( 'error' => array( 'code' => -10, 'message' => array( 'lang' => 'en-us', 'value' => 'This entry already exists in the following tables (ODBC -2035)' ) ) ), 400 );
			}
		);
		$result = $this->connector->send( $this->connection(), $this->item( '1005' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 2, $this->server->data['BusinessPartners'] );
	}

	public function test_match_only_refuses_an_unknown_customer_and_creates_nothing(): void {
		$result = $this->connector->send( $this->connection( array( 'customer_policy' => 'match_only' ) ), $this->item( '1006' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'Orders' ) );
		$this->assertCount( 1, $this->server->data['BusinessPartners'] );
	}

	public function test_a_series_number_replaces_the_generated_card_code(): void {
		$this->connector->send( $this->connection( array( 'bp_series' => '71' ) ), $this->item( '1007' ), $this->payload() );
		$posts = array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'POST' === $request['method'] && str_ends_with( $request['url'], '/BusinessPartners' ) ) );
		$this->assertCount( 1, $posts );
		$body = json_decode( (string) $posts[0]['body'], true );
		$this->assertSame( 71, $body['Series'] );
		$this->assertArrayNotHasKey( 'CardCode', $body );
	}

	public function test_sending_again_links_to_the_first_order_and_makes_no_second_one(): void {
		$first  = $this->connector->send( $this->connection(), $this->item( '1008' ), $this->payload() );
		$second = $this->connector->send( $this->connection(), $this->item( '1008' ), $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$posts = array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'POST' === $request['method'] && str_ends_with( $request['url'], '/Orders' ) );
		$this->assertCount( 1, $posts );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $this->item( '1008' ) ) );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $this->item( '1009' ) ) );
	}

	public function test_a_cancelled_order_does_not_count_so_a_retry_makes_a_new_one(): void {
		$this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() );
		$key = (string) array_key_first( $this->server->all( 'Orders' ) );
		$this->server->data['Orders'][ $key ]['Cancelled'] = 'tYES';
		$this->assertNull( $this->connector->findExisting( $this->connection(), $this->item( '1010' ) ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() )->isSuccess() );
		$this->assertCount( 2, $this->server->all( 'Orders' ) );
	}

	public function test_a_wrong_total_fails_by_default_stays_in_sap_and_is_reported_again_by_the_next_pass(): void {
		$payload                                = $this->payload();
		$payload['order_line'][0]['tax']        = 'S1';
		$result                                 = $this->connector->send( $this->connection(), $this->item( '1011' ), $payload );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '62.97', json_encode( $result->error->context ) );
		$this->assertStringContainsString( '59.97', json_encode( $result->error->context ) );
		$this->assertCount( 1, $this->server->all( 'Orders' ), 'Nothing is cancelled behind the merchant\'s back.' );
		$this->assertInstanceOf( ConnectorError::class, $this->connector->findExisting( $this->connection(), $this->item( '1011' ) ) );
		$again = $this->connector->send( $this->connection(), $this->item( '1011' ), $payload );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'Orders' ) );
	}

	public function test_a_difference_is_accepted_with_a_warning_when_the_merchant_chose_warn(): void {
		$payload                         = $this->payload();
		$payload['order_line'][0]['tax'] = 'S1';
		$result                          = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item( '1012' ), $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_the_tax_code_goes_to_vatgroup_or_taxcode_by_option(): void {
		$payload                         = $this->payload();
		$payload['order_line'][0]['tax'] = 'S1';
		$payload['total']                = 6297;
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1013' ), $payload )->isSuccess() );
		$this->assertSame( 'S1', array_values( $this->server->all( 'Orders' ) )[0]['DocumentLines'][0]['VatGroup'] );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'tax_field' => 'TaxCode' ) ), $this->item( '1014' ), $payload )->isSuccess() );
		$this->assertSame( 'S1', array_values( $this->server->all( 'Orders' ) )[1]['DocumentLines'][0]['TaxCode'] );
	}

	public function test_prices_with_tax_are_sent_as_price_after_vat(): void {
		$payload                       = $this->payload();
		$payload['prices_include_tax'] = true;
		$this->connector->send( $this->connection(), $this->item( '1015' ), $payload );
		$line = array_values( $this->server->all( 'Orders' ) )[0]['DocumentLines'][0];
		$this->assertArrayHasKey( 'PriceAfterVAT', $line );
		$this->assertArrayNotHasKey( 'UnitPrice', $line );
	}

	public function test_a_foreign_currency_order_is_checked_against_the_foreign_total(): void {
		$payload             = $this->payload( array( 'currency' => 'USD', 'total' => 5997 ) );
		$result              = $this->connector->send( $this->connection(), $this->item( '1016' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$order = array_values( $this->server->all( 'Orders' ) )[0];
		$this->assertGreaterThan( 59.97, $order['DocTotal'], 'The local total differs; the foreign one is used.' );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
		$this->assertSame( 'USD', $result->ref->extra['currency'] );
		$found = $this->connector->findExisting( $this->connection(), $this->item( '1016' ) );
		$this->assertSame( 5997, $found->extra['total_minor'] );
	}

	public function test_a_document_that_comes_back_in_another_currency_fails_loudly(): void {
		$this->server->forceCurrency = 'USD';
		$result                      = $this->connector->send( $this->connection(), $this->item( '1017' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'USD', json_encode( $result->error->context ) );
	}

	public function test_an_unknown_item_stops_the_order_before_a_customer_is_created(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH-ITEM';
		$result                              = $this->connector->send( $this->connection(), $this->item( '1018' ), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 1, $this->server->data['BusinessPartners'] );
		$this->assertSame( array(), $this->server->all( 'Orders' ) );
	}

	public function test_a_missing_email_is_map_003_and_a_missing_currency_is_val_002(): void {
		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection(), $this->item( '1019' ), $payload )->error->code );
		$payload = $this->payload();
		unset( $payload['currency'] );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection(), $this->item( '1020' ), $payload )->error->code );
	}

	public function test_shipping_fees_and_discounts_are_lines_on_the_items_the_merchant_named(): void {
		$this->server->addItem( 'SHIP', 'Shipping' );
		$this->server->addItem( 'FEE', 'Fee' );
		$this->server->addItem( 'DISC', 'Discount' );
		$payload             = $this->payload();
		$payload['shipping'] = array( 'name' => 'Shipping', 'total' => 2500 );
		$payload['fee']      = array( array( 'name' => 'Gift wrap', 'total' => 500 ) );
		$payload['discount'] = array( 'name' => 'SAVE10', 'total' => 600 );
		$payload['total']    = 5997 + 2500 + 500 - 600;
		$refused             = $this->connector->send( $this->connection(), $this->item( '1021' ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'shipping_item', json_encode( $refused->error->context ) );
		$options = array( 'shipping_item' => 'SHIP', 'fee_item' => 'FEE', 'discount_item' => 'DISC' );
		$ok      = $this->connector->send( $this->connection( $options ), $this->item( '1021' ), $payload );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$lines = array_values( $this->server->all( 'Orders' ) )[0]['DocumentLines'];
		$this->assertSame( array( 'CS-MUG-01', 'SHIP', 'FEE', 'DISC' ), array_column( $lines, 'ItemCode' ) );
		$this->assertSame( -6.0, $lines[3]['UnitPrice'] );
	}

	public function test_the_warehouse_due_date_and_user_field_options_are_used(): void {
		$result = $this->connector->send( $this->connection( array( 'warehouse' => 'WH1', 'due_days' => '14', 'reference_field' => 'U_WCRef' ) ), $this->item( '1022' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$order = array_values( $this->server->all( 'Orders' ) )[0];
		$this->assertSame( 'WH1', $order['DocumentLines'][0]['WarehouseCode'] );
		$this->assertSame( '2026-11-16', $order['DocDueDate'] );
		$this->assertSame( 'WC-1022', $order['U_WCRef'] );
		$this->assertSame( 'WC-1022', $order['NumAtCard'], 'Customer Ref. No. is always written.' );
		$this->server->data['Orders'][ (string) $order['DocEntry'] ]['U_WCRef'] = 'WC-1022';
		$this->assertTrue( $this->connector->send( $this->connection( array( 'reference_field' => 'U_WCRef' ) ), $this->item( '1022' ), $this->payload() )->alreadyExisted );
		$filters = array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( rawurldecode( $request['url'] ), "U_WCRef eq 'WC-1022'" ) );
		$this->assertNotEmpty( $filters, 'Orders are searched by the user field.' );
	}

	public function test_a_user_field_name_with_odd_characters_falls_back_to_customer_ref_no(): void {
		$this->connector->send( $this->connection( array( 'reference_field' => "U_X' or 1 eq 1" ) ), $this->item( '1023' ), $this->payload() );
		$filters = array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( rawurldecode( $request['url'] ), '1 eq 1' ) );
		$this->assertSame( array(), $filters );
	}

	public function test_quotes_in_a_reference_or_email_cannot_break_out_of_the_filter(): void {
		$payload                     = $this->payload();
		$payload['partner']['email'] = "o'brien@example.test";
		$result                      = $this->connector->send( $this->connection(), $this->item( '1024' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$urls = implode( "\n", array_map( static fn( array $request ): string => rawurldecode( $request['url'] ), $this->kit->http->requests ) );
		$this->assertStringContainsString( "EmailAddress eq 'o''brien@example.test'", $urls );
	}

	public function test_a_long_note_is_cut_with_an_ellipsis_a_warning_and_the_marker_stays(): void {
		$payload         = $this->payload();
		$payload['note'] = str_repeat( 'ن', 600 );
		$result          = $this->connector->send( $this->connection(), $this->item( '1025' ), $payload );
		$this->assertTrue( $result->isSuccess() );
		$comments = array_values( $this->server->all( 'Orders' ) )[0]['Comments'];
		$this->assertLessThanOrEqual( 254, mb_strlen( $comments, 'UTF-8' ) );
		$this->assertStringEndsWith( 'Shop total 59.97 AED', $comments );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_customer_item_is_matched_or_created(): void {
		$payload = array( 'partner' => array( 'name' => 'Layla Haddad', 'email' => 'layla@example.test' ) );
		$item    = $this->item( '77', EntityType::Customer );
		$first   = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $first->isSuccess() && ! $first->alreadyExisted );
		$second = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
	}
}
