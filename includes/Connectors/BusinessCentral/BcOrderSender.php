<?php
/**
 * Sends an order to Business Central.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One pass over an order. It starts from a read (is there already a document with `WC-{id}`?), prepares everything that can be checked
 * without writing (items, tax and shipping targets, prices), and only then creates the header, the lines and the completion marker, checks
 * the total, and posts when the connection asks for it. A pass that is cut off anywhere is repeated safely: a header, a line or a posting
 * that already happened is found again and never done twice.
 */
final class BcOrderSender {

	/**
	 * Longest text of each ship-to field (from the AL tables).
	 */
	private const SHIP_TO_LIMITS = array(
		'shipToName'         => 100,
		'shipToAddressLine1' => 100,
		'shipToAddressLine2' => 50,
		'shipToCity'         => 30,
		'shipToState'        => 30,
		'shipToPostCode'     => 20,
	);

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient    $bc      Client pinned to the company.
	 * @param BcOptions   $options Options.
	 * @param string      $system  System name.
	 * @param Logger|null $logger  Log.
	 */
	public function __construct( private readonly BcClient $bc, private readonly BcOptions $options, private readonly string $system, private readonly ?Logger $logger = null ) {
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
	 * The finished document of an order, or null when there is none or it is not finished. A draft order with all its lines and its marker
	 * is finished when the connection stops at the order; an invoice is finished when it is posted.
	 *
	 * @param Item $item Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( Item $item ): ?ExternalRef {
		$ref     = $item->clientReference();
		$located = $this->locate( $ref );
		if ( null === $located ) {
			return null;
		}
		list( $type, $row ) = array( $located['type'], $located['row'] );
		if ( BcDocType::Invoice === $type ) {
			return $type->isPosted( (string) ( $row['status'] ?? '' ) ) ? BcRefs::document( $type, $row ) : null;
		}
		if ( 'order' !== $this->options->documents() ) {
			return null;
		}
		$id   = OData::guid( $row['id'] ?? null );
		$docs = new BcDocuments( $this->bc, $this->system );
		if ( null === $id || null === $docs->markerCount( $docs->lines( $type, $id ), $ref ) ) {
			return null;
		}
		return BcRefs::document( $type, $row );
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
		$ref     = $item->clientReference();
		$docs    = new BcDocuments( $this->bc, $this->system );
		$located = $this->locate( $ref );
		if ( null !== $located && BcDocType::Invoice === $located['type'] && $located['type']->isPosted( (string) ( $located['row']['status'] ?? '' ) ) ) {
			return SendResult::existing( BcRefs::document( $located['type'], $located['row'] ) );
		}
		$items = ( new BcItems( $this->bc, $this->options, $this->system ) )->resolve( array_map( static fn( $line ): string => $line->sku, $order->lines ) );
		if ( $items instanceof ConnectorError ) {
			return SendResult::failure( $items );
		}
		$plan = BcPlan::forOrder( $order, $items, $this->options, $this->system );
		if ( $plan instanceof ConnectorError ) {
			return SendResult::failure( $plan );
		}
		$type = 'invoice' === $this->options->documents() ? BcDocType::Invoice : BcDocType::Order;
		$work = false;
		if ( null === $located ) {
			$customer = ( new BcCustomers( $this->bc, $this->system, $this->logger ) )->resolve( $order, $this->options, $item->entityId );
			if ( $customer instanceof ConnectorError ) {
				return SendResult::failure( $customer );
			}
			$header = $docs->create( $type, $this->headerBody( $type, $order, (string) ( $customer['row']['number'] ?? '' ), $ref ) );
			$work   = true;
		} else {
			$header = $located['row'];
		}
		$id = OData::guid( $header['id'] ?? null );
		if ( null === $id ) {
			return SendResult::failure( Failures::invalid( $this->system, 'document', __( 'Business Central did not return the id of the document.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no document id' ) );
		}
		$came = strtoupper( trim( (string) ( $header['currencyCode'] ?? '' ) ) );
		if ( '' !== $came && $came !== $order->currency ) {
			return SendResult::failure( Failures::currencyDiffers( $this->system, $order->currency, $came ) );
		}
		$written = $docs->ensureLines( $type, $id, $plan, $ref, $order->currency );
		if ( $written['error'] instanceof ConnectorError ) {
			return SendResult::failure( $written['error'] );
		}
		$work  = $work || $written['written'] > 0;
		$fresh = $docs->header( $type, $id ) ?? $header;
		$check = BcTotals::compare( $fresh, $order->expectedMinor(), $order->currency, $order->pricesIncludeTax, 'fail' === $this->options->totalMismatch(), $this->system );
		if ( $check['error'] instanceof ConnectorError ) {
			// No marker: a document that failed its check is not finished and is not linked on the next try.
			return SendResult::failure( $check['error'] );
		}
		$work = $docs->ensureMarker( $type, $id, $ref, count( $plan ), $order->currency ) || $work;
		if ( 'order' === $this->options->documents() ) {
			$result = BcRefs::document( $type, $fresh, $order->currency, $check['warning'] );
			return $work ? SendResult::created( $result ) : SendResult::existing( $result );
		}
		$ran    = 'invoice' === $this->options->documents() ? $docs->post( BcDocType::Invoice, $id ) : $docs->shipAndInvoice( $id );
		$work   = $work || $ran;
		$posted = $docs->findPosted( BcDocType::Invoice, $ref );
		if ( null === $posted ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Net002,
					array(
						'system' => $this->system,
						'status' => 'pending',
					),
					null,
					null,
					null,
					'posted document not visible yet'
				)
			);
		}
		$result = BcRefs::document( BcDocType::Invoice, $posted, $order->currency, $check['warning'] );
		return $work ? SendResult::created( $result ) : SendResult::existing( $result );
	}

