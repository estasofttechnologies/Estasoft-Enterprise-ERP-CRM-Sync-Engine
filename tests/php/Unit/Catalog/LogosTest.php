<?php
/**
 * The logo of a system: found by connector id, and absent (letter tile) when the file is not there.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Catalog;

use Estasoft\SyncEngine\Catalog\Logos;
use PHPUnit\Framework\TestCase;

final class LogosTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 4 ) . '/';
	}

	public function test_a_connector_with_a_file_gets_its_address(): void {
		$this->assertSame( 'https://example.test/wp-content/plugins/x/assets/logos/xero.png', Logos::urlFor( 'xero', $this->root(), 'https://example.test/wp-content/plugins/x/' ) );
	}

	public function test_a_connector_without_a_file_gets_nothing(): void {
		$this->assertSame( '', Logos::urlFor( 'qoyod', $this->root(), 'https://example.test/' ) );
		$this->assertSame( '', Logos::urlFor( 'xero', sys_get_temp_dir() . '/no-such-plugin/', 'https://example.test/' ) );
	}

	public function test_the_products_of_one_vendor_share_its_file(): void {
		$this->assertSame( 'sage-accounting.png', Logos::fileFor( 'sage200' ) );
		$this->assertSame( 'sage-accounting.png', Logos::fileFor( 'sagex3' ) );
		$this->assertNotSame( '', Logos::urlFor( 'sage300', $this->root(), 'https://example.test/' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function badIds(): array {
		return array(
			'empty'          => array( '' ),
			'a path'         => array( '../../wp-config' ),
			'a slash'        => array( 'a/b' ),
			'capital'        => array( 'Xero' ),
			'a dot'          => array( 'xero.png' ),
			'a double dash'  => array( 'a--b' ),
			'trailing dash'  => array( 'xero-' ),
		);
	}

	/**
	 * @dataProvider badIds
	 */
	public function test_an_id_that_cannot_be_a_file_name_gets_nothing( string $id ): void {
		$this->assertSame( '', Logos::fileFor( $id ) );
		$this->assertSame( '', Logos::urlFor( $id, $this->root(), 'https://example.test/' ) );
	}

	public function test_every_file_in_the_folder_belongs_to_a_catalog_connector(): void {
		$rows = require $this->root() . 'includes/Catalog/catalog.php';
		$ids  = array();
		foreach ( $rows as $row ) {
			$ids[] = $row['connector'] ?? '';
			foreach ( $row['variants'] ?? array() as $variant ) {
				$ids[] = $variant['id'] ?? '';
			}
		}
		$files = array_map( 'basename', glob( $this->root() . Logos::DIRECTORY . '*.png' ) ?: array() );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertContains( basename( $file, '.png' ), $ids, "$file does not match any connector id" );
			$size = getimagesize( $this->root() . Logos::DIRECTORY . $file );
			$this->assertSame( array( 160, 160 ), array( $size[0] ?? 0, $size[1] ?? 0 ), "$file must be 160 x 160" );
		}
	}
}
