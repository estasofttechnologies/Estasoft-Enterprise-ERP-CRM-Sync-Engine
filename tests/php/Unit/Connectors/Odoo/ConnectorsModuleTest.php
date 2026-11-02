<?php
/**
 * Registration of the Odoo connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\ConnectorsModule;
use Estasoft\SyncEngine\Connectors\Odoo\OdooConnector;
use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ConnectorsModuleTest extends OdooTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['estasoft_test_filters'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['estasoft_test_filters'] );
		parent::tearDown();
	}

	private function boot(): Container {
		$container = new Container();
		$container->instance( 'core.http_client', new FakeHttpClient() );
		$module = new ConnectorsModule();
		$module->services( $container );
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

	public function test_it_adds_odoo_to_the_registration_filter_before_add_ons_at_the_default_priority(): void {
		$this->boot();
		$this->assertArrayHasKey( 'estasoft_sync_register_connectors', $GLOBALS['estasoft_test_filters'] );
		$this->assertSame( array( 5 ), array_keys( $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'] ) );

		$callback   = $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'][5][0];
		$connectors = $callback( array() );
		$this->assertCount( 1, $connectors );
		$this->assertInstanceOf( OdooConnector::class, $connectors[0] );

		$registry = new ConnectorRegistry( $connectors );
		$this->assertNotNull( $registry->get( 'odoo' ) );
	}

	public function test_it_keeps_connectors_other_plugins_already_added_and_survives_a_bad_filter_value(): void {
		$this->boot();
		$callback = $GLOBALS['estasoft_test_filters']['estasoft_sync_register_connectors'][5][0];
		$other    = $this->connector( new FakeHttpClient() );
		$this->assertCount( 2, $callback( array( $other ) ) );
		$this->assertCount( 1, $callback( 'not an array' ) );
	}

	public function test_nothing_is_registered_when_the_module_is_only_constructed(): void {
		new ConnectorsModule();
		$this->assertSame( array(), $GLOBALS['estasoft_test_filters'] );
	}
}
