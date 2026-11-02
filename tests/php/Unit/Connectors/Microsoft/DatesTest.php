<?php
/**
 * Dates on documents are the dates of the store.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\BusinessCentral\BusinessCentralConnector;
use Estasoft\SyncEngine\Connectors\DynamicsFinance\DynamicsFinanceConnector;
use Estasoft\SyncEngine\Connectors\DynamicsSales\DynamicsSalesConnector;
use Estasoft\SyncEngine\Connectors\Microsoft\Dates;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase {

	protected function tearDown(): void {
		Dates::resolveWith( null );
	}

	private function zone( string $name ): void {
		Dates::resolveWith( static fn() => new \DateTimeZone( $name ) );
	}

	public function test_a_point_in_time_is_moved_to_the_store_date_not_the_utc_date(): void {
		$this->zone( 'Asia/Dubai' );
		$this->assertSame( '2026-11-01', Dates::calendarDate( '2026-10-31T22:30:00Z' ), '02:30 on the first in Dubai' );
		$this->assertSame( '2026-10-31', Dates::calendarDate( '2026-10-31T19:59:59Z' ) );
		$this->assertSame( '2026-11-01', Dates::calendarDate( '2026-11-01 08:30:00' ), 'A time without an offset is UTC.' );
		$this->assertSame( '2026-11-01', Dates::calendarDate( '2026-11-01T01:30:00+04:00' ) );
		$this->zone( 'America/New_York' );
		$this->assertSame( '2026-10-31', Dates::calendarDate( '2026-11-01T02:30:00Z' ), '22:30 on the 31st in New York' );
	}

	public function test_a_plain_calendar_date_is_never_shifted(): void {
		foreach ( array( 'Asia/Dubai', 'America/New_York', 'Pacific/Kiritimati', 'UTC' ) as $name ) {
			$this->zone( $name );
			$this->assertSame( '2026-11-01', Dates::calendarDate( '2026-11-01' ), $name );
		}
	}

	public function test_text_that_is_not_a_date_gives_nothing(): void {
		foreach ( array( '', '   ', 'next week sometime', '2026-02-30', '2026-13-01', 12, null, array() ) as $bad ) {
			$this->assertSame( '', Dates::calendarDate( $bad ), (string) json_encode( $bad ) );
		}
	}

	public function test_today_is_the_store_s_today(): void {
		$this->zone( 'Pacific/Kiritimati' );
		$this->assertSame( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Pacific/Kiritimati' ) ) )->format( 'Y-m-d' ), Dates::today() );
	}

	public function test_an_order_carries_the_store_date_whichever_connector_sends_it(): void {
		$this->zone( 'Asia/Dubai' );
		$order = OrderData::parse(
			array(
				'currency'   => 'AED',
				'order_date' => '2026-10-31T22:30:00Z',
				'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => 100 ) ),
			),
			'Test system'
		);
		$this->assertInstanceOf( OrderData::class, $order );
		$this->assertSame( '2026-11-01', $order->orderDate );
	}

	public function test_the_default_order_date_rule_asks_for_the_shop_time_in_every_connector(): void {
		$kit = ApiTestKit::make();
		try {
			$rules = array(
				'business-central' => ( new BusinessCentralConnector( $kit->deps ) )->defaultMapping( 'orders' ),
				'dynamics-sales'   => ( new DynamicsSalesConnector( $kit->deps ) )->defaultMapping( 'orders' ),
				'dynamics-finance' => ( new DynamicsFinanceConnector( $kit->deps ) )->defaultMapping( 'orders' ),
			);
			$refund = ( new BusinessCentralConnector( $kit->deps ) )->defaultMapping( 'refunds' );
			foreach ( $rules as $id => $set ) {
				$rule = current( array_filter( $set, static fn( array $r ): bool => 'order_date' === $r['target'] ) );
				$this->assertIsArray( $rule, $id );
				$this->assertSame( 'store', $rule['transforms'][0]['args']['output_timezone'], $id );
			}
			$rule = current( array_filter( $refund, static fn( array $r ): bool => 'date' === $r['target'] ) );
			$this->assertSame( 'store', $rule['transforms'][0]['args']['output_timezone'] );
		} finally {
			$kit->cleanup();
		}
	}
}
