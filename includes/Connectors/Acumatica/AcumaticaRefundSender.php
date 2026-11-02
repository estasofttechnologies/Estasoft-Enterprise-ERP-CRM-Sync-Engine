<?php
/**
 * Sends a WooCommerce refund to Acumatica as a credit memo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Text;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A refund is a Sales Invoice of type "Credit Memo" made on hold, checked against the refund amount, and released only when the option
 * asks for it. The description starts with the refund reference, which is what the search before every create looks for.
 */
final class AcumaticaRefundSender {

	/**
	 * The document type of a credit memo.
	 */
	public const TYPE = 'Credit Memo';

	/**
	 * Fields read when a credit memo is searched.
	 */
	private const SELECT = 'Type,ReferenceNbr,Status,Amount,Currency,Description,Date';

	/**
	 * Set up with the services it uses.
	 *
	 * @param AcumaticaClient      $client  Client.
	 * @param Options              $options Connection options.
	 * @param Amounts              $amounts Money helper.
	 * @param AcumaticaLookups     $lookups Setup lookups.
	 * @param AcumaticaOrderSender $orders  Order reads.
	 * @param string               $system  System name.
	 */
	public function __construct(
		private readonly AcumaticaClient $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly AcumaticaLookups $lookups,
		private readonly AcumaticaOrderSender $orders,
		private readonly string $system
	) {
	}

	/**
	 * Whether the credit memo is to be released.
	 */
	private function releasing(): bool {
		return 'release' === $this->options->choice( 'refund_state', array( 'hold', 'release' ), 'hold' );
	}

