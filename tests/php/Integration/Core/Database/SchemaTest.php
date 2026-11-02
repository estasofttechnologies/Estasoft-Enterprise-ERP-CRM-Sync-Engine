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
use Estasoft\SyncEngine\Core\Database\Upgrades;
use Estasoft\SyncEngine\Domain\EntityType;
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
			'queue claim'          => array( 'queue', 'claim', array( 'state', 'next_attempt_at', 'priority' ), false ),
			'queue unique key'     => array( 'queue', 'connection_key', array( 'connection_id', 'idempotency_key' ), true ),
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

	public function test_the_columns_of_spec_5_2_and_5_3_exist(): void {
		$queue = $this->columns( $this->h->tables->queue() );
		$this->assertSame( 'tinyint(3) unsigned', $queue['priority']['Type'] );
		$this->assertSame( '5', $queue['priority']['Default'] );
		$this->assertSame( 'tinyint(3) unsigned', $queue['reaps']['Type'] );
		$this->assertSame( '0', $queue['reaps']['Default'] );
		$this->assertSame( 'varchar(500)', $queue['error_message']['Type'] );

		$logs = $this->columns( $this->h->tables->logs() );
		$this->assertSame( 'datetime(3)', $logs['ts']['Type'] );
		$this->assertSame( 'varchar(500)', $logs['message']['Type'] );

		$connections = $this->columns( $this->h->tables->connections() );
		$this->assertArrayHasKey( 'disconnected_at', $connections );
	}

	public function test_two_items_cannot_share_a_connection_and_a_key(): void {
		$conn = $this->h->connection();
		$one  = $this->h->queue->enqueue( $conn, EntityType::Order, '1', 'created', 'order:1', str_repeat( 'a', 64 ) );
		$this->assertGreaterThan( 0, $one );

		$suppress = $this->h->wpdb->suppress_errors( true );
		$now      = '2026-11-02 12:00:00';
		$ok       = $this->h->wpdb->insert(
			$this->h->tables->queue(),
			array(
				'connection_id'   => $conn,
				'entity_type'     => 'order',
				'entity_id'       => '1',
				'event'           => 'created',
				'partition_key'   => 'order:1',
				'idempotency_key' => str_repeat( 'a', 64 ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$this->h->wpdb->suppress_errors( $suppress );
		$this->assertFalse( $ok, 'the database itself refuses the second row' );

		$other = $this->h->connection();
		$this->assertGreaterThan( 0, $this->h->queue->enqueue( $other, EntityType::Order, '1', 'created', 'order:1', str_repeat( 'a', 64 ) ), 'the same key on another connection is fine' );
	}

	public function test_a_site_on_the_first_schema_is_upgraded_in_place_and_the_upgrade_can_run_again(): void {
		$queue = $this->h->tables->queue();
		$logs  = $this->h->tables->logs();
		$conns = $this->h->tables->connections();
		$conn  = $this->h->connection( array(), array( 'api_key' => 'sk-test-abcdefghijklmnop' ) );
		$other = $this->h->connection();

		// Put the three tables back in the shape of schema 1.0.0.
		$this->h->sql( "ALTER TABLE `$queue` DROP INDEX `connection_key`, DROP INDEX `claim`" ); // phpcs:ignore
		$this->h->sql( "ALTER TABLE `$queue` ADD KEY `claim` (state, next_attempt_at), ADD KEY `idempotency` (idempotency_key), DROP COLUMN priority, DROP COLUMN reaps, MODIFY error_message text NULL" ); // phpcs:ignore
		$this->h->sql( "ALTER TABLE `$logs` MODIFY ts datetime NOT NULL, MODIFY message text NOT NULL" ); // phpcs:ignore
		$this->h->sql( "ALTER TABLE `$conns` DROP COLUMN disconnected_at" ); // phpcs:ignore
		$this->h->sql( "UPDATE `$conns` SET secret_hints_json = '{\"api_key\":\"mnop\",\"password\":\"\"}' WHERE id = $conn" ); // phpcs:ignore

		$long = str_repeat( 'x', 900 );
		$now  = '2026-11-02 12:00:00';
		$rows = array(
			array( $conn, '1', 'k1', $long ),
			array( $conn, '1', 'k1', 'second row with the same key' ),
			array( $conn, '1', 'k1', 'third row with the same key' ),
			array( $conn, '2', 'k2', 'unique' ),
			array( $other, '1', 'k1', 'same key, another connection' ),
		);
		foreach ( $rows as $row ) {
			// Plain SQL: wpdb->insert() checks values against column lengths it cached from the other tests' schema.
			$this->h->sql(
				$this->h->wpdb->prepare(
					"INSERT INTO `$queue` (connection_id, entity_type, entity_id, event, partition_key, idempotency_key, error_message, created_at, updated_at) VALUES (%d, 'order', %s, 'created', %s, %s, %s, %s, %s)", // phpcs:ignore
					$row[0],
					$row[1],
					'order:' . $row[1],
					str_pad( $row[2], 64, '0' ),
					$row[3],
					$now,
					$now
				)
			);
		}
		$this->h->sql( $this->h->wpdb->prepare( "INSERT INTO `$logs` (ts, level, message) VALUES (%s, 'info', %s)", $now, $long ) ); // phpcs:ignore
		update_option( Migrator::OPTION, '1.0.0' );

		$this->assertTrue( $this->migrator->maybeMigrate(), 'the upgrade runs' );

		$this->assertSame( array( 'state', 'next_attempt_at', 'priority' ), $this->indexColumns( $queue, 'claim' ) );
		$this->assertSame( array( 'connection_id', 'idempotency_key' ), $this->indexColumns( $queue, 'connection_key' ) );
		$this->assertSame( array(), $this->indexColumns( $queue, 'idempotency' ), 'the plain key index is gone' );
		$this->assertSame( 'datetime(3)', $this->columns( $logs )['ts']['Type'] );
		$this->assertSame( 'varchar(500)', $this->columns( $logs )['message']['Type'] );
		$this->assertSame( 'varchar(500)', $this->columns( $queue )['error_message']['Type'] );
		$this->assertArrayHasKey( 'disconnected_at', $this->columns( $conns ) );

		// Repeated keys: the first row of each stays, the others are gone; other connections and other keys are untouched.
		$left = $this->h->wpdb->get_results( "SELECT connection_id, entity_id, error_message FROM `$queue` ORDER BY id", ARRAY_A ); // phpcs:ignore
		$this->assertCount( 3, $left );
		$this->assertSame( 500, mb_strlen( (string) $left[0]['error_message'] ), 'the long message was cut to 500 characters, not lost' );
		$this->assertSame( 'unique', $left[1]['error_message'] );
		$this->assertSame( 'same key, another connection', $left[2]['error_message'] );
		$this->assertSame( 500, mb_strlen( (string) $this->h->wpdb->get_var( "SELECT message FROM `$logs` WHERE level = 'info' ORDER BY id DESC LIMIT 1" ) ) ); // phpcs:ignore

		// Secrets keep only a presence flag.
		$this->assertSame( array( 'api_key' => true, 'password' => true ), json_decode( (string) $this->h->connectionRow( $conn )['secret_hints_json'], true ) );

		// Running it all again changes nothing.
		$before = array(
			$this->h->wpdb->get_var( "SHOW CREATE TABLE `$queue`", 1 ), // phpcs:ignore
			$this->h->wpdb->get_var( "SHOW CREATE TABLE `$logs`", 1 ), // phpcs:ignore
			$this->h->wpdb->get_var( "SHOW CREATE TABLE `$conns`", 1 ), // phpcs:ignore
			$this->h->count( $queue ),
		);
		update_option( Migrator::OPTION, '1.0.0' );
		$this->assertTrue( $this->migrator->maybeMigrate() );
		$this->assertTrue( $this->migrator->migrate() );
		$this->assertSame(
			$before,
			array(
				$this->h->wpdb->get_var( "SHOW CREATE TABLE `$queue`", 1 ), // phpcs:ignore
				$this->h->wpdb->get_var( "SHOW CREATE TABLE `$logs`", 1 ), // phpcs:ignore
				$this->h->wpdb->get_var( "SHOW CREATE TABLE `$conns`", 1 ), // phpcs:ignore
				$this->h->count( $queue ),
			)
		);
	}

	public function test_a_fresh_install_has_nothing_to_upgrade_first(): void {
		// The upgrade steps skip tables that do not exist yet.
		$this->h->sql( "DROP TABLE `{$this->h->tables->reconDiffs()}`, `{$this->h->tables->reconRuns()}`, `{$this->h->tables->queue()}`, `{$this->h->tables->logs()}`, `{$this->h->tables->connections()}`" ); // phpcs:ignore
		$this->assertFalse( $this->migrator->allTablesExist() );
		( new Upgrades( $this->h->wpdb, $this->h->tables ) )->run();
		$this->assertTrue( $this->migrator->migrate() );
		$this->assertTrue( $this->migrator->allTablesExist() );
	}

	/**
	 * Columns of a table by name.
	 *
	 * @param string $table Table name.
	 * @return array<string,array<string,mixed>>
	 */
	private function columns( string $table ): array {
		$out = array();
		foreach ( (array) $this->h->wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $row ) { // phpcs:ignore
			$out[ (string) $row['Field'] ] = $row;
		}
		return $out;
	}

	/**
	 * Columns of an index, in order; empty when the index does not exist.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @return array<int,string>
	 */
	private function indexColumns( string $table, string $index ): array {
		$rows = (array) $this->h->wpdb->get_results( $this->h->wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ), ARRAY_A ); // phpcs:ignore
		usort( $rows, static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_map( static fn( array $row ): string => (string) $row['Column_name'], $rows );
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
