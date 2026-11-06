<?php
/**
 * Refunds of a whole order as Moneybird credit invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Tolerance;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * `PATCH sales_invoices/{id}/duplicate_creditinvoice` copies the invoice into a draft credit invoice with `original_sales_invoice_id` set [documented];
 * an optional `detail_ids` limits it to some lines. WooCommerce refunds are often partial and Moneybird's lines carry no SKU, so only a refund of the
 * whole order is created here; any other refund stops with a clear message. The copy keeps the original's reference (so an invoice and its credit
 * invoice share it) and gets `WC-{refund id}` from a second call. If that call fails, the retry finds the draft copy through the list endpoint
 * (a draft credit invoice that points at the original) and adopts it instead of making a second one.
 */
final class MoneybirdRefunds {

	/**
	 * Set up the helper.
	 *
	 * @param MoneybirdDocuments $documents Shared document helpers.
	 * @param Amounts            $amounts   Conversion helper.
	 */
	public function __construct( private readonly MoneybirdDocuments $documents, private readonly Amounts $amounts ) {
	}

	/**
	 * Send a refund.
	 *
	 * @param ApiClient     $api     Client.
	 * @param Item          $item    Queue item (entity id is the WooCommerce refund id).
	 * @param RefundPayload $refund  Mapped refund.
	 * @param Options       $options Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendRefund( ApiClient $api, Item $item, RefundPayload $refund, Options $options ): SendResult {
		$marker   = $item->clientReference();
		$existing = $this->documents->findByReference( $api, $marker, true );
		if ( null !== $existing ) {
			return $this->resume( $api, $existing, $options );
		}
		$original = $this->documents->findByReference( $api, $refund->orderRef );
		if ( null === $original ) {
			return SendResult::failure( Refusal::value( MoneybirdConnector::LABEL, 'order_ref', __( 'Moneybird has no invoice for the refunded order.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no invoice for the refund' ) );
		}
		if ( 'draft' === (string) ( $original['state'] ?? '' ) ) {
			return SendResult::failure( Refusal::value( MoneybirdConnector::LABEL, 'order_ref', __( 'The invoice of the refunded order is still a draft. Send it in Moneybird first.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'invoice is a draft' ) );
		}
		$currency = strtoupper( PayloadReader::text( $original['currency'] ?? '' ) );
		$exponent = $this->amounts->exponent( $currency );
		$total    = $this->amounts->toMinor( $original['total_price_incl_tax'] ?? null, $exponent );
		$rows     = max( 1, count( (array) ( $original['details'] ?? array() ) ) );
		if ( null === $total || abs( $refund->expectedTotal() - $total ) > Tolerance::minor( $options, $rows ) ) {
			return SendResult::failure(
				Refusal::value(
					MoneybirdConnector::LABEL,
					'amount',
					__( 'Moneybird credit invoices copy whole lines, and this refund covers only part of the order. Create the credit invoice in Moneybird and skip this item.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'partial refund'
				)
			);
		}
		$credit = $this->orphan( $api, $refund->orderRef, (string) $original['id'] );
		if ( null === $credit ) {
			$credit = $api->patch( 'sales_invoices/' . rawurlencode( (string) $original['id'] ) . '/duplicate_creditinvoice.json', array(), new \stdClass() )->json();
		}
		$id = PayloadReader::text( $credit['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( MoneybirdConnector::LABEL, 'credit invoice', __( 'Moneybird did not return the new credit invoice.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no credit invoice id' ) );
		}
		$api->patch( 'sales_invoices/' . rawurlencode( $id ) . '.json', array(), array( 'sales_invoice' => array( 'reference' => $marker ) ) );
		$result = $this->resume( $api, $credit + array( 'id' => $id ), $options );
		return $result->isSuccess() ? SendResult::created( $result->ref ) : $result;
	}

	/**
	 * A draft credit invoice of this original that still has the original's reference: the copy an earlier pass made before its second call failed.
	 *
	 * @param ApiClient $api         Client.
	 * @param string    $orderRef    Reference of the refunded order, WC-{id}.
	 * @param string    $originalId  Id of the original invoice.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	private function orphan( ApiClient $api, string $orderRef, string $originalId ): ?array {
		foreach ( $this->documents->listByReference( $api, $orderRef ) as $candidate ) {
			if ( $this->documents->isCredit( $candidate ) && PayloadReader::text( $candidate['original_sales_invoice_id'] ?? '' ) === $originalId && 'draft' === (string) ( $candidate['state'] ?? '' ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Carry on with a credit invoice that exists: check its total is the whole invoice's, send it when asked.
	 *
	 * @param ApiClient           $api      Client.
	 * @param array<string,mixed> $existing The credit invoice as found.
	 * @param Options             $options  Settings.
	 *
	 * @throws ApiException On failure.
	 */
	private function resume( ApiClient $api, array $existing, Options $options ): SendResult {
		$id     = (string) $existing['id'];
		$credit = $this->documents->read( $api, $id ) ?? $existing;
		$wants  = 'sent' === $options->choice( 'refund_state' ) || ( 'follow' === $options->choice( 'refund_state' ) && $options->is( 'invoice_state', 'sent' ) );
		if ( $wants && 'draft' === (string) ( $credit['state'] ?? '' ) ) {
			$credit = $this->documents->send( $api, $id ) ?? $credit;
		}
		return SendResult::existing( $this->documents->ref( $credit, array() ) );
	}
}
