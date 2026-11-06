<?php
/**
 * Refunds as sevDesk credit notes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A credit note (`CreditNote/Factory/saveCreditNote`) on sevDesk-Update 2.0 [third-party mirror of the reference]. The credit note has no documented
 * link to the invoice; the heading names both references. Positions are net (the line's net amount, or shipping and other amounts converted at the
 * invoice's rate). The total is read back and compared with the refund; a draft that does not match is removed again.
 */
final class SevdeskRefunds {

	/**
	 * Build the helper.
	 *
	 * @param SevdeskContacts  $contacts  Contact lookups.
	 * @param SevdeskSearch    $search    Duplicate search.
	 * @param SevdeskDocuments $documents Shared document helpers.
	 * @param SevdeskReference $reference Reference data.
	 * @param Amounts          $amounts   Conversion helper.
	 */
	public function __construct(
		private readonly SevdeskContacts $contacts,
		private readonly SevdeskSearch $search,
		private readonly SevdeskDocuments $documents,
		private readonly SevdeskReference $reference,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * Send a refund.
	 *
	 * @param ApiClient     $api        Client.
	 * @param Connection    $connection Connection.
	 * @param Item          $item       Queue item (entity id is the WooCommerce refund id).
	 * @param RefundPayload $refund     Mapped refund.
	 * @param Options       $options    Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendRefund( ApiClient $api, Connection $connection, Item $item, RefundPayload $refund, Options $options ): SendResult {
		if ( '1.0' === $this->reference->version( $api, $connection ) ) {
			return SendResult::failure( Refusal::value( SevdeskConnector::LABEL, 'creditNote', __( 'Credit notes need sevDesk-Update 2.0.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'version 1.0' ) );
		}
		if ( '' !== $refund->currency && 'EUR' !== $refund->currency ) {
			return SendResult::failure( $this->documents->currencyRefusal( $refund->currency ) );
		}
		$contactId = $this->contactId( $api, $refund, $options );
		if ( $contactId instanceof ConnectorError ) {
			return SendResult::failure( $contactId );
		}
		$timezone = $options->text( 'timezone' );
		$invoice  = $this->search->find( $api, 'Invoice', $contactId, $refund->orderRef, $refund->orderDate, $timezone );
		if ( null === $invoice ) {
			return SendResult::failure( $this->noInvoice() );
		}
		$marker   = $item->clientReference();
		$existing = $this->search->find( $api, 'CreditNote', $contactId, $marker, $refund->date, $timezone );
		$warnings = array();
		if ( null !== $existing ) {
			$read    = $this->readCredit( $api, (string) $existing['id'] ) ?? $existing;
			$problem = $this->documents->check( $read, $refund->expectedTotal(), $options, $warnings, $refund->rows(), $this->creditDate( $refund ) );
			return null !== $problem ? SendResult::failure( $problem ) : SendResult::existing( $this->documents->ref( $read, $warnings ) );
		}
		$rate      = $this->invoiceRate( $api, (string) $invoice['id'] );
		$positions = $this->positions( $refund, $rate, $options );
		if ( $positions instanceof ConnectorError ) {
			return SendResult::failure( $positions );
		}
		$userId  = $this->reference->userId( $api );
		$ruleId  = PayloadReader::text( $invoice['taxRule']['id'] ?? '' );
		$ruleId  = '' === $ruleId ? '1' : $ruleId;
		$date    = LocalDate::dmy( $this->creditDate( $refund ), $timezone );
		$credit  = array(
			'objectName'           => 'CreditNote',
			'mapAll'               => true,
			'contact'              => SevdeskJson::ref( $contactId, 'Contact' ),
			'contactPerson'        => SevdeskJson::ref( $userId, 'SevUser' ),
			'creditNoteDate'       => $date,
			'status'               => SevdeskDocuments::DRAFT,
			'header'               => 'Gutschrift ' . $marker . ' zu Bestellung ' . $refund->orderRef,
			'headText'             => $refund->reason,
			'addressCountry'       => SevdeskJson::ref( PayloadReader::text( $invoice['addressCountry']['id'] ?? '' ), 'StaticCountry' ),
			'address'              => PayloadReader::text( $invoice['address'] ?? '' ),
			'taxRule'              => array(
				'id'         => $ruleId,
				'objectName' => 'TaxRule',
			),
			'taxText'              => PayloadReader::text( $invoice['taxText'] ?? '' ),
			'taxRate'              => 0,
			'bookingCategory'      => 'UNDERACHIEVEMENT',
			'currency'             => 'EUR',
			'customerInternalNote' => $marker,
		);
		$body    = array(
			'creditNote'          => $credit,
			'creditNotePosSave'   => $positions['positions'],
			'creditNotePosDelete' => null,
			'discountSave'        => null,
			'discountDelete'      => null,
			'takeDefaultAddress'  => false,
			'forCashRegister'     => false,
		);
		$created = (array) ( $api->post( 'CreditNote/Factory/saveCreditNote', array(), $body )->json()['objects'] ?? array() );
		$id      = (string) ( $created['creditNote']['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( SevdeskConnector::LABEL, 'creditNote', __( 'sevDesk did not return the new credit note.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no credit note id' ) );
		}
		$read    = $this->readCredit( $api, $id ) ?? array( 'id' => $id );
		$problem = $this->documents->check( $read, $refund->expectedTotal(), $options, $warnings, $refund->rows(), $this->creditDate( $refund ) );
		if ( null !== $problem ) {
			$api->delete( 'CreditNote/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
			return SendResult::failure( $problem );
		}
		$open = 'open' === $options->choice( 'refund_state' ) || ( 'follow' === $options->choice( 'refund_state' ) && $options->is( 'invoice_state', 'open' ) );
		if ( $open ) {
			$api->put(
				'CreditNote/' . rawurlencode( $id ) . '/sendBy',
				array(),
				array(
					'sendType'  => 'VPR',
					'sendDraft' => false,
				)
			);
			$read = $this->readCredit( $api, $id ) ?? $read;
		}
		return SendResult::created( $this->documents->ref( $read, array_merge( $warnings, $positions['warnings'] ) ) );
	}

	/**
	 * Read a credit note.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Credit note id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function readCredit( ApiClient $api, string $id ): ?array {
		$response = $api->get( 'CreditNote/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() ? SevdeskJson::first( $response->json() ) : null;
	}

	/**
	 * The customer's contact id: the collective contact, or the one with the deterministic number of the order's email.
	 *
	 * @param ApiClient     $api     Client.
	 * @param RefundPayload $refund  Refund.
	 * @param Options       $options Settings.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function contactId( ApiClient $api, RefundPayload $refund, Options $options ): string|ConnectorError {
		if ( $options->is( 'customer_policy', 'collective' ) ) {
			$id = $options->text( 'collective_contact_id' );
			return '' === $id ? $this->noInvoice() : $id;
		}
		if ( '' === $refund->email ) {
			return Refusal::email( SevdeskConnector::LABEL, $refund->orderRef );
		}
		$salt    = $options->is( 'customer_policy', 'always_create' ) ? $refund->orderRef : '';
		$contact = $this->contacts->find( $api, $this->contacts->numberFor( $refund->email, $options, $salt ) );
		return null === $contact ? $this->noInvoice() : (string) $contact['id'];
	}

	/**
	 * The tax rate of the invoice's first position in basis points (the rate shipping and other amounts are booked at).
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $invoiceId Invoice id.
	 *
	 * @throws ApiException On failure.
	 */
	private function invoiceRate( ApiClient $api, string $invoiceId ): int {
		$positions = SevdeskJson::rows( $api->get( 'Invoice/' . rawurlencode( $invoiceId ) . '/getPositions' )->json() );
		foreach ( $positions as $position ) {
			$bp = $this->amounts->basisPoints( $position['taxRate'] ?? null );
			if ( null !== $bp ) {
				return $bp;
			}
		}
		return 0;
	}

	/**
	 * The credit note positions.
	 *
	 * @param RefundPayload $refund  Refund.
	 * @param int           $fallback The invoice's rate in basis points.
	 * @param Options       $options Settings.
	 * @return array{positions:array<int,array<string,mixed>>,warnings:array<int,string>}|ConnectorError
	 */
	private function positions( RefundPayload $refund, int $fallback, Options $options ): array|ConnectorError {
		$rows = array();
		foreach ( $refund->lines as $line ) {
			$net = (int) $line['amount_minor'];
			if ( 0 === $net ) {
				continue;
			}
			$bp     = $this->snap( $net, (int) $line['tax_minor'], $fallback );
			$rows[] = array( '' !== $line['name'] ? (string) $line['name'] : 'Erstattung', (float) $line['quantity'], $net, $bp );
		}
		if ( $refund->shippingMinor > 0 ) {
			$rows[] = array( 'Versand', 1.0, $this->amounts->netFromGross( $refund->shippingMinor, $fallback ), $fallback );
		}
		if ( $refund->otherMinor > 0 ) {
			$rows[] = array( 'Erstattung', 1.0, $this->amounts->netFromGross( $refund->otherMinor, $fallback ), $fallback );
		}
		if ( array() === $rows ) {
			$rest = $refund->expectedTotal();
			if ( $rest <= 0 ) {
				return Refusal::value( SevdeskConnector::LABEL, 'amount', __( 'The refund has no amount.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'empty refund' );
			}
			$rows[] = array( 'Erstattung', 1.0, $this->amounts->netFromGross( $rest, $fallback ), $fallback );
		}
		$positions = array();
		$warnings  = array();
		foreach ( $rows as $index => $row ) {
			list( $name, $quantity, $net, $bp ) = $row;
			$quantity                           = $quantity > 0.0 ? $quantity : 1.0;
			$unit                               = $this->amounts->unitPrice( $net, 2, $quantity, 0 );
			if ( null === $unit ) {
				$name       = $this->amounts->quantityText( $quantity ) . ' × ' . $name;
				$quantity   = 1.0;
				$unit       = $this->amounts->decimal( $net, 2 );
				$warnings[] = 'line_split:' . ( $index + 1 );
			}
			$positions[] = array(
				'objectName' => 'CreditNotePos',
				'mapAll'     => true,
				'quantity'   => $this->amounts->number( $this->amounts->quantityText( $quantity ) ),
				'unity'      => SevdeskJson::ref( $options->int( 'unity_id' ), 'Unity' ),
				'taxRate'    => $this->amounts->number( $this->amounts->percent( $bp ) ),
				'price'      => $this->amounts->number( $unit ),
				'name'       => $name,
				'text'       => '',
			);
		}
		return array(
			'positions' => $positions,
			'warnings'  => $warnings,
		);
	}

	/**
	 * The rate of a refunded line: tax over net, snapped to the invoice's rate or a common rate within half a point.
	 *
	 * @param int $net      Net amount.
	 * @param int $tax      Tax amount.
	 * @param int $fallback The invoice's rate.
	 */
	private function snap( int $net, int $tax, int $fallback ): int {
		if ( $net <= 0 ) {
			return $fallback;
		}
		if ( 0 === $tax ) {
			return 0;
		}
		$bp = intdiv( $tax * 20000 + $net, 2 * $net );
		foreach ( array( $fallback, 0, 500, 700, 1600, 1900 ) as $candidate ) {
			if ( abs( $bp - $candidate ) <= 50 ) {
				return $candidate;
			}
		}
		return $bp;
	}

	/**
	 * The date (UTC text) a credit note is dated: the refund's, else the order's.
	 *
	 * @param RefundPayload $refund Refund.
	 */
	private function creditDate( RefundPayload $refund ): string {
		return '' !== $refund->date ? $refund->date : $refund->orderDate;
	}

	/**
	 * SYNC-VAL-002 for an order that has no invoice to credit.
	 */
	private function noInvoice(): ConnectorError {
		return Refusal::value( SevdeskConnector::LABEL, 'order_ref', __( 'sevDesk has no invoice for the refunded order.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no invoice for the refund' );
	}
}
