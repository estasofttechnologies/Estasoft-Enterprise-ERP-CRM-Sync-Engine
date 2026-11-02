<?php
/**
 * The module wires the services and the hooks.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\CoreModule;
use Estasoft\SyncEngine\Core\Database\Migrator;
use Estasoft\SyncEngine\Core\Installer;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Core\Queue\Worker;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Plugin;
use Estasoft\SyncEngine\Tests\Support\CoreFakeConnector;
use Estasoft\SyncEngine\Tests\Support\CoreFakePayloadBuilder;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class CoreModuleTest extends CoreIntegrationTestCase {

	/**
	 * A container filled by the module alone.
	 */
	private function container(): Container {
		$container = new Container();
		( new CoreModule() )->services( $container );
		return $container;
	}

	/**
	 * Every key the module registers.
	 *
	 * @return array<int,string>
	 */
	private function keys(): array {
		$keys = array();
		foreach ( ( new \ReflectionClass( Services::class ) )->getConstants() as $name => $value ) {
			if ( 'PAYLOAD_BUILDER' !== $name ) {
				$keys[] = (string) $value;
			}
		}
		return $keys;
	}

	public function test_the_booted_plugin_has_the_core_module_loaded(): void {
		$this->assertContains( CoreModule::class, Plugin::moduleClasses() );
		$container = Plugin::instance()->container();
		foreach ( $this->keys() as $key ) {
			$this->assertTrue( $container->has( $key ), $key );
		}
	}

	public function test_every_service_can_be_created(): void {
		$container = $this->container();
		foreach ( $this->keys() as $key ) {
			$this->assertIsObject( $container->get( $key ), $key );
		}
	}

	public function test_services_are_shared_and_also_found_by_class_name(): void {
		$container = $this->container();
		$this->assertSame( $container->get( Services::QUEUE_REPOSITORY ), $container->get( Services::QUEUE_REPOSITORY ) );
		$this->assertSame( $container->get( Services::QUEUE_REPOSITORY ), $container->get( QueueRepository::class ) );
		$this->assertSame( $container->get( Services::ENQUEUER ), $container->get( Enqueuer::class ) );
		$this->assertSame( $container->get( Services::HTTP_CLIENT ), $container->get( HttpClientInterface::class ) );
		$this->assertInstanceOf( Worker::class, $container->get( Services::WORKER ) );
	}

	public function test_the_payload_builder_is_not_the_core_modules_to_provide(): void {
		$container = $this->container();
		$this->assertFalse( $container->has( Services::PAYLOAD_BUILDER ) );
		$this->assertSame( 'payload_builder', Services::PAYLOAD_BUILDER );
	}

	public function test_connectors_come_from_the_register_filter_and_wrong_versions_are_refused(): void {
		$good          = new CoreFakeConnector( null, 'good' );
		$future        = new CoreFakeConnector( null, 'future' );
		$future->version = '2.0.0';
		add_filter( 'estasoft_sync_register_connectors', static fn( array $list ): array => array_merge( $list, array( $good, $future, 'not a connector' ) ) );
		$registry = $this->container()->get( Services::CONNECTOR_REGISTRY );
		remove_all_filters( 'estasoft_sync_register_connectors' );

		$this->assertInstanceOf( ConnectorRegistry::class, $registry );
		$this->assertSame( $good, $registry->get( 'good' ) );
		$this->assertNull( $registry->get( 'future' ) );
		$this->assertSame( array( 'future' => 'contract_version' ), $registry->rejected() );
	}

	public function test_the_real_hooks_are_registered_by_the_booted_plugin(): void {
		$this->assertNotFalse( has_action( Scheduler::HOOK_RUN ) );
		$this->assertNotFalse( has_action( Scheduler::HOOK_REAP ) );
		$this->assertNotFalse( has_action( Scheduler::HOOK_PROBE ) );
		$this->assertNotFalse( has_action( Scheduler::HOOK_PRUNE ) );
		$this->assertNotFalse( has_action( Scheduler::HOOK_PURGE ) );
		$this->assertNotFalse( has_filter( 'cron_schedules', array( Scheduler::class, 'cronSchedules' ) ) );
		$this->assertNotFalse( has_action( 'estasoft_sync_migrated', array( Capabilities::class, 'grant' ) ) );
		$this->assertNotFalse( has_action( 'wp_initialize_site', array( Installer::class, 'installNewSite' ) ) );
		$this->assertNotFalse( has_action( 'estasoft_sync_settings_updated' ) );
		$this->assertNotFalse( has_action( 'plugins_loaded' ) );
		$this->assertNotFalse( has_action( 'admin_init' ) );
	}

	public function test_capabilities_are_granted_again_when_the_schema_is_upgraded(): void {
		$container = Plugin::instance()->container();
		Capabilities::revoke();
		update_option( Migrator::OPTION, '0.0.1' );
		$this->assertTrue( $container->get( Services::MIGRATOR )->maybeMigrate() );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CONFIGURE ) );
	}

	public function test_turning_redaction_off_is_itself_logged_and_so_is_turning_it_back_on(): void {
		$container = Plugin::instance()->container();
		$settings  = $container->get( Services::SETTINGS );
		$this->assertInstanceOf( Settings::class, $settings );
		$before = get_option( Settings::OPTION );
		wp_set_current_user( 1 );
		try {
			$settings->update( array( 'redaction' => false ) );
			$settings->update( array( 'redaction' => false ) );
			$settings->update( array( 'redaction' => true ) );
		} finally {
			wp_set_current_user( 0 );
			update_option( Settings::OPTION, $before, false );
		}
		$messages = array_column( $this->h->logs->latest( 5 ), 'message', 'level' );
		$entries  = $this->h->logs->latest( 5 );
		$this->assertCount( 2, $entries, 'one entry for off, one for on, none for saving the same value again' );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertSame( 'Redaction was turned on by user #1.', $entries[0]['message'] );
		$this->assertSame( 'warning', $entries[1]['level'] );
		$this->assertStringStartsWith( 'Redaction was turned off by user #1.', $entries[1]['message'] );
		$this->assertNotEmpty( $messages );
	}

	public function test_an_order_event_goes_from_enqueue_to_synced_through_the_container_alone(): void {
		$connector = new CoreFakeConnector( null, 'fake' );
		$builder   = new CoreFakePayloadBuilder();
		add_filter( 'estasoft_sync_register_connectors', static fn( array $list ): array => array_merge( $list, array( $connector ) ) );
		$container = $this->container();
		$container->instance( Services::PAYLOAD_BUILDER, $builder );

		$connection = $container->get( Services::CONNECTION_REPOSITORY )->create(
			array( 'connector' => 'fake', 'type' => 'erp', 'label' => 'Fake', 'address' => 'https://erp.example.test' ),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array( 'requests_per_second' => 50 )
		);
		$id = $container->get( Services::ENQUEUER )->enqueue( EntityType::Order, '10490', 'created', null, array( 'label' => '10490' ) );
		$this->assertNotNull( $id );
		$this->assertSame( 0, $connector->count( 'send' ), 'enqueue sent nothing' );

		$report = $container->get( Services::WORKER )->run();
		remove_all_filters( 'estasoft_sync_register_connectors' );

		$this->assertSame( 1, $report->synced );
		$row = $container->get( Services::QUEUE_REPOSITORY )->findRow( $id );
		$this->assertSame( 'synced', $row['state'] );
		$this->assertSame( $connection, $row['connection_id'] );
		$this->assertSame( 'S90001', $row['external_ref'] );
		$this->assertSame( 'Sent to Fakesystem.', $container->get( Services::LOG_REPOSITORY )->latest( 1 )[0]['message'] );
	}

	public function test_without_a_mapping_module_an_item_fails_with_sync_cfg_001_instead_of_crashing(): void {
		$connector = new CoreFakeConnector( null, 'fake' );
		add_filter( 'estasoft_sync_register_connectors', static fn( array $list ): array => array_merge( $list, array( $connector ) ) );
		$container = $this->container();
		$container->get( Services::CONNECTION_REPOSITORY )->create( array( 'connector' => 'fake' ), array(), array( 'requests_per_second' => 50 ) );
		$id = $container->get( Services::ENQUEUER )->enqueue( EntityType::Order, '10490', 'created' );
		$container->get( Services::WORKER )->run();
		remove_all_filters( 'estasoft_sync_register_connectors' );

		$row = $container->get( Services::QUEUE_REPOSITORY )->findRow( $id );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 'SYNC-CFG-001', $row['error_code'] );
		$this->assertSame( 0, $connector->count( 'send' ) );
	}

	public function test_the_container_built_rate_limiter_keeps_its_counters_in_the_database_without_an_object_cache(): void {
		$this->assertFalse( (bool) wp_using_ext_object_cache() );
		$limiter = $this->container()->get( Services::RATE_LIMITER );
		$this->assertEqualsWithDelta( 0.0, $limiter->acquire( 77, 2.0 ), 0.01 );
		$this->assertEqualsWithDelta( 0.5, $limiter->acquire( 77, 2.0 ), 0.05 );
		$rows = (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM {$this->h->wpdb->options} WHERE option_name LIKE 'estasoft\\_sync\\_rl\\_%'" ); // phpcs:ignore
		$this->assertSame( 1, $rows );
		$this->assertSame( 'no', $this->h->wpdb->get_var( "SELECT autoload FROM {$this->h->wpdb->options} WHERE option_name LIKE 'estasoft\\_sync\\_rl\\_%' LIMIT 1" ) ); // phpcs:ignore
	}
}
