<?php
/**
 * Time conversions and day filters in store time.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Support;

use Estasoft\SyncEngine\Core\Support\DayRange;
use Estasoft\SyncEngine\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class TimeAndRangeTest extends TestCase {

	public function test_sql_and_iso_round_trip(): void {
		$moment = new \DateTimeImmutable( '2026-11-02 07:40:05', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '2026-11-02 07:40:05', Time::sql( $moment ) );
		$this->assertSame( '2026-11-02T07:40:05Z', Time::iso( '2026-11-02 07:40:05' ) );
		$this->assertNull( Time::iso( null ) );
		$this->assertNull( Time::iso( '0000-00-00 00:00:00' ) );
		$this->assertNull( Time::fromSql( 'not a date' ) );
	}

	public function test_sql_converts_other_zones_to_utc(): void {
		$moment = new \DateTimeImmutable( '2026-11-02 12:00:00', new \DateTimeZone( 'Asia/Dubai' ) );
		$this->assertSame( '2026-11-02 08:00:00', Time::sql( $moment ) );
	}

	public function test_milliseconds_between(): void {
		$a = new \DateTimeImmutable( '2026-11-02 12:00:00.250', new \DateTimeZone( 'UTC' ) );
		$b = new \DateTimeImmutable( '2026-11-02 12:00:01.100', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( 850, Time::millisecondsBetween( $a, $b ) );
		$this->assertSame( 0, Time::millisecondsBetween( $b, $a ) );
	}

	public function test_today_in_store_time_starts_at_local_midnight(): void {
		$zone = new \DateTimeZone( 'Asia/Dubai' ); // UTC+4.
		$now  = new \DateTimeImmutable( '2026-11-02 21:00:00', new \DateTimeZone( 'UTC' ) ); // 01:00 on 3 Nov in Dubai.
		$this->assertSame( array( '2026-11-02 20:00:00', null ), DayRange::bounds( 'today', $now, $zone ) );
		$this->assertSame( array( '2026-11-01 20:00:00', '2026-11-02 20:00:00' ), DayRange::bounds( 'yesterday', $now, $zone ) );
		$this->assertSame( array( '2026-10-27 20:00:00', null ), DayRange::bounds( '7d', $now, $zone ) );
		$this->assertSame( array( '2026-10-04 20:00:00', null ), DayRange::bounds( '30d', $now, $zone ) );
		$this->assertNull( DayRange::bounds( 'forever', $now, $zone ) );
	}
}
