<?php
/**
 * Lexware Office: invoices, contacts, credit notes, errors and the shape of what goes on the wire.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\LexwareOffice;

use Estasoft\SyncEngine\Connectors\LexwareOffice\LexwareOfficeConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeLexwareOfficeServer;
use PHPUnit\Framework\TestCase;

final class LexwareOfficeFlowTest extends TestCase {

	private ApiTestKit $kit;

	private FakeLexwareOfficeServer $fake;

	protected function setUp(): void {
		$this->kit  = ApiTestKit::make();
		$this->fake = new FakeLexwareOfficeServer( $this->kit->http );
		$this->fake->addArticle( 'CS-MUG-01', 'Mug' );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * A fresh connector instance (as a new PHP request would have), wrapped as the registry does.
	 */
	private function connector(): GuardedConnector {
		return GuardedConnector::wrap( new LexwareOfficeConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * @param array<string,mixed> $options Advanced settings.
	 * @param array<string,mixed> $config  Other config.
	 */
	private function connection( array $options = array(), array $config = array() ): Connection {
		return $this->kit->connection( 'lexware-office', $config + array( 'options' => $options ), array( 'api_key' => FakeLexwareOfficeServer::KEY ) );
	}

	/**
	 * @param array<string,mixed> $over Payload overrides.
	 * @return array<string,mixed>
	 */
	private function payload( array $over = array() ): array {
		return $over + array(
			'currency' => 'EUR',
			'date'     => '2026-11-02 10:30:00',
			'total'    => 5353,
			'coupons'  => 'SPRING',
			'customer' => array(
				'email'      => 'anna@example.test',
				'first_name' => 'Anna',
				'last_name'  => 'Beispiel',
				'street'     => 'Musterstraße 1',
				'zip'        => '10115',
				'city'       => 'Berlin',
				'country'    => 'DE',
			),
			'lines'    => array(
				array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 2, 'total' => 3998, 'tax_code' => '19' ),
			),
			'shipping' => array( 'name' => 'Versand', 'total' => 500, 'tax_code' => '19' ),
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
	private function lastBody( string $path ): array {
		$requests = $this->fake->server->requests( 'POST', $path );
		$this->assertNotEmpty( $requests );
		return end( $requests )->json();
	}

	public function test_an_invoice_has_the_documented_shape_and_the_reference_on_its_first_line(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->lastBody( '#^/v1/invoices$#' );
		$this->assertStringStartsWith( "Bestellung WC-5001\n", $body['introduction'] . "\n" );
		$this->assertSame( 'net', $body['taxConditions']['taxType'] );
		$this->assertSame( '2026-11-02T00:00:00.000+01:00', $body['voucherDate'], 'The German calendar day of the order.' );
		$this->assertSame( 'delivery', $body['shippingConditions']['shippingType'] );
		$this->assertSame( 'EUR', $body['totalPrice']['currency'] );
		$this->assertSame( 'material', $body['lineItems'][0]['type'] );
		$this->assertNotSame( '', $body['lineItems'][0]['id'] );
		$this->assertEquals( 19.99, $body['lineItems'][0]['unitPrice']['netAmount'] );
		$this->assertEquals( 19, $body['lineItems'][0]['unitPrice']['taxRatePercentage'] );
		$this->assertSame( 'custom', $body['lineItems'][1]['type'], 'Shipping is a free line.' );
		$this->assertStringContainsString( 'Gutschein: SPRING', $body['remark'] );
		$this->assertSame( 5353, $result->ref->extra['total_minor'] );
		$this->assertSame( 'draft', $result->ref->extra['status'], 'The default is a draft.' );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#^/v1/invoices$#' )[0]->query, 'No finalize in draft mode.' );
	}

	public function test_open_mode_finalises_with_the_query_parameter(): void {
		$result = $this->connector()->send( $this->connection( array( 'invoice_state' => 'open' ) ), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'true', $this->fake->server->requests( 'POST', '#^/v1/invoices$#' )[0]->query['finalize'] );
		$this->assertSame( 'open', $result->ref->extra['status'] );
		$this->assertNotSame( '', $result->ref->ref );
	}

	public function test_gross_prices_use_the_gross_tax_type_and_gross_amounts(): void {
		$payload                       = $this->payload( array( 'prices_include_tax' => true, 'total' => 1190, 'shipping' => array(), 'coupons' => '' ) );
		$payload['lines'][0]['quantity'] = 1;
		$payload['lines'][0]['total']    = 1190;
		$result                        = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->lastBody( '#^/v1/invoices$#' );
		$this->assertSame( 'gross', $body['taxConditions']['taxType'] );
		$this->assertEquals( 11.9, $body['lineItems'][0]['unitPrice']['grossAmount'] );
		$this->assertArrayNotHasKey( 'netAmount', $body['lineItems'][0]['unitPrice'] );
		$this->assertSame( 1190, $result->ref->extra['total_minor'] );
	}

	public function test_a_special_tax_type_applies_to_the_whole_document(): void {
		$payload                         = $this->payload( array( 'total' => 4498, 'coupons' => '' ) );
		$payload['lines'][0]['tax_code'] = 'intraCommunitySupply';
		$payload['shipping']['tax_code'] = 'intraCommunitySupply';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->lastBody( '#^/v1/invoices$#' );
		$this->assertSame( 'intraCommunitySupply', $body['taxConditions']['taxType'] );
		$this->assertEquals( 0, $body['lineItems'][0]['unitPrice']['taxRatePercentage'] );
		$this->assertSame( 4498, $result->ref->extra['total_minor'] );
	}

	public function test_a_mix_of_a_percentage_and_a_special_type_is_refused_before_anything_is_created(): void {
		$payload                         = $this->payload();
		$payload['shipping']['tax_code'] = 'vatfree';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#^/v1/invoices$#' ) );
	}

	public function test_an_unknown_sku_stops_the_order_or_becomes_a_free_line(): void {
		$payload                       = $this->payload();
		$payload['lines'][0]['sku']    = 'NO-SUCH';
		$refused                       = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#^/v1/invoices$#' ), 'Nothing is sent for an order with an unknown product.' );

		$free = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'custom' ) ), $this->order(), $payload );
		$this->assertTrue( $free->isSuccess(), (string) $free->error?->message() );
		$this->assertSame( 'custom', $this->lastBody( '#^/v1/invoices$#' )['lineItems'][0]['type'] );
	}

	public function test_an_unmapped_tax_code_stops_the_order(): void {
		$payload                       = $this->payload();
		$payload['lines'][0]['tax_code'] = 'banana';
		$result                        = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#^/v1/invoices$#' ) );
	}

	public function test_a_rate_lexware_refuses_is_a_tax_mapping_problem(): void {
		$payload                       = $this->payload( array( 'total' => 4626 ) );
		$payload['lines'][0]['tax_code'] = '16';
		$payload['shipping']['tax_code'] = '16';
		$result                        = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertSame( ErrorCode::Map002, $result->error->code, 'The 406 names a tax rate problem.' );
	}

	public function test_a_wrong_total_is_not_synced_and_never_duplicates(): void {
		$payload = $this->payload( array( 'total' => 9999 ) );
		$first   = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertStringContainsString( '99.99', $first->error->message() );
		$this->assertStringContainsString( '53.53', $first->error->message() );
		$this->assertCount( 1, $this->fake->invoices, 'The draft stays in Lexware for a person to remove.' );

		$again = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->fake->invoices, 'The next pass found the draft by its reference and made no second one.' );

		$warn = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->order(), $payload );
		$this->assertTrue( $warn->isSuccess() );
		$this->assertSame( array( 'total_differs' ), $warn->ref->extra['warnings'] );
		$this->assertCount( 1, $this->fake->invoices );
	}

	public function test_a_retry_in_a_new_process_finds_the_invoice_by_its_reference(): void {
		$first = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$again = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fake->invoices );
		$this->assertCount( 1, $this->fake->contacts );
	}

	public function test_the_reference_is_matched_as_a_whole_word(): void {
		$this->connector()->send( $this->connection(), $this->order( '50' ), $this->payload() );
		$other = $this->connector()->send( $this->connection(), $this->order( '5' ), $this->payload() );

		$this->assertFalse( $other->alreadyExisted, 'WC-5 is not WC-50.' );
		$this->assertCount( 2, $this->fake->invoices );
	}

	public function test_findexisting_answers_from_the_engines_link_and_never_invents_a_record(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->order( '5100' ) ) );

		$sent   = $connector->send( $this->connection(), $this->order( '5100' ), $this->payload() );
		$linked = new Item( 5100, 1, EntityType::Order, '5100', 'created', 'order:5100', 'k', ItemState::Retrying, \Estasoft\SyncEngine\Domain\ItemMode::Live, 1, 5, null, null, $sent->ref->id, 'RE0001' );
		$found  = $this->connector()->findExisting( $this->connection(), $linked );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );

		$gone = new Item( 5101, 1, EntityType::Order, '5101', 'created', 'order:5101', 'k', ItemState::Retrying, \Estasoft\SyncEngine\Domain\ItemMode::Live, 1, 5, null, null, 'deleted-id', null );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $gone ) );
	}

	public function test_a_contact_is_found_by_email_exactly_and_without_case(): void {
		$this->connector()->send( $this->connection(), $this->order( '5201' ), $this->payload() );
		$upper                          = $this->payload();
		$upper['customer']['email']     = 'ANNA@Example.test';
		$this->connector()->send( $this->connection(), $this->order( '5202' ), $upper );
		$this->assertCount( 1, $this->fake->contacts, 'The same person, whatever the case.' );

		$other                          = $this->payload();
		$other['customer']['email']     = 'nna@example.test';
		$this->connector()->send( $this->connection(), $this->order( '5203' ), $other );
		$this->assertCount( 2, $this->fake->contacts, 'nna@ is inside anna@ for the substring filter but is another person.' );
	}

	public function test_the_search_pages_through_every_contact(): void {
		for ( $i = 0; $i < 259; $i++ ) {
			$id                         = sprintf( '00000000-0000-0000-0000-%012d', $i );
			$this->fake->contacts[ $id ] = array( 'id' => $id, 'roles' => array( 'customer' => array( 'number' => 20000 + $i ) ), 'person' => array( 'lastName' => 'Other' ), 'emailAddresses' => array( 'private' => array( sprintf( 'user%03d.anna@example.test', $i ) ) ) );
		}
		$target                               = '99999999-0000-0000-0000-000000000000';
		$this->fake->contacts[ $target ] = array( 'id' => $target, 'roles' => array( 'customer' => array( 'number' => 30000 ) ), 'person' => array( 'lastName' => 'Beispiel' ), 'emailAddresses' => array( 'private' => array( 'anna@example.test' ) ) );
		$ref                                  = $this->connector()->lookupCustomer( $this->connection(), 'anna@example.test' );

		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( $target, $ref->id, 'The match was on the second page of 250.' );
		$this->assertCount( 2, $this->fake->server->requests( 'GET', '#^/v1/contacts$#' ) );
	}

	public function test_two_contacts_with_one_email_use_the_oldest_or_stop(): void {
		foreach ( array( 'b' => 20002, 'a' => 20001 ) as $key => $number ) {
			$id                         = '1111111' . $key . '-0000-0000-0000-000000000000';
			$this->fake->contacts[ $id ] = array( 'id' => $id, 'roles' => array( 'customer' => array( 'number' => $number ) ), 'person' => array( 'lastName' => 'Dup' ), 'emailAddresses' => array( 'private' => array( 'anna@example.test' ) ) );
		}
		$ok = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$this->assertTrue( $ok->isSuccess() );
		$this->assertSame( '1111111a-0000-0000-0000-000000000000', $this->lastBody( '#^/v1/invoices$#' )['address']['contactId'], 'The lowest customer number.' );

		$stop = $this->connector()->send( $this->connection( array( 'duplicate_customer' => 'fail' ) ), $this->order( '5002' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $stop->error->code );
	}

	public function test_wildcard_characters_in_an_email_are_escaped(): void {
		$this->connector()->lookupCustomer( $this->connection(), 'a_b%c@example.test' );
		$query = $this->fake->server->requests( 'GET', '#^/v1/contacts$#' )[0]->query;

		$this->assertSame( 'a\_b\%c@example.test', $query['email'] );
		$this->assertSame( 'true', $query['customer'] );
	}

	public function test_a_company_customer_is_made_with_a_contact_person(): void {
		$payload                         = $this->payload();
		$payload['customer']['company']  = 'Beispiel GmbH';
		$payload['customer']['vat_id']   = 'DE123456789';
		$this->connector()->send( $this->connection(), $this->order(), $payload );
		$body = $this->lastBody( '#^/v1/contacts$#' );

		$this->assertSame( 'Beispiel GmbH', $body['company']['name'] );
		$this->assertSame( 'DE123456789', $body['company']['vatRegistrationId'] );
		$this->assertSame( 'Beispiel', $body['company']['contactPersons'][0]['lastName'] );
		$this->assertSame( array( 'anna@example.test' ), $body['emailAddresses']['business'] );
		$this->assertSame( 'DE', $body['addresses']['billing'][0]['countryCode'] );
		$this->assertSame( 0, $body['version'] );
		$this->assertArrayHasKey( 'customer', $body['roles'] );
	}

	public function test_match_only_never_creates_a_contact(): void {
		$result = $this->connector()->send( $this->connection( array( 'customer_policy' => 'match_only' ) ), $this->order(), $this->payload() );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->fake->contacts );
	}

	public function test_a_missing_email_is_a_mapping_error_and_a_foreign_currency_is_refused_without_a_request(): void {
		$payload                      = $this->payload();
		$payload['customer']['email'] = '';
		$this->assertSame( ErrorCode::Map003, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );
		$this->assertSame( ErrorCode::Val002, $this->connector()->send( $this->connection(), $this->order(), $this->payload( array( 'currency' => 'USD' ) ) )->error->code );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
	}

	public function test_a_line_that_does_not_divide_into_four_decimals_is_sent_as_one_line(): void {
		$payload                          = $this->payload( array( 'total' => 1, 'coupons' => '', 'shipping' => array() ) );
		$payload['lines'][0]['quantity']  = 1000;
		$payload['lines'][0]['total']     = 1;
		$result                           = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$line                             = $this->lastBody( '#^/v1/invoices$#' )['lineItems'][0];

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '1000 × Mug', $line['name'] );
		$this->assertEquals( 1, $line['quantity'] );
		$this->assertEquals( 0.01, $line['unitPrice']['netAmount'] );
		$this->assertSame( array( 'line_split:1' ), $result->ref->extra['warnings'] );
	}

	/**
	 * A refund payload as the mapping builds it: one refunded unit of the mug (net 19.99, tax 3.80).
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function refundPayload( array $over = array() ): array {
		return $over + array(
			'order_ref'  => 'WC-5001',
			'customer'   => array( 'email' => 'anna@example.test' ),
			'order_date' => '2026-11-02 10:30:00',
			'currency'   => 'EUR',
			'date'       => '2026-11-05 09:00:00',
			'reason'     => 'Damaged',
			'amount'     => 2379,
			'lines'      => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 1, 'amount' => 1999, 'tax_amount' => 380 ) ),
		);
	}

	public function test_a_refund_becomes_a_credit_note_linked_to_a_finalised_invoice(): void {
		$connection = $this->connection( array( 'invoice_state' => 'open' ) );
		$this->connector()->send( $connection, $this->order(), $this->payload() );
		$invoiceId = array_key_first( $this->fake->invoices );

		$result = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$request = $this->fake->server->requests( 'POST', '#^/v1/credit-notes$#' )[0];
		$this->assertSame( $invoiceId, $request->query['precedingSalesVoucherId'] );
		$this->assertSame( 'true', $request->query['finalize'] );
		$body = $request->json();
		$this->assertSame( 'gross', $body['taxConditions']['taxType'] );
		$this->assertEquals( 23.79, $body['lineItems'][0]['unitPrice']['grossAmount'] );
		$this->assertEquals( 19, $body['lineItems'][0]['unitPrice']['taxRatePercentage'] );
		$this->assertStringContainsString( 'WC-7001', $body['introduction'] );
		$this->assertSame( 2379, $result->ref->extra['total_minor'], 'The credit note equals the refund to the cent.' );
		$this->assertSame( 'paidoff', $result->ref->extra['status'] );

		$again = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->fake->creditNotes );
	}

	public function test_only_the_first_credit_note_is_linked_and_a_draft_invoice_is_not_referenced(): void {
		$open = $this->connection( array( 'invoice_state' => 'open' ) );
		$this->connector()->send( $open, $this->order(), $this->payload() );
		$this->connector()->send( $open, $this->refundItem( '7001' ), $this->refundPayload() );
		$second = $this->connector()->send( $open, $this->refundItem( '7002' ), $this->refundPayload() );

		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$requests = $this->fake->server->requests( 'POST', '#^/v1/credit-notes$#' );
		$this->assertArrayNotHasKey( 'precedingSalesVoucherId', $requests[1]->query );
		$this->assertContains( 'not_linked', $second->ref->extra['warnings'] );
		$this->assertStringContainsString( 'Rechnung RE', $requests[1]->json()['introduction'] );

		$draft = $this->connection();
		$this->connector()->send( $draft, $this->order( '5002' ), $this->payload() );
		$third = $this->connector()->send( $draft, $this->refundItem( '7003' ), $this->refundPayload( array( 'order_ref' => 'WC-5002' ) ) );
		$this->assertTrue( $third->isSuccess(), (string) $third->error?->message() );
		$this->assertArrayNotHasKey( 'precedingSalesVoucherId', $this->fake->server->requests( 'POST', '#^/v1/credit-notes$#' )[2]->query );
	}

	public function test_a_refund_without_an_invoice_or_with_a_wrong_total_is_refused(): void {
		$none = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $none->error->code );

		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$bad = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'amount' => 5000 ) ) );
		$this->assertSame( ErrorCode::Val002, $bad->error->code, 'The credit note is built from the lines, so a refund amount that the lines do not explain is caught by the total check.' );
	}

	public function test_shipping_and_other_amounts_are_refunded_at_the_invoice_rate(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$payload = $this->refundPayload( array( 'amount' => 3000, 'lines' => array(), 'shipping_amount' => 595, 'other_amount' => 405, 'excluded_amount' => 0 ) );
		$payload['amount'] = 1000;
		$result            = $this->connector()->send( $this->connection(), $this->refundItem(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$names = array_column( $this->lastBody( '#^/v1/credit-notes$#' )['lineItems'], 'name' );
		$this->assertSame( array( 'Versand', 'Erstattung' ), $names );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_a_key_past_its_end_date_stops_before_any_request(): void {
		$connection = $this->connection( array(), array( 'key_expires_on' => '2026-10-01' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$tested     = $this->connector()->test( $connection );

		$this->assertSame( ErrorCode::Auth005, $sent->error->code );
		$this->assertSame( ErrorCode::Auth005, $tested->error->code );
		$this->assertStringContainsString( '2026-10-01', $sent->error->message() );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
	}

	public function test_test_connection_reports_the_company_and_the_key_end_date(): void {
		$result = $this->connector()->test( $this->connection( array(), array( 'key_expires_on' => '2027-01-31' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'Beispiel GmbH', $result->company );
		$this->assertSame( 'API v1 (active)', $result->version );
		$this->assertSame( '2027-01-31 23:59:59', $result->detected['credential_expires_at'] );
		$this->assertSame( 'net', $result->detected['tax_type'] );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:ErrorCode}>
	 */
	public static function provide_answers(): array {
		return array(
			'payment required' => array( 402, '{"message":"x"}', ErrorCode::Auth006 ),
			'version conflict' => array( 409, '{"message":"x"}', ErrorCode::Net002 ),
			'forbidden'        => array( 403, '{"message":"x"}', ErrorCode::Auth004 ),
			'bad gateway'      => array( 503, '', ErrorCode::Net002 ),
			'validation'       => array( 406, '{"IssueList":[{"i18nKey":"missing_entity","source":"lineItems[0].name"}]}', ErrorCode::Val002 ),
			'bad request'      => array( 400, '{"message":"Malformed"}', ErrorCode::Val002 ),
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
		$this->assertStringNotContainsString( FakeLexwareOfficeServer::KEY, $result->error->message() . $result->error->detail );
	}

	public function test_a_validation_answer_keeps_a_short_reason_and_a_rate_limit_without_retry_after_waits_thirty_seconds(): void {
		$this->fake->server->failNext( 'POST', '', FakeHttpClient::status( 406, '{"IssueList":[{"i18nKey":"missing_entity","source":"lineItems[0].name"}]}' ) );
		$validation = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$this->assertStringContainsString( 'missing_entity', $validation->error->message() );

		$this->fake->failNextWrite( 'rate_limit' );
		$limited = $this->connector()->send( $this->connection(), $this->order( '5003' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $limited->error->code );
		$this->assertSame( 30, $limited->error->retryAfter );
	}

	public function test_reading_back_an_invoice_and_the_helpers(): void {
		$connection = $this->connection( array( 'invoice_state' => 'open' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$readback   = $this->connector()->fetchInvoice( $connection, $sent->ref );

		$this->assertSame( 'open', $readback['status'] );
		$this->assertSame( $sent->ref->ref, $readback['number'] );
		$this->assertSame( '', $readback['authority_status'] );
		$this->assertStringContainsString( '/permalink/invoices/view/' . $sent->ref->id, $readback['pdf_url'] );
		$this->assertNull( $this->connector()->fetchInvoice( $connection, new ExternalRef( 'missing' ) ) );

		$this->assertSame( 'CS-MUG-01', $this->connector()->lookupProduct( $connection, 'CS-MUG-01' )->ref );
		$this->assertNull( $this->connector()->lookupProduct( $connection, 'nope' ) );
		$found = $this->connector()->searchProducts( $connection, 'mug' );
		$this->assertCount( 1, $found );
		$codes = array_column( $this->connector()->listTaxCodes( $connection ), 'code' );
		$this->assertContains( 'vatfree', $codes );
		$this->assertContains( '19', $codes );
	}

	public function test_the_api_key_is_sent_as_a_bearer_header_only_and_never_in_a_url(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( 'Bearer ' . FakeLexwareOfficeServer::KEY, $request['headers']['Authorization'] );
			$this->assertStringNotContainsString( FakeLexwareOfficeServer::KEY, $request['url'] );
			$this->assertStringStartsWith( 'https://api.lexware.io/v1/', $request['url'] );
		}
	}
}
