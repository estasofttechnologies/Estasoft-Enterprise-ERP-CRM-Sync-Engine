<?php
/**
 * Receivables invoices against the fake pod: create, duplicate protection, the checks and the settings.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class OracleFusionOrderFlowTest extends OracleFusionTestCase {

	public function test_an_order_is_one_invoice_with_the_reference_the_shop_total_and_descriptive_lines(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1001' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$posts = $this->calls( 'POST', '/receivablesInvoices' );
		$this->assertCount( 1, $posts );
		$this->assertSame( 'application/vnd.oracle.adf.resourceitem+json', $posts[0]['headers']['Content-Type'] );
		$this->assertSame( '2', $posts[0]['headers']['REST-Framework-Version'] );
		$body = json_decode( (string) $posts[0]['body'], true );
		$this->assertSame( 'Shop Business Unit', $body['BusinessUnit'] );
		$this->assertSame( 'Web Orders', $body['TransactionSource'] );
		$this->assertSame( 'Invoice', $body['TransactionType'] );
		$this->assertSame( '1001', $body['BillToCustomerNumber'] );
		$this->assertSame( 'AED', $body['InvoiceCurrencyCode'] );
		$this->assertSame( '2026-11-02', $body['TransactionDate'] );
		$this->assertSame( 'WC-1001', $body['PurchaseOrder'] );
		$this->assertSame( "Gift wrap please\nShop total 59.97 AED", $body['Comments'], 'The shop total is the last line of the comments.' );
		$this->assertArrayNotHasKey( 'FreightAmount', $body );
		$this->assertArrayNotHasKey( 'InvoiceStatus', $body, 'Complete is the default.' );
		$line = $body['receivablesInvoiceLines'][0];
		$this->assertSame( 1, $line['LineNumber'] );
		$this->assertSame( '[CS-MUG-01] Ceramic mug, sand', $line['Description'] );
		$this->assertSame( 3, $line['Quantity'] );
		$this->assertEquals( 19.99, $line['UnitSellingPrice'] );
		$invoice = array_values( $this->server->all( 'receivablesInvoices' ) )[0];
		$this->assertSame( (string) $invoice['CustomerTransactionId'], $result->ref->id );
		$this->assertSame( $invoice['TransactionNumber'], $result->ref->ref );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
		$this->assertArrayNotHasKey( 'warnings', $result->ref->extra );
	}

	public function test_the_search_filters_on_the_reference_and_the_business_unit_with_quotes_doubled(): void {
		$this->connector->findExisting( $this->connection( array(), array( 'business_unit' => "O'Brien Unit" ) ), $this->item( '1002' ) );
		$search = $this->calls( 'GET', '/receivablesInvoices' )[0];
		parse_str( (string) wp_parse_url( $search['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( "PurchaseOrder = 'WC-1002' and BusinessUnit = 'O''Brien Unit'", $query['q'] );
		$this->assertSame( '2', $search['headers']['REST-Framework-Version'], 'The q syntax needs framework version 2.' );
	}

	public function test_the_same_order_is_found_by_its_reference_and_never_made_twice(): void {
		$item = $this->item( '1003' );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $item ) );
		$first = $this->connector->send( $this->connection(), $item, $this->payload() );
		$found = $this->connector->findExisting( $this->connection(), $item );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 5997, $found->extra['total_minor'] );
		$again = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/receivablesInvoices' ) );
	}

	public function test_an_invoice_in_another_business_unit_is_not_taken_for_this_one(): void {
		$this->connector->send( $this->connection(), $this->item( '1004' ), $this->payload() );
		$other = $this->connector->findExisting( $this->connection( array(), array( 'business_unit' => 'Another Unit' ) ), $this->item( '1004' ) );
		$this->assertNull( $other );
	}

	public function test_a_dropped_connection_while_posting_fails_the_item_and_the_retry_makes_exactly_one_invoice(): void {
		$this->server->failNext( FakeHttpClient::timeout() );
		$first = $this->connector->send( $this->connection(), $this->item( '1005' ), $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( array(), $this->server->all( 'receivablesInvoices' ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '1005' ), $this->payload() )->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ) );
	}

	public function test_a_tax_the_shop_did_not_charge_stops_the_order_and_the_next_pass_does_not_link_the_wrong_invoice(): void {
		$this->server->taxRate = 5.0;
		$item                  = $this->item( '1006' );
		$result                = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( '62.97', $context );
		$this->assertStringContainsString( '59.97', $context );
		$this->assertStringContainsString( 'stays in Oracle Fusion', $context );
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ), 'A posted invoice is not deleted by the connector.' );
		$found = $this->connector->findExisting( $this->connection(), $item );
		$this->assertInstanceOf( ConnectorError::class, $found, 'The engine would link a reference; it must get the error instead.' );
		$this->assertSame( ErrorCode::Val002, $found->code );
		$again = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ), 'No second invoice.' );
	}

	public function test_with_total_mismatch_warn_the_invoice_is_linked_and_the_difference_is_a_warning(): void {
		$this->server->taxRate = 5.0;
		$item                  = $this->item( '1007' );
		$result                = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 6297, $result->ref->extra['total_minor'], 'The reference carries what Fusion holds.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item ) );
	}

	public function test_a_pod_that_shows_no_total_gives_a_warning_and_no_total_in_the_reference(): void {
		$this->server->showsTotal = false;
		$result                   = $this->connector->send( $this->connection(), $this->item( '1008' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertArrayNotHasKey( 'total_minor', $result->ref->extra );
	}

	public function test_an_invoice_left_incomplete_is_an_error_for_a_person_and_stays_one_on_the_next_pass(): void {
		$this->server->status = 'Incomplete';
		$item                 = $this->item( '1009' );
		$result               = $this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Incomplete', json_encode( $result->error->context ) );
		$this->assertInstanceOf( ConnectorError::class, $this->connector->findExisting( $this->connection(), $item ) );
		$this->server->status = 'Complete';
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ) );
	}

	public function test_an_invoice_saved_in_another_currency_is_refused(): void {
		$this->server->forceCurrency = 'USD';
		$result                      = $this->connector->send( $this->connection(), $this->item( '1010' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', json_encode( $result->error->context ) );
	}

	public function test_fees_a_discount_and_shipping_become_lines_and_the_freight_amount(): void {
		$payload             = $this->payload( array( 'total' => 5997 + 2500 + 500 - 600 ) );
		$payload['shipping'] = array( 'name' => 'Shipping', 'total' => 2500 );
		$payload['fee']      = array( array( 'name' => 'Gift wrap', 'total' => 500 ) );
		$payload['discount'] = array( 'name' => 'SAVE10', 'total' => 600 );
		$result              = $this->connector->send( $this->connection(), $this->item( '1011' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body  = json_decode( (string) $this->calls( 'POST', '/receivablesInvoices' )[0]['body'], true );
		$lines = $body['receivablesInvoiceLines'];
		$this->assertCount( 3, $lines );
		$this->assertSame( array( 1, 2, 3 ), array_column( $lines, 'LineNumber' ) );
		$this->assertSame( 'Gift wrap', $lines[1]['Description'] );
		$this->assertEquals( 5.00, $lines[1]['UnitSellingPrice'] );
		$this->assertEquals( -6.00, $lines[2]['UnitSellingPrice'], 'A discount is a negative line.' );
		$this->assertEquals( 25.00, $body['FreightAmount'] );
		$this->assertSame( 8397, $result->ref->extra['total_minor'] );
	}

	public function test_a_long_note_is_cut_with_a_warning_and_the_shop_total_still_ends_the_comments(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1012' ), $this->payload( array( 'note' => str_repeat( 'Ab ', 700 ) ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$comments = array_values( $this->server->all( 'receivablesInvoices' ) )[0]['Comments'];
		$this->assertLessThanOrEqual( 1760, mb_strlen( $comments ) );
		$this->assertStringEndsWith( "\nShop total 59.97 AED", $comments );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_long_line_description_is_cut_with_a_warning(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['name']    = str_repeat( 'x', 400 );
		$result                              = $this->connector->send( $this->connection(), $this->item( '1013' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesInvoices' )[0]['body'], true );
		$this->assertLessThanOrEqual( 240, mb_strlen( $body['receivablesInvoiceLines'][0]['Description'] ) );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_options_for_payment_terms_memo_line_and_the_cross_reference_field_reach_the_invoice(): void {
		$options = array( 'memo_line' => 'Web sales', 'reference_field' => 'cross_reference' );
		$result  = $this->connector->send( $this->connection( $options, array( 'payment_terms' => 'Net 30' ) ), $this->item( '1014' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesInvoices' )[0]['body'], true );
		$this->assertSame( 'Net 30', $body['PaymentTerms'] );
		$this->assertSame( 'Web sales', $body['receivablesInvoiceLines'][0]['MemoLine'] );
		$this->assertSame( 'WC-1014', $body['CrossReference'] );
		$this->assertArrayNotHasKey( 'PurchaseOrder', $body );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( $options, array( 'payment_terms' => 'Net 30' ) ), $this->item( '1014' ) ) );
	}

	public function test_a_reference_longer_than_the_field_is_refused_before_anything_is_sent(): void {
		$long = new Item( 5, 1, EntityType::Order, str_repeat( '9', 28 ), 'created', 'order:5', 'h', ItemState::Syncing );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection( array( 'reference_field' => 'cross_reference' ) ), $long, $this->payload() )->error->code, 'WC- and 28 digits are 31 characters; CrossReference takes 30.' );
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesInvoices' ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $long, $this->payload() )->isSuccess(), 'PurchaseOrder takes 50.' );
	}

	public function test_an_unknown_item_is_map_001_and_nothing_is_created(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH-ITEM';
		$result                              = $this->connector->send( $this->connection(), $this->item( '1015' ), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertStringContainsString( 'NO-SUCH-ITEM', $result->error->message() );
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesInvoices' ) );
	}

	public function test_with_free_text_the_item_is_not_looked_up_and_the_sku_goes_into_the_description(): void {
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH-ITEM';
		$result                              = $this->connector->send( $this->connection( array( 'unknown_sku' => 'free_text' ) ), $this->item( '1016' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->calls( 'GET', '/itemsV2' ) );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesInvoices' )[0]['body'], true );
		$this->assertSame( '[NO-SUCH-ITEM] Ceramic mug, sand', $body['receivablesInvoiceLines'][0]['Description'] );
	}

	public function test_missing_settings_are_val_002_that_name_the_setting_and_nothing_is_sent(): void {
		foreach ( array( 'business_unit', 'transaction_source', 'transaction_type', 'bill_to_account' ) as $index => $key ) {
			$result = $this->connector->send( $this->connection( array(), array( $key => '' ) ), $this->item( (string) ( 1100 + $index ) ), $this->payload() );
			$this->assertSame( ErrorCode::Val002, $result->error->code, $key );
			$this->assertStringContainsString( $key, json_encode( $result->error->context ), $key );
		}
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesInvoices' ) );
		$this->assertSame( ErrorCode::Val002, $this->connector->findExisting( $this->connection( array(), array( 'business_unit' => '' ) ), $this->item( '1100' ) )->code );
	}

	public function test_a_customer_account_from_the_mapping_wins_over_the_connection_setting(): void {
		$this->server->accounts[] = '2002';
		$result                   = $this->connector->send( $this->connection(), $this->item( '1016' ), $this->payload( array( 'bill_to_customer_number' => '2002' ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '2002', array_values( $this->server->all( 'receivablesInvoices' ) )[0]['BillToCustomerNumber'] );
	}

	public function test_a_refusal_from_fusion_reaches_the_person_as_val_002_with_the_field_and_the_systems_words(): void {
		$result = $this->connector->send( $this->connection( array(), array( 'bill_to_account' => '99999' ) ), $this->item( '1017' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( 'The customer account 99999 does not exist.', $context );
		$this->assertStringContainsString( '/BillToCustomerNumber', $context );
	}

	public function test_prices_that_include_tax_are_refused_before_any_request(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1018' ), $this->payload( array( 'prices_include_tax' => true ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'prices_include_tax', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->kit->http->requests );
	}

	public function test_a_foreign_currency_the_ledger_cannot_book_is_val_002(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1019' ), $this->payload( array( 'currency' => 'JPY' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'JPY', json_encode( $result->error->context ) );
	}

	public function test_nothing_is_sent_for_a_kind_of_record_the_connector_does_not_take(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '1020', EntityType::Customer ), array() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->calls( 'POST', '/receivablesInvoices' ) );
	}

	public function test_a_role_that_is_missing_is_auth_004_and_a_throttled_write_is_rate_001_with_the_wait(): void {
		$this->server->blocked[] = 'receivablesInvoices';
		$this->assertSame( ErrorCode::Auth004, $this->connector->send( $this->connection(), $this->item( '1021' ), $this->payload() )->error->code );
		$this->server->blocked = array();
		$this->server->failNext( FakeHttpClient::tooManyRequests( 17 ) );
		$throttled = $this->connector->send( $this->connection(), $this->item( '1022' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $throttled->error->code );
		$this->assertSame( 17, $throttled->error->retryAfter );
		$this->assertSame( array(), $this->server->all( 'receivablesInvoices' ) );
	}

	public function test_the_oauth_token_is_requested_once_from_the_identity_domain_and_sent_as_a_bearer_header(): void {
		$this->connector->send( $this->connection(), $this->item( '1023' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '1024' ), $this->payload() );
		$this->assertCount( 1, $this->server->tokenCalls );
		$this->assertSame( 'client_credentials', $this->server->tokenCalls[0]['grant_type'] );
		$this->assertSame( $this->server->scope, $this->server->tokenCalls[0]['scope'] );
		$token = $this->kit->http->matching( '#oauth2/v1/token#' )[0];
		$this->assertStringStartsWith( 'https://idcs-abc123def.identity.oraclecloud.com/', $token['url'] );
		$this->assertSame( 'Bearer ' . $this->server->token, $this->calls( 'POST', '/receivablesInvoices' )[0]['headers']['Authorization'] );
	}

	public function test_basic_sign_in_sends_the_fusion_user_and_asks_for_no_token(): void {
		$connection = $this->connection( array(), array( 'auth_method' => 'basic', 'user' => $this->server->user ), array( 'client_secret' => '' ) );
		$result     = $this->connector->send( $connection, $this->item( '1025' ), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->server->tokenCalls );
		$this->assertStringStartsWith( 'Basic ', $this->calls( 'POST', '/receivablesInvoices' )[0]['headers']['Authorization'] );
	}
}
