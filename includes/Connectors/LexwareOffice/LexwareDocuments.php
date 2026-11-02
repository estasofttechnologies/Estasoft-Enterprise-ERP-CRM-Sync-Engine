<?php
/**
 * Orders as Lexware Office invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Tolerance;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order flow: contact, duplicate search, lines, create (draft or finalised), read back, total check. A pass never repeats the create:
 * it searches first, and a document that exists is checked and linked, never made again.
 */
final class LexwareDocuments {

	/**
	 * Address of the Lexware app, for links.
	 */
	public const APP = 'https://app.lexware.de';

	/**
	 * Set up the object.
	 *
	 * @param LexwareContacts $contacts Contact lookups.
	 * @param LexwareLines    $lines    Line builder.
	 * @param LexwareSearch   $search   Duplicate search.
	 * @param Amounts         $amounts  Conversion helper.
	 */
	public function __construct(
		private readonly LexwareContacts $contacts,
		private readonly LexwareLines $lines,
		private readonly LexwareSearch $search,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * Send an order.
	 *
	 * @param ApiClient    $api     Client.
	 * @param Item         $item    Queue item.
	 * @param OrderPayload $order   Mapped order.
	 * @param Options      $options Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendOrder( ApiClient $api, Item $item, OrderPayload $order, Options $options ): SendResult {
		if ( 'EUR' !== $order->currency ) {
			return SendResult::failure( $this->currencyRefusal( $order->currency ) );
		}
		$contact = $this->contacts->resolve( $api, $order->customer, $options );
		if ( $contact instanceof ConnectorError ) {
			return SendResult::failure( $contact );
		}
		$timezone = $options->text( 'timezone' );
		$marker   = $item->clientReference();
		$existing = $this->search->find( $api, 'invoice', $contact['id'], $marker, $order->date, $timezone );
		$warnings = $contact['address_differs'] ? array( 'address_differs' ) : array();
		if ( null !== $existing ) {
			return $this->resume( $existing, $order, $options, $warnings );
		}
		$built = $this->lines->build( $api, $order, $options );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$warnings = array_merge( $warnings, $built['warnings'] );
		$date     = LocalDate::midnightWithOffset( $order->date, $timezone );
		$body     = array(
			'voucherDate'   => $date,
			'address'       => array( 'contactId' => $contact['id'] ),
			'lineItems'     => $built['items'],
			'totalPrice'    => array( 'currency' => 'EUR' ),
			'taxConditions' => array( 'taxType' => $built['tax_type'] ),
			'title'         => '' !== $order->texts['title'] ? $order->texts['title'] : 'Rechnung',
			'introduction'  => $this->introduction( $marker, $order->texts['introduction'] ),
			'remark'        => $this->remark( $order ),
		);
		$shipping = $options->choice( 'shipping_type' );
		if ( 'none' === $shipping ) {
			$body['shippingConditions'] = array( 'shippingType' => 'none' );
		} elseif ( '' !== $date ) {
			$body['shippingConditions'] = array(
				'shippingType' => $shipping,
				'shippingDate' => $date,
			);
		}
		$open    = $options->is( 'invoice_state', 'open' );
		$created = $api->post( 'invoices', $open ? array( 'finalize' => true ) : array(), $body )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( LexwareOfficeConnector::LABEL, 'invoice', __( 'Lexware Office did not return the new invoice.', 'estasoft-erp-crm-sync-engine' ), 'no invoice id' ) );
		}
		$document = $api->get( 'invoices/' . rawurlencode( $id ) )->json();
		$document = array() === $document ? array( 'id' => $id ) : $document + array( 'id' => $id );
		$problem  = $this->checkTotal( $document, $order->totalMinor, $options, $warnings, $order->rows(), $order->date );
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		return SendResult::created( $this->ref( $document, 'invoices', $warnings ) );
	}

	/**
	 * A document that already exists: check it and link it. An invoice that is still a draft while finalised invoices are wanted stays a draft
	 * (Lexware has no call that finalises a draft) and the result says so.
	 *
	 * @param array<string,mixed> $document   Document from the API.
	 * @param OrderPayload        $order      Order.
	 * @param Options             $options    Settings.
	 * @param array<int,string>   $warnings   Warnings so far.
	 */
	private function resume( array $document, OrderPayload $order, Options $options, array $warnings ): SendResult {
		if ( $options->is( 'invoice_state', 'open' ) && 'draft' === (string) ( $document['voucherStatus'] ?? '' ) ) {
			$warnings[] = 'stays_draft';
		}
		$problem = $this->checkTotal( $document, $order->totalMinor, $options, $warnings, $order->rows(), $order->date );
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		return SendResult::existing( $this->ref( $document, 'invoices', $warnings ) );
	}

	/**
	 * Compare the document's gross total with the shop's, and its date with the day the connector sent.
	 *
	 * @param array<string,mixed> $document Document from the API.
	 * @param int|null            $expected Expected total in minor units, or null for no check.
	 * @param Options             $options  Settings.
	 * @param array<int,string>   $warnings Warnings, extended when the totals differ and the setting is warn, or when the date differs.
	 * @param int                 $rows     Rows of the document, for the default tolerance.
	 * @param string              $utcDate  The date (UTC text) the connector converted into `voucherDate`, or empty for no date check.
	 */
	public function checkTotal( array $document, ?int $expected, Options $options, array &$warnings, int $rows = 1, string $utcDate = '' ): ?ConnectorError {
		if ( '' !== $utcDate && LocalDate::differs( $document['voucherDate'] ?? null, $utcDate, $options->text( 'timezone' ) ) ) {
			$warnings[] = 'date_differs';
		}
		if ( null === $expected ) {
			return null;
		}
		$actual = $this->totalMinor( $document );
		if ( null === $actual ) {
			return Refusal::value( LexwareOfficeConnector::LABEL, 'totalPrice', __( 'Lexware Office returned no total for the document.', 'estasoft-erp-crm-sync-engine' ), 'no total' );
		}
		if ( Tolerance::accepts( $actual, $expected, $options, $rows ) ) {
			return null;
		}
		if ( $options->is( 'total_mismatch', 'warn' ) ) {
			$warnings[] = 'total_differs';
			return null;
		}
		return Refusal::totals(
			LexwareOfficeConnector::LABEL,
			'totalPrice',
			$this->amounts->decimal( $expected, 2 ) . ' EUR',
			$this->amounts->decimal( $actual, 2 ) . ' EUR'
		);
	}

	/**
	 * The gross total of a document in minor units.
	 *
	 * @param array<string,mixed> $document Document from the API.
	 */
	public function totalMinor( array $document ): ?int {
		$value = $document['totalPrice']['totalGrossAmount'] ?? null;
		return null === $value ? null : $this->amounts->toMinor( $value, 2 );
	}

	/**
	 * The reference the engine stores for a document.
	 *
	 * @param array<string,mixed> $document Document from the API.
	 * @param string              $path     `invoices` or `credit-notes`.
	 * @param array<int,string>   $warnings Warnings.
	 */
	public function ref( array $document, string $path, array $warnings ): ExternalRef {
		$id    = PayloadReader::text( $document['id'] ?? '' );
		$extra = array(
			'total_minor' => $this->totalMinor( $document ),
			'currency'    => 'EUR',
			'status'      => PayloadReader::text( $document['voucherStatus'] ?? '' ),
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = array_values( array_unique( $warnings ) );
		}
		return new ExternalRef(
			$id,
			PayloadReader::text( $document['voucherNumber'] ?? '' ),
			self::APP . '/permalink/' . ( 'credit-notes' === $path ? 'credit-notes' : 'invoices' ) . '/view/' . rawurlencode( $id ),
			$extra
		);
	}

	/**
	 * SYNC-VAL-002 for a currency other than euro.
	 *
	 * @param string $currency Currency of the order.
	 */
	public function currencyRefusal( string $currency ): ConnectorError {
		return Refusal::value(
			LexwareOfficeConnector::LABEL,
			'currency',
			sprintf(
				/* translators: %s: currency code. */
				__( 'Lexware Office accepts euro only; the order is in %s.', 'estasoft-erp-crm-sync-engine' ),
				'' === $currency ? '?' : $currency
			),
			'currency not supported'
		);
	}

	/**
	 * The introduction: the reference on the first line, then the mapped text.
	 *
	 * @param string $marker Client reference.
	 * @param string $text   Mapped introduction.
	 */
	private function introduction( string $marker, string $text ): string {
		$line = 'Bestellung ' . $marker;
		return '' === $text ? $line : $line . "\n" . $text;
	}

	/**
	 * The remark: the mapped text, the coupon codes and the order note.
	 *
	 * @param OrderPayload $order Order.
	 */
	private function remark( OrderPayload $order ): string {
		$parts = array();
		foreach ( array( $order->texts['remark'], '' !== $order->coupons ? 'Gutschein: ' . $order->coupons : '', $order->note ) as $part ) {
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( "\n", $parts );
	}
}
