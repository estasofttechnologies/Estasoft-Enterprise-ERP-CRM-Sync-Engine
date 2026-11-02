<?php
/**
 * WP-CLI module: registers `wp estasoft-sync`.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Cli;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\ModuleInterface;

/**
 * Adds the command only while WP-CLI is running, so a normal request loads nothing from this folder.
 */
final class CliModule implements ModuleInterface {

	/**
	 * Name of the command.
	 */
	public const COMMAND = 'estasoft-sync';

	/**
	 * This module registers no services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		unset( $container );
	}

	/**
	 * Register the command when WP-CLI is running.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( self::COMMAND, new Commands( $container, new WpCliOutput(), new StopSignal() ) );
	}
}
