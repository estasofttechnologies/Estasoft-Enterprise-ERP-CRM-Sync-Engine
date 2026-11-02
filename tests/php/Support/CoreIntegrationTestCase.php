<?php
/**
 * Base class of the Core integration tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Autoloader;

// The integration bootstrap loads IntegrationTestCase by hand and does not register the Tests namespace, so do it here, once.
if ( ! defined( 'ESTASOFT_SYNC_CORE_TEST_AUTOLOAD' ) ) {
	define( 'ESTASOFT_SYNC_CORE_TEST_AUTOLOAD', true );
	Autoloader::register( 'Estasoft\\SyncEngine\\Tests\\', dirname( __DIR__ ) . '/' );
}

/**
 * The repositories use real transactions (mapping publish, for example) and the schema tests run DDL, and a transaction
 * cannot be nested in MySQL. So this class does not wrap tests in a transaction; it empties the plugin's tables before each
 * test instead. The tables are owned by the Core tests' own database (estasoft_core).
 */
abstract class CoreIntegrationTestCase extends IntegrationTestCase {

	/**
	 * Hooks a test may add callbacks to or remove callbacks from. Their state is saved before each test and put back
	 * after it, so a test never leaves a callback behind and never removes one that the running plugin (or another
	 * module's tests) relies on.
	 *
	 * @var array<int,string>
	 */
	private const GUARDED_HOOKS = array(
		'estasoft_sync_should_enqueue',
		'estasoft_sync_queue_display',
		'estasoft_sync_retry_schedule',
		'estasoft_sync_classify_error',
		'estasoft_sync_log_redact',
		'estasoft_sync_meta_keys',
		'estasoft_sync_allow_private_hosts',
		'estasoft_sync_register_connectors',
		'estasoft_sync_before_send',
		'estasoft_sync_after_send',
		'estasoft_sync_item_synced',
		'estasoft_sync_item_failed',
		'estasoft_sync_connection_paused',
		'estasoft_sync_connection_resumed',
		'estasoft_sync_shadow_processed',
		'estasoft_sync_settings_updated',
		'estasoft_sync_migrated',
		'estasoft_sync_run_queue',
		'estasoft_sync_reap_locks',
		'estasoft_sync_probe_connections',
		'estasoft_sync_prune_data',
		'estasoft_sync_purge_actions',
		'cron_schedules',
		'init',
		'pre_http_request',
		'http_allowed_safe_ports',
		'wp_doing_cron',
	);

	/**
	 * Wired services.
	 */
	protected CoreHarness $h;

	/**
	 * Saved hook state: name to a clone of its WP_Hook, or null when there was none.
	 *
	 * @var array<string,\WP_Hook|null>
	 */
	private array $savedHooks = array();

	/**
	 * Build the harness, empty the tables and save the hooks. Deliberately does not start the transaction of the parent class.
	 */
	protected function setUp(): void {
		global $wp_filter;
		foreach ( self::GUARDED_HOOKS as $name ) {
			$this->savedHooks[ $name ] = isset( $wp_filter[ $name ] ) ? clone $wp_filter[ $name ] : null;
		}
		$this->h = new CoreHarness();
		$this->h->reset();
	}

	/**
	 * Put the hooks back and empty the tables again.
	 */
	protected function tearDown(): void {
		global $wp_filter;
		foreach ( $this->savedHooks as $name => $saved ) {
			if ( null === $saved ) {
				unset( $wp_filter[ $name ] );
			} else {
				$wp_filter[ $name ] = $saved;
			}
		}
		$this->savedHooks = array();
		$this->h->reset();
	}

	/**
	 * Collect the arguments of every call to an action.
	 *
	 * @param string $hook   Action name.
	 * @param int    $args   Number of arguments.
	 * @return \ArrayObject<int,array<int,mixed>> Filled as the action fires.
	 */
	protected function listen( string $hook, int $args = 1 ): \ArrayObject {
		$calls = new \ArrayObject();
		add_action(
			$hook,
			static function ( ...$arguments ) use ( $calls ): void {
				$calls[] = $arguments;
			},
			10,
			$args
		);
		return $calls;
	}
}
