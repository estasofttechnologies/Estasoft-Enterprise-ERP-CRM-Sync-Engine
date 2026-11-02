<?php
/**
 * The order flow of the Sage connector: find, resolve, create a draft, check, release, pay.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeSageAccountingServer;

final class SageOrderFlowTest extends SageTestCase {

	private function send( string $id, array $merge = array(), ?array $options = null, int $attempt = 0 ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $id, \Estasoft\SyncEngine\Domain\EntityType::Order, $attempt ), $this->payload( $id, $merge ) );
	}

	/**
	 * The invoice creates the connector made, in order.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Tests\Support\ApiFakeRequest>
	 */
	private function creates(): array {
		return $this->sage->calls( 'POST', '/sales_invoices' );
	}

	public function test_an_order_becomes_a_released_invoice_with_the_shops_reference_date_and_total(): void {
		$result = $this->send( '1042' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$invoice = $this->sage->byReference( 'invoice', 'WC-1042' );
		$this->assertSame( 'UNPAID', $invoice['status']['id'] );
		$this->assertSame( '2026-11-01', $invoice['date'], 'The shop\'s date, not the day of the sync.' );
		$this->assertSame( $invoice['id'], $result->ref->id );
		$this->assertSame( 'SI-1', $result->ref->ref, 'The invoice number Sage gave it on release.' );
		$this->assertSame( 10080, $result->ref->extra['total_minor'], '2 x 42.00 plus 20 percent VAT.' );
		$this->assertSame( 10080, $result->ref->extra['outstanding_minor'] );
		$this->assertSame( 'GBP', $result->ref->extra['currency'] );
		$this->assertSame( 'UNPAID', $result->ref->extra['status'] );
	}

	public function test_the_invoice_is_made_as_a_draft_with_the_tax_worked_out_and_then_released(): void {
		$this->send( '1043' );

		$creates = $this->creates();
		$this->assertCount( 1, $creates );
		$body = $creates[0]->json()['sales_invoice'];
		$this->assertSame( 'DRAFT', $body['status_id'] );
		$this->assertSame( 'WC-1043', $body['reference'] );
		$this->assertSame( '2026-11-01', $body['date'] );
		$this->assertSame( '2026-11-01', $body['due_date'] );
		$line = $body['invoice_lines'][0];
		$this->assertSame( 'Mug', $line['description'] );
		$this->assertSame( 'led-4000', $line['ledger_account_id'] );
		$this->assertSame( '2', $line['quantity'] );
		$this->assertSame( '42.00', $line['unit_price'] );
		$this->assertSame( 'GB_STANDARD', $line['tax_rate_id'] );
		$this->assertSame( '16.80', $line['tax_amount'], 'Sage does not work tax out; the caller does.' );
		$this->assertSame( 'CS-MUG-01', $this->sage->store['product'][ $line['product_id'] ]['item_code'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $body['idempotency_id'] );
		$this->assertCount( 1, $this->sage->calls( 'POST', '/sales_invoices/[^/]+/release' ) );
	}

	public function test_every_call_carries_the_business_and_the_bearer_token(): void {
		$this->send( '1044' );

		foreach ( $this->sage->server->received as $request ) {
			if ( 0 !== strpos( $request->path, '/v3.1/' ) ) {
				continue;
			}
			$this->assertSame( FakeSageAccountingServer::BUSINESS, $request->header( 'X-Business' ), $request->path );
			$this->assertStringStartsWith( 'Bearer sage-access-', (string) $request->header( 'Authorization' ), $request->path );
		}
	}

	public function test_the_reference_is_searched_before_every_create_and_a_repeat_makes_no_second_invoice(): void {
		$first  = $this->send( '1045' );
		$second = $this->send( '1045' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->creates() );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$search = $this->sage->calls( 'GET', '/sales_invoices' )[0];
		$this->assertSame( 'WC-1045', $search->query['search'] );
		$this->assertSame( '200', (string) $search->query['items_per_page'] );
	}

	public function test_a_lost_answer_is_not_a_second_invoice(): void {
		$this->sage->addContact( 'Layla Haddad', 'layla@example.test' );
		$this->sage->loseNextAnswer = true;
		$lost                       = $this->send( '1046' );
		$this->assertFalse( $lost->isSuccess() );
		$this->assertCount( 1, $this->sage->store['invoice'], 'Sage did make the draft; only the answer was lost.' );

		$retry = $this->send( '1046' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted, 'The draft was found by its reference and finished.' );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$this->assertSame( 'UNPAID', $this->sage->byReference( 'invoice', 'WC-1046' )['status']['id'] );
	}

	public function test_a_lost_answer_for_the_contact_is_not_a_second_contact(): void {
		$this->sage->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1047' )->isSuccess() );

		$retry = $this->send( '1047' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->sage->store['contact'], 'The contact made by the first pass was found by its reference.' );
	}

	public function test_a_draft_that_a_failed_pass_left_behind_is_checked_and_released_not_made_again(): void {
		$first = $this->send( '1048', array(), array( 'documents' => 'invoice_draft' ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertSame( 'DRAFT', $this->sage->byReference( 'invoice', 'WC-1048' )['status']['id'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connect( array( 'documents' => 'invoice_draft' ) ), $this->item( '1048' ) ), 'A draft is all that was asked for.' );
		$this->assertNull( $this->connector->findExisting( $this->connection, $this->item( '1048' ) ), 'A released invoice was asked for.' );

		$result = $this->send( '1048' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 'UNPAID', $this->sage->byReference( 'invoice', 'WC-1048' )['status']['id'] );
		$this->assertCount( 1, $this->creates() );
	}

	public function test_a_total_that_differs_from_the_shops_asks_sage_to_void_the_draft_and_says_plainly_that_it_stays_when_sage_does_not(): void {
		$result = $this->send( '1049', array( 'total' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '99.99', $result->error->message() );
		$this->assertStringContainsString( '100.80', $result->error->message() );
		$this->assertStringContainsString( 'still in Sage', $result->error->message(), 'The guide documents voiding from UNPAID only; the draft stayed, and the message says so.' );
		$this->assertSame( 'DRAFT', $this->sage->byReference( 'invoice', 'WC-1049' )['status']['id'] );
		$this->assertSame( array(), $this->sage->calls( 'POST', '/sales_invoices/[^/]+/release' ), 'Nothing was released.' );
		$delete = $this->sage->calls( 'DELETE', '/sales_invoices/[^/]+' );
		$this->assertCount( 1, $delete );
		$this->assertSame( array( 'void_reason' => 'Created with the wrong total by the WooCommerce sync' ), $delete[0]->json(), 'The guide: the body must include a single param, void_reason.' );

		$again = $this->send( '1049', array( 'total' => 9999 ) );
		$this->assertFalse( $again->isSuccess() );
		$this->assertStringContainsString( 'still in Sage', $again->error->message() );
		$this->assertCount( 1, $this->creates(), 'The draft that is still there is found; a second one is not made.' );
		$this->assertCount( 1, $this->sage->calls( 'DELETE', '/sales_invoices/[^/]+' ), 'A draft found on a later pass is not touched.' );
	}

	public function test_a_draft_sage_will_not_void_is_released_by_the_next_pass_when_the_total_is_right(): void {
		$this->send( '1049', array( 'total' => 9999 ) );

		$retry = $this->send( '1049' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$this->assertSame( 'UNPAID', $this->sage->byReference( 'invoice', 'WC-1049' )['status']['id'] );
	}

	public function test_when_sage_voids_the_draft_the_next_create_has_a_new_idempotency_id_that_includes_the_voided_id(): void {
		$this->sage->draftsCanBeVoided = true;
		$result                        = $this->send( '1050', array( 'total' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringNotContainsString( 'still in Sage', $result->error->message() );
		$voided = $this->sage->byReference( 'invoice', 'WC-1050' );
		$this->assertSame( 'VOID', $voided['status']['id'] );
		$this->assertSame( 'Created with the wrong total by the WooCommerce sync', $voided['void_reason'] );

		$retry = $this->send( '1050' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$this->assertCount( 2, $this->sage->store['invoice'], 'The voided one stays in Sage; a new one was made.' );
		$ids = array_map( static fn( $r ): string => (string) $r->json()['sales_invoice']['idempotency_id'], $this->creates() );
		$this->assertCount( 2, $ids );
		$this->assertNotSame( $ids[0], $ids[1] );
		$third = $this->send( '1050' );
		$this->assertTrue( $third->alreadyExisted );
	}

	public function test_a_retry_after_a_time_out_sends_the_same_idempotency_id_and_gets_the_first_invoice_not_a_second(): void {
		$this->sage->addContact( 'Layla Haddad', 'layla@example.test' );
		$this->sage->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1051' )->isSuccess() );
		$this->sage->hideDocuments = 1;

		$retry = $this->send( '1051', array(), null, 3 );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->sage->store['invoice'], 'The search did not see the first invoice yet; the same idempotency id made Sage answer with it.' );
		$ids = array_map( static fn( $r ): string => (string) $r->json()['sales_invoice']['idempotency_id'], $this->creates() );
		$this->assertCount( 2, $ids );
		$this->assertSame( $ids[0], $ids[1], 'A later queue attempt does not change the id.' );
	}

	public function test_a_replayed_answer_about_a_draft_that_a_person_deleted_is_seen_to_be_dead_and_a_new_idempotency_id_is_used(): void {
		$this->sage->addContact( 'Layla Haddad', 'layla@example.test' );
		$this->sage->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1052' )->isSuccess() );
		unset( $this->sage->store['invoice'][ $this->sage->byReference( 'invoice', 'WC-1052' )['id'] ] );

		$retry = $this->send( '1052' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$this->assertCount( 3, $this->creates(), 'The first create, the same request again (Sage replayed the deleted draft), and a new one.' );
	}

	public function test_warn_mode_keeps_the_invoice_and_reports_the_difference(): void {
		$result = $this->send( '1051', array( 'total' => 9999 ), array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	/**
	 * An order whose prices include tax, sent from a business in a country.
	 *
	 * @param string              $id      Order id.
	 * @param string              $country Country code.
	 * @param array<string,mixed> $merge   Values merged over the payload.
	 * @return \Estasoft\SyncEngine\Contract\SendResult
	 */
	private function sendInclusive( string $id, string $country, array $merge = array() ) {
		$this->sage->country = $country;
		$connection          = $this->connect( array(), 20 + strlen( $country ), array( 'country' => $country ) );
		return $this->connector->send(
			$connection,
			$this->item( $id ),
			$this->payload( $id, array_replace_recursive( array( 'prices_include_tax' => true, 'order_line' => array( array( 'price_unit' => 5040 ) ) ), $merge ) )
		);
	}

	public function test_in_the_uk_ireland_and_france_prices_with_tax_are_sent_with_the_flag_and_no_tax_amount_and_sage_works_the_tax_out(): void {
		foreach ( array( 'gb', 'ie', 'fr' ) as $index => $country ) {
			$id     = (string) ( 1100 + $index );
			$result = $this->sendInclusive( $id, $country );

			$this->assertTrue( $result->isSuccess(), $country . ': ' . (string) $result->error?->message() );
			$line = $this->creates()[ $index ]->json()['sales_invoice']['invoice_lines'][0];
			$this->assertTrue( $line['unit_price_includes_tax'], $country );
			$this->assertSame( '50.40', $line['unit_price'], $country );
			$this->assertArrayNotHasKey( 'tax_amount', $line, $country . ': Sage works it out.' );
			$this->assertSame( 10080, $result->ref->extra['total_minor'], $country );
			$this->assertSame( '16.80', $this->sage->byReference( 'invoice', 'WC-' . $id )['tax_amount'], $country );
		}
	}

	public function test_shipping_that_includes_tax_is_converted_to_net_because_it_has_no_flag(): void {
		$result = $this->sendInclusive( '1103', 'gb', array( 'shipping' => array( 'total' => 1200, 'tax' => 'GB_STANDARD' ), 'total' => 11280 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->creates()[0]->json()['sales_invoice'];
		$this->assertSame( '10.00', $body['shipping_net_amount'], '12.00 with 20 percent in it is 10.00 and 2.00.' );
		$this->assertSame( '2.00', $body['shipping_tax_amount'] );
		$this->assertSame( 11280, $result->ref->extra['total_minor'] );
	}

	public function test_in_canada_prices_with_tax_are_converted_to_net_and_the_tax_amount_is_sent(): void {
		$result = $this->sendInclusive( '1104', 'ca' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertArrayNotHasKey( 'unit_price_includes_tax', $line, 'The flag does not apply in Canada and Spain.' );
		$this->assertSame( '2', $line['quantity'] );
		$this->assertSame( '42.00', $line['unit_price'], '100.80 with 20 percent in it is 84.00 net, 42.00 each.' );
		$this->assertSame( '16.80', $line['tax_amount'] );
		$this->assertSame( 10080, $result->ref->extra['total_minor'] );
	}

	public function test_in_spain_prices_are_converted_to_net_and_no_tax_amount_is_sent_at_all(): void {
		$result = $this->sendInclusive( '1105', 'es' );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertArrayNotHasKey( 'tax_amount', $line, 'A Spanish business may not send one.' );
		$this->assertSame( '42.00', $line['unit_price'] );
		$this->assertSame( 10080, $result->ref->extra['total_minor'] );

		$without = $this->connector->send( $this->connect( array(), 31, array( 'country' => 'es' ) ), $this->item( '1106' ), $this->payload( '1106' ) );
		$this->assertTrue( $without->isSuccess(), (string) $without->error?->message() );
		$this->assertArrayNotHasKey( 'tax_amount', $this->creates()[1]->json()['sales_invoice']['invoice_lines'][0], 'Also for prices without tax.' );
		$this->assertSame( 10080, $without->ref->extra['total_minor'] );
	}

	public function test_a_converted_line_that_cannot_add_up_exactly_is_off_by_a_cent_and_the_check_allows_one_cent_a_line(): void {
		$result = $this->sendInclusive( '1107', 'ca', array( 'order_line' => array( array( 'quantity' => 3, 'price_unit' => 1999 ) ), 'total' => 5997 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertSame( '3', $line['quantity'] );
		$this->assertSame( '16.66', $line['unit_price'], '59.97 with 20 percent in it: net 49.975 rounds to 49.98, 16.66 each.' );
		$this->assertSame( 5998, $result->ref->extra['total_minor'], 'One cent more than the shop: no net and tax add up to 59.97.' );
	}

	public function test_a_converted_line_whose_net_does_not_divide_by_the_quantity_is_sent_as_one_line_of_the_whole_net(): void {
		$result = $this->sendInclusive( '1108', 'ca', array( 'order_line' => array( array( 'quantity' => 3, 'price_unit' => 333, 'name' => 'Pen' ) ), 'total' => 999 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertSame( '1', $line['quantity'] );
		$this->assertSame( 'Pen (3)', $line['description'] );
		$this->assertSame( '8.33', $line['unit_price'], '9.99 with 20 percent in it is 8.325 net, rounded away from zero.' );
		$this->assertSame( 1000, $result->ref->extra['total_minor'], '8.33 + 1.67: one cent more than the shop, inside the one cent a line the check allows.' );
	}

	public function test_a_total_far_from_the_shops_is_still_caught_for_prices_with_tax(): void {
		$result = $this->sendInclusive( '1109', 'gb', array( 'total' => 9000 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '90.00', $result->error->message() );
	}

	public function test_an_order_in_another_currency_is_stopped_before_anything_is_created(): void {
		$result = $this->send( '1053', array( 'currency' => 'USD' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'GBP', $result->error->message() );
		$this->assertSame( array(), $this->sage->writes() );
	}

	public function test_a_zero_rate_sends_no_tax_amount_and_a_line_without_tax_uses_the_no_tax_rate(): void {
		$zero = $this->send( '1054', array( 'order_line' => array( array( 'tax' => 'GB_ZERO' ) ), 'total' => 8400 ) );
		$this->assertTrue( $zero->isSuccess(), (string) $zero->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertArrayNotHasKey( 'tax_amount', $line, 'Left out for a zero rate.' );

		$none = $this->send( '1055', array( 'order_line' => array( array( 'tax' => '' ) ), 'total' => 8400 ) );
		$this->assertTrue( $none->isSuccess(), (string) $none->error?->message() );
		$this->assertSame( 'GB_NO_TAX', $this->creates()[1]->json()['sales_invoice']['invoice_lines'][0]['tax_rate_id'] );

		$named = $this->send( '1056', array( 'order_line' => array( array( 'tax' => '' ) ), 'total' => 8400 ), array( 'no_tax_rate' => 'GB_ZERO' ) );
		$this->assertTrue( $named->isSuccess(), (string) $named->error?->message() );
		$this->assertSame( 'GB_ZERO', $this->creates()[2]->json()['sales_invoice']['invoice_lines'][0]['tax_rate_id'] );
	}

	public function test_a_line_without_any_tax_key_is_refused_because_sage_needs_a_rate_on_every_line(): void {
		$result = $this->connector->send( $this->connection, $this->item( '1057' ), array_replace( $this->payload( '1057' ), array( 'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 4200 ) ), 'total' => null ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Line 1', $result->error->message() );
		$this->assertSame( array(), $this->sage->writes() );
	}

	public function test_a_tax_rate_sage_does_not_have_stops_the_order_before_anything_is_created(): void {
		$result = $this->send( '1058', array( 'order_line' => array( array( 'tax' => 'GB_NOPE' ) ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertStringContainsString( 'GB_NOPE', $result->error->message() );
		$this->assertSame( array(), $this->sage->writes() );

		$retired = $this->send( '1059', array( 'order_line' => array( array( 'tax' => 'GB_OLD' ) ) ) );
		$this->assertSame( ErrorCode::Map002, $retired->error->code, 'A retired rate counts as unknown.' );
	}

	public function test_tax_is_worked_out_per_line_in_whole_cents_half_away_from_zero(): void {
		$result = $this->send(
			'1060',
			array(
				'order_line' => array( array( 'quantity' => 3, 'price_unit' => 333, 'tax' => 'GB_REDUCED' ) ),
				'total'      => 1049,
			)
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertSame( '0.50', $line['tax_amount'], '9.99 x 5 percent = 0.4995, half away from zero gives 0.50.' );
		$this->assertSame( 1049, $result->ref->extra['total_minor'] );
	}

	public function test_an_unknown_sku_is_refused_before_anything_is_sent_unless_free_text_is_chosen(): void {
		$refused = $this->send( '1061', array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU' ) ) ) );
		$this->assertFalse( $refused->isSuccess() );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( array(), $this->sage->writes(), 'No contact and no invoice were made for a product that is not there.' );

		$free = $this->send( '1062', array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU', 'name' => 'Made to order frame' ) ) ), array( 'unknown_sku' => 'free_text' ) );
		$this->assertTrue( $free->isSuccess(), (string) $free->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertSame( 'Made to order frame', $line['description'] );
		$this->assertArrayNotHasKey( 'product_id', $line );

		$retired = $this->send( '1063', array( 'order_line' => array( array( 'product' => 'WC-RETIRED' ) ) ) );
		$this->assertSame( ErrorCode::Map001, $retired->error->code, 'A retired product counts as unknown.' );
	}

	public function test_a_ledger_account_that_is_missing_or_unknown_is_named_before_anything_is_created(): void {
		$none = $this->connector->send( $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'business_id' => FakeSageAccountingServer::BUSINESS, 'country' => 'gb' ), $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() ), 3 ), $this->item( '1064' ), $this->payload( '1064' ) );
		$this->assertFalse( $none->isSuccess() );
		$this->assertStringContainsString( 'sales_ledger_account', $none->error->message() );
		$this->assertSame( array(), $this->sage->server->received );

		$unknown = $this->send( '1065', array(), array( 'sales_ledger_account' => '9999' ) );
		$this->assertFalse( $unknown->isSuccess() );
		$this->assertStringContainsString( '9999', $unknown->error->message() );
		$this->assertSame( array(), $this->sage->writes() );
	}

	public function test_shipping_uses_the_shipping_fields_and_fees_and_discounts_are_lines_on_their_accounts(): void {
		$result = $this->send(
			'1066',
			array(
				'shipping' => array( 'total' => 1000, 'tax' => 'GB_STANDARD' ),
				'fee'      => array( array( 'total' => 500, 'tax' => 'GB_ZERO', 'name' => 'Gift wrap' ) ),
				'discount' => array( 'total' => 300, 'tax' => 'GB_STANDARD', 'name' => 'Spring code' ),
				'total'    => 10080 + 1200 + 500 - 360,
			),
			array( 'fee_ledger_account' => '4900' )
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->creates()[0]->json()['sales_invoice'];
		$this->assertSame( '10.00', $body['shipping_net_amount'] );
		$this->assertSame( 'GB_STANDARD', $body['shipping_tax_rate_id'] );
		$this->assertSame( '2.00', $body['shipping_tax_amount'] );
		$this->assertCount( 3, $body['invoice_lines'] );
		$this->assertSame( 'Gift wrap', $body['invoice_lines'][1]['description'] );
		$this->assertSame( 'led-4900', $body['invoice_lines'][1]['ledger_account_id'] );
		$this->assertSame( 'led-4000', $body['invoice_lines'][2]['ledger_account_id'], 'A discount without its own account uses the sales account.' );
		$this->assertSame( '-3.00', $body['invoice_lines'][2]['unit_price'] );
		$this->assertSame( '-0.60', $body['invoice_lines'][2]['tax_amount'] );
		$this->assertSame( 11420, $result->ref->extra['total_minor'] );
	}

	public function test_a_line_discount_is_sent_as_an_amount_and_the_tax_is_on_what_is_left(): void {
		$result = $this->send( '1067', array( 'order_line' => array( array( 'discount' => 12.5 ) ), 'total' => 8820 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['sales_invoice']['invoice_lines'][0];
		$this->assertSame( '10.50', $line['discount_amount'], '12.5 percent of 84.00.' );
		$this->assertSame( '14.70', $line['tax_amount'], '20 percent of 73.50.' );
		$this->assertSame( 8820, $result->ref->extra['total_minor'] );
	}

	public function test_a_contact_with_the_email_as_reference_or_email_is_reused_whatever_the_case(): void {
		$byReference = $this->sage->addContact( 'L. Haddad', 'LAYLA@Example.Test' );

		$result = $this->send( '1068' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $this->sage->store['contact'] );
		$this->assertSame( $byReference['id'], $this->creates()[0]->json()['sales_invoice']['contact_id'] );

		$this->sage->addContact( 'Noor', '', array( 'email' => 'noor@example.test' ) );
		$byEmail = $this->send( '1069', array( 'partner' => array( 'email' => 'Noor@example.test' ) ) );
		$this->assertTrue( $byEmail->isSuccess() );
		$this->assertCount( 2, $this->sage->store['contact'] );
	}

	public function test_a_new_contact_is_a_customer_with_the_email_as_reference_and_addresses_in_sages_shape(): void {
		$this->send( '1070', array( 'delivery' => array( 'street' => '9 Other Road', 'city' => 'Leeds', 'zip' => 'LS1 1AA', 'country_id' => 'GB' ) ) );

		$contact = array_values( $this->sage->store['contact'] )[0];
		$this->assertSame( array( 'CUSTOMER' ), $contact['contact_type_ids'] );
		$this->assertSame( 'Layla Haddad', $contact['name'] );
		$this->assertSame( 'layla@example.test', $contact['reference'] );
		$this->assertSame( 'layla@example.test', $contact['email'] );
		$this->assertSame( 'ACCOUNTS', $contact['main_address']['address_type_id'] );
		$this->assertSame( '1 Demo Street', $contact['main_address']['address_line_1'] );
		$this->assertSame( 'N1 9GU', $contact['main_address']['postal_code'] );
		$this->assertSame( 'GB', $contact['main_address']['country_group_id'] );
		$this->assertSame( 'DELIVERY', $contact['delivery_address']['address_type_id'] );
		$this->assertSame( '9 Other Road', $contact['delivery_address']['address_line_1'] );
	}

	public function test_an_email_longer_than_the_reference_limit_is_kept_as_the_email_only(): void {
		$long = str_repeat( 'a', 45 ) . '@example.test';
		$this->send( '1071', array( 'partner' => array( 'email' => $long ) ) );

		$contact = array_values( $this->sage->store['contact'] )[0];
		$this->assertSame( '', $contact['reference'], 'No reference is sent for an email that is too long.' );
		$this->assertArrayNotHasKey( 'reference', $this->sage->calls( 'POST', '/contacts' )[0]->json()['contact'] );
		$this->assertSame( $long, $contact['email'] );
		$this->send( '1072', array( 'partner' => array( 'email' => $long ) ) );
		$this->assertCount( 1, $this->sage->store['contact'], 'Found again by the email field.' );
	}

	public function test_the_payment_is_recorded_once_for_the_amount_outstanding(): void {
		$options = array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'Current Account (1200)' );
		$pay     = array( 'payment' => array( 'reference' => 'ch_3Pq0', 'date' => '2026-11-01' ) );

		$result = $this->send( '1073', $pay, $options );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'PAID', $result->ref->extra['status'] );
		$this->assertSame( 0, $result->ref->extra['outstanding_minor'] );
		$payments = array_values( $this->sage->store['payment'] );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'CUSTOMER_RECEIPT', $payments[0]['transaction_type_id'] );
		$this->assertSame( 'bank-1200', $payments[0]['bank_account_id'] );
		$this->assertSame( '100.80', $payments[0]['total_amount'] );
		$this->assertSame( 'ch_3Pq0', $payments[0]['reference'] );
		$this->assertSame( '2026-11-01', $payments[0]['date'] );
		$this->assertSame( $result->ref->id, $payments[0]['allocated_artefacts'][0]['artefact_id'] );

		$again = $this->send( '1073', $pay, $options );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->sage->store['payment'], 'A repeat does not pay twice.' );
	}

	public function test_a_payment_reference_longer_than_the_limit_is_cut_to_twenty_five_characters(): void {
		$result = $this->send( '1074', array( 'payment' => array( 'reference' => str_repeat( 'x', 40 ), 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'bank-1200' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 25, mb_strlen( array_values( $this->sage->store['payment'] )[0]['reference'] ) );
	}

	public function test_a_bank_account_that_is_missing_or_unknown_is_named_and_the_invoice_stays(): void {
		$pay = array( 'payment' => array( 'date' => '2026-11-01' ) );

		$none = $this->send( '1075', $pay, array( 'documents' => 'invoice_paid' ) );
		$this->assertFalse( $none->isSuccess() );
		$this->assertStringContainsString( 'payment_bank_account', $none->error->message() );
		$this->assertSame( 'UNPAID', $this->sage->byReference( 'invoice', 'WC-1075' )['status']['id'], 'The invoice was released; only the payment is missing.' );

		$unknown = $this->send( '1076', $pay, array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'Nowhere' ) );
		$this->assertStringContainsString( 'Nowhere', $unknown->error->message() );
		$this->assertSame( array(), $this->sage->store['payment'] );
	}

	public function test_an_unpaid_order_gets_no_payment(): void {
		$result = $this->send( '1077', array(), array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'bank-1200' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( array(), $this->sage->store['payment'] );
		$this->assertSame( 10080, $result->ref->extra['outstanding_minor'] );
	}

	public function test_a_payment_that_failed_is_made_on_the_next_pass_without_a_second_invoice(): void {
		$pay     = array( 'payment' => array( 'date' => '2026-11-01' ) );
		$options = array( 'documents' => 'invoice_paid', 'payment_bank_account' => 'bank-1200' );
		$this->send( '1078', $pay );
		$this->sage->failNextWrite( 'server_error' );

		$failed = $this->send( '1078', $pay, $options );
		$this->assertFalse( $failed->isSuccess() );
		$this->assertSame( array(), $this->sage->store['payment'] );
		$this->assertNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '1078' ) ), 'The payment is still missing.' );

		$retry = $this->send( '1078', $pay, $options, 1 );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->sage->store['payment'] );
		$this->assertCount( 1, $this->sage->store['invoice'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '1078' ) ) );
	}

	public function test_the_due_date_is_the_order_date_plus_the_days_in_the_options(): void {
		$this->send( '1079', array(), array( 'due_days' => 30 ) );

		$this->assertSame( '2026-12-01', $this->creates()[0]->json()['sales_invoice']['due_date'] );
	}

	public function test_the_note_becomes_the_invoice_notes(): void {
		$this->send( '1080', array( 'note' => 'Leave with the neighbour' ) );

		$this->assertSame( 'Leave with the neighbour', $this->sage->byReference( 'invoice', 'WC-1080' )['notes'] );
	}

	public function test_a_voided_invoice_stops_the_item_and_a_second_one_is_not_made(): void {
		$this->sage->store['invoice']['si-900'] = array( 'id' => 'si-900', 'reference' => 'WC-1081', 'status' => array( 'id' => 'VOID' ), 'date' => '2026-11-01', 'total_amount' => '0.00' );

		$result = $this->send( '1081' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'voided', $result->error->message() );
		$this->assertSame( array(), $this->sage->writes() );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $this->connector->findExisting( $this->connection, $this->item( '1081' ) ) );
	}

	public function test_an_order_without_an_email_or_a_date_is_refused_before_any_request(): void {
		$noEmail = $this->send( '1082', array( 'partner' => array( 'email' => '' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );

		$noDate = $this->send( '1083', array( 'date_order' => '' ) );
		$this->assertSame( ErrorCode::Val002, $noDate->error->code );
		$this->assertSame( array(), $this->sage->server->received );
	}

	public function test_a_rate_limit_answer_says_how_long_to_wait(): void {
		$this->sage->addContact( 'Layla Haddad', 'layla@example.test' );
		$this->sage->failNextWrite( 'rate_limit', 17 );

		$result = $this->send( '1084' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 17, $result->error->retryAfter );
		$this->assertCount( 0, $this->sage->store['invoice'] );
	}

	public function test_a_user_without_a_role_in_the_business_gets_a_permission_error(): void {
		$this->sage->noAccess = true;

		$result = $this->send( '1085' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}

	public function test_a_connection_that_has_not_chosen_a_business_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'country' => 'gb' ), $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() ), 6 );

		$result = $this->connector->send( $connection, $this->item( '1086' ), $this->payload( '1086' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'choose the business', $result->error->message() );
		$this->assertSame( array(), $this->sage->server->received );
	}

	public function test_a_business_that_is_not_the_users_is_refused_by_sage_and_its_id_is_removed_from_messages(): void {
		$connection = $this->connect( array(), 7, array( 'business_id' => 'ffffffffffffffffffffffffffffffee' ) );

		$result = $this->connector->send( $connection, $this->item( '1087' ), $this->payload( '1087' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringNotContainsString( 'ffffffffffffffffffffffffffffffee', $result->error->message() . json_encode( $result->error->context ) . $result->error->detail );
	}
}
