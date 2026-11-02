<?php
/**
 * Sending an order to Qoyod: the steps, the checks and what a retry does.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeQoyodServer;

final class QoyodOrderFlowTest extends QoyodTestCase {

	public function test_a_new_order_becomes_a_draft_that_is_checked_and_then_approved(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 'WC-10490', $result->ref->ref );
		$this->assertSame( 'approved', $result->ref->extra['state'] );
		$this->assertSame( 7998, $result->ref->extra['total_minor'] );
		$this->assertSame( 'SAR', $result->ref->extra['currency'] );

		$posts = $this->requests( 'POST', '#/2.0/invoices$#' );
		$this->assertCount( 1, $posts );
		$invoice = $this->bodyOf( $posts[0] )['invoice'];
		$this->assertSame( 'Draft', $invoice['status'] );
		$this->assertSame( 'WC-10490', $invoice['reference'] );
		$this->assertSame( "WC-10490 (unchecked)\nGift wrap please", $invoice['description'] );
		$this->assertSame( '2026-11-02', $invoice['issue_date'] );
		$this->assertSame( 1, $invoice['inventory_id'] );
		$this->assertSame( 1, $invoice['line_items_attributes'][0]['product_id'] );
		$this->assertSame( '39.99', $invoice['line_items_attributes'][0]['unit_price'] );
		$this->assertSame( 2, $invoice['line_items_attributes'][0]['quantity'] );
		$this->assertSame( '2', $invoice['line_items_attributes'][0]['tax_id'] );
		$this->assertFalse( $invoice['line_items_attributes'][0]['is_inclusive'] );

		$patches = $this->requests( 'PATCH', '#/2.0/invoices/#' );
		$this->assertCount( 1, $patches );
		$this->assertSame( array( 'invoice' => array( 'description' => "WC-10490\nGift wrap please", 'status' => 'Approved' ) ), $this->bodyOf( $patches[0] ) );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'Approved', $stored['status'] );
	}

	public function test_every_request_carries_the_key_in_the_documented_header_and_goes_to_the_documented_address(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( FakeQoyodServer::KEY, $request['headers']['API-KEY'] );
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
			$this->assertStringStartsWith( 'https://api.qoyod.com/2.0/', $request['url'] );
		}
	}

	public function test_the_only_documented_address_is_used_whatever_the_settings_say(): void {
		$result = $this->connector()->send( $this->connection( array( 'endpoint' => 'www.qoyod.com' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringStartsWith( 'https://api.qoyod.com/2.0/', $request['url'] );
		}
	}

	public function test_a_line_without_a_tax_stops_the_order_before_anything_is_created_and_is_never_approved_as_zero_rated(): void {
		$payload = $this->payload();
		unset( $payload['lines'][0]['tax_code'] );
		$result = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'lines[].tax_code', $result->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
		$this->assertCount( 0, $this->server->all( 'invoices' ) );
	}

	public function test_the_merchant_can_choose_zero_rated_or_exempt_for_lines_without_a_tax(): void {
		$payload = $this->payload();
		unset( $payload['lines'][0]['tax_code'] );
		$zero = $this->connector()->send( $this->connection( array( 'untaxed_rows' => 'zero_rated' ) ), $this->item(), $payload );
		$this->assertTrue( $zero->isSuccess(), (string) $zero->error?->message() );
		$this->assertSame( '2', $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'][0]['tax_id'] );
		$exempt = $this->connector()->send( $this->connection( array( 'untaxed_rows' => 'exempt' ) ), $this->item( EntityType::Order, '2' ), $payload );
		$this->assertTrue( $exempt->isSuccess(), (string) $exempt->error?->message() );
		$this->assertSame( '3', $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[1] )['invoice']['line_items_attributes'][0]['tax_id'] );
	}

	public function test_a_server_that_ignores_the_search_filter_still_gives_the_right_invoice_on_a_small_account(): void {
		$this->server->ignoreFilters = true;
		$connector                   = $this->connector();
		$first                       = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$second = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_a_server_that_ignores_the_search_filter_on_a_huge_account_says_so_instead_of_blaming_the_search(): void {
		$this->server->ignoreFilters = true;
		$this->server->pageSize      = 2;
		for ( $i = 1; $i <= 260; ++$i ) {
			$this->server->tables['invoices'][ 5000 + $i ] = array( 'id' => 5000 + $i, 'reference' => 'OTHER-' . $i, 'status' => 'Approved', 'total' => '1.00', 'contact_id' => 1, 'description' => '', 'issue_date' => '2026-01-01', 'line_items' => array() );
		}
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'did not apply the search filter', $result->error->context['reason'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ), 'Nothing is created when the lookup could not finish.' );
	}

	public function test_a_second_send_links_to_the_same_invoice_and_writes_nothing(): void {
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$writes    = count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'PATCH', '#.#' ) );
		$second    = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'PATCH', '#.#' ) ) );
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

	public function test_the_customer_is_created_once_and_found_again_by_its_email_whatever_the_case(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item( EntityType::Order, '1' ), $this->payload( array( 'number' => '1' ) ) );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( 'نور المنصوري', $contact['name'] );
		$this->assertSame( 'noor@example.com', $contact['email'] );
		$this->assertSame( '300000000000003', $contact['tax_number'] );
		$this->assertSame( 'شارع الشيخ زايد', $contact['billing_address'] );
		$this->assertSame( 'Active', $contact['status'] );
		$body = $this->bodyOf( $this->requests( 'POST', '#/2.0/customers$#' )[0] );
		$this->assertArrayHasKey( 'contact', $body );
	}

	public function test_a_longer_address_that_contains_the_email_is_not_taken_for_the_customer(): void {
		$this->server->tables['contacts'][50] = array( 'id' => 50, 'name' => 'Someone else', 'email' => 'anoor@example.com.sa' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertCount( 2, $this->server->all( 'contacts' ) );
		$this->assertNotSame( 50, $this->server->all( 'invoices' )[0]['contact_id'] );
	}

	public function test_a_missing_email_is_map_003_and_nothing_is_sent(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';
		$result                       = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_an_order_in_another_currency_than_the_account_is_refused_before_anything_is_sent(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'currency' => 'USD' ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', $result->error->context['reason'] );
		$this->assertStringContainsString( 'SAR', $result->error->context['reason'] );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'NO-SUCH-SKU';
		$result                      = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'NO-SUCH-SKU', $result->error->context['sku'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_sku_without_a_mapped_product_is_matched_by_its_sku(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'cs-mug-01';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
		$this->assertSame( 1, $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'][0]['product_id'] );
		$search = $this->requests( 'GET', '#/2.0/products\?#' )[0]['url'];
		$this->assertStringContainsString( 'q%5Bsku_cont%5D=cs-mug-01', $search );
	}

	public function test_taxes_come_from_the_mapped_code_and_the_total_is_checked_with_qoyod_s_figure(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
	}

	public function test_the_tax_can_be_sent_as_a_percentage_when_qoyod_rejects_the_code(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection( array( 'line_tax_field' => 'tax_percent' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'][0];
		$this->assertSame( '15.00', $line['tax_percent'] );
		$this->assertArrayNotHasKey( 'tax_id', $line );
	}

	public function test_prices_that_include_tax_are_sent_as_they_are_with_the_inclusive_flag(): void {
		$payload                         = $this->payload( array( 'total' => 9198, 'prices_include_tax' => true ) );
		$payload['lines'][0]['total']    = 9198;
		$payload['lines'][0]['tax_code'] = '1';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'][0];
		$this->assertTrue( $line['is_inclusive'] );
		$this->assertSame( '45.99', $line['unit_price'] );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
	}

	public function test_a_tax_key_qoyod_does_not_know_is_caught_by_the_total_check_not_posted(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = '1';
		$connector                       = $this->connector();
		$result                          = $connector->send( $this->connection( array( 'line_tax_field' => 'tax_percent' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess() );

		$wrong  = $this->payload( array( 'total' => 5000 ) );
		$wrong['lines'][0]['tax_code'] = '1';
		$failed = $connector->send( $this->connection(), $this->item( EntityType::Order, '77' ), $wrong );
		$this->assertSame( ErrorCode::Val002, $failed->error->code );
		$this->assertStringContainsString( '91.98', $failed->error->context['reason'] );
		$this->assertStringContainsString( '50.00', $failed->error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $failed->error->errorClass() );
	}

	public function test_a_total_that_differs_leaves_a_draft_that_is_never_taken_for_finished(): void {
		$connector = $this->connector();
		$payload   = $this->payload( array( 'total' => 9000 ) );
		$result    = $connector->send( $this->connection(), $this->item(), $payload );

		$this->assertFalse( $result->isSuccess() );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'Draft', $stored['status'], 'Nothing was approved.' );
		$this->assertStringEndsWith( '(unchecked)', explode( "\n", $stored['description'] )[0] );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ) );

		$payload['total'] = 7998;
		$again            = $connector->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $again->isSuccess() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertSame( 'Approved', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_the_warn_option_approves_a_differing_total_and_says_so(): void {
		$result = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item(), $this->payload( array( 'total' => 7000 ) ) );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'Approved', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_an_uneven_line_is_split_so_the_sum_is_exact(): void {
		$payload                         = $this->payload( array( 'total' => 1000 ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1000;
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'];
		$this->assertCount( 2, $lines );
		$this->assertSame( array( 2, '3.33' ), array( $lines[0]['quantity'], $lines[0]['unit_price'] ) );
		$this->assertSame( array( 1, '3.34' ), array( $lines[1]['quantity'], $lines[1]['unit_price'] ) );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_shipping_and_fees_need_service_products_because_qoyod_has_no_lines_without_a_product(): void {
		$payload             = $this->payload( array( 'total' => 7998 + 2500 ) );
		$payload['shipping'] = array( 'name' => 'Courier', 'total' => 2500, 'tax_code' => '2' );
		$refused             = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'shipping_product_sku', $refused->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );

		$result = $this->connector()->send( $this->connection( array( 'shipping_product_sku' => 'SRV-SHIP' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'];
		$this->assertCount( 2, $lines );
		$this->assertSame( 3, $lines[1]['product_id'] );
		$this->assertSame( 'Courier', $lines[1]['description'] );
	}

	public function test_a_fee_with_a_tax_amount_gets_the_rate_that_explains_it(): void {
		$payload         = $this->payload( array( 'total' => 7998 + 345 ) );
		$payload['fees'] = array( array( 'name' => 'Gift wrap', 'total' => 300, 'tax_amount' => 45 ) );
		$result          = $this->connector()->send( $this->connection( array( 'fee_product_sku' => 'SRV-FEE' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['line_items_attributes'];
		$this->assertSame( '1', $lines[1]['tax_id'] );
	}

	public function test_a_reference_that_is_already_taken_is_resolved_by_searching_again(): void {
		$this->server->tables['contacts'][60] = array( 'id' => 60, 'name' => 'Noor', 'email' => 'noor@example.com' );
		$connector                            = $this->connector();
		$first                                = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $first->isSuccess() );
		$this->server->blindSearches = 1;
		$second                      = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_a_lost_answer_does_not_create_a_second_invoice_on_the_retry(): void {
		$this->server->tables['contacts'][60] = array( 'id' => 60, 'name' => 'Noor', 'email' => 'noor@example.com' );
		$connector                            = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertSame( 'Approved', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_a_failure_while_approving_is_picked_up_where_it_stopped(): void {
		$connector = $this->connector();
		$this->server->failWriteAfter( 2, 'server_error' );
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $first->error->code );
		$this->assertSame( 'Draft', $this->server->all( 'invoices' )[0]['status'] );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
	}

	public function test_the_draft_option_leaves_a_checked_draft_that_counts_as_finished(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'approval' => 'draft' ) );
		$result     = $connector->send( $connection, $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'draft', $result->ref->extra['state'] );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'Draft', $stored['status'] );
		$this->assertSame( "WC-10490\nGift wrap please", $stored['description'] );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
	}

	public function test_a_paid_order_records_one_payment_when_payments_are_on(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_account_id' => '7' ) );
		$payload    = $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) );

		$this->assertNull( $connector->findExisting( $connection, $this->item() ) );
		$result = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$payments = $this->server->all( 'invoice_payments' );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'WC-10490-PAY', $payments[0]['reference'] );
		$this->assertSame( '79.98', $payments[0]['amount'] );
		$body = $this->bodyOf( $this->requests( 'POST', '#/2.0/invoice_payments$#' )[0] )['invoice_payment'];
		$this->assertSame( array( 'account_id', 'amount', 'date', 'invoice_id', 'reference' ), array_keys( $body ) );
		$this->assertSame( 'Paid', $this->server->all( 'invoices' )[0]['status'] );

		$again = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice_payments' ), 'Never a second payment.' );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
	}

	public function test_payments_need_a_payment_account_and_an_unpaid_order_records_none(): void {
		$connection = $this->connection( array( 'record_payment' => 'yes' ) );
		$unpaid     = $this->connector()->send( $connection, $this->item(), $this->payload() );
		$this->assertTrue( $unpaid->isSuccess() );
		$this->assertCount( 0, $this->server->all( 'invoice_payments' ) );

		$paid = $this->connector()->send( $connection, $this->item(), $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) ) );
		$this->assertFalse( $paid->isSuccess() );
		$this->assertSame( 'payment_account_id', $paid->error->context['field'] );
	}

	public function test_lists_are_read_page_by_page_and_a_server_without_pages_does_not_loop(): void {
		$this->server->pageSize = 1;
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->server->tables['contacts'][ 70 + $i ] = array( 'id' => 70 + $i, 'name' => 'Other ' . $i, 'email' => "noor{$i}@example.com.sa" );
		}
		$this->server->tables['contacts'][80] = array( 'id' => 80, 'name' => 'Noor', 'email' => 'noor@example.com' );
		$result                               = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 80, $this->server->all( 'invoices' )[0]['contact_id'] );
		$this->assertCount( 5, $this->server->all( 'contacts' ) );

		$this->server->ignorePage = true;
		$before                   = count( $this->kit->http->requests );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$this->assertLessThanOrEqual( 2, count( $this->kit->http->requests ) - $before );
	}

	public function test_a_branch_must_be_chosen_when_there_are_several_and_a_missing_branch_is_refused(): void {
		$this->server->addBranch();
		$none = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( 'inventory_id', $none->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );

		$wrong = $this->connector()->send( $this->connection( array( 'inventory_id' => '99' ) ), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $wrong->error->code );

		$ok = $this->connector()->send( $this->connection( array( 'inventory_id' => '2' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $ok->isSuccess() );
		$this->assertSame( 2, $this->bodyOf( $this->requests( 'POST', '#/2.0/invoices$#' )[0] )['invoice']['inventory_id'] );
	}

	public function test_a_field_qoyod_rejects_is_val_002_with_the_field_name(): void {
		$payload                          = $this->payload();
		$payload['lines'][0]['item']      = '999';
		$result                           = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'product', $result->error->context['field'] );
		$this->assertSame( 'must exist', $result->error->context['reason'] );
	}

	public function test_a_customer_item_creates_the_customer_once(): void {
		$connector = $this->connector();
		$item      = $this->item( EntityType::Customer, '77' );
		$first     = $connector->send( $this->connection(), $item, array( 'customer' => $this->payload()['customer'] ) );
		$second    = $connector->send( $this->connection(), $item, array( 'customer' => $this->payload()['customer'] ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$this->assertNull( $connector->findExisting( $this->connection(), $item ) );
	}

	public function test_secrets_never_appear_in_what_the_connector_writes_into_qoyod(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakeQoyodServer::KEY, (string) $request['body'] );
			$this->assertStringNotContainsString( FakeQoyodServer::KEY, $request['url'] );
		}
	}
}
