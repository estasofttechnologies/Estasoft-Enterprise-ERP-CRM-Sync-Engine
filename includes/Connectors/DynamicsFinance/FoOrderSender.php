<?php
/**
 * Sends an order to Finance and Supply Chain as a sales order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Dates;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;
use Estasoft\SyncEngine\Connectors\Microsoft\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One pass over an order. It starts from a read (is there a sales order of this legal entity whose customer reference is `WC-{id}`?),
 * checks what can be checked without writing (items, the shipping and fee items, the customer group when a customer must be made), then
 * creates the header, the lines one by one, reads the lines back, and compares the total. The header and the lines are separate calls and
 * not one transaction (documented), so a pass cut off anywhere is repeated: lines already there are checked and kept, never added twice.
 * The order name is the marker: `WC-123 (syncing)` while the order is being made, `WC-123 (3 lines)` only after the lines were read back
 * and the total was checked. An order whose total failed the check, or could not be read, keeps the first name, is never linked by
 * findExisting() and is checked again by the next send(). The order is left open; nothing is confirmed or invoiced.
 */
final class FoOrderSender {

	/**
	 * Longest order name (the usual F&O length).
	 */
	private const NAME_MAX = 60;

	/**
	 * Longest sales order number (the F&O sales id is 20 characters).
	 */
	private const NUMBER_MAX = 20;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param FoClient    $fo      Client pinned to the legal entity.
	 * @param FoOptions   $options Options.
	 * @param string      $system  System name.
	 * @param Logger|null $logger  Log.
	 */
	public function __construct( private readonly FoClient $fo, private readonly FoOptions $options, private readonly string $system, private readonly ?Logger $logger = null ) {
	}

	/**
	 * The refusal for a payload that is wrong on its face, found before any request goes out.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param string              $system  System name.
	 */
	public static function precheck( array $payload, string $system ): ?ConnectorError {
		$order = OrderData::parse( $payload, $system );
		return $order instanceof ConnectorError ? $order : null;
	}

