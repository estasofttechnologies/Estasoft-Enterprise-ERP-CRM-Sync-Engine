<?php
/**
 * Money at the edge of the Microsoft connectors.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use PHPUnit\Framework\TestCase;

final class AmountsTest extends TestCase {

	public function test_the_exponent_follows_the_currency(): void {
		$this->assertSame( 2, Amounts::exponent( 'AED' ) );
		$this->assertSame( 3, Amounts::exponent( 'KWD' ) );
		$this->assertSame( 0, Amounts::exponent( 'JPY' ) );
	}

	public function test_minor_units_become_the_decimal_number_of_a_body_without_drift(): void {
		$this->assertSame( 19.99, Amounts::decimal( 1999, 'AED' ) );
		$this->assertSame( 12.345, Amounts::decimal( 12345, 'KWD' ) );
		$this->assertSame( 1500.0, Amounts::decimal( 1500, 'JPY' ) );
		$this->assertSame( -1.05, Amounts::decimal( -105, 'AED' ) );
		$this->assertSame( '37.035', Amounts::text( 37035, 'KWD' ) );
		$this->assertSame( '0.05', Amounts::text( 5, 'AED' ) );
	}

	public function test_a_number_read_from_the_service_becomes_minor_units_and_binary_noise_is_removed(): void {
		$this->assertSame( 43795, Amounts::minor( 437.95, 'AED' ) );
		$this->assertSame( 43795, Amounts::minor( 437.94999999999993, 'AED' ) );
		$this->assertSame( 43795, Amounts::minor( '437.95', 'AED' ) );
		$this->assertSame( 12345, Amounts::minor( 12.345, 'KWD' ) );
		$this->assertSame( 1000, Amounts::minor( 10, 'AED' ) );
		$this->assertSame( -105, Amounts::minor( -1.05, 'AED' ) );
		foreach ( array( null, '', 'abc', array(), true ) as $nothing ) {
			$this->assertSame( 0, Amounts::minor( $nothing, 'AED' ) );
		}
	}

	public function test_two_amounts_are_the_same_within_one_minor_unit(): void {
		$this->assertTrue( Amounts::near( 6297, 6296 ) );
		$this->assertTrue( Amounts::near( 6297, 6297 ) );
		$this->assertFalse( Amounts::near( 6297, 6295 ) );
		$this->assertTrue( Amounts::near( 6297, 6295, 2 ) );
	}
}
