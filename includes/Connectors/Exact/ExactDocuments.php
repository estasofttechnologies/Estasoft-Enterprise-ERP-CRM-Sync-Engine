<?php
/**
 * Sales invoices, sales orders and credit notes in Exact Online.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;

/**
 * The create-and-verify flow. The reference `WC-{id}` is written to `YourRef` and searched before every create. Exact computes the
 * totals itself, so a document is read back after it is created and its currency and total are compared with the order. Until that
 * check has passed, the document's description carries a "sync pending" marker: `isComplete()` is false for a marked document, so a
 * crash or a lost answer between the create and the check is picked up again by `send()` and never linked unchecked.
 */
final class ExactDocuments {

	/**
	 * Text that marks a document whose check has not passed yet.
	 */
	public const PENDING = ' [sync pending]';

	/**
	 * Longest description Exact keeps, in characters.
	 */
	private const DESCRIPTION_MAX = 60;

	/**
	 * Warnings of the current send (cut texts, accepted differences).
	 *
	 * @var array<int,string>
	 */
	private array $warnings = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param ExactApi     $api     API of the division.
	 * @param ExactLookups $lookups Lookups.
	 * @param CrmOptions   $options Options.
	 */
	public function __construct( private readonly ExactApi $api, private readonly ExactLookups $lookups, private readonly CrmOptions $options ) {
	}

	/**
	 * Whether orders go to sales orders instead of sales invoices.
	 */
	public function usesOrders(): bool {
		return 'order' === $this->options->choice( 'document', array( 'invoice', 'order' ), 'invoice' );
	}

	/**
	 * The record of an order or refund reference, or null. Several: the first by number.
	 *
	 * @param string $reference  WC-{id}.
	 * @param bool   $creditNote True to look among credit notes (refunds), false among invoices or orders.
	 * @return array<string,mixed>|null
	 */
	public function find( string $reference, bool $creditNote = false ): ?array {
		$orders   = ! $creditNote && $this->usesOrders();
		$endpoint = $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices';
		$found    = array();
		foreach ( $this->api->rows(
			$endpoint,
			array(
				'$filter' => 'YourRef eq ' . ExactApi::str( $reference ),
				'$select' => $this->select( $orders ),
			)
		) as $row ) {
			if ( trim( (string) ( $row['YourRef'] ?? '' ) ) !== $reference ) {
				continue;
			}
			$isCredit = 8021 === (int) ( $row['Type'] ?? 0 );
			if ( ! $orders && $isCredit !== $creditNote ) {
				continue;
			}
			$found[] = $row;
		}
		if ( array() === $found ) {
			return null;
		}
		usort( $found, static fn( array $a, array $b ): int => (int) ( $a['InvoiceNumber'] ?? $a['OrderNumber'] ?? 0 ) <=> (int) ( $b['InvoiceNumber'] ?? $b['OrderNumber'] ?? 0 ) );
		return $found[0];
	}

	/**
	 * Whether a record is finished: its check passed (no pending marker), a credit note really reduces what the customer owes (a negative
	 * amount) and, when invoices are to be processed, it is processed.
	 *
	 * @param array<string,mixed> $row         Record from find().
	 * @param bool                $creditNote  Whether it is a credit note.
	 */
	public function isComplete( array $row, bool $creditNote = false ): bool {
		if ( str_contains( (string) ( $row['Description'] ?? '' ), trim( self::PENDING ) ) ) {
			return false;
		}
		if ( $creditNote && ! self::reducesReceivables( $row, '' ) ) {
			return false;
		}
		if ( $this->wantsProcessing( $creditNote ) && 50 !== (int) ( $row['Status'] ?? 0 ) ) {
			return false;
		}
		return true;
	}

