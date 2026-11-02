<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ZohoCrm;

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\ZohoCrm\ZohoCrmConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeZohoCrm;
use Estasoft\SyncEngine\Tests\Support\SalesFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Order, refund, customer, sign-in, reconciliation and error behaviour of the Zoho CRM connector.
 */
final class ZohoCrmConnectorTest extends TestCase {

	private ApiTestKit $kit;

	private FakeZohoCrm $zoho;

	private ZohoCrmConnector $inner;

	private ConnectorInterface $connector;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
		$this->zoho = new FakeZohoCrm();
		$this->zoho->install( $this->kit->http );
		$this->inner     = new ZohoCrmConnector( $this->kit->deps );
		$this->connector = GuardedConnector::wrap( $this->inner );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * @param array<string,mixed>  $config  Config and options.
	 * @param array<string,string> $secrets Secret overrides.
	 */
	private function connection( array $config = array(), array $secrets = array() ): Connection {
		$expires = (string) ( (int) $this->kit->clock->now()->format( 'U' ) + 3600 );
		return $this->kit->connection(
			'zoho-crm',
			$config + array( 'data_centre' => 'com', 'client_id' => '1000.SYNTHETICCLIENT', 'org_currency' => 'AED' ),
			$secrets + array( 'client_secret' => FakeZohoCrm::SECRET, 'oauth_access_token' => FakeZohoCrm::ACCESS, 'oauth_refresh_token' => FakeZohoCrm::REFRESH, 'oauth_expires_at' => $expires )
		);
	}

	public function test_an_order_makes_contact_account_deal_and_the_marker_note_last(): void {
		list( $item, $payload ) = SalesFixtures::order( '123', array( 'currency' => 'AED', 'company' => 'Example Trading LLC', 'note' => 'Leave at the gate' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5000, $result->ref->extra['total_minor'] );
		$deal = $this->zoho->all( 'Deals' )[0];
		$this->assertSame( 'WC-123', $deal['Deal_Name'] );
		$this->assertSame( 'Qualification', $deal['Stage'] );
		$this->assertSame( 50.0, (float) $deal['Amount'] );
		$this->assertSame( '2026-11-01', $deal['Closing_Date'] );
		$this->assertSame( 'Leave at the gate', $deal['Description'] );
		$this->assertArrayNotHasKey( 'Currency', $deal, 'The order is in the organisation currency.' );
		$this->assertSame( $this->zoho->all( 'Contacts' )[0]['id'], $deal['Contact_Name']['id'] );
		$this->assertSame( $this->zoho->all( 'Accounts' )[0]['id'], $deal['Account_Name']['id'] );
		$note = $this->zoho->all( 'Notes' )[0];
		$this->assertSame( 'WC-123', $note['Note_Title'] );
		$this->assertStringStartsWith( "WC-123\n", $note['Note_Content'] );
		$this->assertSame( 'Deals', $note['Parent_Id']['module']['api_name'] );
		$this->assertStringStartsWith( 'POST /crm/v8/Notes', end( $this->zoho->calls ) );
	}

	public function test_paid_orders_and_the_stage_options(): void {
		list( $item, $payload ) = SalesFixtures::order( '124', array( 'currency' => 'AED', 'paid' => true ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 'Closed Won', $this->zoho->all( 'Deals' )[0]['Stage'] );

		list( $item, $payload ) = SalesFixtures::order( '125', array( 'currency' => 'AED', 'paid' => true ) );
		$this->connector->send( $this->connection( array( 'stage_paid' => 'Won Stage' ) ), $item, $payload );
		$this->assertSame( 'Won Stage', $this->zoho->all( 'Deals' )[1]['Stage'] );

		list( $item, $payload ) = SalesFixtures::order( '126', array( 'currency' => 'AED' ) );
		$result                 = $this->connector->send( $this->connection( array( 'stage_unpaid' => 'No Such Stage' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'Stage', $result->error->context['field'] );
	}

	public function test_sending_twice_writes_nothing_the_second_time(): void {
		list( $item, $payload ) = SalesFixtures::order( '127', array( 'currency' => 'AED' ) );
		$connection             = $this->connection();
		$first                  = $this->connector->send( $connection, $item, $payload );
		$writes                 = $this->zoho->writes;
		$second                 = $this->connector->send( $connection, $item, $payload );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->zoho->writes );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $item )->id );
	}

	public function test_a_deal_without_its_note_is_unfinished_and_the_retry_completes_it(): void {
		list( $item, $payload ) = SalesFixtures::order( '128', array( 'currency' => 'AED' ) );
		$connection             = $this->connection();
		$this->zoho->failNextWrite( 'server_error', 0, 2 );
		$first = $this->connector->send( $connection, $item, $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->zoho->all( 'Deals' ) );
		$this->assertNull( $this->connector->findExisting( $connection, $item ) );
		$retry = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->zoho->all( 'Deals' ) );
		$this->assertCount( 1, $this->zoho->all( 'Contacts' ) );
		$this->assertCount( 1, $this->zoho->all( 'Notes' ) );
	}

