<?php
/**
 * Removing order meta through the order's own methods.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\DataEraser;
use Estasoft\SyncEngine\Core\Installer;
use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Tests\Support\CoreFakeOrder;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class DataEraserTest extends CoreIntegrationTestCase {

	protected function tearDown(): void {
		Installer::installSite();
		parent::tearDown();
	}

	public function test_order_meta_is_removed_in_batches_through_the_order_object_only(): void {
		/** @var array<int,CoreFakeOrder> $orders */
		$orders = array();
		for ( $id = 1; $id <= 250; ++$id ) {
			$order       = new CoreFakeOrder();
			$order->meta = array(
				'_estasoft_sync_state'       => 'synced',
				'_estasoft_sync_external_id' => '55',
				'_billing_email'             => 'noor@example.com',
			);
			$orders[ $id ] = $order;
		}
		$calls  = 0;
		$eraser = new DataEraser(
			$this->h->wpdb,
			$this->h->tables,
			static function ( string $key, int $limit ) use ( &$orders, &$calls ): array {
				++$calls;
				$found = array();
				foreach ( $orders as $id => $order ) {
					if ( array_key_exists( $key, $order->meta ) ) {
						$found[] = $id;
					}
					if ( count( $found ) >= $limit ) {
						break;
					}
				}
				return $found;
			},
			static fn( int $id ): ?object => $orders[ $id ] ?? null
		);

		$eraser->erase( false );

		foreach ( $orders as $order ) {
			$this->assertSame( array( '_billing_email' => 'noor@example.com' ), $order->meta, 'only the plugin\'s keys went' );
			$this->assertGreaterThanOrEqual( 2, $order->saves );
		}
		$this->assertGreaterThan( 3, $calls, 'the work was done in batches of 100' );
	}

	public function test_the_delete_data_tool_keeps_the_capabilities(): void {
		$eraser = new DataEraser( $this->h->wpdb, $this->h->tables, static fn(): array => array(), static fn(): ?object => null );
		$eraser->erase( false );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertNull( $this->h->wpdb->get_var( $this->h->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->h->tables->queue() ) ) );
	}

	public function test_a_loop_that_cannot_make_progress_stops(): void {
		$loads  = 0;
		$eraser = new DataEraser(
			$this->h->wpdb,
			$this->h->tables,
			static fn(): array => array( 1 ),
			static function () use ( &$loads ): ?object {
				++$loads;
				return null;
			}
		);
		$eraser->erase( false );
		$this->assertSame( count( MetaKeys::order() ), $loads, 'one failed load per key, then it moves on' );
	}
}
