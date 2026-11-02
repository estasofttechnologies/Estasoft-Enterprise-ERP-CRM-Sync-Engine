<?php
/**
 * Sales orders and A/R invoices in SAP Business One.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Charge;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\OrderData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The client reference WC-{order id} is written to NumAtCard (and to a user field when the merchant named one) and searched before every
 * create. An order whose total or currency is wrong is left in SAP for a person, with the shop's total as the last line of its remarks, so
 * that every later pass reports the same error.
 */
final class SapBusinessOneOrders {

	/**
	 * Properties read from a document.
	 */
	public const FIELDS = 'DocEntry,DocNum,NumAtCard,DocTotal,DocTotalFc,DocCurrency,DocDate,DocumentStatus,Comments,CardCode,PaidToDate';

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapBusinessOneGateway  $gateway  Calls.
	 * @param SapBusinessOnePartners $partners Customers.
	 * @param SapBusinessOneOptions  $options  Options.
	 * @param Amounts                $amounts  Conversion helper.
	 * @param ShopTotalMarker        $marker   Shop total in the remarks.
	 * @param string                 $system   System name for messages.
	 */
	public function __construct(
		private readonly SapBusinessOneGateway $gateway,
		private readonly SapBusinessOnePartners $partners,
		private readonly SapBusinessOneOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system
	) {
	}

