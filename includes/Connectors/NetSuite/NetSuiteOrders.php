<?php
/**
 * Sales orders and their invoices in NetSuite.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

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
 * The client reference WC-{order id} is the external id of the sales order, so the documented upsert (PUT .../eid:WC-1) cannot make a
 * second order, and a read by external id tells whether one exists. Nothing is deleted or cancelled here: an order whose total or currency
 * is wrong stays in NetSuite for a person, and the shop's total written in its memo makes every later pass report the same error.
 */
final class NetSuiteOrders {

	/**
	 * Fields read back from a sales order.
	 *
	 * @var array<int,string>
	 */
	private const FIELDS = array( 'id', 'tranId', 'total', 'currency', 'tranDate', 'memo', 'externalId' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param NetSuiteGateway   $gateway   Calls.
	 * @param NetSuiteLookups   $lookups   Reads.
	 * @param NetSuiteCustomers $customers Customers.
	 * @param NetSuiteOptions   $options   Options.
	 * @param Amounts           $amounts   Conversion helper.
	 * @param ShopTotalMarker   $marker    Shop total in the memo.
	 * @param string            $system    System name for messages.
	 * @param string            $host      Host of the account (for links).
	 */
	public function __construct(
		private readonly NetSuiteGateway $gateway,
		private readonly NetSuiteLookups $lookups,
		private readonly NetSuiteCustomers $customers,
		private readonly NetSuiteOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system,
		private readonly string $host
	) {
	}

	/**
	 * The sales order with a client reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null Null when none exists.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		$record = $this->gateway->byExternalId( 'salesOrder', $reference, self::FIELDS );
		return null === $record || ! isset( $record['id'] ) ? null : $record;
	}

	/**
	 * Create the order (customer first) and read it back.
	 *
	 * @param OrderData $data     Checked payload.
	 * @param Warnings  $warnings Receives notes about cut text.
	 * @return array<string,mixed> The new record.
	 *
	 * @throws ApiException On failure and for a payload NetSuite cannot take.
	 */
	public function create( OrderData $data, Warnings $warnings ): array {
		$currencyId = $this->lookups->currencyId( $data->currency );
		if ( null === $currencyId ) {
			$problem = Problem::validation(
				$this->system,
				'currency',
				sprintf(
						/* translators: %s: ISO currency code. */
					__( 'NetSuite has no active currency %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$data->currency
				)
			);
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$body           = $this->body( $data, $currencyId, $warnings );
		$customer       = $this->customers->ensure( $data->partner, $currencyId, $warnings );
		$body['entity'] = array( 'id' => $customer['id'] );
		$this->gateway->write( 'PUT', 'record/v1/salesOrder/eid:' . rawurlencode( $data->reference ), $body );
		$record = $this->find( $data->reference );
		if ( null === $record ) {
			$problem = Problem::validation( $this->system, 'salesOrder', __( 'NetSuite accepted the order but it cannot be read back.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $record;
	}

	/**
	 * Compare the order NetSuite holds with what the shop charged.
	 *
	 * @param array<string,mixed> $record   Sales order or invoice read back.
	 * @param string              $currency ISO code the shop used.
	 * @param int|null            $expected What the shop charged, or null to skip the total.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 * @return ConnectorError|null
	 *
	 * @throws ApiException On failure of the currency read.
	 */
	public function check( array $record, string $currency, ?int $expected, TotalCheck $total, Warnings $warnings ): ?ConnectorError {
		$wanted = $this->lookups->currencyId( $currency );
		$actual = $this->currencyIdOf( $record );
		if ( null !== $wanted && '' !== $actual && $wanted !== $actual ) {
			return Problem::validation(
				$this->system,
				'currency',
				sprintf(
					/* translators: %s: ISO currency code. */
					__( 'NetSuite holds the order in another currency than the shop (%s).', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$currency
				)
			);
		}
		return $total->run( $expected, $this->amounts->fromWire( $record['total'] ?? null, $currency ), $currency, $warnings );
	}

	/**
	 * Check an order found without the payload, against the shop total in its memo.
	 *
	 * @param array<string,mixed> $record Sales order read back.
	 * @param TotalCheck          $total  Total check.
	 * @return ConnectorError|null
	 *
	 * @throws ApiException On failure of the currency read.
	 */
	public function checkMarked( array $record, TotalCheck $total ): ?ConnectorError {
		$marked = $this->marker->read( (string) ( $record['memo'] ?? '' ) );
		if ( null === $marked ) {
			return null;
		}
		return $this->check( $record, $marked['currency'], $marked['minor'], $total, new Warnings() );
	}

	/**
	 * The invoice made from a sales order.
	 *
	 * @param string $orderId Internal id of the sales order.
	 * @return array<string,mixed>|null The newest invoice row (id, tranid, status), or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function invoiceOf( string $orderId ): ?array {
		$rows = $this->gateway->rows( "SELECT id, tranid, status FROM transaction WHERE type = 'CustInvc' AND createdfrom = ? ORDER BY id DESC", array( $orderId ), 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Make the invoice of a sales order when there is none, and read it back.
	 *
	 * @param string $orderId Internal id of the sales order.
	 * @param string $date    Order date (Y-m-d) for the invoice, or empty.
	 * @return array<string,mixed> Invoice record (id, tranId, total, currency).
	 *
	 * @throws ApiException On failure; the transform is run once and a lost answer is found by the read on the next pass.
	 */
	public function ensureInvoice( string $orderId, string $date ): array {
		$row = $this->invoiceOf( $orderId );
		if ( null === $row ) {
			$this->gateway->write( 'POST', 'record/v1/salesOrder/' . rawurlencode( $orderId ) . '/!transform/invoice', '' === $date ? array() : array( 'tranDate' => $date ) );
			$row = $this->invoiceOf( $orderId );
		}
		if ( null === $row || ! isset( $row['id'] ) ) {
			$problem = Problem::validation( $this->system, 'invoice', __( 'NetSuite did not make the invoice. The sales order may need approval or fulfilment first.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$record = $this->gateway->record( 'invoice', (string) $row['id'], array( 'id', 'tranId', 'total', 'currency' ) );
		return null === $record ? $row : $record;
	}

	/**
	 * The reference the engine stores for a sales order.
	 *
	 * @param array<string,mixed> $record   Sales order read back.
	 * @param string              $currency ISO code.
	 * @param array<string,mixed> $extra    More facts.
	 *
	 * @throws ApiException On failure of the currency read.
	 */
	public function reference( array $record, string $currency, array $extra = array() ): ExternalRef {
		$id    = (string) ( $record['id'] ?? '' );
		$total = $this->amounts->fromWire( $record['total'] ?? null, $currency );
		return new ExternalRef(
			$id,
			(string) ( $record['tranId'] ?? '' ),
			'https://' . $this->host . '.app.netsuite.com/app/accounting/transactions/salesord.nl?id=' . rawurlencode( $id ),
			array(
				'total_minor' => $total ?? 0,
				'currency'    => $currency,
			) + $extra
		);
	}

	/**
	 * The ISO code of a record's currency: the memo marker's, else looked up by id.
	 *
	 * @param array<string,mixed> $record Sales order read back.
	 *
	 * @throws ApiException On failure.
	 */
	public function currencyCodeOf( array $record ): string {
		$marked = $this->marker->read( (string) ( $record['memo'] ?? '' ) );
		if ( null !== $marked ) {
			return $marked['currency'];
		}
		$id = $this->currencyIdOf( $record );
		foreach ( $this->lookups->currencies() as $symbol => $currencyId ) {
			if ( $currencyId === $id ) {
				return $symbol;
			}
		}
		return '';
	}

	/**
	 * The REST body of a sales order (without the customer).
	 *
	 * @param OrderData $data       Checked payload.
	 * @param string    $currencyId Internal id of the currency.
	 * @param Warnings  $warnings   Receives notes about cut text.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException For a payload NetSuite cannot take.
	 */
	private function body( OrderData $data, string $currencyId, Warnings $warnings ): array {
		$items = $this->lines( $data );
		$body  = array(
			'externalId'  => $data->reference,
			'otherRefNum' => $data->reference,
			'currency'    => array( 'id' => $currencyId ),
			'memo'        => $this->marker->append( $warnings->fit( 'memo', $data->noteWithPayment( 'Payment reference:' ), 950 ), $data->totalMinor, $data->currency ),
		);
		if ( '' !== $data->orderDate ) {
			$body['tranDate'] = $data->orderDate;
		}
		$shipping = 0;
		foreach ( $data->charges as $charge ) {
			$line = $this->chargeLine( $charge, $data->currency );
			if ( null === $line ) {
				$shipping += $charge->amountMinor;
				continue;
			}
			$items[] = $line;
		}
		if ( 0 !== $shipping ) {
			$body['shippingCost'] = $this->amounts->toNumber( $shipping, $data->currency );
		}
		$body['item'] = array( 'items' => $items );
		if ( $this->options->sendAddresses() ) {
			$billing = $this->address( $data->partner, true );
			if ( array() !== $billing ) {
				$body['billingAddress'] = $billing;
			}
			$delivery = $this->address( array() !== $data->delivery ? $data->delivery : $data->partner, false );
			if ( array() !== $delivery ) {
				$body['shippingAddress'] = $delivery;
			}
		}
		return $body;
	}

	/**
	 * Product lines.
	 *
	 * @param OrderData $data Checked payload.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When a product is not in NetSuite.
	 */
	private function lines( OrderData $data ): array {
		$items = array();
		foreach ( $data->lines as $line ) {
			$entry = array(
				'item'     => array( 'id' => $this->itemId( $line->sku ) ),
				'quantity' => $this->number( $line->quantity ),
				'rate'     => $this->amounts->toNumber( $line->priceMinor, $data->currency ),
			);
			if ( '' !== $line->name ) {
				$entry['description'] = $line->name;
			}
			$items[] = $entry;
		}
		return $items;
	}

	/**
	 * A line for a shipping, fee or discount amount, or null when shipping goes in the shipping cost field.
	 *
	 * @param Charge $charge   Charge.
	 * @param string $currency ISO code.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException When the item for a fee or discount is not set.
	 */
	private function chargeLine( Charge $charge, string $currency ): ?array {
		$item = match ( $charge->kind ) {
			'shipping' => $this->options->shippingItem(),
			'fee'      => $this->options->feeItem(),
			default    => $this->options->discountItem(),
		};
		if ( '' === $item ) {
			if ( 'shipping' === $charge->kind ) {
				return null;
			}
			$problem = Problem::validation(
				$this->system,
				'fee' === $charge->kind ? 'fee_item' : 'discount_item',
				sprintf(
						/* translators: %s: fee or discount. */
					__( 'The order has a %s but the connection has no item for it. Enter the item id in the connection options.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'fee' === $charge->kind ? __( 'fee', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'discount', 'estasoft-enterprise-erpcrm-sync-engine' )
				)
			);
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return array(
			'item'        => array( 'id' => $item ),
			'quantity'    => 1,
			'rate'        => $this->amounts->toNumber( $charge->amountMinor, $currency ),
			'description' => $charge->name,
		);
	}

	/**
	 * The internal id of an item: a number from the SKU match is taken as the id, any other text is looked up as a SKU.
	 *
	 * @param string $value Value of the line's product field.
	 *
	 * @throws ApiException With SYNC-MAP-001 when no item matches.
	 */
	private function itemId( string $value ): string {
		if ( 1 === preg_match( '/^\d{1,12}$/', $value ) ) {
			return $value;
		}
		$item = $this->lookups->itemBySku( $value );
		if ( null === $item ) {
			$problem = Problem::unknownSku( $this->system, $value );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $item['id'];
	}

	/**
	 * A quantity as a JSON number.
	 *
	 * @param string $quantity Plain decimal text.
	 */
	private function number( string $quantity ): int|float {
		return false === strpos( $quantity, '.' ) ? (int) $quantity : (float) $quantity;
	}

	/**
	 * An address block of the REST body.
	 *
	 * @param array<string,string> $source  Name, street, city, state, zip, country and phone.
	 * @param bool                 $billing Whether it is the billing address (a company name leads).
	 * @return array<string,mixed>
	 */
	private function address( array $source, bool $billing ): array {
		$map     = array(
			'addr1'     => 'street',
			'city'      => 'city',
			'state'     => 'state',
			'zip'       => 'zip',
			'addrPhone' => 'phone',
		);
		$address = array();
		$name    = $billing && '' !== ( $source['company_name'] ?? '' ) ? $source['company_name'] : ( $source['name'] ?? '' );
		if ( '' !== $name ) {
			$address['addressee'] = $name;
		}
		foreach ( $map as $field => $key ) {
			if ( '' !== ( $source[ $key ] ?? '' ) ) {
				$address[ $field ] = $source[ $key ];
			}
		}
		if ( '' !== ( $source['country'] ?? '' ) ) {
			$address['country'] = array( 'id' => $source['country'] );
		}
		return count( $address ) > 1 ? $address : array();
	}

	/**
	 * The id of a record's currency as text, or an empty string.
	 *
	 * @param array<string,mixed> $record Record.
	 */
	private function currencyIdOf( array $record ): string {
		$currency = $record['currency'] ?? null;
		return is_array( $currency ) ? (string) ( $currency['id'] ?? '' ) : '';
	}
}
