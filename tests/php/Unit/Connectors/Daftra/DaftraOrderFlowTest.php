<?php
/**
 * Sending an order to Daftra: the steps, the checks and what a retry does.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Daftra;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeDaftraServer;

final class DaftraOrderFlowTest extends DaftraTestCase {

	public function test_a_new_order_becomes_a_draft_that_is_checked_and_then_issued(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 'INV-000001', $result->ref->ref );
		$this->assertSame( 'issued', $result->ref->extra['state'] );
		$this->assertSame( 7998, $result->ref->extra['total_minor'] );

		$posts = $this->requests( 'POST', '#/api2/invoices\.json$#' );
		$this->assertCount( 1, $posts );
		$body = $this->bodyOf( $posts[0] );
		$this->assertSame( 1, $body['Invoice']['draft'] );
		$this->assertSame( 'WC-10490', $body['Invoice']['po_number'] );
		$this->assertSame( '2026-11-02', $body['Invoice']['date'] );
		$this->assertSame( 'SAR', $body['Invoice']['currency_code'] );
		$this->assertSame( 'Gift wrap please', $body['Invoice']['notes'] );
		$this->assertSame( 11, $body['InvoiceItem'][0]['product_id'] );
		$this->assertSame( 39.99, $body['InvoiceItem'][0]['unit_price'] );
		$this->assertSame( 2, $body['InvoiceItem'][0]['quantity'] );
		$this->assertArrayNotHasKey( 'tax1', $body['InvoiceItem'][0] );
		$this->assertCount( 1, $this->requests( 'GET', '#/api2/invoices/update_draft/\d+/0\.json$#' ) );
		$this->assertSame( 0, $this->server->all( 'invoices' )[0]['draft'] );
	}

	public function test_every_request_carries_the_key_in_the_documented_header_and_never_an_authorization_header(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( FakeDaftraServer::KEY, $request['headers']['apikey'] );
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
			$this->assertStringStartsWith( 'https://acme.daftara.com/api2/', $request['url'] );
			$this->assertStringContainsString( '.json', $request['url'] );
		}
	}

	public function test_the_other_documented_domain_can_be_chosen(): void {
		$this->server->host = 'acme.daftra.com';
		$result             = $this->connector()->send( $this->connection( array( 'domain' => 'daftra.com' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertStringStartsWith( 'https://acme.daftra.com/api2/', $this->kit->http->requests[0]['url'] );
	}

	public function test_an_account_name_that_is_not_a_plain_name_is_refused_before_any_request(): void {
		foreach ( array( 'evil.com/x#', 'a b', '', '-bad', 'acme.evil.com' ) as $name ) {
			$result = $this->connector()->send( $this->connection( array( 'subdomain' => $name ) ), $this->item(), $this->payload() );
			$this->assertFalse( $result->isSuccess(), $name );
			$this->assertSame( ErrorCode::Val002, $result->error->code, $name );
			$this->assertSame( 'subdomain', $result->error->context['field'], $name );
		}
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_a_second_send_links_to_the_same_invoice_and_writes_nothing(): void {
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$writes    = count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'GET', '#update_draft#' ) );
		$second    = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'GET', '#update_draft#' ) ) );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_find_existing_returns_the_finished_invoice_and_nothing_before_it(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ) );
		$sent  = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$found = $connector->findExisting( $this->connection(), $this->item() );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
		$this->assertSame( 7998, $found->extra['total_minor'] );
	}

	public function test_the_client_is_created_once_and_found_again_by_its_email_whatever_the_case(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item( EntityType::Order, '1' ), $this->payload( array( 'number' => '1' ) ) );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertCount( 1, $this->server->all( 'clients' ) );
		$client = $this->server->all( 'clients' )[0];
		$this->assertSame( 'نور المنصوري', $client['business_name'] );
		$this->assertSame( 'noor@example.com', $client['email'] );
		$this->assertSame( '300000000000003', $client['bn1'] );
		$this->assertSame( 'شارع الشيخ زايد', $client['address1'] );
		$this->assertSame( 'SA', $client['country_code'] );
		$this->assertSame( 'SAR', $client['default_currency_code'] );
	}

	public function test_an_address_that_contains_the_email_is_not_taken_for_the_client(): void {
		$this->server->tables['clients'][50] = array( 'id' => 50, 'business_name' => 'Someone else', 'email' => 'anoor@example.com.sa' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertCount( 2, $this->server->all( 'clients' ) );
		$this->assertNotSame( 50, $this->server->all( 'invoices' )[0]['client_id'] );
	}

	public function test_a_client_is_looked_for_with_the_email_filter_first_and_one_request_is_enough_when_it_is_found(): void {
		$this->server->tables['clients'][60] = array( 'id' => 60, 'business_name' => 'Noor', 'email' => 'noor@example.com' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$lists = $this->requests( 'GET', '#/api2/clients\.json\?#' );
		$this->assertCount( 1, $lists );
		$this->assertStringContainsString( 'filter%5Bemail%5D=noor%40example.com', $lists[0]['url'] );
		$this->assertStringNotContainsString( 'keywords', $lists[0]['url'] );
		$this->assertSame( 60, $this->server->all( 'invoices' )[0]['client_id'] );
	}

	public function test_a_client_stored_with_capitals_is_found_by_the_keyword_search_after_the_filter_misses(): void {
		$this->server->tables['clients'][60] = array( 'id' => 60, 'business_name' => 'Noor', 'email' => 'Noor@Example.com' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertCount( 2, $this->requests( 'GET', '#/api2/clients\.json\?#' ) );
		$this->assertCount( 1, $this->server->all( 'clients' ), 'No second client.' );
		$this->assertSame( 60, $this->server->all( 'invoices' )[0]['client_id'] );
	}

	public function test_a_server_that_ignores_the_filter_still_gives_the_right_client_by_the_local_match(): void {
		$this->server->ignoreFilters = true;
		$this->server->tables['clients'][60] = array( 'id' => 60, 'business_name' => 'Other', 'email' => 'other@example.com' );
		$this->server->tables['clients'][61] = array( 'id' => 61, 'business_name' => 'Noor', 'email' => 'noor@example.com' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( 61, $this->server->all( 'invoices' )[0]['client_id'] );
		$this->assertCount( 2, $this->server->all( 'clients' ) );
	}

	public function test_a_missing_email_is_map_003_and_nothing_is_sent(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';
		$result                       = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'NO-SUCH-SKU';
		$result                      = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_sku_is_matched_exactly_not_by_the_containment_the_filter_allows(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'cs-mug-01';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
		$this->assertSame( 11, $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'][0]['product_id'], 'CS-MUG-01-GIFT also contains the code.' );
	}

	public function test_the_free_text_option_sends_the_unknown_sku_as_a_text_line(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'NO-SUCH-SKU';
		$result                      = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'free_text' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'][0];
		$this->assertArrayNotHasKey( 'product_id', $line );
		$this->assertSame( 'Ceramic mug', $line['item'] );
	}

	public function test_taxes_come_from_the_mapped_code_and_the_total_is_checked_with_daftra_s_figure(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
		$this->assertSame( 1, $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'][0]['tax1'] );
	}

	public function test_a_tax_included_in_prices_is_priced_with_the_tax_in_it(): void {
		$payload                         = $this->payload( array( 'total' => 8398 ) );
		$payload['lines'][0]['tax_code'] = '2';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 8398, $result->ref->extra['total_minor'], '79.98 plus 5 percent, sent as 83.98 with the tax included.' );
		$this->assertSame( 41.99, $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'][0]['unit_price'] );
	}

	public function test_prices_that_include_tax_are_converted_to_net_when_daftra_adds_the_tax(): void {
		$payload                         = $this->payload( array( 'total' => 9198, 'prices_include_tax' => true ) );
		$payload['lines'][0]['total']    = 9198;
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 39.99, $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'][0]['unit_price'] );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
	}

	public function test_an_unknown_tax_is_map_002_and_nothing_is_created(): void {
		$payload                         = $this->payload();
		$payload['lines'][0]['tax_code'] = '99';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_total_that_differs_deletes_the_draft_so_it_is_never_taken_for_finished(): void {
		$connector = $this->connector();
		$payload   = $this->payload( array( 'total' => 9000 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result = $connector->send( $this->connection(), $this->item(), $payload );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '91.98', $result->error->context['reason'] );
		$this->assertStringContainsString( '90.00', $result->error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		$this->assertCount( 0, $this->server->all( 'invoices' ), 'The draft was removed; nothing was issued.' );
		$this->assertCount( 0, $this->requests( 'GET', '#update_draft#' ) );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ) );

		$payload['total'] = 9198;
		$again            = $connector->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_a_draft_that_cannot_be_deleted_says_so_in_the_error(): void {
		$this->server->refuseDelete = true;
		$result                     = $this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'total' => 100 ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'Delete it in Daftra', $result->error->context['reason'] );
	}

	public function test_the_warn_option_issues_a_differing_total_and_says_so(): void {
		$result = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item(), $this->payload( array( 'total' => 7000 ) ) );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 0, $this->server->all( 'invoices' )[0]['draft'] );
	}

	public function test_an_uneven_line_is_split_so_the_sum_is_exact(): void {
		$payload                         = $this->payload( array( 'total' => 1000 ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1000;
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'];
		$this->assertCount( 2, $items );
		$this->assertSame( array( 2, 3.33 ), array( $items[0]['quantity'], $items[0]['unit_price'] ) );
		$this->assertSame( array( 1, 3.34 ), array( $items[1]['quantity'], $items[1]['unit_price'] ) );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_shipping_and_fees_become_text_lines_and_the_fee_tax_is_found_from_its_amount(): void {
		$payload             = $this->payload( array( 'total' => 7998 + 1200 + 2500 + 375 + 345 ) );
		$payload['shipping'] = array( 'name' => 'Courier', 'total' => 2500, 'tax_code' => '1' );
		$payload['fees']     = array( array( 'name' => 'Gift wrap', 'total' => 300, 'tax_amount' => 45 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result              = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/api2/invoices\.json$#' )[0] )['InvoiceItem'];
		$this->assertCount( 3, $items );
		$this->assertSame( 'Courier', $items[1]['item'] );
		$this->assertArrayNotHasKey( 'product_id', $items[1] );
		$this->assertSame( 1, $items[2]['tax1'] );
	}

	public function test_a_lost_answer_does_not_create_a_second_invoice_on_the_retry(): void {
		$this->server->tables['clients'][60] = array( 'id' => 60, 'business_name' => 'Noor', 'email' => 'noor@example.com' );
		$connector                           = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertSame( 0, $this->server->all( 'invoices' )[0]['draft'] );
	}

	public function test_a_failure_while_issuing_is_picked_up_where_it_stopped(): void {
		$connector = $this->connector();
		$this->server->failWriteAfter( 2, 'server_error' );
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $first->error->code );
		$this->assertSame( 1, $this->server->all( 'invoices' )[0]['draft'] );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ), 'A draft that waits to be issued is not finished.' );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertCount( 1, $this->server->all( 'clients' ) );
	}

	public function test_the_draft_option_leaves_a_checked_draft_that_counts_as_finished(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'invoice_state' => 'draft' ) );
		$result     = $connector->send( $connection, $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'draft', $result->ref->extra['state'] );
		$this->assertSame( 'WC-10490', $result->ref->ref );
		$this->assertSame( 1, $this->server->all( 'invoices' )[0]['draft'] );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
		$this->assertCount( 0, $this->requests( 'GET', '#update_draft#' ) );
	}

	public function test_a_paid_order_records_one_payment_when_payments_are_on(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_method' => 'bank' ) );
		$payload    = $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) );

		$this->assertNull( $connector->findExisting( $connection, $this->item() ) );
		$result = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->bodyOf( $this->requests( 'POST', '#/api2/invoice_payments\.json$#' )[0] )['InvoicePayment'];
		$this->assertSame( 'bank', $body['payment_method'] );
		$this->assertSame( 'WC-10490-PAY', $body['transaction_id'] );
		$this->assertSame( 79.98, $body['amount'] );
		$this->assertSame( (int) $result->ref->id, $body['invoice_id'] );

		$again = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->requests( 'POST', '#/api2/invoice_payments\.json$#' ), 'Never a second payment.' );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
	}

	public function test_payments_need_a_method_and_an_unpaid_order_records_none(): void {
		$connection = $this->connection( array( 'record_payment' => 'yes' ) );
		$this->assertTrue( $this->connector()->send( $connection, $this->item(), $this->payload() )->isSuccess() );
		$this->assertCount( 0, $this->requests( 'POST', '#invoice_payments#' ) );
		$paid = $this->connector()->send( $connection, $this->item( EntityType::Order, '2' ), $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) ) );
		$this->assertFalse( $paid->isSuccess() );
		$this->assertSame( 'payment_method', $paid->error->context['field'] );
	}

	public function test_lists_are_read_page_by_page(): void {
		$this->server->pageSize = 2;
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->server->tables['clients'][ 70 + $i ] = array( 'id' => 70 + $i, 'business_name' => 'Other ' . $i, 'email' => "noor{$i}@example.com.sa" );
		}
		$this->server->tables['clients'][80] = array( 'id' => 80, 'business_name' => 'Noor', 'email' => 'noor@example.com' );
		$result                              = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 80, $this->server->all( 'invoices' )[0]['client_id'] );
		$this->assertCount( 6, $this->server->all( 'clients' ), 'The existing client was found on the last page.' );
	}

	public function test_a_field_daftra_rejects_is_val_002_with_the_field_name(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '999';
		$result                      = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'product_id', $result->error->context['field'] );
		$this->assertSame( 'Product does not exist', $result->error->context['reason'] );
	}

	public function test_a_customer_item_creates_the_client_once(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Customer, '77' );
		$first     = $connector->send( $this->connection(), $item, array( 'customer' => $this->payload()['customer'], 'currency' => 'SAR' ) );
		$second    = $connector->send( $this->connection(), $item, array( 'customer' => $this->payload()['customer'] ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'clients' ) );
		$this->assertNull( $connector->findExisting( $this->connection(), $item ) );
	}

	public function test_secrets_and_the_account_name_never_appear_in_what_the_connector_writes(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakeDaftraServer::KEY, (string) $request['body'] );
			$this->assertStringNotContainsString( FakeDaftraServer::KEY, $request['url'] );
			$this->assertStringNotContainsString( 'acme', (string) $request['body'] );
		}
	}
}
