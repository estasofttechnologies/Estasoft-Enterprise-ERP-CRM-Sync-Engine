<?php
/**
 * Sends a WooCommerce order to Sage 200 as a Sales Order Processing order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\TaxPricing;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * An order is found by `customer_document_no = WC-{id}` before it is created; an order that exists is read back and checked again, so a
 * wrong total keeps being reported until the person deletes the order in Sage 200 (there is no hold state to put it in).
 */
final class Sage200OrderSender {

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage200Client    $client    Client.
	 * @param Options          $options   Connection options.
	 * @param Amounts          $amounts   Money helper.
	 * @param Sage200Lookups   $lookups   Setup lookups.
	 * @param Sage200Customers $customers Customer handling.
	 * @param string           $system    System name.
	 */
	public function __construct(
		private readonly Sage200Client $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly Sage200Lookups $lookups,
		private readonly Sage200Customers $customers,
		private readonly string $system
	) {
	}

	/**
	 * The currency the connector sends: the company's base currency (option, default GBP).
	 */
	public function baseCurrency(): string {
		$code = strtoupper( $this->options->text( 'base_currency' ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'GBP';
	}

	/**
	 * The record of an order that exists; null otherwise.
	 *
	 * @param Item $item The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$order = $this->find( $item->clientReference() );
		return null === $order ? null : $this->reference( $order );
	}

	/**
	 * The order of a client reference (the oldest).
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		foreach ( $this->client->rows( 'sop_orders', 'customer_document_no eq ' . Sage200Client::literal( $clientRef ), 10 ) as $row ) {
			if ( strtolower( trim( (string) ( $row['customer_document_no'] ?? '' ) ) ) === strtolower( $clientRef ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Send an order.
	 *
	 * @param Item         $item    The queue item.
	 * @param OrderPayload $payload The order.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, OrderPayload $payload ): SendResult {
		unset( $item );
		$warnings = array();
		$existing = $this->find( $payload->clientRef );
		if ( null === $existing ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$order = $created;
		} else {
			$order = $this->withTotals( $existing );
		}
		$problem = $this->verify( $order, $payload, $warnings );
		if ( $problem instanceof ConnectorError ) {
			return SendResult::failure( $problem );
		}
		$ref = $this->reference( $order, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The record for an order.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param array<int,string>   $warnings Warnings to carry.
	 */
	public function reference( array $order, array $warnings = array() ): ExternalRef {
		$currency = $this->currencyOf( $order );
		$extra    = array(
			'total_minor' => $this->amounts->toMinor( $order['total_gross_value'] ?? 0, $this->amounts->exponent( $currency ) ),
			'currency'    => $currency,
			'status'      => isset( $order['document_status'] ) && is_scalar( $order['document_status'] ) ? (string) $order['document_status'] : '',
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$id = isset( $order['id'] ) && is_scalar( $order['id'] ) ? (string) $order['id'] : '';
		$no = isset( $order['document_no'] ) && is_scalar( $order['document_no'] ) ? (string) $order['document_no'] : $id;
		return new ExternalRef( $id, $no, null, $extra );
	}

	/**
	 * The currency code of an order record: the base currency when Sage says the order is in it.
	 *
	 * @param array<string,mixed> $order Order record.
	 */
	public function currencyOf( array $order ): string {
		$currency = isset( $order['currency'] ) && is_array( $order['currency'] ) ? $order['currency'] : array();
		if ( isset( $currency['is_base_currency'] ) && false === $currency['is_base_currency'] ) {
			return isset( $currency['currency'] ) && is_string( $currency['currency'] ) ? strtoupper( $currency['currency'] ) : '';
		}
		return $this->baseCurrency();
	}

	/**
	 * A line of a document body.
	 *
	 * @param string $product  Product id.
	 * @param float  $quantity Quantity.
	 * @param float  $price    Unit price without tax.
	 * @param string $tax      Tax code id, or empty.
	 * @param float  $discount Discount percent.
	 * @return array<string,mixed>
	 */
	public static function line( string $product, float $quantity, float $price, string $tax, float $discount = 0.0 ): array {
		$line                       = array(
			'line_type'     => 'EnumLineTypeStandard',
			'product_id'    => Sage200Client::id( $product ),
			'line_quantity' => $quantity,
		);
		$line['selling_unit_price'] = $price;
		if ( $discount > 0.0 ) {
			$line['unit_discount_percent'] = $discount;
		}
		if ( '' !== $tax ) {
			$line['tax_code_id'] = Sage200Client::id( $tax );
		}
		return $line;
	}

	/**
	 * The order record with its totals: the record itself when it has them, else a read by id.
	 *
	 * @param array<string,mixed> $order    Record from a create or a list.
	 * @param string              $endpoint sop_orders or sop_returns.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function withTotals( array $order, string $endpoint = 'sop_orders' ): array {
		if ( isset( $order['total_gross_value'] ) || ! isset( $order['id'] ) || ! is_scalar( $order['id'] ) ) {
			return $order;
		}
		$full = $this->client->find( $endpoint, (string) $order['id'] );
		return null === $full ? $order : array_merge( $order, $full );
	}

	/**
	 * Check the payload, resolve everything, and create the order.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( OrderPayload $payload ): array|ConnectorError {
		$problem = $this->precheck( $payload );
		if ( $problem instanceof ConnectorError ) {
			return $problem;
		}
		$codes = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		foreach ( $payload->extras as $row ) {
			$codes[] = $this->extraProduct( $row );
		}
		$products = $this->lookups->products( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $products[ strtolower( $code ) ] ) ) {
				return Problems::missingProduct( $this->system, $code );
			}
		}
		$taxes = $this->lookups->taxCodes();
		foreach ( $payload->taxCodes() as $code ) {
			if ( ! isset( $taxes[ strtolower( $code ) ] ) ) {
				return Problems::missingTax( $this->system, $code );
			}
		}
		$customer = $this->customers->resolve( $payload->partner, $payload->delivery, $payload->clientRef );
		if ( $customer instanceof ConnectorError ) {
			return $customer;
		}

		$exponent = $this->amounts->exponent( $payload->currency );
		$lines    = array();
		foreach ( $payload->lines as $line ) {
			$tax = $this->taxOf( $line['tax'] ?? null, $taxes );
			$net = $this->net( (int) $line['price_minor'], $tax, $payload->pricesIncludeTax );
			if ( $net instanceof ConnectorError ) {
				return $net;
			}
			$lines[] = self::line( $products[ strtolower( (string) $line['sku'] ) ]['id'], (float) $line['quantity'], $this->amounts->toDecimal( $net, $exponent ), (string) ( $tax['id'] ?? '' ), (float) $line['discount'] );
		}
		foreach ( $payload->extras as $row ) {
			$tax = $this->taxOf( $row['tax'] ?? null, $taxes );
			$net = $this->net( (int) $row['amount_minor'], $tax, $payload->pricesIncludeTax );
			if ( $net instanceof ConnectorError ) {
				return $net;
			}
			$sign    = 'discount' === $row['kind'] ? -1 : 1;
			$lines[] = self::line( $products[ strtolower( $this->extraProduct( $row ) ) ]['id'], 1.0, $sign * $this->amounts->toDecimal( $net, $exponent ), (string) ( $tax['id'] ?? '' ) );
		}

		$body = array(
			'customer_id'          => Sage200Client::id( $customer['id'] ),
			'document_date'        => ( '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ) ) . 'T00:00:00Z',
			'customer_document_no' => $payload->clientRef,
			'lines'                => $lines,
		);
		if ( '' !== $customer['delivery'] ) {
			$body['customer_delivery_address_id'] = Sage200Client::id( $customer['delivery'] );
		}
		$stored = $this->client->create( 'sop_orders', $body );
		if ( ! isset( $stored['id'] ) || ! is_scalar( $stored['id'] ) ) {
			$found = $this->find( $payload->clientRef );
			if ( null === $found ) {
				return Problems::refused( $this->system, 'sop_orders', __( 'Sage 200 did not return the new order.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$stored = $found;
		}
		return $this->withTotals( $stored );
	}

	/**
	 * Everything that can be refused without a request.
	 *
	 * @param OrderPayload $payload The order.
	 */
	private function precheck( OrderPayload $payload ): ?ConnectorError {
		if ( '' === $payload->currency ) {
			return Problems::refused( $this->system, 'currency', __( 'The order has no currency.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( $payload->currency !== $this->baseCurrency() ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: currency of the shop order, 2: base currency of the Sage 200 company. */
					__( 'The order is in %1$s but this connector sends orders only in the base currency of the Sage 200 company (%2$s). Change the "base_currency" setting if the company uses another one.', 'estasoft-erp-crm-sync-engine' ),
					$payload->currency,
					$this->baseCurrency()
				)
			);
		}
		foreach ( $payload->lines as $line ) {
			if ( is_array( $line['tax'] ?? null ) && count( $line['tax'] ) > 1 ) {
				return Problems::refused( $this->system, 'order_line[].tax', __( 'A line in Sage 200 has one tax code. This order has a line with several tax codes.', 'estasoft-erp-crm-sync-engine' ) );
			}
		}
		foreach ( $payload->extras as $row ) {
			if ( '' === $this->extraProduct( $row ) ) {
				$option = $this->extraOption( $row );
				return Problems::refused(
					$this->system,
					$option,
					sprintf(
						/* translators: %s: option name. */
						__( 'The order has a shipping, fee or discount amount, so the connection settings need the product code in "%s".', 'estasoft-erp-crm-sync-engine' ),
						$option
					)
				);
			}
		}
		return null;
	}

	/**
	 * The name of the option that holds the product of an extra row.
	 *
	 * @param array<string,mixed> $row Extra row.
	 */
	private function extraOption( array $row ): string {
		return match ( $row['kind'] ) {
			'shipping' => 'shipping_product',
			'discount' => 'discount_product',
			default    => 'fee_product',
		};
	}

	/**
	 * The product code configured for an extra row.
	 *
	 * @param array<string,mixed> $row Extra row.
	 */
	private function extraProduct( array $row ): string {
		return $this->options->text( $this->extraOption( $row ) );
	}

	/**
	 * The tax code record of a line's tax list.
	 *
	 * @param mixed                                                                               $codes Tax codes of the line, or null.
	 * @param array<string,array{id:string,code:string,name:string,percent:string,rate:int|null}> $taxes Tax codes of the company.
	 * @return array{id:string,code:string,name:string,percent:string,rate:int|null}|null
	 */
	private function taxOf( mixed $codes, array $taxes ): ?array {
		if ( ! is_array( $codes ) || array() === $codes ) {
			return null;
		}
		return $taxes[ strtolower( (string) $codes[0] ) ] ?? null;
	}

	/**
	 * A price without tax, converting a price that includes it.
	 *
	 * @param int                                                                        $minor   Price as the payload gives it.
	 * @param array{id:string,code:string,name:string,percent:string,rate:int|null}|null $tax     Tax code of the line.
	 * @param bool                                                                       $holds   Whether the payload price includes tax.
	 * @return int|ConnectorError
	 */
	private function net( int $minor, ?array $tax, bool $holds ): int|ConnectorError {
		if ( ! $holds || null === $tax ) {
			return $minor;
		}
		if ( null === $tax['rate'] ) {
			return Problems::refused( $this->system, 'tax_rate', __( 'The rate of a tax code could not be read as a plain percentage, so a price with tax cannot be converted exactly.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return TaxPricing::convert( $minor, $tax['rate'], true, false );
	}

	/**
	 * Check currency and total of a stored order.
	 *
	 * @param array<string,mixed> $order    Order record with totals.
	 * @param OrderPayload        $payload  The order.
	 * @param array<int,string>   $warnings Receives warnings.
	 */
	private function verify( array $order, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		$number = isset( $order['document_no'] ) && is_scalar( $order['document_no'] ) ? (string) $order['document_no'] : (string) ( $order['id'] ?? '' );
		$seen   = $this->currencyOf( $order );
		if ( $seen !== $payload->currency ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: order number in Sage 200, 2: currency the order has there, 3: currency of the shop. */
					__( 'Order %1$s is in %2$s in Sage 200 but the shop order is in %3$s. It stays in Sage 200; delete it there and check the currency of the customer.', 'estasoft-erp-crm-sync-engine' ),
					$number,
					'' !== $seen ? $seen : '?',
					$payload->currency
				),
				'currency mismatch'
			);
		}
		if ( ! isset( $order['total_gross_value'] ) ) {
			$warnings[] = __( 'Sage 200 did not return the order total, so it was not compared with the shop.', 'estasoft-erp-crm-sync-engine' );
			return null;
		}
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff  = $check->difference( $payload->totalMinor, $order['total_gross_value'], $payload->currency, $number );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The order stays in Sage 200. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}
}
