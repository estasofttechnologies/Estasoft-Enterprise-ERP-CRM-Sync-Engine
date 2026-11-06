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
 * The order flow: look for the client reference first, so a retry after a timeout never creates a second order; pin a price
 * list in the order's currency (Odoo takes the currency of an order from its price list, and by default from the customer's);
 * find or create the customer; match every line to an Odoo product by internal reference; map taxes by the codes in the tax
 * mapping and make each price agree with whether the tax is included in the price; create the sale order with all its lines in
 * one call; check the currency and the total Odoo worked out; then confirm, invoice and register payment as the payload asks.
 * A step after the creation that fails leaves a valid sale order in Odoo; the error is returned and a retry carries on from it.
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
	 * @param OdooTaxPricing   $pricing  Makes prices agree with the taxes.
	 * @param OdooTotalCheck   $totals   Compares Odoo's total with the shop's.
	 */
	public function __construct(
		private readonly OdooClient $client,
		private readonly OdooCatalog $catalog,
		private readonly OdooPartners $partners,
		private readonly OdooOrderFinder $finder,
		private readonly OdooDocumentFlow $flow,
		private readonly OdooOptions $options,
		private readonly OdooAmounts $amounts,
		private readonly OdooTaxPricing $pricing,
		private readonly OdooTotalCheck $totals,
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
		// A quotation left from an earlier pass is checked again before it is confirmed.
		$warnings = $payload->confirm && in_array( $state, array( 'draft', 'sent' ), true ) ? $this->check( $id, $payload, $this->currencyFor( $payload ) ) : array();
		if ( $this->flow->advance( $id, $payload ) || array() !== $warnings ) {
			return $this->reread( $id, $existing, $warnings );
		}
		return $this->finder->orderRef( $existing );
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
		$currency  = $this->currencyFor( $payload );
		$exponent  = $this->amounts->exponent( $currency );
		$pricelist = $this->pricelistFor( $currency );

		// Everything that can be wrong with the lines is found before the customer is created.
		$products = $this->resolveProducts( $payload, $exponent );
		$taxes    = $this->resolveTaxes( $payload );
		$commands = array();
		foreach ( $payload->lines as $index => $line ) {
			$price      = $this->pricing->price( (int) $line['price_minor'], $line['tax'] ?? array(), $taxes, $payload->pricesIncludeTax );
			$commands[] = array( 0, 0, $this->lineValues( $products['lines'][ $index ], (float) $line['quantity'], $price, $exponent, (string) $line['name'], (float) $line['discount'], $line['tax'], $taxes, $payload->taxesMapped ) );
		}
		foreach ( $payload->extras as $extra ) {
			$price      = $this->pricing->price( (int) $extra['price_minor'], $extra['tax'] ?? array(), $taxes, $payload->pricesIncludeTax );
			$commands[] = array( 0, 0, $this->lineValues( $products['extras'][ (string) $extra['kind'] ], 1.0, $price, $exponent, (string) $extra['name'], 0.0, $extra['tax'], $taxes, false ) );
		}
		$term      = $this->termFor( $payload );
		$warehouse = $this->warehouseFor( $payload );
		$people    = $this->partners->resolveForOrder( $payload->partner, $payload->delivery );

		$values = array(
			'partner_id'       => $people['partner'],
			'client_order_ref' => $payload->clientRef,
			'origin'           => 'WooCommerce #' . $item->entityId,
			'order_line'       => $commands,
			'pricelist_id'     => $pricelist,
			'company_id'       => $this->client->companyId(),
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
		if ( $term > 0 ) {
			$values['payment_term_id'] = $term;
		}
		if ( $warehouse > 0 ) {
			$values['warehouse_id'] = $warehouse;
		}

		$orderId  = $this->client->create( 'sale.order', $values );
		$warnings = $this->check( $orderId, $payload, $currency );
		$this->flow->advance( $orderId, $payload );

		$records      = $this->client->read( 'sale.order', array( $orderId ), OdooOrderFinder::ORDER_FIELDS );
		$record       = $records[0] ?? array(
			'id'           => $orderId,
			'name'         => '',
			'state'        => 'draft',
			'amount_total' => 0,
		);
		$record['id'] = $orderId;
		return $this->finder->orderRef( $record, $warnings );
	}

	/**
	 * Check a quotation before it is confirmed: Odoo must have used the order's currency, and its total must agree with the
	 * shop's. A quotation that fails is removed when Odoo lets the sync user do that (it was made a moment ago and nothing
	 * depends on it); the error says what happened to it.
	 *
	 * @param int              $orderId  Sale order id.
	 * @param OdooOrderPayload $payload  Payload.
	 * @param string           $currency ISO code the order must be in.
	 * @return array<int,array<string,mixed>> Differences accepted with a warning (option total_mismatch = warn).
	 *
	 * @throws OdooException When the currency is wrong, or the total differs and the option says to stop.
	 */
	private function check( int $orderId, OdooOrderPayload $payload, string $currency ): array {
		$records = $this->client->read( 'sale.order', array( $orderId ), array( 'name', 'currency_id', 'amount_total' ) );
		$record  = $records[0] ?? array();
		$number  = OdooRecord::text( $record['name'] ?? '' );
		$actual  = strtoupper( OdooRecord::name( $record['currency_id'] ?? false ) );
		if ( $actual !== $currency ) {
			$removed = $this->discard( $orderId );
			$reason  = sprintf(
				/* translators: 1: Odoo order number, 2: currency Odoo used, 3: currency of the shop order. */
				__( 'Odoo made %1$s in %2$s but the shop order is in %3$s. Check the price list and the currency settings in Odoo.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'' !== $number ? $number : (string) $orderId,
				'' !== $actual ? $actual : '?',
				$currency
			);
			$exception = $this->refused( 'currency', $reason . ' ' . $this->outcome( $removed ) );
			throw $exception;
		}
		$difference = $this->totals->difference( $payload->totalMinor, $record['amount_total'] ?? 0, $currency, '' !== $number ? $number : (string) $orderId );
		if ( null === $difference ) {
			return array();
		}
		if ( $this->totals->isStrict() ) {
			$exception = new OdooException( $this->totals->error( $difference, $this->outcome( $this->discard( $orderId ) ) ) );
			throw $exception;
		}
		return array( $difference );
	}

	/**
	 * Remove a quotation the connector made and does not want to keep.
	 *
	 * @param int $orderId Sale order id.
	 * @return bool True when it was removed.
	 */
	private function discard( int $orderId ): bool {
		try {
			$this->client->callRecords( 'sale.order', 'unlink', array( $orderId ) );
			return true;
		} catch ( OdooException $exception ) {
			return false;
		}
	}

	/**
	 * A sentence for the person about what happened to a rejected quotation.
	 *
	 * @param bool $removed Whether the quotation was removed.
	 */
	private function outcome( bool $removed ): string {
		return $removed
			? __( 'The quotation was removed from Odoo and nothing was confirmed.', 'estasoft-enterprise-erpcrm-sync-engine' )
			: __( 'The quotation stays in Odoo as a draft and was not confirmed: delete it in Odoo before the order is sent again.', 'estasoft-enterprise-erpcrm-sync-engine' );
	}

	/**
	 * Read the order again after it changed.
	 *
	 * @param int                            $id       Sale order id.
	 * @param array<string,mixed>            $fallback Record to use when the read returns nothing.
	 * @param array<int,array<string,mixed>> $warnings Differences accepted with a warning.
	 * @return \Estasoft\SyncEngine\Contract\ExternalRef
	 *
	 * @throws OdooException On failure.
	 */
	private function reread( int $id, array $fallback, array $warnings = array() ): \Estasoft\SyncEngine\Contract\ExternalRef {
		$records      = $this->client->read( 'sale.order', array( $id ), OdooOrderFinder::ORDER_FIELDS );
		$record       = $records[0] ?? $fallback;
		$record['id'] = $id;
		return $this->finder->orderRef( $record, $warnings );
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
	 * ISO code of the order's currency: the payload's, or Odoo's company currency when the payload has none.
	 *
	 * @param OdooOrderPayload $payload Payload.
	 *
	 * @throws OdooException On failure.
	 */
	private function currencyFor( OdooOrderPayload $payload ): string {
		return '' !== $payload->currency ? $payload->currency : $this->catalog->companyCurrency();
	}

	/**
	 * The price list every order is pinned to. Odoo takes the currency of an order from its price list, and takes the price
	 * list from the customer, so an order for a customer whose price list is in another currency would be made in that
	 * currency whatever the shop charged. The price list is therefore always named, and its currency is the order's.
	 *
	 * @param string $currency ISO code of the order's currency.
	 *
	 * @throws OdooException When Odoo has no price list in the currency and the options do not allow making one.
	 */
	private function pricelistFor( string $currency ): int {
		if ( '' === $currency ) {
			$exception = $this->refused( 'currency', __( 'The order has no currency, and Odoo\'s company has none either.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			throw $exception;
		}
		$id = $this->catalog->pricelistId( $currency );
		if ( $id < 1 && $this->options->flag( 'create_pricelist', false ) ) {
			$id = $this->catalog->createPricelist( $currency );
		}
		if ( $id < 1 ) {
			$wanted    = $this->options->text( 'pricelist' );
			$exception = $this->refused(
				'currency',
				'' !== $wanted
					? sprintf(
						/* translators: 1: price list named in the connection options, 2: order currency code. */
						__( 'The price list "%1$s" does not exist in Odoo, or is not in %2$s, the currency of the order.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$wanted,
						$currency
					)
					: sprintf(
						/* translators: %s: order currency code. */
						__( 'The order is in %1$s but Odoo has no active price list in %1$s. Add one in Odoo, or switch on making a price list in the connection options.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$currency
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
					__( 'Odoo has no payment term called "%s". Create it in Odoo or remove the payment term rule.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
					__( 'Odoo has no warehouse "%s".', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
				'reason' => __( 'This order is not in Odoo, so there is nothing to cancel.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
