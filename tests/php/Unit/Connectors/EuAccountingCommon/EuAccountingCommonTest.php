<?php
/**
 * The helpers shared by the Lexware Office, sevDesk, Moneybird and Pennylane connectors: exact money, local dates and payload reading.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\EuAccountingCommon;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use PHPUnit\Framework\TestCase;

final class EuAccountingCommonTest extends TestCase {

	private Amounts $amounts;

	protected function setUp(): void {
		$this->amounts = new Amounts();
	}

	public function test_minor_units_become_decimal_text_without_floats(): void {
		$this->assertSame( '59.97', $this->amounts->decimal( 5997, 2 ) );
		$this->assertSame( '0.05', $this->amounts->decimal( 5, 2 ) );
		$this->assertSame( '-0.05', $this->amounts->decimal( -5, 2 ) );
		$this->assertSame( '37.035', $this->amounts->decimal( 37035, 3 ) );
		$this->assertSame( '1200', $this->amounts->decimal( 1200, 0 ) );
		$this->assertSame( '90071992547409.93', $this->amounts->decimal( 9007199254740993, 2 ), 'Beyond 2^53, where a float would lose the last digit.' );
	}

	public function test_decimal_values_become_minor_units_exactly_and_round_half_up_on_the_next_digit(): void {
		$this->assertSame( 1999, $this->amounts->toMinor( '19.99', 2 ) );
		$this->assertSame( 1999, $this->amounts->toMinor( 19.99, 2 ), 'A float that is not exactly representable still reads as 19.99.' );
		$this->assertSame( 10, $this->amounts->toMinor( '0.1', 2 ) );
		$this->assertSame( 2000, $this->amounts->toMinor( '19.995', 2 ) );
		$this->assertSame( 1999, $this->amounts->toMinor( '19.994', 2 ) );
		$this->assertSame( -150, $this->amounts->toMinor( '-1.5', 2 ) );
		$this->assertSame( 500, $this->amounts->toMinor( 5, 2 ) );
		$this->assertSame( 1200, $this->amounts->toMinor( '12', 2 ) );
		$this->assertNull( $this->amounts->toMinor( '1e3', 2 ) );
		$this->assertNull( $this->amounts->toMinor( 'abc', 2 ) );
		$this->assertNull( $this->amounts->toMinor( null, 2 ) );
	}

	public function test_percentages_and_tax_use_whole_numbers(): void {
		$this->assertSame( 2000, $this->amounts->basisPoints( '20' ) );
		$this->assertSame( 550, $this->amounts->basisPoints( '5.5%' ) );
		$this->assertSame( 210, $this->amounts->basisPoints( 2.1 ) );
		$this->assertNull( $this->amounts->basisPoints( 'x' ) );
		$this->assertSame( '20', $this->amounts->percent( 2000 ) );
		$this->assertSame( '5.5', $this->amounts->percent( 550 ) );
		$this->assertSame( '0', $this->amounts->percent( 0 ) );

		$this->assertSame( 800, $this->amounts->taxOf( 3998, 2000 ), '799.6 rounds to 800.' );
		$this->assertSame( -800, $this->amounts->taxOf( -3998, 2000 ) );
		$this->assertSame( 55, $this->amounts->taxOf( 1000, 550 ) );
		$this->assertSame( 2000, $this->amounts->netFromGross( 2400, 2000 ) );
		$this->assertSame( 1666, $this->amounts->netFromGross( 1999, 2000 ), '1665.83 rounds to 1666.' );
		$this->assertSame( -2000, $this->amounts->netFromGross( -2400, 2000 ) );
	}

	public function test_a_unit_price_is_only_given_when_it_multiplies_back_to_the_exact_total(): void {
		$this->assertSame( '19.99', $this->amounts->unitPrice( 3998, 2, 2.0, 0 ) );
		$this->assertSame( '-19.99', $this->amounts->unitPrice( -3998, 2, 2.0, 0 ) );
		$this->assertSame( '20.00', $this->amounts->unitPrice( 1000, 2, 0.5, 0 ) );
		$this->assertNull( $this->amounts->unitPrice( 1000, 2, 3.0, 0 ), '10.00 over 3 has no two-decimal price.' );
		$this->assertNull( $this->amounts->unitPrice( 1000, 2, 0.0, 0 ) );
		$this->assertNotNull( $this->amounts->unitPrice( 1000, 2, 3.0, 2 ), 'With two more decimals allowed, 3.3333 x 3 rounds back to 10.00.' );
	}

	public function test_quantities_are_written_without_trailing_zeros(): void {
		$this->assertSame( '2', $this->amounts->quantityText( 2.0 ) );
		$this->assertSame( '1.5', $this->amounts->quantityText( 1.5 ) );
		$this->assertSame( '0.25', $this->amounts->quantityText( 0.25 ) );
		$this->assertSame( 2, $this->amounts->number( '2' ) );
		$this->assertSame( 1.5, $this->amounts->number( '1.5' ) );
	}

	public function test_the_shop_time_zone_decides_the_calendar_day_in_summer_and_winter(): void {
		$this->assertSame( '2026-11-03', LocalDate::ymd( '2026-11-02 23:30:00', 'Europe/Paris' ), 'Winter, UTC+1.' );
		$this->assertSame( '2026-11-02', LocalDate::ymd( '2026-11-02 22:59:59', 'Europe/Paris' ) );
		$this->assertSame( '2026-07-02', LocalDate::ymd( '2026-07-01 22:00:00', 'Europe/Paris' ), 'Summer, UTC+2.' );
		$this->assertSame( '03.11.2026', LocalDate::dmy( '2026-11-02 23:30:00', 'Europe/Berlin' ) );
		$this->assertSame( '2026-11-03T00:00:00.000+01:00', LocalDate::midnightWithOffset( '2026-11-02 23:30:00', 'Europe/Berlin' ) );
		$this->assertSame( '2026-11-02', LocalDate::ymd( '2026-11-02T10:30:00', 'Nowhere/Land' ), 'An unknown zone falls back to UTC.' );
		$this->assertSame( '', LocalDate::ymd( 'not a date', 'Europe/Paris' ) );
		$this->assertNull( LocalDate::at( '', 'Europe/Paris' ) );
	}

	public function test_day_bounds_are_the_first_and_last_second_of_the_local_day(): void {
		$bounds = LocalDate::dayBounds( '2026-11-02 23:30:00', 'Europe/Berlin' );

		$this->assertNotNull( $bounds );
		$this->assertSame( '2026-11-02 23:00:00', gmdate( 'Y-m-d H:i:s', $bounds[0] ), 'Midnight on the third in Berlin is 23:00 UTC on the second.' );
		$this->assertSame( '2026-11-03 22:59:59', gmdate( 'Y-m-d H:i:s', $bounds[1] ) );
		$this->assertNull( LocalDate::dayBounds( '', 'Europe/Berlin' ) );
	}

	public function test_payload_values_are_read_defensively(): void {
		$this->assertSame( '12', PayloadReader::text( ' 12 ' ) );
		$this->assertSame( '', PayloadReader::text( array( 'x' ) ) );
		$this->assertSame( 150, PayloadReader::minor( '150' ) );
		$this->assertSame( 150, PayloadReader::minor( 150.0 ) );
		$this->assertNull( PayloadReader::minor( 1.5 ), 'A fraction of a minor unit is not a total.' );
		$this->assertNull( PayloadReader::minor( '1.50' ) );
		$this->assertSame( 2.5, PayloadReader::quantity( '2.5' ) );
		$this->assertSame( 0.0, PayloadReader::quantity( 'many' ) );
		$this->assertSame( 'FR_200', PayloadReader::taxCode( array( '', 'FR_200', 'FR_100' ) ) );
		$this->assertSame( 'FR_200', PayloadReader::taxCode( ' FR_200 ' ) );
	}

	public function test_long_text_is_cut_on_a_character_not_a_byte(): void {
		$this->assertSame( 'short', PayloadReader::shorten( 'short', 10 ) );
		$cut = PayloadReader::shorten( 'نور المنصوري نور المنصوري', 10 );
		$this->assertSame( 10, mb_strlen( $cut ) );
		$this->assertStringEndsWith( '…', $cut );
		$this->assertTrue( (bool) preg_match( '//u', $cut ), 'Still valid UTF-8.' );
	}

	public function test_the_order_marker_matches_the_whole_number_only(): void {
		$this->assertTrue( PayloadReader::hasMarker( 'Bestellung WC-12', 'WC-12' ) );
		$this->assertTrue( PayloadReader::hasMarker( "WC-12\nDanke", 'WC-12' ) );
		$this->assertFalse( PayloadReader::hasMarker( 'Bestellung WC-123', 'WC-12' ), 'WC-12 is not a part of WC-123.' );
		$this->assertFalse( PayloadReader::hasMarker( 'AWC-12', 'WC-12' ) );
		$this->assertFalse( PayloadReader::hasMarker( '', 'WC-12' ) );
	}
}
