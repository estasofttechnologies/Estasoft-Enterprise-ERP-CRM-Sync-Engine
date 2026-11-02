<?php
/**
 * Sending a refund to Holded as a credit note.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

final class HoldedRefundFlowTest extends HoldedTestCase {

	/**
	 * Sync the order the refunds belong to (79.98 net plus 21 percent, 96.78).
	 *
	 * @param array<string,mixed> $config Connection options.
	 */
	private function syncOrder( array $config = array() ): void {
		$result = $this->connector()->send( $this->connection( $config ), $this->item(), $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	private function refundItem( string $id = '501' ): Item {
		return $this->item( EntityType::Refund, $id, 'refunded', '10490' );
	}

	public function test_a_refund_becomes_a_credit_note_for_the_contact_of_the_order(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 'R26-0001', $result->ref->ref );
		$this->assertSame( 4839, $result->ref->extra['total_minor'] );
		$this->assertSame( 'creditnote', $result->ref->extra['document'] );

		$posts = $this->requests( 'POST', '#/documents/creditnote$#' );
		$this->assertCount( 1, $posts );
		$body  = $this->bodyOf( $posts[0] );
		$lines = explode( "\n", $body['notes'] );
		$this->assertSame( '[WC-R-501] 48.39 EUR', $lines[0] );
		$this->assertSame( 'Refund of WC-10490: Damaged in transit', $lines[1] );
		$this->assertSame( $this->server->all( 'invoice' )[0]['contact'], $body['contactId'] );
		$this->assertSame( strtotime( '2026-11-03 12:00:00 UTC' ), $body['date'] );
		$this->assertSame( 'eur', $body['currency'] );
		$this->assertSame( array( 'name' => 'Ceramic mug', 'units' => 1, 'subtotal' => 39.99, 'tax' => 21, 'sku' => 'CS-MUG-01' ), $body['items'][0] );
	}

	public function test_a_second_send_of_the_same_refund_changes_nothing(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$first     = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$writes    = count( $this->requests( 'POST', '#.#' ) );
		$second    = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, count( $this->requests( 'POST', '#.#' ) ) );
		$this->assertCount( 1, $this->server->all( 'creditnote' ) );
		$this->assertInstanceOf( ExternalRef::class, $connector->findExisting( $this->connection(), $this->refundItem() ) );
	}

	public function test_a_lost_answer_does_not_create_a_second_credit_note(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$this->server->loseNextAnswer();
		$first = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertCount( 1, $this->server->all( 'creditnote' ) );

		$retry = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'creditnote' ) );
	}

	public function test_two_refunds_of_one_order_make_two_credit_notes(): void {
		$this->syncOrder();
		$connector = $this->connector();
		$one       = $connector->send( $this->connection(), $this->refundItem( '501' ), $this->refundPayload() );
		$two       = $connector->send( $this->connection(), $this->refundItem( '502' ), $this->refundPayload() );
		$this->assertTrue( $one->isSuccess() );
		$this->assertTrue( $two->isSuccess() );
		$this->assertFalse( $two->alreadyExisted );
		$this->assertNotSame( $one->ref->id, $two->ref->id );
		$this->assertCount( 2, $this->server->all( 'creditnote' ) );
	}

	public function test_shipping_and_other_amounts_are_lines_without_tax_and_the_total_matches(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 4839 + 2875 + 100, 'shipping_amount' => 2875, 'other_amount' => 100 ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/documents/creditnote$#' )[0] )['items'];
		$this->assertCount( 3, $items );
		$this->assertSame( 'Shipping', $items[1]['name'] );
		$this->assertSame( 0, $items[1]['tax'] );
		$this->assertSame( 7814, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_without_lines_is_one_line_for_the_amount_and_left_out_products_are_not_refunded(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 3000, 'excluded_amount' => 500, 'lines' => array() ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$items = $this->bodyOf( $this->requests( 'POST', '#/documents/creditnote$#' )[0] )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 25.0, $items[0]['subtotal'] );
		$this->assertSame( 2500, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_line_whose_product_is_gone_from_holded_is_still_refunded_as_text(): void {
		$this->syncOrder();
		$payload                    = $this->refundPayload();
		$payload['lines'][0]['sku'] = 'WITHDRAWN-1';
		$result                     = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertArrayNotHasKey( 'sku', $this->bodyOf( $this->requests( 'POST', '#/documents/creditnote$#' )[0] )['items'][0] );
	}

	public function test_a_refund_for_an_order_holded_does_not_have_is_refused_before_anything_is_written(): void {
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order', $result->error->context['field'] );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_the_order_is_looked_for_around_its_date_and_otherwise_in_the_last_ninety_days(): void {
		$this->syncOrder();
		$with = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $with->isSuccess() );
		$lists = $this->requests( 'GET', '#/documents/invoice\?#' );
		$last  = end( $lists );
		parse_str( (string) parse_url( $last['url'], PHP_URL_QUERY ), $query );
		$day = strtotime( '2026-11-02 00:00:00 UTC' );
		$this->assertSame( $day - 86400, (int) $query['starttmp'] );

		$without = $this->connector()->send( $this->connection(), $this->refundItem( '502' ), $this->refundPayload( array( 'order_date' => '' ) ) );
		$this->assertTrue( $without->isSuccess(), (string) $without->error?->message() );
		$lists = $this->requests( 'GET', '#/documents/invoice\?#' );
		$last  = end( $lists );
		parse_str( (string) parse_url( $last['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( strtotime( '2026-11-04 00:00:00 UTC' ) - 90 * 86400, (int) $query['starttmp'] );
	}

	public function test_an_order_older_than_ninety_days_is_not_found_without_its_date(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'order_date' => '', 'date' => '2027-03-01' ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'order', $result->error->context['field'] );
	}

	public function test_a_refund_of_a_sales_order_works_and_carries_a_warning(): void {
		$config = array( 'document_type' => 'salesorder' );
		$this->syncOrder( $config );
		$result = $this->connector()->send( $this->connection( $config ), $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_tax_that_no_usual_rate_explains_is_map_002(): void {
		$this->syncOrder();
		$payload = $this->refundPayload( array( 'amount' => 4122, 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 3999, 'tax_amount' => 123 ) ) ) );
		$result  = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'creditnote' ) );
	}

	public function test_a_refund_without_an_order_reference_is_refused_with_the_field_name(): void {
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'order_ref' => '' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_a_total_that_the_lines_do_not_reach_stops_before_the_credit_note_is_made(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'amount' => 5000 ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '48.39', $result->error->context['reason'] );
		$this->assertStringContainsString( '50.00', $result->error->context['reason'] );
		$this->assertCount( 0, $this->server->all( 'creditnote' ) );
	}

	public function test_a_credit_note_that_comes_out_with_another_total_stays_and_is_never_linked_as_finished(): void {
		$this->syncOrder();
		$connector          = $this->connector();
		$this->server->skew = 500;
		$result             = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'R26-0001', $result->error->context['reason'] );
		$this->assertCount( 1, $this->server->all( 'creditnote' ) );
		$this->assertCount( 0, $this->requests( 'DELETE', '#.#' ), 'A numbered credit note is not deleted by the connector.' );
		$this->assertNull( $connector->findExisting( $this->connection(), $this->refundItem() ) );

		$again = $connector->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'creditnote' ), 'No second credit note.' );
	}

	public function test_the_currency_of_the_order_document_is_used_when_the_refund_has_none(): void {
		$this->syncOrder();
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'currency' => '' ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'eur', $this->bodyOf( $this->requests( 'POST', '#/documents/creditnote$#' )[0] )['currency'] );
	}

	public function test_a_failure_from_holded_is_reported_and_leaves_no_credit_note(): void {
		$this->syncOrder();
		$this->server->failNextWrite( 'server_error' );
		$result = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Net002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'creditnote' ) );
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() )->isSuccess() );
	}

	public function test_a_refund_costs_eight_calls_when_nothing_is_kept(): void {
		$this->syncOrder();
		$before = count( $this->kit->http->requests );
		$this->assertTrue( $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() )->isSuccess() );
		$this->assertSame( 8, count( $this->kit->http->requests ) - $before, 'Credit note window, order window and its empty page, the order, products and their empty page, create, read back.' );
	}

	public function test_find_existing_for_a_refund_reads_credit_notes_of_the_last_thirty_days(): void {
		$this->connector()->findExisting( $this->connection(), $this->refundItem() );
		$list = $this->requests( 'GET', '#/documents/creditnote\?#' )[0];
		parse_str( (string) parse_url( $list['url'], PHP_URL_QUERY ), $query );
		$now = strtotime( '2026-11-02 12:00:00 UTC' );
		$this->assertSame( $now - 30 * 86400, (int) $query['starttmp'] );
	}
}