	/**
	 * The credit memo of a refund when it is finished the way the options ask; null otherwise.
	 *
	 * @param Item $item The queue item (a refund).
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$memo = $this->find( $item->clientReference() );
		if ( null === $memo || ( $this->releasing() && $this->unreleased( $memo['status'] ) ) ) {
			return null;
		}
		return $this->reference( $memo );
	}

	/**
	 * Send a refund.
	 *
	 * @param Item          $item    The queue item.
	 * @param RefundPayload $payload The refund.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, RefundPayload $payload ): SendResult {
		unset( $item );
		$memo     = $this->find( $payload->clientRef );
		$existing = null !== $memo;
		if ( null === $memo ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$memo = $created;
		}
		$warnings = array();
		if ( $this->releasing() && $this->unreleased( $memo['status'] ) ) {
			$memo = $this->release( $memo, $warnings );
		}
		$ref = $this->reference( $memo, $warnings );
		return $existing ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * The credit memo of a client reference.
	 *
	 * @param string $clientRef WC-{refund id}.
	 * @return array{nbr:string,status:string,total:float,currency:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		$prefix = $clientRef . ' refund of ';
		$rows   = $this->client->query(
			'SalesInvoice',
			array(
				'$filter' => 'Type eq ' . AcumaticaValue::literal( self::TYPE ) . ' and startswith(Description,' . AcumaticaValue::literal( $prefix ) . ')',
				'$select' => self::SELECT,
			),
			10
		);
		foreach ( $rows as $row ) {
			if ( str_starts_with( strtolower( AcumaticaValue::text( $row, 'Description' ) ), strtolower( $prefix ) ) && ! in_array( strtolower( AcumaticaValue::text( $row, 'Status' ) ), array( 'voided', 'rejected' ), true ) ) {
				return $this->flat( $row );
			}
		}
		return null;
	}

	/**
	 * The record for a credit memo.
	 *
	 * @param array{nbr:string,status:string,total:float,currency:string} $memo     Credit memo.
	 * @param array<int,string>                                           $warnings Warnings to carry.
	 */
	private function reference( array $memo, array $warnings = array() ): ExternalRef {
		$extra = array(
			'total_minor' => abs( $this->amounts->toMinor( $memo['total'], $this->amounts->exponent( $memo['currency'] ) ) ),
			'currency'    => $memo['currency'],
			'status'      => $memo['status'],
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( self::TYPE . '/' . $memo['nbr'], $memo['nbr'], $this->orders->link( 'SO303000', 'DocType=CRM&RefNbr=' . rawurlencode( $memo['nbr'] ) ), $extra );
	}

	/**
	 * A stored credit memo as plain values.
	 *
	 * @param array<string,mixed> $row Record from the API.
	 * @return array{nbr:string,status:string,total:float,currency:string}
	 */
	private function flat( array $row ): array {
		$total = AcumaticaValue::get( $row, 'Amount' );
		return array(
			'nbr'      => AcumaticaValue::text( $row, 'ReferenceNbr' ),
			'status'   => AcumaticaValue::text( $row, 'Status' ),
			'total'    => is_numeric( $total ) ? abs( (float) $total ) : 0.0,
			'currency' => strtoupper( AcumaticaValue::text( $row, 'Currency' ) ),
		);
	}

	/**
	 * Whether a status is one a credit memo has before it is released.
	 *
	 * @param string $status Status text.
	 */
	private function unreleased( string $status ): bool {
		return in_array( strtolower( $status ), array( 'hold', 'balanced', 'pending approval', 'pending print', 'pending email' ), true );
	}

	/**
	 * Take the hold off and release the credit memo.
	 *
	 * @param array{nbr:string,status:string,total:float,currency:string} $memo     Credit memo.
	 * @param array<int,string>                                           $warnings Receives warnings.
	 * @return array{nbr:string,status:string,total:float,currency:string}
	 *
	 * @throws ApiException On failure.
	 */
	private function release( array $memo, array &$warnings ): array {
		$key = array(
			'Type'         => self::TYPE,
			'ReferenceNbr' => $memo['nbr'],
		);
		if ( 'hold' === strtolower( $memo['status'] ) ) {
			$stored = $this->client->put( 'SalesInvoice', AcumaticaValue::fields( $key + array( 'Hold' => false ) ), array( '$select' => self::SELECT ) );
			$fresh  = $this->flat( $stored );
			if ( '' !== $fresh['status'] ) {
				$memo['status'] = $fresh['status'];
			}
		}
		$done = $this->client->action( 'SalesInvoice', 'ReleaseSalesInvoice', AcumaticaValue::fields( $key ) );
		if ( ! $done ) {
			$warnings[] = __( 'Acumatica is still releasing the credit memo. Its status is read on the next check.', 'estasoft-erp-crm-sync-engine' );
			return $memo;
		}
		$row = $this->client->get( 'SalesInvoice/' . rawurlencode( self::TYPE ) . '/' . rawurlencode( $memo['nbr'] ), array( '$select' => self::SELECT ) );
		if ( null !== $row ) {
			$fresh = $this->flat( $row );
			if ( '' !== $fresh['status'] ) {
				$memo['status'] = $fresh['status'];
			}
		}
		return $memo;
	}

	/**
	 * Create the credit memo on hold and check it.
	 *
	 * @param RefundPayload $payload The refund.
	 * @return array{nbr:string,status:string,total:float,currency:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( RefundPayload $payload ): array|ConnectorError {
		$order = $this->orders->find( $payload->orderRef );
		if ( null === $order ) {
			return Problems::refused(
				$this->system,
				'order_ref',
				sprintf(
					/* translators: %s: order reference. */
					__( 'The order %s is not in Acumatica, so it cannot be refunded there. Send the order first.', 'estasoft-erp-crm-sync-engine' ),
					$payload->orderRef
				),
				'order not found'
			);
		}
		$shippingItem = $this->options->text( 'shipping_item' );
		$refundItem   = $this->options->text( 'refund_item' );
		$skus         = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		if ( $payload->shippingMinor > 0 ) {
			if ( '' === $shippingItem ) {
				return Problems::refused( $this->system, 'shipping_item', __( 'The refund includes shipping, so the connection settings need the shipping inventory ID.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$skus[] = $shippingItem;
		}
		if ( $payload->isAmountOnly() ) {
			if ( '' === $refundItem || null === $payload->amountMinor || $payload->amountMinor <= 0 ) {
				return Problems::refused( $this->system, 'refund_line', __( 'This refund has no product lines. Enter an inventory ID for refunds without lines in the connection settings ("refund_item"), or refund by line.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$skus[] = $refundItem;
		}
		$items = $this->lookups->items( $skus );
		foreach ( $skus as $sku ) {
			if ( ! isset( $items[ strtolower( $sku ) ] ) ) {
				return Problems::missingProduct( $this->system, $sku );
			}
		}

		$detail   = $this->client->get(
			'SalesOrder/' . rawurlencode( $order['type'] ) . '/' . rawurlencode( $order['nbr'] ),
			array(
				'$expand' => 'Details,Shipments',
				'$select' => 'OrderType,OrderNbr,Details/InventoryID,Details/TaxCategory,Shipments/InvoiceNbr',
			)
		);
		$taxes    = $this->categoriesOf( $detail ?? array() );
		$invoice  = $this->invoiceOf( $detail ?? array() );
		$currency = $order['currency'];
		$exponent = $this->amounts->exponent( $currency );
		$rows     = array();
		foreach ( $payload->lines as $line ) {
			$id     = $items[ strtolower( (string) $line['sku'] ) ]['id'];
			$unit   = $this->amounts->perUnit( (int) $line['amount_minor'], (float) $line['quantity'] );
			$rows[] = $this->row( $id, (float) $line['quantity'], $this->amounts->toDecimal( $unit, $exponent ), $taxes[ strtolower( $id ) ] ?? '', $invoice );
		}
		if ( $payload->shippingMinor > 0 ) {
			$id     = $items[ strtolower( $shippingItem ) ]['id'];
			$rows[] = $this->row( $id, 1.0, $this->amounts->toDecimal( $payload->shippingMinor, $exponent ), $taxes[ strtolower( $id ) ] ?? '', '' );
		}
		if ( $payload->isAmountOnly() ) {
			$rows[] = $this->row( $items[ strtolower( $refundItem ) ]['id'], 1.0, $this->amounts->toDecimal( (int) $payload->amountMinor, $exponent ), '', '' );
		}

		$description     = $payload->clientRef . ' refund of ' . $payload->orderRef . ( '' !== $payload->reason ? ': ' . $payload->reason : '' );
		$body            = AcumaticaValue::fields(
			array(
				'Type'        => self::TYPE,
				'CustomerID'  => $order['customer'],
				'Date'        => ( '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ) ) . 'T00:00:00',
				'Currency'    => $currency,
				'Description' => Text::fit( $description, 255 ),
				'Hold'        => true,
			)
		);
		$body['Details'] = $rows;
		$stored          = $this->client->put( 'SalesInvoice', $body, array( '$expand' => 'Details' ) );
		$memo            = $this->flat( $stored );
		if ( '' === $memo['nbr'] ) {
			return Problems::refused( $this->system, 'sales_invoice', __( 'Acumatica did not return the new credit memo.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( '' === $memo['status'] ) {
			$memo['status'] = 'Hold';
		}
		if ( '' === $memo['currency'] ) {
			$memo['currency'] = $currency;
		}
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff  = $check->difference( $payload->amountMinor, $memo['total'], $currency, $memo['nbr'] );
		if ( null !== $diff && $check->isStrict() ) {
			return $check->error( $diff, __( 'The credit memo stays on hold in Acumatica. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $memo;
	}

	/**
	 * One credit memo line.
	 *
	 * @param string $inventory Inventory ID.
	 * @param float  $quantity  Quantity (positive).
	 * @param float  $price     Unit price.
	 * @param string $category  Tax category, or empty.
	 * @param string $invoice   Number of the original invoice, or empty.
	 * @return array<string,array{value:mixed}>
	 */
	private function row( string $inventory, float $quantity, float $price, string $category, string $invoice ): array {
		return AcumaticaValue::fields(
			array(
				'InventoryID' => $inventory,
				'Qty'         => $quantity,
				'UnitPrice'   => $price,
				'TaxCategory' => $category,
				'OrigInvNbr'  => $invoice,
				'OrigInvType' => '' !== $invoice ? 'Invoice' : '',
			)
		);
	}

	/**
	 * The tax category of each Inventory ID on the original order.
	 *
	 * @param array<string,mixed> $order Order with its details.
	 * @return array<string,string> Lower-case inventory ID to category.
	 */
	private function categoriesOf( array $order ): array {
		$out = array();
		foreach ( (array) ( $order['Details'] ?? array() ) as $row ) {
			if ( is_array( $row ) && '' !== AcumaticaValue::text( $row, 'TaxCategory' ) ) {
				$out[ strtolower( AcumaticaValue::text( $row, 'InventoryID' ) ) ] = AcumaticaValue::text( $row, 'TaxCategory' );
			}
		}
		return $out;
	}

	/**
	 * The last invoice number among the shipments of the original order.
	 *
	 * @param array<string,mixed> $order Order with its shipments.
	 */
	private function invoiceOf( array $order ): string {
		$invoice = '';
		foreach ( (array) ( $order['Shipments'] ?? array() ) as $row ) {
			if ( is_array( $row ) && '' !== AcumaticaValue::text( $row, 'InvoiceNbr' ) ) {
				$invoice = AcumaticaValue::text( $row, 'InvoiceNbr' );
			}
		}
		return $invoice;
	}
}