	/**
	 * The finished sales order of an item, or null when there is none or it is not finished.
	 *
	 * @param Item $item Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( Item $item ): ?ExternalRef {
		$ref    = $item->clientReference();
		$header = $this->header( $ref );
		if ( null === $header ) {
			return null;
		}
		$count  = self::markerCount( (string) ( $header['SalesOrderName'] ?? '' ), $ref );
		$number = (string) ( $header['SalesOrderNumber'] ?? '' );
		if ( null === $count || '' === $number || count( $this->lines( $number ) ) < $count ) {
			return null;
		}
		return $this->ref( $header, (string) ( $header['CurrencyCode'] ?? '' ) );
	}

	/**
	 * Send an order.
	 *
	 * @param Item                $item    Item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$order = OrderData::parse( $payload, $this->system );
		if ( $order instanceof ConnectorError ) {
			return SendResult::failure( $order );
		}
		$ref      = $item->clientReference();
		$products = ( new FoProducts( $this->fo ) )->resolve( array_map( static fn( $line ): string => $line->sku, $order->lines ) );
		$plan     = $this->plan( $order, $products );
		if ( $plan instanceof ConnectorError ) {
			return SendResult::failure( $plan );
		}
		$date   = FoLine::stamp( 'today' === $this->options->shippingDate() || '' === $order->orderDate ? Dates::today() : $order->orderDate );
		$header = $this->header( $ref );
		$work   = false;
		if ( null === $header ) {
			$customer = ( new FoCustomers( $this->fo, $this->system, $this->logger ) )->resolve( $order, $this->options, $item->entityId );
			if ( $customer instanceof ConnectorError ) {
				return SendResult::failure( $customer );
			}
			try {
				$header = $this->fo->create( 'SalesOrderHeadersV2', $this->headerBody( $order, $ref, (string) ( $customer['row']['CustomerAccount'] ?? '' ), $date ) );
			} catch ( ApiException $exception ) {
				$refusal = $this->numberingRefusal( $exception );
				if ( null === $refusal ) {
					throw $exception;
				}
				return SendResult::failure( $refusal );
			}
			$work = true;
		}
		$number = (string) ( $header['SalesOrderNumber'] ?? '' );
		if ( '' === $number ) {
			return SendResult::failure( Failures::invalid( $this->system, 'SalesOrderNumber', __( 'Finance and Operations did not return the number of the sales order.', 'estasoft-erp-crm-sync-engine' ), 'no order number' ) );
		}
		$came = strtoupper( trim( (string) ( $header['CurrencyCode'] ?? '' ) ) );
		if ( '' !== $came && $came !== $order->currency ) {
			return SendResult::failure( Failures::currencyDiffers( $this->system, $order->currency, $came ) );
		}
		$existing = $this->lines( $number );
		$wrote    = false;
		foreach ( $plan as $index => $line ) {
			if ( isset( $existing[ $index ] ) ) {
				if ( ! $line->matches( $existing[ $index ], $order->currency ) ) {
					return SendResult::failure( $this->changed() );
				}
				continue;
			}
			$this->fo->create( 'SalesOrderLines', $line->body( $number, $order->currency, $date, $this->options->warehouse(), $this->options->site() ) );
			$wrote = true;
		}
		if ( $wrote ) {
			// F&O may price a line from its own agreements: read the lines back and compare them with what was sent.
			$stored = $this->lines( $number );
			foreach ( $plan as $index => $line ) {
				if ( ! isset( $stored[ $index ] ) || ! $line->matches( $stored[ $index ], $order->currency ) ) {
					return SendResult::failure( $this->stored() );
				}
			}
		}
		$work  = $work || $wrote;
		$fresh = $this->header( $ref ) ?? $header;
		$found = isset( $fresh['OrderTotalAmount'] ) ? Amounts::minor( $fresh['OrderTotalAmount'], $order->currency ) : null;
		$check = $this->checkTotal( $found, $order );
		if ( $check['error'] instanceof ConnectorError ) {
			// The order keeps its first name: it is not finished and is not linked on the next try.
			return SendResult::failure( $check['error'] );
		}
		if ( self::markerCount( (string) ( $fresh['SalesOrderName'] ?? '' ), $ref ) !== count( $plan ) ) {
			$this->fo->update( 'SalesOrderHeadersV2', array( 'SalesOrderNumber' => $number ), array( 'SalesOrderName' => self::name( $ref, count( $plan ) ) ) );
			$work = true;
		}
		$result = $this->ref( $fresh, $order->currency, $check['warning'] );
		return $work ? SendResult::created( $result ) : SendResult::existing( $result );
	}

	/**
	 * Compare the total F&O worked out with the shop's. A total F&O does not give (the column may stay empty for an order made over OData) is
	 * not a pass: the order is refused unless the connection accepts differences.
	 *
	 * @param int|null  $found Total in minor units, or null when F&O gave none.
	 * @param OrderData $order Order.
	 * @return array{error:ConnectorError|null,warning:string}
	 */
	private function checkTotal( ?int $found, OrderData $order ): array {
		$expected = $order->expectedMinor();
		$fail     = 'fail' === $this->options->totalMismatch();
		if ( ( null === $found || 0 === $found ) && null !== $expected && 0 !== $expected ) {
			$shop = Amounts::text( $expected, $order->currency ) . ' ' . $order->currency;
			if ( $fail ) {
				return array(
					'error'   => Failures::invalid(
						$this->system,
						'total',
						sprintf(
							/* translators: %s: the shop total with its currency. */
							__( 'Finance and Operations gave no total for this order, so it could not be compared with the shop total of %s and the order was not marked as finished. Check the order there. If this system never fills in the order total for orders made this way, set the option total_mismatch to warn.', 'estasoft-erp-crm-sync-engine' ),
							$shop
						),
						'total unreadable'
					),
					'warning' => '',
				);
			}
			return array(
				'error'   => null,
				'warning' => sprintf(
					/* translators: %s: the shop total with its currency. */
					__( 'Finance and Operations gave no total, so the order could not be compared with the shop total of %s.', 'estasoft-erp-crm-sync-engine' ),
					$shop
				),
			);
		}
		return TotalCheck::compare(
			$found,
			$expected,
			$order->currency,
			$fail,
			$this->system,
			__( 'Check the sales tax groups of the customer and of the items, then delete the order in Finance and Operations and try again.', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * The refusal for an order whose lines were changed after the connector made them.
	 */
	private function changed(): ConnectorError {
		return Failures::invalid( $this->system, 'sales order', __( 'The sales order in Finance and Operations was changed after it was created: its lines are not the ones the shop sent. Delete the order there and try again.', 'estasoft-erp-crm-sync-engine' ), 'lines differ' );
	}

	/**
	 * The refusal for lines that F&O stored differently from what was sent (its own price or quantity rules).
	 */
	private function stored(): ConnectorError {
		return Failures::invalid( $this->system, 'sales order', __( 'Finance and Operations stored the lines differently from what the shop sent (a price or quantity of its own may have replaced it). Check the lines and the price agreements there, delete the order and try again.', 'estasoft-erp-crm-sync-engine' ), 'lines stored differently' );
	}

	/**
	 * The refusal when F&O would not create an order without a sales order number, or null when the failure is something else.
	 *
	 * @param ApiException $exception The failed call.
	 */
	private function numberingRefusal( ApiException $exception ): ?ConnectorError {
		$response = $exception->response();
		if ( 'automatic' !== $this->options->orderNumbering() || 400 !== $exception->status() || null === $response ) {
			return null;
		}
		if ( 1 !== preg_match( '/SalesOrderNumber|sales order number|number sequence/i', MicrosoftErrors::message( $response ) ) ) {
			return null;
		}
		return Failures::invalid(
			$this->system,
			'order_numbering',
			__( 'Finance and Operations would not create the sales order without a sales order number: the number sequence of this legal entity is probably manual. Set the option order_numbering to shop_reference, or make the sequence automatic, then try again.', 'estasoft-erp-crm-sync-engine' ),
			'order number required'
		);
	}

	/**
	 * The order name while the order is being made (see the class description).
	 *
	 * @param string $ref WC-123.
	 */
	public static function pendingName( string $ref ): string {
		return Text::cut( sprintf( '%s (syncing)', $ref ), self::NAME_MAX );
	}

	/**
	 * The text of the order name of a finished order.
	 *
	 * @param string $ref   WC-123.
	 * @param int    $count Number of lines.
	 */
	public static function name( string $ref, int $count ): string {
		return Text::cut( sprintf( '%s (%d lines)', $ref, $count ), self::NAME_MAX );
	}

	/**
	 * The line count the order name carries, or null when it is not the marker of that reference.
	 *
	 * @param string $name Order name.
	 * @param string $ref  WC-123.
	 */
	public static function markerCount( string $name, string $ref ): ?int {
		return 1 === preg_match( '/^' . preg_quote( $ref, '/' ) . ' \((\d+) lines\)$/', trim( $name ), $found ) ? (int) $found[1] : null;
	}

	/**
	 * The order header whose customer reference is the reference. The server filters; the rows are compared again.
	 *
	 * @param string $ref WC-123.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	private function header( string $ref ): ?array {
		$rows = $this->fo->rows( 'SalesOrderHeadersV2', array( OData::eq( 'CustomersOrderReference', $ref ) ) );
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['CustomersOrderReference'] ?? '' ) === $ref ) );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['SalesOrderNumber'] ?? '' ), (string) ( $b['SalesOrderNumber'] ?? '' ) ) );
		return $rows[0] ?? null;
	}

	/**
	 * The lines of an order in the order they were created.
	 *
	 * @param string $number Sales order number.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function lines( string $number ): array {
		if ( ! FoClient::literalSafe( $number ) ) {
			return array();
		}
		$rows = $this->fo->rows( 'SalesOrderLines', array( OData::eq( 'SalesOrderNumber', $number ) ) );
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['LineCreationSequenceNumber'] ?? 0 ) <=> (int) ( $b['LineCreationSequenceNumber'] ?? 0 ) );
		return $rows;
	}

	/**
	 * The lines of the order: products, then shipping and fees on their service items. The discount goes to the header.
	 *
	 * @param OrderData                              $order    Order.
	 * @param array<string,array<string,mixed>|null> $products Released products by SKU (null for an unknown SKU).
	 * @return array<int,FoLine>|ConnectorError
	 */
	private function plan( OrderData $order, array $products ): array|ConnectorError {
		$lines = array();
		foreach ( $order->lines as $line ) {
			$row = $products[ $line->sku ] ?? null;
			if ( null === $row ) {
				return Failures::unknownSku( $this->system, $line->sku );
			}
			$lines[] = new FoLine( (string) $row['ItemNumber'], '' !== $line->name ? $line->name : (string) $row['ItemNumber'], $line->quantity, $line->priceMinor, $line->taxCode );
		}
		$extras = array();
		if ( null !== $order->shipping ) {
			$extras[] = array( 'shipping', $order->shipping, __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) );
		}
		foreach ( $order->fees as $fee ) {
			$extras[] = array( 'fee', $fee, __( 'Fee', 'estasoft-erp-crm-sync-engine' ) );
		}
		foreach ( $extras as $extra ) {
			$item = $this->options->lineItem( $extra[0] );
			if ( '' === $item ) {
				return Failures::invalid(
					$this->system,
					$extra[0] . '_item',
					sprintf(
						/* translators: %s: name of the connection option. */
						__( 'Finance and Operations needs an item for this amount. Set the option %s.', 'estasoft-erp-crm-sync-engine' ),
						$extra[0] . '_item'
					),
					$extra[0] . ' item missing'
				);
			}
			$lines[] = new FoLine( $item, '' !== $extra[1]->name ? $extra[1]->name : $extra[2], 1, $extra[1]->totalMinor, $extra[1]->taxCode );
		}
		return $lines;
	}

	/**
	 * The fields of a new sales order header.
	 *
	 * @param OrderData $order    Order.
	 * @param string    $ref      WC-123.
	 * @param string    $customer Customer account.
	 * @param string    $date     Requested shipping date, as written by FoLine::stamp().
	 * @return array<string,mixed>
	 */
	private function headerBody( OrderData $order, string $ref, string $customer, string $date ): array {
		$body = array(
			'CustomersOrderReference'       => $ref,
			'SalesOrderName'                => self::pendingName( $ref ),
			'OrderingCustomerAccountNumber' => $customer,
			'InvoiceCustomerAccountNumber'  => $customer,
			'CurrencyCode'                  => $order->currency,
			'RequestedShippingDate'         => $date,
			'RequestedReceiptDate'          => $date,
		);
		if ( 'shop_reference' === $this->options->orderNumbering() ) {
			$body['SalesOrderNumber'] = Text::cut( $ref, self::NUMBER_MAX );
		}
		if ( $order->pricesIncludeTax ) {
			$body['ArePricesIncludingSalesTax'] = 'Yes';
		}
		if ( null !== $order->discount ) {
			$body['TotalDiscountAmount'] = Amounts::decimal( $order->discount->totalMinor, $order->currency );
		}
		$warehouse = '' !== ( $order->extra['warehouse'] ?? '' ) ? $order->extra['warehouse'] : $this->options->warehouse();
		$site      = '' !== ( $order->extra['site'] ?? '' ) ? $order->extra['site'] : $this->options->site();
		if ( '' !== $warehouse ) {
			$body['DefaultShippingWarehouseId'] = $warehouse;
		}
		if ( '' !== $site ) {
			$body['DefaultShippingSiteId'] = $site;
		}
		$to = $order->delivery;
		if ( '' !== ( $to['street'] ?? '' ) || '' !== ( $to['name'] ?? '' ) ) {
			$ship  = array(
				'DeliveryAddressName'            => $to['name'] ?? '',
				'DeliveryAddressStreet'          => $to['street'] ?? '',
				'DeliveryAddressCity'            => $to['city'] ?? '',
				'DeliveryAddressZipCode'         => $to['zip'] ?? '',
				'DeliveryAddressCountryRegionId' => strtoupper( $to['country_id'] ?? '' ),
				'DeliveryAddressStateId'         => $to['state_id'] ?? '',
			);
			$body += array_filter( $ship, static fn( $value ): bool => '' !== $value );
		}
		return $body;
	}

	/**
	 * A sales order header as a reference.
	 *
	 * @param array<string,mixed> $header   Header row.
	 * @param string              $currency Currency of the order.
	 * @param string              $warning  Accepted difference or note.
	 */
	private function ref( array $header, string $currency, string $warning = '' ): ExternalRef {
		$extra = array(
			'total_minor' => Amounts::minor( $header['OrderTotalAmount'] ?? 0, $currency ),
			'currency'    => $currency,
			'kind'        => 'sales_order',
			'reference'   => (string) ( $header['CustomersOrderReference'] ?? '' ),
		);
		if ( '' !== $warning ) {
			$extra['warnings'] = array( $warning );
		}
		$number = (string) ( $header['SalesOrderNumber'] ?? '' );
		return new ExternalRef( $number, $number, null, $extra );
	}
}
