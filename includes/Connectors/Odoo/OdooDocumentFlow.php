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
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Each step is a public Odoo method (action_confirm, the invoice wizard, action_post, the payment wizard), because the
 * JSON-2 API cannot chain several calls in one transaction. Every pass starts by reading where the order stands, so a pass
 * after a failure carries on from the step that is missing and never repeats one that happened.
 *
 * Steps that can be run again without harm (confirm, post, set the date, send to the tax service) are tried once more at once
 * when the failure may pass by itself, unless Odoo or a proxy said to wait (429). Steps that make something new (the invoice,
 * the payment) are never replayed blindly: after a failure that may have reached Odoo the connector reads the state again, goes
 * on if the invoice or payment is there, and otherwise returns the failure so the next pass starts from a fresh read.
 * Nothing here signs or reports a tax invoice: the Odoo localisation does that once the invoice is posted.
 */
final class OdooDocumentFlow {

	/**
	 * Sale order states that can still be confirmed.
	 *
	 * @var array<int,string>
	 */
	private const OPEN_STATES = array( 'draft', 'sent' );

	/**
	 * Payment states that mean the invoice has its payment.
	 *
	 * @var array<int,string>
	 */
	private const PAID_STATES = array( 'paid', 'in_payment', 'partial' );

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
		$order   = $this->client->read( 'sale.order', array( $orderId ), array( 'state', 'invoice_ids', 'date_order' ) );
		$state   = isset( $order[0] ) ? OdooRecord::text( $order[0]['state'] ?? '' ) : '';
		$date    = isset( $order[0] ) ? OdooRecord::text( $order[0]['date_order'] ?? '' ) : '';

		if ( $payload->confirm && in_array( $state, self::OPEN_STATES, true ) ) {
			$this->repeatable( fn() => $this->client->callRecords( 'sale.order', 'action_confirm', array( $orderId ) ) );
			$state   = 'sale';
			$date    = '';
			$changed = true;
		}
		// Odoo sets the order date to the moment of confirmation. The shop's date is the one that belongs on the order.
		if ( in_array( $state, array( 'sale', 'done' ), true ) && '' !== $payload->dateOrder && $date !== $payload->dateOrder ) {
			$this->repeatable( fn() => $this->client->write( 'sale.order', array( $orderId ), array( 'date_order' => $payload->dateOrder ) ) );
			$changed = true;
		}
		if ( 'none' === $payload->invoice || ! in_array( $state, array( 'sale', 'done' ), true ) ) {
			return $changed;
		}