	/**
	 * A reference to a record.
	 *
	 * @param array<string,mixed> $row Record.
	 */
	public function ref( array $row ): ExternalRef {
		$orders = isset( $row['OrderID'] );
		$id     = (string) ( $row['OrderID'] ?? $row['InvoiceID'] ?? '' );
		$number = (string) ( $row['OrderNumber'] ?? $row['InvoiceNumber'] ?? '' );
		return new ExternalRef(
			$id,
			'' !== $number ? $number : trim( (string) ( $row['YourRef'] ?? '' ) ),
			null,
			array(
				'currency'    => (string) ( $row['Currency'] ?? '' ),
				'total_minor' => abs( Amounts::minor( $row['AmountFC'] ?? null, (string) ( $row['Currency'] ?? '' ) ) ?? 0 ),
				'status'      => (int) ( $row['Status'] ?? 0 ),
				'document'    => $orders ? 'order' : 'invoice',
				'warnings'    => $this->warnings,
			)
		);
	}

	/**
	 * Create or finish the document of an order.
	 *
	 * @param ExactOrder $order Order.
	 * @throws ExactFailure When the order cannot be sent as it is.
	 */
	public function sendOrder( ExactOrder $order ): SendResult {
		$this->warnings = array();
		$orders         = $this->usesOrders();
		$row            = $this->find( $order->reference );
		$created        = false;
		if ( null === $row ) {
			$row     = $this->create( $order, $orders );
			$created = true;
		}
		$this->verify( $row, $order->currency, $order->totalMinor, false, $created, $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices' );
		$row = $this->finalise( $row, $this->finalDescription( $order->reference, $order->accountName() ), $order->date, false );
		return $created ? SendResult::created( $this->ref( $row ) ) : SendResult::existing( $this->ref( $row ) );
	}

	/**
	 * Create or finish the credit note of a refund.
	 *
	 * @param ExactRefund $refund Refund.
	 * @throws ExactFailure When the refund cannot be sent as it is.
	 */
	public function sendRefund( ExactRefund $refund ): SendResult {
		$this->warnings = array();
		$row            = $this->find( $refund->reference, true );
		$created        = false;
		$currency       = $refund->currency;
		if ( null === $row ) {
			$original = $this->find( $refund->orderRef );
			if ( null === $original || '' === (string) ( $original['OrderedBy'] ?? '' ) ) {
				ExactFailure::refuse( 'order_ref', __( 'The order of this refund is not in Exact Online.', 'estasoft-erp-crm-sync-engine' ) );
			}
			if ( '' === $currency ) {
				$currency = (string) ( $original['Currency'] ?? '' );
			}
			$row     = $this->createCreditNote( $refund, (string) $original['OrderedBy'], $currency );
			$created = true;
		} elseif ( '' === $currency ) {
			$currency = (string) ( $row['Currency'] ?? '' );
		}
		$this->verify( $row, $currency, $refund->amountMinor, true, $created, 'salesinvoice/SalesInvoices' );
		/* translators: 1: refund reference, 2: order reference */
		$description = sprintf( __( 'Refund %1$s of %2$s', 'estasoft-erp-crm-sync-engine' ), $refund->reference, $refund->orderRef );
		$row         = $this->finalise( $row, $description, $refund->date, true );
		return $created ? SendResult::created( $this->ref( $row ) ) : SendResult::existing( $this->ref( $row ) );
	}

	/**
	 * The fields read back for each kind of document.
	 *
	 * @param bool $orders True for sales orders.
	 */
	public function select( bool $orders ): string {
		return $orders
			? 'OrderID,OrderNumber,Description,Status,YourRef,Currency,AmountFC,OrderDate,OrderedBy'
			: 'InvoiceID,InvoiceNumber,Description,Status,YourRef,Currency,AmountFC,InvoiceDate,Type,OrderedBy';
	}

	/**
	 * Whether a document is to be processed by the sync.
	 *
	 * @param bool $creditNote Whether it is a credit note.
	 */
	private function wantsProcessing( bool $creditNote ): bool {
		unset( $creditNote );
		return ! $this->usesOrders() && 'yes' === $this->options->choice( 'process_invoice', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * Create the document of an order and read it back.
	 *
	 * @param ExactOrder $order  Order.
	 * @param bool       $orders True for a sales order.
	 * @return array<string,mixed>
	 */
	private function create( ExactOrder $order, bool $orders ): array {
		if ( strlen( $order->reference ) > 30 ) {
			ExactFailure::refuse( 'reference', __( 'The order number is too long for the reference field of Exact Online.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lines   = array_merge( $this->productLines( $order ), $this->extraLines( $order ) );
		$journal = $orders ? '' : $this->lookups->journal( false );
		$account = $this->lookups->accountByEmail( $order->partner['email'] );
		if ( null === $account ) {
			if ( 'match_only' === $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) ) {
				ExactFailure::refuse( 'partner.email', __( 'No customer with this email address exists in Exact Online, and the connection only matches existing customers.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$account = $this->lookups->createAccount( $order->partner );
		}
		$accountId = ExactApi::guid( (string) ( $account['ID'] ?? '' ) );
		$body      = array(
			'OrderedBy'   => $accountId,
			'YourRef'     => $order->reference,
			'Currency'    => $order->currency,
			'Description' => $order->reference . self::PENDING,
			'OrderDate'   => $order->date . 'T00:00:00',
		);
		if ( '' !== $order->note ) {
			$body['Remarks'] = Text::limit( $order->note, 1000 )[0];
		}
		if ( $orders ) {
			$body['SalesOrderLines'] = $lines;
		} else {
			$body['Journal']           = $journal;
			$body['InvoiceDate']       = $order->date . 'T00:00:00';
			$body['SalesInvoiceLines'] = $lines;
		}
		$made = $this->api->create( $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices', $body );
		return $this->readBack( $made, $orders );
	}

	/**
	 * Create the credit note of a refund and read it back.
	 *
	 * @param ExactRefund $refund    Refund.
	 * @param string      $accountId Customer of the original document.
	 * @param string      $currency  Currency.
	 * @return array<string,mixed>
	 */
	private function createCreditNote( ExactRefund $refund, string $accountId, string $currency ): array {
		if ( strlen( $refund->reference ) > 30 ) {
			ExactFailure::refuse( 'reference', __( 'The refund number is too long for the reference field of Exact Online.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lines = array();
		foreach ( $refund->lines as $line ) {
			$sku  = '' !== $line['sku'] ? $line['sku'] : $this->options->text( 'refund_item' );
			$item = $this->requireItem( $sku, 'refund_item' );
			$unit = $this->netUnit( (int) $line['amount'], (string) $line['tax'], $refund->pricesIncludeTax );
			$qty  = (string) $line['quantity'];
			if ( ctype_digit( $qty ) && (int) $qty > 0 && 0 === $unit % (int) $qty ) {
				$unit = intdiv( $unit, (int) $qty );
			} else {
				$qty = '1';
			}
			$lines[] = $this->line( $item, (string) $line['name'], $qty, $unit, (string) $line['tax'], $currency );
		}
		if ( $refund->shippingMinor > 0 ) {
			$item    = $this->requireItem( $this->options->text( 'shipping_item' ), 'shipping_item' );
			$unit    = $this->netUnit( $refund->shippingMinor, $refund->shippingTax, $refund->pricesIncludeTax );
			$lines[] = $this->line( $item, __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), '1', $unit, $refund->shippingTax, $currency );
		}
		if ( array() === $lines ) {
			$item    = $this->requireItem( $this->options->text( 'refund_item' ), 'refund_item' );
			$lines[] = $this->line( $item, __( 'Refund', 'estasoft-erp-crm-sync-engine' ), '1', $refund->amountMinor, '', $currency );
		}
		$body = array(
			'Type'              => 8021,
			'OrderedBy'         => ExactApi::guid( $accountId ),
			'Journal'           => $this->lookups->journal( true ),
			'YourRef'           => $refund->reference,
			'Description'       => $refund->reference . self::PENDING,
			'InvoiceDate'       => $refund->date . 'T00:00:00',
			'OrderDate'         => $refund->date . 'T00:00:00',
			'SalesInvoiceLines' => $lines,
		);
		if ( '' !== $currency ) {
			$body['Currency'] = $currency;
		}
		if ( '' !== $refund->reason ) {
			$body['Remarks'] = Text::limit( $refund->reason, 1000 )[0];
		}
		return $this->readBack( $this->api->create( 'salesinvoice/SalesInvoices', $body ), false );
	}

	/**
	 * Read a created document again with the fields the check needs.
	 *
	 * @param array<string,mixed> $made   The answer to the create.
	 * @param bool                $orders True for a sales order.
	 * @return array<string,mixed>
	 */
	private function readBack( array $made, bool $orders ): array {
		$idField = $orders ? 'OrderID' : 'InvoiceID';
		$id      = (string) ( $made[ $idField ] ?? '' );
		$row     = $this->api->byKey( $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices', $idField, $id, $this->select( $orders ) );
		if ( null === $row ) {
			ExactFailure::refuse( 'document', __( 'Exact Online created the document but did not return it.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $row;
	}

	/**
	 * Whether a credit note reduces what the customer owes: its amount in the document's currency is zero or negative. A credit note that
	 * Exact Online books as a positive amount would raise the receivable, so it is never accepted, whatever the total option says.
	 *
	 * @param array<string,mixed> $row      Credit note as read back.
	 * @param string              $currency Currency of the refund, used when the row names none.
	 */
	private static function reducesReceivables( array $row, string $currency ): bool {
		$code = '' !== trim( (string) ( $row['Currency'] ?? '' ) ) ? strtoupper( trim( (string) $row['Currency'] ) ) : $currency;
		if ( '' === $code ) {
			return false;
		}
		$amount = Amounts::minor( $row['AmountFC'] ?? null, $code );
		return null !== $amount && $amount <= 0;
	}

	/**
	 * Compare a document with the order or refund. A document the connector just made is deleted again when it is wrong and Exact allows it.
	 *
	 * @param array<string,mixed> $row         Document.
	 * @param string              $currency    Expected currency.
	 * @param int|null            $totalMinor  Expected total including VAT (absolute), or null to skip.
	 * @param bool                $creditNote  Whether it must be a credit note.
	 * @param bool                $justCreated Whether this pass made it.
	 * @param string              $endpoint    Resource, for the delete.
	 * @throws ExactFailure When it differs and the options say fail.
	 */
	private function verify( array $row, string $currency, ?int $totalMinor, bool $creditNote, bool $justCreated, string $endpoint ): void {
		$reason = '';
		$firm   = false;
		$actual = (string) ( $row['Currency'] ?? '' );
		if ( $creditNote && isset( $row['Type'] ) && 8021 !== (int) $row['Type'] ) {
			$reason = __( 'Exact Online did not make a credit note.', 'estasoft-erp-crm-sync-engine' );
			$firm   = true;
		} elseif ( $creditNote && ! self::reducesReceivables( $row, $currency ) ) {
			$reason = __( 'Exact Online recorded the credit note with an amount that is not negative, which would add to what the customer owes.', 'estasoft-erp-crm-sync-engine' );
			$firm   = true;
		} elseif ( '' !== $actual && '' !== $currency && strtoupper( trim( $actual ) ) !== $currency ) {
			/* translators: 1: currency of the order, 2: currency in Exact Online */
			$reason = sprintf( __( 'The order is in %1$s but Exact Online recorded it in %2$s.', 'estasoft-erp-crm-sync-engine' ), $currency, trim( $actual ) );
		} elseif ( null !== $totalMinor && '' !== $currency ) {
			$recorded = Amounts::minor( $row['AmountFC'] ?? null, $currency );
			if ( null !== $recorded && ! Amounts::agree( abs( $totalMinor ), abs( $recorded ) ) ) {
				/* translators: 1: total of the order, 2: total in Exact Online, 3: currency */
				$reason = sprintf( __( 'The total is %1$s but Exact Online recorded %2$s %3$s.', 'estasoft-erp-crm-sync-engine' ), Amounts::decimal( abs( $totalMinor ), $currency ), Amounts::decimal( abs( $recorded ), $currency ), $currency );
			}
		}
		if ( '' === $reason ) {
			return;
		}
		if ( ! $firm && 'warn' === $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			$this->warnings[] = $reason;
			return;
		}
		if ( $justCreated ) {
			$id = (string) ( $row['InvoiceID'] ?? $row['OrderID'] ?? '' );
			if ( 20 === (int) ( $row['Status'] ?? 20 ) && $this->api->delete( $endpoint, $id ) ) {
				$reason .= ' ' . __( 'The document was removed again.', 'estasoft-erp-crm-sync-engine' );
			} else {
				$reason .= ' ' . __( 'The open document stays in Exact Online and has to be deleted there by a person.', 'estasoft-erp-crm-sync-engine' );
			}
		}
		ExactFailure::refuse( 'total', $reason );
	}

	/**
	 * Take the pending marker off a document that passed its check, and process the invoice when the options ask for it.
	 *
	 * @param array<string,mixed> $row         Document.
	 * @param string              $description Final description.
	 * @param string              $date        Order date, Y-m-d, used when the invoice is processed.
	 * @param bool                $creditNote  Whether it is a credit note.
	 * @return array<string,mixed> The document as it stands.
	 */
	private function finalise( array $row, string $description, string $date, bool $creditNote ): array {
		$orders = isset( $row['OrderID'] );
		$id     = (string) ( $row['OrderID'] ?? $row['InvoiceID'] ?? '' );
		if ( str_contains( (string) ( $row['Description'] ?? '' ), trim( self::PENDING ) ) ) {
			list( $text, $cut ) = Text::limit( $description, self::DESCRIPTION_MAX );
			if ( $cut ) {
				$this->warnings[] = __( 'The description was cut to 60 characters.', 'estasoft-erp-crm-sync-engine' );
			}
			$this->api->update( $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices', $id, array( 'Description' => $text ) );
			$row['Description'] = $text;
		}
		if ( $this->wantsProcessing( $creditNote ) && ! $orders ) {
			$fresh = $this->api->byKey( 'salesinvoice/SalesInvoices', 'InvoiceID', $id, $this->select( false ) ) ?? $row;
			if ( 50 !== (int) ( $fresh['Status'] ?? 0 ) ) {
				$this->api->create(
					'salesinvoice/PrintedSalesInvoices',
					array(
						'InvoiceID'   => ExactApi::guid( $id ),
						'InvoiceDate' => $date . 'T00:00:00',
					)
				);
				$fresh = $this->api->byKey( 'salesinvoice/SalesInvoices', 'InvoiceID', $id, $this->select( false ) ) ?? $fresh;
			}
			$row = $fresh;
		}
		return $row;
	}

	/**
	 * The final description of an order document: the reference and the buyer, cut to the length Exact keeps.
	 *
	 * @param string $reference WC-{id}.
	 * @param string $buyer     Buyer name.
	 */
	private function finalDescription( string $reference, string $buyer ): string {
		return trim( $reference . ' ' . $buyer );
	}

	/**
	 * The product lines of an order.
	 *
	 * @param ExactOrder $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function productLines( ExactOrder $order ): array {
		$lines = array();
		foreach ( $order->lines as $line ) {
			$item    = $this->requireItem( (string) $line['sku'], '' );
			$unit    = $this->netUnit( (int) $line['unit'], (string) $line['tax'], $order->pricesIncludeTax );
			$lines[] = $this->line( $item, (string) $line['name'], (string) $line['quantity'], $unit, (string) $line['tax'], $order->currency );
		}
		return $lines;
	}

	/**
	 * Shipping, fee and discount lines, each on the item the options name.
	 *
	 * @param ExactOrder $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function extraLines( ExactOrder $order ): array {
		$lines = array();
		foreach ( $order->extras as $extra ) {
			$option = $extra['kind'] . '_item';
			$item   = $this->requireItem( $this->options->text( $option ), $option );
			$amount = $this->netUnit( (int) $extra['amount'], (string) $extra['tax'], $order->pricesIncludeTax );
			if ( 'discount' === $extra['kind'] ) {
				$amount = -$amount;
			}
			$lines[] = $this->line( $item, (string) $extra['name'], '1', $amount, (string) $extra['tax'], $order->currency );
		}
		return $lines;
	}

	/**
	 * One document line.
	 *
	 * @param array<string,mixed> $item     Item.
	 * @param string              $name     Line text.
	 * @param string              $quantity Quantity text.
	 * @param int                 $unit     Net unit price, minor units.
	 * @param string              $taxCode  VAT code or empty.
	 * @param string              $currency Currency.
	 * @return array<string,mixed>
	 */
	private function line( array $item, string $name, string $quantity, int $unit, string $taxCode, string $currency ): array {
		$line = array(
			'Item'      => ExactApi::guid( (string) ( $item['ID'] ?? '' ) ),
			'Quantity'  => ExactPayload::quantityNumber( $quantity ),
			'UnitPrice' => Amounts::number( $unit, '' !== $currency ? $currency : 'EUR' ),
		);
		if ( '' !== $name ) {
			list( $text, $cut ) = Text::limit( $name, self::DESCRIPTION_MAX );
			if ( $cut ) {
				$this->warnings[] = __( 'A line text was cut to 60 characters.', 'estasoft-erp-crm-sync-engine' );
			}
			$line['Description'] = $text;
		}
		if ( '' !== $taxCode ) {
			$vat = $this->lookups->vatCode( $taxCode );
			if ( null === $vat ) {
				ExactFailure::refuseVat( $taxCode );
			}
			$line['VATCode'] = trim( (string) $vat['Code'] );
		}
		return $line;
	}

	/**
	 * An item that must exist and be sellable.
	 *
	 * @param string $code   Item code.
	 * @param string $option Name of the option that holds the code, or empty for a SKU.
	 * @return array<string,mixed>
	 */
	private function requireItem( string $code, string $option ): array {
		if ( '' === $code ) {
			/* translators: %s: name of a connection option. */
			ExactFailure::refuse( $option, sprintf( __( 'Set the item code in the connection option %s.', 'estasoft-erp-crm-sync-engine' ), $option ) );
		}
		$item = $this->lookups->item( $code );
		if ( null === $item ) {
			ExactFailure::refuseItem( $code );
		}
		if ( false === ( $item['IsSalesItem'] ?? true ) ) {
			/* translators: %s: item code. */
			ExactFailure::refuse( 'order_line.product', sprintf( __( 'Item %s is not allowed for sales in Exact Online.', 'estasoft-erp-crm-sync-engine' ), $code ) );
		}
		return $item;
	}

	/**
	 * A price without VAT: converted when the shop's prices include it.
	 *
	 * @param int    $price     Price in minor units.
	 * @param string $taxCode   VAT code of the line, or empty.
	 * @param bool   $inclusive Whether the price includes VAT.
	 * @throws ExactFailure When a gross price cannot be converted.
	 */
	private function netUnit( int $price, string $taxCode, bool $inclusive ): int {
		if ( ! $inclusive ) {
			return $price;
		}
		if ( '' === $taxCode ) {
			ExactFailure::refuse( 'order_line.tax', __( 'The prices include tax but a line has no VAT code. Map the tax of every line.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$vat = $this->lookups->vatCode( $taxCode );
		if ( null === $vat ) {
			ExactFailure::refuseVat( $taxCode );
		}
		$points = ExactTax::basisPoints( $vat['Percentage'] ?? null );
		if ( null === $points ) {
			/* translators: %s: VAT code. */
			ExactFailure::refuse( 'order_line.tax', sprintf( __( 'VAT code %s is not a plain percentage, so a price with tax cannot be converted.', 'estasoft-erp-crm-sync-engine' ), $taxCode ) );
		}
		return ExactTax::net( $price, $points );
	}
}
