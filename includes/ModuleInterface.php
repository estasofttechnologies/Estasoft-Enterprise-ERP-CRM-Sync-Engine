<?php
/**
 * A module wires one area of the plugin into WordPress.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Modules register services in the container first, then hooks. Nothing runs at load time.
 */
interface ModuleInterface {

	/**
	 * Register services. Called for every module before any hooks are added.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void;

	/**
	 * Add WordPress hooks. Called after all services are registered.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void;
}