		$invoices = $this->customerInvoices( $orderId );
		if ( array() === $invoices ) {
			$this->once(
				fn() => $this->createInvoice( $orderId ),
				fn(): bool => array() !== $this->customerInvoices( $orderId )
			);
			$invoices = $this->customerInvoices( $orderId );
			$changed  = true;
		}
		foreach ( $invoices as $invoice ) {
			$id    = OdooRecord::id( $invoice['id'] ?? 0 );
			$state = OdooRecord::text( $invoice['state'] ?? '' );
			if ( 'post' === $payload->invoice && 'draft' === $state ) {
				$this->repeatable( fn() => $this->client->callRecords( 'account.move', 'action_post', array( $id ) ) );
				$state   = 'posted';
				$changed = true;
			}
			if ( 'posted' === $state ) {
				$changed = $this->sendToTaxService( $id ) || $changed;
			}
		}
		if ( $payload->registerPayment && array() !== $invoices ) {
			$changed = $this->registerPayment( $orderId, $payload->paymentReference ) || $changed;
		}
		return $changed;
	}

	/**
	 * Whether an order has gone through every step a plan asks for. Reads only. A step that is still to do (a quotation not
	 * confirmed, an invoice not made or not posted, a posted invoice still waiting for the tax service, an unpaid invoice)
	 * makes the answer no.
	 *
	 * @param int                                                      $orderId Sale order id.
	 * @param array{confirm:bool,invoice:string,register_payment:bool} $plan    Steps wanted (see OdooOrderPayload::plan()).
	 *
	 * @throws OdooException On failure.
	 */
	public function isComplete( int $orderId, array $plan ): bool {
		$order = $this->client->read( 'sale.order', array( $orderId ), array( 'state' ) );
		$state = isset( $order[0] ) ? OdooRecord::text( $order[0]['state'] ?? '' ) : '';
		if ( 'cancel' === $state ) {
			return true;
		}
		if ( in_array( $state, self::OPEN_STATES, true ) ) {
			return ! $plan['confirm'];
		}
		if ( 'none' === $plan['invoice'] ) {
			return true;
		}
		$invoices = $this->customerInvoices( $orderId );
		if ( array() === $invoices ) {
			return false;
		}
		foreach ( $invoices as $invoice ) {
			$invoiceState = OdooRecord::text( $invoice['state'] ?? '' );
			if ( 'draft' === $invoiceState ) {
				if ( 'post' === $plan['invoice'] ) {
					return false;
				}
				continue;
			}
			if ( $this->taxServicePending( $invoice ) ) {
				return false;
			}
			if ( $plan['register_payment'] && ! in_array( OdooRecord::text( $invoice['payment_state'] ?? '' ), self::PAID_STATES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an invoice or credit note has gone through the steps wanted: a draft is complete only when it should stay a draft,
	 * and a posted one only when it is not waiting for the tax authority service. Reads only.
	 *
	 * @param int  $moveId Move id.
	 * @param bool $posted True when the document should be posted.
	 *
	 * @throws OdooException On failure.
	 */
	public function isMoveComplete( int $moveId, bool $posted ): bool {
		$rows = $this->client->read( 'account.move', array( $moveId ), array_merge( array( 'state' ), $this->taxServiceFields() ) );
		if ( ! isset( $rows[0] ) ) {
			return true;
		}
		$state = OdooRecord::text( $rows[0]['state'] ?? '' );
		if ( 'draft' === $state ) {
			return ! $posted;
		}
		return 'posted' !== $state || ! $this->taxServicePending( $rows[0] );
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
			array_merge( array( 'name', 'state', 'payment_state', 'amount_total', 'amount_residual', 'currency_id' ), $this->taxServiceFields() ),
			array( 'order' => 'id asc' )
		);
	}

	/**
	 * Post a draft invoice or credit note, then send it to the tax authority service where the localisation has one.
	 *
	 * @param int $moveId Move id.
	 *
	 * @throws OdooException On failure.
	 */
	public function postMove( int $moveId ): void {
		$this->repeatable( fn() => $this->client->callRecords( 'account.move', 'action_post', array( $moveId ) ) );
		$this->sendToTaxService( $moveId );
	}

	/**
	 * Ask Odoo to send a posted invoice or credit note to the tax authority service where the localisation has one (Saudi
	 * e-invoicing), when it is waiting and has no error that needs a person. Odoo's own action only handles documents that are
	 * waiting and not blocked, so asking again is harmless; this runs on every pass because the EDI cron ships switched off and
	 * a posted document would otherwise wait for ever after a failed call. Nothing happens in other countries.
	 *
	 * @param int $moveId Move id.
	 * @return bool True when Odoo was asked.
	 *
	 * @throws OdooException On failure.
	 */
	public function sendToTaxService( int $moveId ): bool {
		if ( ! $this->hasTaxService() ) {
			return false;
		}
		$rows = $this->client->read( 'account.move', array( $moveId ), array_merge( array( 'state' ), $this->taxServiceFields() ) );
		if ( ! isset( $rows[0] ) || 'posted' !== OdooRecord::text( $rows[0]['state'] ?? '' ) || ! $this->taxServicePending( $rows[0] ) ) {
			return false;
		}
		$this->repeatable( fn() => $this->client->callRecords( 'account.move', 'action_process_edi_web_services', array( $moveId ) ) );
		return true;
	}

	/**
	 * Whether a posted document waits for the tax authority service and nothing blocks it.
	 *
	 * @param array<string,mixed> $move Move record read with taxServiceFields().
	 */
	private function taxServicePending( array $move ): bool {
		return 'to_send' === OdooRecord::text( $move['edi_state'] ?? '' ) && 'error' !== OdooRecord::text( $move['edi_blocking_level'] ?? '' );
	}

	/**
	 * The fields that say where a document stands with the tax authority service; none when the localisation is absent.
	 *
	 * @return array<int,string>
	 *
	 * @throws OdooException On failure.
	 */
	private function taxServiceFields(): array {
		if ( ! $this->hasTaxService() ) {
			return array();
		}
		return array_keys( array_filter( $this->client->fields( 'account.move', array( 'edi_state', 'edi_blocking_level' ) ) ) );
	}

	/**
	 * Whether the Saudi e-invoicing localisation is installed.
	 *
	 * @throws OdooException On failure.
	 */
	private function hasTaxService(): bool {
		return $this->client->hasField( 'account.move', 'l10n_sa_uuid' ) && $this->client->hasField( 'account.move', 'edi_state' );
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
			if ( 'posted' !== OdooRecord::text( $invoice['state'] ?? '' ) || in_array( OdooRecord::text( $invoice['payment_state'] ?? '' ), self::PAID_STATES, true ) ) {
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
			$this->once(
				function () use ( $values, $context ): void {
					$wizardId = $this->client->create( 'account.payment.register', $values, $context );
					$this->client->callRecords( 'account.payment.register', 'action_create_payments', array( $wizardId ), array(), $context );
				},
				fn(): bool => $this->isPaid( $id )
			);
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Whether an invoice already has its payment.
	 *
	 * @param int $invoiceId Invoice id.
	 *
	 * @throws OdooException On failure.
	 */
	private function isPaid( int $invoiceId ): bool {
		$rows = $this->client->read( 'account.move', array( $invoiceId ), array( 'payment_state' ) );
		return isset( $rows[0] ) && in_array( OdooRecord::text( $rows[0]['payment_state'] ?? '' ), self::PAID_STATES, true );
	}

	/**
	 * Run a step that is safe to run twice; try once more when the failure is the kind that passes by itself. A refusal that
	 * says to wait (429, or any error with a wait time) is not replayed: the caller returns it and the queue waits as told.
	 *
	 * @param callable $step The step.
	 *
	 * @throws OdooException When the step fails twice or fails for a reason that will not pass.
	 */
	private function repeatable( callable $step ): void {
		try {
			$step();
		} catch ( OdooException $exception ) {
			if ( ! $this->mayReplay( $exception ) ) {
				throw $exception;
			}
			$step();
		}
	}

	/**
	 * Run a step that makes something new. It is run once. When it fails in a way that may have reached Odoo, the state is read
	 * again: if the thing is there, the pass goes on; if not, the failure is returned.
	 *
	 * @param callable $step     The step.
	 * @param callable $happened Returns true when the step's result is visible in Odoo.
	 *
	 * @throws OdooException When the step fails and its result is not there.
	 */
	private function once( callable $step, callable $happened ): void {
		try {
			$step();
		} catch ( OdooException $exception ) {
			if ( ErrorClass::Retry !== $exception->error->errorClass() || ! $happened() ) {
				throw $exception;
			}
		}
	}

	/**
	 * Whether a failed step may be run again at once.
	 *
	 * @param OdooException $exception The failure.
	 */
	private function mayReplay( OdooException $exception ): bool {
		if ( ErrorClass::Retry !== $exception->error->errorClass() ) {
			return false;
		}
		return ErrorCode::Rate001 !== $exception->error->code && null === $exception->error->retryAfter;
	}
}
