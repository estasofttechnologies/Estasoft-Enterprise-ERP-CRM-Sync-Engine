<?php
/**
 * Item lookup, invoice read-back, the nightly check list, the connection test and the error shapes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Connectors\OracleFusion\OracleFusionErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class OracleFusionReadsTest extends OracleFusionTestCase {

	public function test_an_item_is_found_by_its_number_and_an_unknown_one_is_null(): void {
		$found = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'CS-MUG-01', $found->id );
		$this->assertNull( $this->connector->lookupProduct( $this->connection(), 'NOPE-1' ) );
	}

	public function test_an_item_typed_in_lower_case_is_found_by_the_upper_case_number(): void {
		$found = $this->connector->lookupProduct( $this->connection(), ' cs-mug-01 ' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'CS-MUG-01', $found->id );
	}

	public function test_with_free_text_every_sku_is_accepted_as_it_is_without_a_request(): void {
		$found = $this->connector->lookupProduct( $this->connection( array( 'unknown_sku' => 'free_text' ) ), 'ANYTHING-9' );
		$this->assertSame( 'ANYTHING-9', $found->id );
		$this->assertSame( array(), $this->kit->http->requests );
	}

	public function test_the_search_matches_part_of_a_number_or_a_description_and_returns_only_numbers(): void {
		$this->assertSame( 'CS-BOWL-02', $this->connector->searchProducts( $this->connection(), 'bowl' )[0]->id, 'Found in the description.' );
		$this->assertSame( 'CS-BOWL-02', $this->connector->searchProducts( $this->connection(), 'BOWL' )[0]->id, 'Found in the number.' );
		$this->assertSame( 'CS-MUG-01', $this->connector->searchProducts( $this->connection(), 'Ceramic mug' )[0]->id );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection(), 'zzzz-no-match' ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection(), '%' ), 'A wildcard alone matches nothing.' );
	}

	public function test_a_quote_in_a_search_cannot_break_out_of_the_filter(): void {
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection(), "x' or ItemNumber like '%" ) );
		$this->assertNull( $this->connector->lookupProduct( $this->connection(), "CS-MUG-01' or ItemNumber like '%" ) );
	}

	public function test_a_customer_lookup_says_there_is_none_after_checking_the_sign_in(): void {
		$wrong = $this->connector->lookupCustomer( $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) ), 'anyone@example.test' );
		$this->assertSame( ErrorCode::Auth001, $wrong->code, 'A wrong secret is told here as well.' );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection(), 'anyone@example.test' ) );
	}

	public function test_there_are_no_tax_codes_because_fusion_works_tax_out_itself(): void {
		$this->assertSame( array(), $this->connector->listTaxes( $this->connection() ) );
	}

	public function test_the_invoice_is_read_back_with_its_number_and_date_and_a_missing_one_is_null(): void {
		$sent    = $this->connector->send( $this->connection(), $this->item( '3001' ), $this->payload() );
		$invoice = $this->connector->fetchInvoice( $this->connection(), $sent->ref );
		$this->assertSame( $sent->ref->ref, $invoice['number'] );
		$this->assertSame( 'issued', $invoice['status'] );
		$this->assertSame( '2026-11-02', $invoice['issued_at'] );
		$this->assertSame( '', $invoice['authority_status'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '1', '1' ) ) );
	}

	public function test_the_nightly_list_has_totals_refunds_and_only_the_shops_invoices_of_this_business_unit_since_the_date(): void {
		$this->connector->send( $this->connection(), $this->item( '4001' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '4002' ), $this->payload( array( 'total' => 3998, 'order_line' => array( array( 'product' => 'CS-MUG-01', 'quantity' => 2, 'price_unit' => 1999 ) ) ) ) );
		$this->connector->send( $this->connection(), $this->item( '9001', EntityType::Refund ), array( 'order_ref' => 'WC-4001', 'date' => '2026-11-03 09:00:00', 'reason' => 'Damaged', 'amount' => 1999, 'refund_line' => array( array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 1999 ) ) ) );
		$this->server->data['receivablesInvoices']['1'] = array( 'CustomerTransactionId' => 1, 'BusinessUnit' => 'Shop Business Unit', 'PurchaseOrder' => 'PO-77', 'InvoiceCurrencyCode' => 'AED', 'TransactionDate' => '2026-11-02', 'TotalAmount' => 10 );
		$this->server->data['receivablesInvoices']['2'] = array( 'CustomerTransactionId' => 2, 'BusinessUnit' => 'Another Unit', 'PurchaseOrder' => 'WC-5555', 'InvoiceCurrencyCode' => 'AED', 'TransactionDate' => '2026-11-02', 'TotalAmount' => 10 );
		$this->server->data['receivablesInvoices']['3'] = array( 'CustomerTransactionId' => 3, 'BusinessUnit' => 'Shop Business Unit', 'PurchaseOrder' => 'WC-0001', 'InvoiceCurrencyCode' => 'AED', 'TransactionDate' => '2025-01-01', 'TotalAmount' => 10 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertIsArray( $list );
		$this->assertSame( array( 'WC-4001', 'WC-4002' ), array_keys( $list ) );
		$this->assertSame( 5997, $list['WC-4001']->extra['total_minor'] );
		$this->assertSame( 1999, $list['WC-4001']->extra['refunded_minor'], 'The credit memo is positive money refunded.' );
		$this->assertSame( 3998, $list['WC-4002']->extra['total_minor'] );
		$this->assertSame( 0, $list['WC-4002']->extra['refunded_minor'] );
		$this->assertSame( '', $list['WC-4002']->extra['email'] );
	}

	public function test_a_pod_without_totals_makes_the_nightly_check_say_so_instead_of_reporting_orders_missing(): void {
		$this->server->showsTotal = false;
		$this->connector->send( $this->connection(), $this->item( '4003' ), $this->payload() );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertInstanceOf( ConnectorError::class, $list );
		$this->assertSame( ErrorCode::Val002, $list->code );
		$this->assertStringContainsString( 'TotalAmount', json_encode( $list->context ) );
	}

	public function test_a_long_list_is_read_page_by_page_until_there_are_no_more(): void {
		for ( $i = 1; $i <= 130; ++$i ) {
			$this->server->data['receivablesInvoices'][ (string) $i ] = array( 'CustomerTransactionId' => $i, 'BusinessUnit' => 'Shop Business Unit', 'PurchaseOrder' => 'WC-' . $i, 'InvoiceCurrencyCode' => 'AED', 'TransactionDate' => '2026-11-02', 'TotalAmount' => 10 );
		}
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertCount( 130, $list );
		$pages = array_filter( $this->calls( 'GET', '/receivablesInvoices' ), static fn( array $request ): bool => str_contains( $request['url'], 'offset=100' ) );
		$this->assertCount( 1, $pages, 'The second page starts at offset 100.' );
	}

	public function test_the_connection_test_reads_the_resources_and_reports_each_role(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( array( 'invoices' => true, 'items' => true, 'credit_memos' => true ), $result->permissions );
		$this->assertSame( 'Shop Business Unit', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
	}

	public function test_a_missing_role_for_credit_memos_is_shown_but_does_not_fail_the_test(): void {
		$this->server->blocked[] = 'receivablesCreditMemos';
		$result                  = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertFalse( $result->permissions['credit_memos'] );
	}

	public function test_a_missing_role_for_invoices_fails_the_test_with_auth_004(): void {
		$this->server->blocked[] = 'receivablesInvoices';
		$result                  = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertContains( 'invoices', $result->missingPermissions() );
	}

	public function test_items_are_only_required_while_unknown_items_are_checked(): void {
		$this->server->blocked[] = 'itemsV2';
		$this->assertSame( ErrorCode::Auth004, $this->connector->test( $this->connection() )->error->code );
		$this->assertTrue( $this->connector->test( $this->connection( array( 'unknown_sku' => 'free_text' ) ) )->ok );
	}

	public function test_a_wrong_secret_is_auth_001_and_the_secret_is_nowhere_in_the_error(): void {
		$connection = $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) );
		$result     = $this->connector->test( $connection );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wr0ng-s3cret-test', $result->error->message() . $result->error->detail . json_encode( $result->error->context ) );
		$this->assertFalse( $this->connector->ping( $connection ) );
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
	}

	public function test_a_wrong_password_for_a_fusion_user_is_auth_001(): void {
		$connection = $this->connection( array(), array( 'auth_method' => 'basic', 'user' => $this->server->user ), array( 'password' => 'wr0ng-passw0rd-test' ) );
		$result     = $this->connector->test( $connection );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wr0ng-passw0rd-test', json_encode( array( $result->error->message(), $result->error->context ) ) );
	}

	public function test_a_token_that_was_replaced_on_the_server_is_asked_for_again_once_and_the_call_goes_through(): void {
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '5001' ), $this->payload() )->isSuccess() );
		$this->server->rotateToken();
		$second = $this->connector->send( $this->connection(), $this->item( '5002' ), $this->payload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 2, $this->server->tokenCalls );
	}

	public function test_the_identity_domain_host_is_not_repeated_in_an_error(): void {
		$result = $this->connector->test( $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) ) );
		$text   = $result->error->message() . $result->error->detail . json_encode( $result->error->context );
		$this->assertStringNotContainsString( 'idcs-abc123def', $text );
		$this->assertStringNotContainsString( 'example.fa.us2', $text );
	}

	/**
	 * @dataProvider provide_addresses
	 */
	public function test_the_address_is_reduced_to_scheme_and_host( string $typed, bool $accepted ): void {
		$result = $this->connector->test( $this->connection( array(), array( 'address' => $typed ) ) );
		$this->assertSame( $accepted, $result->ok, (string) $result->error?->message() );
	}

	/**
	 * Addresses a person may type.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function provide_addresses(): array {
		return array(
			'plain'                 => array( 'https://example.fa.us2.oraclecloud.com', true ),
			'with a slash'          => array( 'https://example.fa.us2.oraclecloud.com/', true ),
			'with the api path'     => array( 'https://example.fa.us2.oraclecloud.com/fscmRestApi/resources/11.13.18.05', true ),
			'upper case'            => array( 'HTTPS://EXAMPLE.FA.US2.ORACLECLOUD.COM', true ),
			'plain http'            => array( 'http://example.fa.us2.oraclecloud.com', false ),
			'an address inside'     => array( 'https://127.0.0.1', false ),
			'user info'             => array( 'https://user:pass@example.fa.us2.oraclecloud.com', false ),
			'nothing'               => array( '', false ),
		);
	}

	public function test_an_identity_domain_address_that_is_not_https_gives_a_clear_failure_and_no_request_to_it(): void {
		$result = $this->connector->test( $this->connection( array(), array( 'identity_url' => 'http://idcs-abc123def.identity.oraclecloud.com' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( array(), $this->server->tokenCalls );
	}

	public function test_a_429_is_throttling_with_the_wait_the_system_gave_or_ten_seconds(): void {
		$errors = new OracleFusionErrors( 'Oracle Fusion Cloud ERP' );
		$with   = $errors->map( new ApiResponse( 429, '{}', array( 'retry-after' => '42' ) ) );
		$this->assertSame( ErrorCode::Rate001, $with->code );
		$this->assertSame( 42, $with->retryAfter );
		$this->assertSame( OracleFusionErrors::DEFAULT_WAIT, $errors->map( new ApiResponse( 429, '', array() ) )->retryAfter );
	}

	public function test_the_error_text_comes_from_the_error_details_else_the_detail_else_the_title(): void {
		$errors = new OracleFusionErrors( 'Oracle Fusion Cloud ERP' );
		$body   = json_encode( array( 'title' => 'Bad Request', 'detail' => 'General', 'o:errorDetails' => array( array( 'detail' => 'Attribute BusinessUnit is invalid', 'o:errorPath' => '/BusinessUnit' ) ) ) );
		$this->assertSame(
			array( 'field' => '/BusinessUnit', 'reason' => 'Attribute BusinessUnit is invalid' ),
			$errors->detail( new ApiResponse( 400, $body ) )
		);
		$this->assertSame( 'General', $errors->detail( new ApiResponse( 400, json_encode( array( 'title' => 'Bad Request', 'detail' => 'General' ) ) ) )['reason'] );
		$this->assertSame( 'Bad Request', $errors->detail( new ApiResponse( 400, json_encode( array( 'title' => 'Bad Request' ) ) ) )['reason'] );
		$this->assertSame( '', $errors->detail( new ApiResponse( 400, '<html>gateway</html>' ) )['reason'] );
	}

	public function test_a_long_html_laden_reason_is_cut_and_stripped(): void {
		$errors = new OracleFusionErrors( 'Oracle Fusion Cloud ERP' );
		$reason = $errors->detail( new ApiResponse( 400, json_encode( array( 'detail' => '<b>Bad</b> ' . str_repeat( 'word ', 100 ) ) ) ) )['reason'];
		$this->assertStringStartsWith( 'Bad word', $reason );
		$this->assertLessThanOrEqual( OracleFusionErrors::REASON_LENGTH, mb_strlen( $reason ) );
		$this->assertStringEndsWith( "\u{2026}", $reason );
	}

	public function test_statuses_the_base_handles_are_left_to_it(): void {
		$errors = new OracleFusionErrors( 'Oracle Fusion Cloud ERP' );
		foreach ( array( 401, 403, 404, 500, 502, 503 ) as $status ) {
			$this->assertNull( $errors->map( new ApiResponse( $status, '{"detail":"x"}', array() ) ), (string) $status );
		}
		$this->assertSame( ErrorCode::Val002, $errors->map( new ApiResponse( 422, '{"detail":"x"}', array() ) )->code );
		$this->assertNull( $errors->map( new ApiResponse( 400, '', array() ) ), 'No text, no mapping: the base decides.' );
	}
}
