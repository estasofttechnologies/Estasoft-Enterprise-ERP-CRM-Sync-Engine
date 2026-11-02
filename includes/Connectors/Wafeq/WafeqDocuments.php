<?php
/**
 * The calls that read and write Wafeq invoices, credit notes and payments.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thin, typed wrappers over the documented endpoints. They decide nothing: the senders decide what to read and when to write.
 */
final class WafeqDocuments {

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqPager $pager Reads every page of a list.
	 */
	public function __construct( private readonly WafeqPager $pager ) {
	}

	/**
	 * The invoices with an external id, in a stable order (lowest id first).
	 *
	 * @param ApiClient $api      Client.
	 * @param string    $external External id.
	 * @return array<int,WafeqRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findInvoices( ApiClient $api, string $external ): array {
		return $this->search( $api, 'invoices/', $external, array( 'expand' => 'line_items' ) );
	}

	/**
	 * The credit notes with an external id, in a stable order (lowest id first).
	 *
	 * @param ApiClient $api      Client.
	 * @param string    $external External id.
	 * @return array<int,WafeqRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findCreditNotes( ApiClient $api, string $external ): array {
		return $this->search( $api, 'credit-notes/', $external, array( 'expand' => 'line_items' ) );
	}

	/**
	 * The payments with an external id, in a stable order (lowest id first).
	 *
	 * @param ApiClient $api      Client.
	 * @param string    $external External id.
	 * @return array<int,WafeqRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findPayments( ApiClient $api, string $external ): array {
		return $this->search( $api, 'payments/', $external, array() );
	}

	/**
	 * One invoice with its lines.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ApiClient $api, string $id ): WafeqRecord {
		return new WafeqRecord( $api->get( 'invoices/' . rawurlencode( $id ) . '/', array( 'expand' => 'line_items' ) )->json() );
	}

	/**
	 * One credit note with its lines.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 *
	 * @throws ApiException On failure.
	 */
	public function creditNote( ApiClient $api, string $id ): WafeqRecord {
		return new WafeqRecord( $api->get( 'credit-notes/' . rawurlencode( $id ) . '/', array( 'expand' => 'line_items' ) )->json() );
	}

	/**
	 * Create an invoice.
	 *
	 * @param ApiClient           $api  Client.
	 * @param array<string,mixed> $body Invoice body.
	 * @param string              $key  Idempotency key.
	 * @return WafeqRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function createInvoice( ApiClient $api, array $body, string $key ): WafeqRecord|ConnectorError {
		return $this->created( $api->post( 'invoices/', array(), $body, array( 'idempotency_key' => $key ) )->json(), 'invoice' );
	}

	/**
	 * Create a credit note.
	 *
	 * @param ApiClient           $api  Client.
	 * @param array<string,mixed> $body Credit note body.
	 * @param string              $key  Idempotency key.
	 * @return WafeqRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function createCreditNote( ApiClient $api, array $body, string $key ): WafeqRecord|ConnectorError {
		return $this->created( $api->post( 'credit-notes/', array(), $body, array( 'idempotency_key' => $key ) )->json(), 'credit note' );
	}

	/**
	 * Ask Wafeq to report a finalized invoice to the tax authority. The answer to a first request is documented as status `PENDING` (the
	 * authority's verdict is still outstanding) and a request made while a report runs as 409 `report_in_progress` [documented].
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 * @param string    $key Idempotency key.
	 * @return string `pending` (or the status Wafeq names, lower case), `in_progress` or `requested` when the answer names none.
	 *
	 * @throws ApiException On failure other than a report already running.
	 */
	public function report( ApiClient $api, string $id, string $key ): string {
		$response = $api->post(
			'invoices/' . rawurlencode( $id ) . '/tax-authority/report/',
			array(),
			null,
			array(
				'idempotency_key' => $key,
				'allow'           => array( 409 ),
			)
		);
		if ( 409 === $response->status() ) {
			return 'in_progress';
		}
		$status = strtolower( WafeqPayloadReader::text( $response->json()['status'] ?? '' ) );
		return '' !== $status ? $status : 'requested';
	}

	/**
	 * Change fields of an invoice.
	 *
	 * @param ApiClient           $api     Client.
	 * @param string              $id      Invoice id.
	 * @param array<string,mixed> $changes Fields to change.
	 *
	 * @throws ApiException On failure.
	 */
	public function patchInvoice( ApiClient $api, string $id, array $changes ): void {
		$api->patch( 'invoices/' . rawurlencode( $id ) . '/', array(), $changes );
	}

	/**
	 * Change fields of a credit note.
	 *
	 * @param ApiClient           $api     Client.
	 * @param string              $id      Credit note id.
	 * @param array<string,mixed> $changes Fields to change.
	 *
	 * @throws ApiException On failure.
	 */
	public function patchCreditNote( ApiClient $api, string $id, array $changes ): void {
		$api->patch( 'credit-notes/' . rawurlencode( $id ) . '/', array(), $changes );
	}

	/**
	 * Record a payment.
	 *
	 * @param ApiClient           $api  Client.
	 * @param array<string,mixed> $body Payment body.
	 * @param string              $key  Idempotency key.
	 * @return WafeqRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function createPayment( ApiClient $api, array $body, string $key ): WafeqRecord|ConnectorError {
		return $this->created( $api->post( 'payments/', array(), $body, array( 'idempotency_key' => $key ) )->json(), 'payment' );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param ApiClient $api        Client.
	 * @param string    $creditNote Credit note id.
	 * @param string    $invoice    Invoice id.
	 * @param int|float $amount     Amount to apply, positive.
	 * @param string    $key        Idempotency key.
	 *
	 * @throws ApiException On failure.
	 */
	public function applyCreditNote( ApiClient $api, string $creditNote, string $invoice, int|float $amount, string $key ): void {
		$api->post(
			'invoice-credit-notes/',
			array(),
			array(
				'credit_note' => $creditNote,
				'invoice'     => $invoice,
				'amount'      => $amount,
			),
			array( 'idempotency_key' => $key )
		);
	}

	/**
	 * Search a list by external id and keep the rows whose external id is exactly the one asked for.
	 *
	 * @param ApiClient           $api      Client.
	 * @param string              $path     List path.
	 * @param string              $external External id.
	 * @param array<string,mixed> $extra    More query parameters.
	 * @return array<int,WafeqRecord>
	 *
	 * @throws ApiException On failure.
	 */
	private function search( ApiClient $api, string $path, string $external, array $extra ): array {
		$found = array();
		foreach ( $this->pager->all( $api, $path, array( 'external_id' => $external ) + $extra ) as $row ) {
			$record = new WafeqRecord( $row );
			if ( $record->externalId() === $external ) {
				$found[] = $record;
			}
		}
		usort(
			$found,
			static fn( WafeqRecord $left, WafeqRecord $right ): int => strcmp( $left->id(), $right->id() )
		);
		return $found;
	}

	/**
	 * The record a create answered with.
	 *
	 * @param array<mixed> $json What the answer decoded to.
	 * @param string       $what Kind of record, for the message.
	 * @return WafeqRecord|ConnectorError
	 */
	private function created( array $json, string $what ): WafeqRecord|ConnectorError {
		$record = new WafeqRecord( $json );
		if ( '' !== $record->id() ) {
			return $record;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => WafeqConnector::LABEL,
				'field'  => $what,
				'reason' => __( 'Wafeq did not return the id of the new record.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'created without id'
		);
	}
}
