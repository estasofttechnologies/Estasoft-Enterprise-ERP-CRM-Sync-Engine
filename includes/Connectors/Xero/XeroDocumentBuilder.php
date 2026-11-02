<?php
/**
 * Builds the invoice and credit note bodies Xero expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Pure: it takes the checked payload, the contact id and the items found, and returns the body or the reason the document cannot be built
 * (SYNC-MAP-001 for a product Xero does not have, SYNC-VAL-002 for a missing account option or an item that is not for sale). Prices are sent
 * exactly as they arrived; Xero is told whether they include tax (LineAmountTypes), so no tax arithmetic happens here.
 */
final class XeroDocumentBuilder {

	/**
	 * Set up with what it depends on.
	 *
	 * @param XeroOptions $options Connection options.
	 * @param XeroAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly XeroOptions $options, private readonly XeroAmounts $amounts ) {
	}

	/**
	 * The sales invoice (a draft; the connector approves it later).
	 *
	 * @param XeroOrderPayload                  $payload   Checked payload.
	 * @param string                            $contactId Xero ContactID.
	 * @param string                            $currency  Currency of the document (the payload's, else the organisation's).
	 * @param array<string,array<string,mixed>> $items     Items by lower-case code.
	 * @param array<int,string>                 $warnings  Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function invoice( XeroOrderPayload $payload, string $contactId, string $currency, array $items, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $line ) {
			$built = $this->productLine( $line, $currency, $items, $cut );
			if ( $built instanceof ConnectorError ) {
				return $built;
			}
			$lines[] = $built;
		}
		foreach ( $payload->extras as $extra ) {
			$built = $this->extraLine( $extra, $currency, $cut );
			if ( $built instanceof ConnectorError ) {
				return $built;
			}
			$lines[] = $built;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long description was shortened to fit Xero.', 'estasoft-erp-crm-sync-engine' );
		}
		$invoice = array(
			'Type'            => 'ACCREC',
			'Contact'         => array( 'ContactID' => $contactId ),
			'LineAmountTypes' => $payload->pricesIncludeTax ? 'Inclusive' : 'Exclusive',
			'Reference'       => $payload->clientRef,
			'Status'          => 'DRAFT',
			'LineItems'       => $lines,
		);
		if ( '' !== $payload->date ) {
			$invoice['Date']    = $payload->date;
			$invoice['DueDate'] = $this->dueDate( $payload->date );
		}
		if ( '' !== $payload->currency ) {
			$invoice['CurrencyCode'] = $payload->currency;
		}
		return $invoice;
	}

	/**
	 * The sales credit note (a draft).
	 *
	 * @param XeroRefundPayload                 $payload   Checked payload.
	 * @param string                            $contactId ContactID of the invoice's contact.
	 * @param string                            $currency  Currency of the invoice.
	 * @param array<string,array<string,mixed>> $items     Items by lower-case code.
	 * @param array<int,string>                 $warnings  Receives notes about cut values.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function creditNote( XeroRefundPayload $payload, string $contactId, string $currency, array $items, array &$warnings ): array|ConnectorError {
		$cut   = false;
		$lines = array();
		foreach ( $payload->lines as $index => $line ) {
			$sku  = (string) $line['sku'];
			$item = '' !== $sku ? ( $items[ strtolower( $sku ) ] ?? null ) : null;
			$text = '' !== (string) $line['name'] ? (string) $line['name'] : ( null !== $item ? XeroPayloadReader::text( $item['Name'] ?? $sku ) : ( '' !== $sku ? $sku : __( 'Refund', 'estasoft-erp-crm-sync-engine' ) ) );
			if ( 0 === $index && '' !== $payload->reason ) {
				$text = $payload->reason . ': ' . $text;
			}
			$row = array(
				'Description' => XeroPayloadReader::limit( $text, 4000, $cut ),
				'Quantity'    => $this->amounts->quantity( (string) $line['quantity'] ),
				'LineAmount'  => $this->amounts->number( (int) $line['amount_minor'], $currency ),
			);
			if ( null !== $item ) {
				$row['ItemCode'] = XeroPayloadReader::text( $item['Code'] );
			} else {
				if ( '' !== $sku && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
					return XeroErrors::unknownProduct( $sku );
				}
				$account = $this->account( 'sales' );
				if ( '' === $account ) {
					return $this->needsAccount( 'sales_account' );
				}
				$row['AccountCode'] = $account;
			}
			if ( null !== $line['tax'] ) {
				$row['TaxType'] = '' === $line['tax'] ? $this->options->text( 'no_tax_type', 'NONE' ) : (string) $line['tax'];
			}
			$lines[] = $row;
		}
		$note = array(
			'Type'            => 'ACCRECCREDIT',
			'Contact'         => array( 'ContactID' => $contactId ),
			'LineAmountTypes' => $payload->pricesIncludeTax ? 'Inclusive' : 'Exclusive',
			'Reference'       => $payload->clientRef,
			'Status'          => 'DRAFT',
			'LineItems'       => $lines,
		);
		if ( '' !== $currency ) {
			$note['CurrencyCode'] = $currency;
		}
		if ( '' !== $payload->date ) {
			$note['Date'] = $payload->date;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long description was shortened to fit Xero.', 'estasoft-erp-crm-sync-engine' );
		}
		return $note;
	}

	/**
	 * One product line of an order.
	 *
	 * @param array<string,mixed>               $line     Line from the payload.
	 * @param string                            $currency Currency.
	 * @param array<string,array<string,mixed>> $items    Items by lower-case code.
	 * @param bool                              $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>|ConnectorError
	 */
	private function productLine( array $line, string $currency, array $items, bool &$cut ): array|ConnectorError {
		$sku  = (string) $line['sku'];
		$item = '' !== $sku ? ( $items[ strtolower( $sku ) ] ?? null ) : null;
		$row  = array(
			'Quantity'   => $this->amounts->quantity( (string) $line['quantity'] ),
			'UnitAmount' => $this->amounts->number( (int) $line['price_minor'], $currency ),
		);
		if ( null !== $item ) {
			if ( isset( $item['IsSold'] ) && false === $item['IsSold'] ) {
				return XeroErrors::refused(
					'order_line[].product',
					sprintf(
						/* translators: %s: item code. */
						__( 'The item %s is not marked as sold in Xero.', 'estasoft-erp-crm-sync-engine' ),
						$sku
					)
				);
			}
			$row['ItemCode'] = XeroPayloadReader::text( $item['Code'] );
			$name            = '' !== (string) $line['name'] ? (string) $line['name'] : XeroPayloadReader::text( $item['Name'] ?? $sku );
		} else {
			if ( '' !== $sku && 'free_text' !== $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) ) {
				return XeroErrors::unknownProduct( $sku );
			}
			$account = $this->account( 'sales' );
			if ( '' === $account ) {
				return $this->needsAccount( 'sales_account' );
			}
			$row['AccountCode'] = $account;
			$name               = '' !== (string) $line['name'] ? (string) $line['name'] : $sku;
		}
		$row['Description'] = XeroPayloadReader::limit( $name, 4000, $cut );
		if ( null !== $line['tax'] ) {
			$row['TaxType'] = '' === $line['tax'] ? $this->options->text( 'no_tax_type', 'NONE' ) : (string) $line['tax'];
		}
		if ( (float) $line['discount'] > 0.0 ) {
			$row['DiscountRate'] = (float) $line['discount'];
		}
		return $row;
	}

	/**
	 * One shipping, fee or discount line.
	 *
	 * @param array<string,mixed> $extra    Extra line from the payload.
	 * @param string              $currency Currency.
	 * @param bool                $cut      Set to true when a description was shortened.
	 * @return array<string,mixed>|ConnectorError
	 */
	private function extraLine( array $extra, string $currency, bool &$cut ): array|ConnectorError {
		$kind    = (string) $extra['kind'];
		$account = $this->account( $kind );
		if ( '' === $account ) {
			return $this->needsAccount( 'discount' === $kind || 'fee' === $kind || 'shipping' === $kind ? $kind . '_account' : 'sales_account' );
		}
		$row = array(
			'Description' => XeroPayloadReader::limit( (string) $extra['name'], 4000, $cut ),
			'Quantity'    => 1.0,
			'UnitAmount'  => $this->amounts->number( (int) $extra['price_minor'], $currency ),
			'AccountCode' => $account,
		);
		if ( null !== $extra['tax'] ) {
			$row['TaxType'] = '' === $extra['tax'] ? $this->options->text( 'no_tax_type', 'NONE' ) : (string) $extra['tax'];
		}
		return $row;
	}

	/**
	 * The account code for a kind of line (shipping, fee, discount, sales).
	 *
	 * @param string $kind Kind.
	 */
	private function account( string $kind ): string {
		$specific = in_array( $kind, array( 'shipping', 'fee', 'discount' ), true ) ? $this->options->text( $kind . '_account' ) : '';
		return '' !== $specific ? $specific : $this->options->text( 'sales_account' );
	}

	/**
	 * The failure for a missing account option.
	 *
	 * @param string $option Option name.
	 */
	private function needsAccount( string $option ): ConnectorError {
		return XeroErrors::refused(
			$option,
			sprintf(
				/* translators: %s: the name of a connection option. */
				__( 'Set the account code in the connection option "%s": Xero needs an account for lines that are not a Xero item.', 'estasoft-erp-crm-sync-engine' ),
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
