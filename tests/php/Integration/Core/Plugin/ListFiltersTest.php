<?php
/**
 * The filters that extend the lists uninstall works from.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Core\OptionNames;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class ListFiltersTest extends CoreIntegrationTestCase {

	public function test_a_meta_key_added_by_a_filter_is_kept_once(): void {
		add_filter( 'estasoft_sync_meta_keys', static fn( array $keys ): array => array_merge( $keys, array( '_estasoft_sync_state', '_extra' ) ) );
		$keys = MetaKeys::order();
		$this->assertContains( '_extra', $keys );
		$this->assertSame( 1, count( array_keys( $keys, '_estasoft_sync_state', true ) ) );
	}

	public function test_the_option_filter_adds_names_and_prefixes_and_ignores_unsafe_ones(): void {
		add_filter(
			'estasoft_sync_option_names',
			static fn( array $names ): array => array_merge( $names, array( 'estasoft_sync_addon_state', 'estasoft_sync_addon_cache_*', 'other_plugin_option', 'estasoft_sync_*', 'estasoft_sync_', '' ) )
		);
		$this->assertContains( 'estasoft_sync_addon_state', OptionNames::exact() );
		$this->assertContains( 'estasoft_sync_addon_cache_', OptionNames::prefixes() );
		$this->assertNotContains( 'other_plugin_option', OptionNames::all(), 'only names with the plugin prefix are accepted' );
		$this->assertNotContains( 'estasoft_sync_*', OptionNames::all(), 'a bare prefix would match everything' );
		$this->assertNotContains( 'estasoft_sync_', OptionNames::all() );
		$this->assertNotContains( '', OptionNames::all() );
	}
}
