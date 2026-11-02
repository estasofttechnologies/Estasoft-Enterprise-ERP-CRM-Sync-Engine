<?php
/**
 * Product lookup, billing documents, the connection test and the error shapes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Connectors\SapS4Hana\SapS4HanaErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class SapS4HanaReadsTest extends SapS4HanaTestCase {

	public function test_a_product_is_found_by_its_number_and_an_unknown_one_is_null(): void {
		$found = $this->connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'CS-MUG-01', $found->id );
		$this->assertNull( $this->connector->lookupProduct( $this->connection(), 'NOPE-1' ) );
	}

	public function test_a_product_typed_in_lower_case_is_found_by_the_upper_case_number(): void {
		$found = $this->connector->lookupProduct( $this->connection(), ' cs-mug-01 ' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'CS-MUG-01', $found->id );
	}

	public function test_the_search_matches_part_of_a_number_and_returns_only_numbers(): void {
		$found = $this->connector->searchProducts( $this->connection(), 'bowl' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-BOWL-02', $found[0]->id );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection(), 'zzzz-no-match' ) );
	}

	public function test_a_quote_in_the_search_cannot_break_out_of_the_filter(): void {
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection(), "x') or ('1' eq '1" ) );
	}

	public function test_a_customer_lookup_says_there_is_none_after_checking_the_sign_in(): void {
		$wrong = $this->connector->lookupCustomer( $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) ), 'anyone@example.test' );
		$this->assertSame( ErrorCode::Auth001, $wrong->code, 'A wrong secret is told here as well.' );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection(), 'anyone@example.test' ) );
	}

	public function test_there_are_no_tax_codes_because_s4hana_decides_tax_itself(): void {
		$this->assertSame( array(), $this->connector->listTaxes( $this->connection() ) );
	}

	public function test_the_billing_document_of_an_order_is_read_back_with_its_number_and_date(): void {
		$this->server->data['A_BillingDocumentItem']['1']   = array( 'BillingDocument' => '90000010', 'ReferenceSDDocument' => '1001' );
		$this->server->data['A_BillingDocumentItem']['2']   = array( 'BillingDocument' => '90000012', 'ReferenceSDDocument' => '1001' );
		$this->server->data['A_BillingDocumentItem']['3']   = array( 'BillingDocument' => '90000011', 'ReferenceSDDocument' => '1002' );
		$this->server->data['A_BillingDocument']['90000012'] = array( 'BillingDocument' => '90000012', 'BillingDocumentDate' => '/Date(1793836800000)/', 'BillingDocumentIsCancelled' => false );
		$invoice = $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '1001', '1001' ) );
		$this->assertSame( '90000012', $invoice['number'], 'The newest billing document of the order.' );
		$this->assertSame( 'issued', $invoice['status'] );
		$this->assertSame( '2026-11-05', substr( $invoice['issued_at'], 0, 10 ) );
		$this->assertSame( '', $invoice['pdf_url'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '7777', '7777' ) ), 'No billing document yet is null, not an error.' );
	}

	public function test_a_cancelled_billing_document_is_reported_as_cancelled(): void {
		$this->server->data['A_BillingDocumentItem']['1']   = array( 'BillingDocument' => '90000020', 'ReferenceSDDocument' => '1003' );
		$this->server->data['A_BillingDocument']['90000020'] = array( 'BillingDocument' => '90000020', 'BillingDocumentDate' => '/Date(1793836800000)/', 'BillingDocumentIsCancelled' => true );
		$this->assertSame( 'cancelled', $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '1003', '1003' ) )['status'] );
	}

	public function test_the_connection_test_reads_the_services_and_reports_each_arrangement(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( array( 'sales_orders' => true, 'products' => true, 'billing_documents' => true, 'credit_memo_requests' => true ), $result->permissions );
		$this->assertStringContainsString( '1710', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
	}

	public function test_a_missing_arrangement_for_billing_is_shown_but_does_not_fail_the_test(): void {
		$this->server->blocked[] = 'API_BILLING_DOCUMENT_SRV';
		$this->server->blocked[] = 'API_CREDIT_MEMO_REQUEST_SRV';
		$result                  = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, 'Orders and products are enough to send orders.' );
		$this->assertFalse( $result->permissions['billing_documents'] );
		$this->assertFalse( $result->permissions['credit_memo_requests'] );
	}

	public function test_a_missing_arrangement_for_sales_orders_fails_the_test_with_auth_004(): void {
		$this->server->blocked[] = 'API_SALES_ORDER_SRV';
		$result                  = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertContains( 'sales_orders', $result->missingPermissions() );
	}

	public function test_a_wrong_secret_is_auth_001_and_the_secret_is_nowhere_in_the_error(): void {
		$result = $this->connector->test( $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wr0ng-s3cret-test', $result->error->message() . $result->error->detail . json_encode( $result->error->context ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array(), array( 'client_secret' => 'wr0ng-s3cret-test' ) ) ) );
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
	}

	public function test_a_wrong_password_for_a_communication_user_is_auth_001(): void {
		$connection = $this->connection( array(), array( 'auth_method' => 'basic', 'user' => $this->server->user ), array( 'password' => 'wr0ng-passw0rd-test' ) );
		$result     = $this->connector->test( $connection );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'wr0ng-passw0rd-test', json_encode( array( $result->error->message(), $result->error->context ) ) );
	}

	public function test_a_token_that_was_replaced_on_the_server_is_asked_for_again_once_and_the_call_goes_through(): void {
		$this->assertTrue( $this->connector->send( $this->connection(), $this->item( '3001' ), $this->payload() )->isSuccess() );
		$this->server->rotateToken();
		$second = $this->connector->send( $this->connection(), $this->item( '3002' ), $this->payload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 2, $this->server->tokenCalls );
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
			'plain'                  => array( 'https://my300000-api.s4hana.cloud.sap', true ),
			'with a slash'           => array( 'https://my300000-api.s4hana.cloud.sap/', true ),
			'with the service path'  => array( 'https://my300000-api.s4hana.cloud.sap/sap/opu/odata/sap/API_SALES_ORDER_SRV', true ),
			'upper case'             => array( 'HTTPS://MY300000-API.S4HANA.CLOUD.SAP', true ),
			'plain http'             => array( 'http://my300000-api.s4hana.cloud.sap', false ),
			'an address inside'      => array( 'https://127.0.0.1', false ),
			'nothing'                => array( '', false ),
		);
	}

	public function test_a_503_is_throttling_with_the_wait_the_system_gave_or_ten_seconds(): void {
		$errors = new SapS4HanaErrors( 'SAP S/4HANA' );
		$with   = $errors->map( new ApiResponse( 503, '{}', array( 'retry-after' => '42' ) ) );
		$this->assertSame( ErrorCode::Rate001, $with->code );
		$this->assertSame( 42, $with->retryAfter );
		$without = $errors->map( new ApiResponse( 503, '', array() ) );
		$this->assertSame( SapS4HanaErrors::DEFAULT_WAIT, $without->retryAfter );
	}

	public function test_a_precondition_failure_says_the_record_changed(): void {
		foreach ( array( 412, 428 ) as $status ) {
			$error = ( new SapS4HanaErrors( 'SAP S/4HANA' ) )->map( new ApiResponse( $status, '{}', array() ) );
			$this->assertSame( ErrorCode::Val002, $error->code, (string) $status );
			$this->assertStringContainsString( 'changed', json_encode( $error->context ) );
		}
	}

	public function test_the_error_text_is_the_first_detail_with_severity_error_else_the_main_message(): void {
		$errors = new SapS4HanaErrors( 'SAP S/4HANA' );
		$body   = json_encode( array( 'error' => array( 'code' => 'X', 'message' => array( 'lang' => 'en', 'value' => 'Main text' ), 'innererror' => array( 'errordetails' => array(
			array( 'code' => 'W1', 'message' => 'A warning', 'severity' => 'warning' ),
			array( 'code' => 'E1', 'message' => 'Material XYZ is not maintained in plant 1710', 'severity' => 'error' ),
		) ) ) ) );
		$this->assertSame( 'Material XYZ is not maintained in plant 1710', $errors->message( new ApiResponse( 400, $body, array() ) ) );
		$this->assertSame( 'Plain', $errors->message( new ApiResponse( 400, json_encode( array( 'error' => array( 'message' => 'Plain' ) ) ), array() ) ) );
		$this->assertSame( '', $errors->message( new ApiResponse( 400, '<html>gateway</html>', array() ) ) );
	}

	public function test_a_server_error_and_a_rate_limit_are_left_to_the_base_mapping(): void {
		$errors = new SapS4HanaErrors( 'SAP S/4HANA' );
		foreach ( array( 401, 403, 404, 429, 500, 502 ) as $status ) {
			$this->assertNull( $errors->map( new ApiResponse( $status, '{}', array() ) ), (string) $status );
		}
	}

	public function test_a_throttled_write_is_rate_001_with_the_wait_and_creates_nothing(): void {
		$this->server->failNext( FakeHttpClient::tooManyRequests( 17 ) );
		$result = $this->connector->send( $this->connection(), $this->item( '3003' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 17, $result->error->retryAfter );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ) );
	}
}
