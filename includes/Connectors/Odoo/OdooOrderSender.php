<?php
/**
 * Creates sale orders in Odoo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order flow: look for the client reference first, so a retry after a timeout never creates a second order; find or
 * create the customer; match every line to an Odoo product by internal reference; map taxes by the codes in the tax mapping;
 * create the sale order with all its lines in one call; then confirm, invoice and register payment as the payload asks.
 * A step after the creation that fails leaves a valid sale order in Odoo; the error is returned and a retry links to it.
 */
final class OdooOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient       $client   Client.
	 * @param OdooCatalog      $catalog  Lookups.
	 * @param OdooPartners     $partners Customers.
	 * @param OdooOrderFinder  $finder   Order lookup.
	 * @param OdooDocumentFlow $flow     Confirmation, invoice and payment steps.
	 * @param OdooOptions      $options  Connection options.
	 * @param OdooAmounts      $amounts  Conversion helper.
	 */
	public function __construct(
		private readonly OdooClient $client,
		private readonly OdooCatalog $catalog,
		private readonly OdooPartners $partners,
		private readonly OdooOrderFinder $finder,
		private readonly OdooDocumentFlow $flow,
		private readonly OdooOptions $options,
		private readonly OdooAmounts $amounts,
	) {
	}

	/**
	 * Send an order event.
	 *
	 * @param Item             $item   Queue item.
	 * @param OdooOrderPayload $parsed Checked payload.
	 */
	public function send( Item $item, OdooOrderPayload $parsed ): SendResult {
		try {
			$existing = $this->finder->order( $parsed->clientRef );
			if ( null !== $existing ) {
				return SendResult::existing( $this->afterExisting( $item, $existing, $parsed ) );
			}
			if ( 'cancelled' === $item->event ) {
				return SendResult::failure( $this->nothingToCancel() );
			}
			return SendResult::created( $this->create( $item, $parsed ) );
		} catch ( OdooException $exception ) {
			return SendResult::failure( $exception->error );
		}
	}

	/**
	 * What to do with an order Odoo already has: move it forward when the payload asks for a step it has not had yet, or cancel
	 * a quotation that was never confirmed. Confirmed orders are never cancelled from here.
	 *
	 * @param Item                $item     Queue item.
	 * @param array<string,mixed> $existing Sale order record.
	 * @param OdooOrderPayload    $payload  Payload.
	 * @return \Estasoft\SyncEngine\Contract\ExternalRef
	 *
	 * @throws OdooException On failure.
	 */
	private function afterExisting( Item $item, array $existing, OdooOrderPayload $payload ): \Estasoft\SyncEngine\Contract\ExternalRef {
		$id    = OdooRecord::id( $existing['id'] ?? 0 );
		$state = OdooRecord::text( $existing['state'] ?? '' );
		if ( 'cancelled' === $item->event ) {
			if ( in_array( $state, array( 'draft', 'sent' ), true ) ) {
				$this->client->callRecords( 'sale.order', 'action_cancel', array( $id ) );
				return $this->reread( $id, $existing );
			}
			return $this->finder->orderRef( $existing );
		}
		if ( 'cancel' === $state ) {
			return $this->finder->orderRef( $existing );
		}
		return $this->flow->advance( $id, $payload ) ? $this->reread( $id, $existing ) : $this->finder->orderRef( $existing );
	}

	/**
	 * Create the order and run the document steps.
	 *
	 * @param Item             $item    Queue item.
	 * @param OdooOrderPayload $payload Payload.
	 * @return \Estasoft\SyncEngine\Contract\ExternalRef
	 *
	 * @throws OdooException On failure.
	 */
	private function create( Item $item, OdooOrderPayload $payload ): \Estasoft\SyncEngine\Contract\ExternalRef {
		$exponent  = $this->exponentFor( $payload );
		$pricelist = $this->pricelistFor( $payload );

		// Everything that can be wrong with the lines is found before the customer is created.
		$products = $this->resolveProducts( $payload, $exponent );
		$taxes    = $this->resolveTaxes( $payload );
		$people   = $this->partners->resolveForOrder( $payload->partner, $payload->delivery );
		$commands = array();
		foreach ( $payload->lines as $index => $line ) {
			$commands[] = array( 0, 0, $this->lineValues( $products['lines'][ $index ], (float) $line['quantity'], (int) $line['price_minor'], $exponent, (string) $line['name'], (float) $line['discount'], $line['tax'], $taxes, $payload->taxesMapped ) );
		}
		foreach ( $payload->extras as $extra ) {
			$commands[] = array( 0, 0, $this->lineValues( $products['extras'][ (string) $extra['kind'] ], 1.0, (int) $extra['price_minor'], $exponent, (string) $extra['name'], 0.0, $extra['tax'], $taxes, false ) );
		}

		$values = array(
			'partner_id'       => $people['partner'],
			'client_order_ref' => $payload->clientRef,
			'origin'           => 'WooCommerce #' . $item->entityId,
			'order_line'       => $commands,
		);
		if ( $people['delivery'] > 0 ) {
			$values['partner_shipping_id'] = $people['delivery'];
		}
		if ( '' !== $payload->dateOrder ) {
			$values['date_order'] = $payload->dateOrder;
		}
		if ( '' !== $payload->note ) {
			$values['note'] = '<p>' . nl2br( esc_html( $payload->note ), false ) . '</p>';
		}
		if ( $pricelist > 0 ) {
			$values['pricelist_id'] = $pricelist;
		}
		$term = $this->termFor( $payload );
		if ( $term > 0 ) {
			$values['payment_term_id'] = $term;
		}
		$warehouse = $this->warehouseFor( $payload );
		if ( $warehouse > 0 ) {
			$values['warehouse_id'] = $warehouse;
		}
		$companyDomain = $this->client->companyDomain();
		if ( array() !== $companyDomain ) {
			$values['company_id'] = $this->client->companyId();
		}

		$orderId = $this->client->create( 'sale.order', $values );
		$this->flow->advance( $orderId, $payload );

		$records      = $this->client->read( 'sale.order', array( $orderId ), OdooOrderFinder::ORDER_FIELDS );
		$record       = $records[0] ?? array(
			'id'           => $orderId,
			'name'         => '',
			'state'        => 'draft',
			'amount_total' => 0,
		);
		$record['id'] = $orderId;
		return $this->finder->orderRef( $record );
	}

	/**
	 * Read the order again after it changed.
	 *
	 * @param int                 $id       Sale order id.
	 * @param array<string,mixed> $fallback Record to use when the read returns nothing.
	 * @return \Estasoft\SyncEngine\Contract\ExternalRef
	 *
	 * @throws OdooException On failure.
	 */
	private function reread( int $id, array $fallback ): \Estasoft\SyncEngine\Contract\ExternalRef {
		$records      = $this->client->read( 'sale.order', array( $id ), OdooOrderFinder::ORDER_FIELDS );
		$record       = $records[0] ?? $fallback;
		$record['id'] = $id;
		return $this->finder->orderRef( $record );
	}

	/**
	 * Values for one order line.
	 *
	 * @param int                    $productId   Odoo product id.
	 * @param float                  $quantity    Quantity.
	 * @param int                    $priceMinor  Unit price in minor units, without tax.
	 * @param int                    $exponent    Currency decimals.
	 * @param string                 $name        Line text, or empty for Odoo's own.
	 * @param float                  $discount    Discount percent.
	 * @param array<int,string>|null $taxCodes    Tax codes, an empty list for no tax, or null for Odoo's default.
	 * @param array<string,int>      $taxIds      Tax ids by code.
	 * @param bool                   $taxesMapped Whether the mapping supplies taxes for the order.
	 * @return array<string,mixed>
	 */
	private function lineValues( int $productId, float $quantity, int $priceMinor, int $exponent, string $name, float $discount, ?array $taxCodes, array $taxIds, bool $taxesMapped ): array {
		$values = array(
			'product_id'      => $productId,
			'product_uom_qty' => $quantity,
			'price_unit'      => $this->amounts->toDecimal( $priceMinor, $exponent ),
		);
		if ( '' !== $name ) {
			$values['name'] = $name;
		}
		if ( $discount > 0.0 ) {
			$values['discount'] = $discount;
		}
		if ( null !== $taxCodes || $taxesMapped ) {
			$ids = array();
			foreach ( $taxCodes ?? array() as $code ) {
				$ids[] = $taxIds[ $code ];
			}
			$values[ $this->client->version()->atLeast( 19 ) ? 'tax_ids' : 'tax_id' ] = array( array( 6, 0, $ids ) );
		}
		return $values;
	}

	/**
	 * Odoo product ids for every line and the service products for shipping, fees and discounts.
	 *
	 * @param OdooOrderPayload $payload  Payload.
	 * @param int              $exponent Currency decimals.
	 * @return array{lines:array<int,int>,extras:array<string,int>}
	 *
	 * @throws OdooException With SYNC-MAP-001 for the first product Odoo does not have.
	 */
	private function resolveProducts( OdooOrderPayload $payload, int $exponent ): array {
		$skus = array();
		$ids  = array();
		foreach ( $payload->lines as $line ) {
			if ( (int) $line['product_id'] > 0 ) {
				$ids[] = (int) $line['product_id'];
			} else {
				$skus[] = (string) $line['sku'];
			}
		}
		$bySku = $this->catalog->productIdsByReference( $skus );
		$byId  = $this->catalog->existingProductIds( $ids );

		$lines = array();
		foreach ( $payload->lines as $index => $line ) {
			$productId = (int) $line['product_id'];
			$sku       = (string) $line['sku'];
			if ( $productId > 0 && isset( $byId[ $productId ] ) ) {
				$lines[ $index ] = $productId;
			} elseif ( '' !== $sku && isset( $bySku[ $sku ] ) ) {
				$lines[ $index ] = $bySku[ $sku ];
			} elseif ( '' !== $sku && 'create' === $this->options->choice( 'missing_product', array( 'fail', 'create' ), 'fail' ) ) {
				$lines[ $index ] = $this->catalog->createProduct( $sku, (string) $line['name'], $this->amounts->toDecimal( (int) $line['price_minor'], $exponent ) );
			} else {
				$exception = new OdooException(
					new ConnectorError(
						ErrorCode::Map001,
						array(
							'sku'    => '' !== $sku ? $sku : (string) $productId,
							'system' => OdooErrorMapper::SYSTEM,
						),
						null,
						null,
						null,
						'product not found'
					),
					'',
					'product.product',
					'search_read'
				);
				throw $exception;
			}
		}
		$extras = array();
		foreach ( $payload->extras as $extra ) {
			$kind = (string) $extra['kind'];
			if ( ! isset( $extras[ $kind ] ) ) {
				$extras[ $kind ] = $this->catalog->serviceProductId( $kind );
			}
		}
		return array(
			'lines'  => $lines,
			'extras' => $extras,
		);
	}

	/**
	 * Tax ids for every code on the payload.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 * @return array<string,int>
	 *
	 * @throws OdooException With SYNC-MAP-002 for the first code Odoo does not have.
	 */
	private function resolveTaxes( OdooOrderPayload $payload ): array {
		$codes = array();
		foreach ( array_merge( $payload->lines, $payload->extras ) as $line ) {
			foreach ( $line['tax'] ?? array() as $code ) {
				$codes[] = (string) $code;
			}
		}
		$found = $this->catalog->taxIds( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $found[ $code ] ) ) {
				$exception = new OdooException(
					new ConnectorError(
						ErrorCode::Map002,
						array(
							'country' => $payload->partner['country_id'] ?? '',
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
		return $found;
	}

	/**
	 * Currency decimals of the order. The payload's currency decides; Odoo's company currency is the fallback.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 *
	 * @throws OdooException On failure.
	 */
	private function exponentFor( OdooOrderPayload $payload ): int {
		return $this->amounts->exponent( '' !== $payload->currency ? $payload->currency : $this->catalog->companyCurrency() );
	}

	/**
	 * The price list for an order in a currency other than the company's; 0 when the company currency is used.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 *
	 * @throws OdooException When Odoo has no price list in the order's currency.
	 */
	private function pricelistFor( OdooOrderPayload $payload ): int {
		if ( '' === $payload->currency ) {
			return 0;
		}
		$company = $this->catalog->companyCurrency();
		if ( '' === $company || $company === $payload->currency ) {
			return 0;
		}
		$id = $this->catalog->pricelistId( $payload->currency );
		if ( $id < 1 ) {
			$exception = $this->refused(
				'currency',
				sprintf(
					/* translators: 1: order currency code, 2: Odoo company currency code. */
					__( 'The order is in %1$s but Odoo has no price list in %1$s. The company currency is %2$s. Add a price list in Odoo.', 'estasoft-erp-crm-sync-engine' ),
					$payload->currency,
					$company
				)
			);
			throw $exception;
		}
		return $id;
	}

	/**
	 * The payment term named on the payload; 0 when none is named.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 *
	 * @throws OdooException When a named payment term does not exist.
	 */
	private function termFor( OdooOrderPayload $payload ): int {
		if ( '' === $payload->paymentTerm ) {
			return 0;
		}
		$id = $this->catalog->paymentTermId( $payload->paymentTerm );
		if ( $id < 1 ) {
			$exception = $this->refused(
				'payment_term',
				sprintf(
					/* translators: %s: payment term name from the mapping. */
					__( 'Odoo has no payment term called "%s". Create it in Odoo or remove the payment term rule.', 'estasoft-erp-crm-sync-engine' ),
					$payload->paymentTerm
				)
			);
			throw $exception;
		}
		return $id;
	}

	/**
	 * The warehouse named on the payload; 0 when none is named.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 *
	 * @throws OdooException When a named warehouse does not exist.
	 */
	private function warehouseFor( OdooOrderPayload $payload ): int {
		if ( '' === $payload->warehouse ) {
			return 0;
		}
		$id = $this->catalog->warehouseId( $payload->warehouse );
		if ( $id < 1 ) {
			$exception = $this->refused(
				'warehouse',
				sprintf(
					/* translators: %s: warehouse code or name from the mapping. */
					__( 'Odoo has no warehouse "%s".', 'estasoft-erp-crm-sync-engine' ),
					$payload->warehouse
				)
			);
			throw $exception;
		}
		return $id;
	}

	/**
	 * The error for a cancellation of an order Odoo does not have.
	 */
	private function nothingToCancel(): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => 'client_order_ref',
				'reason' => __( 'This order is not in Odoo, so there is nothing to cancel.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'cancel without order'
		);
	}

	/**
	 * A rejection as an exception.
	 *
	 * @param string $field  Field path.
	 * @param string $reason Why.
	 */
	private function refused( string $field, string $reason ): OdooException {
		return new OdooException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => OdooErrorMapper::SYSTEM,
					'field'  => $field,
					'reason' => $reason,
				),
				null,
				null,
				null,
				'order rejected'
			)
		);
	}
}
