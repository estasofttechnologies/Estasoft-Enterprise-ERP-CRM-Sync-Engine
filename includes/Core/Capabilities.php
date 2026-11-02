<?php
/**
 * The two capabilities (review-2 changes, capabilities).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * `estasoft_sync_manage` lets a person see and operate the queue. `estasoft_sync_configure` lets a
 * person change connections, mapping and settings. Administrators get both, shop managers get manage.
 */
final class Capabilities {

	/**
	 * View and operate.
	 */
	public const MANAGE = 'estasoft_sync_manage';

	/**
	 * Configure.
	 */
	public const CONFIGURE = 'estasoft_sync_configure';

	/**
	 * Add the capabilities to the standard roles. Safe to call again.
	 */
	public static function grant(): void {
		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			$administrator->add_cap( self::MANAGE );
			$administrator->add_cap( self::CONFIGURE );
		}
		$shop_manager = get_role( 'shop_manager' );
		if ( null !== $shop_manager ) {
			$shop_manager->add_cap( self::MANAGE );
		}
	}

	/**
	 * Remove the capabilities from every role.
	 */
	public static function revoke(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $name ) {
			$role = get_role( (string) $name );
			if ( null === $role ) {
				continue;
			}
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::CONFIGURE );
		}
	}

	/**
	 * Whether the current user may view and operate. A person who may configure may also view.
	 */
	public static function userCanManage(): bool {
		return current_user_can( self::MANAGE ) || current_user_can( self::CONFIGURE );
	}

	/**
	 * Whether the current user may configure.
	 */
	public static function userCanConfigure(): bool {
		return current_user_can( self::CONFIGURE );
	}
}
