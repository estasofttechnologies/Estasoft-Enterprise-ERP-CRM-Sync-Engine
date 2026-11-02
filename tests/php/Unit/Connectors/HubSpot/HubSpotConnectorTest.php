<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\HubSpot;

use Estasoft\SyncEngine\Connectors\HubSpot\HubSpotConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHubSpot;
use Estasoft\SyncEngine\Tests\Support\SalesFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Order, refund, customer, lookup, reconciliation and error behaviour of the HubSpot connector.
 */
final class HubSpotConnectorTest extends TestCase {

	private ApiTestKit $kit;

	private FakeHubSpot $hub;

	private ConnectorInterface $connector;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
		$this->hub = new FakeHubSpot();
		$this->hub->install( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new HubSpotConnector( $this->kit->deps ) );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * @param array<string,mixed> $config Config and options.
	 */
	private function connection( array $config = array() ): Connection {
		return $this->kit->connection( 'hubspot', $config, array( 'access_token' => FakeHubSpot::KEY ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function only( string $object ): array {
		return array_values( array_filter( $this->hub->objects[ $object ], static fn( array $row ): bool => empty( $row['archived'] ) ) );
	}

	public function test_an_order_makes_a_contact_line_items_and_one_deal_with_every_link(): void {
		list( $item, $payload ) = SalesFixtures::order( '123', array( 'shipping' => 500 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 5500, $result->ref->extra['total_minor'] );
		$this->assertSame( 1, $this->hub->count( 'contacts' ) );
		$this->assertSame( 2, $this->hub->count( 'line_items' ), 'The product line and shipping.' );
		$deal = $this->only( 'deals' )[0];
		$this->assertSame( 'WC-123', $deal['properties']['dealname'] );
		$this->assertSame( '55.00', $deal['properties']['amount'] );
		$this->assertSame( 'EUR', $deal['properties']['deal_currency_code'] );
		$this->assertSame( '2026-11-01T00:00:00Z', $deal['properties']['closedate'] );
		$this->assertSame( 'default', $deal['properties']['pipeline'] );
		$this->assertSame( 'appointmentscheduled', $deal['properties']['dealstage'] );
		$this->assertCount( 1, $deal['assoc']['contacts'] );
		$this->assertCount( 2, $deal['assoc']['line_items'] );
		$this->assertStringStartsWith( "WC-123\n", $deal['properties']['description'] );
	}

	public function test_a_paid_order_goes_to_the_closed_won_stage_and_the_options_win(): void {
		list( $item, $payload ) = SalesFixtures::order( '124', array( 'paid' => true ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 'closedwon', $this->only( 'deals' )[0]['properties']['dealstage'] );

		list( $item, $payload ) = SalesFixtures::order( '125', array( 'paid' => true ) );
		$this->connector->send( $this->connection( array( 'pipeline' => 'p2', 'deal_stage' => 's1', 'deal_stage_paid' => 's9' ) ), $item, $payload );
		$this->assertSame( 's9', $this->only( 'deals' )[1]['properties']['dealstage'] );
		$this->assertSame( 'p2', $this->only( 'deals' )[1]['properties']['pipeline'] );
	}

	public function test_sending_twice_finds_the_deal_and_writes_nothing(): void {
		list( $item, $payload ) = SalesFixtures::order( '126' );
		$connection             = $this->connection();
		$first                  = $this->connector->send( $connection, $item, $payload );
		$writes                 = $this->hub->writes;
		$second                 = $this->connector->send( $connection, $item, $payload );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->hub->writes );
		$this->assertSame( 1, $this->hub->count( 'deals' ) );
	}

	public function test_a_crash_before_the_deal_leaves_no_half_deal_and_the_retry_completes(): void {
		list( $item, $payload ) = SalesFixtures::order( '127' );
		$connection             = $this->connection();
		$this->hub->failNextWrite( 'server_error', 0, 2 );
		$first = $this->connector->send( $connection, $item, $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 0, $this->hub->count( 'deals' ), 'Contact and line item exist, the deal does not.' );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'An unfinished order is not reported as found.' );

		$retry = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertSame( 1, $this->hub->count( 'contacts' ), 'The contact was found, not made twice.' );
		$this->assertSame( 1, $this->hub->count( 'deals' ) );
	}

	public function test_a_property_the_merchant_made_is_searched_first_and_the_name_carries_the_reference_too(): void {
		list( $item, $payload ) = SalesFixtures::order( '128' );
		$connection             = $this->connection( array( 'reference_property' => 'woo_order' ) );
		$this->connector->send( $connection, $item, $payload );
		$deal = $this->only( 'deals' )[0];

		$this->assertSame( 'WC-128', $deal['properties']['woo_order'] );
		$this->assertSame( 'WC-128', $deal['properties']['dealname'], 'The name is the documented fallback key.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $item ) );

		$this->hub->objects['deals'][ $deal['id'] ]['properties']['dealname'] = 'Mugs for Dana';
		$this->assertTrue( $this->connector->send( $connection, $item, $payload )->alreadyExisted, 'A renamed deal is still found.' );
		$this->assertSame( 1, $this->hub->count( 'deals' ) );
	}

	public function test_the_reference_is_matched_without_regard_to_case_and_the_oldest_deal_wins(): void {
		$older = $this->hub->addDeal( 'wc-130', '10.00' );
		$this->hub->addDeal( 'WC-130', '10.00' );
		list( $item )  = SalesFixtures::order( '130' );
		$found         = $this->connector->findExisting( $this->connection(), $item );
		$this->assertSame( $older, $found->id );
	}

	public function test_an_amount_that_hubspot_recalculates_is_set_back_once(): void {
		$this->hub->recalculate = true;
		list( $item, $payload ) = SalesFixtures::order( '131', array( 'tax' => 500, 'unit_minor' => 2500, 'quantity' => 2 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5500, $result->ref->extra['total_minor'] );
		$this->assertSame( '55.00', $this->only( 'deals' )[0]['properties']['amount'] );
		$this->assertSame( 1, $this->hub->countCalls( 'PATCH /crm/objects/2026-03/deals' ) );
	}

	public function test_a_total_hubspot_will_not_keep_is_refused_and_the_deal_is_archived(): void {
		$this->hub->recalculate = true;
		$this->hub->lockAmount  = true;
		list( $item, $payload ) = SalesFixtures::order( '132', array( 'tax' => 500 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertStringContainsString( '55.00', $result->error->context['reason'] );
		$this->assertSame( 0, $this->hub->count( 'deals' ), 'The deal with wrong money does not stay.' );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $item ) );
	}

	public function test_the_total_check_can_be_a_warning(): void {
		$this->hub->recalculate = true;
		$this->hub->lockAmount  = true;
		list( $item, $payload ) = SalesFixtures::order( '133', array( 'tax' => 500 ) );
		$result                 = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $payload );

		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
		$this->assertSame( 1, $this->hub->count( 'deals' ) );
	}

	public function test_a_currency_hubspot_does_not_accept_is_named_in_the_error(): void {
		list( $item, $payload ) = SalesFixtures::order( '134', array( 'currency' => 'KWD' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'deal_currency_code', $result->error->context['field'] );
		$this->assertStringContainsString( 'KWD', $result->error->context['reason'] );
	}

	public function test_product_matching_links_known_skus_and_refuses_unknown_ones_before_anything_is_made(): void {
		$productId              = $this->hub->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		list( $item, $payload ) = SalesFixtures::order( '135' );
		$on                     = $this->connection( array( 'product_matching' => 'on' ) );
		$this->assertTrue( $this->connector->send( $on, $item, $payload )->isSuccess() );
		$this->assertSame( $productId, $this->only( 'line_items' )[0]['properties']['hs_product_id'] );

		list( $item, $payload ) = SalesFixtures::order( '136', array( 'sku' => 'NO-SUCH' ) );
		$before                 = $this->hub->writes;
		$result                 = $this->connector->send( $on, $item, $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'NO-SUCH', $result->error->context['sku'] );
		$this->assertSame( $before, $this->hub->writes );

		$free = $this->connection( array( 'product_matching' => 'on', 'unknown_sku' => 'free_text' ) );
		$this->assertTrue( $this->connector->send( $free, $item, $payload )->isSuccess() );
	}

	public function test_lines_that_do_not_multiply_out_are_sent_as_one_unit_with_the_line_total(): void {
		$lines                  = array( array( 'sku' => 'A', 'name' => 'Cloth', 'quantity' => '1.5', 'unit_price' => 1000, 'total' => 1499, 'tax' => 0 ) );
		list( $item, $payload ) = SalesFixtures::order( '137', array( 'lines' => $lines ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$line = $this->only( 'line_items' )[0]['properties'];
		$this->assertSame( '14.99', $line['price'] );
		$this->assertSame( '1', $line['quantity'] );
		$this->assertSame( 'Cloth x 1.5', $line['name'] );
	}

	public function test_bad_payloads_stop_before_any_request(): void {
		list( $item, $payload ) = SalesFixtures::order( '138', array( 'email' => '' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertSame( '138', $result->error->context['order'] );

		list( $item, $payload ) = SalesFixtures::order( '139' );
		unset( $payload['currency'] );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection(), $item, $payload )->error->code );

		list( $item, $payload ) = SalesFixtures::order( '140' );
		$payload['lines']       = array();
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection(), $item, $payload )->error->code );
		$this->assertSame( array(), $this->hub->calls );
	}

	public function test_a_refund_is_a_note_on_the_deal_and_is_not_made_twice(): void {
		list( $order, $payload )  = SalesFixtures::order( '141' );
		$connection               = $this->connection();
		list( $refund, $rpayload ) = SalesFixtures::refund( '45', '141' );

		$early = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertSame( ErrorCode::Val002, $early->error->code, 'The order must be in HubSpot first.' );

		$this->connector->send( $connection, $order, $payload );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ) );
		$first = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$second = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->id );
		$this->assertSame( 1, $this->hub->count( 'notes' ) );
		$note = $this->only( 'notes' )[0];
		$this->assertStringStartsWith( "WC-45\n", $note['properties']['hs_note_body'] );
		$this->assertStringContainsString( '12.00 EUR', $note['properties']['hs_note_body'] );
		$this->assertCount( 1, $note['assoc']['deals'] );
	}

	public function test_a_customer_item_finds_or_creates_the_contact(): void {
		$item = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'k5', ItemState::Syncing );
		list( , $payload ) = SalesFixtures::order( '142' );
		$first  = $this->connector->send( $this->connection(), $item, $payload );
		$second = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( 1, $this->hub->count( 'contacts' ) );
	}

	public function test_a_contact_that_exists_on_the_409_answer_is_used(): void {
		$this->hub->objects['contacts']['7001'] = array( 'id' => '7001', 'properties' => array( 'email' => 'Dana.Example@example.test', 'createdate' => '2026-01-01T00:00:00.000Z' ), 'assoc' => array(), 'archived' => false );
		list( $item, $payload ) = SalesFixtures::order( '143' );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 1, $this->hub->count( 'contacts' ), 'Found by the case-insensitive search, never created.' );
		$this->assertSame( array( '7001' ), $this->only( 'deals' )[0]['assoc']['contacts'] );
	}

