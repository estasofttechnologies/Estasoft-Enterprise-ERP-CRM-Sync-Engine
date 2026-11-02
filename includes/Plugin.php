<?php
/**
 * Plugin bootstrap.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the container, asks each module to register services, then lets each add hooks.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the plugin.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private: use instance().
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Module classes in load order. A module whose class does not exist yet is skipped, so the list
	 * can name modules that later milestones add.
	 *
	 * @return array<int,class-string<ModuleInterface>>
	 */
	public static function moduleClasses(): array {
		$modules = array(
			Core\CoreModule::class,           // Database, repositories, settings, logging, queue, scheduler.
			Connectors\ConnectorsModule::class, // Registry and the Odoo connector.
			Mapping\MappingModule::class,     // Mapping engine and payload builder.
			Woo\WooModule::class,             // WooCommerce hooks, order meta, boxes, columns.
			Jobs\JobsModule::class,           // Stock, tracking, invoices, shadow, reconciliation, notifications, tools.
			Rest\RestModule::class,           // REST API.
			Admin\AdminModule::class,         // Menu, admin bar, widget, notices, assets, help.
			Cli\CliModule::class,             // WP-CLI.
		);

		/**
		 * Filter the module list (for add-on plugins that extend the core).
		 *
		 * @param array<int,string> $modules Module class names.
		 */
		return (array) apply_filters( 'estasoft_sync_modules', $modules );
	}

	/**
	 * Register services and hooks. Safe to call once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'loadTextDomain' ), 1 );

		$modules = array();
		foreach ( self::moduleClasses() as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, ModuleInterface::class ) ) {
				$modules[] = new $class();
			}
		}
		foreach ( $modules as $module ) {
			$module->services( $this->container );
		}
		foreach ( $modules as $module ) {
			$module->hooks( $this->container );
		}
	}

	/**
	 * Load translations. Only English ships; the strings stay translatable.
	 */
	public function loadTextDomain(): void {
		load_plugin_textdomain( ESTASOFT_SYNC_TEXT_DOMAIN, false, dirname( plugin_basename( ESTASOFT_SYNC_FILE ) ) . '/languages' );
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}
}
