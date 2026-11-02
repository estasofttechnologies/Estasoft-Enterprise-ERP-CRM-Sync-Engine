<?php
/**
 * Shadow window and wizard state.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore;
use PHPUnit\Framework\TestCase;

final class OnboardingStateTest extends TestCase {

	private OnboardingState $state;

	protected function setUp(): void {
		$this->state = new OnboardingState( new CoreArrayOptionStore() );
	}

	public function test_a_new_install_starts_at_step_one_with_no_shadow_window(): void {
		$state = $this->state->get();
		$this->assertSame( 1, $state['step'] );
		$this->assertFalse( $state['completed'] );
		$this->assertNull( $state['connector'] );
		$this->assertNull( $state['shadow_started_at'] );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-02 12:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	public function test_shadow_stays_on_after_the_planned_end_date_has_passed(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save(
			array(
				'shadow_started_at' => '2026-11-02T08:00:00Z',
				'shadow_ends_at'    => '2026-11-09T08:00:00Z',
			)
		);
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-05 12:00:00', $utc ) ) );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-09 08:00:00', $utc ) ), 'The planned end date is information only.' );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2027-12-01 00:00:00', $utc ) ), 'A year later nothing has ended it, so it is still on.' );
	}

	public function test_an_unreadable_date_leaves_shadow_on(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => 'not a date' ) );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-20 00:00:00', $utc ) ) );
		$this->state->save( array( 'shadow_started_at' => 'garbage', 'shadow_ends_at' => null ) );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-20 00:00:00', $utc ) ) );
	}

	public function test_shadow_is_not_active_before_it_starts(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save( array( 'shadow_started_at' => '2026-11-10T08:00:00Z' ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-09 00:00:00', $utc ) ) );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-10 08:00:00', $utc ) ) );
	}

	public function test_end_shadow_stores_the_end_and_the_reason(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => '2026-11-09T08:00:00Z' ) );
		$after = $this->state->endShadow( new \DateTimeImmutable( '2026-11-03 09:30:00', new \DateTimeZone( 'Asia/Dubai' ) ), 'person' );
		$this->assertSame( '2026-11-03T05:30:00Z', $after['shadow_ended_at'] );
		$this->assertSame( 'person', $after['shadow_end_reason'] );
		$this->assertSame( 'live', $after['mode'] );
		$this->assertSame( '2026-11-03T05:30:00Z', $after['shadow_ends_at'], 'The date shown is the real end.' );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-03 06:00:00', $utc ) ) );
	}

	public function test_the_first_end_is_kept(): void {
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z' ) );
		$this->state->endShadow( new \DateTimeImmutable( '2026-11-03 09:00:00', new \DateTimeZone( 'UTC' ) ), 'person' );
		$after = $this->state->endShadow( new \DateTimeImmutable( '2026-11-08 09:00:00', new \DateTimeZone( 'UTC' ) ), 'go_live' );
		$this->assertSame( '2026-11-03T09:00:00Z', $after['shadow_ended_at'] );
		$this->assertSame( 'person', $after['shadow_end_reason'] );
	}

	public function test_ending_a_shadow_that_never_started_does_nothing(): void {
		$after = $this->state->endShadow( new \DateTimeImmutable( '2026-11-03 09:00:00', new \DateTimeZone( 'UTC' ) ), 'person' );
		$this->assertNull( $after['shadow_ended_at'] );
		$this->assertNull( $after['mode'] );
	}

	public function test_a_mode_change_to_live_ends_shadow(): void {
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z' ) );
		$this->state->save( array( 'mode' => 'live' ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-03 00:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	public function test_the_marker_the_jobs_module_writes_when_a_person_ends_shadow_also_ends_it(): void {
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z' ) );
		$this->state->save( array( 'shadow_ends_at' => '2026-11-03T08:00:00Z', 'answers' => array( 'shadow_ended_by' => 0 ) ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-04 00:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	public function test_starting_a_new_window_opens_shadow_again(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z' ) );
		$this->state->endShadow( new \DateTimeImmutable( '2026-11-03 09:00:00', $utc ), 'person' );
		$this->state->save( array( 'answers' => array( 'shadow_ended_by' => 7 ) ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-04 00:00:00', $utc ) ) );

		$after = $this->state->save( array( 'shadow_started_at' => '2026-11-10T08:00:00Z', 'shadow_ends_at' => null, 'answers' => array( 'shadow_ended_by' => 7, 'shadow_days' => 7 ) ) );
		$this->assertNull( $after['shadow_ended_at'] );
		$this->assertSame( 'shadow', $after['mode'] );
		$this->assertArrayNotHasKey( 'shadow_ended_by', $after['answers'] );
		$this->assertSame( 7, $after['answers']['shadow_days'] );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-12 00:00:00', $utc ) ) );
	}

	public function test_save_merges_known_fields_only_and_clamps_the_step(): void {
		$saved = $this->state->save( array( 'step' => 99, 'connector' => 'odoo', 'made_up' => 1, 'answers' => array( 'stock' => true ) ) );
		$this->assertSame( 5, $saved['step'] );
		$this->assertSame( 'odoo', $saved['connector'] );
		$this->assertSame( array( 'stock' => true ), $saved['answers'] );
		$this->assertArrayNotHasKey( 'made_up', $saved );
	}
}
