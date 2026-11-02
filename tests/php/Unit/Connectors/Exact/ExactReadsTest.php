<?php
/**
 * Stock, invoice facts, lookups and the nightly check of the Exact Online connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Read-only paths.
 */
final class ExactReadsTest extends ExactTestCase {

	public function test_stock_is_summed_over_the_chosen_warehouses_and_non_stock_items_are_left_out(): void {
		$this->server->itemWarehouses = array(
			array( 'ItemCode' => 'CS-MUG-01', 'WarehouseCode' => 'MAIN', 'CurrentStock' => 10.0, 'PlannedStockOut' => 3.0, 'ItemIsStockItem' => true ),
			array( 'ItemCode' => 'CS-MUG-01', 'WarehouseCode' => 'WEB', 'CurrentStock' => 4.0, 'PlannedStockOut' => 1.0, 'ItemIsStockItem' => true ),
			array( 'ItemCode' => 'CS-MUG-01', 'WarehouseCode' => 'SHOP', 'CurrentStock' => 99.0, 'PlannedStockOut' => 0.0, 'ItemIsStockItem' => true ),
			array( 'ItemCode' => 'SHIP-01', 'WarehouseCode' => 'MAIN', 'CurrentStock' => 5.0, 'PlannedStockOut' => 0.0, 'ItemIsStockItem' => false ),
		);
		$connection = $this->connection();
		$all        = $this->connector->fetchStock( $connection, array( 'CS-MUG-01', 'SHIP-01', 'NO-SUCH' ), array() );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 113, 'held' => 4 ) ), $all );
		$chosen = $this->connector->fetchStock( $connection, array( 'CS-MUG-01' ), array( 'main', 'WEB' ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 14, 'held' => 4 ) ), $chosen );
	}

	public function test_invoice_facts_are_read_back_by_the_guid(): void {
		$connection = $this->connection( array( 'options' => array( 'process_invoice' => 'yes' ) ) );
		list( $item, $payload ) = $this->order( '5001' );
		$sent  = $this->connector->send( $connection, $item, $payload );
		$facts = $this->connector->fetchInvoice( $connection, $sent->ref );
		$this->assertSame( 'processed', $facts['status'] );
		$this->assertSame( '2026-10-30', $facts['issued_at'] );
		$this->assertSame( '', $facts['authority_status'] );
		$this->assertNotSame( '', $facts['number'] );
	}

	public function test_an_open_invoice_reads_as_open(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '5002' );
		$sent = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( 'open', $this->connector->fetchInvoice( $connection, $sent->ref )['status'] );
	}

	public function test_the_nightly_list_has_totals_and_refunded_amounts_and_pages_to_the_end(): void {
		$this->server->pageSize = 1;
		$connection = $this->connection();
		foreach ( array( '5003', '5004', '5005' ) as $id ) {
			list( $item, $payload ) = $this->order( $id );
			$this->connector->send( $connection, $item, $payload );
		}
		list( $refund, $refundPayload ) = $this->refund( '5006', '5004' );
		$this->connector->send( $connection, $refund, $refundPayload );
		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5003', 'WC-5004', 'WC-5005' ), array_keys( $list ) );
		$this->assertSame( 14100, $list['WC-5004']->extra['total_minor'] );
		$this->assertSame( 2000, $list['WC-5004']->extra['refunded_minor'] );
		$this->assertSame( 0, $list['WC-5003']->extra['refunded_minor'] );
		$this->assertArrayHasKey( 'email', $list['WC-5003']->extra );
	}

	public function test_the_window_starts_at_the_given_day(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '5007' );
		$this->connector->send( $connection, $item, $payload );
		$this->assertSame( array(), $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-15T00:00:00Z' ) ) );
	}

	public function test_lookups_and_search(): void {
		$connection = $this->connection();
		$product    = $this->connector->lookupProduct( $connection, 'cs-mug-01' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-MUG-01', $product->ref );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'NOPE' ) );
		$found = $this->connector->searchProducts( $connection, 'MUG' );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ) => $r->ref, $found ) );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
	}

	public function test_the_default_mapping_targets_fields_the_catalog_knows(): void {
		foreach ( array( 'orders', 'customers', 'products', 'refunds', 'stock' ) as $type ) {
			$paths = array_map( static fn( array $f ): string => (string) preg_replace( '/\[\d*\]/', '', $f['path'] ), $this->connector->describeFields( $type ) );
			foreach ( $this->connector->defaultMapping( $type ) as $rule ) {
				if ( 'stock' === $type ) {
					continue;
				}
				$this->assertContains( (string) preg_replace( '/\[\d*\]/', '', (string) $rule['target'] ), $paths, $type . ': ' . $rule['id'] );
			}
		}
	}
}
