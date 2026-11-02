<?php
/**
 * Receivables invoices: duplicate search, create and checks.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\OrderData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * An order becomes one invoice in Oracle Fusion Receivables, billed to one configured customer account. The shop reference `WC-{id}` is
 * written to `PurchaseOrder` (or `CrossReference`) and searched for before every create; the shop's total is the last line of `Comments`, so
 * a later pass can tell that an invoice was made with a wrong total even though the engine links what findExisting() returns without
 * calling send(). Attribute names that could not be read on an Oracle page are listed in docs/connectors/oracle-fusion.md.
 */
final class OracleFusionOrders {

	public const RESOURCE = 'receivablesInvoices';

	/**
	 * Longest `Comments`.
	 */
	public const COMMENTS_LENGTH = 1760;

	/**
	 * Longest line description.
	 */
	public const DESCRIPTION_LENGTH = 240;

	/**
	 * Attributes that may hold the invoice total, in the order they are tried.
	 */
	private const TOTAL_ATTRIBUTE = 'TotalAmount';

	/**
	 * Attribute that holds the open amount (equal to the total only while nothing was paid or credited).
	 */
	private const BALANCE_ATTRIBUTE = 'InvoiceBalanceAmount';

	/**
	 * Set up with what it depends on.
	 *
	 * @param OracleFusionGateway $gateway Calls.
	 * @param OracleFusionItems   $items   Items.
	 * @param OracleFusionOptions $options Options.
	 * @param Amounts             $amounts Conversion helper.
	 * @param ShopTotalMarker     $marker  Shop total marker.
	 * @param string              $system  System name for messages.
	 */
	public function __construct(
		private readonly OracleFusionGateway $gateway,
		private readonly OracleFusionItems $items,
		private readonly OracleFusionOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system
	) {
	}

	/**
	 * The invoice with a client reference (the oldest when several have it).
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		return $this->findIn( self::RESOURCE, $reference );
	}

	/**
	 * A record of a resource with a client reference, in the business unit.
	 *
	 * @param string $collection  Resource name.
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findIn( string $collection, string $reference ): ?array {
		$rows = $this->gateway->list(
			$collection,
			array(
				'q'       => $this->filter( $reference ),
				'orderBy' => 'CustomerTransactionId:asc',
			),
			2
		);
		return $rows[0] ?? null;
	}

	/**
	 * The `q` filter for one reference in the business unit.
	 *
	 * @param string $reference WC-{id}.
	 */
	public function filter( string $reference ): string {
		return $this->options->referenceAttribute() . ' = ' . $this->gateway->literal( $reference ) . ' and BusinessUnit = ' . $this->gateway->literal( $this->options->businessUnit() );
	}

