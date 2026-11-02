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

	public function test_shadow_is_active_between_its_start_and_its_end(): void {
		$this->state->save(
			array(
				'shadow_started_at' => '2026-11-02T08:00:00Z',
				'shadow_ends_at'    => '2026-11-09T08:00:00Z',
			)
		);
		$utc = new \DateTimeZone( 'UTC' );
		$this->assertTrue( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-05 12:00:00', $utc ) ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-09 08:00:00', $utc ) ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-12-01 00:00:00', $utc ) ) );
	}

	public function test_ending_shadow_early_stops_it_at_once(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$this->state->save( array( 'shadow_started_at' => '2026-11-02T08:00:00Z', 'shadow_ends_at' => '2026-11-09T08:00:00Z' ) );
		$this->state->save( array( 'shadow_ends_at' => '2026-11-03T08:00:00Z' ) );
		$this->assertFalse( $this->state->isShadowActive( new \DateTimeImmutable( '2026-11-04 00:00:00', $utc ) ) );
	}

	public function test_save_merges_known_fields_only_and_clamps_the_step(): void {
		$saved = $this->state->save( array( 'step' => 99, 'connector' => 'odoo', 'made_up' => 1, 'answers' => array( 'stock' => true ) ) );
		$this->assertSame( 5, $saved['step'] );
		$this->assertSame( 'odoo', $saved['connector'] );
		$this->assertSame( array( 'stock' => true ), $saved['answers'] );
		$this->assertArrayNotHasKey( 'made_up', $saved );
	}
}