	public function test_a_foreign_currency_needs_multi_currency_and_is_sent_as_the_currency_field(): void {
		list( $item, $payload ) = SalesFixtures::order( '129', array( 'currency' => 'EUR' ) );
		$refused                = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'Currency', $refused->error->context['field'] );

		$this->zoho->multiCurrency = true;
		$accepted                  = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $accepted->isSuccess(), (string) $accepted->error?->message() );
		$this->assertSame( 'EUR', $this->zoho->all( 'Deals' )[0]['Currency'] );
		$this->assertSame( 'EUR', $accepted->ref->extra['currency'] );
	}

	public function test_a_pipeline_is_sent_only_when_given_and_a_missing_one_is_named(): void {
		$this->zoho->requirePipeline = true;
		list( $item, $payload )      = SalesFixtures::order( '130', array( 'currency' => 'AED' ) );
		$refused                     = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 'Pipeline', $refused->error->context['field'] );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'pipeline' => 'Standard (Standard)' ) ), $item, $payload )->isSuccess() );
	}

	public function test_a_custom_reference_field_is_searched_and_the_name_stays_readable(): void {
		list( $item, $payload ) = SalesFixtures::order( '131', array( 'currency' => 'AED' ) );
		$connection             = $this->connection( array( 'reference_field' => 'Woo_Order' ) );
		$this->connector->send( $connection, $item, $payload );
		$deal = $this->zoho->all( 'Deals' )[0];
		$this->assertSame( 'WC-131', $deal['Woo_Order'] );
		$this->assertSame( 'Order 131', $deal['Deal_Name'] );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
	}

	public function test_contacts_are_found_by_email_and_a_duplicate_answer_gives_the_existing_one(): void {
		$this->zoho->uniqueEmail = true;
		$this->zoho->searchBlind = true;
		$existing                = '4999';
		$this->zoho->rows['Contacts'][ $existing ] = array( 'id' => $existing, 'Last_Name' => 'Example', 'First_Name' => 'Dana', 'Email' => 'Dana.Example@example.test', 'Created_Time' => '2026-01-01T00:00:00+00:00', '_deleted' => false );
		list( $item, $payload ) = SalesFixtures::order( '132', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertSame( $existing, $this->zoho->all( 'Deals' )[0]['Contact_Name']['id'] );
		$this->assertCount( 1, $this->zoho->all( 'Contacts' ) );

		$this->zoho->searchBlind = false;
		list( $item, $payload )  = SalesFixtures::order( '133', array( 'currency' => 'AED' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( $existing, $this->zoho->all( 'Deals' )[1]['Contact_Name']['id'], 'Found by email, case-insensitively.' );
	}

	public function test_a_buyer_with_one_name_gets_it_as_the_last_name_and_company_names_with_commas_are_escaped(): void {
		list( $item, $payload ) = SalesFixtures::order( '134', array( 'currency' => 'AED', 'name' => 'Madonna', 'company' => 'Acme (Dubai), LLC' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$contact = $this->zoho->all( 'Contacts' )[0];
		$this->assertSame( 'Madonna', $contact['Last_Name'] );
		$this->assertArrayNotHasKey( 'First_Name', $contact );

		list( $item, $payload ) = SalesFixtures::order( '135', array( 'currency' => 'AED', 'company' => 'Acme (Dubai), LLC' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertCount( 1, $this->zoho->all( 'Accounts' ), 'The account was found through an escaped criteria.' );
		$escaped = array_values( array_filter( $this->zoho->calls, static fn( string $call ): bool => str_contains( $call, 'Accounts/search' ) ) );
		$this->assertStringContainsString( rawurlencode( 'Acme \(Dubai\)\, LLC' ), $escaped[1] );
	}

	public function test_a_refund_is_a_note_on_the_deal_and_is_not_made_twice(): void {
		list( $order, $payload )   = SalesFixtures::order( '136', array( 'currency' => 'AED' ) );
		$connection                = $this->connection();
		list( $refund, $rpayload ) = SalesFixtures::refund( '47', '136', array( 'currency' => 'AED' ) );

		$this->assertSame( ErrorCode::Val002, $this->connector->send( $connection, $refund, $rpayload )->error->code );
		$this->connector->send( $connection, $order, $payload );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ) );
		$first  = $this->connector->send( $connection, $refund, $rpayload );
		$second = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->id );
		$this->assertCount( 2, $this->zoho->all( 'Notes' ) );
		$this->assertStringContainsString( '12.00 AED', end( $this->zoho->rows['Notes'] )['Note_Content'] );
	}

	public function test_a_customer_item_finds_or_creates_the_contact(): void {
		$item = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'k5', ItemState::Syncing );
		list( , $payload ) = SalesFixtures::order( '137' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->alreadyExisted );
		$this->assertCount( 1, $this->zoho->all( 'Contacts' ) );
	}

	public function test_test_connection_names_the_organisation_and_the_missing_scope(): void {
		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'Example Trading LLC', $good->company );
		$this->assertSame( 'AED', $good->detected['currency'] );

		$this->zoho->denied = array( 'Notes' );
		$bad                = $this->connector->test( $this->connection() );
		$this->assertFalse( $bad->ok );
		$this->assertSame( array( 'read_notes' ), $bad->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $bad->error->code );
	}

	public function test_low_credits_stop_the_connector_with_a_wait_and_a_429_without_retry_after_waits_a_minute(): void {
		$this->zoho->credits    = 10;
		list( $item, $payload ) = SalesFixtures::order( '138', array( 'currency' => 'AED' ) );
		$low                    = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $low->error->code );
		$this->assertSame( 3600, $low->error->retryAfter );
		$this->assertSame( 0, $this->zoho->writes );

		$this->zoho->credits = null;
		$this->kit->http->route( 'GET', '~/Deals/search~', new HttpResponse( 429, '{"code":"TOO_MANY_REQUESTS","message":"x","status":"error"}', array() ) );
		$limited = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 60, $limited->error->retryAfter );
	}

	public function test_an_expired_token_is_refreshed_once_and_a_refused_token_refreshes_and_retries(): void {
		list( $item, $payload ) = SalesFixtures::order( '139', array( 'currency' => 'AED' ) );
		$stale                  = $this->connection( array(), array( 'oauth_access_token' => 'expired-token-0006', 'oauth_expires_at' => '1' ) );
		$this->assertTrue( $this->connector->send( $stale, $item, $payload )->isSuccess() );
		$this->assertSame( 1, $this->zoho->tokenCalls );
		$this->assertSame( 'Zoho-oauthtoken ' . FakeZohoCrm::FRESH, $this->kit->http->requests[ count( $this->kit->http->requests ) - 1 ]['headers']['Authorization'] );

		$refused = $this->connection( array(), array( 'oauth_access_token' => 'revoked-token-0007' ) );
		list( $item, $payload ) = SalesFixtures::order( '140', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $refused, $item, $payload )->isSuccess() );
		$this->assertSame( 2, $this->zoho->tokenCalls );
	}

	public function test_the_data_centre_decides_the_hosts_for_sign_in_and_data(): void {
		$provider = $this->inner->oauthProvider();
		$config   = $provider->config( $this->connection( array( 'data_centre' => 'ca' ) ) );
		$this->assertSame( 'https://accounts.zohocloud.ca/oauth/v2/auth', $config->authorizeUrl );
		$this->assertSame( 'https://accounts.zohocloud.ca/oauth/v2/token', $config->tokenUrl );
		$this->assertSame( ',', $config->scopeSeparator );
		$this->assertStringContainsString( 'ZohoCRM.modules.deals.CREATE,ZohoCRM.modules.deals.READ', $config->scopeString() );
		$this->assertSame( 'offline', $config->extraAuthorizeParams['access_type'] );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( 'body', $config->clientAuth );

		list( $item, $payload ) = SalesFixtures::order( '141', array( 'currency' => 'AED' ) );
		$this->connector->send( $this->connection( array( 'data_centre' => 'eu' ) ), $item, $payload );
		$this->assertStringStartsWith( 'https://www.zohoapis.eu/crm/v8/', $this->kit->http->requests[0]['url'] );
	}

	public function test_sign_in_helpers_list_the_organisation_and_store_its_currency(): void {
		$connection = $this->connection();
		$this->assertSame( array( array( 'id' => '9000001', 'name' => 'Example Trading LLC' ) ), $this->inner->organisations( $connection ) );
		$this->assertSame( array( 'org_id' => '9000001', 'org_name' => 'Example Trading LLC', 'org_currency' => 'AED' ), $this->inner->selectOrganisation( $connection, '9000001' ) );
	}

	public function test_reconciliation_reads_order_deals_through_coql_across_pages(): void {
		for ( $i = 1; $i <= 230; $i++ ) {
			$this->zoho->addDeal( 'WC-' . ( 8000 + $i ), 10.0 );
		}
		$this->zoho->addDeal( 'Website redesign', 999.0 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertCount( 230, $list );
		$this->assertSame( 1000, $list['WC-8001']->extra['total_minor'] );
		$this->assertNull( $list['WC-8001']->extra['refunded_minor'] );
		$this->assertSame( 2, $this->zoho->countCalls( 'POST /crm/v8/coql' ) );
		$this->assertSame( array(), $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2035-01-01T00:00:00Z' ) ) );
	}

	public function test_a_lookup_by_email_and_bad_payloads(): void {
		list( $item, $payload ) = SalesFixtures::order( '142', array( 'currency' => 'AED' ) );
		$connection             = $this->connection();
		$this->connector->send( $connection, $item, $payload );
		$this->assertSame( 'Dana Example', $this->connector->lookupCustomer( $connection, 'DANA.EXAMPLE@example.test' )->ref );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
		$this->zoho->addProduct( 'CS-BOWL-02', 'Stoneware bowl set' );
		$this->assertSame( 'Stoneware bowl set', $this->connector->lookupProduct( $connection, 'cs-bowl-02' )->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$found = $this->connector->searchProducts( $connection, 'bowl' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-BOWL-02', $found[0]->ref );

		list( $item, $payload ) = SalesFixtures::order( '143', array( 'email' => '' ) );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $connection, $item, $payload )->error->code );
	}

	public function test_the_money_is_exact_and_no_secret_is_in_a_url(): void {
		$this->zoho->multiCurrency = true;
		list( $item, $payload )    = SalesFixtures::order( '144', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$result                    = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 37.035, $this->zoho->all( 'Deals' )[0]['Amount'] );
		foreach ( $this->kit->http->requests as $request ) {
			foreach ( array( FakeZohoCrm::ACCESS, FakeZohoCrm::REFRESH, FakeZohoCrm::SECRET ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $request['url'] );
			}
		}
	}
}
