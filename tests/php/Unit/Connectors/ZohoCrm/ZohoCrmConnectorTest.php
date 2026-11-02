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

	public function test_a_field_the_merchant_made_is_searched_first_and_the_name_carries_the_reference_too(): void {
		list( $item, $payload ) = SalesFixtures::order( '131', array( 'currency' => 'AED' ) );
		$connection             = $this->connection( array( 'reference_field' => 'Woo_Order' ) );
		$this->connector->send( $connection, $item, $payload );
		$deal = $this->zoho->all( 'Deals' )[0];
		$this->assertSame( 'WC-131', $deal['Woo_Order'] );
		$this->assertSame( 'WC-131', $deal['Deal_Name'], 'The name is the documented fallback key.' );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );

		$this->zoho->rows['Deals'][ $deal['id'] ]['Deal_Name'] = 'Mugs for Dana';
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted, 'A renamed deal is still found.' );
		$this->assertCount( 1, $this->zoho->all( 'Deals' ) );
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

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function standardPipeline(): array {
		return array( array( 'display_value' => 'Standard (Standard)', 'actual_value' => 'Standard (Standard)', 'default' => true ) );
	}

	public function test_a_reconciliation_reads_each_deals_own_currency_so_a_kuwaiti_dinar_deal_is_not_lost(): void {
		$this->zoho->multiCurrency = true;
		$this->zoho->addDeal( 'WC-9001', 37.035, 'KWD' );
		$this->zoho->addDeal( 'WC-9002', 10.5, 'AED' );
		$this->zoho->addDeal( 'WC-9003', 1999.0, 'JPY' );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertSame( 37035, $list['WC-9001']->extra['total_minor'], 'Three decimals, read with the deal currency.' );
		$this->assertSame( 'KWD', $list['WC-9001']->extra['currency'] );
		$this->assertSame( 1050, $list['WC-9002']->extra['total_minor'] );
		$this->assertSame( 1999, $list['WC-9003']->extra['total_minor'], 'No decimals for yen.' );
	}

	public function test_a_single_currency_organisation_reads_amounts_in_its_own_currency_without_asking_for_the_currency_column(): void {
		$this->zoho->addDeal( 'WC-9101', 12.5 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertSame( 1250, $list['WC-9101']->extra['total_minor'] );
		$this->assertSame( 'AED', $list['WC-9101']->extra['currency'] );
		$this->assertSame( 1, $this->zoho->countCalls( 'POST /crm/v8/coql' ), 'mc_status said no, so the column was never asked for.' );
	}

	public function test_when_the_organisation_does_not_say_the_query_asks_for_the_currency_and_falls_back_once(): void {
		$this->zoho->reportsMultiCurrency = false;
		$this->zoho->addDeal( 'WC-9201', 12.5 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertSame( 1250, $list['WC-9201']->extra['total_minor'] );
		$this->assertSame( 2, $this->zoho->countCalls( 'POST /crm/v8/coql' ), 'The refused query and the one without the column.' );

		$this->zoho->multiCurrency = true;
		$this->zoho->addDeal( 'WC-9202', 37.035, 'KWD' );
		$again = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertSame( 37035, $again['WC-9202']->extra['total_minor'] );
	}

	public function test_test_connection_chooses_the_only_pipeline_and_deals_carry_it(): void {
		$this->zoho->pipelines = $this->standardPipeline();
		$test                  = $this->connector->test( $this->connection() );
		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertTrue( $test->permissions['read_pipelines'] );
		$this->assertSame( 'Standard (Standard)', $test->detected['pipeline'] );

		list( $item, $payload ) = SalesFixtures::order( '180', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'detected' => $test->detected ) ), $item, $payload )->isSuccess() );
		$this->assertSame( 'Standard (Standard)', $this->zoho->all( 'Deals' )[0]['Pipeline'] );
	}

	public function test_test_connection_picks_the_default_pipeline_but_asks_when_there_are_several_and_none_is_the_default(): void {
		$this->zoho->pipelines = array(
			array( 'display_value' => 'Sales (Sales)', 'actual_value' => 'Sales (Sales)', 'default' => false ),
			array( 'display_value' => 'Web shop (Web shop)', 'actual_value' => 'Web shop (Web shop)', 'default' => true ),
		);
		$this->assertSame( 'Web shop (Web shop)', $this->connector->test( $this->connection() )->detected['pipeline'] );

		$this->zoho->pipelines[1]['default'] = false;
		$ambiguous                           = $this->connector->test( $this->connection() );
		$this->assertFalse( $ambiguous->ok );
		$this->assertSame( 'pipeline', $ambiguous->error->context['field'] );
		$this->assertStringContainsString( 'Sales (Sales), Web shop (Web shop)', $ambiguous->error->context['reason'] );

		$chosen = $this->connector->test( $this->connection( array( 'pipeline' => 'web shop (web shop)' ) ) );
		$this->assertTrue( $chosen->ok, 'A named pipeline is accepted whatever its case.' );
		$this->assertArrayNotHasKey( 'pipeline', $chosen->detected, 'The merchant chose; nothing is stored.' );

		$unknown = $this->connector->test( $this->connection( array( 'pipeline' => 'Nope' ) ) );
		$this->assertFalse( $unknown->ok );
		$this->assertStringContainsString( 'Sales (Sales)', $unknown->error->context['reason'] );
	}

	public function test_an_account_without_pipelines_needs_none_and_a_missing_scope_is_a_soft_warning(): void {
		$none = $this->connector->test( $this->connection() );
		$this->assertTrue( $none->ok );
		$this->assertArrayNotHasKey( 'pipeline', $none->detected );

		$this->zoho->pipelines      = $this->standardPipeline();
		$this->zoho->deniedSettings = array( 'pipeline' );
		$soft                       = $this->connector->test( $this->connection() );
		$this->assertTrue( $soft->ok, 'The test still passes; the person is told what is missing.' );
		$this->assertContains( 'read_pipelines', $soft->missingPermissions() );
	}

	public function test_a_deal_without_a_pipeline_in_an_account_that_has_them_says_what_to_do(): void {
		$this->zoho->pipelines  = $this->standardPipeline();
		list( $item, $payload ) = SalesFixtures::order( '181', array( 'currency' => 'AED' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'Pipeline', $result->error->context['field'] );
		$this->assertStringContainsString( 'pipeline name', $result->error->context['reason'] );
		$this->assertCount( 0, $this->zoho->all( 'Deals' ) );
	}

	public function test_test_connection_makes_the_reference_field_once_and_every_deal_carries_it_beside_the_name(): void {
		$this->zoho->strictFields = true;
		$first                    = $this->connector->test( $this->connection() );
		$this->assertTrue( $first->ok, (string) $first->error?->message() );
		$this->assertTrue( $first->permissions['create_reference_field'] );
		$this->assertSame( 'Woo_Order_Ref', $first->detected['reference_field'] );
		$this->assertSame( 'field', $first->detected['reference_mode'] );
		$this->assertArrayNotHasKey( 'warning', $first->detected );
		$this->assertSame( array( 'Woo_Order_Ref' => 'Woo Order Ref' ), $this->zoho->customFields );

		$this->connector->test( $this->connection() );
		$this->assertSame( 1, $this->zoho->countCalls( 'POST /crm/v8/settings/fields' ), 'The second test finds it.' );

		$connection             = $this->connection( array( 'detected' => $first->detected ) );
		list( $item, $payload ) = SalesFixtures::order( '182', array( 'currency' => 'AED' ) );
		$sent                   = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$deal = $this->zoho->all( 'Deals' )[0];
		$this->assertSame( 'WC-182', $deal['Woo_Order_Ref'] );
		$this->assertSame( 'WC-182', $deal['Deal_Name'] );
	}

	public function test_a_rep_who_renames_the_deal_does_not_break_replays_refunds_or_reconciliation(): void {
		$this->zoho->strictFields = true;
		$detected                 = $this->connector->test( $this->connection() )->detected;
		$connection               = $this->connection( array( 'detected' => $detected ) );
		list( $order, $payload )  = SalesFixtures::order( '183', array( 'currency' => 'AED' ) );
		$first                    = $this->connector->send( $connection, $order, $payload );
		$this->zoho->rows['Deals'][ $first->ref->id ]['Deal_Name'] = 'Dana - big mug order';

		$replay = $this->connector->send( $connection, $order, $payload );
		$this->assertTrue( $replay->alreadyExisted );
		$this->assertCount( 1, $this->zoho->all( 'Deals' ), 'No second deal.' );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $order )->id );

		list( $refund, $rpayload ) = SalesFixtures::refund( '55', '183', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $connection, $refund, $rpayload )->isSuccess() );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->extra['deal_id'] );

		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertArrayHasKey( 'WC-183', $list, 'The reference field is read, not the new name.' );
	}

	public function test_a_deal_made_before_the_field_existed_is_still_found_by_its_name(): void {
		$legacy = $this->zoho->addDeal( 'WC-184', 50.0 );
		$this->zoho->addDeal( 'Website redesign', 999.0 );
		$connection             = $this->connection( array( 'detected' => array( 'reference_field' => 'Woo_Order_Ref' ) ) );
		list( $item, $payload ) = SalesFixtures::order( '184', array( 'currency' => 'AED' ) );
		$this->zoho->rows['Notes']['9'] = array( 'id' => '9', 'Note_Title' => 'WC-184', 'Parent' => $legacy, '_deleted' => false, 'Created_Time' => '2026-01-01T00:00:00+00:00' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( $legacy, $result->ref->id );
		$this->assertCount( 2, $this->zoho->all( 'Deals' ) );
		$this->assertArrayHasKey( 'WC-184', $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) ) );
	}

	public function test_when_the_field_cannot_be_made_the_name_stays_the_key_and_the_test_says_so(): void {
		$this->zoho->strictFields     = true;
		$this->zoho->fieldsCreatable = false;
		$test                        = $this->connector->test( $this->connection() );
		$this->assertTrue( $test->ok, 'Orders still work; only the safer key is missing.' );
		$this->assertFalse( $test->permissions['create_reference_field'] );
		$this->assertSame( array( 'create_reference_field' ), $test->missingPermissions() );
		$this->assertSame( 'name', $test->detected['reference_mode'] );
		$this->assertSame( 'reference_field', $test->detected['warning'] );
		$this->assertArrayNotHasKey( 'reference_field', $test->detected );

		list( $item, $payload ) = SalesFixtures::order( '185', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'detected' => $test->detected ) ), $item, $payload )->isSuccess(), 'Nothing is written to a field that does not exist.' );
		$this->assertSame( 'WC-185', $this->zoho->all( 'Deals' )[0]['Deal_Name'] );

		$this->zoho->fieldsCreatable = true;
		$this->zoho->fieldCap        = 0;
		$this->assertFalse( $this->connector->test( $this->connection() )->permissions['create_reference_field'], 'An edition at its field cap is the same answer.' );

		$this->zoho->deniedSettings = array( 'fields' );
		$scope                      = $this->connector->test( $this->connection() );
		$this->assertTrue( $scope->ok );
		$this->assertFalse( $scope->permissions['create_reference_field'] );
	}

	public function test_naming_the_deal_name_on_purpose_creates_nothing_and_warns_about_nothing(): void {
		$test = $this->connector->test( $this->connection( array( 'reference_field' => 'name' ) ) );
		$this->assertTrue( $test->ok );
		$this->assertSame( 0, $this->zoho->countCalls( 'POST /crm/v8/settings/fields' ) );
		$this->assertArrayNotHasKey( 'create_reference_field', $test->permissions );
		$this->assertArrayNotHasKey( 'warning', $test->detected );
		$this->assertSame( 'name', $test->detected['reference_mode'] );
	}

	public function test_ping_is_one_cheap_call_and_false_for_a_refused_sign_in(): void {
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
		$this->assertSame( array( 'GET /crm/v8/org' ), $this->zoho->calls );

		$this->zoho->failNextWrite( 'auth' );
		$this->assertFalse( $this->connector->ping( $this->connection() ) );
	}

	public function test_the_sign_in_asks_for_the_scopes_that_read_pipelines_and_make_the_field(): void {
		$scopes = $this->inner->oauthProvider()->config( $this->connection() )->scopeString();
		foreach ( array( 'ZohoCRM.settings.pipeline.READ', 'ZohoCRM.settings.layouts.READ', 'ZohoCRM.settings.fields.READ', 'ZohoCRM.settings.fields.CREATE' ) as $scope ) {
			$this->assertStringContainsString( $scope, $scopes );
		}
	}
}
