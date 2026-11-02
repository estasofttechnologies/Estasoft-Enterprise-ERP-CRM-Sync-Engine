<?php
/**
 * Moneybird: invoices, contacts, credit invoices, errors and the shape of what goes on the wire.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Moneybird;

use Estasoft\SyncEngine\Connectors\Moneybird\MoneybirdConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeMoneybirdServer;
use PHPUnit\Framework\TestCase;

final class MoneybirdFlowTest extends TestCase {

	private ApiTestKit $kit;

	private FakeMoneybirdServer $fake;

	protected function setUp(): void {
		$this->kit  = ApiTestKit::make();
		$this->fake = new FakeMoneybirdServer( $this->kit->http );
		$this->fake->addProduct( 'CS-MUG-01', 'Mug' );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connector(): GuardedConnector {
		return GuardedConnector::wrap( new MoneybirdConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * @param array<string,mixed> $options Advanced settings.
	 */
	private function connection( array $options = array() ): Connection {
		return $this->kit->connection( 'moneybird', array( 'administration_id' => FakeMoneybirdServer::ADMINISTRATION, 'options' => $options ), array( 'api_token' => FakeMoneybirdServer::TOKEN ) );
	}

	/**
	 * @param array<string,mixed> $over Payload overrides.
	 * @return array<string,mixed>
	 */
	private function payload( array $over = array() ): array {
		return $over + array(
			'currency' => 'EUR',
			'date'     => '2026-11-02 23:30:00',
			'total'    => 5443,
			'customer' => array(
				'email'      => 'anna@example.test',
				'first_name' => 'Anna',
				'last_name'  => 'Beispiel',
				'street'     => 'Hoofdstraat 1',
				'zip'        => '1011 AB',
				'city'       => 'Amsterdam',
				'country'    => 'NL',
			),
			'lines'    => array(
				array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 2, 'total' => 3998, 'tax_code' => '501' ),
			),
			'shipping' => array( 'name' => 'Verzending', 'total' => 500, 'tax_code' => '501' ),
		);
	}

	private function order( string $id = '5001' ): Item {
		return new Item( (int) $id, 1, EntityType::Order, $id, 'created', 'order:' . $id, 'key-' . $id, ItemState::Syncing );
	}

	private function refundItem( string $id = '7001' ): Item {
		return new Item( (int) $id, 1, EntityType::Refund, $id, 'refunded', 'order:5001', 'key-r' . $id, ItemState::Syncing );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function body( string $pathRegex ): array {
		$requests = $this->fake->server->requests( 'POST', $pathRegex );
		$this->assertNotEmpty( $requests );
		return end( $requests )->json();
	}

	public function test_a_draft_invoice_has_the_reference_the_local_date_and_one_tax_rate_per_line(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $this->body( '#/sales_invoices\.json$#' )['sales_invoice'];
		$this->assertSame( 'WC-5001', $invoice['reference'] );
		$this->assertSame( '2026-11-03', $invoice['invoice_date'], 'Half past eleven in the evening UTC is already the third in Amsterdam.' );
		$this->assertFalse( $invoice['prices_are_incl_tax'] );
		$this->assertSame( 'EUR', $invoice['currency'] );
		$this->assertSame( array( 'description' => 'Mug', 'price' => '19.99', 'amount' => '2', 'tax_rate_id' => 501, 'product_id' => (int) $this->fake->products['CS-MUG-01']['id'] ), $invoice['details_attributes'][0] );
		$this->assertSame( 'Verzending', $invoice['details_attributes'][1]['description'] );
		$this->assertSame( 5443, $result->ref->extra['total_minor'] );
		$this->assertSame( 'draft', $result->ref->extra['state'] );
		$this->assertSame( '', $result->ref->ref, 'A draft has no invoice number yet.' );
		$this->assertSame( array(), $this->fake->server->requests( 'PATCH', '#send_invoice#' ) );
	}

	public function test_sent_mode_sends_it_manually_once_and_the_number_appears(): void {
		$connection = $this->connection( array( 'invoice_state' => 'sent' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );

		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 'open', $sent->ref->extra['state'] );
		$this->assertNotSame( '', $sent->ref->ref );
		$this->assertSame( 'Manual', $this->fake->server->requests( 'PATCH', '#send_invoice#' )[0]->json()['sales_invoice_sending']['delivery_method'] );

		$again = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->fake->server->requests( 'PATCH', '#send_invoice#' ), 'The send step is not repeated.' );
	}

	public function test_prices_with_tax_set_the_flag_and_are_not_converted(): void {
		$payload                         = $this->payload( array( 'prices_include_tax' => true, 'total' => 2380, 'shipping' => array() ) );
		$payload['lines'][0]['quantity'] = 1;
		$payload['lines'][0]['total']    = 2380;
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $this->body( '#/sales_invoices\.json$#' )['sales_invoice'];
		$this->assertTrue( $invoice['prices_are_incl_tax'] );
		$this->assertSame( '23.80', $invoice['details_attributes'][0]['price'] );
		$this->assertSame( 2380, $result->ref->extra['total_minor'] );
	}

	public function test_a_tax_rate_that_is_not_a_sales_rate_stops_the_order(): void {
		$payload                         = $this->payload();
		$payload['lines'][0]['tax_code'] = '9999';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/sales_invoices\.json$#' ) );
	}

	public function test_an_unknown_product_stops_the_order_or_becomes_a_free_line(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'NO-SUCH';
		$this->assertSame( ErrorCode::Map001, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );

		$free = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'custom' ) ), $this->order(), $payload );
		$this->assertTrue( $free->isSuccess() );
		$this->assertArrayNotHasKey( 'product_id', $this->body( '#/sales_invoices\.json$#' )['sales_invoice']['details_attributes'][0] );
	}

	public function test_ledger_accounts_workflow_and_style_are_sent_when_set(): void {
		$this->connector()->send( $this->connection( array( 'sales_ledger_account' => '111', 'shipping_ledger_account' => '222', 'workflow_id' => '9', 'document_style_id' => '5' ) ), $this->order(), $this->payload() );
		$invoice = $this->body( '#/sales_invoices\.json$#' )['sales_invoice'];

		$this->assertSame( 111, $invoice['details_attributes'][0]['ledger_account_id'] );
		$this->assertSame( 222, $invoice['details_attributes'][1]['ledger_account_id'] );
		$this->assertSame( 9, $invoice['workflow_id'] );
		$this->assertSame( 5, $invoice['document_style_id'] );
	}

	public function test_a_wrong_total_deletes_the_draft_and_names_both_totals(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload( array( 'total' => 9999 ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '99.99', $result->error->message() );
		$this->assertStringContainsString( '54.43', $result->error->message() );
		$this->assertCount( 0, $this->fake->invoices );

		$warn = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->order(), $this->payload( array( 'total' => 9999 ) ) );
		$this->assertTrue( $warn->isSuccess() );
		$this->assertSame( array( 'total_differs' ), $warn->ref->extra['warnings'] );
	}

	public function test_a_currency_moneybird_did_not_use_is_caught_after_the_draft_is_made(): void {
		$this->fake->forcedCurrency = 'EUR';
		$result                     = $this->connector()->send( $this->connection(), $this->order(), $this->payload( array( 'currency' => 'USD' ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', $result->error->message() );
		$this->assertStringContainsString( 'EUR', $result->error->message() );
		$this->assertCount( 0, $this->fake->invoices, 'The draft in the wrong currency was removed.' );
	}

	public function test_the_search_is_by_reference_and_a_retry_never_duplicates(): void {
		$first = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$again = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fake->invoices );
		$this->assertCount( 1, $this->fake->contacts );
		$searches = $this->fake->server->requests( 'GET', '#/find_by_reference/WC-5001\.json$#' );
		$this->assertCount( 2, $searches, 'Searched before every create.' );
	}

	public function test_findexisting_reports_only_finished_records(): void {
		$draft = $this->connection();
		$this->connector()->send( $draft, $this->order(), $this->payload() );
		$this->assertInstanceOf( ExternalRef::class, $this->connector()->findExisting( $draft, $this->order() ) );

		$sent = $this->connection( array( 'invoice_state' => 'sent' ) );
		$this->assertNull( $this->connector()->findExisting( $sent, $this->order() ), 'A draft is not finished when invoices are to be sent.' );
		$this->assertNull( $this->connector()->findExisting( $this->connection( array( 'payment_account' => '88' ) ), $this->order() ), 'An unpaid invoice is not finished when payment is wanted.' );

		$this->connector()->send( $sent, $this->order(), $this->payload() );
		$this->assertInstanceOf( ExternalRef::class, $this->connector()->findExisting( $sent, $this->order() ) );
	}

	public function test_a_contact_is_matched_by_exact_email_across_pages(): void {
		for ( $i = 0; $i < 120; $i++ ) {
			$id                         = 100 + $i;
			$this->fake->contacts[ $id ] = array( 'id' => (string) $id, 'firstname' => 'F' . $i, 'lastname' => 'L', 'email' => sprintf( 'u%03d.anna@example.test', $i ) );
		}
		$this->fake->contacts[900] = array( 'id' => '900', 'firstname' => 'Anna', 'lastname' => 'Beispiel', 'email' => 'Anna@Example.test', 'send_invoices_to_email' => 'anna@example.test' );
		$ref                        = $this->connector()->lookupCustomer( $this->connection(), 'anna@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( '900', $ref->id );
		$this->assertSame( 'Anna Beispiel', $ref->ref );
		$this->assertCount( 2, $this->fake->server->requests( 'GET', '#/contacts\.json$#' ) );

		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$this->assertSame( 900, $this->body( '#/sales_invoices\.json$#' )['sales_invoice']['contact_id'], 'The existing contact is used.' );
	}

	public function test_a_company_customer_and_the_policies(): void {
		$payload                        = $this->payload();
		$payload['customer']['company'] = 'Beispiel B.V.';
		$payload['customer']['vat_id']  = 'NL123456789B01';
		$this->connector()->send( $this->connection(), $this->order(), $payload );
		$contact = $this->body( '#/contacts\.json$#' )['contact'];

		$this->assertSame( 'Beispiel B.V.', $contact['company_name'] );
		$this->assertSame( 'NL123456789B01', $contact['tax_number'] );
		$this->assertSame( 'Hoofdstraat 1', $contact['address1'] );
		$this->assertSame( '1011 AB', $contact['zipcode'] );
		$this->assertSame( 'anna@example.test', $contact['send_invoices_to_email'] );

		$only = $this->connector()->send( $this->connection( array( 'customer_policy' => 'match_only' ) ), $this->order( '5002' ), $this->payload( array( 'customer' => array( 'email' => 'new@example.test', 'first_name' => 'N', 'last_name' => 'N', 'country' => 'NL' ) ) ) );
		$this->assertSame( ErrorCode::Val002, $only->error->code );
	}

	public function test_missing_email_is_a_mapping_error(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';

		$this->assertSame( ErrorCode::Map003, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );
	}

	public function test_a_payment_is_registered_once_and_only_when_the_invoice_is_not_a_draft(): void {
		$payload            = $this->payload();
		$payload['payment'] = array( 'date' => '2026-11-04 08:00:00', 'reference' => 'ch_1' );
		$paid               = $this->connection( array( 'invoice_state' => 'sent', 'payment_account' => '88' ) );
		$result             = $this->connector()->send( $paid, $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->fake->payments );
		$this->assertSame( '54.43', $this->fake->payments[0][1]['price'] );
		$this->assertSame( 88, $this->fake->payments[0][1]['financial_account_id'] );
		$this->assertSame( '2026-11-04', $this->fake->payments[0][1]['payment_date'] );
		$this->assertSame( 'paid', $result->ref->extra['state'] );

		$this->connector()->send( $paid, $this->order(), $payload );
		$this->assertCount( 1, $this->fake->payments, 'Not paid twice.' );

		$this->connector()->send( $this->connection( array( 'payment_account' => '88' ) ), $this->order( '5002' ), $payload );
		$this->assertCount( 1, $this->fake->payments, 'A draft cannot be paid.' );
	}

	/**
	 * A refund payload for the whole order.
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function refundPayload( array $over = array() ): array {
		return $over + array(
			'order_ref'  => 'WC-5001',
			'customer'   => array( 'email' => 'anna@example.test' ),
			'order_date' => '2026-11-02 23:30:00',
			'currency'   => 'EUR',
			'amount'     => 5443,
			'lines'      => array(),
		);
	}

	public function test_a_refund_of_the_whole_order_becomes_a_credit_invoice_with_its_own_reference(): void {
		$connection = $this->connection( array( 'invoice_state' => 'sent' ) );
		$orderSent = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$result    = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->fake->server->requests( 'PATCH', '#/duplicate_creditinvoice\.json$#' ) );
		$this->assertSame( '{}', $this->fake->server->requests( 'PATCH', '#/duplicate_creditinvoice\.json$#' )[0]->body );
		$credit = end( $this->fake->invoices );
		$this->assertSame( 'WC-7001', $credit['reference'], 'The reference was set straight after the copy.' );
		$this->assertNotEmpty( $credit['original_sales_invoice_id'] );
		$this->assertSame( 'open', $result->ref->extra['state'], 'Sent like the invoice (refund_state follows invoice_state).' );

		$again = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 2, $this->fake->invoices );
		$this->assertSame( $orderSent->ref->id, $this->connector()->findExisting( $connection, $this->order() )?->id, 'The order is still found by its own reference.' );
	}

	public function test_a_partial_refund_a_draft_original_and_a_missing_invoice_are_refused_clearly(): void {
		$none = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $none->error->code );

		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$draft = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertStringContainsString( 'draft', $draft->error->message() );

		$sent = $this->connection( array( 'invoice_state' => 'sent' ) );
		$this->connector()->send( $sent, $this->order( '5002' ), $this->payload() );
		$part = $this->connector()->send( $sent, $this->refundItem( '7002' ), $this->refundPayload( array( 'order_ref' => 'WC-5002', 'amount' => 1000 ) ) );
		$this->assertSame( ErrorCode::Val002, $part->error->code );
		$this->assertStringContainsString( 'whole lines', $part->error->message() );
		$this->assertSame( array(), $this->fake->server->requests( 'PATCH', '#/duplicate_creditinvoice#' ) );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:ErrorCode}>
	 */
	public static function provide_answers(): array {
		return array(
			'account limit'  => array( 402, '{"error":"Account limit reached"}', ErrorCode::Auth006 ),
			'ip blocked'     => array( 403, '{"error":"IP blacklisted"}', ErrorCode::Auth004 ),
			'unprocessable'  => array( 422, '{"error":{"contact_id":["is invalid"]}}', ErrorCode::Val002 ),
			'bad request'    => array( 400, '{"error":"Bad request"}', ErrorCode::Val002 ),
			'not acceptable' => array( 406, '', ErrorCode::Val002 ),
			'server error'   => array( 500, '', ErrorCode::Net002 ),
		);
	}

	/**
	 * @dataProvider provide_answers
	 *
	 * @param int       $status Status of the answer to the first write.
	 * @param string    $body   Body.
	 * @param ErrorCode $code   Expected code.
	 */
	public function test_error_answers_map_to_catalog_codes( int $status, string $body, ErrorCode $code ): void {
		$this->fake->server->failNext( 'POST', '', FakeHttpClient::status( $status, $body ) );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( $code, $result->error->code );
		$this->assertStringNotContainsString( FakeMoneybirdServer::TOKEN, $result->error->message() . $result->error->detail );
		$this->assertStringNotContainsString( FakeMoneybirdServer::ADMINISTRATION, $result->error->message() . $result->error->detail );
	}

	public function test_a_validation_answer_keeps_the_field_names(): void {
		$this->fake->server->failNext( 'POST', '', FakeHttpClient::status( 422, '{"error":{"contact_id":["is invalid"],"details":["too short"]}}' ) );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertStringContainsString( 'contact_id: is invalid', $result->error->message() );
		$this->assertStringContainsString( 'details: too short', $result->error->message() );
	}

	public function test_a_rate_limit_without_retry_after_waits_thirty_seconds(): void {
		$this->fake->failNextWrite( 'rate_limit' );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 30, $result->error->retryAfter );
	}

	public function test_the_token_is_a_bearer_header_only_and_every_path_names_the_administration(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( 'Bearer ' . FakeMoneybirdServer::TOKEN, $request['headers']['Authorization'] );
			$this->assertStringNotContainsString( FakeMoneybirdServer::TOKEN, $request['url'] );
			$this->assertMatchesRegularExpression( '#^https://moneybird\.com/api/v2/(administrations\.json|' . FakeMoneybirdServer::ADMINISTRATION . '/)#', $request['url'] );
		}
	}

	public function test_the_pacer_is_told_about_the_documented_limit(): void {
		$hints = $this->connector()->rateLimitHints();

		$this->assertSame( 0.5, $hints['requests_per_second'] );
		$this->assertSame( 1, $hints['concurrency'] );
	}

	public function test_test_connection_reports_the_administration_and_tax_codes_are_the_sales_rates(): void {
		$result = $this->connector()->test( $this->connection() );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'Beispiel B.V.', $result->company );
		$this->assertSame( 'Europe/Amsterdam', $result->detected['time_zone'] );

		$codes = $this->connector()->listTaxCodes( $this->connection() );
		$this->assertContains( '501', array_column( $codes, 'code' ) );
		$this->assertSame( '21', $codes[1]['rate_percent'] );
	}

	public function test_invoice_facts_and_product_helpers(): void {
		$connection = $this->connection( array( 'invoice_state' => 'sent' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$facts      = $this->connector()->fetchInvoice( $connection, $sent->ref );

		$this->assertSame( 'open', $facts['status'] );
		$this->assertSame( $sent->ref->ref, $facts['number'] );
		$this->assertNull( $this->connector()->fetchInvoice( $connection, new ExternalRef( '31337' ) ) );
		$this->assertSame( 'CS-MUG-01', $this->connector()->lookupProduct( $connection, 'CS-MUG-01' )->ref );
		$this->assertNull( $this->connector()->lookupProduct( $connection, 'NOPE' ) );
		$this->assertCount( 1, $this->connector()->searchProducts( $connection, 'mug' ) );
	}
}
