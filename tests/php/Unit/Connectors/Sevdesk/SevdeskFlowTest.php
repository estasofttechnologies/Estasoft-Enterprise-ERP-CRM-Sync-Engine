<?php
/**
 * sevDesk: invoices, contacts, credit notes, stock, errors and the shape of what goes on the wire.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sevdesk;

use Estasoft\SyncEngine\Connectors\Sevdesk\SevdeskConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSevdeskServer;
use PHPUnit\Framework\TestCase;

final class SevdeskFlowTest extends TestCase {

	private ApiTestKit $kit;

	private FakeSevdeskServer $fake;

	protected function setUp(): void {
		$this->kit  = ApiTestKit::make();
		$this->fake = new FakeSevdeskServer( $this->kit->http );
		$this->fake->addPart( 'CS-MUG-01', 'Mug', 12 );
		$this->fake->addPart( 'CS-BOOK-02', 'Book' );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connector(): GuardedConnector {
		return GuardedConnector::wrap( new SevdeskConnector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * @param array<string,mixed> $options Advanced settings.
	 * @param array<string,mixed> $config  Other config.
	 */
	private function connection( array $options = array(), array $config = array() ): Connection {
		return $this->kit->connection( 'sevdesk', $config + array( 'options' => $options ), array( 'api_token' => FakeSevdeskServer::TOKEN ) );
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
				array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 2, 'total' => 3998, 'tax_code' => '1', 'tax_rate' => '19' ),
			),
			'shipping' => array( 'name' => 'Versand', 'total' => 500, 'tax_code' => '1', 'tax_rate' => '19' ),
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

	public function test_a_draft_invoice_has_the_documented_shape_and_the_reference_twice(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body    = $this->body( '#/Invoice/Factory/saveInvoice$#' );
		$invoice = $body['invoice'];
		$this->assertSame( array( 'invoice', 'invoicePosSave', 'invoicePosDelete', 'discountSave', 'discountDelete', 'takeDefaultAddress' ), array_keys( $body ), 'The trailing parameters are present and in order.' );
		$this->assertSame( 100, $invoice['status'] );
		$this->assertSame( 'RE', $invoice['invoiceType'] );
		$this->assertSame( 'WC-5001', $invoice['customerInternalNote'] );
		$this->assertStringContainsString( 'WC-5001', $invoice['header'] );
		$this->assertSame( '02.11.2026', $invoice['invoiceDate'] );
		$this->assertSame( '02.11.2026', $invoice['deliveryDate'] );
		$this->assertSame( array( 'id' => '1', 'objectName' => 'TaxRule' ), $invoice['taxRule'] );
		$this->assertArrayNotHasKey( 'taxType', $invoice );
		$this->assertTrue( $invoice['showNet'] );
		$this->assertSame( "Beispiel\nAnna Beispiel\nMusterstraße 1\n10115 Berlin", "Beispiel\n" . $invoice['address'] );
		$this->assertSame( 'Umsatzsteuer 19%', $invoice['taxText'] );
		$this->assertSame( 77, $invoice['contactPerson']['id'] );
		$position = $body['invoicePosSave'][0];
		$this->assertEquals( 19.99, $position['price'] );
		$this->assertEquals( 2, $position['quantity'] );
		$this->assertEquals( 19, $position['taxRate'] );
		$this->assertSame( 'Unity', $position['unity']['objectName'] );
		$this->assertSame( 'Part', $position['part']['objectName'] );
		$this->assertStringContainsString( 'Gutschein: SPRING', $invoice['footText'] );
		$this->assertSame( 5353, $result->ref->extra['total_minor'] );
		$this->assertSame( 100, $result->ref->extra['status'] );
		$this->assertSame( array(), $this->fake->server->requests( 'PUT', '#sendBy#' ), 'A draft is not sent.' );
	}

	public function test_open_mode_marks_the_invoice_as_sent_once(): void {
		$connection = $this->connection( array( 'invoice_state' => 'open' ) );
		$sent       = $this->connector()->send( $connection, $this->order(), $this->payload() );

		$this->assertTrue( $sent->isSuccess() );
		$this->assertSame( 200, $sent->ref->extra['status'] );
		$this->assertSame( 'VPR', $this->fake->server->requests( 'PUT', '#/sendBy$#' )[0]->json()['sendType'] );

		$again = $this->connector()->send( $connection, $this->order(), $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->fake->server->requests( 'PUT', '#/sendBy$#' ), 'The status step is not repeated.' );
	}

	public function test_a_missing_rate_or_a_mixed_or_unknown_rule_stops_the_order(): void {
		$norate = $this->payload();
		unset( $norate['lines'][0]['tax_rate'], $norate['shipping']['tax_rate'] );
		$this->assertSame( ErrorCode::Val002, $this->connector()->send( $this->connection(), $this->order(), $norate )->error->code );

		$mixed                         = $this->payload();
		$mixed['shipping']['tax_code'] = '2';
		$this->assertSame( ErrorCode::Val002, $this->connector()->send( $this->connection(), $this->order(), $mixed )->error->code );

		$unknown                          = $this->payload();
		$unknown['lines'][0]['tax_code']  = '99';
		$unknown['shipping']['tax_code']  = '99';
		$this->assertSame( ErrorCode::Map002, $this->connector()->send( $this->connection(), $this->order(), $unknown )->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/saveInvoice$#' ) );
	}

	public function test_an_account_on_the_older_system_version_uses_the_tax_type(): void {
		$this->fake->version = '1.0';
		$payload             = $this->payload();
		$payload['lines'][0]['tax_code'] = 'default';
		$payload['shipping']['tax_code'] = 'default';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $this->body( '#/saveInvoice$#' )['invoice'];
		$this->assertSame( 'default', $invoice['taxType'] );
		$this->assertArrayNotHasKey( 'taxRule', $invoice );
	}

	public function test_prices_with_tax_are_converted_to_net_with_whole_numbers(): void {
		$payload                         = $this->payload( array( 'prices_include_tax' => true, 'total' => 2380, 'shipping' => array(), 'coupons' => '' ) );
		$payload['lines'][0]['quantity'] = 1;
		$payload['lines'][0]['total']    = 2380;
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertEquals( 20.0, $this->body( '#/saveInvoice$#' )['invoicePosSave'][0]['price'] );
		$this->assertSame( 2380, $result->ref->extra['total_minor'] );
	}

	public function test_a_wrong_total_removes_the_draft_again_and_names_both_totals(): void {
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload( array( 'total' => 9999 ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '99.99', $result->error->message() );
		$this->assertStringContainsString( '53.53', $result->error->message() );
		$this->assertCount( 0, $this->fake->invoices, 'The rejected draft was deleted.' );

		$warn = $this->connector()->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->order(), $this->payload( array( 'total' => 9999 ) ) );
		$this->assertTrue( $warn->isSuccess() );
		$this->assertSame( array( 'total_differs' ), $warn->ref->extra['warnings'] );
		$this->assertCount( 1, $this->fake->invoices );
	}

	public function test_a_line_that_does_not_divide_into_cents_becomes_one_position(): void {
		$payload                         = $this->payload( array( 'total' => 1, 'coupons' => '', 'shipping' => array() ) );
		$payload['lines'][0]['quantity'] = 3;
		$payload['lines'][0]['total']    = 1;
		$payload['lines'][0]['tax_rate'] = '0';
		$payload['lines'][0]['tax_code'] = '2';
		$result                          = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$position                        = $this->body( '#/saveInvoice$#' )['invoicePosSave'][0];

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '3 × Mug', $position['name'] );
		$this->assertEquals( 1, $position['quantity'] );
		$this->assertEquals( 0.01, $position['price'] );
		$this->assertSame( array( 'line_split:1' ), $result->ref->extra['warnings'] );
	}

	public function test_a_retry_in_a_new_process_finds_the_invoice_and_the_reference_is_a_whole_word(): void {
		$first = $this->connector()->send( $this->connection(), $this->order( '50' ), $this->payload() );
		$again = $this->connector()->send( $this->connection(), $this->order( '50' ), $this->payload() );
		$other = $this->connector()->send( $this->connection(), $this->order( '5' ), $this->payload() );

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertFalse( $other->alreadyExisted, 'WC-5 is not WC-50.' );
		$this->assertCount( 2, $this->fake->invoices );
		$this->assertCount( 1, $this->fake->contacts );
	}

	public function test_the_duplicate_search_pages_through_every_invoice_of_the_day(): void {
		$this->connector()->send( $this->connection(), $this->order( '6001' ), $this->payload() );
		$contactId = (string) array_key_first( $this->fake->contacts );
		$stamp     = (int) $this->fake->invoices[ array_key_first( $this->fake->invoices ) ]['_date'];
		for ( $i = 0; $i < 120; $i++ ) {
			$id                          = 9000 + $i;
			$this->fake->invoices[ $id ] = array( 'id' => (string) $id, 'contact' => array( 'id' => $contactId ), 'invoiceType' => 'RE', 'header' => 'Rechnung ' . $i, 'customerInternalNote' => 'x', '_date' => $stamp, 'status' => '100', 'sumGross' => '1.00', 'currency' => 'EUR' );
		}
		$target                          = $this->fake->invoices[ array_key_first( $this->fake->invoices ) ];
		$target['id']                    = '9999';
		$target['customerInternalNote']  = 'WC-6002';
		$target['header']                = 'Rechnung zu Bestellung WC-6002';
		$this->fake->invoices[9999]      = $target;
		$before                          = count( $this->fake->invoices );

		$result = $this->connector()->send( $this->connection(), $this->order( '6002' ), $this->payload() );
		$this->assertTrue( $result->alreadyExisted, (string) $result->error?->message() );
		$this->assertSame( '9999', $result->ref->id );
		$this->assertCount( $before, $this->fake->invoices );
	}

	public function test_a_contact_is_made_once_with_its_address_and_email_and_a_detail_failure_does_not_fail_the_order(): void {
		$this->connector()->send( $this->connection(), $this->order( '5201' ), $this->payload() );
		$this->connector()->send( $this->connection(), $this->order( '5202' ), $this->payload() );
		$this->assertCount( 1, $this->fake->contacts, 'The same email gives the same contact.' );
		$this->assertSame( array( 'ContactAddress', 'CommunicationWay' ), array_column( $this->fake->details, 'objectName' ) );
		$contact = reset( $this->fake->contacts );
		$this->assertMatchesRegularExpression( '/^WC-[0-9a-f]{12}$/', $contact['customerNumber'] );

		$this->fake->server->failNext( 'POST', '/api/v1/ContactAddress', FakeHttpClient::json( array( 'message' => 'Bad request' ), 400 ) );
		$other                      = $this->payload();
		$other['customer']['email'] = 'ben@example.test';
		$result                     = $this->connector()->send( $this->connection(), $this->order( '5203' ), $other );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertContains( 'contact_details_failed', $result->ref->extra['warnings'] );
	}

	public function test_a_collective_customer_needs_no_contact_per_buyer(): void {
		$this->fake->contacts[42] = array( 'id' => '42', 'objectName' => 'Contact', 'customerNumber' => 'WEB', 'name' => 'Webshop' );
		$result                   = $this->connector()->send( $this->connection( array( 'customer_policy' => 'collective', 'collective_contact_id' => '42' ) ), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->fake->contacts );
		$this->assertSame( 42, $this->body( '#/saveInvoice$#' )['invoice']['contact']['id'] );

		$missing = $this->connector()->send( $this->connection( array( 'customer_policy' => 'collective', 'collective_contact_id' => '999' ) ), $this->order( '5002' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
	}

	public function test_an_unknown_part_stops_the_order_or_becomes_a_free_position(): void {
		$payload                    = $this->payload();
		$payload['lines'][0]['sku'] = 'NO-SUCH';
		$this->assertSame( ErrorCode::Map001, $this->connector()->send( $this->connection(), $this->order(), $payload )->error->code );
		$this->assertSame( array(), $this->fake->server->requests( 'POST', '#/saveInvoice$#' ) );

		$free = $this->connector()->send( $this->connection( array( 'unknown_sku' => 'custom' ) ), $this->order(), $payload );
		$this->assertTrue( $free->isSuccess() );
		$this->assertArrayNotHasKey( 'part', $this->body( '#/saveInvoice$#' )['invoicePosSave'][0] );
	}

	public function test_a_payment_is_booked_once_and_only_on_an_open_invoice(): void {
		$payload                    = $this->payload();
		$payload['payment']         = array( 'date' => '2026-11-03 08:00:00', 'reference' => 'ch_1' );
		$open                       = $this->connection( array( 'invoice_state' => 'open', 'payment_account' => '55' ) );
		$sent                       = $this->connector()->send( $open, $this->order(), $payload );

		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertCount( 1, $this->fake->payments );
		$this->assertSame( 53.53, $this->fake->payments[0][1]['amount'] );
		$this->assertSame( 'FULL_PAYMENT', $this->fake->payments[0][1]['type'] );
		$this->assertSame( 20261103, $this->fake->payments[0][1]['date'], 'The booking date is an integer, yyyymmdd.' );
		$this->assertSame( 1000, $sent->ref->extra['status'] );

		$this->connector()->send( $open, $this->order(), $payload );
		$this->assertCount( 1, $this->fake->payments, 'Not booked twice.' );

		$this->connector()->send( $this->connection( array( 'payment_account' => '55' ) ), $this->order( '5002' ), $payload );
		$this->assertCount( 1, $this->fake->payments, 'A draft cannot be paid.' );
	}

	/**
	 * A refund of one mug (net 19.99, tax 3.80).
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

	public function test_a_refund_becomes_a_credit_note_and_is_not_made_twice(): void {
		$connection = $this->connection();
		$this->connector()->send( $connection, $this->order(), $this->payload() );
		$result = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->body( '#/saveCreditNote$#' );
		$this->assertSame( 'UNDERACHIEVEMENT', $body['creditNote']['bookingCategory'] );
		$this->assertSame( 100, $body['creditNote']['status'] );
		$this->assertStringContainsString( 'WC-7001 zu Bestellung WC-5001', $body['creditNote']['header'] );
		$this->assertSame( array( 'creditNote', 'creditNotePosSave', 'creditNotePosDelete', 'discountSave', 'discountDelete', 'takeDefaultAddress', 'forCashRegister' ), array_keys( $body ) );
		$this->assertEquals( 19.99, $body['creditNotePosSave'][0]['price'] );
		$this->assertEquals( 19, $body['creditNotePosSave'][0]['taxRate'] );
		$this->assertSame( 2379, $result->ref->extra['total_minor'] );

		$again = $this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->fake->creditNotes );
	}

	public function test_a_refund_needs_the_newer_system_version_and_an_invoice_and_a_matching_total(): void {
		$none = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $none->error->code, 'No invoice for the order.' );

		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );
		$bad = $this->connector()->send( $this->connection(), $this->refundItem(), $this->refundPayload( array( 'amount' => 5000 ) ) );
		$this->assertSame( ErrorCode::Val002, $bad->error->code );
		$this->assertCount( 0, $this->fake->creditNotes, 'The credit note that did not match was removed again.' );

		$this->fake->version = '1.0';
		$old                 = $this->connector()->send( $this->connection( array(), array( 'detected' => array( 'bookkeeping_version' => '1.0' ) ) ), $this->refundItem( '7002' ), $this->refundPayload() );
		$this->assertStringContainsString( '2.0', $old->error->message() );
	}

	public function test_stock_is_read_per_part_for_parts_with_tracking_only(): void {
		$stock = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01', 'CS-BOOK-02', 'NO-SUCH' ), array() );

		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 12, 'held' => 0 ) ), $stock );
		$this->assertCount( 1, $this->fake->server->requests( 'GET', '#/getStock$#' ) );
	}

	public function test_invoice_facts_and_the_nightly_list(): void {
		$connection = $this->connection( array( 'invoice_state' => 'open' ) );
		$sent       = $this->connector()->send( $connection, $this->order( '5001' ), $this->payload() );
		$this->connector()->send( $connection, $this->refundItem(), $this->refundPayload() );
		$this->connector()->send( $connection, $this->order( '5002' ), $this->payload() );

		$facts = $this->connector()->fetchInvoice( $connection, $sent->ref );
		$this->assertSame( 'open', $facts['status'] );
		$this->assertSame( $sent->ref->ref, $facts['number'] );
		$this->assertNull( $this->connector()->fetchInvoice( $connection, new ExternalRef( '31337' ) ) );

		$list = $this->connector()->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5001', 'WC-5002' ), array_keys( $list ) );
		$this->assertSame( 5353, $list['WC-5001']->extra['total_minor'] );
		$this->assertSame( 2379, $list['WC-5001']->extra['refunded_minor'] );
		$this->assertSame( 0, $list['WC-5002']->extra['refunded_minor'] );
		$this->assertCount( 0, array_diff_key( $list, array( 'WC-5001' => 1, 'WC-5002' => 1 ) ) );
	}

	/**
	 * @return array<string,array{0:int,1:string,2:ErrorCode}>
	 */
	public static function provide_answers(): array {
		return array(
			'rejected as 500'       => array( 500, '{"error":{"message":"debitor/creditor number already exists"}}', ErrorCode::Val002 ),
			'plain server error'    => array( 500, '{"message":"Server Error"}', ErrorCode::Net002 ),
			'other generic text'    => array( 500, '{"message":"Internal error, please try again later"}', ErrorCode::Net002 ),
			'nested generic text'   => array( 500, '{"error":{"message":"Something went wrong"}}', ErrorCode::Net002 ),
			'missing field as 500'  => array( 500, '{"message":"Field contact is required"}', ErrorCode::Val002 ),
			'empty 500'             => array( 500, '', ErrorCode::Net002 ),
			'bad gateway'           => array( 502, '', ErrorCode::Net002 ),
			'bad request'           => array( 400, '{"message":"Bad request"}', ErrorCode::Val002 ),
			'forbidden'             => array( 403, '{"message":"x"}', ErrorCode::Auth004 ),
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
		$this->assertStringNotContainsString( FakeSevdeskServer::TOKEN, $result->error->message() . $result->error->detail );
	}

	public function test_a_rate_limit_without_retry_after_waits_thirty_seconds(): void {
		$this->fake->failNextWrite( 'rate_limit' );
		$result = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 30, $result->error->retryAfter );
	}

	public function test_the_token_is_sent_raw_in_the_header_and_never_in_a_url(): void {
		$this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		foreach ( $this->kit->http->requests as $request ) {
			$this->assertSame( FakeSevdeskServer::TOKEN, $request['headers']['Authorization'], 'No Bearer prefix.' );
			$this->assertStringNotContainsString( FakeSevdeskServer::TOKEN, $request['url'] );
			$this->assertStringStartsWith( 'https://my.sevdesk.de/api/v1/', $request['url'] );
		}
	}

	public function test_test_connection_reports_the_company_the_version_and_the_bank_accounts(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'Beispiel GmbH', $result->company );
		$this->assertSame( 'sevDesk-Update 2.0', $result->version );
		$this->assertSame( '2.0', $result->detected['bookkeeping_version'] );
		$this->assertTrue( $result->detected['unity_found'] );
		$this->assertSame( 'Girokonto', $result->detected['bank_accounts'][0]['name'] );
	}

	public function test_tax_codes_depend_on_the_system_version(): void {
		$new = array_column( $this->connector()->listTaxCodes( $this->connection() ), 'code' );
		$old = array_column( $this->connector()->listTaxCodes( $this->connection( array(), array( 'detected' => array( 'bookkeeping_version' => '1.0' ) ) ) ), 'code' );

		$this->assertContains( '18', $new );
		$this->assertContains( 'noteu', $old );
		$this->assertNotContains( 'noteu', $new );
	}

	public function test_findexisting_answers_from_the_engines_link_only(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->order( '5100' ) ) );

		$sent   = $connector->send( $this->connection(), $this->order( '5100' ), $this->payload() );
		$linked = new Item( 5100, 1, EntityType::Order, '5100', 'created', 'order:5100', 'k', ItemState::Retrying, \Estasoft\SyncEngine\Domain\ItemMode::Live, 1, 5, null, null, $sent->ref->id, $sent->ref->ref );
		$found  = $this->connector()->findExisting( $this->connection(), $linked );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $sent->ref->id, $found->id );
	}

	public function test_the_booking_date_can_be_a_unix_timestamp_and_an_unreadable_date_books_nothing(): void {
		$payload            = $this->payload();
		$payload['payment'] = array( 'date' => '2026-11-03 08:00:00', 'reference' => 'ch_1' );
		$unix               = $this->connection( array( 'invoice_state' => 'open', 'payment_account' => '55', 'payment_date_format' => 'unix' ) );
		$this->assertTrue( $this->connector()->send( $unix, $this->order(), $payload )->isSuccess() );
		$midnight = ( new \DateTimeImmutable( '2026-11-03 00:00:00', new \DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp();
		$this->assertSame( $midnight, $this->fake->payments[0][1]['date'] );

		$payload['payment']['date'] = 'soon';
		$odd                        = $this->connector()->send( $this->connection( array( 'invoice_state' => 'open', 'payment_account' => '55' ) ), $this->order( '5002' ), $payload );
		$this->assertTrue( $odd->isSuccess() );
		$this->assertContains( 'payment_date_unreadable', $odd->ref->extra['warnings'] );
		$this->assertCount( 1, $this->fake->payments, 'Nothing was booked for the second order.' );
	}

	public function test_a_payment_that_is_already_there_is_not_booked_again_after_a_lost_answer(): void {
		$payload            = $this->payload();
		$payload['payment'] = array( 'date' => '2026-11-03 08:00:00', 'reference' => 'ch_1' );
		$open               = $this->connection( array( 'invoice_state' => 'open' ) );
		$sent               = $this->connector()->send( $open, $this->order(), $payload );
		$id                 = (int) $sent->ref->id;
		$this->assertSame( 0, count( $this->fake->payments ) );

		// The booking went through at sevDesk but the answer never came back: the next pass finds the invoice paid.
		$this->fake->invoices[ $id ]['paidAmount'] = '53.53';
		$this->fake->invoices[ $id ]['status']     = '1000';
		$again = $this->connector()->send( $this->connection( array( 'invoice_state' => 'open', 'payment_account' => '55' ) ), $this->order(), $payload );

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( array(), $this->fake->server->requests( 'PUT', '#/bookAmount$#' ), 'Nothing is booked twice.' );
	}

	public function test_the_default_tolerance_is_one_unit_per_row_and_the_option_replaces_it(): void {
		$lines = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$lines[] = array( 'sku' => 'CS-MUG-01', 'name' => 'Mug ' . $i, 'quantity' => 1, 'total' => 99, 'tax_code' => '1', 'tax_rate' => '19' );
		}
		// WooCommerce rounds the tax of each line (0.19 x 10 = 1.90), sevDesk rounds the tax of the rate group (1.88): 2 cents apart.
		$payload = $this->payload( array( 'lines' => $lines, 'shipping' => array(), 'total' => 1180 ) );

		$default = $this->connector()->send( $this->connection(), $this->order(), $payload );
		$this->assertTrue( $default->isSuccess(), (string) $default->error?->message() );
		$this->assertSame( 1178, $default->ref->extra['total_minor'] );

		$strict = $this->connector()->send( $this->connection( array( 'total_tolerance' => '1' ) ), $this->order( '5002' ), $payload );
		$this->assertSame( ErrorCode::Val002, $strict->error->code );
		$this->assertStringContainsString( '11.80', $strict->error->message() );
		$this->assertStringContainsString( '11.78', $strict->error->message() );

		$exact = $this->connector()->send( $this->connection( array( 'total_tolerance' => '2' ) ), $this->order( '5003' ), $payload );
		$this->assertTrue( $exact->isSuccess() );

		$short = $this->payload( array( 'total' => 5353 + 3 ) );
		$this->assertSame( ErrorCode::Val002, $this->connector()->send( $this->connection(), $this->order( '5004' ), $short )->error->code, 'Two rows allow two units, not three.' );
	}

	public function test_a_date_the_vendor_changed_is_noted_and_the_connector_never_writes_it_back(): void {
		$this->fake->overrideDate = '2026-11-09T00:00:00+01:00';
		$result                   = $this->connector()->send( $this->connection(), $this->order(), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertContains( 'date_differs', $result->ref->extra['warnings'] );

		$this->fake->overrideDate = null;
		$clean                    = $this->connector()->send( $this->connection(), $this->order( '5002' ), $this->payload() );
		$this->assertArrayNotHasKey( 'warnings', $clean->ref->extra );
	}

	public function test_ping_is_one_cheap_call(): void {
		$this->assertTrue( $this->connector()->ping( $this->connection() ) );
		$this->assertCount( 1, $this->kit->http->requests );
		$this->assertStringEndsWith( '/Tools/bookkeepingSystemVersion', $this->kit->http->requests[0]['url'] );

		$bad = $this->kit->connection( 'sevdesk', array(), array( 'api_token' => 'wrong-token-0000' ), 9 );
		$this->assertFalse( $this->connector()->ping( $bad ) );
	}
}
