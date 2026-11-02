<?php
/**
 * The lists that uninstall works from.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core;

use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Core\OptionNames;
use PHPUnit\Framework\TestCase;

final class MetaKeysAndOptionNamesTest extends TestCase {

	public function test_order_keys_cover_spec_5_10_and_everything_the_woo_module_writes(): void {
		$keys = MetaKeys::order();
		// Spec 5.10.
		foreach ( array( '_estasoft_sync_state', '_estasoft_sync_external_id', '_estasoft_sync_external_ref', '_estasoft_sync_last_at', '_estasoft_sync_invoice', '_estasoft_sync_tracking' ) as $key ) {
			$this->assertContains( $key, $keys, $key );
		}
		// Woo\OrderMeta::KEYS.
		foreach ( \Estasoft\SyncEngine\Woo\OrderMeta::KEYS as $key ) {
			$this->assertContains( $key, $keys, $key );
		}
	}

	public function test_product_keys_cover_spec_5_10_the_woo_module_and_the_stock_buffer(): void {
		$keys = MetaKeys::product();
		foreach ( array( '_estasoft_sync_exclude', '_estasoft_sync_external_id', '_estasoft_sync_external_ref', '_estasoft_sync_last_stock', '_estasoft_sync_last_stock_at', '_estasoft_sync_match', '_estasoft_sync_stock_buffer' ) as $key ) {
			$this->assertContains( $key, $keys, $key );
		}
	}

	public function test_user_keys_include_the_contact_id_of_spec_5_10(): void {
		$this->assertContains( 'estasoft_sync_external_id', MetaKeys::user() );
		$this->assertContains( 'estasoft_sync_dismissed_notices', MetaKeys::user() );
	}

	public function test_core_and_module_options_are_listed_and_a_prefix_family_ends_with_a_star(): void {
		$exact = OptionNames::exact();
		foreach ( array( 'estasoft_sync_settings', 'estasoft_sync_onboarding', 'estasoft_sync_db_version', 'estasoft_sync_installed_at', 'estasoft_sync_last_run', 'estasoft_sync_capacity', 'estasoft_sync_readiness' ) as $name ) {
			$this->assertContains( $name, $exact, $name );
		}
		$this->assertContains( 'estasoft_sync_rl_', OptionNames::prefixes() );
		$this->assertNotContains( 'estasoft_sync_rl_*', $exact );
	}

	public function test_an_option_that_only_shares_the_prefix_is_not_in_the_list(): void {
		$this->assertNotContains( 'estasoft_sync_pro_license', OptionNames::exact() );
	}
}
