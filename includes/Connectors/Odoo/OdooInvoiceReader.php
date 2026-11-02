<?php
/**
 * Reads the invoice of a synced sale order, with its e-invoicing status where Odoo has one.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The invoice is the latest customer invoice linked to the order. Where the Saudi localisation is installed
 * (l10n_sa_edi) the status of its tax authority submission is read from the electronic invoicing fields
 * (edi_state, edi_error_message) and the QR code and issue time from l10n_sa. The plugin only reads and shows these.
 */
final class OdooInvoiceReader {

	/**
	 * Optional fields, read only when the Odoo version has them.
	 *
	 * @var array<int,string>
	 */
	private const OPTIONAL = array( 'edi_state', 'edi_error_message', 'l10n_sa_qr_code_str', 'l10n_sa_confirmation_datetime', 'l10n_sa_uuid' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooAmounts $amounts ) {
	}

	/**
	 * Invoice facts for a sale order.
	 *
	 * @param ExternalRef $ref The sale order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string,payment_state:string,reject_reason:string,invoice_id:string,url:string,total_minor:int}|null Null when the order has no invoice yet.
	 *
	 * @throws OdooException On failure.
	 */
	public function fetch( ExternalRef $ref ): ?array {
		$orderId = (int) $ref->id;
		if ( $orderId < 1 ) {
			return null;
		}
		$order = $this->client->read( 'sale.order', array( $orderId ), array( 'invoice_ids' ) );
		$ids   = isset( $order[0] ) ? OdooRecord::ids( $order[0]['invoice_ids'] ?? array() ) : array();
		if ( array() === $ids ) {
			return null;
		}
		$have   = $this->client->fields( 'account.move', self::OPTIONAL );
		$fields = array( 'name', 'state', 'payment_state', 'invoice_date', 'amount_total', 'currency_id' );
		foreach ( array_keys( array_filter( $have ) ) as $field ) {
			$fields[] = $field;
		}
		$invoices = $this->client->searchRead(
			'account.move',
			array( array( 'id', 'in', $ids ), array( 'move_type', '=', 'out_invoice' ), array( 'state', '!=', 'cancel' ) ),
			$fields,
			array(
				'order' => 'id desc',
				'limit' => 1,
			)
		);
		if ( array() === $invoices ) {
			return null;
		}
		return $this->describe( $invoices[0], $have );
	}

	/**
	 * Facts of one invoice.
	 *
	 * @param array<string,mixed> $invoice Invoice record.
	 * @param array<string,bool>  $have    Optional fields the version has.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string,payment_state:string,reject_reason:string,invoice_id:string,url:string,total_minor:int}
	 */
	private function describe( array $invoice, array $have ): array {
		$id       = OdooRecord::id( $invoice['id'] ?? 0 );
		$state    = OdooRecord::text( $invoice['state'] ?? '' );
		$name     = OdooRecord::text( $invoice['name'] ?? '' );
		$currency = strtoupper( OdooRecord::name( $invoice['currency_id'] ?? false ) );
		$issued   = $this->amounts->fromOdooDatetime( $invoice['l10n_sa_confirmation_datetime'] ?? '' );
		if ( '' === $issued ) {
			$date   = OdooRecord::text( $invoice['invoice_date'] ?? '' );
			$issued = '' !== $date ? $date . 'T00:00:00Z' : '';
		}
		$problem = $this->plainText( OdooRecord::text( $invoice['edi_error_message'] ?? '' ) );

		return array(
			'number'           => 'posted' === $state && '/' !== $name ? $name : '',
			'status'           => $state,
			'authority_status' => $this->authorityStatus( $state, OdooRecord::text( $invoice['edi_state'] ?? '' ), '' !== $problem, ( $have['edi_state'] ?? false ) && ( $have['l10n_sa_uuid'] ?? false ) ),
			'pdf_url'          => 'posted' === $state ? $this->client->address()->url( '/report/pdf/account.report_invoice/' . $id ) : '',
			'qr'               => OdooRecord::text( $invoice['l10n_sa_qr_code_str'] ?? '' ),
			'issued_at'        => 'posted' === $state ? $issued : '',
			'payment_state'    => OdooRecord::text( $invoice['payment_state'] ?? '' ),
			'reject_reason'    => $problem,
			'invoice_id'       => (string) $id,
			'url'              => $this->client->recordUrl( 'account.move', $id ),
			'total_minor'      => $this->amounts->toMinor( $invoice['amount_total'] ?? 0, $this->amounts->exponent( $currency ) ),
		);
	}

	/**
	 * Status of the submission to the tax authority: waiting, rejected, reported or not_required.
	 *
	 * @param string $state       Invoice state (draft, posted).
	 * @param string $ediState    Odoo's aggregated electronic invoicing state (to_send, sent, to_cancel, cancelled), or empty.
	 * @param bool   $hasProblem  Whether Odoo recorded an error message.
	 * @param bool   $hasEInvoice Whether the Saudi e-invoicing fields exist on this server.
	 */
	private function authorityStatus( string $state, string $ediState, bool $hasProblem, bool $hasEInvoice ): string {
		if ( ! $hasEInvoice || 'posted' !== $state ) {
			return $hasEInvoice && 'draft' === $state ? 'waiting' : 'not_required';
		}
		return match ( $ediState ) {
			'sent'      => 'reported',
			'to_send'   => $hasProblem ? 'rejected' : 'waiting',
			'to_cancel' => 'waiting',
			'cancelled' => 'rejected',
			default     => $hasProblem ? 'rejected' : 'not_required',
		};
	}

	/**
	 * Odoo's error message is HTML; keep the words only.
	 *
	 * @param string $html Message.
	 */
	private function plainText( string $html ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $html ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 300 ) . '...' : $text;
	}
}
