<?php
/**
 * Registration of the connectors.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

require_once dirname( __DIR__, 3 ) . '/Support/ApiConnectorsNamespaceFunctions.php';

use Estasoft\SyncEngine\Connectors\ConnectorsModule;
use Estasoft\SyncEngine\Connectors\Odoo\OdooConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshTask;
use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ConnectorsModuleTest extends OdooTestCase {

	private ?ApiTestKit $kit = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['estasoft_test_filters'] = array();
		$GLOBALS['estasoft_test_actions'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['estasoft_test_filters'], $GLOBALS['estasoft_test_actions'] );
		$this->kit?->cleanup();
		parent::tearDown();
	}

	private function boot(): Container {
		$this->kit = ApiTestKit::make();
		$container = new Container();
		$module    = new ConnectorsModule( array( 'odoo' => \Estasoft\SyncEngine\Connectors\Odoo\OdooProvider::class ) );
		$module->services( $container );
		$container->instance( ConnectorsModule::DEPENDENCIES, $this->kit->deps );
		$module->hooks( $container );
		return $container;
	}

	public function test_it_is_a_module(): void {
		$this->assertInstanceOf( ModuleInterface::class, new ConnectorsModule() );
	}

	public function test_the_odoo_connector_is_a_lazy_service_built_with_the_engine_http_client(): void {
		$container = $this->boot();
		$this->assertTrue( $container->has( ConnectorsModule::ODOO ) );
		$this->assertInstanceOf( OdooConnector::class, $container->get( ConnectorsModule::ODOO ) );
		$this->assertSame( $container->get( ConnectorsModule::ODOO ), $container->get( ConnectorsModule::ODOO ) );
	}

	public function test_it_adds_the_providers_to_the_registration_filter_before_add_ons_at_the_default_priority(): void {
		$container = $this->boot();
		$this->assertArrayHasKey( 'estasoft_sync_register_connectors', $GLOBALS['estasoft_test_filters'] );
		$this->assertSame( array( 5 ), array_keys( $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'] ) );

		$callback   = $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'][5][0];
		$connectors = $callback( array() );
		$this->assertSame( array( 'odoo' ), array_keys( $connectors ) );
		$this->assertInstanceOf( \Closure::class, $connectors['odoo'], 'A connector is built the first time it is asked for.' );

		$registry = new ConnectorRegistry( $connectors );
		$this->assertSame( array( 'odoo' ), $registry->ids() );
		$this->assertInstanceOf( OdooConnector::class, $registry->get( 'odoo' ) );
		$this->assertSame( $container->get( ConnectorsModule::ODOO ), $registry->get( 'odoo' ) );
	}

	public function test_it_keeps_connectors_other_plugins_already_added_and_survives_a_bad_filter_value(): void {
		$this->boot();
		$callback = $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'][5][0];
		$other    = $this->connector( new FakeHttpClient() );
		$this->assertCount( 2, $callback( array( $other ) ) );
		$this->assertCount( 1, $callback( 'not an array' ) );
	}

	public function test_an_add_on_with_the_same_id_replaces_the_shipped_connector(): void {
		$this->boot();
		$callback   = $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'][5][0];
		$replacement = $this->connector( new FakeHttpClient() );
		$registry   = new ConnectorRegistry( array_merge( $callback( array() ), array( $replacement ) ) );
		$this->assertSame( $replacement, $registry->get( 'odoo' ) );
	}

	public function test_it_schedules_the_token_refresh_every_five_minutes_and_cleans_old_exports_with_the_daily_task(): void {
		$this->boot();
		$callback = $GLOBALS['estasoft_test_filters']['estasoft_sync_scheduled_tasks'][10][0];
		$tasks    = $callback( array( 'x' => 60 ) );
		$this->assertSame( 300, $tasks[ RefreshTask::HOOK ] );
		$this->assertSame( 60, $tasks['x'] );
		$this->assertArrayHasKey( RefreshTask::HOOK, $GLOBALS['estasoft_test_actions'] );
		$this->assertArrayHasKey( 'estasoft_sync_prune_data', $GLOBALS['estasoft_test_actions'] );

		$names = $GLOBALS['estasoft_test_filters']['estasoft_sync_option_names'][10][0]( array() );
		$this->assertContains( 'estasoft_sync_lock_*', $names );
	}

	public function test_nothing_is_registered_when_the_module_is_only_constructed(): void {
		new ConnectorsModule();
		$this->assertSame( array(), $GLOBALS['estasoft_test_filters'] );
	}
}
