<?php
/**
 * Pennylane: draft-first invoices, finalising, customers, errors and the shape of what goes on the wire.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Pennylane;

use Estasoft\SyncEngine\Connectors\Pennylane\PennylaneConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakePennylaneServer;
use PHPUnit\Framework\TestCase;

final class PennylaneFlowTest extends TestCase {

	private ApiTestKit $kit;

	private FakePennylaneServer $fake;

	protected function setUp(): void {
		$this->kit  = ApiTestKit::make();
		$this->fake = new FakePennylaneServer( $this->kit->http );
		$this->fake->addProduct( 'CS-MUG-01', 'Mug' );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connector(): GuardedConnector {
		return GuardedConnector::wrap( new PennylaneConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * @param array<string,mixed> $options Advanced settings.
	 * @param array<string,mixed> $config  More config.
	 */
	private function connection( array $options = array(), array $config = array() ): Connection {
		return $this->kit->connection( 'pennylane', $config + array( 'token_lifetime' => '12', 'options' => $options ), array( 'api_token' => FakePennylaneServer::TOKEN ) );
	}

	/**
	 * A French order: 2 mugs and shipping, 20 percent VAT on both. Net 44.98, VAT 9.00, total 53.98.
	 *
	 * @param array<string,mixed> $over Payload overrides.
	 * @return array<string,mixed>
	 */
	private function payload( array $over = array() ): array {
		return $over + array(
			'currency' => 'EUR',
			'date'     => '2026-11-02 23:30:00',
			'total'    => 5398,
			'customer' => array(
				'email'      => 'anna@example.test',
				'first_name' => 'Anna',
				'last_name'  => 'Beispiel',
				'street'     => '1 rue de la Paix',
				'zip'        => '75002',
				'city'       => 'Paris',
				'country'    => 'FR',
			),
			'lines'    => array(
				array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 2, 'total' => 3998, 'tax_code' => 'FR_200' ),
			),
			'shipping' => array( 'name' => 'Livraison', 'total' => 500, 'tax_code' => 'FR_200' ),
		);
	}

	private function order( string $id = '5001' ): Item {
		return new Item( (int) $id, 1, EntityType::Order, $id, 'created', 'order:' . $id, 'key-' . $id, ItemState::Syncing );
	}

	/**
	 * The body of the last POST to a path.
	 *
	 * @return array<string,mixed>
	 */
	private function body( string $pathRegex ): array {
		$requests = $this->fake->server->requests( 'POST', $pathRegex );
		$this->assertNotEmpty( $requests );
		return end( $requests )->json();
	}

	public function test_the_invoice_is_made_as_a_draft_with_the_reference_the_local_date_and_text_amounts(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $this->body( '#/customer_invoices$#' );
		$this->assertTrue( $invoice['draft'] );
		$this->assertSame( 'WC-5001', $invoice['external_reference'] );
		$this->assertSame( '2026-11-03', $invoice['date'], 'Half past eleven in the evening UTC is already the third in Paris.' );
		$this->assertSame( '2026-12-03', $invoice['deadline'], 'Thirty days after the invoice date.' );
		$this->assertEquals(
			array(
				'label'                   => 'Mug',
				'quantity'                => 2,
				'unit'                    => 'piece',
				'raw_currency_unit_price' => '19.99',
				'vat_rate'                => 'FR_200',
			),
			$invoice['invoice_lines'][0]
		);
		$this->assertSame( 'Livraison', $invoice['invoice_lines'][1]['label'] );
		$this->assertSame( 'service', $invoice['invoice_lines'][1]['unit'] );
		$this->assertSame( '5.00', $invoice['invoice_lines'][1]['raw_currency_unit_price'], 'Amounts go out as text.' );
		$this->assertSame( 5398, $result->ref->extra['total_minor'] );
		$this->assertSame( 'draft', $result->ref->extra['status'] );
		$this->assertSame( '', $result->ref->ref, 'A draft has no invoice number yet.' );
		$this->assertSame( array(), $this->fake->server->requests( 'PUT', '#/finalize$#' ), 'A draft is not finalised.' );
	}

	public function test_finalised_mode_finalises_after_the_check_once_and_the_number_appears(): void {
		$connection = $this->connection( array( 'invoice_state' => 'finalized' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );

		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 'upcoming', $sent->ref->extra['status'] );
		$this->assertSame( 'F-2026-0001', $sent->ref->ref );
		$this->assertTrue( $this->body( '#/customer_invoices$#' )['draft'], 'It is still created as a draft first.' );
		$this->assertCount( 1, $this->fake->server->requests( 'PUT', '#/finalize$#' ) );

		$again = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $sent->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fake->server->requests( 'PUT', '#/finalize$#' ), 'The finalise step is not repeated.' );
		$this->assertCount( 1, $this->fake->invoices );
	}

	public function test_a_draft_left_by_an_earlier_pass_is_finalised_by_the_replay_and_findexisting_waits_for_it(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$finalised = $this->connection( array( 'invoice_state' => 'finalized' ) );

		$this->assertNull( $this->connector()->findExisting( $finalised, $this->order() ), 'A draft is not finished when invoices are to be finalised.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector()->findExisting( $this->connection(), $this->order() ) );

		$replay = $this->connector()->send( $finalised, $this->order(), $this->payload() );
		$this->assertTrue( $replay->alreadyExisted );
		$this->assertSame( 'F-2026-0001', $replay->ref->ref );
		$this->assertCount( 1, $this->fake->invoices, 'No second invoice.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector()->findExisting( $finalised, $this->order() ) );
	}

	public function test_a_finalise_call_that_is_refused_leaves_the_draft_and_says_so(): void {
		$this->fake->canFinalize = false;
		$result                  = $this->connector()->send( $this->connection( array( 'invoice_state' => 'finalized' ) ), $this->order(), $this->payload() );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 1, $this->fake->invoices, 'The draft stays; a retry finds it.' );
		$this->assertTrue( $this->fake->invoices[ array_key_first( $this->fake->invoices ) ]['draft'] );
	}

	public function test_prices_with_tax_are_converted_to_net_by_the_rate_of_the_code(): void {
		$payload                         = $this->payload( array( 'prices_include_tax' => true, 'total' => 2400, 'shipping' => array() ) );
		$payload['lines'][0]['quantity'] = 1;
		$payload['lines'][0]['total']    = 2400;
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '20.00', $this->body( '#/customer_invoices$#' )['invoice_lines'][0]['raw_currency_unit_price'] );
		$this->assertSame( 2400, $result->ref->extra['total_minor'] );
	}

	public function test_prices_with_tax_and_a_code_whose_rate_is_not_known_ask_for_the_rate_field(): void {
		$payload                          = $this->payload( array( 'prices_include_tax' => true, 'shipping' => array() ) );
		$payload['lines'][0]['tax_code']  = 'MY_CODE';
		$result                           = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'tax rate', $result->error->message() );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/customer_invoices$#' ) );

		$payload['lines'][0]['tax_rate'] = 20;
		$payload['lines'][0]['total']    = 2400;
		$payload['lines'][0]['quantity'] = 1;
		$payload['total']                = 2400;
		$this->fake->vat['MY_CODE']      = 2000;
		$fixed                           = $this->connector()->send( $this->connection(), $this->order( '5002' ), $payload );
		$this->assertTrue( $fixed->isSuccess(), (string) $fixed->error?->message() );
		$this->assertSame( '20.00', $this->body( '#/customer_invoices$#' )['invoice_lines'][0]['raw_currency_unit_price'] );
	}

	public function test_a_vat_code_that_cannot_be_one_is_a_mapping_error_and_one_pennylane_refuses_is_too(): void {
		$payload                         = $this->payload();
		$payload['lines'][0]['tax_code'] = 'FR 200';
		$bad                             = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$this->assertSame( ErrorCode::Map002, $bad->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/customer_invoices$#' ) );

		$payload['lines'][0]['tax_code'] = 'FR_999';
		$refused                         = $this->connector()->send( $this->connection(), $this->order( '5002' ), $payload );
		$this->assertSame( ErrorCode::Map002, $refused->error->code, 'The 422 for an invalid VAT code.' );
		$this->assertSame( array(), $this->fake->invoices );
	}

	public function test_shipping_without_a_code_takes_the_code_of_the_first_line(): void {
		$payload                         = $this->payload();
		$payload['shipping']['tax_code'] = '';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'FR_200', $this->body( '#/customer_invoices$#' )['invoice_lines'][1]['vat_rate'] );
	}

	public function test_an_unknown_product_stops_the_order_or_becomes_a_free_line(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'NO-SUCH';
		$this->assertSame( ErrorCode::Map001, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/customer_invoices$#' ) );

		$free = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'custom' ) ), $this->order(), $payload );
		$this->assertTrue( $free->isSuccess() );
		$lookup = $this->fake->server->requests( 'GET', '#/products$#' )[0];
		$this->assertSame( '[{"field":"reference","operator":"eq","value":"NO-SUCH"}]', $lookup->query['filter'] );
	}

	public function test_a_line_that_cannot_be_divided_exactly_becomes_one_line_of_quantity_one(): void {
		$payload                         = $this->payload( array( 'total' => 1200, 'shipping' => array() ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1000;
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->body( '#/customer_invoices$#' )['invoice_lines'][0];
		$this->assertSame( '3 × Mug', $line['label'] );
		$this->assertSame( '10.00', $line['raw_currency_unit_price'] );
		$this->assertEquals( 1, $line['quantity'] );
		$this->assertSame( array( 'line_split:1' ), $result->ref->extra['warnings'] );
		$this->assertSame( 1200, $result->ref->extra['total_minor'] );
	}

	public function test_ledger_accounts_and_the_template_are_sent_only_when_set(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$plain = $this->body( '#/customer_invoices$#' );
		$this->assertArrayNotHasKey( 'ledger_account_id', $plain['invoice_lines'][0] );
		$this->assertArrayNotHasKey( 'customer_invoice_template_id', $plain );

		$options = array( 'sales_ledger_account' => '111', 'shipping_ledger_account' => '222', 'customer_invoice_template_id' => '7', 'payment_days' => 14 );
		$this->connector()->send( $this->connection( $options ), $this->order( '5002' ), $this->payload() );
		$invoice = $this->body( '#/customer_invoices$#' );
		$this->assertSame( 111, $invoice['invoice_lines'][0]['ledger_account_id'] );
		$this->assertSame( 222, $invoice['invoice_lines'][1]['ledger_account_id'] );
		$this->assertSame( 7, $invoice['customer_invoice_template_id'] );
		$this->assertSame( '2026-11-17', $invoice['deadline'] );
	}

	public function test_a_wrong_total_stops_before_finalising_names_both_totals_and_the_retry_carries_on(): void {
		$connection              = $this->connection( array( 'invoice_state' => 'finalized' ) );
		$this->fake->vat['FR_200'] = 1000;
		$result                  = $this->connector()->send( $connection, $this->order(), $this->payload() );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '53.98', $result->error->message() );
		$this->assertStringContainsString( '49.48', $result->error->message() );
		$this->assertSame( array(), $this->fake->server->requests( 'PUT', '#/finalize$#' ), 'Nothing is finalised on a wrong total.' );
		$this->assertCount( 1, $this->fake->invoices );

		$this->fake->vat['FR_200'] = 2000;
		$this->fake->invoices[ array_key_first( $this->fake->invoices ) ]['currency_amount'] = '53.98';
		$retry = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->fake->invoices, 'The retry used the draft that was already there.' );
		$this->assertCount( 1, $this->fake->server->requests( 'PUT', '#/finalize$#' ) );
	}

	public function test_the_warn_setting_keeps_the_invoice_and_says_the_totals_differ(): void {
		$this->fake->vat['FR_200'] = 1000;
		$result                    = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array( 'total_differs' ), $result->ref->extra['warnings'] );
		$this->assertSame( 4948, $result->ref->extra['total_minor'] );
	}

	public function test_a_currency_other_than_euro_is_refused_before_any_request(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload( array( 'currency' => 'GBP' ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'GBP', $result->error->message() );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
	}

	public function test_the_search_is_by_external_reference_and_a_retry_never_duplicates(): void {
		$first = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$again = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fake->invoices );
		$this->assertCount( 1, $this->fake->customers );
		$searches = array_filter(
			$this->fake->server->requests( 'GET', '#/customer_invoices$#' ),
			static fn( $request ): bool => '[{"field":"external_reference","operator":"eq","value":"WC-5001"}]' === ( $request->query['filter'] ?? '' )
		);
		$this->assertCount( 2, $searches, 'Searched before every create.' );
	}

	public function test_a_credit_note_with_the_same_reference_is_not_taken_for_the_invoice(): void {
		$this->fake->invoices[7001] = array( 'id' => 7001, 'status' => 'upcoming', 'draft' => false, 'credit_note' => true, 'external_reference' => 'WC-5001', 'currency' => 'EUR', 'currency_amount' => '10.00' );
		$result                     = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertNotSame( '7001', $result->ref->id );
	}

	public function test_a_returning_buyer_is_found_by_the_reference_made_from_the_email_and_never_updated(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$second = $this->payload();
		$second['customer']['email'] = 'ANNA@example.test ';
		$this->connector()->send( $this->connection(), $this->order( '5002' ), $second );

		$this->assertCount( 1, $this->fake->customers, 'Same buyer, same customer, whatever the case of the email.' );
		$this->assertCount( 1, $this->fake->server->requests( 'POST', '#/individual_customers$#' ) );
		$this->assertSame( array(), $this->fake->server->requests( 'PUT', '#/customers#' ) );
		$this->assertSame( array(), $this->fake->server->requests( 'PATCH' ) );
		$customer = array_values( $this->fake->customers )[0];
		$this->assertMatchesRegularExpression( '/^WC-C-[0-9a-f]{16}$/', $customer['external_reference'] );
	}

	public function test_an_individual_and_a_company_customer_are_different_resources(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$person = $this->body( '#/individual_customers$#' );
		$this->assertSame( 'Anna', $person['first_name'] );
		$this->assertSame( 'Beispiel', $person['last_name'] );
		$this->assertSame( array( 'anna@example.test' ), $person['emails'] );
		$this->assertSame( array( 'address' => '1 rue de la Paix', 'postal_code' => '75002', 'city' => 'Paris', 'country_alpha2' => 'FR' ), $person['billing_address'] );

		$payload                        = $this->payload();
		$payload['customer']['email']   = 'achats@exemple.test';
		$payload['customer']['company'] = 'Exemple SAS';
		$payload['customer']['vat_id']  = 'FR12345678901';
		$this->connector()->send( $this->connection(), $this->order( '5002' ), $payload );
		$company = $this->body( '#/company_customers$#' );
		$this->assertSame( 'Exemple SAS', $company['name'] );
		$this->assertSame( 'FR12345678901', $company['vat_number'] );
		$this->assertArrayNotHasKey( 'first_name', $company );
	}

	public function test_the_customer_policies(): void {
		$only = $this->connector()->send( $this->connection( array( 'customer_policy' => 'match_only' ) ), $this->order(), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $only->error->code );
		$this->assertSame( array(), $this->fake->customers );

		$this->connector()->send( $this->connection( array( 'customer_policy' => 'always_create' ) ), $this->order( '5002' ), $this->payload() );
		$this->connector()->send( $this->connection( array( 'customer_policy' => 'always_create' ) ), $this->order( '5003' ), $this->payload() );
		$this->assertCount( 2, $this->fake->customers, 'Every order gets its own customer.' );

		$this->fake->customers[55] = array( 'id' => 55, 'customer_type' => 'company', 'name' => 'Boutique en ligne' );
		$collective                = $this->connection( array( 'customer_policy' => 'collective', 'collective_customer_id' => '55' ) );
		$this->connector()->send( $collective, $this->order( '5004' ), $this->payload() );
		$this->assertSame( 55, $this->body( '#/customer_invoices$#' )['customer_id'] );
		$this->assertCount( 3, $this->fake->customers, 'The collective customer and the two made for the other orders; none was made for the collective one.' );

		$missing = $this->connector()->send( $this->connection( array( 'customer_policy' => 'collective', 'collective_customer_id' => '999' ) ), $this->order( '5005' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
	}

	public function test_missing_email_is_a_mapping_error(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';

		$this->assertSame( ErrorCode::Map003, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
	}

	public function test_a_customer_item_is_created_or_found(): void {
		$item = new Item( 77, 1, EntityType::Customer, '77', 'created', 'customer:77', 'key-c77', ItemState::Syncing );
		$new  = $this->connector()->send( $this->connection(), $item, $this->payload() );
		$this->assertTrue( $new->isSuccess(), (string) $new->error?->message() );
		$this->assertFalse( $new->alreadyExisted );
		$this->assertSame( 'Anna Beispiel', $new->ref->ref );

		$again = $this->connector()->send( $this->connection(), $item, $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $new->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fake->customers );
	}

	public function test_refunds_are_not_offered_and_a_refund_item_is_refused_without_a_request(): void {
		$flags = $this->connector()->capabilities();
		$this->assertFalse( $flags[ Capability::Refunds->value ] );
		$this->assertNotContains( EntityType::Refund, $this->connector()->supportedEntities() );

		$item   = new Item( 7001, 1, EntityType::Refund, '7001', 'refunded', 'order:5001', 'key-r7001', ItemState::Syncing );
		$result = $this->connector()->send( $this->connection(), $item, array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:ErrorCode}>
	 */
	public static function provide_answers(): array {
		return array(
			'bad request'     => array( 400, '{"error":"bad_request","message":"Invalid payload"}', ErrorCode::Val002 ),
			'conflict'        => array( 409, '{"error":"conflict","message":"Reference already used"}', ErrorCode::Val002 ),
			'unprocessable'   => array( 422, '{"error":"unprocessable_entity","message":"Totals do not match"}', ErrorCode::Val002 ),
			'vat refused'     => array( 422, '{"error":"unprocessable_entity","message":"Invalid VAT code","details":{"field":"vat_rate"}}', ErrorCode::Map002 ),
			'missing scope'   => array( 403, '{"error":"forbidden","message":"Missing scope: customer_invoices:all"}', ErrorCode::Auth004 ),
			'token expired'   => array( 401, '{"error":"unauthorized","message":"Token expired"}', ErrorCode::Auth005 ),
			'token refused'   => array( 401, '{"error":"unauthorized","message":"Invalid token"}', ErrorCode::Auth001 ),
			'server error'    => array( 500, '{"error":"internal_server_error"}', ErrorCode::Net002 ),
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
		$this->fake->server->failNext( 'POST', '', FakeHttpClient::status( $status, $body, array( 'content-type' => 'application/json' ) ) );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( $code, $result->error->code );
		$this->assertStringNotContainsString( FakePennylaneServer::TOKEN, $result->error->message() . $result->error->detail );
	}

	public function test_a_missing_scope_names_the_scope_the_token_lacks(): void {
		$this->fake->scopes = array( 'customers:all', 'products:readonly' );
		$result             = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringContainsString( 'customer_invoices:all', $result->error->message() );
	}

	public function test_a_validation_answer_keeps_the_field_and_the_reason_short(): void {
		$this->fake->server->failNext( 'POST', '', FakeHttpClient::json( array( 'error' => 'unprocessable_entity', 'message' => 'Validation failed', 'details' => array( 'field' => 'deadline', 'issue' => 'before date' ) ), 422 ) );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertStringContainsString( 'deadline: before date', $result->error->message() );
	}

	public function test_a_rate_limit_without_retry_after_waits_thirty_seconds(): void {
		$this->fake->failNextWrite( 'rate_limit' );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 30, $result->error->retryAfter );
	}

	public function test_an_expired_token_is_reported_by_the_test_and_by_a_send(): void {
		$this->fake->expired = true;

		$this->assertSame( ErrorCode::Auth005, $this->connector()->test( $this->connection() )->error->code );
		$this->assertSame( ErrorCode::Auth005, $this->connector()->send( $this->connection(), $this->order(), $this->payload() )->error->code );
	}

	public function test_the_token_is_a_bearer_header_only_and_every_url_is_under_the_v2_base(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( 'Bearer ' . FakePennylaneServer::TOKEN, $request['headers']['Authorization'] );
			$this->assertStringNotContainsString( FakePennylaneServer::TOKEN, $request['url'] );
			$this->assertStringStartsWith( 'https://app.pennylane.com/api/external/v2/', $request['url'] );
		}
	}

	public function test_the_pacer_is_told_about_the_documented_limit(): void {
		$hints = $this->connector()->rateLimitHints();

		$this->assertSame( 4.0, $hints['requests_per_second'] );
		$this->assertSame( 1, $hints['concurrency'] );
	}

	public function test_test_connection_reports_the_company_the_scopes_and_the_token_end_date(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'Exemple SAS', $result->company );
		$this->assertSame( 'API v2', $result->version );
		$this->assertSame( array( 'customers:all', 'customer_invoices:all', 'products:readonly' ), $result->detected['scopes'] );
		$this->assertSame( $this->kit->clock->now()->modify( '+12 months' )->format( 'Y-m-d H:i:s' ), $result->detected['credential_expires_at'], 'Twelve months from the (fake) clock.' );
		$this->assertSame( '2027-11-02', substr( $result->detected['credential_expires_at'], 0, 10 ) );
		$this->assertSame( array(), $result->missingPermissions() );

		$short = $this->connector()->test( $this->connection( array(), array( 'token_lifetime' => '1' ) ) );
		$this->assertSame( $this->kit->clock->now()->modify( '+1 month' )->format( 'Y-m-d H:i:s' ), $short->detected['credential_expires_at'] );

		$unlimited = $this->connector()->test( $this->connection( array(), array( 'token_lifetime' => 'unlimited' ) ) );
		$this->assertArrayNotHasKey( 'credential_expires_at', $unlimited->detected );
	}

	public function test_a_token_without_the_invoice_scope_is_shown_as_a_missing_permission(): void {
		$this->fake->scopes = array( 'products:readonly' );
		$result             = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, 'Scope names are read from an answer that could not be verified, so the screen warns and does not block.' );
		$this->assertSame( array( 'customers', 'customer_invoices' ), $result->missingPermissions() );
	}

	public function test_tax_codes_are_the_french_codes_and_the_unconfirmed_ones_say_so(): void {
		$codes = $this->connector()->listTaxCodes( $this->connection() );

		$this->assertSame( array( 'FR_200', 'FR_100', 'FR_55', 'FR_21', 'exempt' ), array_column( $codes, 'code' ) );
		$this->assertStringNotContainsString( 'confirmed', $codes[0]['name'] );
		$this->assertStringContainsString( 'confirmed', $codes[1]['name'] );
	}

	public function test_the_field_catalog_offers_the_line_tax_rate_and_none_for_refunds(): void {
		$this->assertContains( 'lines[].tax_rate', array_column( $this->connector()->describeFields( 'orders' ), 'path' ) );
		$this->assertSame( array(), $this->connector()->describeFields( 'refunds' ) );
		$this->assertSame( array(), $this->connector()->defaultMapping( 'refunds' ) );
	}

	public function test_invoice_facts_and_product_helpers(): void {
		$draft = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$facts = $this->connector()->fetchInvoice( $this->connection(), $draft->ref );
		$this->assertSame( 'draft', $facts['status'] );
		$this->assertSame( '', $facts['number'] );
		$this->assertSame( '', $facts['issued_at'] );

		$final = $this->connector()->send( $this->connection( array( 'invoice_state' => 'finalized' ) ), $this->order( '5002' ), $this->payload() );
		$facts = $this->connector()->fetchInvoice( $this->connection(), $final->ref );
		$this->assertSame( 'F-2026-0001', $facts['number'], 'A draft takes no number, so this is the first one given.' );
		$this->assertSame( '2026-11-03', $facts['issued_at'] );

		$this->assertNull( $this->connector()->fetchInvoice( $this->connection(), new ExternalRef( '31337' ) ) );
		$this->assertSame( 'CS-MUG-01', $this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' )->ref );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), 'NOPE' ) );
		$this->assertCount( 1, $this->connector()->searchProducts( $this->connection(), 'Mug' ) );
		$this->assertCount( 0, $this->connector()->searchProducts( $this->connection(), 'mu' ), 'The filters match whole values, not parts.' );
	}

	public function test_the_night_list_pages_through_with_the_same_filter_and_keeps_only_this_shops_invoices(): void {
		for ( $i = 1; $i <= 130; $i++ ) {
			$id                        = 9000 + $i;
			$this->fake->invoices[ $id ] = array( 'id' => $id, 'invoice_number' => 'F-' . $i, 'status' => 'upcoming', 'draft' => false, 'credit_note' => false, 'external_reference' => 'WC-' . $i, 'date' => '2026-10-05', 'currency' => 'EUR', 'currency_amount' => '12.30' );
		}
		$this->fake->invoices[9500] = array( 'id' => 9500, 'status' => 'upcoming', 'draft' => false, 'credit_note' => false, 'external_reference' => 'manual-1', 'date' => '2026-10-05', 'currency' => 'EUR', 'currency_amount' => '1.00' );
		$this->fake->invoices[9501] = array( 'id' => 9501, 'status' => 'upcoming', 'draft' => false, 'credit_note' => true, 'external_reference' => 'WC-900', 'date' => '2026-10-05', 'currency' => 'EUR', 'currency_amount' => '5.00' );
		$this->fake->invoices[9502] = array( 'id' => 9502, 'status' => 'upcoming', 'draft' => false, 'credit_note' => false, 'external_reference' => 'WC-901', 'date' => '2026-09-30', 'currency' => 'EUR', 'currency_amount' => '5.00' );

		$list = $this->connector()->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );

		$this->assertCount( 130, $list, 'Two pages, no manual invoice, no credit note, nothing before the window.' );
		$this->assertSame( 1230, $list['WC-7']->extra['total_minor'] );
		$this->assertSame( 'F-7', $list['WC-7']->ref );
		$pages = $this->fake->server->requests( 'GET', '#/customer_invoices$#' );
		$this->assertCount( 2, $pages );
		$this->assertSame( $pages[0]->query['filter'], $pages[1]->query['filter'], 'The cursor does not hold the filter, so every page sends it.' );
		$this->assertArrayHasKey( 'cursor', $pages[1]->query );
	}
}