	/**
	 * Create the invoice.
	 *
	 * @param OrderData $data     Checked payload.
	 * @param Warnings  $warnings Receives notes.
	 * @return array<string,mixed> The new invoice.
	 *
	 * @throws ApiException With SYNC-VAL-002 for a payload Fusion cannot take, SYNC-MAP-001 for an unknown item; on any request failure.
	 */
	public function create( OrderData $data, Warnings $warnings ): array {
		$billTo = $this->billTo( $data );
		$this->requireSettings( $billTo );
		if ( strlen( $data->reference ) > $this->options->referenceLength() ) {
			$this->refuse(
				$this->options->referenceAttribute(),
				sprintf(
					/* translators: %d: number of characters. */
					__( 'The order reference is longer than the %d characters Oracle Fusion takes in this field.', 'estasoft-erp-crm-sync-engine' ),
					$this->options->referenceLength()
				)
			);
		}
		if ( 'fail' === $this->options->unknownSku() ) {
			$this->assertItemsExist( $data );
		}
		$created = $this->gateway->create( self::RESOURCE, $this->body( $data, $billTo, $warnings ) );
		if ( ! isset( $created['CustomerTransactionId'] ) ) {
			$created = $this->find( $data->reference );
		}
		if ( null === $created ) {
			$this->refuse( 'CustomerTransactionId', __( 'Oracle Fusion accepted the invoice but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $created;
	}

	/**
	 * The invoice read again when the answer to the create did not show its total.
	 *
	 * @param array<string,mixed> $invoice Invoice as created.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function settled( array $invoice ): array {
		if ( null !== $this->totalOf( $invoice, true ) || ! isset( $invoice['CustomerTransactionId'] ) ) {
			return $invoice;
		}
		return ( $this->gateway->one( self::RESOURCE, (string) $invoice['CustomerTransactionId'] ) ?? array() ) + $invoice;
	}

	/**
	 * An invoice that is not complete needs a person.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 */
	public function unfinished( array $invoice ): ?ConnectorError {
		if ( 'incomplete' !== strtolower( (string) ( $invoice['InvoiceStatus'] ?? '' ) ) ) {
			return null;
		}
		return Problem::validation(
			$this->system,
			'InvoiceStatus',
			__( 'The transaction is in Oracle Fusion but its status is Incomplete. Complete or delete it in Oracle Fusion Receivables, then try again.', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * Compare the invoice with what was sent: currency, and the total against the shop's.
	 *
	 * @param array<string,mixed> $invoice  Invoice from Fusion.
	 * @param OrderData           $data     Payload.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 * @param bool                $fresh    Whether the invoice was created a moment ago (its open amount then equals its total).
	 */
	public function check( array $invoice, OrderData $data, TotalCheck $total, Warnings $warnings, bool $fresh ): ?ConnectorError {
		$actual = strtoupper( (string) ( $invoice['InvoiceCurrencyCode'] ?? '' ) );
		if ( '' !== $actual && $data->currency !== $actual ) {
			return Problem::validation(
				$this->system,
				'InvoiceCurrencyCode',
				sprintf(
					/* translators: 1: currency in Fusion, 2: currency of the shop. */
					__( 'Oracle Fusion holds the invoice in %1$s but the shop charged in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$data->currency
				)
			);
		}
		$figure = $this->totalOf( $invoice, $fresh );
		$error  = $total->run( $data->totalMinor, null === $figure ? null : $figure['minor'], $data->currency, $warnings );
		if ( null === $error ) {
			return null;
		}
		return Problem::validation( $this->system, 'total', (string) $error->context['reason'] . ' ' . __( 'The invoice stays in Oracle Fusion; correct or delete it there.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The check findExisting() can make without a payload: the invoice total against the shop total written in its comments.
	 *
	 * @param array<string,mixed> $invoice Invoice from Fusion.
	 * @param TotalCheck          $total   Total check.
	 */
	public function checkMarked( array $invoice, TotalCheck $total ): ?ConnectorError {
		$unfinished = $this->unfinished( $invoice );
		if ( null !== $unfinished ) {
			return $unfinished;
		}
		$shop   = $this->marker->read( (string) ( $invoice['Comments'] ?? '' ) );
		$figure = $this->totalOf( $invoice, false );
		if ( null === $shop || null === $figure ) {
			return null;
		}
		$error = $total->run( $shop['minor'], $figure['minor'], $shop['currency'], new Warnings(), 'total' );
		return null === $error ? null : Problem::validation( $this->system, 'total', (string) $error->context['reason'] . ' ' . __( 'The invoice stays in Oracle Fusion; correct or delete it there.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The total of an invoice in minor units, when Fusion shows one.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 * @param bool                $fresh   Whether the open amount may stand in for a missing total.
	 * @return array{minor:int,attribute:string}|null
	 */
	public function totalOf( array $invoice, bool $fresh ): ?array {
		$currency = strtoupper( (string) ( $invoice['InvoiceCurrencyCode'] ?? '' ) );
		if ( '' === $currency ) {
			return null;
		}
		$attributes = $fresh ? array( self::TOTAL_ATTRIBUTE, self::BALANCE_ATTRIBUTE ) : array( self::TOTAL_ATTRIBUTE );
		foreach ( $attributes as $attribute ) {
			if ( isset( $invoice[ $attribute ] ) && ( is_int( $invoice[ $attribute ] ) || is_float( $invoice[ $attribute ] ) || is_string( $invoice[ $attribute ] ) ) ) {
				$minor = $this->amounts->fromWire( $invoice[ $attribute ], $currency );
				if ( null !== $minor ) {
					return array(
						'minor'     => $minor,
						'attribute' => $attribute,
					);
				}
			}
		}
		return null;
	}

	/**
	 * The reference the engine stores for an invoice.
	 *
	 * @param array<string,mixed> $invoice  Invoice from Fusion.
	 * @param bool                $fresh    Whether the invoice was created a moment ago.
	 * @param array<string,mixed> $extra    More facts.
	 */
	public function reference( array $invoice, bool $fresh, array $extra = array() ): ExternalRef {
		$figure = $this->totalOf( $invoice, $fresh );
		$facts  = array( 'currency' => strtoupper( (string) ( $invoice['InvoiceCurrencyCode'] ?? '' ) ) );
		if ( null !== $figure ) {
			$facts['total_minor'] = abs( $figure['minor'] );
		}
		return new ExternalRef(
			(string) ( $invoice['CustomerTransactionId'] ?? '' ),
			(string) ( $invoice['TransactionNumber'] ?? ( $invoice['CustomerTransactionId'] ?? '' ) ),
			null,
			$facts + $extra
		);
	}

	/**
	 * The customer account the invoice is billed to: the mapping's, else the connection setting.
	 *
	 * @param OrderData $data Payload.
	 */
	public function billTo( OrderData $data ): string {
		return '' !== $data->rawText( 'bill_to_customer_number' ) ? $data->rawText( 'bill_to_customer_number' ) : $this->options->billToAccount();
	}

	/**
	 * Stop with SYNC-VAL-002 for every setting an invoice cannot be made without.
	 *
	 * @param string $billTo Customer account number.
	 *
	 * @throws ApiException With SYNC-VAL-002 when one is missing.
	 */
	public function requireSettings( string $billTo ): void {
		$this->requireUnit();
		$this->mustHave( 'transaction_source', $this->options->transactionSource(), __( 'Enter the transaction source in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		$this->mustHave( 'transaction_type', $this->options->transactionType(), __( 'Enter the transaction type in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		$this->mustHave( 'bill_to_account', $billTo, __( 'Enter the number of the customer account all web orders are billed to in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * Stop with SYNC-VAL-002 when the business unit is not set (every search is made in it).
	 *
	 * @throws ApiException With SYNC-VAL-002 when it is missing.
	 */
	public function requireUnit(): void {
		$this->mustHave( 'business_unit', $this->options->businessUnit(), __( 'Enter the business unit in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The request body.
	 *
	 * @param OrderData $data     Payload.
	 * @param string    $billTo   Customer account number.
	 * @param Warnings  $warnings Receives notes.
	 * @return array<string,mixed>
	 */
	private function body( OrderData $data, string $billTo, Warnings $warnings ): array {
		$body = array(
			'BusinessUnit'                       => $this->options->businessUnit(),
			'TransactionSource'                  => $this->options->transactionSource(),
			'TransactionType'                    => $this->options->transactionType(),
			'BillToCustomerNumber'               => $billTo,
			'InvoiceCurrencyCode'                => $data->currency,
			$this->options->referenceAttribute() => $data->reference,
		);
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $data->orderDate, $match ) ) {
			$body['TransactionDate'] = $match[1];
		}
		if ( '' !== $this->options->paymentTerms() ) {
			$body['PaymentTerms'] = $this->options->paymentTerms();
		}
		$freight = 0;
		foreach ( $data->chargesOf( 'shipping' ) as $charge ) {
			$freight += $charge->amountMinor;
		}
		if ( 0 !== $freight ) {
			$body['FreightAmount'] = $this->amounts->toNumber( $freight, $data->currency );
		}
		$note = $data->noteWithPayment( __( 'Payment', 'estasoft-erp-crm-sync-engine' ) );
		$last = $this->marker->append( '', $data->totalMinor, $data->currency );
		$room = self::COMMENTS_LENGTH - ( '' === $last ? 0 : strlen( $last ) + 1 );
		$text = $this->marker->append( $warnings->fit( 'Comments', $note, $room ), $data->totalMinor, $data->currency );
		if ( '' !== $text ) {
			$body['Comments'] = $text;
		}
		$body['receivablesInvoiceLines'] = $this->lines( $data, $warnings );
		return $body;
	}

	/**
	 * The invoice lines: one per product, one per fee, one negative line for a discount.
	 *
	 * @param OrderData $data     Payload.
	 * @param Warnings  $warnings Receives notes.
	 * @return array<int,array<string,mixed>>
	 */
	private function lines( OrderData $data, Warnings $warnings ): array {
		$lines  = array();
		$number = 0;
		foreach ( $data->lines as $line ) {
			$text    = '' === $line->sku ? $line->name : '[' . $line->sku . '] ' . $line->name;
			$row     = array(
				'LineNumber'       => ++$number,
				'Description'      => $warnings->fit( 'Description', trim( $text ), self::DESCRIPTION_LENGTH ),
				'Quantity'         => $this->number( $line->quantity ),
				'UnitSellingPrice' => $this->amounts->toNumber( $line->priceMinor, $data->currency ),
			);
			$lines[] = $this->decorated( $row, $line->taxCode() );
		}
		foreach ( $data->charges as $charge ) {
			if ( 'shipping' === $charge->kind ) {
				continue;
			}
			$row     = array(
				'LineNumber'       => ++$number,
				'Description'      => $warnings->fit( 'Description', $charge->name, self::DESCRIPTION_LENGTH ),
				'Quantity'         => 1,
				'UnitSellingPrice' => $this->amounts->toNumber( $charge->amountMinor, $data->currency ),
			);
			$lines[] = $this->decorated( $row, $charge->taxCode() );
		}
		return $lines;
	}

	/**
	 * A line with the tax classification and the memo line when they are set.
	 *
	 * @param array<string,mixed> $row     Line.
	 * @param string              $taxCode Tax classification code from the tax mapping, or empty.
	 * @return array<string,mixed>
	 */
	private function decorated( array $row, string $taxCode ): array {
		if ( '' !== $taxCode ) {
			$row['TaxClassificationCode'] = $taxCode;
		}
		if ( '' !== $this->options->memoLine() ) {
			$row['MemoLine'] = $this->options->memoLine();
		}
		return $row;
	}

	/**
	 * A quantity as a JSON number.
	 *
	 * @param string $quantity Decimal text.
	 */
	private function number( string $quantity ): int|float {
		return str_contains( $quantity, '.' ) ? (float) $quantity : (int) $quantity;
	}

	/**
	 * Stop with SYNC-MAP-001 for the first item number Fusion does not know.
	 *
	 * @param OrderData $data Payload.
	 *
	 * @throws ApiException With SYNC-MAP-001.
	 */
	private function assertItemsExist( OrderData $data ): void {
		foreach ( array_values( array_unique( array_map( static fn( $line ): string => $line->sku, $data->lines ) ) ) as $sku ) {
			if ( '' !== $sku && null === $this->items->product( $sku ) ) {
				$problem = Problem::unknownSku( $this->system, $sku );
				throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
		}
	}

	/**
	 * Refuse a setting that is missing.
	 *
	 * @param string $field  Setting name.
	 * @param string $value  Value.
	 * @param string $reason Why it is needed.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the value is empty.
	 */
	private function mustHave( string $field, string $value, string $reason ): void {
		if ( '' === $value ) {
			$this->refuse( $field, $reason );
		}
	}

	/**
	 * Stop with SYNC-VAL-002.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 *
	 * @throws ApiException Always.
	 */
	private function refuse( string $field, string $reason ): never {
		$problem = Problem::validation( $this->system, $field, $reason );
		throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}
}