	public function test_test_connection_reports_the_scopes_the_key_lacks(): void {
		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'HubSpot API 2026-03', $good->version );
		$this->assertSame( 'HubSpot 1234567', $good->company );
		$this->assertSame( 1, $good->detected['pipelines'] );

		$this->hub->deniedObjects = array( 'notes' );
		$bad                      = $this->connector->test( $this->connection() );
		$this->assertFalse( $bad->ok );
		$this->assertSame( array( 'read_notes' ), $bad->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $bad->error->code );

		$this->hub->deniedObjects = array();
		$wrong                    = $this->connector->test( $this->connection( array( 'pipeline' => 'nope' ) ) );
		$this->assertFalse( $wrong->ok );
		$this->assertSame( 'pipeline', $wrong->error->context['field'] );
	}

	public function test_a_denied_scope_names_itself_in_the_error(): void {
		$this->hub->deniedObjects = array( 'contacts' );
		list( $item, $payload )   = SalesFixtures::order( '144' );
		$result                   = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringContainsString( 'crm.objects.contacts.read', (string) $result->error->context['action'] );
	}

	public function test_a_burst_limit_without_retry_after_waits_ten_seconds_and_a_daily_one_an_hour(): void {
		list( $item, $payload ) = SalesFixtures::order( '146' );
		$this->kit->http->route( 'POST', '~/deals/search~', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'status' => 'error', 'errorType' => 'RATE_LIMIT', 'policyName' => 'DAILY' ), 429 ) );
		$daily = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 3600, $daily->error->retryAfter );
	}

	public function test_a_locked_answer_is_retried_and_other_fields_are_named(): void {
		list( $item, $payload ) = SalesFixtures::order( '147' );
		$this->kit->http->route( 'POST', '~/deals/search~', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'status' => 'error' ), 423 ) );
		$locked = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Net002, $locked->error->code );
	}

	public function test_reconciliation_lists_order_deals_since_a_date_across_pages(): void {
		for ( $i = 1; $i <= 130; $i++ ) {
			$this->hub->addDeal( 'WC-' . ( 5000 + $i ), '10.00' );
		}
		$this->hub->addDeal( 'Website redesign', '999.00' );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertIsArray( $list );
		$this->assertCount( 130, $list );
		$this->assertSame( 1000, $list['WC-5001']->extra['total_minor'] );
		$this->assertArrayHasKey( 'refunded_minor', $list['WC-5001']->extra );
		$this->assertNull( $list['WC-5001']->extra['refunded_minor'] );
		$this->assertArrayNotHasKey( 'Website redesign', $list );
		$this->assertGreaterThan( 1, $this->hub->countCalls( 'POST /crm/objects/2026-03/deals/search' ) );

		$none = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2030-01-01T00:00:00Z' ) );
		$this->assertSame( array(), $none );
	}

	public function test_lookups_find_contacts_and_products_and_search_by_text(): void {
		$this->hub->addProduct( 'CS-BOWL-02', 'Stoneware bowl set' );
		$connection = $this->connection();
		list( $item, $payload ) = SalesFixtures::order( '148' );
		$this->connector->send( $connection, $item, $payload );

		$customer = $this->connector->lookupCustomer( $connection, 'DANA.EXAMPLE@example.test' );
		$this->assertSame( 'Dana Example', $customer->ref );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
		$this->assertSame( 'Stoneware bowl set', $this->connector->lookupProduct( $connection, 'cs-bowl-02' )->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$found = $this->connector->searchProducts( $connection, 'bowl' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-BOWL-02', $found[0]->ref );
	}

	public function test_the_money_is_exact_in_three_decimal_currencies_and_whole_unit_currencies(): void {
		$this->hub->currencies[] = 'KWD';
		$this->hub->currencies[] = 'JPY';
		list( $item, $payload )  = SalesFixtures::order( '149', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$result                  = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( '37.035', $this->only( 'deals' )[0]['properties']['amount'] );

		list( $item, $payload ) = SalesFixtures::order( '150', array( 'currency' => 'JPY', 'unit_minor' => 1999, 'quantity' => 3 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
		$this->assertSame( '5997', $this->only( 'deals' )[1]['properties']['amount'] );
	}

	public function test_the_key_is_never_in_the_requests_url_or_the_error_text(): void {
		list( $item, $payload ) = SalesFixtures::order( '151' );
		$this->hub->failNextWrite( 'unknown_fault' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakeHubSpot::KEY, $request['url'] );
			$this->assertSame( 'Bearer ' . FakeHubSpot::KEY, $request['headers']['Authorization'] );
		}
		$this->assertInstanceOf( ConnectorError::class, $result->error );
		$this->assertStringNotContainsString( FakeHubSpot::KEY, $result->error->message() . $result->error->detail );
	}

	public function test_a_deal_that_cannot_be_made_leaves_none_of_its_line_items_behind(): void {
		list( $item, $payload ) = SalesFixtures::order( '160', array( 'shipping' => 500 ) );
		$this->hub->failNextWrite( 'server_error', 0, 3 );
		$failed = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertFalse( $failed->isSuccess(), 'The deal create was the fourth write: contact and two line items came first.' );
		$this->assertSame( 0, $this->hub->count( 'deals' ) );
		$this->assertSame( 0, $this->hub->count( 'line_items' ), 'Both line items were archived again.' );
		$this->assertSame( 1, $this->hub->count( 'contacts' ), 'The contact is a real record and stays.' );

		$retry = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertSame( 2, $this->hub->count( 'line_items' ) );
	}

	public function test_a_line_item_that_cannot_be_made_takes_the_ones_made_before_it_away(): void {
		list( $item, $payload ) = SalesFixtures::order( '161', array( 'shipping' => 500 ) );
		$this->hub->failNextWrite( 'server_error', 0, 2 );
		$failed = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertFalse( $failed->isSuccess() );
		$this->assertSame( 0, $this->hub->count( 'line_items' ) );
		$this->assertSame( 0, $this->hub->count( 'deals' ) );
	}

	public function test_a_deal_archived_for_wrong_money_takes_its_line_items_with_it(): void {
		$this->hub->recalculate = true;
		$this->hub->lockAmount  = true;
		list( $item, $payload ) = SalesFixtures::order( '162', array( 'tax' => 500, 'shipping' => 500 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->hub->count( 'deals' ) );
		$this->assertSame( 0, $this->hub->count( 'line_items' ) );
	}

	public function test_the_close_date_goes_out_as_an_iso_utc_midnight_which_hubspot_accepts_for_a_datetime(): void {
		list( $item, $payload ) = SalesFixtures::order( '163' );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( '2026-11-01T00:00:00Z', $this->only( 'deals' )[0]['properties']['closedate'] );
	}

	public function test_test_connection_makes_the_reference_property_once_and_every_deal_carries_it_beside_the_name(): void {
		$this->hub->strictProperties = true;
		$first                       = $this->connector->test( $this->connection() );
		$this->assertTrue( $first->ok, (string) $first->error?->message() );
		$this->assertTrue( $first->permissions['create_reference_field'] );
		$this->assertSame( 'estasoft_order_ref', $first->detected['reference_field'] );
		$this->assertSame( 'field', $first->detected['reference_mode'] );
		$this->assertArrayNotHasKey( 'warning', $first->detected );
		$definition = $this->hub->properties['estasoft_order_ref'];
		$this->assertSame( 'string', $definition['type'] );
		$this->assertSame( 'text', $definition['fieldType'] );
		$this->assertTrue( $definition['hasUniqueValue'] );

		$this->connector->test( $this->connection() );
		$this->assertSame( 1, $this->hub->countCalls( 'POST /crm/properties/' ), 'The second test finds it.' );

		list( $item, $payload ) = SalesFixtures::order( '164' );
		$sent                   = $this->connector->send( $this->connection( array( 'detected' => $first->detected ) ), $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$deal = $this->only( 'deals' )[0];
		$this->assertSame( 'WC-164', $deal['properties']['estasoft_order_ref'] );
		$this->assertSame( 'WC-164', $deal['properties']['dealname'] );
	}

	public function test_a_rep_who_renames_the_deal_does_not_break_replays_refunds_or_reconciliation(): void {
		$this->hub->strictProperties = true;
		$connection                  = $this->connection( array( 'detected' => $this->connector->test( $this->connection() )->detected ) );
		list( $order, $payload )     = SalesFixtures::order( '165' );
		$first                       = $this->connector->send( $connection, $order, $payload );
		$this->hub->objects['deals'][ $first->ref->id ]['properties']['dealname'] = 'Dana - big mug order';

		$replay = $this->connector->send( $connection, $order, $payload );
		$this->assertTrue( $replay->alreadyExisted );
		$this->assertSame( 1, $this->hub->count( 'deals' ), 'No second deal.' );

		list( $refund, $rpayload ) = SalesFixtures::refund( '56', '165' );
		$this->assertTrue( $this->connector->send( $connection, $refund, $rpayload )->isSuccess() );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->extra['deal_id'] );

		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );
		$this->assertArrayHasKey( 'WC-165', $list );
		$this->assertSame( 5000, $list['WC-165']->extra['total_minor'] );
	}

	public function test_a_deal_made_before_the_property_existed_is_still_found_by_its_name(): void {
		$legacy                 = $this->hub->addDeal( 'WC-166', '50.00' );
		$connection             = $this->connection( array( 'detected' => array( 'reference_field' => 'estasoft_order_ref' ) ) );
		list( $item, $payload ) = SalesFixtures::order( '166' );
		$result                 = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( $legacy, $result->ref->id );
		$this->assertSame( 1, $this->hub->count( 'deals' ) );
		$this->assertArrayHasKey( 'WC-166', $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) ) );
	}

	public function test_another_worker_that_makes_the_same_deal_first_is_refused_by_the_unique_property_and_its_deal_is_used(): void {
		$this->hub->strictProperties = true;
		$detected                    = $this->connector->test( $this->connection() )->detected;
		$connection                  = $this->connection( array( 'detected' => $detected ) );
		$this->hub->raceReference    = 'WC-167';
		list( $item, $payload )      = SalesFixtures::order( '167' );
		$result                      = $this->connector->send( $connection, $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 1, $this->hub->count( 'deals' ), 'One deal, not two.' );
		$this->assertSame( 0, $this->hub->count( 'line_items' ), 'The line items made for the losing attempt are gone.' );
	}

	public function test_when_the_property_cannot_be_made_the_name_stays_the_key_and_the_test_says_so(): void {
		$this->hub->strictProperties  = true;
		$this->hub->schemaWriteDenied = true;
		$test                         = $this->connector->test( $this->connection() );
		$this->assertTrue( $test->ok, 'Orders still work; only the safer key is missing.' );
		$this->assertFalse( $test->permissions['create_reference_field'] );
		$this->assertSame( array( 'create_reference_field' ), $test->missingPermissions() );
		$this->assertSame( 'name', $test->detected['reference_mode'] );
		$this->assertSame( 'reference_field', $test->detected['warning'] );
		$this->assertArrayNotHasKey( 'reference_field', $test->detected );

		list( $item, $payload ) = SalesFixtures::order( '168' );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'detected' => $test->detected ) ), $item, $payload )->isSuccess(), 'Nothing is written to a property that does not exist.' );

		$this->hub->schemaWriteDenied = false;
		$this->hub->schemaReadDenied  = true;
		$creates                      = $this->hub->countCalls( 'POST /crm/properties/' );
		$unreadable                   = $this->connector->test( $this->connection() );
		$this->assertTrue( $unreadable->ok );
		$this->assertFalse( $unreadable->permissions['create_reference_field'], 'Without the schema scope the property cannot even be looked for.' );
		$this->assertSame( $creates, $this->hub->countCalls( 'POST /crm/properties/' ), 'No create is tried blind.' );
	}

	public function test_naming_dealname_on_purpose_creates_nothing_and_warns_about_nothing(): void {
		$test = $this->connector->test( $this->connection( array( 'reference_property' => 'dealname' ) ) );
		$this->assertTrue( $test->ok );
		$this->assertSame( 0, $this->hub->countCalls( 'POST /crm/properties/' ) );
		$this->assertArrayNotHasKey( 'create_reference_field', $test->permissions );
		$this->assertArrayNotHasKey( 'warning', $test->detected );
		$this->assertSame( 'name', $test->detected['reference_mode'] );
	}

	public function test_a_low_header_on_an_answer_does_not_stop_the_connector_because_no_header_is_trusted(): void {
		list( $item, $payload ) = SalesFixtures::order( '169' );
		$this->kit->http->route( 'POST', '~/line_items$~', new HttpResponse( 201, '{"id":"900","properties":{}}', array( 'X-HubSpot-RateLimit-Daily-Remaining' => '1' ) ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertNotSame( ErrorCode::Rate001, $result->error?->code );
	}

	public function test_ping_is_one_cheap_call_and_false_for_a_refused_key(): void {
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
		$this->assertSame( 1, count( $this->hub->calls ) );
		$this->assertStringStartsWith( 'GET /crm/objects/2026-03/deals', $this->hub->calls[0] );

		$bad = $this->kit->connection( 'hubspot', array(), array( 'access_token' => 'wrong-key-0002' ), 2 );
		$this->assertFalse( $this->connector->ping( $bad ) );
	}
}
