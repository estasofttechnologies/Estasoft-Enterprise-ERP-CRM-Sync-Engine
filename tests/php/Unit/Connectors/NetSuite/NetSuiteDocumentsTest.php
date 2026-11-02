<?php
/**
 * Invoices, credit memos and reads against the fake NetSuite.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class NetSuiteDocumentsTest extends NetSuiteTestCase {

	private function invoiceOptions(): array {
		return array( 'documents' => 'order_invoice' );
	}

	public function test_with_invoices_on_the_order_is_billed_once_and_an_unfinished_order_is_not_reported_as_found(): void {
		$item = $this->item( '2001' );
		$this->assertNull( $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ) );
		$first = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$invoice = array_values( $this->server->all( 'invoice' ) )[0];
		$this->assertSame( '2026-11-02', $invoice['tranDate'], 'The invoice keeps the order date.' );
		$this->assertSame( $invoice['tranId'], $first->ref->extra['invoice_number'] );
		$second = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice' ), 'The transform is not repeated.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ) );
	}

	public function test_an_order_made_without_invoices_is_continued_when_the_option_changes(): void {
		$item = $this->item( '2002' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $this->payload() )->isSuccess() );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection(), $item ) );
		$this->assertNull( $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $item ), 'The invoice is still missing.' );
		$done = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $done->isSuccess() );
		$this->assertTrue( $done->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ) );
	}

	public function test_an_order_that_needs_approval_stops_with_a_clear_message_and_continues_once_approved(): void {
		$this->server->needsApproval = true;
		$item                        = $this->item( '2003' );
		$stopped                     = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertFalse( $stopped->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $stopped->error->code );
		$this->assertStringContainsString( 'approved', json_encode( $stopped->error->context ) );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ) );
		$this->server->needsApproval = false;
		$done                        = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $done->isSuccess(), (string) $done->error?->message() );
		$this->assertCount( 1, $this->server->all( 'salesOrder' ), 'No second order.' );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	public function test_a_lost_answer_of_the_invoice_transform_is_found_on_the_next_pass_and_never_repeated(): void {
		$item = $this->item( '2004' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $this->payload() )->isSuccess() );
		$orderId = (string) array_key_first( $this->server->all( 'salesOrder' ) );
		$this->kit->http->request( 'POST', 'https://' . $this->server->host() . '/services/rest/record/v1/salesOrder/' . $orderId . '/!transform/invoice', array( 'Authorization' => 'Bearer ' . $this->server->token ), '{}' );
		$this->assertCount( 1, $this->server->all( 'invoice' ), 'The first request made the invoice but its answer was lost.' );
		$done = $this->connector->send( $this->connection( $this->invoiceOptions() ), $item, $this->payload() );
		$this->assertTrue( $done->isSuccess(), (string) $done->error?->message() );
		$this->assertCount( 1, $this->server->all( 'invoice' ) );
	}

	private function refundPayload( array $override = array() ): array {
		return $override + array(
			'order_ref'   => 'WC-2005',
			'date'        => '2026-11-03 09:00:00',
			'reason'      => 'Damaged',
			'amount'      => 1999,
			'refund_line' => array(
				array(
					'product'  => $this->itemId( 'CS-MUG-01' ),
					'quantity' => 1,
					'amount'   => 1999,
				),
			),
		);
	}

	public function test_a_refund_is_a_credit_memo_made_from_the_invoice_with_its_own_reference(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$refund = $this->item( '501', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $refund ) );
		$result = $this->connector->send( $this->connection( $this->invoiceOptions() ), $refund, $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$memo = array_values( $this->server->all( 'creditMemo' ) )[0];
		$this->assertSame( 'WC-501', $memo['externalId'] );
		$this->assertSame( 'WC-501', $memo['otherRefNum'] );
		$this->assertStringContainsString( '[WC-501] Damaged', $memo['memo'] );
		$this->assertSame( 1999, $result->ref->extra['total_minor'] );
		$again = $this->connector->send( $this->connection( $this->invoiceOptions() ), $refund, $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'creditMemo' ) );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $refund ) );
	}

	public function test_a_credit_memo_that_adds_the_refund_to_the_copied_invoice_lines_is_caught_removed_and_never_reported_as_synced(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$this->server->creditMemoLines = 'add';
		$refund                        = $this->item( '510', EntityType::Refund );
		$result                        = $this->connector->send( $this->connection( $this->invoiceOptions() ), $refund, $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( '79.96', $context, 'What NetSuite shows: the 19.99 refund on top of the 59.97 invoice.' );
		$this->assertStringContainsString( '19.99', $context, 'What the shop refunded.' );
		$this->assertStringContainsString( 'was removed', $context );
		$this->assertSame( array(), $this->server->all( 'creditMemo' ) );
		$deletes = array_filter( $this->kit->http->requests, static fn( array $request ): bool => 'DELETE' === $request['method'] && str_contains( $request['url'], '/record/v1/creditMemo/' ) );
		$this->assertCount( 1, $deletes );
	}

	public function test_a_credit_memo_that_cannot_be_removed_stays_and_findexisting_keeps_reporting_it(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$this->server->creditMemoLines         = 'add';
		$this->server->refuseCreditMemoDelete = true;
		$refund                                = $this->item( '511', EntityType::Refund );
		$result                                = $this->connector->send( $this->connection( $this->invoiceOptions() ), $refund, $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'stays in NetSuite', json_encode( $result->error->context ) );
		$this->assertCount( 1, $this->server->all( 'creditMemo' ) );
		$found = $this->connector->findExisting( $this->connection( $this->invoiceOptions() ), $refund );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $found, 'The engine would link a reference; it must get the error.' );
		$again = $this->connector->send( $this->connection( $this->invoiceOptions() ), $refund, $this->refundPayload() );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'creditMemo' ), 'No second credit memo, and the found one is not deleted by a pass that did not create it.' );
	}

	public function test_with_total_mismatch_warn_the_credit_memo_is_kept_with_a_warning(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$this->server->creditMemoLines = 'add';
		$options                       = $this->invoiceOptions() + array( 'total_mismatch' => 'warn' );
		$result                        = $this->connector->send( $this->connection( $options ), $this->item( '512', EntityType::Refund ), $this->refundPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertCount( 1, $this->server->all( 'creditMemo' ) );
	}

	public function test_the_refund_total_is_written_as_the_last_line_of_the_memo(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '513', EntityType::Refund ), $this->refundPayload() );
		$memo = array_values( $this->server->all( 'creditMemo' ) )[0];
		$this->assertStringEndsWith( "\nShop total 19.99 AED", $memo['memo'] );
	}

	public function test_a_refund_without_an_invoice_is_refused_with_a_clear_message_and_creates_nothing(): void {
		$this->connector->send( $this->connection(), $this->item( '2005' ), $this->payload() );
		$result = $this->connector->send( $this->connection(), $this->item( '502', EntityType::Refund ), $this->refundPayload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'invoice', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->server->all( 'creditMemo' ) );
	}

	public function test_a_refund_for_an_order_that_is_not_in_netsuite_is_refused(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '503', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-9999' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_a_refund_that_names_no_products_needs_the_refund_item_option(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2005' ), $this->payload() );
		$payload = $this->refundPayload();
		unset( $payload['refund_line'] );
		$refused = $this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '504', EntityType::Refund ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$item = $this->server->addItem( 'REFUND', 'Refund' );
		$ok   = $this->connector->send( $this->connection( $this->invoiceOptions() + array( 'refund_item' => $item ) ), $this->item( '504', EntityType::Refund ), $payload );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
	}

	public function test_a_refund_whose_order_ref_is_not_an_order_reference_is_refused(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '505', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'order 5' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_invoice_facts_come_back_for_a_synced_order(): void {
		$sent = $this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2006' ), $this->payload() );
		$info = $this->connector->fetchInvoice( $this->connection(), $sent->ref );
		$this->assertIsArray( $info );
		$this->assertSame( 'open', $info['status'] );
		$this->assertSame( '', $info['authority_status'] );
		$this->assertStringStartsWith( 'INV', $info['number'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '99999' ) ) );
	}

	public function test_the_nightly_check_lists_orders_with_totals_refunds_and_email(): void {
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '2007' ), $this->payload() );
		$this->connector->send( $this->connection( $this->invoiceOptions() ), $this->item( '601', EntityType::Refund ), $this->refundPayload( array( 'order_ref' => 'WC-2007' ) ) );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertArrayHasKey( 'WC-2007', $list );
		$this->assertSame( 5997, $list['WC-2007']->extra['total_minor'] );
		$this->assertSame( 1999, $list['WC-2007']->extra['refunded_minor'] );
		$this->assertSame( 'noor@example.test', $list['WC-2007']->extra['email'] );
	}

	public function test_the_nightly_check_reads_every_page(): void {
		$this->connector->send( $this->connection(), $this->item( '2008' ), $this->payload() );
		$template = array_values( $this->server->all( 'salesOrder' ) )[0];
		for ( $i = 0; $i < 450; ++$i ) {
			$id                                    = (string) ( 5000 + $i );
			$this->server->records['salesOrder'][ $id ] = array( 'id' => $id, 'tranId' => 'SO' . $id, 'externalId' => 'WC-' . $id ) + $template;
		}
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertCount( 451, $list, 'More than one page of 200 rows is read to the end.' );
		$posts = array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( $request['url'], 'suiteql' ) && str_contains( $request['url'], 'offset=400' ) );
		$this->assertNotEmpty( $posts );
	}

	public function test_lookups_find_a_customer_an_item_and_search_items(): void {
		$customer = $this->connector->lookupCustomer( $this->connection(), 'EXISTING@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $customer );
		$this->assertSame( 'Existing Buyer', $customer->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$item = $this->connector->lookupProduct( $this->connection(), 'cs-bowl-02' );
		$this->assertInstanceOf( ExternalRef::class, $item );
		$this->assertSame( 'CS-BOWL-02', $item->ref );
		$this->assertNull( $this->connector->lookupProduct( $this->connection(), 'NO-SUCH' ) );
		$found = $this->connector->searchProducts( $this->connection(), 'mug' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-MUG-01', $found[0]->ref );
	}

	public function test_the_sku_field_option_only_accepts_a_known_column(): void {
		$this->connector->lookupProduct( $this->connection( array( 'sku_field' => 'itemid; DROP TABLE item' ) ), 'X' );
		$queries = array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( (string) $request['body'], 'FROM item' ) );
		foreach ( $queries as $request ) {
			$this->assertStringNotContainsString( 'DROP', (string) $request['body'] );
		}
		$this->connector->lookupProduct( $this->connection( array( 'sku_field' => 'custitem_sku' ) ), 'X' );
		$this->assertStringContainsString( 'UPPER(custitem_sku)', (string) $this->kit->http->last()['body'] );
	}

	public function test_test_connection_reports_permissions_the_account_and_the_currencies(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertSame( '1234567-SB1', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( array( 'USD', 'AED', 'KWD' ), $result->detected['currencies'] );
	}

	public function test_an_account_without_suitetax_still_connects_but_says_so(): void {
		$this->server->suiteTax = false;
		$result                 = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'suitetax' ), $result->missingPermissions() );
	}

	public function test_a_role_that_cannot_read_customers_fails_the_test_with_auth_004(): void {
		$this->kit->http->route( 'GET', '~/record/v1/customer\?~', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'status' => 403, 'o:errorDetails' => array( array( 'detail' => 'No permission', 'o:errorCode' => 'INSUFFICIENT_PERMISSION' ) ) ), 403 ) );
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertContains( 'customers', $result->missingPermissions() );
	}
}
