<?php
/**
 * The steps after an order or refund is created: which ones are repeated, which ones never are, and what each pass does when
 * the record already exists in Odoo (the Saudi tax service step in particular).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooConnectorStepsTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array(
			'odoo 17 json-rpc' => array( 17 ),
			'odoo 18 json-rpc' => array( 18 ),
			'odoo 19 json-2'   => array( 19 ),
		);
	}

	/**
	 * @param array<string,mixed> $options Connection options.
	 */
	private function send( FakeOdooServer $server, array $payload, array $options = array() ): SendResult {
		return $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ),
			$this->item( EntityType::Order, '10490' ),
			$payload
		);
	}

	private function refund( FakeOdooServer $server, array $options = array(), array $payload = array() ): SendResult {
		$payload += array(
			'order_ref'   => 'WC-10490',
			'reason'      => 'Damaged in delivery',
			'refund_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900, 'tax' => 'VAT 15%' ) ),
		);
		return $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ),
			$this->item( EntityType::Refund, '5521', 'refunded' ),
			$payload
		);
	}

	private function findOrder( FakeOdooServer $server, array $options = array() ): ExternalRef|null {
		$found = $this->connector( $server )->findExisting( $this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ), $this->item( EntityType::Order, '10490' ) );
		$this->assertNotInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $found );
		return $found;
	}

	private function findRefund( FakeOdooServer $server, array $options = array() ): ExternalRef|null {
		$found = $this->connector( $server )->findExisting( $this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ), $this->item( EntityType::Refund, '5521', 'refunded' ) );
		$this->assertNotInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $found );
		return $found;
	}

	private function saudi( int $major = 19 ): FakeOdooServer {
		$server = $this->server( $major, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$server->db->addTax( 'VAT 15%', 15.0 );
		return $server;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function saudiPayload(): array {
		$payload                         = $this->orderPayload( array( 'currency' => 'SAR' ) );
		$payload['order_line'][0]['tax'] = 'VAT 15%';
		$payload['shipping']['tax']      = 'VAT 15%';
		return $payload;
	}

	/* ------------------------------------------------------------ the Saudi tax service step */

	public function test_a_posted_invoice_still_waiting_for_the_tax_service_is_sent_on_the_next_pass(): void {
		$server  = $this->saudi();
		$options = array( 'documents' => 'order_invoice' );
		// Two failures in a row defeat the single immediate retry, so this pass ends with the invoice posted and waiting.
		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );
		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );

		$first = $this->send( $server, $this->saudiPayload(), $options );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertSame( 'posted', $server->db->all( 'account.move' )[0]['state'] );
		$this->assertSame( 'to_send', $server->db->all( 'account.move' )[0]['_edi_state'], 'Posted, but the tax authority has not heard of it.' );
		$this->assertSame( array(), $server->db->ediRuns );

		// The engine asks findExisting first. It must not hide the work that is left.
		$this->assertNull( $this->findOrder( $server, $options ), 'The order exists but its invoice is not finished.' );

		$second = $this->send( $server, $this->saudiPayload(), $options );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( 'sent', $server->db->all( 'account.move' )[0]['_edi_state'] );
		$this->assertSame( array( 1 ), $server->db->ediRuns );
		$this->assertCount( 1, $server->db->all( 'account.move' ), 'No second invoice.' );

		$this->assertNotNull( $this->findOrder( $server, $options ), 'Finished: now it is simply found.' );
	}

	public function test_a_pass_with_nothing_left_does_not_ask_the_tax_service_again(): void {
		$server  = $this->saudi();
		$options = array( 'documents' => 'order_invoice' );
		$this->send( $server, $this->saudiPayload(), $options );
		$this->assertSame( array( 1 ), $server->db->ediRuns );

		$again = $this->send( $server, $this->saudiPayload(), $options );
		$this->assertTrue( $again->isSuccess() );
		$this->assertSame( array( 1 ), $server->db->ediRuns, 'Already sent: Odoo is not asked again.' );
		$this->assertCount( 1, $server->callsTo( 'account.move', 'action_process_edi_web_services' ) );
	}

	public function test_a_document_the_tax_service_refused_is_not_sent_again_and_is_reported_as_rejected(): void {
		$server                       = $this->saudi();
		$server->db->zatcaOutcome = 'reject';
		$options                      = array( 'documents' => 'order_invoice' );

		$first = $this->send( $server, $this->saudiPayload(), $options );
		$this->assertTrue( $first->isSuccess(), 'The invoice is in Odoo; the refusal is shown through fetchInvoice.' );
		$this->assertSame( array( 1 ), $server->db->ediRuns );

		$server->db->zatcaOutcome = 'accept';
		$second                   = $this->send( $server, $this->saudiPayload(), $options );
		$this->assertTrue( $second->isSuccess() );
		$this->assertSame( array( 1 ), $server->db->ediRuns, 'Blocked by an error: only a person can fix it, so it is left alone.' );
		$this->assertCount( 1, $server->callsTo( 'account.move', 'action_process_edi_web_services' ) );
		$this->assertNotNull( $this->findOrder( $server, $options ), 'A blocked document does not keep the item open for ever.' );

		$invoice = $this->connector( $server )->fetchInvoice( $this->connection( $server ), $first->ref );
		$this->assertSame( 'rejected', $invoice['authority_status'] );
		$this->assertSame( 'to_send', $invoice['edi_state'], 'Odoo\'s own state is reported next to the summary.' );
		$this->assertSame( 'error', $invoice['edi_blocking_level'] );
		$this->assertStringContainsString( 'address is missing', $invoice['reject_reason'] );
	}

	public function test_fetch_invoice_reports_waiting_for_a_document_that_is_posted_and_not_yet_sent(): void {
		$server = $this->saudi();
		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );
		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );
		$first   = $this->send( $server, $this->saudiPayload(), array( 'documents' => 'order_invoice' ) );
		$invoice = $this->connector( $server )->fetchInvoice( $this->connection( $server ), new ExternalRef( '1', 'S00490' ) );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'waiting', $invoice['authority_status'] );
		$this->assertSame( 'to_send', $invoice['edi_state'] );
		$this->assertSame( '', $invoice['edi_blocking_level'] );
		$this->assertSame( '', $invoice['reject_reason'] );
	}

	public function test_a_credit_note_that_already_exists_is_posted_and_sent_on_the_next_pass(): void {
		$server = $this->saudi();
		$order  = $this->send( $server, $this->saudiPayload(), array( 'documents' => 'order_invoice' ) );
		$this->assertTrue( $order->isSuccess(), (string) $order->error?->message() );
		$server->db->ediRuns = array();

		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );
		$server->failNext( FakeHttpClient::timeout(), 'action_process_edi_web_services' );
		$first = $this->refund( $server, array( 'refund_state' => 'post' ) );
		$this->assertFalse( $first->isSuccess() );
		$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'to_send', $notes[0]['_edi_state'] );
		$this->assertNull( $this->findRefund( $server, array( 'refund_state' => 'post' ) ), 'Waiting for the tax service: not "found" yet.' );

		$second = $this->refund( $server, array( 'refund_state' => 'post' ) );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertTrue( $second->alreadyExisted, 'The credit note was found, not made again.' );
		$notes = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'sent', $notes[0]['_edi_state'] );
		$this->assertNotNull( $this->findRefund( $server, array( 'refund_state' => 'post' ) ) );
	}

	public function test_a_credit_note_left_as_a_draft_is_posted_when_the_option_asks_for_it(): void {
		$server = $this->saudi();
		$this->send( $server, $this->saudiPayload(), array( 'documents' => 'order_invoice' ) );
		$this->refund( $server );
		$this->assertSame( 'draft', array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) )[0]['state'] );
		$this->assertNotNull( $this->findRefund( $server ), 'A draft is what was asked for.' );

		$this->assertNull( $this->findRefund( $server, array( 'refund_state' => 'post' ) ), 'Now a posted note is wanted.' );
		$result = $this->refund( $server, array( 'refund_state' => 'post' ) );
		$this->assertTrue( $result->alreadyExisted );
		$note = array_values( array_filter( $server->db->all( 'account.move' ), static fn( array $m ): bool => 'out_refund' === $m['move_type'] ) )[0];
		$this->assertSame( 'posted', $note['state'] );
		$this->assertSame( 'sent', $note['_edi_state'] );
	}

	/* ------------------------------------------------------------ steps that make something new */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_lost_answer_on_the_invoice_step_never_makes_a_second_invoice( int $major ): void {
		$server = $this->server( $major );
		$server->loseNextAnswer( 'create_invoices' );

		$result = $this->send( $server, $this->orderPayload(), array( 'documents' => 'order_invoice' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $server->callsTo( 'sale.advance.payment.inv', 'create_invoices' ), 'Not replayed.' );
		$invoices = $server->db->all( 'account.move' );
		$this->assertCount( 1, $invoices, 'The invoice Odoo made while the answer was lost is the one used.' );
		$this->assertSame( 'posted', $invoices[0]['state'] );
	}

	public function test_an_invoice_step_that_did_not_reach_odoo_is_returned_not_replayed_in_the_same_pass(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::timeout(), 'create_invoices' );

		$first = $this->send( $server, $this->orderPayload(), array( 'documents' => 'order_invoice' ) );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorClass::Retry, $first->error->errorClass() );
		$this->assertSame( array(), $server->db->all( 'account.move' ) );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'] );
		$this->assertCount( 0, $server->callsTo( 'sale.advance.payment.inv', 'create_invoices' ), 'The request never arrived, and was not sent again blindly.' );

		$second = $this->send( $server, $this->orderPayload(), array( 'documents' => 'order_invoice' ) );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 1, $server->db->all( 'account.move' ) );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_lost_answer_on_the_payment_step_never_registers_a_second_payment( int $major ): void {
		$server = $this->server( $major );
		$server->loseNextAnswer( 'action_create_payments' );

		$result = $this->send( $server, $this->orderPayload( array( 'payment' => array( 'reference' => 'pi_3Abc123' ) ) ), array( 'documents' => 'order_invoice_payment' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $server->callsTo( 'account.payment.register', 'action_create_payments' ), 'Not replayed.' );
		$this->assertSame( 'paid', $server->db->all( 'account.move' )[0]['payment_state'] );
	}

	public function test_a_payment_step_that_did_not_reach_odoo_is_returned_and_finished_by_the_next_pass(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::timeout(), 'action_create_payments' );
		$options = array( 'documents' => 'order_invoice_payment' );

		$first = $this->send( $server, $this->orderPayload(), $options );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'not_paid', $server->db->all( 'account.move' )[0]['payment_state'] );
		$this->assertNull( $this->findOrder( $server, $options ), 'An unpaid invoice is unfinished work.' );

		$second = $this->send( $server, $this->orderPayload(), $options );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertSame( 'paid', $server->db->all( 'account.move' )[0]['payment_state'] );
		$this->assertCount( 1, $server->db->all( 'account.move' ) );
		$this->assertNotNull( $this->findOrder( $server, $options ) );
	}

	/* ------------------------------------------------------------ steps that are safe to repeat */

	public function test_a_step_that_is_safe_to_repeat_is_tried_again_once_after_a_passing_error(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::timeout(), 'action_confirm' );
		$result = $this->send( $server, $this->orderPayload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'sale', $result->ref->extra['state'] );
	}

	public function test_a_429_is_returned_with_its_wait_and_not_replayed_at_once(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::tooManyRequests( 45 ), 'action_confirm' );

		$result = $this->send( $server, $this->orderPayload() );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 45, $result->error->retryAfter, 'The queue waits as Odoo said.' );
		$this->assertCount( 0, $server->callsTo( 'sale.order', 'action_confirm' ), 'Not sent again at once.' );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );

		$next = $this->send( $server, $this->orderPayload() );
		$this->assertTrue( $next->isSuccess(), (string) $next->error?->message() );
		$this->assertSame( 'sale', $next->ref->extra['state'] );
	}

	public function test_a_429_with_no_wait_given_still_is_not_replayed(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::status( 429, 'slow down' ), 'action_confirm' );
		$result = $this->send( $server, $this->orderPayload() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertCount( 0, $server->callsTo( 'sale.order', 'action_confirm' ) );
	}
}
