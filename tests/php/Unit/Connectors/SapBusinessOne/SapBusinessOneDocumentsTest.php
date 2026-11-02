<?php
/**
 * Invoices, credit notes and reads against the fake Service Layer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class SapBusinessOneDocumentsTest extends SapBusinessOneTestCase {

	/**
	 * Options that turn invoices on.
	 *
	 * @return array<string,string>
	 */
	private function invoiceOptions(): array {
		return array( 'documents' => 'order_invoice' );
	}

	public function test_with_invoices_on_the_order_is_copied_to_an_invoice_once_and_an_unfinished_order_is_not_reported_as_found(): void {
		$item = $this->item( '2001' );
		$this->assertNull( $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ) );
		$first = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertCount( 1, $this->server->all( 'Invoices' ) );
		$invoice = array_values( $this->server->all( 'Invoices' ) )[0];
		$this->assertSame( 'WC-2001', $invoice['NumAtCard'] );
		$this->assertSame( '2026-11-02', $invoice['DocDate'] );
		$this->assertSame( 'CS-MUG-01', $invoice['DocumentLines'][0]['ItemCode'], 'The invoice lines are the order\'s lines.' );
		$this->assertSame( 3, $invoice['DocumentLines'][0]['Quantity'] );
		$posts = array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'POST' === $request['method'] && str_ends_with( $request['url'], '/Invoices' ) ) );
		$body  = json_decode( (string) $posts[0]['body'], true );
		$this->assertSame( array( 'BaseType' => 17, 'BaseEntry' => 1, 'BaseLine' => 0 ), $body['DocumentLines'][0] );
		$this->assertSame( (string) $invoice['DocNum'], $first->ref->extra['invoice_number'] );
		$second = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Invoices' ), 'The copy is not repeated.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ) );
	}

	public function test_an_order_made_without_invoices_is_continued_when_the_option_changes(): void {
		$item = $this->item( '2002' );
		$this->connector->send( $this->connection(), $item, $this->payload() );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $item ) );
		$this->assertNull( $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ) );
		$done = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $done->isSuccess() && $done->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Orders' ) );
		$this->assertCount( 1, $this->server->all( 'Invoices' ) );
	}

	public function test_an_invoice_whose_answer_was_lost_is_found_by_its_reference_and_not_made_twice(): void {
		$item = $this->item( '2003' );
		$this->connector->send( $this->connection(), $item, $this->payload() );
		$order = array_values( $this->server->all( 'Orders' ) )[0];
		$this->kit->http->request(
			'POST',
			$this->server->address . '/b1s/v2/Invoices',
			array( 'Cookie' => 'B1SESSION=' . (string) array_key_first( $this->server->sessions ) ),
			(string) json_encode( array( 'CardCode' => $order['CardCode'], 'NumAtCard' => 'WC-2003', 'DocCurrency' => 'AED', 'DocumentLines' => array( array( 'BaseType' => 17, 'BaseEntry' => $order['DocEntry'], 'BaseLine' => 0 ) ) ) )
		);
		$this->assertCount( 1, $this->server->all( 'Invoices' ) );
		$done = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $done->isSuccess(), (string) $done->error?->message() );
		$this->assertCount( 1, $this->server->all( 'Invoices' ) );
	}

	/**
	 * A refund payload.
	 *
	 * @param array<string,mixed> $override Replacements.
	 * @return array<string,mixed>
	 */
	private function refundPayload( array $override = array() ): array {
		return $override + array(
			'order_ref'   => 'WC-2004',
			'date'        => '2026-11-03 09:00:00',
			'reason'      => 'Damaged',
			'amount'      => 1999,
			'refund_line' => array(
				array(
					'product'  => 'CS-MUG-01',
					'quantity' => 1,
					'amount'   => 1999,
				),
			),
		);
	}

	public function test_a_refund_is_a_credit_note_with_its_own_reference_and_the_order_named_in_the_remarks(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$refund = $this->item( '501', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $refund ) );
		$result = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$note = array_values( $this->server->all( 'CreditNotes' ) )[0];
		$this->assertSame( 'WC-501', $note['NumAtCard'] );
		$this->assertSame( 'Refund WC-501 of order WC-2004: Damaged', $note['Comments'] );
		$this->assertSame( 19.99, $note['DocumentLines'][0]['LineTotal'] );
		$this->assertSame( 1999, $result->ref->extra['total_minor'] );
		$again = $this->connector->send( $this->connection(), $refund, $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'CreditNotes' ) );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $refund ) );
	}

	public function test_a_refund_for_an_order_that_is_not_in_sap_is_refused_before_anything_is_made(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '502', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-9999' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'CreditNotes' ) );
	}

	public function test_an_amount_only_refund_needs_the_refund_item_option(): void {
		$this->connector->send( $this->connection(), $this->item( '2004' ), $this->payload() );
		$payload = $this->refundPayload();
		unset( $payload['refund_line'] );
		$refused = $this->connector->send( $this->connection(), $this->item( '503', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->server->addItem( 'REFUND', 'Refund' );
		$ok = $this->connector->send( $this->connection( array( 'refund_item' => 'REFUND' ) ), $this->item( '503', EntityType::Refund ), $payload );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
	}

	public function test_stock_is_summed_over_the_chosen_warehouses_and_unknown_items_are_left_out(): void {
		$all = $this->connector->fetchStock( $this->connection(), array( 'CS-BOWL-02', 'cs-mug-01', 'NO-SUCH' ), array() );
		$this->assertSame( array( 'quantity' => 19, 'held' => 2 ), $all['CS-BOWL-02'] );
		$this->assertSame( array( 'quantity' => 42, 'held' => 4 ), $all['cs-mug-01'] );
		$this->assertArrayNotHasKey( 'NO-SUCH', $all );
		$one = $this->connector->fetchStock( $this->connection(), array( 'CS-BOWL-02' ), array( 'WH2' ) );
		$this->assertSame( array( 'quantity' => 5, 'held' => 0 ), $one['CS-BOWL-02'] );
	}

	public function test_stock_asks_in_chunks_for_many_items(): void {
		$skus = array();
		for ( $i = 0; $i < 45; ++$i ) {
			$this->server->addItem( 'BULK-' . $i, 'Bulk', array( 'WH1' => array( $i, 0 ) ) );
			$skus[] = 'BULK-' . $i;
		}
		$stock = $this->connector->fetchStock( $this->connection(), $skus, array() );
		$this->assertCount( 45, $stock );
		$this->assertSame( 44, $stock['BULK-44']['quantity'] );
	}

	public function test_invoice_facts_come_back_for_a_synced_order(): void {
		$sent = $this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$info = $this->connector->fetchInvoice( $this->connection(), $sent->ref );
		$this->assertIsArray( $info );
		$this->assertSame( 'open', $info['status'] );
		$this->assertSame( '2026-11-02', $info['issued_at'] );
		$key = (string) array_key_first( $this->server->all( 'Invoices' ) );
		$this->server->data['Invoices'][ $key ]['PaidToDate'] = 59.97;
		$this->assertSame( 'paid', $this->connector->fetchInvoice( $this->connection(), $sent->ref )['status'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '99999' ) ) );
	}

	public function test_the_nightly_check_lists_orders_with_totals_refunds_and_email(): void {
		$this->connector->send( $this->connection(), $this->item( '2006' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '504', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-2006' ) ) );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertArrayHasKey( 'WC-2006', $list );
		$this->assertSame( 5997, $list['WC-2006']->extra['total_minor'] );
		$this->assertSame( 1999, $list['WC-2006']->extra['refunded_minor'] );
		$this->assertSame( 'noor@example.test', $list['WC-2006']->extra['email'] );
		$this->assertArrayNotHasKey( 'WC-504', $list );
	}

	public function test_the_nightly_check_follows_the_next_page_links(): void {
		$this->connector->send( $this->connection(), $this->item( '2007' ), $this->payload() );
		$template = array_values( $this->server->all( 'Orders' ) )[0];
		for ( $i = 0; $i < 450; ++$i ) {
			$this->server->data['Orders'][ (string) ( 100 + $i ) ] = array( 'DocEntry' => 100 + $i, 'NumAtCard' => 'WC-9' . $i ) + $template;
		}
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertCount( 451, $list );
		$skips = array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( rawurldecode( $request['url'] ), '$skip=400' ) );
		$this->assertNotEmpty( $skips, 'The third page was asked for through the link.' );
	}

	public function test_version_one_uses_its_own_path_date_literal_and_next_link_key(): void {
		$this->server->version = 'v1';
		$this->connector->send( $this->connection(), $this->item( '2008' ), $this->payload() );
		$template = array_values( $this->server->all( 'Orders' ) )[0];
		for ( $i = 0; $i < 250; ++$i ) {
			$this->server->data['Orders'][ (string) ( 100 + $i ) ] = array( 'DocEntry' => 100 + $i, 'NumAtCard' => 'WC-8' . $i ) + $template;
		}
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertCount( 251, $list );
		$urls = implode( "\n", array_map( static fn( array $request ): string => rawurldecode( $request['url'] ), $this->kit->http->requests ) );
		$this->assertStringContainsString( '/b1s/v1/Orders', $urls );
		$this->assertStringContainsString( "DocDate ge '2026-01-01'", $urls );
	}

	public function test_lookups_find_a_customer_an_item_and_search_items(): void {
		$customer = $this->connector->lookupCustomer( $this->connection(), 'EXISTING@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $customer );
		$this->assertSame( 'Existing Buyer', $customer->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$item = $this->connector->lookupProduct( $this->connection(), 'cs-bowl-02' );
		$this->assertInstanceOf( ExternalRef::class, $item );
		$this->assertSame( 'CS-BOWL-02', $item->id );
		$this->assertNull( $this->connector->lookupProduct( $this->connection(), 'NO-SUCH' ) );
		$found = $this->connector->searchProducts( $this->connection(), 'mug' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-MUG-01', $found[0]->ref );
	}

	public function test_tax_codes_are_the_output_vat_groups_with_their_rates(): void {
		$codes = $this->connector->listTaxes( $this->connection() );
		$this->assertSame( array( 'S1', 'X0' ), array_column( $codes, 'code' ) );
		$this->assertSame( 5.0, $codes[0]['rate_percent'] );
	}

	public function test_test_connection_reports_the_version_warehouses_and_permissions(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Service Layer 1000160', $result->version );
		$this->assertSame( 'SBO_TEST', $result->company );
		$this->assertSame( array( 'WH1', 'WH2' ), array_column( $result->warehouses, 'id' ) );
		$this->assertSame( array(), $result->missingPermissions() );
	}

	public function test_a_user_without_the_right_to_read_invoices_fails_the_test_with_auth_004(): void {
		$this->kit->http->route( 'GET', '~/Invoices\?~', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'error' => array( 'code' => -1013, 'message' => array( 'lang' => 'en-us', 'value' => 'You are not authorized to view invoices.' ) ) ), 400 ) );
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}
}
