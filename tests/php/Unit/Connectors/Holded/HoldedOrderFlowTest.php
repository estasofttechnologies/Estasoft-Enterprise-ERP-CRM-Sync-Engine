<?php
/**
 * Sending an order to Holded: the steps, the checks and what a retry does.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\FakeHoldedServer;

final class HoldedOrderFlowTest extends HoldedTestCase {

	/**
	 * Writes the connector made.
	 */
	private function writes(): int {
		return count( $this->requests( 'POST', '#.#' ) ) + count( $this->requests( 'PUT', '#.#' ) ) + count( $this->requests( 'DELETE', '#.#' ) );
	}

	public function test_a_new_order_becomes_an_invoice_with_the_marker_in_its_notes(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 'F26-0001', $result->ref->ref );
		$this->assertSame( 'invoice', $result->ref->extra['document'] );
		$this->assertSame( 9678, $result->ref->extra['total_minor'] );
		$this->assertSame( 'EUR', $result->ref->extra['currency'] );

		$posts = $this->requests( 'POST', '#/documents/invoice$#' );
		$this->assertCount( 1, $posts );
		$body  = $this->bodyOf( $posts[0] );
		$lines = explode( "\n", $body['notes'] );
		$this->assertSame( '[WC-10490] 96.78 EUR', $lines[0] );
		$this->assertSame( 'Gift wrap please', $lines[1] );
		$this->assertSame( 'eur', $body['currency'] );
		$this->assertSame( strtotime( '2026-11-02 12:00:00 UTC' ), $body['date'] );
		$this->assertSame( array( 'name' => 'Ceramic mug', 'units' => 2, 'subtotal' => 39.99, 'tax' => 21, 'sku' => 'CS-MUG-01' ), $body['items'][0] );
		$this->assertSame( $this->server->all( 'contacts' )[0]['id'], $body['contactId'] );
	}

	public function test_every_request_carries_the_key_in_the_key_header_and_never_an_authorization_header(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertNotEmpty( $this->kit->http->requests );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( FakeHoldedServer::KEY, $request['headers']['key'] );
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
			$this->assertStringStartsWith( 'https://api.holded.com/api/invoicing/v1/', $request['url'] );
		}
	}

	public function test_a_sales_order_is_made_when_the_option_says_so(): void {
		$result = $this->connector()->send( $this->connection( array( 'document_type' => 'salesorder' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'SO26-0001', $result->ref->ref );
		$this->assertCount( 1, $this->requests( 'POST', '#/documents/salesorder$#' ) );
		$this->assertCount( 0, $this->server->all( 'invoice' ) );
	}

	public function test_an_option_outside_its_list_falls_back_to_the_default(): void {
		$result = $this->connector()->send( $this->connection( array( 'document_type' => 'purchase' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $this->requests( 'POST', '#/documents/invoice$#' ) );
	}

	public function test_the_warehouse_the_channel_and_the_payment_method_are_sent_when_set(): void {
		$config = array( 'warehouse_id' => 'w2', 'sales_channel_id' => 'sc-1', 'payment_method_id' => 'pm_9' );
		$this->connector()->send( $this->connection( $config ), $this->item(), $this->payload() );
		$body = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] );
		$this->assertSame( 'w2', $body['warehouseId'] );
		$this->assertSame( 'sc-1', $body['salesChannelId'] );
		$this->assertSame( 'pm_9', $body['paymentMethodId'] );
		$this->connector()->send( $this->connection( array( 'warehouse_id' => 'w 2; drop' ) ), $this->item( EntityType::Order, '2' ), $this->payload() );
		$second = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[1] );
		$this->assertArrayNotHasKey( 'warehouseId', $second, 'An identifier with odd characters is ignored.' );
	}

	public function test_the_contact_is_created_once_and_found_again_by_its_email_whatever_the_case(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item( EntityType::Order, '1' ), $this->payload( array( 'number' => '1' ) ) );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( 'Núria Ibáñez', $contact['name'] );
		$this->assertSame( 'nuria@example.com', $contact['email'] );
		$this->assertSame( 'client', $contact['type'] );
		$this->assertSame( 'B12345674', $contact['code'] );
		$this->assertSame( '+34600123456', $contact['phone'] );
		$this->assertSame( 'Calle de Alcalá 12', $contact['billAddress']['address'] );
		$this->assertSame( '28014', $contact['billAddress']['postalCode'] );
		$this->assertSame( 'ES', $contact['billAddress']['countryCode'] );
		$this->assertCount( 1, $this->requests( 'GET', '#/contacts\?#' ), 'The contact list is read once for both orders.' );
	}

	public function test_a_contact_that_already_exists_is_used_not_created(): void {
		$this->server->tables['contacts']['c900'] = array( 'id' => 'c900', 'name' => 'Núria', 'email' => 'NURIA@example.com' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$this->assertSame( 'c900', $this->server->all( 'invoice' )[0]['contact'] );
	}

	public function test_an_address_that_contains_the_email_is_not_taken_for_the_contact(): void {
		$this->server->tables['contacts']['c901'] = array( 'id' => 'c901', 'name' => 'Other', 'email' => 'anuria@example.com.es' );
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertCount( 2, $this->server->all( 'contacts' ) );
		$this->assertNotSame( 'c901', $this->server->all( 'invoice' )[0]['contact'] );
	}

	public function test_a_missing_email_is_map_003_and_nothing_is_sent(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';
		$result                       = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_a_second_send_links_to_the_same_invoice_and_writes_nothing(): void {
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$writes    = $this->writes();
		$second    = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->writes() );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_find_existing_returns_the_finished_invoice_and_nothing_before_it(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ) );
		$sent  = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$found = $connector->findExisting( $this->connection(), $this->item() );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
		$this->assertSame( 9678, $found->extra['total_minor'] );
	}

	public function test_find_existing_follows_the_link_the_engine_stored(): void {
		$id = $this->server->seedDocument( 'invoice', array( 'notes' => "[WC-10490] 96.78 EUR\nmade last year", 'total' => 96.78, 'date' => strtotime( '2025-01-05 12:00:00 UTC' ) ) );
		$linked = new Item( 1, 1, EntityType::Order, '10490', 'created', 'order:10490', 'k', ItemState::Synced, externalId: $id );
		$found  = $this->connector()->findExisting( $this->connection(), $linked );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $id, $found->id );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->item() ), 'Without the link the old invoice is outside the 30 days looked at.' );
	}

	public function test_find_existing_reads_only_the_last_thirty_days(): void {
		$this->connector()->findExisting( $this->connection(), $this->item() );
		$list = $this->requests( 'GET', '#/documents/invoice\?#' )[0];
		parse_str( (string) parse_url( $list['url'], PHP_URL_QUERY ), $query );
		$now = strtotime( '2026-11-02 12:00:00 UTC' );
		$this->assertSame( $now - 30 * 86400, (int) $query['starttmp'] );
		$this->assertSame( $now + 86400, (int) $query['endtmp'] );
	}

	public function test_the_search_window_in_send_surrounds_the_order_date(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'date' => '2026-03-10' ) ) );
		$list = $this->requests( 'GET', '#/documents/invoice\?#' )[0];
		parse_str( (string) parse_url( $list['url'], PHP_URL_QUERY ), $query );
		$day = strtotime( '2026-03-10 00:00:00 UTC' );
		$this->assertSame( $day - 86400, (int) $query['starttmp'] );
		$this->assertSame( $day + 3 * 86400 - 1, (int) $query['endtmp'] );
	}

	public function test_an_old_order_is_found_by_send_through_its_own_date_window(): void {
		$payload   = $this->payload( array( 'date' => '2026-03-10' ) );
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $first->isSuccess() );
		$second = $connector->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ), 'Outside the 30 days findExisting does not look.' );
	}

	public function test_a_reference_that_only_starts_like_ours_is_not_taken_for_ours(): void {
		$this->server->seedDocument( 'invoice', array( 'notes' => '[WC-104901] 10.00 EUR', 'total' => 10 ) );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->item() ) );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 2, $this->server->all( 'invoice' ) );
	}

	public function test_the_marker_is_found_even_when_a_person_moved_it_to_the_description(): void {
		$this->server->seedDocument( 'invoice', array( 'notes' => '', 'desc' => '[WC-10490] 96.78 EUR', 'total' => 96.78, 'currency' => 'eur', 'products' => array( array(), array() ) ) );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_a_list_without_notes_is_read_one_document_at_a_time(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->server->listWithoutNotes = true;
		$before                         = count( $this->requests( 'GET', '#/documents/invoice/#' ) );
		$result                         = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertGreaterThan( $before, count( $this->requests( 'GET', '#/documents/invoice/#' ) ) );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_a_list_without_notes_and_too_many_documents_stops_with_an_error_not_a_second_invoice(): void {
		for ( $i = 0; $i < 60; ++$i ) {
			$this->server->seedDocument( 'invoice', array( 'notes' => 'x', 'total' => 1 ) );
		}
		$this->server->listWithoutNotes = true;
		$result                         = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 60, $this->server->all( 'invoice' ) );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'NO-SUCH-SKU';
		$result                     = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 0, $this->writes() );
		$this->assertCount( 0, $this->server->all( 'contacts' ) );
	}

	public function test_a_sku_is_matched_exactly_not_by_containment(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'cs-mug-01';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
		$this->assertSame( 'CS-MUG-01', $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0]['sku'], 'The SKU is written as Holded has it.' );
	}

	public function test_the_free_text_option_sends_an_unknown_sku_as_a_text_line(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'NO-SUCH-SKU';
		$result                     = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'free_text' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0];
		$this->assertArrayNotHasKey( 'sku', $line );
		$this->assertSame( 'Ceramic mug', $line['name'] );
	}

	public function test_a_line_without_a_sku_is_a_text_line(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = '';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
		$this->assertArrayNotHasKey( 'sku', $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0] );
	}

	public function test_the_product_list_is_read_once_per_run_and_again_after_ten_minutes(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item( EntityType::Order, '1' ), $this->payload() );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload() );
		$this->assertCount( 1, $this->requests( 'GET', '#/products\?page=1#' ) );
		$this->kit->clock->advance( 601 );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '3' ), $this->payload() );
		$this->assertCount( 2, $this->requests( 'GET', '#/products\?page=1#' ) );
	}

	public function test_a_line_without_a_tax_code_is_sent_with_an_explicit_zero_tax(): void {
		$payload = $this->payload( array( 'total' => 7998 ) );
		unset( $payload['lines'][0]['tax_code'] );
		$result = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 0, $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0]['tax'] );
		$this->assertSame( 7998, $result->ref->extra['total_minor'] );
	}

	public function test_a_fractional_tax_rate_is_sent_as_a_number(): void {
		$payload                         = $this->payload( array( 'total' => 8438 ) );
		$payload['lines'][0]['tax_code'] = '5.5';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5.5, $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0]['tax'] );
	}

	public function test_a_percent_sign_in_the_tax_code_is_accepted(): void {
		$payload                         = $this->payload();
		$payload['lines'][0]['tax_code'] = '21 %';
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->item(), $payload )->isSuccess() );
	}

	public function test_a_tax_code_that_is_not_a_percentage_is_map_002_and_nothing_is_created(): void {
		foreach ( array( 'IVA21', '-5', '150', 'abc' ) as $code ) {
			$payload                         = $this->payload();
			$payload['lines'][0]['tax_code'] = $code;
			$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
			$this->assertSame( ErrorCode::Map002, $result->error->code, $code );
		}
		$this->assertSame( 0, $this->writes() );
	}

	public function test_prices_that_include_tax_are_converted_to_net(): void {
		$payload                      = $this->payload( array( 'total' => 9678, 'prices_include_tax' => true ) );
		$payload['lines'][0]['total'] = 9678;
		$result                       = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 39.99, $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'][0]['subtotal'] );
		$this->assertSame( 9678, $result->ref->extra['total_minor'] );
	}

	public function test_a_total_that_differs_stops_before_anything_is_created(): void {
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'total' => 9000 ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '96.78', $result->error->context['reason'] );
		$this->assertStringContainsString( '90.00', $result->error->context['reason'] );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		$this->assertSame( 0, $this->writes() );
		$this->assertCount( 0, $this->server->all( 'contacts' ), 'Not even the contact is made.' );
	}

	public function test_the_warn_option_makes_the_document_and_says_so(): void {
		$result = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item(), $this->payload( array( 'total' => 9000 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_an_uneven_line_is_split_so_the_sum_is_exact(): void {
		$payload                         = $this->payload( array( 'total' => 1000 ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1000;
		$payload['lines'][0]['tax_code'] = '0';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'];
		$this->assertCount( 2, $items );
		$this->assertSame( array( 2, 3.33 ), array( $items[0]['units'], $items[0]['subtotal'] ) );
		$this->assertSame( array( 1, 3.34 ), array( $items[1]['units'], $items[1]['subtotal'] ) );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_a_quantity_with_a_fraction_becomes_one_line_that_says_so(): void {
		$payload                         = $this->payload( array( 'total' => 2500 ) );
		$payload['lines'][0]['quantity'] = 2.5;
		$payload['lines'][0]['total']    = 2500;
		$payload['lines'][0]['tax_code'] = '0';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'Ceramic mug (× 2.5)', $items[0]['name'] );
	}

	public function test_shipping_and_fees_become_text_lines_and_the_fee_rate_is_found_from_its_tax(): void {
		$payload             = $this->payload( array( 'total' => 9678 + 3025 + 363 ) );
		$payload['shipping'] = array( 'name' => 'Courier', 'total' => 2500, 'tax_code' => '21' );
		$payload['fees']     = array( array( 'name' => 'Gift wrap', 'total' => 300, 'tax_amount' => 63 ) );
		$result              = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['items'];
		$this->assertCount( 3, $items );
		$this->assertSame( 'Courier', $items[1]['name'] );
		$this->assertArrayNotHasKey( 'sku', $items[1] );
		$this->assertSame( 21, $items[2]['tax'] );
	}

	public function test_a_fresh_order_costs_seven_calls_on_an_empty_account_and_a_second_one_four(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item(), $this->payload() );
		$first = count( $this->kit->http->requests );
		$this->assertSame( 7, $first, 'Window, products and the empty page after them, contacts, create contact, create invoice, read it back.' );
		$connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertSame( 4, count( $this->kit->http->requests ) - $first, 'The products and contacts are kept: window page and its empty page, create, read back.' );
	}

	public function test_a_lost_answer_does_not_create_a_second_invoice_on_the_retry(): void {
		$connector = $this->connector();
		$this->server->tables['contacts']['c900'] = array( 'id' => 'c900', 'name' => 'Núria', 'email' => 'nuria@example.com' );
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
	}

	public function test_a_lost_answer_on_the_contact_does_not_create_it_twice(): void {
		$connector = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
		$this->assertCount( 0, $this->server->all( 'invoice' ) );

		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->server->all( 'contacts' ), 'The kept contact list was dropped, so the contact made before is found.' );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_a_failure_before_the_invoice_leaves_nothing_but_the_contact_and_the_retry_goes_on(): void {
		$connector = $this->connector();
		$this->server->failWriteAfter( 1, 'server_error' );
		$first = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $first->error->code );
		$this->assertCount( 0, $this->server->all( 'invoice' ) );
		$retry = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertCount( 1, $this->server->all( 'contacts' ) );
	}

	public function test_an_invoice_that_comes_out_with_another_total_is_not_deleted_and_never_linked_as_finished(): void {
		$connector          = $this->connector();
		$this->server->skew = 500;
		$result = $connector->send( $this->connection(), $this->item(), $this->payload() );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'F26-0001', $result->error->context['reason'], 'The error names the invoice that stays in Holded.' );
		$this->assertStringContainsString( '101.78', $result->error->context['reason'] );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertCount( 0, $this->requests( 'DELETE', '#.#' ), 'An issued invoice is never deleted by the connector.' );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item() ), 'A failed invoice is not a finished record.' );

		$again = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $again->isSuccess(), 'The retry reports the same invoice instead of making a second one.' );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_a_sales_order_that_comes_out_with_another_total_is_deleted(): void {
		$connector          = $this->connector();
		$config             = array( 'document_type' => 'salesorder' );
		$this->server->skew = 500;
		$result             = $connector->send( $this->connection( $config ), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertCount( 0, $this->server->all( 'salesorder' ) );
		$this->assertCount( 1, $this->requests( 'DELETE', '#/documents/salesorder/#' ) );

		$retry = $connector->send( $this->connection( $config ), $this->item(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->server->all( 'salesorder' ) );
	}

	public function test_a_sales_order_that_cannot_be_deleted_says_so(): void {
		$this->server->skew         = 500;
		$this->server->refuseDelete = true;
		$result                     = $this->connector()->send( $this->connection( array( 'document_type' => 'salesorder' ) ), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'Delete it in Holded', $result->error->context['reason'] );
		$this->assertNull( $this->connector()->findExisting( $this->connection( array( 'document_type' => 'salesorder' ) ), $this->item() ) );
	}

	public function test_the_warn_option_keeps_a_document_with_another_total_and_findexisting_still_refuses_it(): void {
		$this->server->skew = 500;
		$result             = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->item() ), 'The document does not show what the notes promise.' );
	}

	public function test_an_answer_with_status_zero_inside_a_200_is_val_002_with_its_text(): void {
		$this->server->failWriteAfter( 1, 'status_zero' );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'Contact not found', $result->error->context['reason'] );
		$this->assertCount( 0, $this->server->all( 'invoice' ) );
	}

	public function test_a_rate_limit_is_rate_001_with_the_wait_holded_asks_for(): void {
		$this->server->failNextWrite( 'rate_limit', 17 );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 17, $result->error->retryAfter );
	}

	public function test_a_monthly_quota_is_rate_001_with_a_long_wait(): void {
		$this->server->failNextWrite( 'month_limit', 864000 );
		$result = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertGreaterThanOrEqual( 3600, $result->error->retryAfter );
	}

	public function test_lists_are_read_page_by_page(): void {
		$this->server->pageSize = 2;
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->server->tables['contacts'][ 'c7' . $i ] = array( 'id' => 'c7' . $i, 'name' => 'Other ' . $i, 'email' => "nuria{$i}@example.com.es" );
		}
		$this->server->tables['contacts']['c80'] = array( 'id' => 'c80', 'name' => 'Núria', 'email' => 'nuria@example.com' );
		$result                                  = $this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'c80', $this->server->all( 'invoice' )[0]['contact'] );
		$this->assertCount( 6, $this->server->all( 'contacts' ), 'The existing contact was found on the last page.' );
	}

	public function test_a_customer_without_a_name_is_refused_with_the_field_before_any_request(): void {
		$payload                        = $this->payload();
		$payload['customer']['name']    = '   ';
		$payload['customer']['company'] = '';
		$result                         = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'customer.name', $result->error->context['field'] );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_the_order_number_is_in_the_notes_when_it_differs_from_the_id(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'number' => 'WEB-2026-77' ) ) );
		$notes = (string) $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['notes'];
		$this->assertStringContainsString( 'WEB-2026-77', $notes );
		$this->assertStringStartsWith( '[WC-10490] ', $notes );
	}

	public function test_a_very_long_note_never_cuts_the_marker_off(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload( array( 'note' => str_repeat( 'ñ', 5000 ) ) ) );
		$notes = (string) $this->bodyOf( $this->requests( 'POST', '#/documents/invoice$#' )[0] )['notes'];
		$this->assertStringStartsWith( '[WC-10490] 96.78 EUR', $notes );
		$this->assertLessThanOrEqual( 2000, mb_strlen( $notes ) );
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

	public function test_secrets_never_appear_in_what_the_connector_writes(): void {
		$this->connector()->send( $this->connection(), $this->item(), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakeHoldedServer::KEY, (string) $request['body'] );
			$this->assertStringNotContainsString( FakeHoldedServer::KEY, $request['url'] );
		}
	}
}
