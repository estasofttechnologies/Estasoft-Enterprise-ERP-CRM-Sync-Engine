<?php
/**
 * The calls that read and write Qoyod invoices, credit notes and payments.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thin, typed wrappers over the endpoints. Requests wrap their body in the resource name (`{"invoice": {...}}`) as the documented
 * examples of Qoyod do. They decide nothing: the senders decide what to read and when to write.
 */
final class QoyodDocuments {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodPager $pager Reads every page of a list.
	 */
	public function __construct( private readonly QoyodPager $pager ) {
	}

	/**
	 * The invoices with a reference.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference.
	 * @return array<int,QoyodRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findInvoices( ApiClient $api, string $reference ): array {
		return $this->search( $api, 'invoices', 'invoices', $reference );
	}

	/**
	 * The credit notes with a reference.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference.
	 * @return array<int,QoyodRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findCreditNotes( ApiClient $api, string $reference ): array {
		return $this->search( $api, 'credit_notes', 'credit_notes', $reference );
	}

	/**
	 * The payments with a reference.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference.
	 * @return array<int,QoyodRecord>
	 *
	 * @throws ApiException On failure.
	 */
	public function findPayments( ApiClient $api, string $reference ): array {
		return $this->search( $api, 'invoice_payments', 'invoice_payments', $reference );
	}

	/**
	 * One invoice.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ApiClient $api, string $id ): QoyodRecord {
		return QoyodRecord::unwrap( $api->get( 'invoices/' . rawurlencode( $id ) )->json(), 'invoice' );
	}

	/**
	 * One credit note.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 *
	 * @throws ApiException On failure.
	 */
	public function creditNote( ApiClient $api, string $id ): QoyodRecord {
		return QoyodRecord::unwrap( $api->get( 'credit_notes/' . rawurlencode( $id ) )->json(), 'credit_note' );
	}

	/**
	 * Create an invoice.
	 *
	 * @param ApiClient           $api  Client.
	 * @param array<string,mixed> $body Invoice fields.
	 * @return QoyodRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function createInvoice( ApiClient $api, array $body ): QoyodRecord|ConnectorError {
		return $this->created( $api->post( 'invoices', array(), array( 'invoice' => $body ) )->json(), 'invoice' );
	}

	/**
	 * Create a credit note.
	 *
	 * @param ApiClient           $api  Client.
	 * @param array<string,mixed> $body Credit note fields.
	 * @return QoyodRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function createCreditNote( ApiClient $api, array $body ): QoyodRecord|ConnectorError {
		return $this->created( $api->post( 'credit_notes', array(), array( 'credit_note' => $body ) )->json(), 'credit_note' );
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
		$api->patch( 'invoices/' . rawurlencode( $id ), array(), array( 'invoice' => $changes ) );
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
		$api->patch( 'credit_notes/' . rawurlencode( $id ), array(), array( 'credit_note' => $changes ) );
	}

	/**
	 * Record a payment of an invoice.
	 *
	 * @param ApiClient            $api       Client.
	 * @param array<string,string> $payment   account_id, amount, date, invoice_id, reference.
	 *
	 * @throws ApiException On failure.
	 */
	public function createPayment( ApiClient $api, array $payment ): void {
		$api->post( 'invoice_payments', array(), array( 'invoice_payment' => $payment ) );
	}

	/**
	 * Allocate a credit note to an invoice.
	 *
	 * @param ApiClient $api        Client.
	 * @param string    $invoice    Invoice id.
	 * @param string    $creditNote Credit note id.
	 * @param string    $amount     Amount as a decimal string.
	 * @param string    $date       Y-m-d.
	 *
	 * @throws ApiException On failure.
	 */
	public function allocateCreditNote( ApiClient $api, string $invoice, string $creditNote, string $amount, string $date ): void {
		$api->post(
			'invoices/' . rawurlencode( $invoice ) . '/allocations',
			array(),
			array(
				'invoice' => array(
					'allocations_attributes' => array(
						array(
							'amount'      => $amount,
							'date'        => $date,
							'source_id'   => (int) $creditNote,
							'source_type' => 'CreditNote',
						),
					),
				),
			)
		);
	}

	/**
	 * Search a list by reference (Ransack `reference_eq`) and keep the rows whose reference is exactly the one asked for.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $path      List path.
	 * @param string    $root      Key that holds the rows.
	 * @param string    $reference Reference.
	 * @return array<int,QoyodRecord>
	 *
	 * @throws ApiException On failure.
	 */
	private function search( ApiClient $api, string $path, string $root, string $reference ): array {
		$found = array();
		foreach ( $this->pager->all( $api, $path, array( 'q' => array( 'reference_eq' => $reference ) ), $root ) as $row ) {
			$record = new QoyodRecord( $row );
			if ( $record->reference() === $reference ) {
				$found[] = $record;
			}
		}
		usort(
			$found,
			static fn( QoyodRecord $left, QoyodRecord $right ): int => (int) $left->id() <=> (int) $right->id()
		);
		return $found;
	}

	/**
	 * The record a create answered with.
	 *
	 * @param array<mixed> $json What the answer decoded to.
	 * @param string       $key  Wrapper key.
	 * @return QoyodRecord|ConnectorError
	 */
	private function created( array $json, string $key ): QoyodRecord|ConnectorError {
		$record = QoyodRecord::unwrap( $json, $key );
		if ( '' !== $record->id() ) {
			return $record;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => $key,
				'reason' => __( 'Qoyod did not return the id of the new record.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'created without id'
		);
	}
}
