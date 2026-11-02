<?php
/**
 * money_minor (AS-18) and date_utc.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Transforms\DateUtc;
use Estasoft\SyncEngine\Mapping\Transforms\MoneyMinor;
use Estasoft\SyncEngine\Mapping\Transforms\TransformArgumentException;

final class MoneyAndDateTest extends TransformTestCase {

	public function test_aed_uses_two_decimals(): void {
		$context = $this->context( array( 'order' => array( 'currency' => 'AED' ) ) );
		$this->assertSame( 21900, ( new MoneyMinor() )->apply( '219.00', array(), $context ) );
		$this->assertSame( 1999, ( new MoneyMinor() )->apply( 19.99, array(), $context ) );
	}

	public function test_kwd_uses_three_decimals_without_float_error(): void {
		$context = $this->context( array( 'order' => array( 'currency' => 'KWD' ) ) );
		$money   = new MoneyMinor();
		$this->assertSame( 12345, $money->apply( '12.345', array(), $context ) );
		$this->assertSame( 12345, $money->apply( 12.345, array(), $context ) );
		$this->assertSame( 1, $money->apply( '0.001', array(), $context ) );
		$this->assertSame( 3, $money->apply( '0.003', array(), $context ) );
		$this->assertSame( 300, $money->apply( 0.1 + 0.2, array(), $context ), 'Floating point 0.30000000000000004 is exactly 0.300.' );
	}

	public function test_jpy_has_no_decimals(): void {
		$this->assertSame( 1500, ( new MoneyMinor() )->apply( '1500', array(), $this->context( array( 'order' => array( 'currency' => 'JPY' ) ) ) ) );
	}

	public function test_currency_argument_overrides_the_order_currency(): void {
		$context = $this->context( array( 'order' => array( 'currency' => 'AED' ) ) );
		$this->assertSame( 1250, ( new MoneyMinor() )->apply( '1.250', array( 'currency' => 'KWD' ), $context ) );
		$this->assertSame( 1250, ( new MoneyMinor() )->apply( '1.250', array( 'currency' => 'order.currency' ), $this->context( array( 'order' => array( 'currency' => 'KWD' ) ) ) ) );
	}

	public function test_minor_input_stays_whole(): void {
		$money = new MoneyMinor();
		$this->assertSame( 21900, $money->apply( 21900, array( 'from' => 'minor' ), $this->context() ) );
		$this->assertSame( 21900, $money->apply( '21900', array( 'from' => 'minor' ), $this->context() ) );
	}

	public function test_text_that_is_not_a_number_becomes_empty_with_a_warning(): void {
		$context = $this->context( array( 'order' => array( 'currency' => 'AED' ) ) );
		$this->assertSame( '', ( new MoneyMinor() )->apply( 'free', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_empty_amounts_pass_through(): void {
		$context = $this->context();
		$this->assertNull( ( new MoneyMinor() )->apply( null, array(), $context ) );
		$this->assertSame( '', ( new MoneyMinor() )->apply( '', array(), $context ) );
		$this->assertSame( array(), $this->warnings( $context ) );
	}

	public function test_money_check(): void {
		$money = new MoneyMinor();
		$this->assertSame( array(), $money->check( array() ) );
		$this->assertSame( array(), $money->check( array( 'from' => 'minor', 'currency' => 'KWD' ) ) );
		$this->assertSame( array(), $money->check( array( 'currency' => 'order.currency' ) ) );
		$this->assertCount( 1, $money->check( array( 'from' => 'cents' ) ) );
		$this->assertCount( 1, $money->check( array( 'currency' => 'dinar!' ) ) );
	}

	/**
	 * @dataProvider dates
	 */
	public function test_dates_become_utc( string $typed, array $args, string $expected ): void {
		$this->assertSame( $expected, ( new DateUtc() )->apply( $typed, $args, $this->context() ), $typed );
	}

	/**
	 * @return array<string,array{0:string,1:array<string,string>,2:string}>
	 */
	public static function dates(): array {
		return array(
			'iso with z'            => array( '2026-11-02T07:34:00Z', array(), '2026-11-02 07:34:00' ),
			'iso with offset'       => array( '2026-11-02T11:34:00+04:00', array(), '2026-11-02 07:34:00' ),
			'mysql is utc'          => array( '2026-11-02 07:34:00', array(), '2026-11-02 07:34:00' ),
			'mysql in dubai'        => array( '2026-11-02 11:34:00', array( 'timezone' => 'Asia/Dubai' ), '2026-11-02 07:34:00' ),
			'iso output'            => array( '2026-11-02 07:34:00', array( 'format' => 'iso' ), '2026-11-02T07:34:00Z' ),
			'date output'           => array( '2026-11-02T23:30:00-05:00', array( 'format' => 'date' ), '2026-11-03' ),
			'unix seconds'          => array( '1793604840', array(), '2026-11-02 07:34:00' ),
			'unix milliseconds'     => array( '1793604840000', array(), '2026-11-02 07:34:00' ),
			'arabic digits'         => array( '٢٠٢٦-١١-٠٢ ٠٧:٣٤:٠٠', array(), '2026-11-02 07:34:00' ),
		);
	}

	public function test_the_default_output_zone_is_utc_whatever_the_store_zone_is(): void {
		$date = new DateUtc( static fn(): \DateTimeZone => new \DateTimeZone( 'Asia/Dubai' ) );
		$this->assertSame( '2026-11-02', $date->apply( '2026-11-02T22:30:00Z', array( 'format' => 'date' ), $this->context() ), 'Nothing changes unless output_timezone is given.' );
	}

	public function test_an_order_placed_at_half_past_one_in_dubai_can_give_the_shops_own_date(): void {
		$store = new DateUtc( static fn(): \DateTimeZone => new \DateTimeZone( 'Asia/Dubai' ) );
		// 3 Nov 01:30 in Dubai (UTC+4) is 2 Nov 21:30 in UTC.
		$utc = '2026-11-02T21:30:00Z';
		$this->assertSame( '2026-11-02', $store->apply( $utc, array( 'format' => 'date' ), $this->context() ) );
		$this->assertSame( '2026-11-03', $store->apply( $utc, array( 'format' => 'date', 'output_timezone' => 'store' ), $this->context() ) );
		$this->assertSame( '2026-11-03', $store->apply( $utc, array( 'format' => 'date', 'output_timezone' => 'Asia/Dubai' ), $this->context() ) );
		$this->assertSame( '2026-11-03 01:30:00', $store->apply( $utc, array( 'output_timezone' => 'store' ), $this->context() ) );
	}

	public function test_a_utc_plus_three_store_gets_its_own_date_too(): void {
		$riyadh = new DateUtc( static fn(): \DateTimeZone => new \DateTimeZone( 'Asia/Riyadh' ) );
		$this->assertSame( '2026-11-03', $riyadh->apply( '2026-11-02T22:30:00Z', array( 'format' => 'date', 'output_timezone' => 'store' ), $this->context() ) );
		$this->assertSame( '2026-11-02', $riyadh->apply( '2026-11-02T20:59:59Z', array( 'format' => 'date', 'output_timezone' => 'store' ), $this->context() ) );
	}

	public function test_an_iso_result_in_another_zone_carries_its_offset(): void {
		$date = new DateUtc();
		$this->assertSame( '2026-11-03T01:30:00+04:00', $date->apply( '2026-11-02T21:30:00Z', array( 'format' => 'iso', 'output_timezone' => 'Asia/Dubai' ), $this->context() ) );
		$this->assertSame( '2026-11-02T21:30:00Z', $date->apply( '2026-11-02T21:30:00Z', array( 'format' => 'iso', 'output_timezone' => 'UTC' ), $this->context() ) );
	}

	public function test_store_zone_outside_wordpress_is_utc_and_a_bad_output_zone_is_refused(): void {
		$date = new DateUtc();
		$this->assertSame( '2026-11-02', $date->apply( '2026-11-02T21:30:00Z', array( 'format' => 'date', 'output_timezone' => 'store' ), $this->context() ) );
		$this->assertCount( 1, $date->check( array( 'output_timezone' => 'Mars/Olympus' ) ) );
		$this->assertSame( array(), $date->check( array( 'output_timezone' => 'store' ) ) );
		$this->expectException( TransformArgumentException::class );
		$date->apply( '2026-11-02', array( 'output_timezone' => 'Mars/Olympus' ), $this->context() );
	}

	public function test_unreadable_date_becomes_empty_with_a_warning(): void {
		$context = $this->context();
		$this->assertSame( '', ( new DateUtc() )->apply( 'next tuesday-ish?!', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_empty_date_passes_through(): void {
		$context = $this->context();
		$this->assertNull( ( new DateUtc() )->apply( null, array(), $context ) );
		$this->assertSame( array(), $this->warnings( $context ) );
	}

	public function test_bad_format_or_zone_is_refused(): void {
		$date = new DateUtc();
		$this->assertCount( 1, $date->check( array( 'format' => 'rfc' ) ) );
		$this->assertCount( 1, $date->check( array( 'timezone' => 'Mars/Olympus' ) ) );
		$this->assertSame( array(), $date->check( array( 'format' => 'iso', 'timezone' => 'Asia/Dubai' ) ) );
		$this->expectException( TransformArgumentException::class );
		$date->apply( '2026-11-02', array( 'timezone' => 'Mars/Olympus' ), $this->context() );
	}
}
