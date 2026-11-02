<?php
/**
 * Invoices, credit notes, payments and allocations in Xero.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Every call carries the tenant header (set by the connector) and goes through the shared client, so pacing, redaction and error mapping
 * are the same everywhere. A search reads every page of a server-side `where` filter and then compares the reference itself.
 */
final class XeroInvoices {

	/**
	 * Statuses in the order a finished document is preferred when a reference matches more than one.
	 *
	 * @var array<int,string>
	 */
	private const PREFERRED = array( 'PAID', 'AUTHORISED', 'SUBMITTED', 'DRAFT' );

	/**
	 * Ids of deleted documents seen by the last search, by reference.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $dead = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient  $api    Client for the connection.
	 * @param XeroPaging $paging Paging helper.
	 */
	public function __construct( private readonly ApiClient $api, private readonly XeroPaging $paging = new XeroPaging() ) {
	}

	/**
	 * Ids of the documents with a reference that were deleted, as the last search for that reference saw them. They go into the idempotency key of the next create, so
	 * a create that follows the connector's own deletion of a draft is not answered with the deleted draft, while a plain retry (same body, nothing deleted) keeps the
	 * same key and gets the first answer.
	 *
	 * @param string $reference Reference such as WC-1042.
	 * @return array<int,string>
	 */
	public function deadIds( string $reference ): array {
		return $this->dead[ $reference ] ?? array();
	}

	/**
	 * The sales invoice with a reference.
	 *
	 * @param string $reference Reference, such as WC-1042.
	 * @return array<string,mixed>|null The invoice, null when none exists, or an array with only 'voided' => true when it was voided.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findInvoice( string $reference ): ?array {
		return $this->pick( $this->paging->all( $this->api, 'Invoices', array( 'where' => 'Type=="ACCREC" AND Reference=="' . $this->quote( $reference ) . '"' ), 'Invoices' ), $reference );
	}

	/**
	 * The sales credit note with a reference.
	 *
	 * @param string $reference Reference, such as WC-77.
	 * @return array<string,mixed>|null Same convention as findInvoice().
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findCreditNote( string $reference ): ?array {
		return $this->pick( $this->paging->all( $this->api, 'CreditNotes', array( 'where' => 'Type=="ACCRECCREDIT" AND Reference=="' . $this->quote( $reference ) . '"' ), 'CreditNotes' ), $reference );
	}

	/**
	 * One invoice by id.
	 *
	 * @param string $id InvoiceID.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function invoice( string $id ): ?array {
		return $this->single( $this->api->get( 'Invoices/' . rawurlencode( $id ), array( 'unitdp' => 4 ), null, array( 'allow' => array( 404 ) ) ), 'Invoices' );
	}

	/**
	 * One credit note by id.
	 *
	 * @param string $id CreditNoteID.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function creditNote( string $id ): ?array {
		return $this->single( $this->api->get( 'CreditNotes/' . rawurlencode( $id ), array( 'unitdp' => 4 ), null, array( 'allow' => array( 404 ) ) ), 'CreditNotes' );
	}

	/**
	 * Create a sales invoice.
	 *
	 * @param array<string,mixed> $invoice         Invoice.
	 * @param string              $key             Idempotency key.
	 * @param bool                $allowBackorders Let tracked items go below zero.
	 * @return array<string,mixed> The created invoice.
	 *
	 * @throws ApiException On a failed call or an answer that holds validation errors.
	 */
	public function createInvoice( array $invoice, string $key, bool $allowBackorders ): array {
		$response = $this->api->put(
			'Invoices',
			array(
				'summarizeErrors' => 'true',
				'unitdp'          => 4,
				'allowBackorders' => $allowBackorders ? 'true' : 'false',
			),
			array( 'Invoices' => array( $invoice ) ),
			array( 'idempotency_key' => $key )
		);
		return $this->created( $response, 'Invoices' );
	}

	/**
	 * Create a sales credit note.
	 *
	 * @param array<string,mixed> $note Credit note.
	 * @param string              $key  Idempotency key.
	 * @return array<string,mixed> The created credit note.
	 *
	 * @throws ApiException On a failed call or an answer that holds validation errors.
	 */
	public function createCreditNote( array $note, string $key ): array {
		$response = $this->api->put(
			'CreditNotes',
			array(
				'summarizeErrors' => 'true',
				'unitdp'          => 4,
			),
			array( 'CreditNotes' => array( $note ) ),
			array( 'idempotency_key' => $key )
		);
		return $this->created( $response, 'CreditNotes' );
	}

