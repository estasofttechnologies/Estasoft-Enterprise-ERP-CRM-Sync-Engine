<?php
/**
 * Refunds become credit notes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooConnectorRefundTest extends OdooTestCase {

	/**
	 * A server that already has order WC-10490 with a posted invoice.
	 *
	 * @param int                 $major   Version.
	 * @param array<string,mixed> $options Server options.
	 * @param array<string,mixed> $payload Order payload values to replace.
	 */
	private function invoicedOrder( int $major = 18, array $options = array(), array $payload = array() ): FakeOdooServer {
		$server = $this->server( $major, $options );
		$result = $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) ),
			$this->item( EntityType::Order, '10490' ),
			$this->orderPayload( $payload )
		);
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		return $server;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function refundPayload(): array {
		return array(
			'order_ref'   => 'WC-10490',
			'reason'      => 'Damaged in delivery',
			'refund_line' => array(
				array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900, 'tax' => 'VAT 5% (AE)' ),
			),
			'shipping'    => array( 'total' => 2500, 'tax' => 'VAT 5% (AE)' ),
		);
	}

	private function refund( FakeOdooServer $server, array $payload, array $options = array(), string $id = '5521' ): SendResult {
		return $this->connector( $server )->send( $this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ), $this->item( EntityType::Refund, $id, 'refunded' ), $payload );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array( 'odoo 17' => array( 17 ), 'odoo 18' => array( 18 ), 'odoo 19' => array( 19 ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_refund_becomes_a_draft_credit_note_linked_to_the_invoice( int $major ): void {
		$server = $this->invoicedOrder( $major );
		$result = $this->refund( $server, $this->refundPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertCount( 1, $notes );
		$note = $notes[0];
		$this->assertSame( 'draft', $note['state'], 'A credit note is a draft unless the option says post.' );
		$this->assertSame( 1, $note['reversed_entry_id'], 'Linked to the posted invoice.' );
		$this->assertSame( 'WC-5521 Damaged in delivery', $note['ref'] );
		$this->assertSame( 'S00490', $note['invoice_origin'] );
		$this->assertSame( $server->db->all( 'sale.order' )[0]['partner_id'], $note['partner_id'] );
		$this->assertSame( 256.2, $note['amount_total'], '(219.00 + 25.00) + 5% VAT' );
		$this->assertSame( 25620, $result->ref->extra['total_minor'] );
		$this->assertSame( 'credit_note', $result->ref->extra['document'] );
		$this->assertSame( 'WC-5521 Damaged in delivery', $result->ref->ref, 'A draft has no number yet, so the reference is shown.' );
	}

	public function test_a_credit_note_can_be_posted_by_option_or_by_the_mapping(): void {
		$server = $this->invoicedOrder();
		$result = $this->refund( $server, $this->refundPayload(), array( 'refund_state' => 'post' ) );
		$this->assertSame( 'RINV/2026/00001', $result->ref->ref );
		$this->assertSame( 'posted', $result->ref->extra['state'] );

		$other  = $this->invoicedOrder();
		$byRule = $this->refund( $other, $this->refundPayload() + array( 'flow' => array( 'post' => true ) ) );
		$this->assertSame( 'posted', $byRule->ref->extra['state'] );

		$off = $this->invoicedOrder();
		$this->assertSame( 'draft', $this->refund( $off, $this->refundPayload() + array( 'flow' => array( 'post' => false ) ), array( 'refund_state' => 'post' ) )->ref->extra['state'] );
	}

	public function test_the_same_refund_twice_makes_one_credit_note_even_when_the_reason_changes(): void {
		$server = $this->invoicedOrder();
		$first  = $this->refund( $server, $this->refundPayload() );
		$second = $this->refund( $server, array( 'reason' => 'Another reason' ) + $this->refundPayload() );

		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
	}

	public function test_find_existing_matches_the_reference_exactly_with_or_without_a_reason(): void {
		$server     = $this->invoicedOrder();
		$connector  = $this->connector( $server );
		$connection = $this->connection( $server );
		$this->refund( $server, $this->refundPayload() );
		$this->refund( $server, array( 'reason' => '' ) + $this->refundPayload(), array(), '55210' );

		$found = $connector->findExisting( $connection, $this->item( EntityType::Refund, '5521', 'refunded' ) );
		$this->assertSame( 'WC-5521 Damaged in delivery', $found->ref );
		$this->assertNull( $connector->findExisting( $connection, $this->item( EntityType::Refund, '552', 'refunded' ) ), 'WC-552 is not WC-5521.' );
		$this->assertNull( $connector->findExisting( $connection, $this->item( EntityType::Refund, '55', 'refunded' ) ) );
		$plain = $connector->findExisting( $connection, $this->item( EntityType::Refund, '55210', 'refunded' ) );
		$this->assertSame( 'WC-55210', $plain->ref );
	}

	public function test_a_reference_with_wildcard_characters_cannot_match_other_credit_notes(): void {
		$server     = $this->invoicedOrder();
		$connection = $this->connection( $server );
		$this->refund( $server, $this->refundPayload() );
		$this->assertNull( $this->connector( $server )->findExisting( $connection, $this->item( EntityType::Refund, '5%', 'refunded' ) ) );
		$this->assertNull( $this->connector( $server )->findExisting( $connection, $this->item( EntityType::Refund, '55_1', 'refunded' ) ) );
	}

	public function test_amounts_that_divide_evenly_keep_quantity_and_unit_price(): void {
		$server  = $this->invoicedOrder();
		$payload = $this->refundPayload();
		$payload['refund_line'] = array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'amount' => 43800, 'tax' => 'VAT 5% (AE)' ) );
		unset( $payload['shipping'] );
		$this->refund( $server, $payload );
		$lines = $server->db->all( 'account.move.line' );
		$line  = $lines[ count( $lines ) - 1 ];
		$this->assertSame( 2.0, $line['quantity'] );
		$this->assertSame( 219.0, $line['price_unit'] );
		$this->assertSame( $server->db->idOf( 'product.product', 'default_code', 'CS-BOWL-02' ), $line['product_id'] );
	}

	public function test_amounts_that_do_not_divide_evenly_become_one_line_so_the_total_is_exact(): void {
		$server  = $this->invoicedOrder();
		$payload = $this->refundPayload();
		$payload['refund_line'] = array( array( 'product' => 'CS-BOWL-02', 'name' => 'Bowl', 'quantity' => 3, 'amount' => 1000, 'tax' => '' ) );
		unset( $payload['shipping'] );
		$result = $this->refund( $server, $payload );
		$lines  = $server->db->all( 'account.move.line' );
		$line   = $lines[ count( $lines ) - 1 ];
		$this->assertSame( 1.0, $line['quantity'] );
		$this->assertSame( 10.0, $line['price_unit'] );
		$this->assertSame( 'Bowl x 3', $line['name'] );
		$this->assertSame( 1000, $result->ref->extra['total_minor'] );
	}

	public function test_a_refund_with_only_an_amount_is_one_untaxed_line_on_the_refund_product(): void {
		$server = $this->invoicedOrder();
		$result = $this->refund( $server, array( 'order_ref' => 'WC-10490', 'amount' => 5000 ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5000, $result->ref->extra['total_minor'], 'The credit note total is the refund amount, exactly.' );
		$this->assertGreaterThan( 0, $server->db->idOf( 'product.product', 'default_code', 'REFUND' ) );
		$lines = $server->db->all( 'account.move.line' );
		$this->assertSame( array(), $lines[ count( $lines ) - 1 ]['tax_ids'] );
		$this->assertSame( 'Refund WC-5521', $lines[ count( $lines ) - 1 ]['name'] );
	}

	public function test_a_refund_with_neither_lines_nor_an_amount_is_val_002(): void {
		$server = $this->invoicedOrder();
		$result = $this->refund( $server, array( 'order_ref' => 'WC-10490' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'amount', $result->error->context['field'] );
	}

	public function test_without_an_invoice_the_credit_note_stands_alone(): void {
		$server = $this->server( 18 );
		$this->connector( $server )->send( $this->connection( $server ), $this->item( EntityType::Order, '10490' ), $this->orderPayload() );
		$result = $this->refund( $server, $this->refundPayload() );
		$this->assertTrue( $result->isSuccess() );
		$note = $server->db->all( 'account.move' )[0];
		$this->assertArrayNotHasKey( 'reversed_entry_id', $note );
		$this->assertSame( 'out_refund', $note['move_type'] );
	}

	public function test_a_refund_for_an_order_odoo_does_not_have_is_val_002_naming_it(): void {
		$server = $this->server( 18 );
		$result = $this->refund( $server, $this->refundPayload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertStringContainsString( 'WC-10490', $result->error->context['reason'] );
		$this->assertCount( 0, $server->db->all( 'account.move' ) );
	}

	public function test_a_refund_that_does_not_say_which_order_is_refused_before_any_request(): void {
		$server = $this->server( 18 );
		$result = $this->refund( $server, array( 'amount' => 100 ) );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $server->requests );
	}

	public function test_an_unknown_tax_code_is_map_002(): void {
		$server  = $this->invoicedOrder();
		$payload = $this->refundPayload();
		$payload['refund_line'][0]['tax'] = 'Mystery tax';
		$this->assertSame( ErrorCode::Map002, $this->refund( $server, $payload )->error->code );
	}

	public function test_a_saudi_credit_note_always_has_a_zatca_reason_and_is_sent_to_the_tax_service_when_posted(): void {
		$server = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$server->db->addTax( 'VAT 15%', 15.0 );
		$sent = $this->orderPayload( array( 'currency' => 'SAR' ) );
		$sent['order_line'][0]['tax'] = 'VAT 15%';
		$sent['shipping']['tax']      = 'VAT 15%';
		$this->connector( $server )->send( $this->connection( $server, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) ), $this->item( EntityType::Order, '10490' ), $sent );
		$payload = $this->refundPayload();
		$payload['refund_line'][0]['tax'] = 'VAT 15%';
		$payload['shipping']['tax']       = 'VAT 15%';
		$result = $this->refund( $server, $payload, array( 'refund_state' => 'post' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertSame( 'BR-KSA-17-reason-4', $notes[0]['l10n_sa_reason'], 'Refund of goods or services.' );
		$this->assertSame( 'sent', $notes[0]['_edi_state'] );

		$own = $this->invoicedOrder( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ), array( 'currency' => 'SAR' ) );
		$this->refund( $own, array( 'zatca_reason' => 'BR-KSA-17-reason-1', 'amount' => 100, 'order_ref' => 'WC-10490' ) );
		$ownNotes = array_values( array_filter( $own->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertSame( 'BR-KSA-17-reason-1', $ownNotes[0]['l10n_sa_reason'] );
	}

	public function test_before_odoo_19_no_zatca_reason_is_sent_because_credit_notes_have_no_such_field(): void {
		foreach ( array( 17, 18 ) as $major ) {
			$server = $this->invoicedOrder( $major, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ), array( 'currency' => 'SAR' ) );
			$result = $this->refund( $server, array( 'zatca_reason' => 'BR-KSA-17-reason-1', 'amount' => 100, 'order_ref' => 'WC-10490' ) );
			$this->assertTrue( $result->isSuccess(), 'Odoo ' . $major . ': ' . (string) $result->error?->message() );
			$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
			$this->assertCount( 1, $notes );
			$this->assertArrayNotHasKey( 'l10n_sa_reason', $notes[0], 'Odoo ' . $major );
		}
	}

	public function test_no_zatca_field_is_sent_to_a_server_without_the_saudi_module(): void {
		$server = $this->invoicedOrder();
		$this->refund( $server, $this->refundPayload() );
		$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertArrayNotHasKey( 'l10n_sa_reason', $notes[0] );
	}

	public function test_a_rights_error_on_credit_notes_is_auth_004(): void {
		$server = $this->invoicedOrder();
		$server->db->deny( 'account.move', 'create' );
		$result = $this->refund( $server, $this->refundPayload() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertSame( 'create invoices and credit notes', $result->error->context['action'] );
	}

	public function test_a_refund_that_is_found_is_not_created_again_after_a_lost_answer(): void {
		$server = $this->invoicedOrder();
		$server->loseNextAnswer( '"account.move","create"' );
		$first = $this->refund( $server, $this->refundPayload() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$second = $this->refund( $server, $this->refundPayload() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
	}
}
