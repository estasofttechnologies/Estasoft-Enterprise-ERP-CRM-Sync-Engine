<?php
/**
 * Creates credit notes in Odoo for WooCommerce refunds.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A refund becomes a customer credit note (account.move of type out_refund) linked to the order's posted invoice when
 * there is one. The credit note is created as a draft unless the payload or the option refund_state says post. Odoo, or
 * its tax localisation, issues and reports the credit note; this plugin never signs it. The reference WC-{refund id}
 * goes at the start of the credit note's reference, followed by the reason, so a retry finds the credit note again.
 */
final class OdooRefundSender {

	/**
	 * ZATCA reason used when a Saudi credit note needs one and the mapping gives none: refund of goods or services.
	 */
	private const ZATCA_DEFAULT_REASON = 'BR-KSA-17-reason-4';

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient       $client  Client.
	 * @param OdooCatalog      $catalog Lookups.
	 * @param OdooOrderFinder  $finder  Order and credit note lookup.
	 * @param OdooDocumentFlow $flow    Invoice lookup.
	 * @param OdooOptions      $options Connection options.
	 * @param OdooAmounts      $amounts Conversion helper.
	 */
	public function __construct(
		private readonly OdooClient $client,
		private readonly OdooCatalog $catalog,
		private readonly OdooOrderFinder $finder,
		private readonly OdooDocumentFlow $flow,
		private readonly OdooOptions $options,
		private readonly OdooAmounts $amounts,
	) {
	}

