<?php
/**
 * The nine tables and the migrator.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Database;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Database\Migrator;
use Estasoft\SyncEngine\Core\Database\Schema;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Support\WpOptionStore;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class SchemaTest extends CoreIntegrationTestCase {

	private Migrator $migrator;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->migrator = new Migrator( new Schema( $this->h->tables, $wpdb->get_charset_collate() ), $this->h->tables, new WpOptionStore(), $wpdb );
	}

	protected function tearDown(): void {
		// Leave the database as the activation left it, whatever a test did.
		$this->migrator->migrate();
		parent::tearDown();
	}

	public function test_all_nine_tables_exist(): void {
		$expected = array( 'connections', 'queue', 'logs', 'mappings', 'id_map', 'state', 'idempotency', 'recon_runs', 'recon_diffs' );
		$this->assertSame( $expected, array_keys( $this->h->tables->all() ) );
		foreach ( $this->h->tables->all() as $logical => $table ) {
			$this->assertSame( $table, $this->h->wpdb->get_var( $this->h->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $logical );
			$this->assertStringStartsWith( $this->h->wpdb->prefix . 'estasoft_sync_', $table );
		}
		$this->assertTrue( $this->migrator->allTablesExist() );
	}

	public function test_tables_are_utf8mb4(): void {
		foreach ( $this->h->tables->all() as $table ) {
			$create = (string) $this->h->wpdb->get_var( "SHOW CREATE TABLE `{$table}`", 1 ); // phpcs:ignore
			$this->assertStringContainsString( 'utf8mb4', $create, $table );
		}
	}

	public function test_arabic_and_emoji_text_round_trips_through_the_log_table(): void {
		$id = $this->h->logs->write( array( 'level' => 'info', 'message' => 'نور المنصوري ☕ 📦', 'entity_ref' => 'order:1' ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'نور المنصوري ☕ 📦', $this->h->logs->find( $id )['message'] );
	}

	/**
	 * @dataProvider indexes
	 *
	 * @param array<int,string> $columns Columns of the index, in order.
	 */
	public function test_the_indexes_the_queries_rely_on_exist( string $logical, string $index, array $columns, bool $unique ): void {
		$table = $this->h->tables->all()[ $logical ];
		$rows  = $this->h->wpdb->get_results( $this->h->wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ), ARRAY_A ); // phpcs:ignore
		$this->assertNotEmpty( $rows, "$logical.$index is missing" );
		usort( $rows, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		$this->assertSame( $columns, array_column( $rows, 'Column_name' ) );
		$this->assertSame( $unique ? '0' : '1', (string) $rows[0]['Non_unique'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:array<int,string>,3:bool}>
	 */
	public static function indexes(): array {
		return array(
			'queue claim'          => array( 'queue', 'claim', array( 'state', 'next_attempt_at' ), false ),
			'queue partition'      => array( 'queue', 'partition_order', array( 'connection_id', 'partition_key', 'id' ), false ),
			'queue lock'           => array( 'queue', 'locked', array( 'state', 'locked_until' ), false ),
			'queue entity'         => array( 'queue', 'entity', array( 'entity_type', 'entity_id' ), false ),
			'idempotency key'      => array( 'idempotency', 'idempotency_key', array( 'idempotency_key' ), true ),
			'mapping version'      => array( 'mappings', 'version_key', array( 'connection_id', 'type', 'version' ), true ),
			'id map wc'            => array( 'id_map', 'wc_key', array( 'connection_id', 'entity_type', 'wc_id' ), true ),
			'state wc'             => array( 'state', 'wc_key', array( 'connection_id', 'entity_type', 'wc_id' ), true ),
			'logs time'            => array( 'logs', 'ts', array( 'ts' ), false ),
			'connections status'   => array( 'connections', 'status', array( 'status' ), false ),
		);
	}

	public function test_running_the_migration_again_changes_nothing(): void {
		$before = $this->h->wpdb->get_var( "SHOW CREATE TABLE `{$this->h->tables->queue()}`", 1 ); // phpcs:ignore
		$id     = $this->h->connection();
		$this->assertTrue( $this->migrator->migrate() );
		$this->assertTrue( $this->migrator->migrate() );
		$this->assertSame( $before, $this->h->wpdb->get_var( "SHOW CREATE TABLE `{$this->h->tables->queue()}`", 1 ) ); // phpcs:ignore
		$this->assertNotNull( $this->h->connections->findPublic( $id ), 'data survives a migration' );
		$this->assertSame( Schema::VERSION, get_option( Migrator::OPTION ) );
	}

	public function test_a_site_with_an_older_version_is_upgraded_and_stamped(): void {
		update_option( Migrator::OPTION, '0.0.1' );
		$this->assertTrue( $this->migrator->needsUpgrade() );

		$this->h->sql( "DROP TABLE `{$this->h->tables->reconDiffs()}`" ); // phpcs:ignore
		$this->assertFalse( $this->migrator->allTablesExist() );

		$this->assertTrue( $this->migrator->maybeMigrate() );
		$this->assertTrue( $this->migrator->allTablesExist() );
		$this->assertSame( Schema::VERSION, $this->migrator->storedVersion() );
		$this->assertFalse( $this->migrator->needsUpgrade() );
		$this->assertFalse( $this->migrator->maybeMigrate(), 'nothing to do the second time' );
	}

	public function test_a_missing_column_is_added_back_by_the_upgrade(): void {
		$this->h->sql( "ALTER TABLE `{$this->h->tables->mappings()}` DROP COLUMN based_on" ); // phpcs:ignore
		$this->assertNull( $this->h->wpdb->get_var( "SHOW COLUMNS FROM `{$this->h->tables->mappings()}` LIKE 'based_on'" ) ); // phpcs:ignore
		$this->assertTrue( $this->migrator->migrate() );
		$this->assertSame( 'based_on', $this->h->wpdb->get_var( "SHOW COLUMNS FROM `{$this->h->tables->mappings()}` LIKE 'based_on'" ) ); // phpcs:ignore
	}

	public function test_the_migration_announces_itself_so_capabilities_can_be_granted(): void {
		$calls = $this->listen( 'estasoft_sync_migrated', 2 );
		update_option( Migrator::OPTION, '0.0.1' );
		$this->migrator->maybeMigrate();
		$this->assertCount( 1, $calls );
		$this->assertSame( '0.0.1', $calls[0][0] );
		$this->assertSame( Schema::VERSION, $calls[0][1] );
		remove_all_actions( 'estasoft_sync_migrated' );
	}

	public function test_table_names_are_derived_from_the_prefix(): void {
		$tables = new Tables( 'wp_7_' );
		$this->assertSame( 'wp_7_estasoft_sync_queue', $tables->queue() );
		$this->assertCount( 9, $tables->all() );
	}
}
