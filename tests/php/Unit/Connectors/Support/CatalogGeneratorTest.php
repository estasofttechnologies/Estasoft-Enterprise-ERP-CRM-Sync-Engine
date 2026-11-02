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

	/**
	 * A small plugin root with a catalog and some documents.
	 *
	 * @param array<int,array<string,mixed>> $rows Catalog rows.
	 * @param array<string,string>           $docs Document text by file name.
	 */
	private function fixtureRoot( array $rows, array $docs ): string {
		$root = sys_get_temp_dir() . '/estasoft-catalog-' . bin2hex( random_bytes( 5 ) );
		mkdir( $root . '/includes/Catalog', 0777, true );
		mkdir( $root . '/docs/connectors', 0777, true );
		file_put_contents( $root . '/includes/Catalog/catalog.php', "<?php\nreturn " . estasoft_export( $rows, 0 ) . ";\n" );
		foreach ( $docs as $name => $text ) {
			file_put_contents( $root . '/docs/connectors/' . $name, $text );
		}
		return $root;
	}

	/**
	 * The rows of a generated catalog file, read back by loading it.
	 *
	 * @param string $catalog File text.
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsOf( string $catalog ): array {
		$file = sys_get_temp_dir() . '/estasoft-catalog-out-' . bin2hex( random_bytes( 5 ) ) . '.php';
		file_put_contents( $file, $catalog );
		$rows = require $file;
		unlink( $file );
		return $rows;
	}

	private function removeRoot( string $root ): void {
		foreach ( (array) glob( $root . '/docs/connectors/*' ) as $file ) {
			unlink( $file );
		}
		@rmdir( $root . '/docs/connectors' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test cleaning up its temporary folder.
	}

	private function row( string $name ): array {
		return array( 'name' => $name, 'type' => 'erp', 'regions' => 'Global', 'connector' => null, 'connection_method' => 'key', 'needs' => 'Hand-written needs.', 'wave' => 1 );
	}

	public function test_the_reason_a_system_is_unavailable_goes_into_the_row_and_the_needs_text_stays(): void {
		$root = $this->fixtureRoot(
			array( $this->row( 'Gone' ), $this->row( 'Fine' ) ),
			array(
				'gone.md' => "---\nid: gone\nname: Gone\ncatalog_row: Gone\nconnection_method: none\navailable: false\nunavailable_reason: \"No API is published yet.\"\nhosts: []\n---\n\n## How the merchant connects\n\n1. Wait.\n",
				'fine.md' => "---\nid: fine\nname: Fine\ncatalog_row: Fine\nconnection_method: key\navailable: true\nhosts: [api.fine.test]\n---\n\n## How the merchant connects\n\n1. Paste the key.\n",
			)
		);
		$problems = array();
		$built    = estasoft_catalog_from_docs( $root, $problems );
		$rows     = $this->rowsOf( $built['catalog'] );
		$this->removeRoot( $root );

		$gone = array_column( $rows, null, 'name' )['Gone'];
		$this->assertFalse( $gone['available'] );
		$this->assertFalse( $gone['selectable'] );
		$this->assertSame( 'No API is published yet.', $gone['unavailable_reason'] );
		$this->assertSame( 'Hand-written needs.', $gone['needs'] );
		$this->assertArrayNotHasKey( 'unavailable_reason', array_column( $rows, null, 'name' )['Fine'], 'A system that can be chosen has none.' );
		$this->assertSame( array(), $problems );
	}

	public function test_an_unavailable_document_without_a_reason_is_reported(): void {
		$root = $this->fixtureRoot( array( $this->row( 'Gone' ) ), array( 'gone.md' => "---\nid: gone\nname: Gone\ncatalog_row: Gone\nconnection_method: none\navailable: false\nhosts: []\n---\n\n## How the merchant connects\n\n1. Wait.\n" ) );
		$problems = array();
		estasoft_catalog_from_docs( $root, $problems );
		$this->removeRoot( $root );
		$this->assertStringContainsString( 'no unavailable_reason', implode( ' | ', $problems ) );
	}

	public function test_a_row_without_a_document_keeps_the_reason_it_has(): void {
		$row = $this->row( 'Static' ) + array( 'available' => false, 'selectable' => false, 'unavailable_reason' => 'Kept.' );
		$this->assertSame( 'Kept.', estasoft_row( $row, array() )['unavailable_reason'] );
		$this->assertArrayNotHasKey( 'unavailable_reason', estasoft_row( $this->row( 'Plain' ), array() ) );
	}

	public function test_wildcard_hosts_are_kept_as_patterns_and_normalised(): void {
		$root = $this->fixtureRoot(
			array( $this->row( 'Cloud' ) ),
			array( 'cloud.md' => "---\nid: cloud\nname: Cloud\ncatalog_row: Cloud\nconnection_method: cloud_app\navailable: true\nhosts: [\"*.OracleCloud.com\", \" login.cloud.test \", \"*.fa.oraclecloud.com\"]\n---\n\n## How the merchant connects\n\n1. Sign in.\n" )
		);
		$problems = array();
		$built    = estasoft_catalog_from_docs( $root, $problems );
		$rows     = $this->rowsOf( $built['catalog'] );
		$readme   = $built['readme'];
		$this->removeRoot( $root );

		$this->assertSame( array( '*.oraclecloud.com', 'login.cloud.test', '*.fa.oraclecloud.com' ), $rows[0]['hosts'], 'Lower case, trimmed, and a pattern keeps its *.' );
		$this->assertSame( array(), $problems, 'Wildcard hosts are accepted.' );
		$this->assertStringContainsString( '`*.oraclecloud.com`', $readme );
		$this->assertStringContainsString( 'A host that starts with `*.` is a pattern', $readme );
		$this->assertTrue( estasoft_host_is_pattern( $rows[0]['hosts'][0] ) );
		$this->assertFalse( estasoft_host_is_pattern( $rows[0]['hosts'][1] ) );
	}

	public function test_a_host_that_is_not_a_name_or_a_clean_pattern_is_reported(): void {
		foreach ( array( '*', '*.com', 'a.*.example.com', 'https://api.example.com', 'api.example.com/v1', 'api.example.com:8443', 'api example.com', '*.*.example.com', 'localhost' ) as $bad ) {
			$this->assertNotNull( estasoft_host_problem( $bad ), $bad );
		}
		foreach ( array( 'api.example.com', '*.oraclecloud.com', '*.fa.us2.oraclecloud.com', 'a-b.example.co.uk', 'login.microsoftonline.com' ) as $good ) {
			$this->assertNull( estasoft_host_problem( $good ), $good );
		}
		$root = $this->fixtureRoot( array( $this->row( 'Odd' ) ), array( 'odd.md' => "---\nid: odd\nname: Odd\ncatalog_row: Odd\nconnection_method: key\navailable: true\nhosts: [\"https://odd.test/v1\"]\n---\n\n## How the merchant connects\n\n1. Go.\n" ) );
		$problems = array();
		estasoft_catalog_from_docs( $root, $problems );
		$this->removeRoot( $root );
		$this->assertStringContainsString( 'odd: hosts:', implode( ' | ', $problems ) );
	}
}
