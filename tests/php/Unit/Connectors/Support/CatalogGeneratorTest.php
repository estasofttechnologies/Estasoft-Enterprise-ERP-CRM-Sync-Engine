<?php
/**
 * The catalog and the connector index are built from the connector documents.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

define( 'ESTASOFT_CATALOG_LIB', true );
require_once dirname( __DIR__, 5 ) . '/bin/generate-catalog-from-docs.php';

use PHPUnit\Framework\TestCase;

final class CatalogGeneratorTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 5 );
	}

	public function test_the_catalog_and_the_index_are_up_to_date(): void {
		$problems = array();
		$built    = estasoft_catalog_from_docs( $this->root(), $problems );
		$this->assertSame( $built['catalog'], (string) file_get_contents( $this->root() . '/includes/Catalog/catalog.php' ), 'Run: php bin/generate-catalog-from-docs.php' );
		$this->assertSame( $built['readme'], (string) file_get_contents( $this->root() . '/docs/connectors/README.md' ), 'Run: php bin/generate-catalog-from-docs.php' );
		$this->assertSame( array(), array_filter( $problems, static fn( string $line ): bool => str_contains( $line, 'is not a row of the catalog' ) || str_contains( $line, 'no id or catalog_row' ) ), 'Every document names a row of the catalog.' );
	}

	public function test_every_catalog_row_has_the_fields_the_screens_read(): void {
		$rows = require $this->root() . '/includes/Catalog/catalog.php';
		$this->assertGreaterThanOrEqual( 30, count( $rows ) );
		foreach ( $rows as $row ) {
			foreach ( array( 'name', 'type', 'regions', 'connector', 'connection_method', 'needs', 'available', 'verified', 'variants', 'hosts', 'terms_url', 'privacy_url', 'developer_url', 'steps', 'wave' ) as $key ) {
				$this->assertArrayHasKey( $key, $row, $row['name'] . ' lacks ' . $key );
			}
			$this->assertFalse( $row['verified'], $row['name'] . ': nothing has been tried on a live account.' );
			$this->assertLessThanOrEqual( ESTASOFT_MAX_STEPS, count( $row['steps'] ), $row['name'] );
			foreach ( $row['variants'] as $variant ) {
				$this->assertArrayHasKey( 'id', $variant );
				$this->assertArrayHasKey( 'label', $variant );
			}
		}
	}

	public function test_the_two_rows_with_several_products_list_them(): void {
		$rows = array_column( require $this->root() . '/includes/Catalog/catalog.php', null, 'name' );
		foreach ( array( 'Sage Business Cloud Accounting and Sage 50' => array( 'sage-accounting', 'sage50-export' ), 'Sage 200, Sage 300 and Sage X3' => array( 'sage200', 'sage300', 'sagex3' ) ) as $name => $ids ) {
			if ( ! isset( $rows[ $name ] ) || array() === $rows[ $name ]['variants'] ) {
				$this->markTestSkipped( 'The Sage documents are not all written yet.' );
			}
			$found = array_column( $rows[ $name ]['variants'], 'id' );
			sort( $found );
			sort( $ids );
			$this->assertSame( $ids, $found, $name );
			$this->assertContains( $rows[ $name ]['connector'], $ids );
		}
	}

	public function test_the_front_matter_reader_handles_the_shapes_the_documents_use(): void {
		$text = "---\nid: daftra\nname: Daftra\ncatalog_row: Daftra   # the row\nconnection_method: key\navailable: true\nverified: false\nhosts: [\"*.daftara.com\", api.daftra.com]\nterms_url: https://example.test/terms    # not verified\nprivacy_url: \"\"\ndeveloper_url: https://example.test/dev#anchor\nvariants: [{ id: a, label: \"Product A, the first\" }, {id: b, label: Product B}]\nsteps_note: 'it''s'\n---\n\n# Body\n";
		$front = estasoft_front_matter( $text );
		$this->assertSame( 'Daftra', $front['catalog_row'] );
		$this->assertTrue( $front['available'] );
		$this->assertFalse( $front['verified'] );
		$this->assertSame( array( '*.daftara.com', 'api.daftra.com' ), $front['hosts'] );
		$this->assertSame( 'https://example.test/terms', $front['terms_url'] );
		$this->assertSame( '', $front['privacy_url'] );
		$this->assertSame( 'https://example.test/dev#anchor', $front['developer_url'], 'A # inside a word is not a comment.' );
		$this->assertSame( array( array( 'id' => 'a', 'label' => 'Product A, the first' ), array( 'id' => 'b', 'label' => 'Product B' ) ), $front['variants'] );
		$this->assertSame( "it's", $front['steps_note'] );
		$this->assertSame( array(), estasoft_front_matter( "# no front matter\n" ) );
	}

	public function test_block_lists_and_empty_lists(): void {
		$front = estasoft_front_matter( "---\nid: x\nhosts: []\nvariants:\n  - id: a\n    label: A\n  - id: b\n    label: B\n---\n" );
		$this->assertSame( array(), $front['hosts'] );
		$this->assertSame( array( array( 'id' => 'a', 'label' => 'A' ), array( 'id' => 'b', 'label' => 'B' ) ), $front['variants'] );
	}

	public function test_steps_come_from_the_numbered_list_with_the_limits_enforced(): void {
		$long     = str_repeat( 'x', 141 );
		$doc      = "## 2. How the merchant connects\n\n1. Open `Settings` in Xero.\n2. Make an app.\n   It needs the redirect address.\n3. Third.\n4. Fourth.\n5. Fifth.\n6. Sixth.\n7. Seventh {$long}.\n\nSome note.\n\n## 3. Authentication\n\n1. Not a step.\n";
		$problems = array();
		$steps    = estasoft_steps( $doc, 'demo', $problems );
		$this->assertCount( 6, $steps );
		$this->assertSame( 'Open `Settings` in Xero.', $steps[0] );
		$this->assertSame( 'Make an app. It needs the redirect address.', $steps[1], 'A continuation line joins the step.' );
		$this->assertStringContainsString( '7 steps', $problems[0] );

		$problems = array();
		estasoft_steps( "## How the merchant connects\n\n1. {$long}\n", 'demo', $problems );
		$this->assertStringContainsString( 'has 141 characters', $problems[0] );

		$problems = array();
		$this->assertSame( array(), estasoft_steps( "## Other\n", 'demo', $problems ) );
		$this->assertStringContainsString( "no section 'How the merchant connects'", $problems[0] );
	}

	public function test_the_writer_makes_valid_php_that_reads_back_the_same(): void {
		$value = array(
			'name'    => "Zoho's \\ books",
			'flags'   => array( true, false, null, 3 ),
			'nested'  => array( array( 'id' => 'a', 'steps' => array( 'one', 'two' ) ) ),
			'empty'   => array(),
		);
		$php = 'return ' . estasoft_export( $value, 0 ) . ';';
		$this->assertSame( $value, eval( $php ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- reads back a generated literal in a test.
	}
}
