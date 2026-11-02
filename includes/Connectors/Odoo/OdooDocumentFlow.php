<?php
/**
 * Moves a created sale order through confirmation, invoice and payment.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ErrorClass;

/**
 * Each step is a public Odoo method (action_confirm, the invoice wizard, action_post, the payment wizard), because the
 * JSON-2 API cannot chain several calls in one transaction. A step that fails with an error that may pass by itself is
 * tried once more at once; other failures reach the caller, which reports them. Nothing here signs or reports a tax
 * invoice: the Odoo localisation does that after posting.
 */
final class OdooDocumentFlow {

	/**
	 * Sale order states that can still be confirmed.
	 *
	 * @var array<int,string>
	 */
	private const OPEN_STATES = array( 'draft', 'sent' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient $client Client.
	 */
	public function __construct( private readonly OdooClient $client ) {
	}

	/**
	 * Run the steps the payload asks for.
	 *
	 * @param int              $orderId Sale order id.
	 * @param OdooOrderPayload $payload Payload with the document flow.
	 * @return bool True when something was changed in Odoo.
	 *
	 * @throws OdooException When a step fails.
	 */
	public function advance( int $orderId, OdooOrderPayload $payload ): bool {
		$changed = false;
		$order   = $this->client->read( 'sale.order', array( $orderId ), array( 'state', 'invoice_ids' ) );
		$state   = isset( $order[0] ) ? OdooRecord::text( $order[0]['state'] ?? '' ) : '';

		if ( $payload->confirm && in_array( $state, self::OPEN_STATES, true ) ) {
			$this->attempt( fn() => $this->client->callRecords( 'sale.order', 'action_confirm', array( $orderId ) ) );
			$state   = 'sale';
			$changed = true;
		}
		if ( 'none' === $payload->invoice || ! in_array( $state, array( 'sale', 'done' ), true ) ) {
			return $changed;
		}

		$invoices = $this->customerInvoices( $orderId );
		if ( array() === $invoices ) {
			$this->attempt( fn() => $this->createInvoice( $orderId ) );
			$invoices = $this->customerInvoices( $orderId );
			$changed  = true;
		}
		if ( 'post' === $payload->invoice ) {
			foreach ( $invoices as $invoice ) {
				if ( 'draft' === OdooRecord::text( $invoice['state'] ?? '' ) ) {
					$id = OdooRecord::id( $invoice['id'] ?? 0 );
					$this->attempt( fn() => $this->client->callRecords( 'account.move', 'action_post', array( $id ) ) );
					$this->sendToTaxService( $id );
					$changed = true;
				}
			}
		}
		if ( $payload->registerPayment && array() !== $invoices ) {
			$changed = $this->registerPayment( $orderId, $payload->paymentReference ) || $changed;
		}
		return $changed;
	}

	/**
	 * Customer invoices of an order (credit notes are left out).
	 *
	 * @param int $orderId Sale order id.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException On failure.
	 */
	public function customerInvoices( int $orderId ): array {
		$order = $this->client->read( 'sale.order', array( $orderId ), array( 'invoice_ids' ) );
		$ids   = isset( $order[0] ) ? OdooRecord::ids( $order[0]['invoice_ids'] ?? array() ) : array();
		if ( array() === $ids ) {
			return array();
		}
		return $this->client->searchRead(
			'account.move',
			array( array( 'id', 'in', $ids ), array( 'move_type', '=', 'out_invoice' ), array( 'state', '!=', 'cancel' ) ),
			array( 'name', 'state', 'payment_state', 'amount_total', 'amount_residual', 'currency_id' ),
			array( 'order' => 'id asc' )
		);
	}

	/**
	 * Create the invoice with Odoo's own wizard (ordered quantities follow each product's invoicing policy).
	 *
	 * @param int $orderId Sale order id.
	 *
	 * @throws OdooException On failure.
	 */
	private function createInvoice( int $orderId ): void {
		$context  = array(
			'active_model' => 'sale.order',
			'active_ids'   => array( $orderId ),
			'active_id'    => $orderId,
		);
		$wizardId = $this->client->create( 'sale.advance.payment.inv', array( 'advance_payment_method' => 'delivered' ), $context );
		$this->client->callRecords( 'sale.advance.payment.inv', 'create_invoices', array( $wizardId ), array(), $context );
	}

	/**
	 * Register the payment of a posted invoice with Odoo's payment wizard.
	 *
	 * @param int    $orderId   Sale order id.
	 * @param string $reference Payment reference (the shop's transaction id).
	 * @return bool True when a payment was registered.
	 *
	 * @throws OdooException On failure.
	 */
	private function registerPayment( int $orderId, string $reference ): bool {
		$changed = false;
		foreach ( $this->customerInvoices( $orderId ) as $invoice ) {
			if ( 'posted' !== OdooRecord::text( $invoice['state'] ?? '' ) || in_array( OdooRecord::text( $invoice['payment_state'] ?? '' ), array( 'paid', 'in_payment' ), true ) ) {
				continue;
			}
			$id      = OdooRecord::id( $invoice['id'] ?? 0 );
			$context = array(
				'active_model' => 'account.move',
				'active_ids'   => array( $id ),
				'active_id'    => $id,
			);
			$values  = array();
			if ( '' !== $reference ) {
				$values['communication'] = $reference;
			}
			$this->attempt(
				function () use ( $values, $context ): void {
					$wizardId = $this->client->create( 'account.payment.register', $values, $context );
					$this->client->callRecords( 'account.payment.register', 'action_create_payments', array( $wizardId ), array(), $context );
				}
			);
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Ask Odoo to send a posted invoice to the tax authority service where the localisation has one (Saudi e-invoicing). The
	 * EDI cron ships switched off, so a posted invoice would wait for it. Nothing happens in other countries.
	 *
	 * @param int $invoiceId Invoice id.
	 *
	 * @throws OdooException On failure.
	 */
	private function sendToTaxService( int $invoiceId ): void {
		if ( $this->client->hasField( 'account.move', 'l10n_sa_uuid' ) && $this->client->hasField( 'account.move', 'edi_state' ) ) {
			$this->attempt( fn() => $this->client->callRecords( 'account.move', 'action_process_edi_web_services', array( $invoiceId ) ) );
		}
	}

	/**
	 * Run a step; try once more when the failure is the kind that passes by itself.
	 *
	 * @param callable $step The step.
	 *
	 * @throws OdooException When the step fails twice or fails for a reason that will not pass.
	 */
	private function attempt( callable $step ): void {
		try {
			$step();
		} catch ( OdooException $exception ) {
			if ( ErrorClass::Retry !== $exception->error->errorClass() ) {
				throw $exception;
			}
			$step();
		}
	}
}
