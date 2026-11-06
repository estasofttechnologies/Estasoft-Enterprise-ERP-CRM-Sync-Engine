<?php
/**
 * Refunds as Lexware Office credit notes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

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
use Estasoft\SyncEngine\Domain\Item;

/**
 * A credit note [documented]: created as a draft, or finalised with `finalize=true`; linked to the invoice with `precedingSalesVoucherId` only
 * when the invoice is not a draft and has no credit note yet (one credit note per invoice; a draft invoice answers 406). Credit notes have no line
 * discounts. Every line is sent with its gross amount (product lines: net plus tax, shipping and other amounts as the shop refunded them), so the
 * credit note total equals the refund exactly and only the split between net and tax depends on the rate.
 */
final class LexwareRefunds {

	/**
	 * Set up the object.
	 *
	 * @param LexwareContacts  $contacts  Contact lookups.
	 * @param LexwareSearch    $search    Duplicate search.
	 * @param LexwareDocuments $documents Shared document helpers.
	 * @param LexwareLines     $lines     Line helpers (special types).
	 * @param Amounts          $amounts   Conversion helper.
	 */
	public function __construct(
		private readonly LexwareContacts $contacts,
		private readonly LexwareSearch $search,
		private readonly LexwareDocuments $documents,
		private readonly LexwareLines $lines,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * Send a refund.
	 *
	 * @param ApiClient     $api     Client.
	 * @param Item          $item    Queue item (entity id is the WooCommerce refund id).
	 * @param RefundPayload $refund Mapped refund.
	 * @param Options       $options Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendRefund( ApiClient $api, Item $item, RefundPayload $refund, Options $options ): SendResult {
		if ( '' !== $refund->currency && 'EUR' !== $refund->currency ) {
			return SendResult::failure( $this->documents->currencyRefusal( $refund->currency ) );
		}
		if ( '' === $refund->email ) {
			return SendResult::failure( Refusal::email( LexwareOfficeConnector::LABEL, $refund->orderRef ) );
		}
		$matches = $this->contacts->findByEmail( $api, $refund->email );
		if ( array() === $matches ) {
			return SendResult::failure( $this->noInvoice() );
		}
		$contactId = (string) $matches[0]['id'];
		$timezone  = $options->text( 'timezone' );
		$invoice   = $this->search->find( $api, 'invoice', $contactId, $refund->orderRef, $refund->orderDate, $timezone );
		if ( null === $invoice ) {
			return SendResult::failure( $this->noInvoice() );
		}
		$marker   = $item->clientReference();
		$existing = $this->search->find( $api, 'creditnote', $contactId, $marker, $refund->date, $timezone );
		$warnings = array();
		if ( null !== $existing ) {
			$problem = $this->documents->checkTotal( $existing, $refund->expectedTotal(), $options, $warnings, $refund->rows(), $this->creditDate( $refund ) );
			return null !== $problem ? SendResult::failure( $problem ) : SendResult::existing( $this->documents->ref( $existing, 'credit-notes', $warnings ) );
		}
		$linked = $this->canLink( $invoice );
		$items  = $this->items( $refund, $invoice );
		if ( $items instanceof ConnectorError ) {
			return SendResult::failure( $items );
		}
		$date = LocalDate::midnightWithOffset( $this->creditDate( $refund ), $timezone );
		$text = 'Gutschrift ' . $marker . ' zu Bestellung ' . $refund->orderRef;
		if ( ! $linked && '' !== PayloadReader::text( $invoice['voucherNumber'] ?? '' ) ) {
			$text .= ' (Rechnung ' . PayloadReader::text( $invoice['voucherNumber'] ) . ')';
		}
		$body = array(
			'voucherDate'   => $date,
			'address'       => array( 'contactId' => $contactId ),
			'lineItems'     => $items['items'],
			'totalPrice'    => array( 'currency' => 'EUR' ),
			'taxConditions' => array( 'taxType' => $items['tax_type'] ),
			'title'         => 'Gutschrift',
			'introduction'  => $text,
		);
		if ( '' !== $refund->reason ) {
			$body['remark'] = $refund->reason;
		}
		$query = array();
		if ( $linked ) {
			$query['precedingSalesVoucherId'] = PayloadReader::text( $invoice['id'] ?? '' );
		}
		$refundOpen = 'open' === $options->choice( 'refund_state' ) || ( 'follow' === $options->choice( 'refund_state' ) && $options->is( 'invoice_state', 'open' ) );
		if ( $refundOpen ) {
			$query['finalize'] = true;
		}
		$created = $api->post( 'credit-notes', $query, $body )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( LexwareOfficeConnector::LABEL, 'credit note', __( 'Lexware Office did not return the new credit note.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no credit note id' ) );
		}
		$document = $api->get( 'credit-notes/' . rawurlencode( $id ) )->json();
		$document = array() === $document ? array( 'id' => $id ) : $document + array( 'id' => $id );
		if ( ! $linked ) {
			$warnings[] = 'not_linked';
		}
		$problem = $this->documents->checkTotal( $document, $refund->expectedTotal(), $options, $warnings, $refund->rows(), $this->creditDate( $refund ) );
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		return SendResult::created( $this->documents->ref( $document, 'credit-notes', $warnings ) );
	}

