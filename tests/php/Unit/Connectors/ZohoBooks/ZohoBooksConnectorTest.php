<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ZohoBooks;

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\ZohoBooks\ZohoBooksConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeZohoBooks;
use Estasoft\SyncEngine\Tests\Support\SalesFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Order, refund, payment, customer, lookup, reconciliation and error behaviour of the Zoho Books connector.
 */
final class ZohoBooksConnectorTest extends TestCase {

	private ApiTestKit $kit;

	private FakeZohoBooks $books;

	private ZohoBooksConnector $inner;

	private ConnectorInterface $connector;

	protected function setUp(): void {
		$this->kit   = ApiTestKit::make();
		$this->books = new FakeZohoBooks();
		$this->books->install( $this->kit->http );
		$this->books->addItem( 'CS-MUG-01', 'Ceramic mug, sand' );
		$this->inner     = new ZohoBooksConnector( $this->kit->deps );
		$this->connector = GuardedConnector::wrap( $this->inner );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * @param array<string,mixed> $options Options.
	 * @param array<string,mixed> $config  Config overrides.
	 */
	private function connection( array $options = array(), array $config = array() ): Connection {
		$expires = (string) ( (int) $this->kit->clock->now()->format( 'U' ) + 3600 );
		return $this->kit->connection(
			'zoho-books',
			$config + array( 'data_centre' => 'com', 'client_id' => '1000.SYNTHETICCLIENT', 'organization_id' => FakeZohoBooks::ORG, 'organization_currency' => 'AED', 'options' => $options ),
			array( 'client_secret' => FakeZohoBooks::SECRET, 'oauth_access_token' => FakeZohoBooks::ACCESS, 'oauth_refresh_token' => FakeZohoBooks::REFRESH, 'oauth_expires_at' => $expires )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $collection ): array {
		return array_values( $this->books->rows[ $collection ] );
	}

	public function test_an_order_becomes_a_sent_invoice_with_the_reference_the_date_and_the_exact_total(): void {
		list( $item, $payload ) = SalesFixtures::order( '123', array( 'shipping' => 500, 'note' => 'Leave at the gate' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 5500, $result->ref->extra['total_minor'] );
		$this->assertSame( 'WC-123', $result->ref->ref );
		$invoice = $this->rows( 'invoices' )[0];
		$this->assertSame( 'WC-123', $invoice['reference_number'] );
		$this->assertSame( 'sent', $invoice['status'] );
		$this->assertSame( '2026-11-01', $invoice['date'] );
		$this->assertSame( 'Leave at the gate', $invoice['notes'] );
		$this->assertSame( 'c-eur', $invoice['currency_id'] );
		$this->assertCount( 2, $invoice['line_items'], 'The product and shipping.' );
		$this->assertSame( (string) array_keys( $this->books->rows['items'] )[0], $invoice['line_items'][0]['item_id'] );
		$this->assertSame( 25.0, $invoice['line_items'][0]['rate'] );
		$this->assertSame( 'Shipping', $invoice['line_items'][1]['name'] );
		$this->assertStringContainsString( 'organization_id=' . FakeZohoBooks::ORG, $this->books->calls[0] );
	}

	public function test_sending_twice_writes_nothing_the_second_time_and_a_draft_is_not_called_found(): void {
		list( $item, $payload ) = SalesFixtures::order( '124' );
		$connection             = $this->connection();
		$first                  = $this->connector->send( $connection, $item, $payload );
		$writes                 = $this->books->writes;
		$second                 = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->books->writes );

		$draftOnly = $this->connection( array( 'invoice_state' => 'draft' ) );
		list( $item, $payload ) = SalesFixtures::order( '125' );
		$this->connector->send( $draftOnly, $item, $payload );
		$this->assertSame( 'draft', $this->rows( 'invoices' )[1]['status'] );
		$this->assertNotNull( $this->connector->findExisting( $draftOnly, $item ) );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $item ), 'A draft is unfinished when invoices should be sent.' );
	}

