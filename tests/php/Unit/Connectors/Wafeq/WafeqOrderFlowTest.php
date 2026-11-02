<?php
/**
 * Sending an order to Wafeq: the steps, the checks and what a retry does.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Wafeq\WafeqIds;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class WafeqOrderFlowTest extends WafeqTestCase {

	public function test_a_new_order_becomes_a_draft_that_is_checked_and_then_finalized(): void {
		$connector = $this->connector();
		$result    = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 'WC-10490', $result->ref->ref );
		$this->assertSame( 'finalized', $result->ref->extra['state'] );
		$this->assertSame( 7998, $result->ref->extra['total_minor'] );

		$created = $this->requests( 'POST', '#/v1/invoices/$#' );
		$this->assertCount( 1, $created );
		$body = $this->bodyOf( $created[0] );
		$this->assertSame( 'DRAFT', $body['status'] );
		$this->assertSame( 'WC-10490', $body['external_id'] );
		$this->assertSame( 'WC-10490 (unchecked)', $body['reference'] );
		$this->assertSame( 'WC-10490', $body['invoice_number'] );
		$this->assertSame( '2026-11-02', $body['invoice_date'] );
		$this->assertSame( 'TAX_EXCLUSIVE', $body['tax_amount_type'] );
		$this->assertSame( 'SAR', $body['currency'] );
		$this->assertSame( 'Gift wrap please', $body['notes'] );

		$patches = $this->requests( 'PATCH', '#/v1/invoices/inv_#' );
		$this->assertCount( 1, $patches );
		$this->assertSame( array( 'reference' => 'WC-10490', 'status' => 'FINALIZED' ), $this->bodyOf( $patches[0] ) );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'FINALIZED', $stored['status'] );
		$this->assertSame( 'WC-10490', $stored['reference'] );
	}

	public function test_the_authorization_header_is_the_documented_one_and_the_idempotency_key_is_a_uuid(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$post = $this->requests( 'POST', '#/v1/invoices/$#' )[0];
		$this->assertSame( 'Api-Key ' . \Estasoft\SyncEngine\Tests\Support\FakeWafeqServer::KEY, $post['headers']['Authorization'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $post['headers']['X-Wafeq-Idempotency-Key'] );
		$this->assertSame( ( new WafeqIds() )->idempotencyKey( $this->item(), 'invoice' ), $post['headers']['X-Wafeq-Idempotency-Key'] );
		$this->assertSame( 'application/json', $post['headers']['Content-Type'] );
	}

	public function test_the_tax_authority_report_is_not_asked_for_unless_the_connection_says_so(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 0, $this->requests( 'POST', '#/tax-authority/report#' ) );
		$this->assertArrayNotHasKey( 'tax_authority', $result->ref->extra );
	}

	public function test_a_finalized_invoice_is_reported_when_the_connection_asks_and_the_status_is_kept(): void {
		$result = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$reports = $this->requests( 'POST', '#/v1/invoices/[^/]+/tax-authority/report/$#' );
		$this->assertCount( 1, $reports );
		$this->assertSame( 'pending', $result->ref->extra['tax_authority'] );
		$this->assertArrayHasKey( 'x-wafeq-idempotency-key', array_change_key_case( $reports[0]['headers'] ) );
		$this->assertSame( array(), $result->ref->extra['warnings'] ?? array() );
	}

	public function test_a_report_already_running_is_not_an_error(): void {
		$this->kit->http->route( 'POST', '#/tax-authority/report#', static fn( array $request ) => FakeHttpClient::json( array( 'code' => 'report_in_progress' ), 409 ) );
		$result = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'in_progress', $result->ref->extra['tax_authority'] );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_a_retry_of_the_same_report_gets_the_stored_answer_for_the_same_key(): void {
		$connector = $this->connector();
		$config    = array( 'tax_authority_report' => 'yes' );
		$connector->send( $this->connection( $config ), $this->item(), $this->payload() );
		$again = $connector->send( $this->connection( $config ), $this->item(), $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( 'pending', $again->ref->extra['tax_authority'] );
		$keys = array_map( static fn( array $request ): string => array_change_key_case( $request['headers'] )['x-wafeq-idempotency-key'] ?? '', $this->requests( 'POST', '#/tax-authority/report#' ) );
		$this->assertCount( 1, array_unique( $keys ), 'Both requests carry the same key.' );
	}

	public function test_a_report_wafeq_refuses_is_a_warning_and_the_invoice_stays_done(): void {
		$this->server->reportRefused = true;
		$result                      = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'refused', $result->ref->extra['tax_authority'] );
		$this->assertStringContainsString( 'not registered', implode( ' ', $result->ref->extra['warnings'] ) );
	}

	public function test_a_report_that_fails_for_a_reason_a_retry_can_cure_fails_the_item_so_it_retries(): void {
		$this->server->failWriteAfter( 3, 'server_error' );
		$result = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes' ) ), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Net002, $result->error->code );
		$this->assertSame( 'FINALIZED', $this->server->all( 'invoices' )[0]['status'] );
		$retry = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertSame( 'pending', $retry->ref->extra['tax_authority'] );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
	}

	public function test_a_draft_is_never_reported(): void {
		$result = $this->connector()->send( $this->connection( array( 'tax_authority_report' => 'yes', 'invoice_state' => 'draft' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 0, $this->requests( 'POST', '#/tax-authority/report#' ) );
	}

	public function test_a_customer_is_found_by_name_when_the_keyword_search_does_not_cover_the_email(): void {
		$this->server->tables['contacts']['cnt_old'] = array( 'id' => 'cnt_old', 'name' => 'نور المنصوري', 'email' => 'Noor@Example.com', 'external_id' => '' );
		$this->kit->http->route(
			'GET',
			'#/v1/contacts/.*keyword=noor%40example\.com#i',
			static fn( array $request ) => FakeHttpClient::json( array( 'count' => 0, 'next' => null, 'previous' => null, 'results' => array() ) )
		);
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->all( 'contacts' ), 'The contact made by hand is used, no second one.' );
		$this->assertSame( 'cnt_old', $this->server->all( 'invoices' )[0]['contact'] );
	}

	public function test_a_second_send_links_to_the_same_invoice_and_creates_nothing(): void {
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$posts     = count( $this->requests( 'POST', '#/v1/#' ) );
		$second    = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $posts, count( $this->requests( 'POST', '#/v1/#' ) ), 'No second write of any kind.' );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertCount( 1, $this->requests( 'PATCH', '#/v1/invoices/inv_#' ), 'The invoice was patched once, by the first send.' );
	}

	public function test_find_existing_returns_the_finished_invoice_and_nothing_before_it(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ) );
		$sent = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$found = $connector->findExisting( $this->connection(), $this->item() );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
		$this->assertSame( 7998, $found->extra['total_minor'] );
	}

	public function test_the_customer_is_created_once_and_found_again_by_the_hash_of_the_email(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item( EntityType::Order, '1' ), $this->payload( array( 'number' => '1' ) ) );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( ( new WafeqIds() )->contactExternalId( 'noor@example.com' ), $contact['external_id'] );
		$this->assertStringNotContainsString( 'noor', $contact['external_id'], 'The external id holds no personal data.' );
		$this->assertSame( 'نور المنصوري', $contact['name'] );
		$this->assertSame( 'شارع الشيخ زايد', $contact['address'] );
		$this->assertSame( array( 'customer' ), $contact['relationship'] );
		$this->assertSame( 'individual', $contact['party_type'] );
	}

	public function test_a_contact_made_by_hand_is_found_by_its_email_whatever_the_case(): void {
		$this->server->tables['contacts']['cnt_hand'] = array( 'id' => 'cnt_hand', 'name' => 'Noor (made in Wafeq)', 'email' => 'NOOR@example.com', 'external_id' => '' );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'contacts' ), 'No second contact.' );
		$this->assertSame( 'cnt_hand', $this->server->all( 'invoices' )[0]['contact'] );
	}

	public function test_a_company_customer_is_a_business_with_its_tax_number(): void {
		$payload                         = $this->payload();
		$payload['customer']['company']    = 'شركة النور';
		$payload['customer']['tax_number'] = '300000000000003';
		$this->connector()->send( $this->connection(), $this->item(), $payload );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( 'شركة النور', $contact['name'] );
		$this->assertSame( 'business', $contact['party_type'] );
		$this->assertSame( '300000000000003', $contact['tax_registration_number'] );
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
		$this->assertSame( 'NO-SUCH-SKU', $result->error->context['sku'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ), 'No contact, no invoice.' );
	}

	public function test_a_sku_without_a_mapped_item_is_matched_to_the_wafeq_item_by_its_sku(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'cs-mug-01';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
		$body = $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] );
		$this->assertSame( 'itm_mug', $body['line_items'][0]['item'] );
	}

	public function test_the_free_text_option_sends_the_unknown_sku_as_a_text_line(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['item'] = '';
		$payload['lines'][0]['sku']  = 'NO-SUCH-SKU';
		$result                      = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'free_text' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'][0];
		$this->assertArrayNotHasKey( 'item', $line );
		$this->assertSame( 'Ceramic mug', $line['description'] );
	}

	public function test_the_unit_price_is_the_line_total_over_the_quantity_and_an_uneven_line_is_split_exactly(): void {
		$payload                        = $this->payload( array( 'total' => 1000 ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1000;
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'];
		$this->assertCount( 2, $lines );
		$this->assertSame( array( 2, 3.33 ), array( $lines[0]['quantity'], $lines[0]['unit_amount'] ) );
		$this->assertSame( array( 1, 3.34 ), array( $lines[1]['quantity'], $lines[1]['unit_amount'] ) );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_shipping_and_fees_become_lines_with_their_tax_found_from_the_amounts(): void {
		$payload             = $this->payload( array( 'total' => 7998 + 2500 + 375 + 300 + 45 ) );
		$payload['shipping'] = array( 'name' => 'Courier', 'total' => 2500, 'tax_code' => 'tax_15' );
		$payload['fees']     = array( array( 'name' => 'Gift wrap', 'total' => 300, 'tax_amount' => 45 ) );
		$payload['lines'][0]['tax_code'] = 'tax_15';
		$payload['total']    = 7998 + 1200 + 2500 + 375 + 300 + 45;
		$result              = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'];
		$this->assertCount( 3, $lines );
		$this->assertSame( 'Courier', $lines[1]['description'] );
		$this->assertSame( 'tax_15', $lines[1]['tax_rate'] );
		$this->assertSame( 'Gift wrap', $lines[2]['description'] );
		$this->assertSame( 'tax_15', $lines[2]['tax_rate'], 'The fee carries a tax amount, the rate is the one that gives it.' );
		$this->assertArrayNotHasKey( 'item', $lines[1] );
	}

	public function test_taxes_come_from_the_mapped_code_and_the_total_is_checked_with_wafeq_s_figure(): void {
		$payload                         = $this->payload( array( 'total' => 9198 ) );
		$payload['lines'][0]['tax_code'] = 'tax_15';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
		$this->assertSame( 'tax_15', $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'][0]['tax_rate'] );
	}

	public function test_prices_that_include_tax_are_converted_to_net_before_sending(): void {
		$payload                              = $this->payload( array( 'total' => 9198, 'prices_include_tax' => true ) );
		$payload['lines'][0]['total']         = 9198;
		$payload['lines'][0]['tax_code']      = 'tax_15';
		$result                               = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'];
		$this->assertSame( 39.99, $lines[0]['unit_amount'] );
		$this->assertSame( 2, $lines[0]['quantity'] );
		$this->assertSame( 9198, $result->ref->extra['total_minor'] );
	}

	public function test_an_unknown_tax_code_is_map_002_and_nothing_is_created(): void {
		$payload                         = $this->payload();
		$payload['lines'][0]['tax_code'] = 'tax_nope';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_a_total_that_differs_stops_before_posting_and_the_draft_is_not_taken_for_finished(): void {
		$connector = $this->connector();
		$payload   = $this->payload( array( 'total' => 9000 ) );
		$payload['lines'][0]['tax_code'] = 'tax_15';
		$result = $connector->send( $this->connection(), $this->item(), $payload );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '91.98', $result->error->context['reason'] );
		$this->assertStringContainsString( '90.00', $result->error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'DRAFT', $stored['status'], 'Nothing was posted.' );
		$this->assertSame( 'WC-10490 (unchecked)', $stored['reference'] );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ), 'A draft that failed the check is never linked.' );

		$payload['total'] = 9198;
		$again            = $connector->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $again->isSuccess() );
		$this->assertTrue( $again->alreadyExisted, 'The pass carried on with the same invoice.' );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertSame( 'FINALIZED', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_the_warn_option_finalizes_a_differing_total_and_says_so_in_the_result(): void {
		$payload = $this->payload( array( 'total' => 7000 ) );
		$result  = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'FINALIZED', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_one_minor_unit_of_rounding_is_accepted(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'total' => 7999 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	public function test_a_lost_answer_does_not_create_a_second_invoice_on_the_retry(): void {
		$this->server->tables['contacts']['cnt_hand'] = array( 'id' => 'cnt_hand', 'name' => 'Noor', 'email' => 'noor@example.com', 'external_id' => '' );
		$connector = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'invoices' ), 'The work was done, only the answer was lost.' );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertSame( 'FINALIZED', $this->server->all( 'invoices' )[0]['status'] );
	}

	public function test_a_failure_while_finalizing_is_picked_up_where_it_stopped(): void {
		$connector = $this->connector();
		$this->server->failWriteAfter( 2, 'server_error' );
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $first->error->code );
		$this->assertSame( 'DRAFT', $this->server->all( 'invoices' )[0]['status'] );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
	}

	public function test_the_draft_option_leaves_a_checked_draft_that_counts_as_finished(): void {
		$connector = $this->connector();
		$connection = $this->connection( array( 'invoice_state' => 'draft' ) );
		$result = $connector->send( $connection, $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'draft', $result->ref->extra['state'] );
		$stored = $this->server->all( 'invoices' )[0];
		$this->assertSame( 'DRAFT', $stored['status'] );
		$this->assertSame( 'WC-10490', $stored['reference'] );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
	}

	public function test_the_invoice_number_prefix_option_is_used(): void {
		$this->connector()->send( $this->connection( array( 'invoice_number_prefix' => 'SHOP/' ) ), $this->item(), $this->payload() );
		$this->assertSame( 'SHOP/10490', $this->server->all( 'invoices' )[0]['invoice_number'] );
	}

	public function test_a_paid_order_records_one_payment_when_payments_are_on(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'Main bank' ) );
		$payload    = $this->payload( array( 'payment' => array( 'date' => '2026-11-02', 'reference' => 'ch_3Pabc' ) ) );

		$this->assertNull( $connector->findExisting( $connection, $this->item() ) );
		$result = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$payments = $this->server->all( 'payments' );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'acc_bank', $payments[0]['paid_through_account'] );
		$this->assertSame( 'WC-10490-PAY', $payments[0]['external_id'] );
		$this->assertSame( 'ch_3Pabc', $payments[0]['reference'] );
		$this->assertSame( $result->ref->id, $payments[0]['invoice_payments'][0]['invoice'] );
		$this->assertSame( 79.98, $payments[0]['amount'] );

		$again = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'payments' ), 'Never a second payment.' );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $connection, $this->item() ) );
	}

	public function test_a_payment_that_failed_is_added_by_the_next_pass_without_a_second_invoice(): void {
		$connector  = $this->connector();
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'acc_bank' ) );
		$payload    = $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) );
		$this->server->failWriteAfter( 3, 'timeout' );
		$first = $connector->send( $connection, $this->item(), $payload );
		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 0, $this->server->all( 'payments' ) );
		$this->assertNull( $connector->findExisting( $connection, $this->item() ), 'The invoice is not finished without its payment.' );

		$retry = $connector->send( $connection, $this->item(), $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'invoices' ) );
		$this->assertCount( 1, $this->server->all( 'payments' ) );
	}

	public function test_an_unpaid_order_records_no_payment(): void {
		$connection = $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'acc_bank' ) );
		$this->assertTrue( $this->connector()->send( $connection, $this->item(), $this->payload() )->isSuccess() );
		$this->assertCount( 0, $this->server->all( 'payments' ) );
	}

	public function test_payments_need_a_payment_account(): void {
		$connection = $this->connection( array( 'record_payment' => 'yes' ) );
		$result     = $this->connector()->send( $connection, $this->item(), $this->payload( array( 'payment' => array( 'date' => '2026-11-02' ) ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'payment_account', $result->error->context['field'] );
	}

	public function test_lists_are_read_page_by_page_never_from_the_first_page_alone(): void {
		$this->server->pageSize = 1;
		for ( $i = 1; $i <= 4; ++$i ) {
			$this->server->tables['contacts'][ 'cnt_x' . $i ] = array( 'id' => 'cnt_x' . $i, 'name' => 'Other ' . $i, 'email' => "other{$i}@example.com", 'external_id' => '' );
		}
		$this->server->tables['contacts']['cnt_target'] = array( 'id' => 'cnt_target', 'name' => 'Noor', 'email' => 'noor@example.com', 'external_id' => '' );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'cnt_target', $this->server->all( 'invoices' )[0]['contact'] );
		$this->assertCount( 5, $this->server->all( 'contacts' ), 'The existing contact was found on the last page.' );
	}

	public function test_the_sales_account_can_be_named_by_code_and_two_revenue_accounts_need_a_choice(): void {
		$this->server->tables['accounts']['acc_rev2'] = array( 'id' => 'acc_rev2', 'name' => 'Other income', 'code' => '4100', 'classification' => 'REVENUE', 'is_payment_enabled' => false );
		$unnamed = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $unnamed->error->code );
		$this->assertSame( 'sales_account', $unnamed->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );

		$named = $this->connector()->send( $this->connection( array( 'sales_account' => '4100' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $named->isSuccess() );
		$this->assertSame( 'acc_rev2', $this->bodyOf( $this->requests( 'POST', '#/v1/invoices/$#' )[0] )['line_items'][0]['account'] );
	}

	public function test_a_sales_account_that_does_not_exist_is_refused(): void {
		$result = $this->connector()->send( $this->connection( array( 'sales_account' => 'nope' ) ), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_a_field_wafeq_rejects_is_val_002_with_the_field_name_and_needs_a_person(): void {
		$payload                     = $this->payload();
		$payload['lines'][0]['name'] = '';
		$payload['lines'][0]['sku']  = '';
		$result                      = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'line_items', $result->error->context['field'] );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
	}

	public function test_a_switched_off_api_is_the_plan_error(): void {
		$this->server->apiEnabled = false;
		$result                   = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Auth006, $result->error->code );
	}

	public function test_a_customer_item_creates_the_contact_once(): void {
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

	public function test_secrets_never_appear_in_what_the_connector_writes_into_wafeq(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( \Estasoft\SyncEngine\Tests\Support\FakeWafeqServer::KEY, (string) $request['body'] );
			$this->assertStringNotContainsString( \Estasoft\SyncEngine\Tests\Support\FakeWafeqServer::KEY, $request['url'] );
		}
	}
}
