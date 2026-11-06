<?php
/**
 * Sends a WooCommerce order to ERPNext: Sales Order, optional Sales Invoice, optional Payment Entry.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Every pass starts from a read: the Sales Order (found by `po_no = WC-{id}`), its Sales Invoice (found through the order link on its
 * items) and its Payment Entry (found through its reference rows) are looked up first, and only the steps that are missing are done.
 * Inserting and submitting are separate calls, so a pass that stopped after the insert simply submits the draft the next time.
 */
final class ErpnextOrderSender {

	/**
	 * Fields read for a Sales Order row.
	 *
	 * @var array<int,string>
	 */
	private const ORDER_FIELDS = array( 'name', 'docstatus', 'status', 'grand_total', 'currency', 'customer', 'transaction_date', 'po_no', 'creation' );

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient    $client    Client.
	 * @param Options          $options   Connection options.
	 * @param Amounts          $amounts   Money helper.
	 * @param ErpnextLookups   $lookups   Setup lookups.
	 * @param ErpnextCustomers $customers Customer handling.
	 * @param ErpnextTaxes     $taxes     Tax templates.
	 * @param string           $system    System name.
	 * @param string           $address   Site address, for record links.
	 */
	public function __construct(
		private readonly ErpnextClient $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly ErpnextLookups $lookups,
		private readonly ErpnextCustomers $customers,
		private readonly ErpnextTaxes $taxes,
		private readonly string $system,
		private readonly string $address
	) {
	}

	/**
	 * The documents the options ask for: order, order_invoice or order_invoice_payment.
	 */
	public function documents(): string {
		return $this->options->choice( 'documents', array( 'order', 'order_invoice', 'order_invoice_payment' ), 'order' );
	}

	/**
	 * The record of an order when every step the options ask for is finished; null otherwise.
	 *
	 * @param Item $item The queue item.
	 * @param bool $paid Whether the order is paid (payment steps are skipped for unpaid orders).
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item, bool $paid ): ?ExternalRef {
		$order = $this->findOrder( $item->clientReference() );
		if ( null === $order || 1 !== (int) $order['docstatus'] ) {
			return null;
		}
		$invoice = null;
		if ( 'order' !== $this->documents() ) {
			$invoice = $this->findInvoice( (string) $order['name'] );
			if ( null === $invoice || 1 !== (int) $invoice['docstatus'] ) {
				return null;
			}
		}
		$payment = null;
		if ( 'order_invoice_payment' === $this->documents() && $paid && null !== $invoice ) {
			$payment = $this->findPayment( (string) $invoice['name'] );
			if ( null === $payment && (float) ( $invoice['outstanding_amount'] ?? 0 ) > 0.0 ) {
				return null;
			}
		}
		return $this->reference( $order, $invoice, $payment );
	}

	/**
	 * Send an order, finishing whatever is missing.
	 *
	 * @param Item         $item    The queue item.
	 * @param OrderPayload $payload The order.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, OrderPayload $payload ): SendResult {
		$existing = $this->findOrder( $payload->clientRef );
		$warnings = array();
		$created  = null;

		if ( null === $existing ) {
			try {
				$created = $this->create( $payload );
			} catch ( ApiException $exception ) {
				// The site says the order already exists (a duplicate name or reference): it is the same order, so it is linked, not an error.
				$existing = self::isDuplicate( $exception ) ? $this->findOrder( $payload->clientRef ) : null;
				if ( null === $existing ) {
					throw $exception;
				}
			}
		}
		if ( null !== $created ) {
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			list( $order, $problem, $warnings ) = $created;
			if ( $problem instanceof ConnectorError ) {
				return SendResult::failure( $problem );
			}
		} else {
			$order = $existing;
			if ( 0 === (int) $order['docstatus'] ) {
				$problem = $this->verifyDraft( (string) $order['name'], $payload, $warnings );
				if ( $problem instanceof ConnectorError ) {
					return SendResult::failure( $problem );
				}
			}
		}

		if ( 0 === (int) $order['docstatus'] ) {
			$stored = $this->client->submit( 'Sales Order', (string) $order['name'] );
			$order  = array_merge( $order, array_intersect_key( $stored, array_flip( self::ORDER_FIELDS ) ) );
			if ( 1 !== (int) ( $order['docstatus'] ?? 0 ) ) {
				$order['docstatus'] = 1;
			}
		}

		$invoice = null;
		if ( 'order' !== $this->documents() ) {
			$invoice = $this->ensureInvoice( $order, $payload );
			if ( $invoice instanceof ConnectorError ) {
				return SendResult::failure( $invoice );
			}
		}
		$payment = null;
		if ( 'order_invoice_payment' === $this->documents() && $payload->isPaid() && null !== $invoice ) {
			$payment = $this->ensurePayment( $invoice, $order, $payload );
			if ( $payment instanceof ConnectorError ) {
				return SendResult::failure( $payment );
			}
		}

		$ref = $this->reference( $order, $invoice, $payment, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Whether a failure is the site saying that a record with this name already exists (DuplicateEntryError, HTTP 409).
	 *
	 * @param ApiException $exception Failure.
	 */
	public static function isDuplicate( ApiException $exception ): bool {
		return 409 === $exception->status() && 'DuplicateEntryError' === ( $exception->error()->context['field'] ?? '' );
	}

