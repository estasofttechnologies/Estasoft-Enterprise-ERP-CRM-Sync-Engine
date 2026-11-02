<?php
/**
 * What is left of the hourly tick's time budget for the shadow comparison.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

use Estasoft\SyncEngine\Jobs\JobsModule;
use PHPUnit\Framework\TestCase;

final class TickBudgetTest extends TestCase {

	public function test_the_whole_budget_is_left_when_the_start_is_not_known(): void {
		$this->assertSame( 15.0, JobsModule::budgetLeft( 15.0, null, 1000.0 ) );
	}

	public function test_time_already_spent_by_reconciliation_is_taken_off(): void {
		$this->assertEqualsWithDelta( 4.5, JobsModule::budgetLeft( 15.0, 1000.0, 1010.5 ), 0.0001 );
	}

	public function test_nothing_is_left_after_a_full_reconciliation_run(): void {
		$this->assertSame( 0.0, JobsModule::budgetLeft( 15.0, 1000.0, 1015.2 ) );
		$this->assertSame( 0.0, JobsModule::budgetLeft( 15.0, 1000.0, 1900.0 ) );
	}

	public function test_a_clock_that_stepped_back_does_not_grow_the_budget(): void {
		$this->assertSame( 15.0, JobsModule::budgetLeft( 15.0, 1000.0, 990.0 ) );
	}
}
