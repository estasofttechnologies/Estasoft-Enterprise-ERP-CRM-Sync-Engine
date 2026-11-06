<?php
/**
 * The calls that read and write Daftra invoices, credit notes and payments.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thin, typed wrappers over the documented endpoints. A document is written as `{"Invoice": {...}, "InvoiceItem": [...]}` and a create answers
 * HTTP 202 with `{"result":"successful","code":202,"id":...}`. They decide nothing: the senders decide what to read and when to write.
 */
final class DaftraDocuments {

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraPager $pager Reads every page of a list.
	 */
	public function __construct( private readonly DaftraPager $pager ) {
	}

	/**
	 * The invoices with a purchase-order reference.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference.
	 * @return array<int,DaftraRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findInvoices( ApiClient $api, string $reference ): array {
		return $this->matching( $this->pager->all( $api, 'invoices.json', array( 'po_number' => $reference ), 'Invoice' ), $reference );
	}

	/**
	 * The credit notes with a reference, among those of one client. The list page documents a `client_id` filter and no reference filter, so every
	 * page of the client's credit notes is read and the reference is matched here; a credit note is found however long ago it was made.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference.
	 * @param string    $clientId  Daftra client id of the order's invoice.
	 * @return array<int,DaftraRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findCreditNotes( ApiClient $api, string $reference, string $clientId ): array {
		return $this->matching( $this->pager->all( $api, 'credit_notes.json', array( 'client_id' => $clientId ), 'CreditNote' ), $reference );
	}

	/**
	 * One credit note, or null when Daftra does not have it.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 *
	 * @throws ApiException On failure other than a missing credit note.
	 */
	public function creditNoteIfExists( ApiClient $api, string $id ): ?DaftraRecord {
		$response = $api->get( 'credit_notes/' . rawurlencode( $id ) . '.json', array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() ? DaftraRecord::fromAnswer( $response->json(), 'CreditNote' ) : null;
	}

	/**
	 * One invoice with its items and payments.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ApiClient $api, string $id ): DaftraRecord {
		return DaftraRecord::fromAnswer( $api->get( 'invoices/' . rawurlencode( $id ) . '.json' )->json(), 'Invoice' );
	}

	/**
	 * One credit note with its items.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 *
	 * @throws ApiException On failure.
	 */
	public function creditNote( ApiClient $api, string $id ): DaftraRecord {
		return DaftraRecord::fromAnswer( $api->get( 'credit_notes/' . rawurlencode( $id ) . '.json' )->json(), 'CreditNote' );
	}

	/**
	 * Create an invoice.
	 *
	 * @param ApiClient                      $api     Client.
	 * @param array<string,mixed>            $invoice Invoice fields.
	 * @param array<int,array<string,mixed>> $items   Invoice items.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function createInvoice( ApiClient $api, array $invoice, array $items ): string|ConnectorError {
		return $this->created(
			$api->post(
				'invoices.json',
				array(),
				array(
					'Invoice'     => $invoice,
					'InvoiceItem' => $items,
				)
			)->json(),
			'invoice'
		);
	}

	/**
	 * Create a credit note.
	 *
	 * @param ApiClient                      $api     Client.
	 * @param array<string,mixed>            $invoice Credit note fields (sent as the Invoice object, as the documentation shows).
	 * @param array<int,array<string,mixed>> $items   Items.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function createCreditNote( ApiClient $api, array $invoice, array $items ): string|ConnectorError {
		return $this->created(
			$api->post(
				'credit_notes.json',
				array(),
				array(
					'Invoice'     => $invoice,
					'InvoiceItem' => $items,
				)
			)->json(),
			'credit note'
		);
	}

	/**
	 * Issue a draft invoice: Daftra gives it its official number and posts its entries.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	public function issue( ApiClient $api, string $id ): void {
		$api->get( 'invoices/update_draft/' . rawurlencode( $id ) . '/0.json' );
	}

	/**
	 * Change fields of an invoice (the fields sent replace the old ones; items are not sent).
	 *
	 * @param ApiClient           $api     Client.
	 * @param string              $id      Invoice id.
	 * @param array<string,mixed> $changes Invoice fields to change.
	 *
	 * @throws ApiException On failure.
	 */
	public function updateInvoice( ApiClient $api, string $id, array $changes ): void {
		$api->put( 'invoices/' . rawurlencode( $id ) . '.json', array(), array( 'Invoice' => $changes ) );
	}

	/**
	 * Delete an invoice (used for a draft that failed the check).
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	public function deleteInvoice( ApiClient $api, string $id ): void {
		$api->delete( 'invoices/' . rawurlencode( $id ) . '.json' );
	}

	/**
	 * Delete a credit note (used for one that failed the check).
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 *
	 * @throws ApiException On failure.
	 */
	public function deleteCreditNote( ApiClient $api, string $id ): void {
		$api->delete( 'credit_notes/' . rawurlencode( $id ) . '.json' );
	}

	/**
	 * Record a payment of an invoice.
	 *
	 * @param ApiClient           $api     Client.
	 * @param array<string,mixed> $payment invoice_id, amount, payment_method, date, transaction_id.
	 *
	 * @throws ApiException On failure.
	 */
	public function createPayment( ApiClient $api, array $payment ): void {
		$api->post( 'invoice_payments.json', array(), array( 'InvoicePayment' => $payment ) );
	}

	/**
	 * The rows whose reference is exactly the one asked for, as records, in a stable order (lowest id first).
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows of a list.
	 * @param string                         $reference Reference.
	 * @return array<int,DaftraRecord>
	 */
	private function matching( array $rows, string $reference ): array {
		$found = array();
		foreach ( $rows as $row ) {
			$record = new DaftraRecord( $row );
			if ( $record->reference() === $reference ) {
				$found[] = $record;
			}
		}
		usort(
			$found,
			static fn( DaftraRecord $left, DaftraRecord $right ): int => (int) $left->id() <=> (int) $right->id()
		);
		return $found;
	}

	/**
	 * The id a create answered with.
	 *
	 * @param array<mixed> $json What the answer decoded to.
	 * @param string       $what Kind of record, for the message.
	 * @return string|ConnectorError
	 */
	private function created( array $json, string $what ): string|ConnectorError {
		$id = DaftraPayloadReader::text( $json['id'] ?? '' );
		if ( '' !== $id && ! ( isset( $json['result'] ) && 'successful' !== $json['result'] ) ) {
			return $id;
		}
		$message = DaftraPayloadReader::text( $json['message'] ?? '' );
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => DaftraConnector::LABEL,
				'field'  => $what,
				'reason' => '' !== $message ? $message : __( 'Daftra did not return the id of the new record.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			null,
			null,
			null,
			'created without id'
		);
	}
}
