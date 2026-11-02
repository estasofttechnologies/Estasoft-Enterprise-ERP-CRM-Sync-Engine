<?php
/**
 * Orders as Pennylane customer invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Tolerance;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order flow: search by `external_reference`, customer, lines, a DRAFT invoice (`draft: true`), read back, total check and, in finalised mode, the
 * finalise call. Omitting `draft` would finalise at once [documented] and a finalised invoice can only be cancelled with a credit note, so the
 * connector always creates a draft, checks it, and only then finalises. Every step starts from a read, so a pass that stopped half way carries on.
 */
final class PennylaneDocuments {

	/**
	 * Set up the helper.
	 *
	 * @param PennylaneCustomers $customers Customer lookups.
	 * @param PennylaneLines     $lines     Line builder.
	 * @param PennylaneApi       $pennylane Request helpers.
	 * @param Amounts            $amounts   Conversion helper.
	 */
	public function __construct(
		private readonly PennylaneCustomers $customers,
		private readonly PennylaneLines $lines,
		private readonly PennylaneApi $pennylane,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * The invoice with an external reference, or null. Credit notes are ignored.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference External reference such as WC-1234.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findByReference( ApiClient $api, string $reference ): ?array {
		$cursor = null;
		for ( $i = 0; $i < 10; $i++ ) {
			$page = $this->pennylane->page( $api, 'customer_invoices', array( array( 'external_reference', 'eq', $reference ) ), $cursor );
			foreach ( $page['items'] as $invoice ) {
				if ( PayloadReader::text( $invoice['external_reference'] ?? '' ) === $reference && empty( $invoice['credit_note'] ) ) {
					return $invoice;
				}
			}
			$cursor = $page['next'];
			if ( null === $cursor ) {
				break;
			}
		}
		return null;
	}

	/**
	 * Send an order.
	 *
	 * @param ApiClient    $api     Client.
	 * @param Item         $item    Queue item.
	 * @param OrderPayload $order   Mapped order.
	 * @param Options      $options Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendOrder( ApiClient $api, Item $item, OrderPayload $order, Options $options ): SendResult {
		if ( 'EUR' !== $order->currency ) {
			return SendResult::failure( $this->currencyRefusal( $order->currency ) );
		}
		$reference = $item->clientReference();
		$existing  = $this->findByReference( $api, $reference );
		if ( null !== $existing ) {
			$result = $this->finish( $api, PayloadReader::text( $existing['id'] ?? '' ), $order, $options, array() );
			return $result->isSuccess() ? SendResult::existing( $result->ref ) : $result;
		}
		$customer = $this->customers->resolve( $api, $order->customer, $options, $reference );
		if ( $customer instanceof ConnectorError ) {
			return SendResult::failure( $customer );
		}
		$built = $this->lines->build( $api, $order, $options );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$timezone = $options->text( 'timezone' );
		$date     = LocalDate::ymd( $order->date, $timezone );
		$deadline = LocalDate::at( $order->date, $timezone );
		$body     = array(
			'customer_id'        => (int) $customer['id'],
			'date'               => $date,
			'deadline'           => null === $deadline ? $date : $deadline->modify( '+' . $options->int( 'payment_days' ) . ' days' )->format( 'Y-m-d' ),
			'draft'              => true,
			'external_reference' => $reference,
			'invoice_lines'      => $built['lines'],
		);
		if ( '' !== $options->text( 'customer_invoice_template_id' ) ) {
			$body['customer_invoice_template_id'] = (int) $options->text( 'customer_invoice_template_id' );
		}
		$created = $api->post( 'customer_invoices', array(), $body )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( PennylaneConnector::LABEL, 'invoice', __( 'Pennylane did not return the new invoice.', 'estasoft-erp-crm-sync-engine' ), 'no invoice id' ) );
		}
		return $this->finish( $api, $id, $order, $options, $built['warnings'] );
	}

	/**
	 * The steps after the invoice exists: read back, check the total, finalise when asked.
	 *
	 * @param ApiClient         $api      Client.
	 * @param string            $id       Invoice id.
	 * @param OrderPayload      $order    Order.
	 * @param Options           $options  Settings.
	 * @param array<int,string> $warnings Warnings so far.
	 *
	 * @throws ApiException On failure.
	 */
	private function finish( ApiClient $api, string $id, OrderPayload $order, Options $options, array $warnings ): SendResult {
		$invoice = $this->read( $api, $id );
		if ( null === $invoice ) {
			return SendResult::failure( Refusal::value( PennylaneConnector::LABEL, 'invoice', __( 'Pennylane could not read the invoice back.', 'estasoft-erp-crm-sync-engine' ), 'read back failed' ) );
		}
		$problem = $this->check( $invoice, $order->totalMinor, $options, $warnings, $order->rows(), $order->date );
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		if ( $options->is( 'invoice_state', 'finalized' ) && $this->isDraft( $invoice ) ) {
			$response = $api->put( 'customer_invoices/' . rawurlencode( $id ) . '/finalize', array(), null, array( 'allow' => array( 404, 405 ) ) );
			if ( ! $response->isSuccess() ) {
				return SendResult::failure( Refusal::value( PennylaneConnector::LABEL, 'finalize', __( 'Pennylane did not accept the finalise call. The invoice stays a draft.', 'estasoft-erp-crm-sync-engine' ), 'finalize refused' ) );
			}
			$invoice = $this->read( $api, $id ) ?? $invoice;
			if ( LocalDate::differs( $invoice['date'] ?? null, $order->date, $options->text( 'timezone' ) ) ) {
				$warnings[] = 'date_differs';
			}
		}
		return SendResult::created( $this->ref( $invoice, $warnings ) );
	}

	/**
	 * Read an invoice.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function read( ApiClient $api, string $id ): ?array {
		$response = $api->get( 'customer_invoices/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() ? $response->json() : null;
	}

	/**
	 * Whether an invoice is still a draft.
	 *
	 * @param array<string,mixed> $invoice Invoice as read.
	 */
	public function isDraft( array $invoice ): bool {
		return 'draft' === PayloadReader::text( $invoice['status'] ?? '' ) || ! empty( $invoice['draft'] );
	}

	/**
	 * Compare the gross total with the shop's.
	 *
	 * @param array<string,mixed> $invoice  Invoice as read.
	 * @param int|null            $expected Expected total in minor units, or null for no check.
	 * @param Options             $options  Settings.
	 * @param array<int,string>   $warnings Warnings, extended when the totals differ and the setting is warn, or when the date differs.
	 * @param int                 $rows     Rows of the document, for the default tolerance.
	 * @param string              $utcDate  The date (UTC text) the connector converted into `date`, or empty for no date check.
	 */
	public function check( array $invoice, ?int $expected, Options $options, array &$warnings, int $rows = 1, string $utcDate = '' ): ?ConnectorError {
		if ( '' !== $utcDate && LocalDate::differs( $invoice['date'] ?? null, $utcDate, $options->text( 'timezone' ) ) ) {
			$warnings[] = 'date_differs';
		}
		if ( null === $expected ) {
			return null;
		}
		$actual = $this->amounts->toMinor( $invoice['currency_amount'] ?? null, 2 );
		if ( null === $actual ) {
			return Refusal::value( PennylaneConnector::LABEL, 'currency_amount', __( 'Pennylane returned no total for the invoice.', 'estasoft-erp-crm-sync-engine' ), 'no total' );
		}
		if ( Tolerance::accepts( $actual, $expected, $options, $rows ) ) {
			return null;
		}
		if ( $options->is( 'total_mismatch', 'warn' ) ) {
			$warnings[] = 'total_differs';
			return null;
		}
		return Refusal::totals( PennylaneConnector::LABEL, 'currency_amount', $this->amounts->decimal( $expected, 2 ) . ' EUR', $this->amounts->decimal( $actual, 2 ) . ' EUR' );
	}

	/**
	 * The reference the engine stores for a document.
	 *
	 * @param array<string,mixed> $invoice  Invoice as read.
	 * @param array<int,string>   $warnings Warnings.
	 */
	public function ref( array $invoice, array $warnings ): ExternalRef {
		$extra = array(
			'total_minor' => $this->amounts->toMinor( $invoice['currency_amount'] ?? null, 2 ),
			'currency'    => 'EUR',
			'status'      => $this->isDraft( $invoice ) ? 'draft' : PayloadReader::text( $invoice['status'] ?? 'finalized' ),
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = array_values( array_unique( $warnings ) );
		}
		return new ExternalRef( PayloadReader::text( $invoice['id'] ?? '' ), PayloadReader::text( $invoice['invoice_number'] ?? '' ), null, $extra );
	}

	/**
	 * SYNC-VAL-002 for a currency other than euro.
	 *
	 * @param string $currency Currency of the order.
	 */
	public function currencyRefusal( string $currency ): ConnectorError {
		return Refusal::value(
			PennylaneConnector::LABEL,
			'currency',
			sprintf(
				/* translators: %s: currency code. */
				__( 'The Pennylane connector supports euro only; the order is in %s.', 'estasoft-erp-crm-sync-engine' ),
				'' === $currency ? '?' : $currency
			),
			'currency not supported'
		);
	}
}
