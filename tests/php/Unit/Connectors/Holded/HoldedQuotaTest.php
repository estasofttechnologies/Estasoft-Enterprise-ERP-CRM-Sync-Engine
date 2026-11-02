<?php
/**
 * The call quota of a Holded plan: list reads stop before it runs out, and the stop is remembered.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class HoldedQuotaTest extends HoldedTestCase {

	/**
	 * Many products, one per page, so a SKU at the end needs many reads.
	 */
	private function manyProducts(): void {
		$this->server->pageSize = 1;
		for ( $i = 1; $i <= 30; ++$i ) {
			$this->server->tables['products'][ 'q' . $i ] = array( 'id' => 'q' . $i, 'name' => 'Bulk ' . $i, 'sku' => 'BULK-' . $i, 'price' => 1 );
		}
	}

	public function test_a_list_read_stops_before_it_when_fewer_than_ten_calls_are_left(): void {
		$this->manyProducts();
		$this->server->quotaLeft = 12;
		$result                  = $this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Rate001, $result->code );
		$this->assertSame( 21600, $result->retryAfter, 'A month window with no reset time waits six hours.' );
		$this->assertCount( 3, $this->kit->http->requests, 'Reads went on while 10 or more were left (11, 10) and then stopped at 9 without a fourth.' );
	}

	public function test_a_minute_window_waits_a_minute(): void {
		$this->manyProducts();
		$this->server->quotaLeft   = 12;
		$this->server->quotaWindow = 'minute';
		$result                    = $this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertSame( ErrorCode::Rate001, $result->code );
		$this->assertSame( 60, $result->retryAfter );
	}

	public function test_the_reset_header_sets_the_wait_whether_it_is_seconds_or_a_unix_time(): void {
		$this->manyProducts();
		$this->server->quotaLeft  = 12;
		$this->server->quotaReset = '900';
		$this->assertSame( 900, $this->connector()->lookupProduct( $this->connection(), 'BULK-30' )->retryAfter );

		$other                      = $this->kit->connection( 'holded', array(), array( 'api_key' => \Estasoft\SyncEngine\Tests\Support\FakeHoldedServer::KEY ), 2 );
		$this->server->quotaLeft  = 12;
		$this->server->quotaReset = (string) ( strtotime( '2026-11-02 12:00:00 UTC' ) + 1800 );
		$this->assertEqualsWithDelta( 1800, $this->connector()->lookupProduct( $other, 'BULK-30' )->retryAfter, 30, 'A Unix time is turned into seconds from now.' );
	}

	public function test_the_stop_is_remembered_by_a_new_run_which_then_makes_no_request(): void {
		$this->manyProducts();
		$this->server->quotaLeft = 12;
		$this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$before = count( $this->kit->http->requests );
		$again  = $this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertSame( ErrorCode::Rate001, $again->code );
		$this->assertCount( $before, $this->kit->http->requests );
	}

	public function test_a_stale_reading_no_longer_stops_anything_and_the_read_goes_on_from_the_page_it_reached(): void {
		$this->manyProducts();
		$this->server->quotaLeft = 12;
		$this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$read = count( $this->requests( 'GET', '#/products\?#' ) );
		$this->server->quotaLeft = 2000;
		$this->kit->clock->advance( 3601 );
		$found = $this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertNotInstanceOf( ConnectorError::class, $found );
		$this->assertSame( 'q30', $found->id );
		$this->assertCount( $read + 34, $this->requests( 'GET', '#/products\?#' ), 'The index had expired with the hour, so it was read again from page 1: 34 products, one to a page.' );
	}

	public function test_a_minute_window_stop_loses_no_progress_the_read_goes_on_from_the_page_it_reached(): void {
		$this->manyProducts();
		$this->server->quotaLeft   = 12;
		$this->server->quotaWindow = 'minute';
		$this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$read = count( $this->requests( 'GET', '#/products\?#' ) );
		$this->assertGreaterThan( 0, $read );
		$this->server->quotaLeft = 2000;
		$this->kit->clock->advance( 61 );
		$found = $this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertSame( 'q30', $found->id );
		$this->assertCount( 34, $this->requests( 'GET', '#/products\?#' ), 'Every page of the 34 was read exactly once.' );
	}

	public function test_a_month_reading_is_still_fresh_after_a_minute(): void {
		$this->manyProducts();
		$this->server->quotaLeft = 12;
		$this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$read = count( $this->requests( 'GET', '#/products\?#' ) );
		$this->server->quotaLeft = 2000;
		$this->kit->clock->advance( 61 );
		$this->connector()->lookupProduct( $this->connection(), 'BULK-30' );
		$this->assertCount( $read, $this->requests( 'GET', '#/products\?#' ), 'The read is still held back.' );
	}

	public function test_a_warm_order_is_held_back_too_when_the_quota_is_nearly_spent_but_creates_are_not(): void {
		$connector = $this->connector();
		$connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->server->quotaLeft = 3;
		$connector->ping( $this->connection() );
		$held = $connector->send( $this->connection(), $this->item( EntityType::Order, '2' ), $this->payload( array( 'number' => '2' ) ) );
		$this->assertSame( ErrorCode::Rate001, $held->error->code );
		$this->assertCount( 1, $this->server->all( 'invoice' ), 'No second invoice and no write at all.' );
	}

	public function test_without_a_reading_nothing_is_held_back(): void {
		$this->server->quotaLeft = 0;
		$this->kit->http->route( 'GET', '#/warehouse#', static fn( array $request ) => \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array() ) );
		$this->assertTrue( $this->connector()->ping( $this->connection() ), 'A server that reports no quota header cannot hold anything back.' );
		$this->assertNotInstanceOf( ConnectorError::class, $this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' ) );
	}

	public function test_ping_is_one_cheap_call_and_says_no_for_a_wrong_key(): void {
		$connector = $this->connector();
		$this->assertTrue( $connector->ping( $this->connection() ) );
		$this->assertCount( 1, $this->kit->http->requests );
		$this->assertStringContainsString( '/invoicing/v1/warehouse', $this->kit->http->requests[0]['url'] );
		$this->assertFalse( $connector->ping( $this->connection( array(), 'hld-wrong-key-0123456789', 2 ) ) );
	}
}
