<?php
/**
 * Activation and deactivation.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Static entry points for the activation and deactivation hooks. Activation runs in a request where
 * the plugin has not booted, so the work is done by Core\Installer, which needs no container.
 */
final class Lifecycle {

	/**
	 * Create or upgrade tables, grant capabilities, set the welcome redirect flag.
	 *
	 * @param bool $networkWide True for network-wide multisite activation.
	 */
	public static function activate( bool $networkWide = false ): void {
		if ( class_exists( Core\Installer::class ) ) {
			Core\Installer::activate( $networkWide );
		}
	}

	/**
	 * Unschedule recurring actions. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		if ( class_exists( Core\Installer::class ) ) {
			Core\Installer::deactivate();
		}
	}
}