	/**
	 * Change the status of an invoice (AUTHORISED to approve, DELETED to remove a draft).
	 *
	 * @param string $id     InvoiceID.
	 * @param string $status New status.
	 * @return array<string,mixed> The invoice after the change.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function setInvoiceStatus( string $id, string $status ): array {
		$response = $this->api->post(
			'Invoices/' . rawurlencode( $id ),
			array( 'unitdp' => 4 ),
			array(
				'Invoices' => array(
					array(
						'InvoiceID' => $id,
						'Status'    => $status,
					),
				),
			)
		);
		return $this->created( $response, 'Invoices' );
	}

	/**
	 * Change the status of a credit note.
	 *
	 * @param string $id     CreditNoteID.
	 * @param string $status New status.
	 * @return array<string,mixed> The credit note after the change.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function setCreditNoteStatus( string $id, string $status ): array {
		$response = $this->api->post(
			'CreditNotes/' . rawurlencode( $id ),
			array( 'unitdp' => 4 ),
			array(
				'CreditNotes' => array(
					array(
						'CreditNoteID' => $id,
						'Status'       => $status,
					),
				),
			)
		);
		return $this->created( $response, 'CreditNotes' );
	}

	/**
	 * Record a payment against an invoice.
	 *
	 * @param array<string,mixed> $payment Payment: Invoice, Account, Date, Amount, Reference.
	 * @param string              $key     Idempotency key.
	 * @return array<string,mixed> The payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function createPayment( array $payment, string $key ): array {
		$response = $this->api->put( 'Payments', array(), array( 'Payments' => array( $payment ) ), array( 'idempotency_key' => $key ) );
		return $this->created( $response, 'Payments' );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param string $creditNoteId CreditNoteID.
	 * @param string $invoiceId    InvoiceID.
	 * @param float  $amount       Amount to apply, as a number.
	 * @param string $day          Date of the allocation (YYYY-MM-DD).
	 * @param string $key          Idempotency key.
	 * @return array<string,mixed> The allocation.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function allocate( string $creditNoteId, string $invoiceId, float $amount, string $day, string $key ): array {
		$allocation = array(
			'Invoice' => array( 'InvoiceID' => $invoiceId ),
			'Amount'  => $amount,
			'Date'    => $day,
		);
		$response   = $this->api->put( 'CreditNotes/' . rawurlencode( $creditNoteId ) . '/Allocations', array( 'summarizeErrors' => 'true' ), array( 'Allocations' => array( $allocation ) ), array( 'idempotency_key' => $key ) );
		return $this->created( $response, 'Allocations' );
	}

	/**
	 * Pick the document to use among the ones a search found.
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows from the server.
	 * @param string                         $reference Reference searched.
	 * @return array<string,mixed>|null
	 */
	private function pick( array $rows, string $reference ): ?array {
		$live   = array();
		$voided = false;
		$dead   = array();
		foreach ( $rows as $row ) {
			if ( ( $row['Reference'] ?? '' ) !== $reference ) {
				continue;
			}
			$status = (string) ( $row['Status'] ?? '' );
			if ( 'DELETED' === $status ) {
				$dead[] = XeroPayloadReader::text( $row['InvoiceID'] ?? ( $row['CreditNoteID'] ?? '' ) );
				continue;
			}
			if ( 'VOIDED' === $status ) {
				$voided = true;
				continue;
			}
			$live[] = $row;
		}
		sort( $dead );
		$this->dead[ $reference ] = array_values( array_filter( array_unique( $dead ), static fn( string $id ): bool => '' !== $id ) );
		if ( array() === $live ) {
			return $voided ? array( 'voided' => true ) : null;
		}
		usort(
			$live,
			static function ( array $a, array $b ): int {
				$rank = static fn( array $row ): int => (int) array_search( (string) ( $row['Status'] ?? '' ), self::PREFERRED, true );
				return $rank( $a ) <=> $rank( $b );
			}
		);
		return $live[0];
	}

	/**
	 * The first element of a one-document answer.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $key      Key of the list.
	 * @return array<string,mixed>|null
	 */
	private function single( ApiResponse $response, string $key ): ?array {
		if ( 404 === $response->status() ) {
			return null;
		}
		$data = $response->json();
		$row  = isset( $data[ $key ][0] ) && is_array( $data[ $key ][0] ) ? $data[ $key ][0] : null;
		return $row;
	}

	/**
	 * The element a create or update returned, refusing an answer that carries validation errors.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $key      Key of the list.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When the answer holds validation errors or no element.
	 */
	private function created( ApiResponse $response, string $key ): array {
		return XeroAnswer::element( $response, $key );
	}

	/**
	 * A value for a where filter: a plain string with no quotes or control characters.
	 *
	 * @param string $value Value.
	 */
	private function quote( string $value ): string {
		return str_replace( array( '"', '\\' ), '', preg_replace( '/[\x00-\x1F]/', '', $value ) ?? '' );
	}
}