	/**
	 * Whether the credit note can be linked to the invoice: not a draft, and no credit note related to it yet.
	 *
	 * @param array<string,mixed> $invoice Invoice from the API.
	 */
	private function canLink( array $invoice ): bool {
		if ( 'draft' === PayloadReader::text( $invoice['voucherStatus'] ?? '' ) || '' === PayloadReader::text( $invoice['id'] ?? '' ) ) {
			return false;
		}
		foreach ( (array) ( $invoice['relatedVouchers'] ?? array() ) as $related ) {
			if ( is_array( $related ) && 'creditnote' === strtolower( PayloadReader::text( $related['voucherType'] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The credit note's tax type and line items, all with gross amounts.
	 *
	 * @param RefundPayload       $refund  Refund.
	 * @param array<string,mixed> $invoice The refunded invoice.
	 * @return array{tax_type:string,items:array<int,array<string,mixed>>}|ConnectorError
	 */
	private function items( RefundPayload $refund, array $invoice ): array|ConnectorError {
		$taxType = PayloadReader::text( $invoice['taxConditions']['taxType'] ?? '' );
		$special = null !== $this->lines->specialType( $taxType );
		$type    = $special ? (string) $this->lines->specialType( $taxType ) : 'gross';
		$default = $this->invoiceRate( $invoice );
		$items   = array();
		foreach ( $refund->lines as $line ) {
			$gross = (int) $line['amount_minor'] + (int) $line['tax_minor'];
			if ( 0 === $gross ) {
				continue;
			}
			$rate    = $special ? 0 : $this->rateOf( (int) $line['amount_minor'], (int) $line['tax_minor'], $default );
			$items[] = $this->item( '' !== $line['name'] ? (string) $line['name'] : 'Erstattung', (float) $line['quantity'], $gross, $rate, $special );
		}
		if ( $refund->shippingMinor > 0 ) {
			$items[] = $this->item( 'Versand', 1.0, $refund->shippingMinor, $special ? 0 : $default, $special );
		}
		if ( $refund->otherMinor > 0 ) {
			$items[] = $this->item( 'Erstattung', 1.0, $refund->otherMinor, $special ? 0 : $default, $special );
		}
		if ( array() === $items ) {
			$rest = $refund->expectedTotal();
			if ( $rest <= 0 ) {
				return Refusal::value( LexwareOfficeConnector::LABEL, 'amount', __( 'The refund has no amount.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'empty refund' );
			}
			$items[] = $this->item( 'Erstattung', 1.0, $rest, $special ? 0 : $default, $special );
		}
		return array(
			'tax_type' => $type,
			'items'    => $items,
		);
	}

	/**
	 * One credit note line with a gross unit price.
	 *
	 * @param string $name     Name.
	 * @param float  $quantity Quantity.
	 * @param int    $gross    Line amount with tax, minor units.
	 * @param int    $bp       Rate in basis points.
	 * @param bool   $special  True when the document uses a special tax type (net amounts).
	 * @return array<string,mixed>
	 */
	private function item( string $name, float $quantity, int $gross, int $bp, bool $special ): array {
		$quantity = $quantity > 0.0 ? $quantity : 1.0;
		$unit     = $this->amounts->unitPrice( $gross, 2, $quantity, 2 );
		if ( null === $unit ) {
			$name     = $this->amounts->quantityText( $quantity ) . ' × ' . $name;
			$quantity = 1.0;
			$unit     = $this->amounts->decimal( $gross, 2 );
		}
		return array(
			'type'      => 'custom',
			'name'      => $name,
			'quantity'  => $this->amounts->number( $this->amounts->quantityText( $quantity ) ),
			'unitName'  => 'Stück',
			'unitPrice' => array(
				'currency'                                 => 'EUR',
				( $special ? 'netAmount' : 'grossAmount' ) => $this->amounts->number( $unit ),
				'taxRatePercentage'                        => $this->amounts->number( $this->amounts->percent( $bp ) ),
			),
		);
	}

	/**
	 * The rate of the first line of the refunded invoice, in basis points (the rate shipping and other amounts are booked at).
	 *
	 * @param array<string,mixed> $invoice Invoice from the API.
	 */
	private function invoiceRate( array $invoice ): int {
		foreach ( (array) ( $invoice['lineItems'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['unitPrice']['taxRatePercentage'] ) ) {
				$bp = $this->amounts->basisPoints( $line['unitPrice']['taxRatePercentage'] );
				if ( null !== $bp ) {
					return $bp;
				}
			}
		}
		return 1900;
	}

	/**
	 * The rate a refunded line was taxed at: tax over net, snapped to the invoice's rate or a common rate when it is within half a point.
	 *
	 * @param int $net     Net amount.
	 * @param int $tax     Tax amount.
	 * @param int $fallback The invoice's first rate.
	 */
	private function rateOf( int $net, int $tax, int $fallback ): int {
		if ( $net <= 0 || 0 === $tax ) {
			return 0 === $tax && $net > 0 ? 0 : $fallback;
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
		return Refusal::value(
			LexwareOfficeConnector::LABEL,
			'order_ref',
			__( 'Lexware Office has no invoice for the refunded order.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'no invoice for the refund'
		);
	}
}