	public function test_a_crash_after_the_create_is_resumed_from_the_read_not_replayed(): void {
		list( $item, $payload ) = SalesFixtures::order( '126' );
		$connection             = $this->connection();
		$this->books->failNextWrite( 'server_error', 0, 2 );
		$first = $this->connector->send( $connection, $item, $payload );

		$this->assertFalse( $first->isSuccess(), 'The mark-as-sent step failed.' );
		$this->assertCount( 1, $this->rows( 'invoices' ) );
		$this->assertSame( 'draft', $this->rows( 'invoices' )[0]['status'] );
		$this->assertNull( $this->connector->findExisting( $connection, $item ) );

		$retry = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->rows( 'invoices' ), 'No second invoice.' );
		$this->assertCount( 1, $this->rows( 'contacts' ) );
		$this->assertSame( 'sent', $this->rows( 'invoices' )[0]['status'] );
	}

	public function test_tax_codes_are_sent_per_line_and_for_shipping_and_the_total_follows_zoho(): void {
		$lines                  = array( array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => '2', 'unit_price' => 2500, 'total' => 5000, 'tax' => 250, 'tax_code' => 't-vat5' ) );
		list( $item, $payload ) = SalesFixtures::order( '127', array( 'lines' => $lines, 'shipping' => 1000 ) );
		$payload['shipping']['tax_code'] = 't-vat5';
		$payload['total']                = 5250 + 1050;
		$result                          = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 6300, $result->ref->extra['total_minor'] );
		$invoice = $this->rows( 'invoices' )[0];
		$this->assertSame( 't-vat5', $invoice['line_items'][0]['tax_id'] );
		$this->assertSame( 't-vat5', $invoice['line_items'][1]['tax_id'] );
		$this->assertFalse( $invoice['is_inclusive_tax'] ?? false );
	}

	public function test_a_total_that_differs_is_refused_and_the_draft_is_kept_for_a_person(): void {
		$this->books->totalDrift = 100;
		list( $item, $payload )  = SalesFixtures::order( '128' );
		$result                  = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertStringContainsString( '50.00', $result->error->context['reason'] );
		$this->assertStringContainsString( '51.00', $result->error->context['reason'] );
		$this->assertSame( 'draft', $this->rows( 'invoices' )[0]['status'], 'It was never sent.' );

		$again = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $again->error->code, 'The same draft is checked again.' );
		$this->assertCount( 1, $this->rows( 'invoices' ), 'No second invoice.' );

		$warn = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $payload );
		$this->assertTrue( $warn->isSuccess() );
		$this->assertCount( 1, $warn->ref->extra['warnings'] );
		$this->assertSame( 'sent', $this->rows( 'invoices' )[0]['status'] );
	}

	public function test_an_unknown_sku_is_refused_before_anything_is_made_unless_free_text_is_allowed(): void {
		list( $item, $payload ) = SalesFixtures::order( '129', array( 'sku' => 'NEW-SKU', 'product' => 'Walnut tray' ) );
		$refused                = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( 'NEW-SKU', $refused->error->context['sku'] );
		$this->assertSame( 0, $this->books->writes );

		$free = $this->connector->send( $this->connection( array( 'unknown_sku' => 'free_text' ) ), $item, $payload );
		$this->assertTrue( $free->isSuccess() );
		$line = $this->rows( 'invoices' )[0]['line_items'][0];
		$this->assertArrayNotHasKey( 'item_id', $line );
		$this->assertSame( 'Walnut tray', $line['name'] );
		$this->assertSame( 'NEW-SKU', $line['description'] );
	}

	public function test_a_currency_that_is_not_set_up_is_named_and_nothing_is_made(): void {
		list( $item, $payload ) = SalesFixtures::order( '130', array( 'currency' => 'SAR' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'SAR', $result->error->context['reason'] );
		$this->assertSame( 0, $this->books->writes );
	}

	public function test_a_buyer_in_two_currencies_gets_a_contact_per_currency(): void {
		list( $first, $p1 )  = SalesFixtures::order( '131', array( 'currency' => 'EUR' ) );
		list( $second, $p2 ) = SalesFixtures::order( '132', array( 'currency' => 'AED' ) );
		$connection          = $this->connection();
		$this->assertTrue( $this->connector->send( $connection, $first, $p1 )->isSuccess() );
		$this->assertTrue( $this->connector->send( $connection, $second, $p2 )->isSuccess() );
		$contacts = $this->rows( 'contacts' );
		$this->assertCount( 2, $contacts );
		$this->assertSame( 'Dana Example', $contacts[0]['contact_name'] );
		$this->assertSame( 'Dana Example (AED)', $contacts[1]['contact_name'] );
		$this->assertSame( 'AED', $contacts[1]['currency_code'] );
	}

	public function test_an_existing_contact_is_found_by_email_ignoring_case(): void {
		$id                     = $this->books->addContact( 'Dana Old', 'DANA.Example@example.test' );
		list( $item, $payload ) = SalesFixtures::order( '133' );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertCount( 1, $this->rows( 'contacts' ) );
		$this->assertSame( $id, $this->rows( 'invoices' )[0]['customer_id'] );
	}

	public function test_a_paid_order_records_one_payment_when_asked_and_never_twice(): void {
		$options                = array( 'record_payment' => 'yes', 'payment_account' => 'acct-1' );
		list( $item, $payload ) = SalesFixtures::order( '134', array( 'paid' => true ) );
		$result                 = $this->connector->send( $this->connection( $options ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'paid', $result->ref->extra['status'] );
		$this->assertCount( 1, $this->rows( 'customerpayments' ) );
		$this->assertSame( 'WC-134', $this->rows( 'customerpayments' )[0]['reference_number'] );

		$again = $this->connector->send( $this->connection( $options ), $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->rows( 'customerpayments' ) );
		$this->assertNotNull( $this->connector->findExisting( $this->connection( $options ), $item ) );

		list( $unpaid, $upayload ) = SalesFixtures::order( '135' );
		$this->connector->send( $this->connection( $options ), $unpaid, $upayload );
		$this->assertCount( 1, $this->rows( 'customerpayments' ), 'An unpaid order records no payment.' );
		$this->assertNull( $this->connector->findExisting( $this->connection( $options ), $unpaid ), 'With payments on, an unpaid invoice is not a finished record.' );
	}

	public function test_a_refund_is_a_credit_note_against_the_invoices_customer_and_is_not_made_twice(): void {
		list( $order, $payload )   = SalesFixtures::order( '136' );
		$connection                = $this->connection( array( 'refund_tax_id' => 't-vat5', 'refund_state' => 'open' ) );
		list( $refund, $rpayload ) = SalesFixtures::refund( '48', '136', array( 'amount' => 1050 ) );

		$early = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertSame( ErrorCode::Val002, $early->error->code );
		$this->connector->send( $connection, $order, $payload );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ) );
		$first = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertSame( 1050, $first->ref->extra['total_minor'] );
		$note = $this->rows( 'creditnotes' )[0];
		$this->assertSame( 'WC-48', $note['reference_number'] );
		$this->assertSame( 'open', $note['status'] );
		$this->assertSame( $this->rows( 'invoices' )[0]['customer_id'], $note['customer_id'] );
		$this->assertStringContainsString( 'WC-136', $note['notes'] );
		$this->assertSame( 't-vat5', $note['line_items'][0]['tax_id'] );

		$second = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->rows( 'creditnotes' ) );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->id );
	}

	public function test_a_credit_note_left_as_a_draft_is_opened_on_the_next_pass(): void {
		list( $order, $payload )   = SalesFixtures::order( '137' );
		list( $refund, $rpayload ) = SalesFixtures::refund( '49', '137' );
		$this->connector->send( $this->connection(), $order, $payload );
		$this->connector->send( $this->connection(), $refund, $rpayload );
		$this->assertSame( 'draft', $this->rows( 'creditnotes' )[0]['status'] );

		$open = $this->connection( array( 'refund_state' => 'open' ) );
		$this->assertNull( $this->connector->findExisting( $open, $refund ) );
		$this->assertTrue( $this->connector->send( $open, $refund, $rpayload )->alreadyExisted );
		$this->assertSame( 'open', $this->rows( 'creditnotes' )[0]['status'] );
	}

	public function test_a_customer_item_finds_or_creates_the_contact(): void {
		$item = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'k5', ItemState::Syncing );
		list( , $payload ) = SalesFixtures::order( '138' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->alreadyExisted );
		$this->assertCount( 1, $this->rows( 'contacts' ) );
	}

	public function test_the_organisation_is_required_on_every_call_and_checked_by_the_test(): void {
		list( $item, $payload ) = SalesFixtures::order( '139' );
		$none                   = $this->connection( array(), array( 'organization_id' => '' ) );
		$result                 = $this->connector->send( $none, $item, $payload );
		$this->assertSame( 'organization_id', $result->error->context['field'] );
		$this->assertSame( 0, $this->books->writes );
		$test = $this->connector->test( $none );
		$this->assertFalse( $test->ok );
		$this->assertSame( 'organization_id', $test->error->context['field'] );

		$wrong = $this->connector->test( $this->connection( array(), array( 'organization_id' => '999' ) ) );
		$this->assertFalse( $wrong->ok );

		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'Example Trading LLC', $good->company );
		$this->assertSame( 'AED', $good->detected['currency'] );
		$this->assertSame( 1, $good->detected['taxes'] );
	}

	public function test_test_connection_names_the_scope_the_token_lacks(): void {
		$this->books->denied = array( 'creditnotes' );
		$bad                 = $this->connector->test( $this->connection() );
		$this->assertFalse( $bad->ok );
		$this->assertSame( array( 'read_creditnotes' ), $bad->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $bad->error->code );
	}

	public function test_sign_in_helpers_list_the_organisations_and_store_the_chosen_one(): void {
		$connection = $this->connection( array(), array( 'organization_id' => '' ) );
		$this->assertSame( array( array( 'id' => FakeZohoBooks::ORG, 'name' => 'Example Trading LLC' ) ), $this->inner->organisations( $connection ) );
		$this->assertSame( array( 'organization_id' => FakeZohoBooks::ORG, 'organization_name' => 'Example Trading LLC', 'organization_currency' => 'AED' ), $this->inner->selectOrganisation( $connection, FakeZohoBooks::ORG ) );
		$config = $this->inner->oauthProvider()->config( $this->connection( array( 'record_payment' => 'yes' ) ) );
		$this->assertStringContainsString( 'ZohoBooks.invoices.CREATE', $config->scopeString() );
		$this->assertStringContainsString( 'ZohoBooks.customerpayments.CREATE', $config->scopeString() );
		$this->assertStringNotContainsString( 'customerpayments', $this->inner->oauthProvider()->config( $this->connection() )->scopeString() );
	}

	public function test_tax_codes_come_from_the_settings(): void {
		$this->assertSame( array( array( 'code' => 't-vat5', 'name' => 'VAT 5%', 'rate_percent' => '5' ) ), $this->connector->listTaxCodes( $this->connection() ) );
		$this->assertSame( array( array( 'code' => 't-vat5', 'label' => 'VAT 5%', 'rate_percent' => 5.0 ) ), $this->connector->listTaxes( $this->connection() ) );
	}

	public function test_rate_limit_codes_wait_as_documented_and_a_limit_answer_without_header_uses_the_code(): void {
		list( $item, $payload ) = SalesFixtures::order( '140' );
		$this->kit->http->route( 'GET', '~/invoices\?~', new HttpResponse( 429, '{"code":45,"message":"daily limit"}', array() ) );
		$daily = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $daily->error->code );
		$this->assertSame( 3600, $daily->error->retryAfter );
	}

	public function test_an_unauthorised_answer_after_a_refresh_is_a_sign_in_problem_and_a_scope_text_is_a_permission_problem(): void {
		$this->books->failNextWrite( 'auth' );
		list( $item, $payload ) = SalesFixtures::order( '141' );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertContains( $result->error->code, array( ErrorCode::Auth001, ErrorCode::Auth002 ), 'The refresh was refused, so the sign-in is over.' );

		$kit = ApiTestKit::make();
		$kit->http->route( 'POST', '~/oauth/v2/token~', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'access_token' => 'new-token-1006', 'expires_in' => 3600 ) ) );
		$kit->http->fallback( static fn(): HttpResponse => new HttpResponse( 401, '{"code":57,"message":"scope ZohoBooks.invoices.CREATE is missing"}', array() ) );
		$expires   = (string) ( (int) $kit->clock->now()->format( 'U' ) + 3600 );
		$connector = GuardedConnector::wrap( new ZohoBooksConnector( $kit->deps ) );
		$scoped    = $connector->send( $kit->connection( 'zoho-books', array( 'data_centre' => 'com', 'client_id' => 'x', 'organization_id' => FakeZohoBooks::ORG, 'organization_currency' => 'AED' ), array( 'client_secret' => 's', 'oauth_access_token' => 'a', 'oauth_refresh_token' => 'r', 'oauth_expires_at' => $expires ) ), $item, $payload );
		$this->assertSame( ErrorCode::Auth004, $scoped->error->code, 'A 401 that names a scope is a permission problem.' );
		$kit->cleanup();
	}

	public function test_reconciliation_and_invoice_read_back(): void {
		$this->books->addInvoice( 'WC-9001', 10.0 );
		$this->books->addInvoice( 'WC-9002', 20.0 );
		$this->books->addInvoice( 'INV-manual', 99.0 );
		list( $item, $payload ) = SalesFixtures::order( '142' );
		$sent                   = $this->connector->send( $this->connection(), $item, $payload );
		$list                   = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertSame( array( 'WC-9001', 'WC-9002', 'WC-142' ), array_keys( $list ) );
		$this->assertSame( 1000, $list['WC-9001']->extra['total_minor'] );
		$this->assertNull( $list['WC-9001']->extra['refunded_minor'] );
		$this->assertSame( array(), $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2035-01-01T00:00:00Z' ) ) );

		$invoice = $this->connector->fetchInvoice( $this->connection(), new ExternalRef( $sent->ref->id ) );
		$this->assertSame( 'sent', $invoice['status'] );
		$this->assertStringStartsWith( 'INV-', $invoice['number'] );
		$this->assertSame( '', $invoice['authority_status'] );
		$this->assertSame( '2026-11-01', $invoice['issued_at'] );
		$this->assertNull( $this->connector->fetchInvoice( $this->connection(), new ExternalRef( '404404' ) ) );
	}

	public function test_lookups_find_contacts_and_items_and_search_by_text(): void {
		$this->books->addItem( 'CS-BOWL-02', 'Stoneware bowl set' );
		$this->books->addContact( 'Dana Example', 'dana.example@example.test' );
		$connection = $this->connection();
		$this->assertSame( 'Dana Example', $this->connector->lookupCustomer( $connection, 'DANA.EXAMPLE@example.test' )->ref );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
		$this->assertSame( 'Stoneware bowl set', $this->connector->lookupProduct( $connection, 'cs-bowl-02' )->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$this->assertSame( 'CS-BOWL-02', $this->connector->searchProducts( $connection, 'bowl' )[0]->ref );
	}

	public function test_the_money_is_exact_in_three_decimals_and_a_discount_is_carried_before_tax(): void {
		list( $item, $payload ) = SalesFixtures::order( '143', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 12.345, $this->rows( 'invoices' )[0]['line_items'][0]['rate'] );

		list( $item, $payload )   = SalesFixtures::order( '144' );
		$payload['discount']      = array( 'total' => 500, 'codes' => 'WELCOME' );
		$payload['total']        -= 500;
		$result                   = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 4500, $result->ref->extra['total_minor'] );
		$this->assertSame( '5.00', $this->rows( 'invoices' )[1]['discount'] ?? '5.00' );
	}

	public function test_two_different_buyers_with_the_same_name_each_get_a_contact_and_the_email_comes_first_next_time(): void {
		list( $first, $p1 )  = SalesFixtures::order( '160', array( 'name' => 'John Smith', 'email' => 'john.smith@example.test', 'currency' => 'EUR' ) );
		list( $second, $p2 ) = SalesFixtures::order( '161', array( 'name' => 'John Smith', 'email' => 'john.other@example.test', 'currency' => 'EUR' ) );
		$connection          = $this->connection();

		$this->assertTrue( $this->connector->send( $connection, $first, $p1 )->isSuccess() );
		$result = $this->connector->send( $connection, $second, $p2 );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );

		$contacts = $this->rows( 'contacts' );
		$this->assertCount( 2, $contacts );
		$this->assertSame( 'John Smith', $contacts[0]['contact_name'] );
		$this->assertSame( 'John Smith (john.other@example.test)', $contacts[1]['contact_name'] );
		$this->assertSame( $contacts[1]['contact_id'], $this->rows( 'invoices' )[1]['customer_id'] );

		list( $third, $p3 ) = SalesFixtures::order( '162', array( 'name' => 'John Smith', 'email' => 'JOHN.OTHER@example.test', 'currency' => 'EUR' ) );
		$this->assertTrue( $this->connector->send( $connection, $third, $p3 )->isSuccess() );
		$this->assertCount( 2, $this->rows( 'contacts' ), 'The second John is found by email, not made again.' );
		$this->assertSame( $contacts[1]['contact_id'], $this->rows( 'invoices' )[2]['customer_id'] );
	}

	public function test_a_name_that_is_taken_for_another_reason_is_not_retried_forever(): void {
		$this->books->addContact( 'Dana Example', 'someone.else@example.test' );
		$this->books->addContact( 'Dana Example (dana.example@example.test)', 'third.person@example.test' );
		list( $item, $payload ) = SalesFixtures::order( '163' );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'already exists', $result->error->context['reason'] );
		$this->assertCount( 2, $this->rows( 'contacts' ) );
		$this->assertSame( 0, $this->books->writes );
	}

	public function test_a_payment_applies_the_balance_the_invoice_still_has_on_the_day_the_order_was_paid(): void {
		$options                = array( 'record_payment' => 'yes', 'payment_account' => 'acct-1' );
		$this->books->addInvoice( 'WC-170', 50.0, 'EUR', 20.0 );
		list( $item, $payload ) = SalesFixtures::order( '170', array( 'paid' => true ) );
		$payload['paid_at']     = '2026-11-03 09:00:00';
		$result                 = $this->connector->send( $this->connection( $options ), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$payment = $this->rows( 'customerpayments' )[0];
		$this->assertSame( 20.0, (float) $payment['amount'], 'A credit already took 30.00 off the invoice.' );
		$this->assertSame( '2026-11-03', $payment['date'], 'The paid date, not the order date.' );
		$this->assertSame( 'paid', $result->ref->extra['status'] );
		$this->assertSame( 0.0, (float) $this->rows( 'invoices' )[0]['balance'] );
	}

	public function test_an_invoice_that_nothing_is_owed_on_gets_no_payment(): void {
		$this->books->addInvoice( 'WC-171', 50.0, 'EUR', 0.0 );
		list( $item, $payload ) = SalesFixtures::order( '171', array( 'paid' => true ) );
		$result                 = $this->connector->send( $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'acct-1' ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 0, $this->rows( 'customerpayments' ) );
	}

	public function test_a_sku_that_is_not_in_the_item_name_is_found_and_the_item_list_is_read_once_per_order(): void {
		$tray = $this->books->addItem( 'ZZ-900', 'Walnut tray' );
		$bowl = $this->books->addItem( 'ZZ-901', 'Stoneware bowl', 12.0, 'Hand made' );
		$lines                  = array(
			array( 'sku' => 'ZZ-900', 'name' => 'Tray', 'quantity' => '1', 'unit_price' => 2000, 'total' => 2000, 'tax' => 0 ),
			array( 'sku' => 'zz-901', 'name' => 'Bowl', 'quantity' => '2', 'unit_price' => 1500, 'total' => 3000, 'tax' => 0 ),
		);
		list( $item, $payload ) = SalesFixtures::order( '172', array( 'lines' => $lines ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $this->rows( 'invoices' )[0];
		$this->assertSame( $tray, $invoice['line_items'][0]['item_id'] );
		$this->assertSame( $bowl, $invoice['line_items'][1]['item_id'] );
		$reads = array_filter( $this->books->calls, static fn( string $call ): bool => str_starts_with( $call, 'GET /books/v3/items' ) && ! str_contains( $call, 'search_text' ) );
		$this->assertCount( 1, $reads, 'One read of the item list serves both lines.' );
	}

	public function test_an_item_with_no_sku_never_matches_and_the_refusal_names_the_sku(): void {
		$this->books->addItem( '', 'Gift wrap' );
		list( $item, $payload ) = SalesFixtures::order( '173', array( 'sku' => 'GIFT-WRAP', 'product' => 'Gift wrap' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code, 'Matching is by SKU; an item that has the same name but no SKU is not a match.' );
		$this->assertSame( 'GIFT-WRAP', $result->error->context['sku'] );
		$this->assertSame( 0, $this->books->writes );
	}

	public function test_the_sku_mapping_search_finds_a_sku_that_is_not_in_the_name(): void {
		$this->books->addItem( 'ZZ-902', 'Linen cloth' );
		$found = $this->connector->searchProducts( $this->connection(), 'zz-90' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'ZZ-902', $found[0]->ref );
	}

	public function test_the_daily_allowance_follows_the_plan(): void {
		$method = new \ReflectionMethod( $this->inner, 'limitsFor' );
		$method->setAccessible( true );
		$this->assertSame( 900, $method->invoke( $this->inner, $this->connection() )->requestsPerDay, 'Free is the default.' );
		$this->assertSame( 1800, $method->invoke( $this->inner, $this->connection( array( 'plan' => 'standard' ) ) )->requestsPerDay );
		$this->assertSame( 4500, $method->invoke( $this->inner, $this->connection( array( 'plan' => 'professional' ) ) )->requestsPerDay );
		$this->assertSame( 9000, $method->invoke( $this->inner, $this->connection( array( 'plan' => 'premium' ) ) )->requestsPerDay );
		$this->assertSame( 900, $method->invoke( $this->inner, $this->connection( array( 'plan' => 'nonsense' ) ) )->requestsPerDay );
		$names = array_map( static fn( $field ): string => $field->name, $this->inner->credentialFields() );
		$this->assertContains( 'plan', $names );
	}

	public function test_the_connector_stops_with_a_wait_before_the_daily_allowance_is_used_up(): void {
		$connection = $this->connection();
		for ( $i = 0; $i < 900; $i++ ) {
			$this->assertTrue( $this->connector->ping( $connection ) );
		}
		$this->assertFalse( $this->connector->ping( $connection ), 'The 901st call is not made.' );
		$callsBefore            = count( $this->books->calls );
		list( $item, $payload ) = SalesFixtures::order( '174' );
		$stopped                = $this->connector->send( $connection, $item, $payload );

		$this->assertSame( ErrorCode::Rate001, $stopped->error->code );
		$this->assertGreaterThan( 11 * 3600, $stopped->error->retryAfter, 'It waits for the next day, not a minute.' );
		$this->assertSame( $callsBefore, count( $this->books->calls ), 'Nothing reached Zoho.' );
		$this->assertSame( 0, $this->books->writes );
	}

	public function test_ping_is_one_cheap_call_and_false_for_a_refused_sign_in(): void {
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
		$this->assertSame( array( 'GET /books/v3/organizations' ), $this->books->calls );

		$this->books->failNextWrite( 'auth' );
		$this->assertFalse( $this->connector->ping( $this->connection() ) );
	}
}
