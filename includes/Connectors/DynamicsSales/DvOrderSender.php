<?php
/**
 * Sends an order to Dynamics 365 Sales as a deal.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
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
 * One pass over an order. It starts from a read (is there a deal named `WC-{id} - …`?), checks everything that can be checked without writing
 * (the currency row and its decimals, the products, the contact policy), then creates the account and contact if they are missing, the deal
 * with its marker, and the products one by one, and compares the total Dataverse worked out. A pass cut off anywhere is repeated safely.
 */
final class DvOrderSender {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient    $dv          Client.
	 * @param DvOptions   $options     Options.
	 * @param string      $system      System name.
	 * @param string      $environment Environment address, for links.
	 * @param Logger|null $logger      Log.
	 */
	public function __construct( private readonly DvClient $dv, private readonly DvOptions $options, private readonly string $system, private readonly string $environment, private readonly ?Logger $logger = null ) {
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
	 * The finished deal of an order, or null.
	 *
	 * @param Item $item Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( Item $item ): ?ExternalRef {
		$ref  = $item->clientReference();
		$opps = new DvOpportunities( $this->dv, $this->options, $this->system );
		$row  = $opps->find( $ref );
		if ( null === $row || ! $opps->isComplete( $row, $ref ) ) {
			return null;
		}
		return $opps->ref( $row, $this->currencyOf( $row ), $this->environment );
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
		$opps     = new DvOpportunities( $this->dv, $this->options, $this->system );
		$currency = ( new DvCurrencies( $this->dv, $this->system ) )->resolve( $order->currency );
		if ( $currency instanceof ConnectorError ) {
			return SendResult::failure( $currency );
		}
		$products = ( new DvProducts( $this->dv ) )->resolve( array_map( static fn( $line ): string => $line->sku, $order->lines ) );
		$plan     = DvPlan::forOrder( $order, $products, $this->options, $this->system );
		if ( $plan instanceof ConnectorError ) {
			return SendResult::failure( $plan );
		}
		$priceList = $this->priceList();
		if ( $priceList instanceof ConnectorError ) {
			return SendResult::failure( $priceList );
		}
		$row  = $opps->find( $ref );
		$work = false;
		if ( null === $row ) {
			$parties = ( new DvParties( $this->dv, $this->system, $this->logger ) )->resolve( $order, $this->options, $item->entityId );
			if ( $parties instanceof ConnectorError ) {
				return SendResult::failure( $parties );
			}
			$row  = $opps->create( $this->headerBody( $order, $ref, $currency['id'], $parties, count( $plan ), $priceList ) );
			$work = true;
		} elseif ( 0 !== strcasecmp( (string) ( $row['_transactioncurrencyid_value'] ?? '' ), $currency['id'] ) ) {
			return SendResult::failure( Failures::currencyDiffers( $this->system, $order->currency, '' ) );
		}
		$id = OData::guid( $row['opportunityid'] ?? null );
		if ( null === $id ) {
			return SendResult::failure( Failures::invalid( $this->system, 'opportunity', __( 'Dynamics 365 did not return the id of the deal.', 'estasoft-erp-crm-sync-engine' ), 'no opportunity id' ) );
		}
		$written = $opps->ensureLines( $id, $plan, $order->currency );
		if ( $written['error'] instanceof ConnectorError ) {
			return SendResult::failure( $written['error'] );
		}
		$work  = $work || $written['written'] > 0;
		$fresh = $opps->header( $id ) ?? $row;
		$found = isset( $fresh['totalamount'] ) ? Amounts::minor( $fresh['totalamount'], $order->currency ) : null;
		$check = TotalCheck::compare(
			$found,
			$order->expectedMinor(),
			$order->currency,
			'fail' === $this->options->totalMismatch(),
			$this->system,
			__( 'Check that the products and the tax amounts of the order were written as the shop has them, then delete the deal and try again.', 'estasoft-erp-crm-sync-engine' )
		);
		if ( $check['error'] instanceof ConnectorError ) {
			return SendResult::failure( $check['error'] );
		}
		$result = $opps->ref( $fresh, $order->currency, $this->environment, $check['warning'] );
		return $work ? SendResult::created( $result ) : SendResult::existing( $result );
	}

	/**
	 * The columns of a new deal.
	 *
	 * @param OrderData                                                           $order    Order.
	 * @param string                                                              $ref      WC-123.
	 * @param string                                                              $currency Currency row GUID.
	 * @param array{contact:array<string,mixed>,account:array<string,mixed>|null} $parties  Contact and account.
	 * @param int                                                                 $lines    Number of product lines.
	 * @param string|null                                                         $priceList Price list GUID, or null.
	 * @return array<string,mixed>
	 */
	private function headerBody( OrderData $order, string $ref, string $currency, array $parties, int $lines, ?string $priceList ): array {
		$marker             = DvOpportunities::marker( $ref, $lines, $order->partner['email'] ?? '' );
		$note               = trim( $order->note . ( '' !== $order->paymentReference ? "\n" . sprintf( /* translators: %s: payment reference. */ __( 'Payment %s', 'estasoft-erp-crm-sync-engine' ), $order->paymentReference ) : '' ) . ( '' !== ( $order->partner['vat'] ?? '' ) ? "\n" . sprintf( /* translators: %s: tax number of the buyer. */ __( 'Tax number %s', 'estasoft-erp-crm-sync-engine' ), $order->partner['vat'] ) : '' ) );
		list( $note, $cut ) = Text::fit( $note, DvOpportunities::DESCRIPTION_MAX - strlen( $marker ) - 1 );
		if ( $cut ) {
			$this->logger?->warning( 'The order note was shortened to fit the Dynamics 365 description.', array( 'order' => $ref ) );
		}
		$body = array(
			'name'                             => Text::cut( $ref . ' - ' . $order->buyerName(), DvOpportunities::NAME_MAX ),
			'description'                      => ( '' !== $note ? $note . "\n" : '' ) . $marker,
			'estimatedvalue'                   => Amounts::decimal( (int) $order->expectedMinor(), $order->currency ),
			'transactioncurrencyid@odata.bind' => '/transactioncurrencies(' . $currency . ')',
		);
		if ( null === $order->expectedMinor() ) {
			unset( $body['estimatedvalue'] );
		}
		if ( '' !== $order->orderDate ) {
			$body['estimatedclosedate'] = $order->orderDate;
		}
		$account = $parties['account'];
		if ( null !== $account && null !== OData::guid( $account['accountid'] ?? null ) ) {
			$body['customerid_account@odata.bind'] = '/accounts(' . OData::guid( $account['accountid'] ) . ')';
		} else {
			$body['customerid_contact@odata.bind'] = '/contacts(' . (string) OData::guid( $parties['contact']['contactid'] ?? null ) . ')';
		}
		if ( null !== $order->discount ) {
			$body['discountamount'] = Amounts::decimal( $order->discount->totalMinor, $order->currency );
		}
		if ( null !== $priceList ) {
			$body['pricelevelid@odata.bind'] = '/pricelevels(' . $priceList . ')';
		}
		$column = $this->options->referenceColumn();
		if ( '' !== $column ) {
			$body[ $column ] = $ref;
		}
		return $body;
	}

	/**
	 * The price list the connection names, if any.
	 *
	 * @return string|ConnectorError|null The GUID, a refusal when no such list exists, or null when none is configured.
	 *
	 * @throws ApiException On failure.
	 */
	private function priceList(): string|ConnectorError|null {
		$name = $this->options->priceList();
		if ( '' === $name ) {
			return null;
		}
		foreach ( $this->dv->rows(
			'pricelevels',
			array(
				'$filter' => OData::eq( 'name', $name ),
				'$select' => 'pricelevelid,name',
			)
		) as $row ) {
			if ( 0 === strcasecmp( (string) ( $row['name'] ?? '' ), $name ) && null !== OData::guid( $row['pricelevelid'] ?? null ) ) {
				return (string) OData::guid( $row['pricelevelid'] );
			}
		}
		return Failures::invalid( $this->system, 'price_list', __( 'There is no price list with this name in Dynamics 365.', 'estasoft-erp-crm-sync-engine' ), 'price list missing' );
	}

	/**
	 * ISO code of the currency of an opportunity row.
	 *
	 * @param array<string,mixed> $row Opportunity row.
	 *
	 * @throws ApiException On failure.
	 */
	private function currencyOf( array $row ): string {
		return ( new DvCurrencies( $this->dv, $this->system ) )->codeOf( (string) ( $row['_transactioncurrencyid_value'] ?? '' ) );
	}
}