	/**
	 * What can be checked without asking Odoo: the refund must say which order it belongs to.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @return ConnectorError|null VAL-002, or null when the payload can be sent.
	 */
	public static function precheck( array $payload ): ?ConnectorError {
		if ( '' !== OdooPayloadReader::text( $payload['order_ref'] ?? '' ) ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => 'order_ref',
				'reason' => __( 'The refund does not say which order it belongs to.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'refund rejected'
		);
	}

	/**
	 * Send a refund.
	 *
	 * @param Item                $item    Queue item (entity id is the WooCommerce refund id).
	 * @param array<string,mixed> $payload Mapped payload.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$clientRef = $item->clientReference();
		$orderRef  = OdooPayloadReader::text( $payload['order_ref'] ?? '' );
		try {
			$existing = $this->finder->creditNote( $clientRef );
			if ( null !== $existing ) {
				return SendResult::existing( $this->finder->creditNoteRef( $existing ) );
			}
			$order = $this->finder->order( $orderRef );
			if ( null === $order ) {
				return SendResult::failure(
					$this->refused(
						'order_ref',
						sprintf(
							/* translators: %s: order reference such as WC-10490. */
							__( 'Odoo does not have the order %s yet. Send the order first.', 'estasoft-erp-crm-sync-engine' ),
							$orderRef
						)
					)
				);
			}
			return SendResult::created( $this->create( $clientRef, $order, $payload ) );
		} catch ( OdooException $exception ) {
			return SendResult::failure( $exception->error );
		}
	}

	/**
	 * Create the credit note.
	 *
	 * @param string              $clientRef WC-{refund id}.
	 * @param array<string,mixed> $order     Sale order record.
	 * @param array<string,mixed> $payload   Mapped payload.
	 *
	 * @throws OdooException On failure.
	 */
	private function create( string $clientRef, array $order, array $payload ): ExternalRef {
		$currency = strtoupper( OdooRecord::name( $order['currency_id'] ?? false ) );
		$exponent = $this->amounts->exponent( $currency );
		$lines    = OdooPayloadReader::rows( $payload['refund_line'] ?? null );
		$extras   = $this->extraLines( $payload );

		$codes = array();
		foreach ( array_merge( $lines, $extras ) as $line ) {
			if ( array_key_exists( 'tax', $line ) ) {
				$codes = array_merge( $codes, OdooPayloadReader::taxCodes( $line['tax'] ) );
			}
		}
		$taxIds = $this->catalog->taxIds( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $taxIds[ $code ] ) ) {
				$exception = new OdooException(
					new ConnectorError(
						ErrorCode::Map002,
						array(
							'country' => '',
							'rate'    => $code,
							'system'  => OdooErrorMapper::SYSTEM,
						),
						null,
						null,
						null,
						'tax not found'
					),
					'',
					'account.tax',
					'search_read'
				);
				throw $exception;
			}
		}

		$commands = array();
		foreach ( $lines as $line ) {
			$commands[] = array( 0, 0, $this->refundLine( $line, $exponent, $taxIds ) );
		}
		foreach ( $extras as $extra ) {
			$commands[] = array( 0, 0, $this->extraValues( $extra, $exponent, $taxIds ) );
		}
		if ( array() === $commands ) {
			$commands[] = array( 0, 0, $this->amountOnlyLine( $payload, $clientRef, $exponent ) );
		}

		$reason     = OdooPayloadReader::text( $payload['reason'] ?? '' );
		$invoice    = $this->latestPostedInvoice( OdooRecord::id( $order['id'] ?? 0 ) );
		$values     = array(
			'move_type'        => 'out_refund',
			'partner_id'       => OdooRecord::id( $order['partner_id'] ?? 0 ),
			'ref'              => '' !== $reason ? $clientRef . ' ' . $reason : $clientRef,
			'invoice_origin'   => OdooRecord::text( $order['name'] ?? '' ),
			'invoice_line_ids' => $commands,
		);
		$currencyId = OdooRecord::id( $order['currency_id'] ?? 0 );
		if ( $currencyId > 0 ) {
			$values['currency_id'] = $currencyId;
		}
		$date = $this->amounts->toOdooDatetime( OdooPayloadReader::text( $payload['date'] ?? '' ) );
		if ( '' !== $date ) {
			$values['invoice_date'] = substr( $date, 0, 10 );
		}
		if ( $invoice > 0 ) {
			$values['reversed_entry_id'] = $invoice;
		}
		if ( $this->client->hasField( 'account.move', 'l10n_sa_reason' ) ) {
			$zatca                    = OdooPayloadReader::text( $payload['zatca_reason'] ?? '' );
			$values['l10n_sa_reason'] = '' !== $zatca ? $zatca : self::ZATCA_DEFAULT_REASON;
		}

		$moveId = $this->client->create( 'account.move', $values );
		$flow   = isset( $payload['flow'] ) && is_array( $payload['flow'] ) ? $payload['flow'] : array();
		$post   = OdooPayloadReader::flag( $flow['post'] ?? null, 'post' === $this->options->choice( 'refund_state', array( 'draft', 'post' ), 'draft' ) );
		if ( $post ) {
			$this->client->callRecords( 'account.move', 'action_post', array( $moveId ) );
			if ( $this->client->hasField( 'account.move', 'l10n_sa_uuid' ) && $this->client->hasField( 'account.move', 'edi_state' ) ) {
				$this->client->callRecords( 'account.move', 'action_process_edi_web_services', array( $moveId ) );
			}
		}

		$records      = $this->client->read( 'account.move', array( $moveId ), OdooOrderFinder::MOVE_FIELDS );
		$record       = $records[0] ?? array(
			'name' => '',
			'ref'  => $clientRef,
		);
		$record['id'] = $moveId;
		return $this->finder->creditNoteRef( $record );
	}

	/**
	 * The latest posted customer invoice of an order, or 0.
	 *
	 * @param int $orderId Sale order id.
	 *
	 * @throws OdooException On failure.
	 */
	private function latestPostedInvoice( int $orderId ): int {
		$posted = 0;
		foreach ( $this->flow->customerInvoices( $orderId ) as $invoice ) {
			if ( 'posted' === OdooRecord::text( $invoice['state'] ?? '' ) ) {
				$posted = max( $posted, OdooRecord::id( $invoice['id'] ?? 0 ) );
			}
		}
		return $posted;
	}

	/**
	 * Values for a refunded product line. The quantity and unit price are kept when the amount divides evenly; otherwise
	 * the line is one unit at the full amount, so the total is exact.
	 *
	 * @param array<string,mixed> $line     Line from the payload.
	 * @param int                 $exponent Currency decimals.
	 * @param array<string,int>   $taxIds   Tax ids by code.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException On failure.
	 */
	private function refundLine( array $line, int $exponent, array $taxIds ): array {
		$sku      = OdooPayloadReader::text( $line['product'] ?? ( $line['sku'] ?? '' ) );
		$quantity = isset( $line['quantity'] ) && is_numeric( $line['quantity'] ) ? max( 1.0, (float) $line['quantity'] ) : 1.0;
		$amount   = abs( (int) OdooPayloadReader::minor( $line['amount'] ?? null ) );
		$name     = OdooPayloadReader::text( $line['name'] ?? '' );
		$name     = '' !== $name ? $name : ( '' !== $sku ? $sku : __( 'Refund', 'estasoft-erp-crm-sync-engine' ) );

		$whole  = floor( $quantity ) === $quantity && 0 === $amount % (int) $quantity;
		$values = array(
			'name'       => $name,
			'quantity'   => $whole ? $quantity : 1.0,
			'price_unit' => $this->amounts->toDecimal( $whole ? intdiv( $amount, (int) $quantity ) : $amount, $exponent ),
		);
		if ( ! $whole ) {
			$values['name'] = $name . ' x ' . rtrim( rtrim( number_format( $quantity, 3, '.', '' ), '0' ), '.' );
		}
		$productId = isset( $line['product_id'] ) && is_numeric( $line['product_id'] ) ? (int) $line['product_id'] : 0;
		if ( $productId < 1 && '' !== $sku ) {
			$found     = $this->catalog->productIdsByReference( array( $sku ) );
			$productId = $found[ $sku ] ?? 0;
		}
		if ( $productId > 0 ) {
			$values['product_id'] = $productId;
		}
		if ( array_key_exists( 'tax', $line ) ) {
			$values['tax_ids'] = array( array( 6, 0, $this->taxIdList( OdooPayloadReader::taxCodes( $line['tax'] ), $taxIds ) ) );
		}
		return $values;
	}

	/**
	 * Shipping lines refunded with the order (positive amounts).
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private function extraLines( array $payload ): array {
		$shipping = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$amount   = OdooPayloadReader::minor( $shipping['total'] ?? null );
		if ( null === $amount || 0 === $amount ) {
			return array();
		}
		$name  = OdooPayloadReader::text( $shipping['name'] ?? '' );
		$extra = array(
			'name'   => '' !== $name ? $name : __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
			'amount' => abs( $amount ),
		);
		if ( array_key_exists( 'tax', $shipping ) ) {
			$extra['tax'] = $shipping['tax'];
		}
		return array( $extra );
	}

	/**
	 * Values for the shipping line of a credit note.
	 *
	 * @param array<string,mixed> $extra    Shipping line.
	 * @param int                 $exponent Currency decimals.
	 * @param array<string,int>   $taxIds   Tax ids by code.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException On failure.
	 */
	private function extraValues( array $extra, int $exponent, array $taxIds ): array {
		$values = array(
			'product_id' => $this->catalog->serviceProductId( 'shipping' ),
			'name'       => (string) $extra['name'],
			'quantity'   => 1.0,
			'price_unit' => $this->amounts->toDecimal( (int) $extra['amount'], $exponent ),
		);
		if ( array_key_exists( 'tax', $extra ) ) {
			$values['tax_ids'] = array( array( 6, 0, $this->taxIdList( OdooPayloadReader::taxCodes( $extra['tax'] ), $taxIds ) ) );
		}
		return $values;
	}

	/**
	 * The line for a refund that has an amount but no lines: one line for the whole amount, without tax, so the credit
	 * note total equals the refund exactly.
	 *
	 * @param array<string,mixed> $payload   Payload.
	 * @param string              $clientRef WC-{refund id}.
	 * @param int                 $exponent  Currency decimals.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException When the refund has no amount.
	 */
	private function amountOnlyLine( array $payload, string $clientRef, int $exponent ): array {
		$amount = OdooPayloadReader::minor( $payload['amount'] ?? null );
		if ( null === $amount || 0 === $amount ) {
			$exception = new OdooException( $this->refused( 'amount', __( 'The refund has no amount and no lines.', 'estasoft-erp-crm-sync-engine' ) ) );
			throw $exception;
		}
		return array(
			'product_id' => $this->catalog->serviceProductId( 'refund' ),
			'name'       => sprintf(
				/* translators: %s: refund reference such as WC-5521. */
				__( 'Refund %s', 'estasoft-erp-crm-sync-engine' ),
				$clientRef
			),
			'quantity'   => 1.0,
			'price_unit' => $this->amounts->toDecimal( abs( $amount ), $exponent ),
			'tax_ids'    => array( array( 6, 0, array() ) ),
		);
	}

	/**
	 * Tax ids for a list of codes.
	 *
	 * @param array<int,string> $codes  Codes.
	 * @param array<string,int> $taxIds Tax ids by code.
	 * @return array<int,int>
	 */
	private function taxIdList( array $codes, array $taxIds ): array {
		$ids = array();
		foreach ( $codes as $code ) {
			if ( isset( $taxIds[ $code ] ) ) {
				$ids[] = $taxIds[ $code ];
			}
		}
		return $ids;
	}

	/**
	 * A rejection as SYNC-VAL-002.
	 *
	 * @param string $field  Field path.
	 * @param string $reason Why.
	 */
	private function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'refund rejected'
		);
	}
}
