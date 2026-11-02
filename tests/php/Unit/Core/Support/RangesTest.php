<?php
/**
 * Number ranges.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Support;

use Estasoft\SyncEngine\Core\Support\Ranges;
use PHPUnit\Framework\TestCase;

final class RangesTest extends TestCase {

	public function test_the_ranges_match_review_2(): void {
		$this->assertSame( array( 3, 20, 5 ), Ranges::BREAKER_THRESHOLD );
		$this->assertSame( array( 1, 60, 2 ), Ranges::PROBE_MINUTES );
		$this->assertSame( array( 1, 50, 4 ), Ranges::REQUESTS_PER_SECOND );
		$this->assertSame( array( 1, 10, 2 ), Ranges::CONCURRENCY );
		$this->assertSame( array( 1, 10, 2 ), Ranges::GLOBAL_CONCURRENCY );
		$this->assertSame( array( 0, 999, 2 ), Ranges::SAFETY_BUFFER );
		$this->assertSame( array( 10, 10000, 200 ), Ranges::BACKLOG );
		$this->assertSame( 10, Ranges::MAX_RECIPIENTS );
	}

	public function test_read_accepts_whole_numbers_inside_the_range_only(): void {
		$range = Ranges::PROBE_MINUTES;
		$this->assertSame( 1, Ranges::read( 1, $range ) );
		$this->assertSame( 60, Ranges::read( 60, $range ) );
		$this->assertSame( 30, Ranges::read( '30', $range ) );
		$this->assertSame( 30, Ranges::read( 30.0, $range ) );
		$this->assertNull( Ranges::read( 0, $range ) );
		$this->assertNull( Ranges::read( 61, $range ) );
		$this->assertNull( Ranges::read( 2.5, $range ) );
		$this->assertNull( Ranges::read( 'abc', $range ) );
		$this->assertNull( Ranges::read( '', $range ) );
		$this->assertNull( Ranges::read( null, $range ) );
		$this->assertNull( Ranges::read( true, $range ) );
	}

	public function test_the_message_names_both_ends(): void {
		$this->assertSame( 'Enter a number from 1 to 60.', Ranges::message( Ranges::PROBE_MINUTES ) );
	}
}
