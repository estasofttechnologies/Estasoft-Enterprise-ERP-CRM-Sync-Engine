<?php
/**
 * Sends a refund to Dynamics 365 Sales as a note on the deal.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A CRM has no credit note. The refund becomes a note (an annotation) on the deal of the original order: subject `Refund WC-456 of WC-123`,
 * text with the amount and the reason, and a marker `[estasoft:refund WC-456 minor=1250 AED]` the nightly check reads. The deal's value
 * is not changed.
 */
final class DvRefundSender {

	/**
	 * Columns read of a note.
	 */
	private const SELECT = 'annotationid,subject,notetext,_objectid_value';

	/**
	 * Longest subject (documented: 500 characters).
	 */
	private const SUBJECT_MAX = 500;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient  $dv      Client.
	 * @param DvOptions $options Options.
	 * @param string    $system  System name.
	 */
	public function __construct( private readonly DvClient $dv, private readonly DvOptions $options, private readonly string $system ) {
	}

	/**
	 * The refusal for a payload that is wrong on its face, found before any request goes out.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param string              $system  System name.
	 */
	public static function precheck( array $payload, string $system ): ?ConnectorError {
		$data = self::parse( $payload, $system );
		return $data instanceof ConnectorError ? $data : null;
	}

	/**
	 * The note of a refund, or null.
	 *
	 * @param Item $item Refund item.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( Item $item ): ?ExternalRef {
		$note = $this->note( $item->clientReference() );
		return null === $note ? null : $this->ref( $note );
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
		$data = self::parse( $payload, $this->system );
		if ( $data instanceof ConnectorError ) {
			return SendResult::failure( $data );
		}
		$ref  = $item->clientReference();
		$note = $this->note( $ref );
		if ( null !== $note ) {
			return SendResult::existing( $this->ref( $note ) );
		}
		$opps = new DvOpportunities( $this->dv, $this->options, $this->system );
		$deal = $opps->find( $data['order_ref'] );
		if ( null === $deal || null === OData::guid( $deal['opportunityid'] ?? null ) ) {
			return SendResult::failure(
				Failures::invalid(
					$this->system,
					'order_ref',
					sprintf(
						/* translators: %s: order reference such as WC-123. */
						__( 'The deal for order %s is not in Dynamics 365 yet, so the refund has nothing to refer to.', 'estasoft-erp-crm-sync-engine' ),
						$data['order_ref']
					),
					'original deal missing'
				)
			);
		}
		$currency = ( new DvCurrencies( $this->dv, $this->system ) )->codeOf( (string) ( $deal['_transactioncurrencyid_value'] ?? '' ) );
		$amount   = Amounts::text( $data['amount'], $currency ) . ' ' . $currency;
		$text     = sprintf(
			/* translators: 1: refund reference, 2: order reference, 3: amount with currency. */
			__( 'Refund %1$s of order %2$s: %3$s.', 'estasoft-erp-crm-sync-engine' ),
			$ref,
			$data['order_ref'],
			$amount
		);
		if ( '' !== $data['reason'] ) {
			$text .= ' ' . sprintf( /* translators: %s: reason of the refund. */ __( 'Reason: %s', 'estasoft-erp-crm-sync-engine' ), $data['reason'] );
		}
		$text   .= "\n" . sprintf( '[estasoft:refund %s minor=%d %s]', $ref, $data['amount'], $currency );
		$created = $this->dv->create(
			'annotations',
			array(
				'subject'                         => Text::cut( sprintf( 'Refund %s of %s', $ref, $data['order_ref'] ), self::SUBJECT_MAX ),
				'notetext'                        => $text,
				'objectid_opportunity@odata.bind' => '/opportunities(' . OData::guid( $deal['opportunityid'] ) . ')',
			),
			'annotationid',
			self::SELECT
		);
		return SendResult::created( $this->ref( $created, $data['amount'], $currency ) );
	}

	/**
	 * The note whose subject starts with `Refund {ref} of `.
	 *
	 * @param string $ref WC-456.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	private function note( string $ref ): ?array {
		$prefix = 'Refund ' . $ref . ' of ';
		foreach ( $this->dv->rows(
			'annotations',
			array(
				'$filter' => OData::startsWith( 'subject', $prefix ),
				'$select' => self::SELECT,
			)
		) as $row ) {
			if ( 0 === strncmp( (string) ( $row['subject'] ?? '' ), $prefix, strlen( $prefix ) ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * A note as a reference.
	 *
	 * @param array<string,mixed> $note     Note row.
	 * @param int|null            $amount   Refunded minor units when known (else read from the marker).
	 * @param string              $currency ISO code when known.
	 */
	private function ref( array $note, ?int $amount = null, string $currency = '' ): ExternalRef {
		$marker = 1 === preg_match( '/\[estasoft:refund (WC-\d+) minor=(-?\d+) ([A-Z]{3})\]/', (string) ( $note['notetext'] ?? '' ), $found ) ? $found : null;
		return new ExternalRef(
			(string) ( $note['annotationid'] ?? '' ),
			(string) ( $note['subject'] ?? '' ),
			null,
			array(
				'kind'           => 'note',
				'refunded_minor' => $amount ?? (int) ( $marker[2] ?? 0 ),
				'currency'       => '' !== $currency ? $currency : (string) ( $marker[3] ?? '' ),
			)
		);
	}

	/**
	 * Read and check a mapped refund payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param string              $system  System name.
	 * @return array{order_ref:string,reason:string,amount:int}|ConnectorError
	 */
	private static function parse( array $payload, string $system ): array|ConnectorError {
		$orderRef = is_string( $payload['order_ref'] ?? null ) ? trim( $payload['order_ref'] ) : '';
		$amount   = $payload['amount'] ?? null;
		if ( 1 !== preg_match( '/^WC-\d+$/', $orderRef ) ) {
			return Failures::invalid( $system, 'order_ref', __( 'A refund needs the reference of its order, WC- and the order number.', 'estasoft-erp-crm-sync-engine' ), 'order ref' );
		}
		if ( is_string( $amount ) && 1 === preg_match( '/^\d{1,15}$/', trim( $amount ) ) ) {
			$amount = (int) trim( $amount );
		}
		if ( ! is_int( $amount ) || $amount <= 0 ) {
			return Failures::invalid( $system, 'amount', __( 'A refund needs an amount above zero in whole numbers of the smallest currency unit.', 'estasoft-erp-crm-sync-engine' ), 'refund amount' );
		}
		return array(
			'order_ref' => $orderRef,
			'reason'    => is_string( $payload['reason'] ?? null ) ? trim( $payload['reason'] ) : '',
			'amount'    => $amount,
		);
	}
}
