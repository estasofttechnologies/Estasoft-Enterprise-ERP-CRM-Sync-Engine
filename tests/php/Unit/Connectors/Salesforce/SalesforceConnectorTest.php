<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Salesforce;

use Estasoft\SyncEngine\Connectors\Salesforce\SalesforceConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSalesforce;
use Estasoft\SyncEngine\Tests\Support\SalesFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Order, refund, customer, sign-in, reconciliation and error behaviour of the Salesforce connector.
 */
final class SalesforceConnectorTest extends TestCase {

	private ApiTestKit $kit;

	private FakeSalesforce $sf;

	private SalesforceConnector $inner;

	private ConnectorInterface $connector;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
		$this->sf  = new FakeSalesforce();
		$this->sf->install( $this->kit->http );
		$this->inner     = new SalesforceConnector( $this->kit->deps );
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
			'salesforce',
			$config + array( 'domain' => 'example.my.salesforce.com', 'client_id' => '3MVG9SyntheticConsumerKey', 'org_currency' => 'EUR' ),
			$secrets + array( 'client_secret' => FakeSalesforce::SECRET, 'oauth_access_token' => FakeSalesforce::ACCESS, 'oauth_refresh_token' => FakeSalesforce::REFRESH, 'oauth_expires_at' => $expires )
		);
	}

	public function test_an_order_makes_account_contact_opportunity_and_the_contact_role_last(): void {
		list( $item, $payload ) = SalesFixtures::order( '123', array( 'company' => 'Example Trading LLC', 'note' => 'Leave at the gate' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 5000, $result->ref->extra['total_minor'] );
		$opp = $this->sf->all( 'Opportunity' )[0];
		$this->assertSame( 'WC-123', $opp['Name'] );
		$this->assertSame( 'Prospecting', $opp['StageName'] );
		$this->assertSame( '2026-11-01', $opp['CloseDate'] );
		$this->assertSame( 50.0, (float) $opp['Amount'] );
		$this->assertStringStartsWith( "WC-123\n", $opp['Description'] );
		$this->assertSame( $this->sf->all( 'Account' )[0]['Id'], $opp['AccountId'] );
		$contact = $this->sf->all( 'Contact' )[0];
		$this->assertSame( 'Example', $contact['LastName'] );
		$this->assertSame( 'Dana', $contact['FirstName'] );
		$role = $this->sf->all( 'OpportunityContactRole' )[0];
		$this->assertTrue( $role['IsPrimary'] );
		$this->assertSame( $contact['Id'], $role['ContactId'] );
		$this->assertStringStartsWith( 'POST /services/data/v66.0/sobjects/OpportunityContactRole', end( $this->sf->calls ) );
	}

	public function test_paid_orders_the_stage_options_and_a_stage_salesforce_refuses(): void {
		list( $item, $payload ) = SalesFixtures::order( '124', array( 'paid' => true ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 'Closed Won', $this->sf->all( 'Opportunity' )[0]['StageName'] );

		list( $item, $payload ) = SalesFixtures::order( '125' );
		$this->connector->send( $this->connection( array( 'stage_unpaid' => 'Qualification' ) ), $item, $payload );
		$this->assertSame( 'Qualification', $this->sf->all( 'Opportunity' )[1]['StageName'] );

		list( $item, $payload ) = SalesFixtures::order( '126' );
		$result                 = $this->connector->send( $this->connection( array( 'stage_unpaid' => 'Nonsense' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'StageName', $result->error->context['field'] );
	}

	public function test_sending_twice_writes_nothing_the_second_time(): void {
		list( $item, $payload ) = SalesFixtures::order( '127' );
		$connection             = $this->connection();
		$first                  = $this->connector->send( $connection, $item, $payload );
		$writes                 = $this->sf->writes;
		$second                 = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->sf->writes );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $item )->id );
	}

	public function test_an_opportunity_without_its_contact_role_is_unfinished_and_the_retry_completes_it(): void {
		list( $item, $payload ) = SalesFixtures::order( '128' );
		$connection             = $this->connection();
		$this->sf->failNextWrite( 'server_error', 0, 2 );
		$first = $this->connector->send( $connection, $item, $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->sf->all( 'Opportunity' ) );
		$this->assertNull( $this->connector->findExisting( $connection, $item ) );
		$retry = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted );
		$this->assertCount( 1, $this->sf->all( 'Opportunity' ) );
		$this->assertCount( 1, $this->sf->all( 'Contact' ) );
		$this->assertCount( 1, $this->sf->all( 'OpportunityContactRole' ) );
	}

	public function test_currency_is_sent_only_in_multi_currency_orgs_and_a_foreign_order_is_refused_otherwise(): void {
		list( $item, $payload ) = SalesFixtures::order( '129', array( 'currency' => 'AED' ) );
		$refused                = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'CurrencyIsoCode', $refused->error->context['field'] );
		$this->assertStringContainsString( 'AED', $refused->error->context['reason'] );
		$this->assertSame( 0, $this->sf->writes );

		$this->sf->multiCurrency = true;
		$accepted                = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $accepted->isSuccess(), (string) $accepted->error?->message() );
		$this->assertSame( 'AED', $this->sf->all( 'Opportunity' )[0]['CurrencyIsoCode'] );
	}

	public function test_a_custom_reference_field_is_searched_and_the_name_stays_readable(): void {
		list( $item, $payload ) = SalesFixtures::order( '130' );
		$connection             = $this->connection( array( 'reference_field' => 'Woo_Order__c' ) );
		$this->connector->send( $connection, $item, $payload );
		$opp = $this->sf->all( 'Opportunity' )[0];
		$this->assertSame( 'WC-130', $opp['Woo_Order__c'] );
		$this->assertSame( 'Order 130', $opp['Name'] );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
	}

	public function test_products_mode_adds_one_line_per_product_with_the_gross_total_and_refuses_unknown_codes(): void {
		$this->sf->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		$connection             = $this->connection( array( 'lines' => 'products' ) );
		$lines                  = array( array( 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => '2', 'unit_price' => 2500, 'total' => 5000, 'tax' => 250 ) );
		list( $item, $payload ) = SalesFixtures::order( '131', array( 'lines' => $lines ) );
		$result                 = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->sf->all( 'OpportunityLineItem' )[0];
		$this->assertSame( 52.5, (float) $line['TotalPrice'] );
		$this->assertSame( 2, $line['Quantity'] );
		$this->assertSame( '01s000000000001', $this->sf->all( 'Opportunity' )[0]['Pricebook2Id'] );
		$this->assertSame( 5250, $result->ref->extra['total_minor'] );

		list( $item, $payload ) = SalesFixtures::order( '132', array( 'sku' => 'NO-SUCH' ) );
		$before                 = $this->sf->writes;
		$refused                = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( $before, $this->sf->writes );
		$skip = $this->connector->send( $this->connection( array( 'lines' => 'products', 'unknown_sku' => 'skip' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $skip->error->code, 'A skipped line leaves the Opportunity without its amount, which the total check refuses.' );
		$this->assertCount( 1, $this->sf->all( 'Opportunity' ), 'The Opportunity with wrong money was deleted.' );
	}

	public function test_shipping_becomes_a_line_only_when_a_product_is_named_for_it(): void {
		$this->sf->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		$this->sf->addProduct( 'SHIPPING', 'Shipping' );
		list( $item, $payload ) = SalesFixtures::order( '133', array( 'shipping' => 500 ) );
		$without                = $this->connector->send( $this->connection( array( 'lines' => 'products' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $without->error->code );
		$this->assertStringContainsString( '55.00', $without->error->context['reason'] );

		$with = $this->connector->send( $this->connection( array( 'lines' => 'products', 'shipping_product' => 'SHIPPING' ) ), $item, $payload );
		$this->assertTrue( $with->isSuccess(), (string) $with->error?->message() );
		$this->assertSame( 5500, $with->ref->extra['total_minor'] );
		$this->assertCount( 2, $this->sf->all( 'OpportunityLineItem' ) );
	}

	public function test_a_total_that_differs_is_refused_and_can_be_a_warning(): void {
		$this->sf->amountDrift  = 1.0;
		list( $item, $payload ) = SalesFixtures::order( '134' );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertCount( 0, $this->sf->all( 'Opportunity' ) );
		$warn = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $payload );
		$this->assertTrue( $warn->isSuccess() );
		$this->assertCount( 1, $warn->ref->extra['warnings'] );
	}

	public function test_contacts_are_reused_and_duplicate_rules_cannot_fail_an_order(): void {
		$this->sf->duplicateRules = true;
		list( $item, $payload )   = SalesFixtures::order( '135' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$headers = array_values( array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( $request['url'], 'sobjects/Contact' ) && 'POST' === $request['method'] ) );
		$this->assertSame( 'allowSave=true', $headers[0]['headers']['Sforce-Duplicate-Rule-Header'] );

		list( $item, $payload ) = SalesFixtures::order( '136', array( 'email' => 'DANA.example@example.test' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertCount( 1, $this->sf->all( 'Contact' ) );
	}

	public function test_quotes_in_values_are_escaped_in_soql(): void {
		list( $item, $payload ) = SalesFixtures::order( '137', array( 'company' => "O'Brien & Sons" ) );
		$this->connector->send( $this->connection(), $item, $payload );
		list( $item, $payload ) = SalesFixtures::order( '138', array( 'company' => "O'Brien & Sons" ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertCount( 1, $this->sf->all( 'Account' ), 'The account was found through an escaped literal.' );
		$this->assertSame( "O'Brien & Sons", $this->sf->all( 'Account' )[0]['Name'] );
		$queries = array_values( array_filter( $this->sf->calls, static fn( string $call ): bool => str_contains( $call, 'FROM+Account' ) || str_contains( $call, 'FROM%20Account' ) ) );
		$this->assertStringContainsString( rawurlencode( "O\\'Brien & Sons" ), $queries[0] );
	}

	public function test_a_refund_is_a_completed_task_on_the_opportunity_and_is_not_made_twice(): void {
		list( $order, $payload )   = SalesFixtures::order( '139' );
		$connection                = $this->connection();
		list( $refund, $rpayload ) = SalesFixtures::refund( '47', '139' );

		$this->assertSame( ErrorCode::Val002, $this->connector->send( $connection, $refund, $rpayload )->error->code );
		$this->connector->send( $connection, $order, $payload );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ) );
		$first  = $this->connector->send( $connection, $refund, $rpayload );
		$second = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->id );
		$task = $this->sf->all( 'Task' )[0];
		$this->assertSame( 'WC-47', $task['Subject'] );
		$this->assertSame( 'Completed', $task['Status'] );
		$this->assertSame( '2026-11-02', $task['ActivityDate'] );
		$this->assertStringContainsString( '12.00 EUR', $task['Description'] );
		$this->assertSame( $this->sf->all( 'Opportunity' )[0]['Id'], $task['WhatId'] );
	}

	public function test_a_customer_item_finds_or_creates_the_contact(): void {
		$item = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'k5', ItemState::Syncing );
		list( , $payload ) = SalesFixtures::order( '140' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->alreadyExisted );
		$this->assertCount( 1, $this->sf->all( 'Contact' ) );
	}

	public function test_test_connection_reports_version_org_rights_and_missing_currency_setting(): void {
		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'Salesforce API v66.0', $good->version );
		$this->assertSame( 'Example Trading LLC', $good->company );
		$this->assertFalse( $good->detected['multi_currency'] );
		$this->assertArrayNotHasKey( 'warning', $good->detected );
		$this->assertArrayHasKey( 'warning', $this->connector->test( $this->connection( array( 'org_currency' => '' ) ) )->detected );

		$this->sf->notCreateable = array( 'Task' );
		$bad                     = $this->connector->test( $this->connection() );
		$this->assertFalse( $bad->ok );
		$this->assertSame( array( 'create_task' ), $bad->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $bad->error->code );

		$this->sf->notCreateable = array();
		$old                     = $this->connector->test( $this->connection( array( 'api_version' => 'v60.0' ) ) );
		$this->assertFalse( $old->ok, 'v60.0 is not served by the fake org.' );
		$this->assertSame( 'api_version', $old->error->context['field'] );
	}

	public function test_the_domain_must_be_a_salesforce_address_and_is_normalised(): void {
		list( $item, $payload ) = SalesFixtures::order( '141' );
		$this->assertSame( 'domain', $this->connector->send( $this->connection( array( 'domain' => 'evil.example.org' ) ), $item, $payload )->error->context['field'] );
		$this->assertSame( 'domain', $this->connector->send( $this->connection( array( 'domain' => '' ) ), $item, $payload )->error->context['field'] );
		$this->assertSame( 0, count( $this->kit->http->requests ) );
		$this->connector->send( $this->connection( array( 'domain' => 'https://Example.my.salesforce.com/lightning/page' ) ), $item, $payload );
		$this->assertStringStartsWith( 'https://example.my.salesforce.com/services/data/v66.0/', $this->kit->http->requests[0]['url'] );
	}

	public function test_limits_and_edition_errors_map_to_waits_and_the_plan_message(): void {
		$this->sf->remaining    = 20;
		list( $item, $payload ) = SalesFixtures::order( '142' );
		$low                    = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $low->error->code );
		$this->assertSame( 3600, $low->error->retryAfter );
		$this->assertSame( 0, $this->sf->writes );

		$this->sf->remaining = null;
		$this->kit->http->route( 'GET', '~/query~', new HttpResponse( 403, '[{"message":"TotalRequests Limit exceeded.","errorCode":"REQUEST_LIMIT_EXCEEDED"}]', array() ) );
		$over = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $over->error->code );
		$this->assertSame( 3600, $over->error->retryAfter );

		$kit = ApiTestKit::make();
		$kit->http->fallback( static fn(): HttpResponse => new HttpResponse( 403, '[{"message":"API is not enabled for this Organization or Partner","errorCode":"API_DISABLED_FOR_ORG"}]', array() ) );
		$expires = (string) ( (int) $kit->clock->now()->format( 'U' ) + 3600 );
		$plain   = GuardedConnector::wrap( new SalesforceConnector( $kit->deps ) )->send( $kit->connection( 'salesforce', array( 'domain' => 'example.my.salesforce.com', 'client_id' => 'k' ), array( 'client_secret' => 's', 'oauth_access_token' => 'a', 'oauth_refresh_token' => 'r', 'oauth_expires_at' => $expires ) ), $item, $payload );
		$this->assertSame( ErrorCode::Auth006, $plain->error->code );
		$kit->cleanup();
	}

	public function test_the_token_is_refreshed_with_the_consumer_secret_in_the_body_and_a_refused_one_retries(): void {
		list( $item, $payload ) = SalesFixtures::order( '143' );
		$stale                  = $this->connection( array(), array( 'oauth_access_token' => 'expired-0005', 'oauth_expires_at' => '1' ) );
		$this->assertTrue( $this->connector->send( $stale, $item, $payload )->isSuccess() );
		$this->assertCount( 1, $this->sf->tokenRequests );
		$this->assertSame( 'refresh_token', $this->sf->tokenRequests[0]['grant_type'] );
		$this->assertSame( FakeSalesforce::SECRET, $this->sf->tokenRequests[0]['client_secret'] );
		$this->assertSame( '3MVG9SyntheticConsumerKey', $this->sf->tokenRequests[0]['client_id'] );

		list( $item, $payload ) = SalesFixtures::order( '144' );
		$this->assertTrue( $this->connector->send( $this->connection( array(), array( 'oauth_access_token' => 'revoked-0006' ) ), $item, $payload )->isSuccess() );
		$this->assertCount( 2, $this->sf->tokenRequests );
	}

	public function test_sign_in_settings_use_the_merchants_domain_pkce_and_optionally_the_certificate_flow(): void {
		$config = $this->inner->oauthProvider()->config( $this->connection() );
		$this->assertSame( 'https://example.my.salesforce.com/services/oauth2/authorize', $config->authorizeUrl );
		$this->assertSame( 'https://example.my.salesforce.com/services/oauth2/token', $config->tokenUrl );
		$this->assertSame( 'api refresh_token', $config->scopeString() );
		$this->assertTrue( $config->usePkce );
		$this->assertSame( 'authorization_code', $config->grant );
		$this->assertSame( array( 'example.my.salesforce.com' ), $config->allowedHosts );

		$jwt = $this->inner->oauthProvider()->config( $this->connection( array( 'auth_method' => 'jwt_bearer', 'jwt_username' => 'sync@example.test' ) ) );
		$this->assertSame( 'jwt_bearer', $jwt->grant );
		$this->assertSame( 'sync@example.test', $jwt->jwtSubject );
		$this->assertSame( 'https://example.my.salesforce.com', $jwt->jwtAudience );

		$this->assertSame( array( array( 'id' => '00D000000000001', 'name' => 'Example Trading LLC' ) ), $this->inner->organisations( $this->connection() ) );
	}

	public function test_reconciliation_follows_the_query_pages_and_lookups_escape_wildcards(): void {
		$this->sf->pageSize = 100;
		for ( $i = 1; $i <= 230; $i++ ) {
			$this->sf->addOpportunity( 'WC-' . ( 9000 + $i ), 10.0 );
		}
		$this->sf->addOpportunity( 'Website redesign', 999.0 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertCount( 230, $list );
		$this->assertSame( 1000, $list['WC-9001']->extra['total_minor'] );
		$this->assertNull( $list['WC-9001']->extra['refunded_minor'] );
		$this->assertSame( 2, $this->sf->countCalls( 'GET /services/data/v66.0/query/more-' ), 'Pages two and three follow nextRecordsUrl.' );
		$this->assertSame( array(), $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2035-01-01T00:00:00Z' ) ) );

		$this->sf->addProduct( 'CS-BOWL-02', 'Stoneware bowl set' );
		$this->sf->addProduct( 'X_100%', 'Odd code' );
		$connection = $this->connection();
		$this->assertSame( 'Stoneware bowl set', $this->connector->lookupProduct( $connection, 'cs-bowl-02' )->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$this->assertCount( 1, $this->connector->searchProducts( $connection, 'bowl' ) );
		$this->assertCount( 1, $this->connector->searchProducts( $connection, '100%' ), 'A percent sign is a character, not a wildcard.' );
		$this->assertCount( 1, $this->connector->searchProducts( $connection, '%' ), 'A lone percent sign matches only the product that has one.' );
		$this->assertCount( 0, $this->connector->searchProducts( $connection, 'Y_100' ), 'An underscore is a character too.' );
	}

	public function test_lookup_of_a_customer_and_exact_money_in_three_decimals(): void {
		$this->sf->multiCurrency = true;
		list( $item, $payload )  = SalesFixtures::order( '145', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$result                  = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 37.035, $this->sf->all( 'Opportunity' )[0]['Amount'] );
		$this->assertSame( 'Dana Example', $this->connector->lookupCustomer( $this->connection(), 'dana.example@example.test' )->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		foreach ( $this->kit->http->requests as $request ) {
			foreach ( array( FakeSalesforce::ACCESS, FakeSalesforce::REFRESH, FakeSalesforce::SECRET ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $request['url'] );
			}
		}
	}
}