	/**
	 * The document that carries the reference. Orders are looked for first (not in the invoice mode); a posted invoice means the order was
	 * invoiced already, by this connector or by a person, and must not be created again.
	 *
	 * @param string $ref WC-123.
	 * @return array{type:BcDocType,row:array<string,mixed>}|null
	 *
	 * @throws ApiException On failure.
	 */
	private function locate( string $ref ): ?array {
		$docs = new BcDocuments( $this->bc, $this->system );
		if ( 'invoice' !== $this->options->documents() ) {
			$order = $docs->find( BcDocType::Order, $ref );
			if ( null !== $order ) {
				return array(
					'type' => BcDocType::Order,
					'row'  => $order,
				);
			}
		}
		$invoice = $docs->find( BcDocType::Invoice, $ref );
		if ( null !== $invoice && ( 'invoice' === $this->options->documents() || BcDocType::Invoice->isPosted( (string) ( $invoice['status'] ?? '' ) ) ) ) {
			return array(
				'type' => BcDocType::Invoice,
				'row'  => $invoice,
			);
		}
		return null;
	}

	/**
	 * The fields of a new order or invoice.
	 *
	 * @param BcDocType $type           Order or invoice.
	 * @param OrderData $order          Order.
	 * @param string    $customerNumber Customer number.
	 * @param string    $ref            WC-123.
	 * @return array<string,mixed>
	 */
	private function headerBody( BcDocType $type, OrderData $order, string $customerNumber, string $ref ): array {
		$body = array(
			'externalDocumentNumber' => $ref,
			'customerNumber'         => $customerNumber,
			'currencyCode'           => $order->currency,
		);
		if ( '' !== $order->orderDate ) {
			$body[ $type->dateField() ] = $order->orderDate;
			if ( 'order' === $this->options->postingDate() ) {
				$body['postingDate'] = $order->orderDate;
			}
		}
		$to = $order->delivery;
		if ( '' !== ( $to['street'] ?? '' ) || '' !== ( $to['name'] ?? '' ) ) {
			$ship               = array(
				'shipToName'         => $to['name'] ?? '',
				'shipToAddressLine1' => $to['street'] ?? '',
				'shipToAddressLine2' => $to['street2'] ?? '',
				'shipToCity'         => $to['city'] ?? '',
				'shipToState'        => $to['state_id'] ?? '',
				'shipToPostCode'     => $to['zip'] ?? '',
				'shipToCountry'      => strtoupper( $to['country_id'] ?? '' ),
			);
			list( $ship, $cut ) = Text::fitFields( $ship, self::SHIP_TO_LIMITS );
			if ( array() !== $cut ) {
				$this->logger?->warning( 'Delivery address text was shortened to fit Business Central.', array( 'fields' => implode( ',', $cut ) ) );
			}
			$body += array_filter( $ship, static fn( $value ): bool => '' !== $value );
		}
		return $body;
	}
}
