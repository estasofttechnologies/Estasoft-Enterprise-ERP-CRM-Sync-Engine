<?php
/**
 * The setup choices read from the stored answers.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore;
use Estasoft\SyncEngine\Woo\Adapters\CoreSendChoices;
use PHPUnit\Framework\TestCase;

final class CoreSendChoicesTest extends TestCase {

	/**
	 * Choices over stored answers.
	 *
	 * @param array<string,mixed> $answers Answers.
	 */
	private function choices( array $answers ): CoreSendChoices {
		$state = new OnboardingState( new CoreArrayOptionStore() );
		$state->save( array( 'answers' => $answers ) );
		return new CoreSendChoices( $state );
	}

	public function test_nothing_stored_means_the_answers_the_screen_starts_with(): void {
		$choices = $this->choices( array() );
		$this->assertTrue( $choices->sendOrders() );
		$this->assertSame( 'paid_or_cod', $choices->sendWhen() );
		$this->assertTrue( $choices->sendRefunds() );
	}

	public function test_stored_answers_are_read(): void {
		$choices = $this->choices( array( 'what' => array( 'send_orders' => false, 'send_when' => 'completed', 'send_refunds' => false ) ) );
		$this->assertFalse( $choices->sendOrders() );
		$this->assertSame( 'completed', $choices->sendWhen() );
		$this->assertFalse( $choices->sendRefunds() );
	}

	public function test_a_value_of_the_wrong_kind_is_the_default(): void {
		$choices = $this->choices( array( 'what' => array( 'send_orders' => 'no', 'send_when' => 'whenever', 'send_refunds' => 0 ) ) );
		$this->assertTrue( $choices->sendOrders() );
		$this->assertSame( 'paid_or_cod', $choices->sendWhen() );
		$this->assertTrue( $choices->sendRefunds() );
	}

	public function test_answers_that_are_not_a_table_are_ignored(): void {
		$choices = $this->choices( array( 'what' => 'everything' ) );
		$this->assertTrue( $choices->sendOrders() );
		$this->assertSame( 'paid_or_cod', $choices->sendWhen() );
	}
}
