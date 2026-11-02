<?php
/**
 * Refunds as Order Entry credit notes, and the reads of Sage 300.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage300;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class Sage300RefundReadTest extends Sage300TestCase {

	/**
	 * Send the order the refunds refer to.
	 */
	private function placeOrder(): void {
		$result = $this->sendOrder();
		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	/**
	 * Send a refund.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Refund override.
	 * @param string              $id      Refund id.
	 */
	private function sendRefund( array $config = array(), array $payload = array(), string $id = '501' ) {
		return $this->connector()->send( $this->connection( $config ), $this->item( EntityType::Refund, $id ), $this->refund( $payload ) );
	}

	public function test_a_refund_becomes_a_credit_note_for_the_orders_customer_with_the_refund_reference(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array( 'location' => 'MAIN' ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$notes = $this->server->all( 'OE/OECreditDebitNotes' );
		$this->assertCount( 1, $notes );
		$note = $notes[0];
		$this->assertSame( 'WC-501', $note['Reference'] );
		$this->assertSame( 'CreditNote', $note['CreditDebitNoteType'] );
		$this->assertSame( '1200', $note['CustomerNumber'] );
		$this->assertSame( '2026-11-03', $note['CreditDebitNoteDate'] );
		$this->assertSame( 'Refund of WC-100: Damaged', $note['Description'] );
		$line = $note['CreditDebitDetails'][0];
		$this->assertSame( array( 'Item', 'CS-BOWL-02', 'MAIN', 'EA' ), array( $line['LineType'], $line['Item'], $line['Location'], $line['CreditDebitNoteUOM'] ), 'The unit is the item\'s stocking unit.' );
		$this->assertSame( 1.0, (float) $line['QuantityReturned'] );
		$this->assertSame( 219.0, (float) $line['UnitPrice'] );
		$this->assertSame( 'CN000001', $result->ref->ref );
	}

	public function test_the_same_refund_is_never_made_twice_and_findExisting_knows_it(): void {
		$this->placeOrder();
		$first = $this->sendRefund();
		$again = $this->sendRefund();

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'OE/OECreditDebitNotes' ) );
		$this->assertSame( 'CN000001', $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Refund, '501' ) )->ref );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Refund, '502' ) ) );
	}

	public function test_a_lost_answer_after_the_create_is_continued_not_repeated(): void {
		$this->placeOrder();
		$this->server->loseAnswerOf( 'POST', '#/OE/OECreditDebitNotes$#' );
		$first = $this->sendRefund();
		$this->assertSame( ErrorCode::Net001, $first->error->code );

		$again = $this->sendRefund();
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'OE/OECreditDebitNotes' ) );
	}

	public function test_an_order_that_is_not_in_sage_cannot_be_refunded_there(): void {
		$result = $this->sendRefund();

		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'OE/OECreditDebitNotes' ) );
	}

	public function test_an_unknown_product_is_map_001(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'amount' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_refunded_shipping_and_a_refund_without_lines_become_miscellaneous_lines_with_their_codes(): void {
		$this->placeOrder();
		$refused = $this->sendRefund( array(), array( 'refund_line' => array(), 'shipping_amount' => 2500, 'amount' => 2500 ), '601' );
		$this->assertSame( 'shipping_charge_code', $refused->error->context['field'] );
		$none = $this->sendRefund( array(), array( 'refund_line' => array(), 'amount' => 500 ), '602' );
		$this->assertSame( 'refund_line', $none->error->context['field'] );

		$shipping = $this->sendRefund( array( 'shipping_charge_code' => 'SHIP', 'refund_charge_code' => 'REF' ), array( 'refund_line' => array(), 'shipping_amount' => 2500, 'amount' => 2500 ), '603' );
		$this->assertTrue( $shipping->isSuccess(), $this->why( $shipping ) );
		$lines = $this->server->all( 'OE/OECreditDebitNotes' )[0]['CreditDebitDetails'];
		$this->assertCount( 1, $lines, 'One shipping line, not two.' );
		$this->assertSame( array( 'Miscellaneous', 'SHIP', 25.0 ), array( $lines[0]['LineType'], $lines[0]['MiscellaneousChargesCode'], (float) $lines[0]['ExtendedAmountMiscellaneousCharge'] ) );

		$only = $this->sendRefund( array( 'refund_charge_code' => 'REF' ), array( 'refund_line' => array(), 'amount' => 500 ), '604' );
		$this->assertTrue( $only->isSuccess(), $this->why( $only ) );
		$this->assertSame( 'REF', $this->server->all( 'OE/OECreditDebitNotes' )[1]['CreditDebitDetails'][0]['MiscellaneousChargesCode'] );
	}

	public function test_a_refund_that_names_no_order_is_refused(): void {
		$result = $this->sendRefund( array(), array( 'order_ref' => 'bogus' ) );

		$this->assertSame( 'order_ref', $result->error->context['field'] );
	}

	public function test_the_nightly_list_has_the_shops_orders_with_totals_and_every_page_is_read(): void {
		for ( $i = 1; $i <= 130; $i++ ) {
			$this->server->put( 'OE/OEOrders', array( 'OrderNumber' => sprintf( 'OE%06d', $i ), 'OrderUniquifier' => (string) $i, 'PONumber' => 'WC-' . $i, 'CustomerNumber' => '1200', 'OrderDate' => '2026-11-02', 'OrderTotal' => 10.0 ) );
		}
		$this->server->put( 'OE/OEOrders', array( 'OrderNumber' => 'OE999999', 'OrderUniquifier' => '999', 'PONumber' => 'PO-77', 'CustomerNumber' => '1200', 'OrderDate' => '2026-11-02', 'OrderTotal' => 10.0 ) );
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );

		$this->assertCount( 130, $list, 'Both pages, and not the order that is not the shop\'s.' );
		$this->assertSame( 1000, $list['WC-7']->extra['total_minor'] );
		$this->assertSame( 0, $list['WC-7']->extra['refunded_minor'] );
		$this->assertSame( 'AED', $list['WC-7']->extra['currency'], 'The currency of the order\'s customer.' );
		$this->assertSame( 2, $this->server->count( 'GET', '#/OE/OEOrders$#' ) );
	}

	public function test_a_server_that_refuses_startswith_gets_the_date_filter_alone_and_the_prefix_is_applied_after_reading(): void {
		$this->server->rejectStartswith = true;
		$this->server->put( 'OE/OEOrders', array( 'OrderNumber' => 'OE000001', 'OrderUniquifier' => '1', 'PONumber' => 'WC-5', 'CustomerNumber' => '1200', 'OrderDate' => '2026-11-02', 'OrderTotal' => 10.0 ) );
		$this->server->put( 'OE/OEOrders', array( 'OrderNumber' => 'OE000002', 'OrderUniquifier' => '2', 'PONumber' => 'PO-8', 'CustomerNumber' => '1200', 'OrderDate' => '2026-11-02', 'OrderTotal' => 10.0 ) );
		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) );

		$this->assertSame( array( 'WC-5' ), array_keys( $list ) );
	}

	public function test_orders_before_the_window_are_not_listed(): void {
		$this->placeOrder();
		$this->server->records['OE/OEOrders'][0]['OrderDate'] = '2026-10-01';

		$this->assertSame( array(), $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-02T00:00:00Z' ) ) );
	}

	public function test_a_customer_is_found_by_the_email_property_and_an_unknown_one_is_null(): void {
		$ref = $this->connector()->lookupCustomer( $this->connection(), 'NOOR@example.test' );

		$this->assertSame( '1300', $ref->id );
		$this->assertSame( 'Noor Al-Mansouri', $ref->ref );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), '' ) );

		$this->server->customerProps = array( 'CustomerNumber', 'CustomerName' );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'noor@example.test' ), 'No email property: nothing to match on.' );
	}

	public function test_a_product_is_found_by_key_and_products_are_searched_with_a_fallback(): void {
		$ref = $this->connector()->lookupProduct( $this->connection(), 'cs-mug-01' );
		$this->assertSame( 'CS-MUG-01', $ref->id );
		$this->assertSame( 'EA', $ref->extra['unit'] );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), 'NO-SUCH' ) );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), ' ' ) );

		$found = $this->connector()->searchProducts( $this->connection(), 'MUG' );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ): string => $r->id, $found ) );
		$this->assertSame( array(), $this->connector()->searchProducts( $this->connection(), '' ) );

		$this->server->failNext( 'GET', '#/IC/ICItems$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'InvalidParameters', 'message' => array( 'value' => 'substringof not supported' ) ) ), 400 ) );
		$fallback = $this->connector()->searchProducts( $this->connection(), 'CS-MUG-01' );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ): string => $r->id, $fallback ), 'The key lookup when the filter is refused.' );
	}

	public function test_tax_groups_are_listed_without_a_rate(): void {
		$codes = $this->connector()->listTaxes( $this->connection() );

		$this->assertSame( array( 'UAEVAT', 'NOTAX' ), array_column( $codes, 'code' ) );
		$this->assertStringContainsString( 'rate set by the tax group', $codes[0]['label'] );
		$this->assertSame( 0.0, $codes[0]['rate_percent'] );
	}
}
