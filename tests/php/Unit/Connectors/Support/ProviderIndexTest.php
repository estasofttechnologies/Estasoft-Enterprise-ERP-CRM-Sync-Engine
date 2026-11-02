<?php
/**
 * providers.php is generated; the build fails when it is out of date.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

define( 'ESTASOFT_INDEX_LIB', true );
require_once dirname( __DIR__, 5 ) . '/bin/generate-connector-index.php';

use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use PHPUnit\Framework\TestCase;

final class ProviderIndexTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 5 );
	}

	public function test_providers_php_is_up_to_date(): void {
		$problems = array();
		$expected = estasoft_generate_connector_index( $this->root(), $problems );
		$actual   = (string) file_get_contents( $this->root() . '/includes/Connectors/providers.php' );
		$this->assertSame( array(), $problems, 'Every provider must load.' );
		$this->assertSame( $expected, $actual, 'Run: php bin/generate-connector-index.php' );
	}

	public function test_it_lists_sorted_unique_ids_that_point_at_providers(): void {
		$list = require $this->root() . '/includes/Connectors/providers.php';
		$this->assertIsArray( $list );
		$ids = array_keys( $list );
		$sorted = $ids;
		sort( $sorted );
		$this->assertSame( $sorted, $ids );
		$this->assertArrayHasKey( 'odoo', $list );
		foreach ( $list as $id => $class ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $id );
			$this->assertTrue( is_subclass_of( $class, ConnectorProvider::class ), $class );
			$this->assertSame( $id, ( new $class() )->id(), 'The provider says its own id.' );
		}
	}

	public function test_every_provider_file_is_in_the_index(): void {
		$list    = require $this->root() . '/includes/Connectors/providers.php';
		$files   = glob( $this->root() . '/includes/Connectors/*/*Provider.php' );
		$found   = 0;
		$problems = array();
		estasoft_generate_connector_index( $this->root(), $problems );
		$this->assertSame( array(), $problems, 'Every provider must load.' );
		foreach ( (array) $files as $file ) {
			if ( 'Support' === basename( dirname( $file ) ) ) {
				continue;
			}
			$class = 'Estasoft\\SyncEngine\\Connectors\\' . basename( dirname( $file ) ) . '\\' . basename( $file, '.php' );
			if ( is_subclass_of( $class, ConnectorProvider::class ) ) {
				++$found;
				$this->assertContains( $class, $list, $class . ' is missing from providers.php.' );
			}
		}
		$this->assertCount( $found, $list );
	}

	public function test_the_output_is_deterministic(): void {
		$this->assertSame( estasoft_generate_connector_index( $this->root() ), estasoft_generate_connector_index( $this->root() ) );
	}
}
