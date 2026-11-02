<?php
/**
 * The plugin boots inside a real WordPress.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration;

use Estasoft\SyncEngine\Plugin;
use Estasoft\SyncEngine\Tests\Support\IntegrationTestCase;

final class BootTest extends IntegrationTestCase {

	public function test_plugin_is_loaded_and_has_a_container(): void {
		$this->assertTrue( defined( 'ESTASOFT_SYNC_VERSION' ) );
		$this->assertSame( '0.1.0', ESTASOFT_SYNC_VERSION );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Container::class, Plugin::instance()->container() );
	}

	public function test_requirements_guard_passes_with_the_woocommerce_stub(): void {
		$this->assertSame( '', estasoft_sync_missing_requirement() );
	}

	public function test_the_requirement_check_gives_a_code_and_translates_nothing(): void {
		$this->assertSame( '', estasoft_sync_unmet_requirement( true ) );
		$this->assertSame( '', estasoft_sync_unmet_requirement( false ) );
	}
}
