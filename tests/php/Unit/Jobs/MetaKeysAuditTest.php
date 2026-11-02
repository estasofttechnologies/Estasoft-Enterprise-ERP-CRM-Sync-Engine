<?php
/**
 * The Jobs module writes no order, product or user meta key that uninstall would not remove.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;

use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Jobs\JobsModule;
use PHPUnit\Framework\TestCase;

final class MetaKeysAuditTest extends TestCase {

	/**
	 * Every meta key the Jobs source names.
	 *
	 * @return array<string,array<int,string>> Key to the files that name it.
	 */
	private function literals(): array {
		$found    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 4 ) . '/includes/Jobs', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( "/'(_?estasoft_sync_[a-z_]+)'/", $source, $matches ) ) {
				foreach ( $matches[1] as $key ) {
					$found[ $key ][] = basename( $file->getPathname() );
				}
			}
		}
		return $found;
	}

	public function test_every_meta_key_in_the_jobs_source_is_in_the_core_list(): void {
		$listed = array_merge( MetaKeys::order(), MetaKeys::product(), MetaKeys::user() );
		$meta   = array_filter( $this->literals(), static fn( string $key ): bool => str_starts_with( $key, '_estasoft_sync_' ), ARRAY_FILTER_USE_KEY );

		$this->assertNotSame( array(), $meta, 'the scan found the meta keys' );
		foreach ( array_keys( $meta ) as $key ) {
			$this->assertContains( $key, $listed, "meta key {$key} is written by Jobs but missing from Core\\MetaKeys" );
		}
	}

	public function test_the_filter_the_module_adds_covers_every_key_it_writes(): void {
		$module = new JobsModule();
		$order  = $module->addMetaKeys( array(), 'order' );
		$product = $module->addMetaKeys( array(), 'product' );

		$this->assertEqualsCanonicalizing( JobsModule::ORDER_KEYS_WRITTEN, $order );
		$this->assertEqualsCanonicalizing( JobsModule::PRODUCT_KEYS_OWNED, $product );
		$this->assertSame( array( 'x' ), $module->addMetaKeys( array( 'x' ), 'user' ), 'Jobs writes no user meta' );
		$this->assertContains( '_estasoft_sync_tracking_number', $order );
		$this->assertContains( '_estasoft_sync_last_stock_at', $product );
	}

	public function test_nothing_in_jobs_writes_user_meta(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 4 ) . '/includes/Jobs', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$this->assertDoesNotMatchRegularExpression( '/\b(update|add|delete)_user_meta\s*\(/', (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
			}
		}
	}
}
