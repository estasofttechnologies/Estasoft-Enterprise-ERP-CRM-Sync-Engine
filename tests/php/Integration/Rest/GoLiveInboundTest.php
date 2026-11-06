<?php
/**
 * Going live applies the stock and tracking choices of setup, in the real plugin with real services.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class GoLiveInboundTest extends RestIntegrationCase {

	private int $id;

	protected function setUp(): void {
		parent::setUp();
		$this->id = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
				'address'   => 'https://erp.example.test',
			),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array()
		);
		$this->actAs( 'administrator' );
	}

	/**
	 * Going live saves inside a transaction that the plugin's own database work can commit, so the setup state is removed by hand.
	 */
	protected function tearDown(): void {
		delete_option( OnboardingState::OPTION );
		parent::tearDown();
	}

	/**
	 * Save step 3 answers the way the wizard does.
	 *
	 * @param array<string,mixed> $what Answers.
	 */
	private function answer( array $what ): void {
		$this->container->get( Services::ONBOARDING )->save( array( 'connector' => 'odoo', 'answers' => array( 'what' => $what ) ) );
	}

	public function test_stock_and_tracking_are_off_until_the_person_goes_live_with_them_on(): void {
		$stock    = $this->container->get( JobServices::STOCK );
		$tracking = $this->container->get( JobServices::TRACKING );
		$this->assertFalse( $stock->rules( $this->id )['enabled'] );
		$this->assertFalse( $tracking->settings( $this->id )['tracking_enabled'] );

		$this->answer( array( 'stock_in' => true, 'tracking_in' => true ) );
		$this->assertSame( 200, $this->call( 'POST', '/go-live', array( 'mode' => 'shadow' ) )->get_status() );

		$this->assertTrue( $stock->rules( $this->id )['enabled'], 'Update WooCommerce stock was left on' );
		$this->assertTrue( $tracking->settings( $this->id )['tracking_enabled'], 'Update order status and tracking numbers was left on' );
	}

	public function test_a_choice_turned_off_in_setup_stays_off(): void {
		$this->answer( array( 'stock_in' => false, 'tracking_in' => true ) );
		$this->call( 'POST', '/go-live', array( 'mode' => 'live' ) );

		$this->assertFalse( $this->container->get( JobServices::STOCK )->rules( $this->id )['enabled'] );
		$this->assertTrue( $this->container->get( JobServices::TRACKING )->settings( $this->id )['tracking_enabled'] );
	}
}