	/**
	 * The Sales Order of a client reference (the oldest that is not cancelled).
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findOrder( string $clientRef ): ?array {
		$rows = $this->client->rows( 'Sales Order', array( array( 'po_no', '=', $clientRef ), array( 'docstatus', '!=', 2 ) ), self::ORDER_FIELDS, 'creation asc', 5 );
		foreach ( $rows as $row ) {
			if ( (string) ( $row['po_no'] ?? '' ) === $clientRef ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The Sales Invoice made from an order (not a return, not cancelled).
	 *
	 * @param string $order Sales Order name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findInvoice( string $order ): ?array {
		$rows = $this->client->rows(
			'Sales Invoice',
			array( array( 'Sales Invoice Item', 'sales_order', '=', $order ), array( 'docstatus', '!=', 2 ), array( 'is_return', '=', 0 ) ),
			array( 'name', 'docstatus', 'status', 'grand_total', 'outstanding_amount', 'currency', 'posting_date', 'creation' ),
			'creation asc',
			20
		);
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $seen[ (string) $row['name'] ] ) ) {
				$seen[ (string) $row['name'] ] = $row;
			}
		}
		return array() === $seen ? null : reset( $seen );
	}

	/**
	 * The Payment Entry that references an invoice.
	 *
	 * @param string $invoice Sales Invoice name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findPayment( string $invoice ): ?array {
		$rows = $this->client->rows(
			'Payment Entry',
			array( array( 'Payment Entry Reference', 'reference_name', '=', $invoice ), array( 'docstatus', '!=', 2 ), array( 'payment_type', '=', 'Receive' ) ),
			array( 'name', 'docstatus', 'paid_amount', 'creation' ),
			'creation asc',
			5
		);
		return array() === $rows ? null : $rows[0];
	}

	/**
	 * The record for an order and its documents.
	 *
	 * @param array<string,mixed>      $order    Sales Order row.
	 * @param array<string,mixed>|null $invoice  Sales Invoice row.
	 * @param array<string,mixed>|null $payment  Payment Entry row.
	 * @param array<int,string>        $warnings Warnings to carry.
	 */
	public function reference( array $order, ?array $invoice, ?array $payment, array $warnings = array() ): ExternalRef {
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		$extra    = array(
			'total_minor' => $this->amounts->toMinor( (float) ( $order['grand_total'] ?? 0 ), $this->amounts->exponent( $currency ) ),
			'currency'    => $currency,
			'status'      => (string) ( $order['status'] ?? '' ),
		);
		if ( null !== $invoice ) {
			$extra['invoice'] = (string) $invoice['name'];
		}
		if ( null !== $payment ) {
			$extra['payment'] = (string) $payment['name'];
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$name = (string) $order['name'];
		return new ExternalRef( $name, $name, $this->link( 'sales-order', $name ), $extra );
	}

	/**
	 * A link to a document in the ERPNext desk.
	 *
	 * @param string $slug Route name of the DocType, for example sales-order.
	 * @param string $name Document name.
	 */
	public function link( string $slug, string $name ): string {
		return rtrim( $this->address, '/' ) . '/app/' . $slug . '/' . rawurlencode( $name );
	}

	/**
	 * Insert the draft order and check it.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array{0:array<string,mixed>,1:ConnectorError|null,2:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( OrderPayload $payload ): array|ConnectorError {
		$company = $this->lookups->company();
		if ( $company instanceof ConnectorError ) {
			return $company;
		}
		if ( '' === $payload->currency ) {
			return Problems::refused( $this->system, 'currency', __( 'The order has no currency.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$priceList = $this->lookups->priceList( $payload->currency );
		if ( $priceList instanceof ConnectorError ) {
			return $priceList;
		}
		$rate = $this->lookups->exchangeRate( $payload->currency, $company['currency'], '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ) );
		if ( $rate instanceof ConnectorError ) {
			return $rate;
		}

		$skus = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		foreach ( $payload->extras as $row ) {
			if ( 'discount' === $row['kind'] ) {
				continue;
			}
			$option = 'shipping' === $row['kind'] ? 'shipping_item' : 'fee_item';
			if ( '' === $this->options->text( $option ) ) {
				return Problems::refused(
					$this->system,
					$option,
					sprintf(
						/* translators: %s: option name. */
						__( 'The order has a shipping or fee amount, so the connection settings need the item code in "%s".', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$option
					)
				);
			}
			$skus[] = $this->options->text( $option );
		}
		$items = $this->lookups->items( $skus );
		foreach ( $skus as $sku ) {
			if ( ! isset( $items[ strtolower( $sku ) ] ) ) {
				return Problems::missingProduct( $this->system, $sku );
			}
		}

		$template = $this->template( $payload, (string) $company['name'] );
		if ( $template instanceof ConnectorError ) {
			return $template;
		}

		$customer = $this->customers->resolve( $payload );
		if ( $customer instanceof ConnectorError ) {
			return $customer;
		}

		$exponent = $this->amounts->exponent( $payload->currency );
		$date     = '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' );
		$delivery = $this->amounts->addDays( $date, $this->options->integer( 'delivery_days', 0, 365, 0 ) );
		$rows     = array();
		foreach ( $payload->lines as $line ) {
			$price = $this->taxes->price( $this->discounted( (int) $line['price_minor'], (float) $line['discount'] ), $template['template'], $payload->pricesIncludeTax );
			if ( $price instanceof ConnectorError ) {
				return $price;
			}
			$rows[] = $this->itemRow( $items[ strtolower( (string) $line['sku'] ) ], (float) $line['quantity'], $this->amounts->toDecimal( $price, $exponent ), $delivery, (string) $line['name'] );
		}
		$discount = 0;
		foreach ( $payload->extras as $row ) {
			$price = $this->taxes->price( (int) $row['amount_minor'], $template['template'], $payload->pricesIncludeTax );
			if ( $price instanceof ConnectorError ) {
				return $price;
			}
			if ( 'discount' === $row['kind'] ) {
				$discount = $price;
				continue;
			}
			$code   = $this->options->text( 'shipping' === $row['kind'] ? 'shipping_item' : 'fee_item' );
			$rows[] = $this->itemRow( $items[ strtolower( $code ) ], 1.0, $this->amounts->toDecimal( $price, $exponent ), $delivery, (string) $row['name'] );
		}
		$doc = array(
			'customer'            => $customer['customer'],
			'company'             => $company['name'],
			'order_type'          => 'Sales',
			'transaction_date'    => $date,
			'delivery_date'       => $delivery,
			'po_no'               => $payload->clientRef,
			'currency'            => $payload->currency,
			'conversion_rate'     => $rate,
			'selling_price_list'  => $priceList,
			'price_list_currency' => $payload->currency,
			'plc_conversion_rate' => $rate,
			'ignore_pricing_rule' => 1,
			'items'               => $rows,
			'taxes'               => null === $template['template'] ? array() : $template['template']['rows'],
			'taxes_and_charges'   => null === $template['template'] ? '' : $template['template']['name'],
		);
		if ( '' !== $customer['billing'] ) {
			$doc['customer_address'] = $customer['billing'];
		}
		if ( '' !== $customer['shipping'] ) {
			$doc['shipping_address_name'] = $customer['shipping'];
		}
		if ( '' !== $customer['contact'] ) {
			$doc['contact_person'] = $customer['contact'];
		}
		if ( '' !== $this->options->text( 'warehouse' ) ) {
			$doc['set_warehouse'] = $this->options->text( 'warehouse' );
		}
		if ( $discount > 0 ) {
			$doc['apply_discount_on'] = 'Net Total';
			$doc['discount_amount']   = $this->amounts->toDecimal( $discount, $exponent );
		}

		$stored = $this->client->insert( 'Sales Order', $doc );
		if ( '' === (string) ( $stored['name'] ?? '' ) ) {
			return Problems::refused( $this->system, 'sales_order', __( 'ERPNext did not return the new order.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$order    = array_intersect_key( $stored, array_flip( self::ORDER_FIELDS ) ) + array( 'docstatus' => 0 );
		$warnings = array();
		$problem  = $this->verify( $stored, $payload, $warnings );
		if ( '' !== $payload->note ) {
			$this->note( (string) $stored['name'], $payload->note );
		}
		return array( $order, $problem, $warnings );
	}

	/**
	 * Read a draft that an earlier pass left and check it again.
	 *
	 * @param string            $name     Sales Order name.
	 * @param OrderPayload      $payload  The order.
	 * @param array<int,string> $warnings Receives warnings.
	 *
	 * @throws ApiException On failure.
	 */
	private function verifyDraft( string $name, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		$doc = $this->client->get( 'Sales Order', $name );
		if ( null === $doc ) {
			return Problems::refused( $this->system, 'sales_order', __( 'The draft order disappeared from ERPNext. It will be made again on the next try.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $this->verify( $doc, $payload, $warnings );
	}

	/**
	 * Check currency and total of an inserted document.
	 *
	 * @param array<string,mixed> $doc      Stored Sales Order.
	 * @param OrderPayload        $payload  The order.
	 * @param array<int,string>   $warnings Receives warnings.
	 */
	private function verify( array $doc, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		$name = (string) ( $doc['name'] ?? '' );
		if ( strtoupper( (string) ( $doc['currency'] ?? '' ) ) !== $payload->currency ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: ERPNext document name, 2: currency the order has in ERPNext, 3: currency of the shop. */
					__( '%1$s came back in %2$s but the shop order is in %3$s. The draft stays in ERPNext; delete it there and check the price list and customer currency.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$name,
					(string) ( $doc['currency'] ?? '' ),
					$payload->currency
				),
				'currency mismatch'
			);
		}
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff  = $check->difference( $payload->totalMinor, $doc['grand_total'] ?? 0, $payload->currency, $name );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The draft stays in ERPNext and was not submitted. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}

	/**
	 * Which tax template the order uses, if any.
	 *
	 * @param OrderPayload $payload The order.
	 * @param string       $company Company.
	 * @return array{template:array<string,mixed>|null}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function template( OrderPayload $payload, string $company ): array|ConnectorError {
		$codes = array();
		$none  = false;
		$rows  = array_merge( $payload->lines, $payload->extras );
		foreach ( $rows as $row ) {
			if ( 'discount' === ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$list = $row['tax'] ?? null;
			if ( null === $list || array() === $list ) {
				$none = true;
				continue;
			}
			foreach ( (array) $list as $code ) {
				$codes[ (string) $code ] = true;
			}
		}
		if ( count( $codes ) > 1 || ( count( $codes ) === 1 && $none && $payload->taxesMapped ) ) {
			return Problems::refused(
				$this->system,
				'order_line[].tax',
				__( 'ERPNext applies one tax template to a whole order. This order has lines with different tax codes, or with and without a tax code.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		if ( array() === $codes ) {
			return array( 'template' => null );
		}
		$template = $this->taxes->template( (string) array_key_first( $codes ), $company );
		if ( $template instanceof ConnectorError ) {
			return $template;
		}
		return array( 'template' => $template );
	}

	/**
	 * One item row of a Sales Order.
	 *
	 * @param array<string,mixed> $item     Item row from the lookup.
	 * @param float               $quantity Quantity.
	 * @param float               $rate     Unit price as a decimal.
	 * @param string              $delivery Delivery date.
	 * @param string              $text     Line text.
	 * @return array<string,mixed>
	 */
	private function itemRow( array $item, float $quantity, float $rate, string $delivery, string $text ): array {
		$row = array(
			'item_code'     => (string) $item['item_code'],
			'qty'           => $quantity,
			'rate'          => $rate,
			'delivery_date' => $delivery,
		);
		if ( '' !== $text ) {
			$row['description'] = $text;
		}
		return $row;
	}

	/**
	 * A unit price reduced by a line discount in percent, in whole numbers.
	 *
	 * @param int   $minor    Price in minor units.
	 * @param float $discount Percent (0 to 100).
	 */
	private function discounted( int $minor, float $discount ): int {
		if ( $discount <= 0.0 ) {
			return $minor;
		}
		$per = (int) round( $discount * 100 );
		$abs = abs( $minor );
		$net = intdiv( $abs * ( 10000 - $per ) + 5000, 10000 );
		return $minor < 0 ? -$net : $net;
	}

	/**
	 * Add the buyer's note to the order as a Comment. A failure is not an error: the note is a courtesy.
	 *
	 * @param string $order Sales Order name.
	 * @param string $note  Text.
	 */
	private function note( string $order, string $note ): void {
		try {
			$this->client->insert(
				'Comment',
				array(
					'comment_type'      => 'Comment',
					'reference_doctype' => 'Sales Order',
					'reference_name'    => $order,
					'content'           => $note,
				)
			);
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Find the invoice of an order or make it, and submit it.
	 *
	 * @param array<string,mixed> $order   Sales Order row (submitted).
	 * @param OrderPayload        $payload The order.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function ensureInvoice( array $order, OrderPayload $payload ): array|ConnectorError {
		$invoice = $this->findInvoice( (string) $order['name'] );
		if ( null === $invoice ) {
			$so = $this->client->get( 'Sales Order', (string) $order['name'] );
			if ( null === $so ) {
				return Problems::refused( $this->system, 'sales_order', __( 'The order disappeared from ERPNext before it was invoiced.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$date  = '' !== $payload->date ? $payload->date : (string) ( $so['transaction_date'] ?? gmdate( 'Y-m-d' ) );
			$items = array();
			foreach ( (array) ( $so['items'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$items[] = array(
					'item_code'   => $row['item_code'],
					'qty'         => $row['qty'],
					'rate'        => $row['rate'],
					'warehouse'   => $row['warehouse'] ?? '',
					'sales_order' => $so['name'],
					'so_detail'   => $row['name'],
				);
			}
			$taxes = array();
			foreach ( (array) ( $so['taxes'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$taxes[] = array_intersect_key( $row, array_flip( array( 'charge_type', 'row_id', 'account_head', 'description', 'included_in_print_rate', 'cost_center', 'rate', 'tax_amount' ) ) );
				}
			}
			$doc = array(
				'customer'            => $so['customer'],
				'company'             => $so['company'],
				'posting_date'        => $date,
				'set_posting_time'    => 1,
				'due_date'            => $date,
				'po_no'               => $payload->clientRef,
				'currency'            => $so['currency'],
				'conversion_rate'     => $so['conversion_rate'] ?? 1,
				'selling_price_list'  => $so['selling_price_list'] ?? '',
				'price_list_currency' => $so['price_list_currency'] ?? $so['currency'],
				'plc_conversion_rate' => $so['plc_conversion_rate'] ?? 1,
				'ignore_pricing_rule' => 1,
				'update_stock'        => $this->options->flag( 'update_stock', false ) ? 1 : 0,
				'items'               => $items,
				'taxes'               => $taxes,
				'taxes_and_charges'   => $so['taxes_and_charges'] ?? '',
				'remarks'             => $payload->note,
			);
			foreach ( array( 'customer_address', 'shipping_address_name', 'contact_person' ) as $key ) {
				if ( ! empty( $so[ $key ] ) ) {
					$doc[ $key ] = $so[ $key ];
				}
			}
			if ( (float) ( $so['discount_amount'] ?? 0 ) > 0.0 ) {
				$doc['apply_discount_on'] = $so['apply_discount_on'] ?? 'Net Total';
				$doc['discount_amount']   = $so['discount_amount'];
			}
			$stored  = $this->client->insert( 'Sales Invoice', $doc );
			$invoice = array_intersect_key( $stored, array_flip( array( 'name', 'docstatus', 'status', 'grand_total', 'outstanding_amount', 'currency' ) ) ) + array( 'docstatus' => 0 );
			if ( '' === (string) ( $invoice['name'] ?? '' ) ) {
				return Problems::refused( $this->system, 'sales_invoice', __( 'ERPNext did not return the new invoice.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
		}
		if ( 0 === (int) $invoice['docstatus'] ) {
			$stored  = $this->client->submit( 'Sales Invoice', (string) $invoice['name'] );
			$invoice = array_merge( $invoice, array_intersect_key( $stored, array_flip( array( 'status', 'grand_total', 'outstanding_amount', 'currency' ) ) ), array( 'docstatus' => 1 ) );
		}
		return $invoice;
	}

	/**
	 * Find the payment of an invoice or record it, and submit it.
	 *
	 * @param array<string,mixed> $invoice Sales Invoice row (submitted).
	 * @param array<string,mixed> $order   Sales Order row.
	 * @param OrderPayload        $payload The order.
	 * @return array<string,mixed>|null|ConnectorError Null when nothing is outstanding.
	 *
	 * @throws ApiException On failure.
	 */
	private function ensurePayment( array $invoice, array $order, OrderPayload $payload ): array|null|ConnectorError {
		$payment = $this->findPayment( (string) $invoice['name'] );
		if ( null === $payment ) {
			$full = $this->client->get( 'Sales Invoice', (string) $invoice['name'] );
			if ( null === $full ) {
				return Problems::refused( $this->system, 'sales_invoice', __( 'The invoice disappeared from ERPNext before the payment was recorded.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$outstanding = (float) ( $full['outstanding_amount'] ?? 0 );
			if ( $outstanding <= 0.0 ) {
				return null;
			}
			$accountName = $this->options->text( 'payment_account' );
			if ( '' === $accountName ) {
				return Problems::refused( $this->system, 'payment_account', __( 'To record payments, enter the bank or cash account that receives the money in the connection settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$account = $this->client->get( 'Account', $accountName );
			if ( null === $account || 1 === (int) ( $account['is_group'] ?? 0 ) || 1 === (int) ( $account['disabled'] ?? 0 ) || (string) ( $account['company'] ?? '' ) !== (string) $full['company'] ) {
				return Problems::refused( $this->system, 'payment_account', __( 'The payment account must exist, be enabled, not be a group, and belong to the company of the order.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			if ( strtoupper( (string) ( $account['account_currency'] ?? '' ) ) !== strtoupper( (string) $full['currency'] ) ) {
				return Problems::refused(
					$this->system,
					'payment_account',
					sprintf(
						/* translators: 1: account currency, 2: order currency. */
						__( 'The payment account is in %1$s but the order is in %2$s. Use an account in the order currency; no exchange differences are booked.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						(string) ( $account['account_currency'] ?? '' ),
						(string) $full['currency']
					)
				);
			}
			$date = '' !== $payload->paidAt ? $payload->paidAt : (string) ( $full['posting_date'] ?? gmdate( 'Y-m-d' ) );
			$doc  = array(
				'payment_type'    => 'Receive',
				'company'         => $full['company'],
				'posting_date'    => $date,
				'party_type'      => 'Customer',
				'party'           => $full['customer'],
				'paid_to'         => $accountName,
				'paid_amount'     => $outstanding,
				'received_amount' => $outstanding,
				'reference_no'    => '' !== $payload->paymentReference ? $payload->paymentReference : $payload->clientRef,
				'reference_date'  => $date,
				'references'      => array(
					array(
						'reference_doctype' => 'Sales Invoice',
						'reference_name'    => $full['name'],
						'allocated_amount'  => $outstanding,
					),
				),
			);
			if ( '' !== $this->options->text( 'mode_of_payment' ) ) {
				$doc['mode_of_payment'] = $this->options->text( 'mode_of_payment' );
			}
			$rate = (float) ( $full['conversion_rate'] ?? 1 );
			if ( $rate > 0.0 && 1.0 !== $rate ) {
				$doc['source_exchange_rate'] = $rate;
				$doc['target_exchange_rate'] = $rate;
			}
			$stored  = $this->client->insert( 'Payment Entry', $doc );
			$payment = array_intersect_key( $stored, array_flip( array( 'name', 'docstatus', 'paid_amount' ) ) ) + array( 'docstatus' => 0 );
			if ( '' === (string) ( $payment['name'] ?? '' ) ) {
				return Problems::refused( $this->system, 'payment_entry', __( 'ERPNext did not return the new payment.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
		}
		if ( 0 === (int) $payment['docstatus'] ) {
			$this->client->submit( 'Payment Entry', (string) $payment['name'] );
			$payment['docstatus'] = 1;
		}
		unset( $order );
		return $payment;
	}
}
