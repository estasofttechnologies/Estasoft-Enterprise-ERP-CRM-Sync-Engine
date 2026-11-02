<?php
/**
 * Sends a refund to Business Central as a sales credit memo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderExtra;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A credit memo for the customer and in the currency of the original document, with `externalDocumentNumber = WC-{refund id}`. The first
 * line is a comment `Refund of WC-{order id}` (the nightly check finds the order through it), the last is the completion marker. A draft
 * credit memo is the default: a person posts it. With `refund_state = post` the memo is posted after the total was checked.
 */
final class BcRefundSender {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient  $bc      Client pinned to the company.
	 * @param BcOptions $options Options.
	 * @param string    $system  System name.
	 */
	public function __construct( private readonly BcClient $bc, private readonly BcOptions $options, private readonly string $system ) {
	}

	/**
	 * The refusal for a payload that is wrong on its face, found before any request goes out.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param string              $system  System name.
	 */
	public static function precheck( array $payload, string $system ): ?ConnectorError {
		$data = BcRefundData::parse( $payload, $system );
		return $data instanceof ConnectorError ? $data : null;
	}

	/**
	 * The finished credit memo of a refund, or null. Finished means posted, or (when the connection leaves memos as drafts) complete.
	 *
	 * @param Item $item Refund item.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( Item $item ): ?ExternalRef {
		$ref  = $item->clientReference();
		$docs = new BcDocuments( $this->bc, $this->system );
		$row  = $docs->find( BcDocType::CreditMemo, $ref );
		if ( null === $row ) {
			return null;
		}
		if ( BcDocType::CreditMemo->isPosted( (string) ( $row['status'] ?? '' ) ) ) {
			return BcRefs::document( BcDocType::CreditMemo, $row );
		}
		$id = OData::guid( $row['id'] ?? null );
		if ( 'post' === $this->options->refundState() || null === $id || null === $docs->markerCount( $docs->lines( BcDocType::CreditMemo, $id ), $ref ) ) {
			return null;
		}
		return BcRefs::document( BcDocType::CreditMemo, $row );
	}

	/**
	 * Send a refund.
	 *
	 * @param Item                $item    Refund item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$data = BcRefundData::parse( $payload, $this->system );
		if ( $data instanceof ConnectorError ) {
			return SendResult::failure( $data );
		}
		$ref  = $item->clientReference();
		$docs = new BcDocuments( $this->bc, $this->system );
		$memo = $docs->find( BcDocType::CreditMemo, $ref );
		if ( null !== $memo && BcDocType::CreditMemo->isPosted( (string) ( $memo['status'] ?? '' ) ) ) {
			return SendResult::existing( BcRefs::document( BcDocType::CreditMemo, $memo ) );
		}
		$origin = $this->origin( $docs, $data->orderRef );
		if ( null === $origin ) {
			return SendResult::failure(
				Failures::invalid(
					$this->system,
					'order_ref',
					sprintf(
						/* translators: %s: order reference such as WC-123. */
						__( 'The order %s is not in Business Central yet, so the refund has nothing to refer to.', 'estasoft-erp-crm-sync-engine' ),
						$data->orderRef
					),
					'original order missing'
				)
			);
		}
		$currency = $origin['currency'];
		$items    = ( new BcItems( $this->bc, $this->options, $this->system ) )->resolve( array_map( static fn( array $line ): string => $line['sku'], $data->lines ) );
		if ( $items instanceof ConnectorError ) {
			return SendResult::failure( $items );
		}
		$plan = $this->plan( $data, $items );
		if ( $plan instanceof ConnectorError ) {
			return SendResult::failure( $plan );
		}
		$work = false;
		if ( null === $memo ) {
			$body = array(
				'externalDocumentNumber' => $ref,
				'customerNumber'         => $origin['customer'],
				'currencyCode'           => $currency,
			);
			if ( '' !== $data->date ) {
				$body['creditMemoDate'] = $data->date;
				if ( 'order' === $this->options->postingDate() ) {
					$body['postingDate'] = $data->date;
				}
			}
			$memo = $docs->create( BcDocType::CreditMemo, $body );
			$work = true;
		}
		$id = OData::guid( $memo['id'] ?? null );
		if ( null === $id ) {
			return SendResult::failure( Failures::invalid( $this->system, 'document', __( 'Business Central did not return the id of the document.', 'estasoft-erp-crm-sync-engine' ), 'no document id' ) );
		}
		$came = strtoupper( trim( (string) ( $memo['currencyCode'] ?? '' ) ) );
		if ( '' !== $came && $came !== $currency ) {
			return SendResult::failure( Failures::currencyDiffers( $this->system, $currency, $came ) );
		}
		$written = $docs->ensureLines( BcDocType::CreditMemo, $id, $plan, $ref, $currency );
		if ( $written['error'] instanceof ConnectorError ) {
			return SendResult::failure( $written['error'] );
		}
		$work  = $work || $written['written'] > 0;
		$fresh = $docs->header( BcDocType::CreditMemo, $id ) ?? $memo;
		$posts = 'post' === $this->options->refundState();
		$check = BcTotals::compare( $fresh, $data->amountMinor, $currency, false, $posts && 'fail' === $this->options->totalMismatch(), $this->system );
		if ( $check['error'] instanceof ConnectorError ) {
			return SendResult::failure( $check['error'] );
		}
		if ( ! $posts ) {
			$result = BcRefs::document( BcDocType::CreditMemo, $fresh, $currency, $check['warning'] );
			return $work ? SendResult::created( $result ) : SendResult::existing( $result );
		}
		$work   = $docs->post( BcDocType::CreditMemo, $id ) || $work;
		$posted = $docs->findPosted( BcDocType::CreditMemo, $ref );
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
		$result = BcRefs::document( BcDocType::CreditMemo, $posted, $currency, $check['warning'] );
		return $work ? SendResult::created( $result ) : SendResult::existing( $result );
	}

	/**
	 * The customer and currency of the document the order became.
	 *
	 * @param BcDocuments $docs     Documents.
	 * @param string      $orderRef WC-123.
	 * @return array{customer:string,currency:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	private function origin( BcDocuments $docs, string $orderRef ): ?array {
		$row = $docs->find( BcDocType::Order, $orderRef ) ?? $docs->find( BcDocType::Invoice, $orderRef );
		if ( null === $row || '' === (string) ( $row['customerNumber'] ?? '' ) || '' === (string) ( $row['currencyCode'] ?? '' ) ) {
			return null;
		}
		return array(
			'customer' => (string) $row['customerNumber'],
			'currency' => strtoupper( (string) $row['currencyCode'] ),
		);
	}

	/**
	 * The lines of the credit memo: the reference note, the reason, the products, shipping and a remaining amount.
	 *
	 * @param BcRefundData                           $data  Refund.
	 * @param array<string,array<string,mixed>|null> $items Items by SKU.
	 * @return array<int,BcLine>|ConnectorError
	 */
	private function plan( BcRefundData $data, array $items ): array|ConnectorError {
		$lines = array( BcLine::comment( sprintf( 'Refund of %s', $data->orderRef ) ) );
		if ( '' !== $data->reason ) {
			$lines[] = BcLine::comment( sprintf( /* translators: %s: reason of the refund. */ __( 'Reason: %s', 'estasoft-erp-crm-sync-engine' ), $data->reason ) );
		}
		foreach ( $data->lines as $line ) {
			$row  = $items[ $line['sku'] ] ?? null;
			$type = 'Item';
			if ( null === $row ) {
				if ( 'account' !== $this->options->unknownSku() ) {
					return Failures::unknownSku( $this->system, $line['sku'] );
				}
				$type = 'Account';
			}
			$number = null === $row ? $this->options->unknownSkuAccount() : (string) $row['number'];
			$name   = '' !== $line['name'] ? $line['name'] : ( null === $row ? $line['sku'] : (string) ( $row['displayName'] ?? '' ) );
			$qty    = $line['quantity'];
			if ( is_int( $qty ) && $qty > 0 && 0 === $line['amount'] % $qty ) {
				$lines[] = new BcLine( $type, $number, $name, $qty, intdiv( $line['amount'], $qty ) );
				continue;
			}
			$lines[] = new BcLine(
				$type,
				$number,
				sprintf(
					/* translators: 1: product name, 2: quantity. */
					__( '%1$s (quantity %2$s)', 'estasoft-erp-crm-sync-engine' ),
					$name,
					(string) $qty
				),
				1,
				$line['amount']
			);
		}
		foreach ( array( array( 'shipping', $data->shippingMinor, __( 'Shipping refund', 'estasoft-erp-crm-sync-engine' ) ), array( 'refund', $data->otherMinor, __( 'Refund', 'estasoft-erp-crm-sync-engine' ) ) ) as $extra ) {
			if ( $extra[1] <= 0 ) {
				continue;
			}
			$line = BcPlan::extraLine( $extra[0], new OrderExtra( '', $extra[1] ), $extra[2], 1, $this->options, $this->system );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		return $lines;
	}
}
