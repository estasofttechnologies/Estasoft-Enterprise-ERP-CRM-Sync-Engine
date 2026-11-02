<?php
/**
 * The two capabilities.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class CapabilitiesTest extends CoreIntegrationTestCase {

	/**
	 * Users made by a test.
	 *
	 * @var array<int,int>
	 */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();
		if ( null === get_role( 'shop_manager' ) ) {
			add_role( 'shop_manager', 'Shop manager', array( 'read' => true ) );
		}
		Capabilities::revoke();
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		wp_set_current_user( 0 );
		remove_role( 'configure_only' );
		Capabilities::grant();
		parent::tearDown();
	}

	/**
	 * Make a user and make them the current user.
	 *
	 * @param string $role Role.
	 */
	private function actAs( string $role ): void {
		$id            = wp_insert_user( array( 'user_login' => 'u_' . $role . '_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => 'u' . wp_rand() . '@example.test' ) );
		$this->users[] = (int) $id;
		wp_set_current_user( (int) $id );
	}

	public function test_the_names_are_the_ones_in_the_contract(): void {
		$this->assertSame( 'estasoft_sync_manage', Capabilities::MANAGE );
		$this->assertSame( 'estasoft_sync_configure', Capabilities::CONFIGURE );
	}

	public function test_administrators_get_both_and_shop_managers_get_manage_only(): void {
		Capabilities::grant();
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );
		$this->assertTrue( $administrator->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( $administrator->has_cap( Capabilities::CONFIGURE ) );
		$this->assertTrue( $shop_manager->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( $shop_manager->has_cap( Capabilities::CONFIGURE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_granting_twice_changes_nothing(): void {
		Capabilities::grant();
		$before = get_role( 'administrator' )->capabilities;
		Capabilities::grant();
		$this->assertSame( $before, get_role( 'administrator' )->capabilities );
	}

	public function test_revoking_removes_them_from_every_role(): void {
		Capabilities::grant();
		add_role( 'configure_only', 'Configure only', array( 'read' => true, Capabilities::CONFIGURE => true ) );
		Capabilities::revoke();
		foreach ( array( 'administrator', 'shop_manager', 'configure_only' ) as $role ) {
			$this->assertFalse( get_role( $role )->has_cap( Capabilities::MANAGE ), $role );
			$this->assertFalse( get_role( $role )->has_cap( Capabilities::CONFIGURE ), $role );
		}
	}

	public function test_the_checks_follow_the_current_user(): void {
		Capabilities::grant();

		$this->actAs( 'administrator' );
		$this->assertTrue( Capabilities::userCanManage() );
		$this->assertTrue( Capabilities::userCanConfigure() );

		$this->actAs( 'shop_manager' );
		$this->assertTrue( Capabilities::userCanManage() );
		$this->assertFalse( Capabilities::userCanConfigure() );

		$this->actAs( 'subscriber' );
		$this->assertFalse( Capabilities::userCanManage() );
		$this->assertFalse( Capabilities::userCanConfigure() );

		wp_set_current_user( 0 );
		$this->assertFalse( Capabilities::userCanManage() );
		$this->assertFalse( Capabilities::userCanConfigure() );
	}

	public function test_a_person_who_may_configure_may_also_view_and_operate(): void {
		add_role( 'configure_only', 'Configure only', array( 'read' => true, Capabilities::CONFIGURE => true ) );
		$this->actAs( 'configure_only' );
		$this->assertTrue( Capabilities::userCanConfigure() );
		$this->assertTrue( Capabilities::userCanManage() );
	}
}
