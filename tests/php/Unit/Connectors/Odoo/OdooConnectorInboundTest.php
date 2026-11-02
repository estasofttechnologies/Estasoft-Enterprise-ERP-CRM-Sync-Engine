<?php
/**
 * Reading stock, deliveries, invoices and the order list back from Odoo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooConnectorInboundTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array( 'odoo 17' => array( 17 ), 'odoo 18' => array( 18 ), 'odoo 19' => array( 19 ) );
	}

	/**
	 * Create orders through the connector.
	 *
	 * @param FakeOdooServer      $server  Server.
	 * @param array<int,string>   $ids     WooCommerce order ids.
	 * @param array<string,mixed> $options Connection options.
	 * @return array<string,ExternalRef>
	 */
	private function place( FakeOdooServer $server, array $ids, array $options = array() ): array {
		$refs = array();
		foreach ( $ids as $id ) {
			$result = $this->connector( $server )->send(
				$this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ),
				$this->item( EntityType::Order, $id ),
				$this->orderPayload()
			);
			$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
			$refs[ $id ] = $result->ref;
		}
		return $refs;
	}

	/* ------------------------------------------------------------ stock */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_stock_is_summed_over_the_warehouses_that_count_using_the_version_s_context_key( int $major ): void {
		$server = $this->server( $major );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-BOWL-02', 'CS-MUG-01' ), array( '1', '2' ) );

		$this->assertSame(
			array(
				'CS-BOWL-02' => array( 'quantity' => 20, 'held' => 2 ),
				'CS-MUG-01'  => array( 'quantity' => 42, 'held' => 0 ),
			),
			$result
		);
		$call = $server->callsTo( 'product.product', 'search_read' );
		$this->assertSame( array( 1, 2 ), $call[ count( $call ) - 1 ]['context'][ $major >= 18 ? 'warehouse_id' : 'warehouse' ] );
	}

	public function test_one_warehouse_counts_only_that_warehouse(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-BOWL-02' ), array( '2' ) );
		$this->assertSame( array( 'CS-BOWL-02' => array( 'quantity' => 6, 'held' => 0 ) ), $result );
	}

	public function test_warehouses_may_be_given_by_code(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-BOWL-02' ), array( 'DXB' ) );
		$this->assertSame( 14, $result['CS-BOWL-02']['quantity'] );
	}

	public function test_no_warehouse_means_all_warehouses_of_the_company(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-BOWL-02' ), array() );
		$this->assertSame( 20, $result['CS-BOWL-02']['quantity'] );
		$call = $server->callsTo( 'product.product', 'search_read' );
		$this->assertArrayNotHasKey( 'warehouse_id', $call[ count( $call ) - 1 ]['context'] );
	}

	public function test_products_that_keep_no_stock_and_unknown_references_are_left_out(): void {
		$server = $this->server( 18 );
		$server->db->addProduct( 'SVC-1', 'A service', 10.0, array(), array(), false );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'SVC-1', 'NOPE', 'CS-MUG-01' ), array() );
		$this->assertSame( array( 'CS-MUG-01' ), array_keys( $result ) );
	}

	public function test_on_odoo_17_a_stockable_product_is_one_of_type_product(): void {
		$server = $this->server( 17 );
		$server->db->addProduct( 'SVC-1', 'A service', 10.0, array(), array(), false );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'SVC-1', 'CS-MUG-01' ), array() );
		$this->assertSame( array( 'CS-MUG-01' ), array_keys( $result ) );
	}

	public function test_fractions_are_rounded_down_and_held_never_exceeds_the_quantity(): void {
		$server = $this->server( 18 );
		$server->db->addProduct( 'CS-ROPE', 'Rope by the metre', 1.0, array( 1 => 10.9 ), array( 1 => 25.0 ) );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-ROPE' ), array( '1' ) );
		$this->assertSame( array( 'quantity' => 10, 'held' => 10 ), $result['CS-ROPE'] );
	}

	public function test_many_references_are_asked_for_in_batches_of_a_hundred(): void {
		$server = $this->server( 18 );
		$skus   = array();
		for ( $i = 1; $i <= 250; $i++ ) {
			$skus[] = 'BULK-' . $i;
			$server->db->addProduct( 'BULK-' . $i, 'Bulk', 1.0, array( 1 => (float) $i ) );
		}
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), $skus, array( '1' ) );
		$this->assertCount( 250, $result );
		$this->assertSame( 250, $result['BULK-250']['quantity'] );
		$this->assertCount( 3, $server->callsTo( 'product.product', 'search_read' ) );
	}

	public function test_no_references_means_no_request(): void {
		$server = $this->server( 18 );
		$this->assertSame( array(), $this->connector( $server )->fetchStock( $this->connection( $server ), array( '', '  ' ), array() ) );
		$this->assertSame( array(), $server->callsTo( 'product.product', 'search_read' ) );
	}

	public function test_stock_failures_are_returned_not_thrown(): void {
		$server = $this->server( 18 );
		$server->db->deny( 'product.product', 'read' );
		$result = $this->connector( $server )->fetchStock( $this->connection( $server ), array( 'CS-MUG-01' ), array() );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Auth004, $result->code );
	}

	/* ------------------------------------------------------------ tracking */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_finished_deliveries_come_with_carrier_tracking_and_time( int $major ): void {
		$server = $this->server( $major );
		$refs   = $this->place( $server, array( '10490', '10491' ) );
		$server->db->addDelivery( (int) $refs['10490']->id, 'Aramex', 'AX-2231-8841', '2026-11-02 11:35:00' );
		$server->db->addDelivery( (int) $refs['10491']->id, 'DHL Express', 'DH-7719-0042', '2026-11-02 12:10:00' );

		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-02 00:00:00', new \DateTimeZone( 'UTC' ) ) );
		$this->assertSame(
			array(
				array( 'order_ref' => 'WC-10490', 'status' => 'done', 'carrier' => 'Aramex', 'tracking' => 'AX-2231-8841', 'done_at' => '2026-11-02T11:35:00Z', 'delivery' => 'WH/OUT/00001' ),
				array( 'order_ref' => 'WC-10491', 'status' => 'done', 'carrier' => 'DHL Express', 'tracking' => 'DH-7719-0042', 'done_at' => '2026-11-02T12:10:00Z', 'delivery' => 'WH/OUT/00002' ),
			),
			$rows
		);
	}

	public function test_only_deliveries_since_the_time_are_returned(): void {
		$server = $this->server( 18 );
		$refs   = $this->place( $server, array( '10490', '10491' ) );
		$server->db->addDelivery( (int) $refs['10490']->id, 'Aramex', 'OLD', '2026-11-01 08:00:00' );
		$server->db->addDelivery( (int) $refs['10491']->id, 'Aramex', 'NEW', '2026-11-02 08:00:00' );
		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-02T00:00:00+00:00' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'NEW', $rows[0]['tracking'] );

		$offset = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-02T03:00:00+04:00' ) );
		$this->assertCount( 1, $offset, '03:00 at +04:00 is 23:00 UTC the day before; the delivery of 08:00 that day is older than that.' );
	}

	public function test_orders_made_in_odoo_by_hand_are_not_reported(): void {
		$server = $this->server( 18 );
		$mine   = $this->place( $server, array( '10490' ) );
		$hand   = $server->db->insert( 'sale.order', array( 'name' => 'S09999', 'client_order_ref' => 'PO-7788', 'state' => 'sale', 'partner_id' => 1, 'currency_id' => 1 ) );
		$server->db->addDelivery( (int) $mine['10490']->id, 'Aramex', 'AX-1', '2026-11-02 08:00:00' );
		$server->db->addDelivery( $hand, 'Aramex', 'AX-2', '2026-11-02 09:00:00' );
		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-10490' ), array_column( $rows, 'order_ref' ) );
	}

	public function test_incoming_and_unfinished_deliveries_are_not_reported(): void {
		$server = $this->server( 18 );
		$refs   = $this->place( $server, array( '10490' ) );
		$server->db->addDelivery( (int) $refs['10490']->id, 'Aramex', 'AX-1', '2026-11-02 08:00:00' );
		$server->db->insert( 'stock.picking', array( 'name' => 'WH/IN/1', 'state' => 'done', 'date_done' => '2026-11-02 09:00:00', 'origin' => 'S00490', 'picking_type_code' => 'incoming', 'sale_id' => (int) $refs['10490']->id ) );
		$server->db->insert( 'stock.picking', array( 'name' => 'WH/OUT/9', 'state' => 'assigned', 'date_done' => false, 'origin' => 'S00490', 'picking_type_code' => 'outgoing', 'sale_id' => (int) $refs['10490']->id ) );
		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertCount( 1, $rows );
	}

	public function test_without_the_delivery_module_the_delivery_is_still_reported_without_carrier_or_number(): void {
		$server = $this->server( 18, array( 'delivery' => false ) );
		$refs   = $this->place( $server, array( '10490' ) );
		$server->db->addDelivery( (int) $refs['10490']->id, '', '', '2026-11-02 08:00:00' );
		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( '', $rows[0]['carrier'] );
		$this->assertSame( '', $rows[0]['tracking'] );
		$this->assertSame( 'done', $rows[0]['status'] );
	}

	public function test_a_delivery_without_a_sale_link_is_matched_through_its_source_document(): void {
		$server                = $this->server( 18 );
		$server->db->saleLink  = false;
		$refs                  = $this->place( $server, array( '10490' ) );
		$server->db->addDelivery( (int) $refs['10490']->id, 'Aramex', 'AX-9', '2026-11-02 08:00:00' );
		$rows = $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( 'WC-10490', $rows[0]['order_ref'] );
		$this->assertSame( 'AX-9', $rows[0]['tracking'] );
	}

	public function test_nothing_finished_gives_an_empty_list_and_no_order_lookup(): void {
		$server = $this->server( 18 );
		$this->assertSame( array(), $this->connector( $server )->fetchTracking( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) ) );
		$this->assertSame( array(), $server->callsTo( 'sale.order', 'read' ) );
	}

	/* ------------------------------------------------------------ invoices */

	public function test_an_order_without_an_invoice_gives_null(): void {
		$server = $this->server( 18 );
		$refs   = $this->place( $server, array( '10490' ) );
		$this->assertNull( $this->connector( $server )->fetchInvoice( $this->connection( $server ), $refs['10490'] ) );
		$this->assertNull( $this->connector( $server )->fetchInvoice( $this->connection( $server ), new ExternalRef( '0' ) ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_posted_invoice_gives_number_status_payment_state_and_link( int $major ): void {
		$server = $this->server( $major );
		$refs   = $this->place( $server, array( '10490' ), array( 'documents' => 'order_invoice' ) );
		$invoice = $this->connector( $server )->fetchInvoice( $this->connection( $server ), $refs['10490'] );

		$this->assertSame( 'INV/2026/00001', $invoice['number'] );
		$this->assertSame( 'posted', $invoice['status'] );
		$this->assertSame( 'not_required', $invoice['authority_status'], 'No e-invoicing on this server.' );
		$this->assertSame( 'not_paid', $invoice['payment_state'] );
		$this->assertSame( 'https://erp.example.com/report/pdf/account.report_invoice/1', $invoice['pdf_url'] );
		$this->assertSame( '', $invoice['qr'] );
		$this->assertSame( gmdate( 'Y-m-d' ) . 'T00:00:00Z', $invoice['issued_at'] );
		$this->assertSame( 48615, $invoice['total_minor'] );
		$this->assertSame( '1', $invoice['invoice_id'] );
	}

	public function test_a_draft_invoice_has_no_number_and_no_pdf_yet(): void {
		$server = $this->server( 18 );
		$refs   = $this->place( $server, array( '10490' ), array( 'documents' => 'order_invoice', 'invoice_state' => 'draft' ) );
		$invoice = $this->connector( $server )->fetchInvoice( $this->connection( $server ), $refs['10490'] );
		$this->assertSame( '', $invoice['number'] );
		$this->assertSame( 'draft', $invoice['status'] );
		$this->assertSame( '', $invoice['pdf_url'] );
		$this->assertSame( '', $invoice['issued_at'] );
	}

	public function test_a_paid_invoice_says_so(): void {
		$server = $this->server( 18 );
		$refs   = $this->place( $server, array( '10490' ), array( 'documents' => 'order_invoice_payment' ) );
		$this->assertSame( 'paid', $this->connector( $server )->fetchInvoice( $this->connection( $server ), $refs['10490'] )['payment_state'] );
	}

	public function test_a_saudi_invoice_goes_from_waiting_to_reported(): void {
		$server = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$server->db->addTax( 'VAT 15%', 15.0 );
		$result = $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) ),
			$this->item( EntityType::Order, '10490' ),
			$this->orderPayload( array( 'currency' => 'SAR', 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 10000, 'tax' => 'VAT 15%' ) ), 'shipping' => array() ) )
		);
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );

		$connection = $this->connection( $server );
		$invoice    = $this->connector( $server )->fetchInvoice( $connection, $result->ref );
		$this->assertSame( 'reported', $invoice['authority_status'], 'The send step already asked Odoo to report it, and the service accepted.' );
		$this->assertSame( 'QR-INV/2026/00001', $invoice['qr'] );
		$this->assertStringEndsWith( 'Z', $invoice['issued_at'] );
		$this->assertSame( '', $invoice['reject_reason'] );

		$server->db->records['account.move'][1]['_edi_state'] = 'to_send';
		$this->assertSame( 'waiting', $this->connector( $server )->fetchInvoice( $connection, $result->ref )['authority_status'] );
	}

	public function test_a_saudi_invoice_the_authority_rejected_shows_the_reason_in_plain_words(): void {
		$server               = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$server->db->zatcaOutcome = 'reject';
		$result = $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) ),
			$this->item( EntityType::Order, '10490' ),
			$this->orderPayload( array( 'currency' => 'SAR' ) )
		);
		$invoice = $this->connector( $server )->fetchInvoice( $this->connection( $server ), $result->ref );
		$this->assertSame( 'rejected', $invoice['authority_status'] );
		$this->assertSame( "The buyer's address is missing.", $invoice['reject_reason'] );
	}

	public function test_a_server_without_e_invoicing_never_reports_an_authority_status_other_than_not_required(): void {
		$server = $this->server( 19, array( 'country' => 'AE' ) );
		$refs   = $this->place( $server, array( '10490' ), array( 'documents' => 'order_invoice' ) );
		$this->assertSame( 'not_required', $this->connector( $server )->fetchInvoice( $this->connection( $server ), $refs['10490'] )['authority_status'] );
	}

	/* ------------------------------------------------------------ reconciliation */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_the_order_list_gives_totals_in_minor_units_state_and_email( int $major ): void {
		$server = $this->server( $major );
		$this->place( $server, array( '10490', '10491' ) );
		$list = $this->connector( $server )->listForReconcile( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );

		$this->assertSame( array( 'WC-10490', 'WC-10491' ), array_keys( $list ) );
		$ref = $list['WC-10490'];
		$this->assertSame( 'S00490', $ref->ref );
		$this->assertSame( 48615, $ref->extra['total_minor'] );
		$this->assertSame( 0, $ref->extra['refunded_minor'] );
		$this->assertSame( 'noor@example.com', $ref->extra['email'] );
		$this->assertSame( 'sale', $ref->extra['state'] );
		$this->assertSame( 'AED', $ref->extra['currency'] );
		$this->assertSame( 0, $ref->extra['duplicates'] );
		$this->assertArrayNotHasKey( 'invoice_ids', $ref->extra );
	}

	public function test_refunds_are_summed_by_order_whether_linked_to_the_invoice_or_only_by_source_document(): void {
		$server = $this->server( 18 );
		$this->place( $server, array( '10490' ), array( 'documents' => 'order_invoice' ) );
		$connection = $this->connection( $server, array( 'options' => array( 'call_interval_ms' => 0 ) ) );
		foreach ( array( '5521' => 21900, '5522' => 5000 ) as $id => $amount ) {
			$this->connector( $server )->send( $connection, $this->item( EntityType::Refund, (string) $id, 'refunded' ), array( 'order_ref' => 'WC-10490', 'amount' => $amount ) );
		}
		// A credit note made by hand in Odoo that only names the order.
		$server->db->insert( 'account.move', array( 'name' => 'RINV/9', 'move_type' => 'out_refund', 'state' => 'posted', 'invoice_origin' => 'S00490', 'amount_total' => 10.0, 'currency_id' => 1 ) );
		// A cancelled one does not count.
		$server->db->insert( 'account.move', array( 'name' => 'RINV/10', 'move_type' => 'out_refund', 'state' => 'cancel', 'invoice_origin' => 'S00490', 'amount_total' => 99.0, 'currency_id' => 1 ) );

		$list = $this->connector( $server )->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( 21900 + 5000 + 1000, $list['WC-10490']->extra['refunded_minor'] );
	}

	public function test_orders_before_the_window_and_orders_not_made_by_the_plugin_are_left_out(): void {
		$server = $this->server( 18 );
		$this->place( $server, array( '10490' ) );
		$server->db->insert( 'sale.order', array( 'name' => 'S00001', 'client_order_ref' => 'PO-1', 'state' => 'sale', 'partner_id' => 1, 'currency_id' => 1, 'date_order' => gmdate( 'Y-m-d H:i:s' ), 'amount_total' => 1.0 ) );
		$server->db->insert( 'sale.order', array( 'name' => 'S00002', 'client_order_ref' => 'WC-OLD', 'state' => 'sale', 'partner_id' => 1, 'currency_id' => 1, 'date_order' => '2020-01-01 00:00:00', 'amount_total' => 1.0 ) );
		$list = $this->connector( $server )->listForReconcile( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-10490' ), array_keys( $list ) );
	}

	public function test_two_odoo_orders_with_the_same_reference_are_counted_as_duplicates(): void {
		$server = $this->server( 18 );
		$this->place( $server, array( '10490' ) );
		$server->db->insert( 'sale.order', array( 'name' => 'S00777', 'client_order_ref' => 'WC-10490', 'state' => 'draft', 'partner_id' => 1, 'currency_id' => 1, 'date_order' => gmdate( 'Y-m-d H:i:s' ), 'amount_total' => 5.0 ) );
		$list = $this->connector( $server )->listForReconcile( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertCount( 1, $list );
		$this->assertSame( 'S00490', $list['WC-10490']->ref, 'The oldest wins.' );
		$this->assertSame( 1, $list['WC-10490']->extra['duplicates'] );
	}

	public function test_a_long_list_is_read_in_pages(): void {
		$server = $this->server( 18 );
		for ( $i = 1; $i <= 520; $i++ ) {
			$server->db->insert( 'sale.order', array( 'name' => sprintf( 'S%05d', $i ), 'client_order_ref' => 'WC-' . $i, 'state' => 'sale', 'partner_id' => 1, 'currency_id' => 1, 'date_order' => gmdate( 'Y-m-d H:i:s' ), 'amount_total' => 10.0 ) );
		}
		$server->db->addPartner( 'Someone', 'a@b.c' );
		$list = $this->connector( $server )->listForReconcile( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) );
		$this->assertCount( 520, $list );
		$this->assertSame( 1000, $list['WC-520']->extra['total_minor'] );
		$searches = array_filter( $server->callsTo( 'sale.order', 'search_read' ), static fn( array $c ): bool => isset( $c['params']['limit'] ) && 500 === $c['params']['limit'] );
		$this->assertCount( 2, $searches );
	}

	public function test_an_empty_window_gives_an_empty_list(): void {
		$server = $this->server( 18 );
		$this->assertSame( array(), $this->connector( $server )->listForReconcile( $this->connection( $server ), new \DateTimeImmutable( '2026-11-01T00:00:00Z' ) ) );
	}

	/* ------------------------------------------------------------ failures */

	public function test_every_read_returns_an_error_instead_of_throwing(): void {
		$server     = $this->server( 18 );
		$connection = $this->connection( $server );
		$http       = ( new FakeHttpClient() )->fallback( static fn() => FakeHttpClient::timeout() );
		$connector  = $this->connector( $http );
		$since      = new \DateTimeImmutable( '2026-11-01T00:00:00Z' );
		foreach ( array(
			$connector->fetchStock( $connection, array( 'A' ), array() ),
			$connector->fetchTracking( $connection, $since ),
			$connector->fetchInvoice( $connection, new ExternalRef( '5', 'S5' ) ),
			$connector->listForReconcile( $connection, $since ),
			$connector->findExisting( $connection, $this->item( EntityType::Order, '1' ) ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Net001, $result->code );
		}
	}
}
