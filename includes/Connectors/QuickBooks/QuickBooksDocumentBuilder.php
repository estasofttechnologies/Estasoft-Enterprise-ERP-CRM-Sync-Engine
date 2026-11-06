<?php
/**
 * Builds the invoice and credit memo bodies QuickBooks expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Pure: it takes the checked payload, the customer id and the items found, and returns the body or the reason the document cannot be built
 * (SYNC-MAP-001 for a product QuickBooks does not have, SYNC-VAL-002 for a missing item option or a line discount). Prices are sent as they
 * arrived; QuickBooks is told whether they include tax (GlobalTaxCalculation), so no tax arithmetic happens here.
 */
final class QuickBooksDocumentBuilder {

	/**
	 * Marker kept in the private note while a document has not been checked yet.
	 */
	public const UNCHECKED = ' [unchecked]';

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksOptions $options Connection options.
	 * @param QuickBooksAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly QuickBooksOptions $options, private readonly QuickBooksAmounts $amounts ) {
	}

	/**
	 * The invoice. Its private note carries the unchecked marker until the connector has read the total back.
	 *
	 * @param QuickBooksOrderPayload                 $payload    Checked payload.
	 * @param string                                 $customerId QuickBooks customer Id.
	 * @param string                                 $currency   Currency of the document.
	 * @param string                                 $home       Home currency of the company.
	 * @param bool                                   $multi      Whether the company uses more than one currency (then every document names its currency).
	 * @param bool                                   $us         Whether the company is a US company.
	 * @param array<string,array<string,mixed>>      $items      Items by lower-case SKU.
	 * @param array<string,array<string,mixed>|null> $named      Items named in the options, by kind (shipping, fee, discount, generic).
	 * @param array<int,string>                      $warnings   Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function invoice( QuickBooksOrderPayload $payload, string $customerId, string $currency, string $home, bool $multi, bool $us, array $items, array $named, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $line ) {
			$built = $this->productLine( $line, $currency, $items, $named, $cut );
			if ( $built instanceof ConnectorError ) {
				return $built;
			}
			$lines[] = $built;
		}
		foreach ( $payload->extras as $extra ) {
			$built = $this->extraLine( $extra, $currency, $named, $cut );
			if ( $built instanceof ConnectorError ) {
				return $built;
			}
			$lines[] = $built;
		}
		$invoice = array(
			'DocNumber'   => $payload->clientRef,
			'PrivateNote' => $payload->clientRef . self::UNCHECKED,
			'CustomerRef' => array( 'value' => $customerId ),
			'Line'        => $lines,
		);
		if ( '' !== $payload->date ) {
			$invoice['TxnDate'] = $payload->date;
			$invoice['DueDate'] = $this->dueDate( $payload->date );
		}
		if ( '' !== $currency && ( $multi || $currency !== $home ) ) {
			$invoice['CurrencyRef'] = array( 'value' => $currency );
		}
		if ( ! $us ) {
			$invoice['GlobalTaxCalculation'] = $payload->pricesIncludeTax ? 'TaxInclusive' : 'TaxExcluded';
		}
		if ( isset( $payload->partner['email'] ) ) {
			$invoice['BillEmail'] = array( 'Address' => QuickBooksPayloadReader::limit( $payload->partner['email'], 100, $cut ) );
		}
		$billing = QuickBooksCustomers::address( $payload->partner, $cut );
		if ( null !== $billing ) {
			$invoice['BillAddr'] = $billing;
		}
		$shipping = QuickBooksCustomers::address( $payload->delivery, $cut );
		if ( null !== $shipping ) {
			$invoice['ShipAddr'] = $shipping;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long value was shortened to fit QuickBooks.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $invoice;
	}

	/**
	 * The credit memo.
	 *
	 * @param QuickBooksRefundPayload                $payload    Checked payload.
	 * @param array<string,mixed>                    $invoice    The refunded order's invoice.
	 * @param string                                 $currency   Currency of the invoice.
	 * @param string                                 $home       Home currency of the company.
	 * @param bool                                   $multi      Whether the company uses more than one currency (then every document names its currency).
	 * @param bool                                   $us         Whether the company is a US company.
	 * @param array<string,array<string,mixed>>      $items      Items by lower-case SKU.
	 * @param array<string,array<string,mixed>|null> $named      Items named in the options, by kind.
	 * @param array<int,string>                      $warnings   Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function creditMemo( QuickBooksRefundPayload $payload, array $invoice, string $currency, string $home, bool $multi, bool $us, array $items, array $named, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $index => $line ) {
			$sku  = (string) $line['sku'];
			$item = '' !== $sku ? ( $items[ strtolower( $sku ) ] ?? null ) : null;
			$kind = (string) ( $line['kind'] ?? '' );
			if ( null === $item ) {
				$item = 'shipping' === $kind ? ( $named['shipping'] ?? null ) : null;
			}
			if ( null === $item ) {
				if ( '' !== $sku && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
					return QuickBooksErrors::unknownProduct( $sku );
				}
				$item = $named['generic'] ?? null;
			}
			if ( null === $item ) {
				return $this->needsItem( 'shipping' === $kind ? 'shipping_item' : 'generic_item' );
			}
			$text = '' !== (string) $line['name'] ? (string) $line['name'] : ( '' !== $sku ? $sku : (string) ( $item['Name'] ?? '' ) );
			if ( 0 === $index && '' !== $payload->reason ) {
				$text = $payload->reason . ': ' . $text;
			}
			$amount   = (int) $line['amount_minor'];
			$quantity = (string) $line['quantity'];
			if ( 1 === preg_match( '/^[1-9]\d{0,6}$/', $quantity ) && 0 === $amount % (int) $quantity ) {
				$qty  = (float) $quantity;
				$unit = $this->amounts->number( intdiv( $amount, (int) $quantity ), $currency );
			} else {
				$qty   = 1.0;
				$unit  = $this->amounts->number( $amount, $currency );
				$text .= ' (' . $quantity . ')';
			}
			$detail = array(
				'ItemRef'   => array( 'value' => (string) $item['Id'] ),
				'Qty'       => $qty,
				'UnitPrice' => $unit,
			);
			if ( null !== $line['tax'] ) {
				$detail['TaxCodeRef'] = array( 'value' => '' === $line['tax'] ? $this->options->text( 'no_tax_code', 'NON' ) : (string) $line['tax'] );
			}
			$lines[] = array(
				'DetailType'          => 'SalesItemLineDetail',
				'Amount'              => $this->amounts->number( $amount, $currency ),
				'Description'         => QuickBooksPayloadReader::limit( $text, 4000, $cut ),
				'SalesItemLineDetail' => $detail,
			);
		}
		$memo = array(
			'DocNumber'   => $payload->clientRef,
			'PrivateNote' => 'Refund of ' . $payload->orderRef . ( '' !== $payload->reason ? ': ' . $payload->reason : '' ) . self::UNCHECKED,
			'CustomerRef' => array( 'value' => QuickBooksPayloadReader::text( $invoice['CustomerRef']['value'] ?? '' ) ),
			'Line'        => $lines,
		);
		if ( '' !== $payload->date ) {
			$memo['TxnDate'] = $payload->date;
		}
		if ( '' !== $currency && ( $multi || $currency !== $home ) ) {
			$memo['CurrencyRef'] = array( 'value' => $currency );
		}
		if ( ! $us ) {
			$memo['GlobalTaxCalculation'] = $payload->pricesIncludeTax ? 'TaxInclusive' : 'TaxExcluded';
		}
		if ( $cut ) {
			$warnings[] = __( 'A long description was shortened to fit QuickBooks.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $memo;
	}

	/**
	 * One product line of an order.
	 *
	 * @param array<string,mixed>                    $line     Line from the payload.
	 * @param string                                 $currency Currency.
	 * @param array<string,array<string,mixed>>      $items    Items by lower-case SKU.
	 * @param array<string,array<string,mixed>|null> $named Items named in the options.
	 * @param bool                                   $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>|ConnectorError
	 */
	private function productLine( array $line, string $currency, array $items, array $named, bool &$cut ): array|ConnectorError {
		$sku  = (string) $line['sku'];
		$item = '' !== $sku ? ( $items[ strtolower( $sku ) ] ?? null ) : null;
		if ( (float) $line['discount'] > 0.0 ) {
			return QuickBooksErrors::refused( 'order_line[].discount', __( 'A discount on a single line is not supported. Send the discount as the order discount.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( null === $item ) {
			if ( '' !== $sku && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return QuickBooksErrors::unknownProduct( $sku );
			}
			$item = $named['generic'] ?? null;
			if ( null === $item ) {
				return $this->needsItem( 'generic_item' );
			}
			$name = '' !== (string) $line['name'] ? (string) $line['name'] : $sku;
		} else {
			$name = '' !== (string) $line['name'] ? (string) $line['name'] : QuickBooksPayloadReader::text( $item['Name'] ?? $sku );
		}
		return $this->line( (string) $item['Id'], $name, (string) $line['quantity'], (int) $line['price_minor'], $line['tax'], $currency, $cut );
	}

	/**
	 * One shipping, fee or discount line.
	 *
	 * @param array<string,mixed>                    $extra    Extra line from the payload.
	 * @param string                                 $currency Currency.
	 * @param array<string,array<string,mixed>|null> $named Items named in the options.
	 * @param bool                                   $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>|ConnectorError
	 */
	private function extraLine( array $extra, string $currency, array $named, bool &$cut ): array|ConnectorError {
		$kind = (string) $extra['kind'];
		$item = $named[ $kind ] ?? ( $named['generic'] ?? null );
		if ( null === $item ) {
			return $this->needsItem( '' !== $this->options->text( $kind . '_item' ) ? $kind . '_item' : ( '' !== $this->options->text( 'generic_item' ) ? $kind . '_item' : 'generic_item' ) );
		}
		return $this->line( (string) $item['Id'], (string) $extra['name'], '1', (int) $extra['price_minor'], $extra['tax'], $currency, $cut );
	}

	/**
	 * A sales line.
	 *
	 * @param string      $itemId   Item Id.
	 * @param string      $name     Description.
	 * @param string      $quantity Quantity as decimal text.
	 * @param int         $unit     Unit price in minor units.
	 * @param string|null $tax      Tax code, empty for untaxed, null for the item's default.
	 * @param string      $currency Currency.
	 * @param bool        $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>
	 */
	private function line( string $itemId, string $name, string $quantity, int $unit, ?string $tax, string $currency, bool &$cut ): array {
		$detail = array(
			'ItemRef'   => array( 'value' => $itemId ),
			'Qty'       => $this->amounts->quantity( $quantity ),
			'UnitPrice' => $this->amounts->number( $unit, $currency ),
		);
		if ( null !== $tax ) {
			$detail['TaxCodeRef'] = array( 'value' => '' === $tax ? $this->options->text( 'no_tax_code', 'NON' ) : $tax );
		}
		return array(
			'DetailType'          => 'SalesItemLineDetail',
			'Amount'              => $this->amounts->number( $this->amounts->lineTotal( $quantity, $unit ), $currency ),
			'Description'         => QuickBooksPayloadReader::limit( $name, 4000, $cut ),
			'SalesItemLineDetail' => $detail,
		);
	}

	/**
	 * The failure for a missing item option.
	 *
	 * @param string $option Option name.
	 */
	private function needsItem( string $option ): ConnectorError {
		return QuickBooksErrors::refused(
			$option,
			sprintf(
				/* translators: %s: the name of a connection option. */
				__( 'Set the name or SKU of a service item in the connection option "%s": QuickBooks needs an item on every line.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$option
			)
		);
	}

	/**
	 * The due date: the order date plus the connection's days.
	 *
	 * @param string $date Order date (YYYY-MM-DD).
	 */
	private function dueDate( string $date ): string {
		$days = $this->options->integer( 'due_days', 0, 0, 365 );
		if ( 0 === $days ) {
			return $date;
		}
		try {
			return ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return $date;
		}
	}
}
