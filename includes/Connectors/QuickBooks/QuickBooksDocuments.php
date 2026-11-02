<?php
/**
 * Invoices, credit memos and payments in QuickBooks.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Search by DocNumber (the shop's reference), create, mark as checked, delete, pay. QuickBooks posts an invoice when it is created and has no
 * draft state, so a document the connector made carries "[unchecked]" in its private note until its total has been read back; a sparse update then
 * removes the marker. A document that still has the marker is never counted as finished.
 */
final class QuickBooksDocuments {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksApi $api Client.
	 */
	public function __construct( private readonly QuickBooksApi $api ) {
	}

	/**
	 * The invoice with a document number.
	 *
	 * @param string $number DocNumber, such as WC-1042.
	 * @return array<string,mixed>|null The invoice, null when none exists, or an array with only 'voided' => true when it was voided.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findInvoice( string $number ): ?array {
		return $this->pick( $this->api->select( 'Invoice', 'DocNumber = ' . QuickBooksApi::quote( $number ) ), $number );
	}

	/**
	 * The credit memo with a document number.
	 *
	 * @param string $number DocNumber, such as WC-77.
	 * @return array<string,mixed>|null Same convention as findInvoice().
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findCreditMemo( string $number ): ?array {
		return $this->pick( $this->api->select( 'CreditMemo', 'DocNumber = ' . QuickBooksApi::quote( $number ) ), $number );
	}

	/**
	 * One invoice by id.
	 *
	 * @param string $id Invoice Id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function invoice( string $id ): ?array {
		return $this->fetch( 'invoice', $id );
	}

	/**
	 * One document by id.
	 *
	 * @param string $entity invoice or creditmemo.
	 * @param string $id     Document Id.
	 * @return array<string,mixed>|null Null when QuickBooks has no such document (or it was deleted).
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function fetch( string $entity, string $id ): ?array {
		$response = $this->api->get( $entity . '/' . rawurlencode( $id ), array(), array( 'allow' => array( 400, 404 ) ) );
		if ( 404 === $response->status() || ( 400 === $response->status() && QuickBooksErrors::has( $response, '610' ) ) ) {
			return null;
		}
		if ( 400 === $response->status() ) {
			$failure = QuickBooksErrors::exception( QuickBooksErrors::fromResponse( $response ) ?? QuickBooksErrors::refused( $entity, __( 'QuickBooks refused the request.', 'estasoft-erp-crm-sync-engine' ), 400 ), $response );
			throw $failure;
		}
		return $this->api->entity( $response, self::key( $entity ) );
	}

	/**
	 * Create a document.
	 *
	 * @param string              $entity    invoice or creditmemo.
	 * @param array<string,mixed> $body      Body.
	 * @param string              $requestId requestid value.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function create( string $entity, array $body, string $requestId ): array {
		return $this->api->entity( $this->api->post( $entity, $body, array(), $requestId ), self::key( $entity ) );
	}

	/**
	 * Remove the unchecked marker from the private note. A sparse update needs the document's current SyncToken; when QuickBooks says the token is
	 * stale (error 5010: the answer in hand is older than the document, for example a replayed answer), the document is read again and the update
	 * is tried once more.
	 *
	 * @param string              $entity   invoice or creditmemo.
	 * @param array<string,mixed> $document The document as last returned.
	 * @return array<string,mixed> The updated document.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function markChecked( string $entity, array $document ): array {
		$id = QuickBooksPayloadReader::text( $document['Id'] ?? '' );
		try {
			return $this->clean( $entity, $document );
		} catch ( ApiException $stale ) {
			if ( ! QuickBooksErrors::has( $stale->response(), '5010' ) ) {
				throw $stale;
			}
		}
		$fresh = $this->fetch( $entity, $id );
		if ( null === $fresh ) {
			throw $stale;
		}
		return self::isUnchecked( $fresh ) ? $this->clean( $entity, $fresh ) : $fresh;
	}

	/**
	 * Delete a document the connector made and found wrong.
	 *
	 * @param string              $entity   invoice or creditmemo.
	 * @param array<string,mixed> $document The document.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function delete( string $entity, array $document ): void {
		$this->api->post(
			$entity,
			array(
				'Id'        => QuickBooksPayloadReader::text( $document['Id'] ?? '' ),
				'SyncToken' => QuickBooksPayloadReader::text( $document['SyncToken'] ?? '0' ),
			),
			array( 'operation' => 'delete' )
		);
	}

	/**
	 * Record a payment against an invoice.
	 *
	 * @param array<string,mixed> $payment   Payment.
	 * @param string              $requestId requestid value.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function pay( array $payment, string $requestId ): array {
		return $this->api->entity( $this->api->post( 'payment', $payment, array(), $requestId ), 'Payment' );
	}

	/**
	 * Send the sparse update that removes the marker.
	 *
	 * @param string              $entity   invoice or creditmemo.
	 * @param array<string,mixed> $document The document with the SyncToken to use.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function clean( string $entity, array $document ): array {
		$note = str_replace( QuickBooksDocumentBuilder::UNCHECKED, '', QuickBooksPayloadReader::text( $document['PrivateNote'] ?? '' ) );
		$body = array(
			'Id'          => QuickBooksPayloadReader::text( $document['Id'] ?? '' ),
			'SyncToken'   => QuickBooksPayloadReader::text( $document['SyncToken'] ?? '0' ),
			'sparse'      => true,
			'PrivateNote' => $note,
		);
		return $this->api->entity( $this->api->post( $entity, $body ), self::key( $entity ) );
	}

	/**
	 * The key of the entity in an answer.
	 *
	 * @param string $entity invoice or creditmemo.
	 */
	private static function key( string $entity ): string {
		return 'creditmemo' === $entity ? 'CreditMemo' : 'Invoice';
	}

	/**
	 * Whether a document still has the unchecked marker.
	 *
	 * @param array<string,mixed> $document Document.
	 */
	public static function isUnchecked( array $document ): bool {
		return false !== strpos( QuickBooksPayloadReader::text( $document['PrivateNote'] ?? '' ), QuickBooksDocumentBuilder::UNCHECKED );
	}

	/**
	 * Pick the document to use among the ones a search found.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows from the server.
	 * @param string                         $number DocNumber searched.
	 * @return array<string,mixed>|null
	 */
	private function pick( array $rows, string $number ): ?array {
		$live   = array();
		$voided = false;
		foreach ( $rows as $row ) {
			if ( ( $row['DocNumber'] ?? '' ) !== $number ) {
				continue;
			}
			if ( is_numeric( $row['TotalAmt'] ?? null ) && 0.0 === (float) $row['TotalAmt'] && false !== stripos( QuickBooksPayloadReader::text( $row['PrivateNote'] ?? '' ), 'voided' ) ) {
				$voided = true;
				continue;
			}
			$live[] = $row;
		}
		if ( array() === $live ) {
			return $voided ? array( 'voided' => true ) : null;
		}
		usort(
			$live,
			static fn( array $a, array $b ): int => (int) self::isUnchecked( $a ) <=> (int) self::isUnchecked( $b )
		);
		return $live[0];
	}
}
