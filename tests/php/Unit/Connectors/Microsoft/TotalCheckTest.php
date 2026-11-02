<?php
/**
 * The comparison of the shop total with the total of the system.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\TotalCheck;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class TotalCheckTest extends TestCase {

	public function test_equal_totals_and_one_minor_unit_of_rounding_pass_silently(): void {
		foreach ( array( array( 6297, 6297 ), array( 6296, 6297 ), array( 6298, 6297 ) ) as $pair ) {
			$result = TotalCheck::compare( $pair[0], $pair[1], 'AED', true, 'Test system', 'hint' );
			$this->assertNull( $result['error'] );
			$this->assertSame( '', $result['warning'] );
		}
	}

	public function test_nothing_to_compare_with_is_not_a_failure(): void {
		$this->assertNull( TotalCheck::compare( 5000, null, 'AED', true, 'Test system', 'hint' )['error'] );
		$this->assertNull( TotalCheck::compare( 5000, 0, 'AED', true, 'Test system', 'hint' )['error'] );
		$missing = TotalCheck::compare( null, 6297, 'AED', true, 'Test system', 'hint' );
		$this->assertNull( $missing['error'], 'A total the system has not filled in is a warning, never a failure.' );
		$this->assertNotSame( '', $missing['warning'] );
		$this->assertNotSame( '', TotalCheck::compare( 0, 6297, 'AED', true, 'Test system', 'hint' )['warning'] );
	}

	public function test_a_difference_is_a_failure_that_names_both_totals_or_a_warning_by_choice(): void {
		$fail = TotalCheck::compare( 5997, 6297, 'AED', true, 'Test system', 'Check the tax codes.' );
		$this->assertSame( ErrorCode::Val002, $fail['error']->code );
		$this->assertStringContainsString( '62.97 AED', $fail['error']->message() );
		$this->assertStringContainsString( '59.97 AED', $fail['error']->message() );
		$this->assertStringContainsString( 'Check the tax codes.', $fail['error']->message() );

		$warn = TotalCheck::compare( 5997, 6297, 'AED', false, 'Test system', 'hint' );
		$this->assertNull( $warn['error'] );
		$this->assertStringContainsString( '62.97 AED', $warn['warning'] );
	}

	public function test_three_decimal_currencies_are_written_with_three_decimals(): void {
		$fail = TotalCheck::compare( 37030, 37035, 'KWD', true, 'Test system', 'hint' );
		$this->assertStringContainsString( '37.035 KWD', $fail['error']->message() );
		$this->assertStringContainsString( '37.030 KWD', $fail['error']->message() );
	}
}
