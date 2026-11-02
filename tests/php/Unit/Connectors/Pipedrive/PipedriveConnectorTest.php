<?php

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Pipedrive;

use Estasoft\SyncEngine\Connectors\Pipedrive\PipedriveConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakePipedrive;
use Estasoft\SyncEngine\Tests\Support\SalesFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Order, refund, customer, lookup, reconciliation and error behaviour of the Pipedrive connector.
 */
final class PipedriveConnectorTest extends TestCase {

	private ApiTestKit $kit;

	private FakePipedrive $pd;

	private ConnectorInterface $connector;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
		$this->pd  = new FakePipedrive();
		$this->pd->install( $this->kit->http );
		$this->connector = GuardedConnector::wrap( new PipedriveConnector( $this->kit->deps ) );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	/**
	 * @param array<string,mixed> $config Config and options.
	 */
	private function connection( array $config = array() ): Connection {
		return $this->kit->connection( 'pipedrive', $config + array( 'company_domain' => 'example' ), array( 'api_token' => FakePipedrive::KEY ) );
	}

	public function test_an_order_makes_a_person_an_organization_a_deal_and_the_summary_note_last(): void {
		list( $item, $payload ) = SalesFixtures::order( '123', array( 'company' => 'Example Trading LLC', 'note' => 'Leave at the gate' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertSame( 5000, $result->ref->extra['total_minor'] );
		$this->assertSame( 'https://example.pipedrive.com/deal/' . $result->ref->id, $result->ref->url );
		$deal = $this->pd->live( 'deals' )[0];
		$this->assertSame( 'WC-123', $deal['title'] );
		$this->assertSame( 50.0, (float) $deal['value'] );
		$this->assertSame( 'EUR', $deal['currency'] );
		$this->assertSame( '2026-11-01T00:00:00Z', $deal['add_time'] );
		$this->assertSame( 'open', $deal['status'] );
		$this->assertCount( 1, $this->pd->live( 'persons' ) );
		$this->assertSame( 'dana.example@example.test', $this->pd->live( 'persons' )[0]['emails'][0]['value'] );
		$this->assertCount( 1, $this->pd->live( 'organizations' ) );
		$this->assertSame( $this->pd->live( 'organizations' )[0]['id'], $deal['org_id'] );
		$note = $this->pd->live( 'notes' )[0];
		$this->assertStringStartsWith( 'WC-123<br>', $note['content'] );
		$this->assertStringContainsString( 'Leave at the gate', $note['content'] );
		$this->assertSame( 'POST /api/v1/notes', end( $this->pd->calls ) );
	}

	public function test_sending_twice_writes_nothing_the_second_time(): void {
		list( $item, $payload ) = SalesFixtures::order( '124' );
		$connection             = $this->connection();
		$first                  = $this->connector->send( $connection, $item, $payload );
		$writes                 = $this->pd->writes;
		$second                 = $this->connector->send( $connection, $item, $payload );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $writes, $this->pd->writes );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $item )->id );
	}

	public function test_a_deal_without_its_note_is_unfinished_and_the_retry_completes_it(): void {
		list( $item, $payload ) = SalesFixtures::order( '125' );
		$connection             = $this->connection();
		$this->pd->failNextWrite( 'server_error', 0, 2 );
		$first = $this->connector->send( $connection, $item, $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->pd->live( 'deals' ), 'The deal was made, the note was not.' );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'An unfinished deal is not reported as found.' );

		$retry = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess() );
		$this->assertTrue( $retry->alreadyExisted, 'The deal was found and finished.' );
		$this->assertCount( 1, $this->pd->live( 'deals' ) );
		$this->assertCount( 1, $this->pd->live( 'persons' ) );
		$this->assertCount( 1, $this->pd->live( 'notes' ) );
		$this->assertSame( $retry->ref->id, $this->connector->findExisting( $connection, $item )->id );
	}

	public function test_an_existing_person_and_organization_are_reused_by_email_and_name(): void {
		$this->pd->rows['persons']['7'] = array( 'id' => 7, 'name' => 'Dana Old', 'emails' => array( array( 'value' => 'DANA.example@example.test', 'primary' => true ) ), 'deleted' => false, 'update_time' => '2026-01-01T00:00:00Z' );
		$this->pd->rows['organizations']['8'] = array( 'id' => 8, 'name' => 'example trading llc', 'deleted' => false, 'update_time' => '2026-01-01T00:00:00Z' );
		list( $item, $payload ) = SalesFixtures::order( '126', array( 'company' => 'Example Trading LLC' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$deal = $this->pd->live( 'deals' )[0];
		$this->assertSame( 7, $deal['person_id'] );
		$this->assertSame( 8, $deal['org_id'] );
		$this->assertCount( 1, $this->pd->live( 'persons' ) );
		$this->assertCount( 1, $this->pd->live( 'organizations' ) );
	}

	public function test_the_pipeline_and_stage_options_are_sent_and_a_paid_order_can_be_won(): void {
		list( $item, $payload ) = SalesFixtures::order( '127', array( 'paid' => true ) );
		$this->connector->send( $this->connection( array( 'pipeline_id' => '3', 'stage_id' => '12', 'mark_won' => 'yes' ) ), $item, $payload );
		$deal = $this->pd->live( 'deals' )[0];
		$this->assertSame( 3, $deal['pipeline_id'] );
		$this->assertSame( 12, $deal['stage_id'] );
		$this->assertSame( 'won', $deal['status'] );

		list( $item, $payload ) = SalesFixtures::order( '128' );
		$this->connector->send( $this->connection( array( 'mark_won' => 'yes' ) ), $item, $payload );
		$this->assertSame( 'open', $this->pd->live( 'deals' )[1]['status'], 'An unpaid order stays open.' );
	}

	public function test_products_mode_attaches_known_products_creates_unknown_ones_on_request_and_refuses_otherwise(): void {
		$mug = $this->pd->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		list( $item, $payload ) = SalesFixtures::order( '129' );
		$result = $this->connector->send( $this->connection( array( 'lines' => 'products' ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$row = $this->pd->dealProducts[ $result->ref->id ][0];
		$this->assertSame( (int) $mug, $row['product_id'] );
		$this->assertSame( 25.0, (float) $row['item_price'] );
		$this->assertSame( 2, $row['quantity'] );

		list( $item, $payload ) = SalesFixtures::order( '130', array( 'sku' => 'NEW-SKU' ) );
		$before                 = $this->pd->writes;
		$refused                = $this->connector->send( $this->connection( array( 'lines' => 'products' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( $before, $this->pd->writes, 'Nothing is made for an order that will be refused.' );

		$created = $this->connector->send( $this->connection( array( 'lines' => 'products', 'unknown_sku' => 'create' ) ), $item, $payload );
		$this->assertTrue( $created->isSuccess() );
		$this->assertSame( 'NEW-SKU', end( $this->pd->rows['products'] )['code'] );
	}

	public function test_a_deal_value_that_follows_the_products_is_set_back_and_a_locked_one_is_refused(): void {
		$this->pd->addProduct( 'CS-MUG-01', 'Ceramic mug, sand' );
		$this->pd->valueFromProducts = true;
		list( $item, $payload )      = SalesFixtures::order( '131', array( 'tax' => 500 ) );
		$result                      = $this->connector->send( $this->connection( array( 'lines' => 'products' ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5500, $result->ref->extra['total_minor'] );
		$this->assertSame( 55.0, (float) $this->pd->live( 'deals' )[0]['value'] );

		$this->pd->lockValue    = true;
		list( $item, $payload ) = SalesFixtures::order( '132', array( 'tax' => 500 ) );
		$locked                 = $this->connector->send( $this->connection( array( 'lines' => 'products' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $locked->error->code );
		$this->assertSame( 'total', $locked->error->context['field'] );
		$this->assertCount( 1, $this->pd->live( 'deals' ), 'The deal with wrong money was deleted.' );
	}

	public function test_a_currency_pipedrive_refuses_is_named_in_the_error(): void {
		list( $item, $payload ) = SalesFixtures::order( '133', array( 'currency' => 'KWD' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'KWD', $result->error->context['reason'] );
		$this->assertCount( 0, $this->pd->live( 'deals' ) );
	}

	public function test_bad_payloads_stop_before_any_request(): void {
		list( $item, $payload ) = SalesFixtures::order( '134', array( 'email' => 'not-an-email' ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		list( $item, $payload ) = SalesFixtures::order( '135' );
		$payload['total']       = 'abc';
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection(), $item, $payload )->error->code );
		$this->assertSame( array(), $this->pd->calls );
	}

	public function test_a_refund_is_a_note_on_the_deal_and_is_not_made_twice(): void {
		list( $order, $payload )   = SalesFixtures::order( '136' );
		$connection                = $this->connection();
		list( $refund, $rpayload ) = SalesFixtures::refund( '46', '136' );

		$this->assertSame( ErrorCode::Val002, $this->connector->send( $connection, $refund, $rpayload )->error->code );
		$this->connector->send( $connection, $order, $payload );
		$this->assertNull( $this->connector->findExisting( $connection, $refund ) );
		$first  = $this->connector->send( $connection, $refund, $rpayload );
		$second = $this->connector->send( $connection, $refund, $rpayload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $this->connector->findExisting( $connection, $refund )->id );
		$this->assertCount( 2, $this->pd->live( 'notes' ), 'The order note and one refund note.' );
		$this->assertStringContainsString( '12.00 EUR', end( $this->pd->rows['notes'] )['content'] );
	}

	public function test_a_customer_item_finds_or_creates_the_person(): void {
		$item = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'k5', ItemState::Syncing );
		list( , $payload ) = SalesFixtures::order( '137' );
		$first  = $this->connector->send( $this->connection(), $item, $payload );
		$second = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->pd->live( 'persons' ) );
	}

	public function test_the_company_address_is_checked_and_normalised(): void {
		list( $item, $payload ) = SalesFixtures::order( '138' );
		$this->connector->send( $this->connection( array( 'company_domain' => 'https://Example.pipedrive.com/' ) ), $item, $payload );
		$this->assertStringStartsWith( 'https://example.pipedrive.com/', $this->kit->http->requests[0]['url'] );

		$this->assertSame( 'company_domain', $this->connector->send( $this->connection( array( 'company_domain' => 'evil.example.org' ) ), $item, $payload )->error->context['field'] );
		$this->assertSame( 'company_domain', $this->connector->send( $this->connection( array( 'company_domain' => '' ) ), $item, $payload )->error->context['field'] );
	}

	public function test_test_connection_names_the_company_and_the_missing_access(): void {
		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'Example Trading LLC', $good->company );
		$this->assertSame( 'Sync User', $good->detected['user'] );

		$this->pd->denied = array( 'deals' );
		$bad              = $this->connector->test( $this->connection() );
		$this->assertFalse( $bad->ok );
		$this->assertSame( array( 'read_deals' ), $bad->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $bad->error->code );
	}

	public function test_rate_limits_without_retry_after_wait_by_the_headers_and_cloudflare_blocks_wait_long(): void {
		list( $item, $payload ) = SalesFixtures::order( '139' );
		$this->kit->http->route( 'GET', '~/itemSearch~', new HttpResponse( 429, '{"success":false}', array( 'x-ratelimit-remaining' => '0' ) ) );
		$burst = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $burst->error->code );
		$this->assertSame( 5, $burst->error->retryAfter );

		$kit = ApiTestKit::make();
		$kit->http->route( 'GET', '~/itemSearch~', new HttpResponse( 429, '{"success":false}', array( 'x-ratelimit-remaining' => '12' ) ) );
		$daily = GuardedConnector::wrap( new PipedriveConnector( $kit->deps ) )->send( $kit->connection( 'pipedrive', array( 'company_domain' => 'example' ), array( 'api_token' => FakePipedrive::KEY ) ), $item, $payload );
		$this->assertSame( 900, $daily->error->retryAfter );

		$block = ApiTestKit::make();
		$block->http->route( 'GET', '~/itemSearch~', new HttpResponse( 403, '<html><body>Access denied</body></html>', array() ) );
		$blocked = GuardedConnector::wrap( new PipedriveConnector( $block->deps ) )->send( $block->connection( 'pipedrive', array( 'company_domain' => 'example' ), array( 'api_token' => FakePipedrive::KEY ) ), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $blocked->error->code, 'A Cloudflare page is a rate block, not a permission problem.' );
		$this->assertSame( 900, $blocked->error->retryAfter );
		$kit->cleanup();
		$block->cleanup();
	}

	public function test_reconciliation_reads_updated_deals_across_cursor_pages(): void {
		for ( $i = 1; $i <= 520; $i++ ) {
			$this->pd->addDeal( 'WC-' . ( 7000 + $i ), 10.0 );
		}
		$this->pd->addDeal( 'Website redesign', 999.0 );
		$list = $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		$this->assertCount( 520, $list );
		$this->assertSame( 1000, $list['WC-7001']->extra['total_minor'] );
		$this->assertNull( $list['WC-7001']->extra['refunded_minor'] );
		$this->assertSame( 2, $this->pd->countCalls( 'GET /api/v2/deals?' ), 'Two cursor pages.' );
		$this->assertSame( array(), $this->connector->listForReconcile( $this->connection(), new \DateTimeImmutable( '2035-01-01T00:00:00Z' ) ) );
	}

	public function test_lookups_find_persons_and_products_and_search_by_text(): void {
		$this->pd->addProduct( 'CS-BOWL-02', 'Stoneware bowl set' );
		$connection             = $this->connection();
		list( $item, $payload ) = SalesFixtures::order( '140' );
		$this->connector->send( $connection, $item, $payload );

		$this->assertSame( 'Dana Example', $this->connector->lookupCustomer( $connection, 'Dana.Example@example.test' )->ref );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
		$this->assertSame( 'Stoneware bowl set', $this->connector->lookupProduct( $connection, 'cs-bowl-02' )->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$found = $this->connector->searchProducts( $connection, 'bowl' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-BOWL-02', $found[0]->ref );
	}

	public function test_the_money_is_exact_and_the_token_never_leaves_the_header(): void {
		$this->pd->currencies[] = 'KWD';
		list( $item, $payload ) = SalesFixtures::order( '141', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$result                 = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 37.035, $this->pd->live( 'deals' )[0]['value'] );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakePipedrive::KEY, $request['url'] );
			$this->assertSame( FakePipedrive::KEY, $request['headers']['x-api-token'] );
		}
	}
}