	/**
	 * The open or closed (not cancelled) order with a client reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		return $this->byReference( 'Orders', $this->options->referenceField(), $reference );
	}

	/**
	 * The A/R invoice with a client reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoiceOf( string $reference ): ?array {
		return $this->byReference( 'Invoices', 'NumAtCard', $reference );
	}

	/**
	 * Create the order (customer first) and return the document SAP answers with.
	 *
	 * @param OrderData $data     Checked payload.
	 * @param Warnings  $warnings Receives notes about cut text.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure and for a payload SAP cannot take.
	 */
	public function create( OrderData $data, Warnings $warnings ): array {
		$lines = $this->lines( $data );
		$this->assertItemsExist( $lines );
		$customer = $this->partners->ensure( $data->partner, $data->delivery, $warnings );
		$body     = array(
			'CardCode'      => $customer['id'],
			'NumAtCard'     => $data->reference,
			'DocCurrency'   => $data->currency,
			'Comments'      => $this->marker->append( $warnings->fit( 'Comments', $data->noteWithPayment( 'Payment reference:' ), 215 ), $data->totalMinor, $data->currency ),
			'DocumentLines' => $lines,
		);
		if ( '' !== $data->orderDate ) {
			$body['DocDate']    = $data->orderDate;
			$body['TaxDate']    = $data->orderDate;
			$body['DocDueDate'] = gmdate( 'Y-m-d', (int) strtotime( $data->orderDate . ' +' . $this->options->dueDays() . ' days UTC' ) );
		}
		if ( 'NumAtCard' !== $this->options->referenceField() ) {
			$body[ $this->options->referenceField() ] = $data->reference;
		}
		if ( array() !== $data->delivery ) {
			$body['Address2'] = $warnings->fit( 'Address2', $this->addressText( $data->delivery ), 254 );
		}
		$created = $this->gateway->create( 'Orders', $body );
		if ( ! isset( $created['DocEntry'] ) ) {
			$created = $this->find( $data->reference );
		}
		if ( null === $created ) {
			$problem = Problem::validation( $this->system, 'Orders', __( 'SAP accepted the order but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $created;
	}

	/**
	 * Make the invoice of an order when there is none (it copies the order's lines) and return it.
	 *
	 * @param array<string,mixed> $order Order document.
	 * @param string              $date  Order date (Y-m-d), or empty.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure; the copy is made once and a lost answer is found by the read on the next pass.
	 */
	public function ensureInvoice( array $order, string $date ): array {
		$reference = (string) ( $order['NumAtCard'] ?? '' );
		$invoice   = $this->invoiceOf( $reference );
		if ( null !== $invoice ) {
			return $invoice;
		}
		$entry = (string) ( $order['DocEntry'] ?? '' );
		$full  = $this->gateway->one( 'Orders', $entry, array( 'select' => 'DocumentLines' ) );
		$lines = array();
		foreach ( (array) ( $full['DocumentLines'] ?? array() ) as $position => $line ) {
			$lines[] = array(
				'BaseType'  => 17,
				'BaseEntry' => (int) $entry,
				'BaseLine'  => is_array( $line ) && isset( $line['LineNum'] ) ? (int) $line['LineNum'] : $position,
			);
		}
		$body = array(
			'CardCode'      => (string) ( $order['CardCode'] ?? '' ),
			'NumAtCard'     => $reference,
			'DocCurrency'   => (string) ( $order['DocCurrency'] ?? '' ),
			'Comments'      => (string) ( $order['Comments'] ?? '' ),
			'DocumentLines' => $lines,
		);
		if ( '' !== $date ) {
			$body['DocDate'] = $date;
			$body['TaxDate'] = $date;
		}
		$created = $this->gateway->create( 'Invoices', $body );
		return isset( $created['DocEntry'] ) ? $created : ( $this->invoiceOf( $reference ) ?? $created );
	}

	/**
	 * Compare the document SAP holds with what the shop charged.
	 *
	 * @param array<string,mixed> $document Order, invoice or credit note.
	 * @param string              $currency ISO code the shop used.
	 * @param int|null            $expected What the shop charged, or null to skip the total.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 */
	public function check( array $document, string $currency, ?int $expected, TotalCheck $total, Warnings $warnings ): ?ConnectorError {
		$actual = strtoupper( (string) ( $document['DocCurrency'] ?? '' ) );
		$wanted = strtoupper( $currency );
		if ( '' !== $actual && $wanted !== $actual ) {
			return Problem::validation(
				$this->system,
				'DocCurrency',
				sprintf(
					/* translators: 1: currency in SAP, 2: currency of the shop. */
					__( 'SAP holds the document in %1$s but the shop charged in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$currency
				)
			);
		}
		return $total->run( $expected, $this->totalOf( $document, $currency ), $currency, $warnings );
	}

	/**
	 * Check a document found without the payload against the shop total in its remarks.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param TotalCheck          $total    Total check.
	 */
	public function checkMarked( array $document, TotalCheck $total ): ?ConnectorError {
		$marked = $this->marker->read( (string) ( $document['Comments'] ?? '' ) );
		return null === $marked ? null : $this->check( $document, $marked['currency'], $marked['minor'], $total, new Warnings() );
	}

	/**
	 * The total of a document in minor units: the foreign currency total when SAP gives one, else the local total.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $currency ISO code.
	 */
	public function totalOf( array $document, string $currency ): ?int {
		$foreign = $this->amounts->fromWire( $document['DocTotalFc'] ?? null, $currency );
		if ( null !== $foreign && 0 !== $foreign ) {
			return $foreign;
		}
		return $this->amounts->fromWire( $document['DocTotal'] ?? null, $currency );
	}

	/**
	 * The currency code of a document: the remarks' marker, else DocCurrency.
	 *
	 * @param array<string,mixed> $document Document.
	 */
	public function currencyOf( array $document ): string {
		$marked = $this->marker->read( (string) ( $document['Comments'] ?? '' ) );
		return null !== $marked ? $marked['currency'] : strtoupper( (string) ( $document['DocCurrency'] ?? '' ) );
	}

	/**
	 * The reference the engine stores for a document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $currency ISO code.
	 * @param array<string,mixed> $extra    More facts.
	 */
	public function reference( array $document, string $currency, array $extra = array() ): ExternalRef {
		return new ExternalRef(
			(string) ( $document['DocEntry'] ?? '' ),
			(string) ( $document['DocNum'] ?? '' ),
			null,
			array(
				'total_minor' => abs( (int) $this->totalOf( $document, $currency ) ),
				'currency'    => $currency,
			) + $extra
		);
	}

	/**
	 * Refuse the order before anything is created when an item code is not in SAP (SYNC-MAP-001).
	 *
	 * @param array<int,array<string,mixed>> $lines Document lines.
	 *
	 * @throws ApiException With SYNC-MAP-001 for the first unknown item.
	 */
	private function assertItemsExist( array $lines ): void {
		$codes = array_values( array_unique( array_map( static fn( array $line ): string => (string) $line['ItemCode'], $lines ) ) );
		foreach ( array_chunk( $codes, 20 ) as $chunk ) {
			$filter = implode( ' or ', array_map( fn( string $code ): string => 'ItemCode eq ' . $this->gateway->literal( $code ), $chunk ) );
			$found  = array();
			foreach ( $this->gateway->list(
				'Items',
				array(
					'filter' => "({$filter}) and Frozen eq 'tNO'",
					'select' => 'ItemCode',
				),
				40
			) as $row ) {
				$found[] = strtolower( (string) ( $row['ItemCode'] ?? '' ) );
			}
			foreach ( $chunk as $code ) {
				if ( ! in_array( strtolower( $code ), $found, true ) ) {
					$problem = Problem::unknownSku( $this->system, $code );
					throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
				}
			}
		}
	}

	/**
	 * A document of an entity with a client reference.
	 *
	 * @param string $entity    Orders or Invoices.
	 * @param string $field     Property that holds the reference.
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	private function byReference( string $entity, string $field, string $reference ): ?array {
		$rows = $this->gateway->list(
			$entity,
			array(
				'filter'  => $field . ' eq ' . $this->gateway->literal( $reference ) . " and Cancelled eq 'tNO'",
				'select'  => self::FIELDS,
				'orderby' => 'DocEntry',
			),
			1
		);
		return $rows[0] ?? null;
	}

	/**
	 * Document lines for products, shipping, fees and discounts.
	 *
	 * @param OrderData $data Checked payload.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When the item for shipping, a fee or a discount is not set.
	 */
	private function lines( OrderData $data ): array {
		$lines = array();
		foreach ( $data->lines as $line ) {
			$priceKey = $data->pricesIncludeTax ? 'PriceAfterVAT' : 'UnitPrice';
			$entry    = array(
				'ItemCode' => $line->sku,
				'Quantity' => false === strpos( $line->quantity, '.' ) ? (int) $line->quantity : (float) $line->quantity,
				$priceKey  => $this->amounts->toNumber( $line->priceMinor, $data->currency ),
			);
			if ( '' !== $line->taxCode() ) {
				$entry[ $this->options->taxField() ] = $line->taxCode();
			}
			if ( '' !== $this->options->warehouse() ) {
				$entry['WarehouseCode'] = $this->options->warehouse();
			}
			$lines[] = $entry;
		}
		foreach ( $data->charges as $charge ) {
			$lines[] = $this->chargeLine( $charge, $data );
		}
		return $lines;
	}

	/**
	 * A line for a shipping, fee or discount amount.
	 *
	 * @param Charge    $charge Charge.
	 * @param OrderData $data   Payload.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When the item is not set.
	 */
	private function chargeLine( Charge $charge, OrderData $data ): array {
		$option = match ( $charge->kind ) {
			'shipping' => array( 'shipping_item', $this->options->shippingItem(), __( 'shipping', 'estasoft-erp-crm-sync-engine' ) ),
			'fee'      => array( 'fee_item', $this->options->feeItem(), __( 'fee', 'estasoft-erp-crm-sync-engine' ) ),
			default    => array( 'discount_item', $this->options->discountItem(), __( 'discount', 'estasoft-erp-crm-sync-engine' ) ),
		};
		if ( '' === $option[1] ) {
			$problem = Problem::validation(
				$this->system,
				$option[0],
				sprintf(
					/* translators: %s: shipping, fee or discount. */
					__( 'The order has a %s amount but the connection has no item for it. Enter the item code in the connection options.', 'estasoft-erp-crm-sync-engine' ),
					$option[2]
				)
			);
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$line = array(
			'ItemCode'        => $option[1],
			'Quantity'        => 1,
			'UnitPrice'       => $this->amounts->toNumber( $charge->amountMinor, $data->currency ),
			'ItemDescription' => $charge->name,
		);
		if ( '' !== $charge->taxCode() ) {
			$line[ $this->options->taxField() ] = $charge->taxCode();
		}
		return $line;
	}

	/**
	 * A delivery address as one line of text.
	 *
	 * @param array<string,string> $address Address fields.
	 */
	private function addressText( array $address ): string {
		$town = trim( ( $address['city'] ?? '' ) . ' ' . ( $address['zip'] ?? '' ) );
		return implode( ', ', array_filter( array( $address['name'] ?? '', $address['street'] ?? '', $town, $address['country'] ?? '' ), static fn( string $part ): bool => '' !== $part ) );
	}
}
