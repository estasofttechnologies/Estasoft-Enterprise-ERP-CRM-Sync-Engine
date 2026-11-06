<?php
/**
 * Orders as sevDesk invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Tolerance;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order flow: contact, duplicate search, positions, draft invoice (`Invoice/Factory/saveInvoice`), read back, total check, optional
 * "sent" status and optional payment. Every step starts from a read, so a pass that stopped half way carries on and never repeats the create,
 * the status change or the payment.
 */
final class SevdeskDocuments {

	/**
	 * Draft status [documented].
	 */
	public const DRAFT = 100;

	/**
	 * Open status (sent, not yet paid) [documented].
	 */
	public const OPEN = 200;

	/**
	 * Paid status [documented].
	 */
	public const PAID = 1000;

	/**
	 * Build the helper.
	 *
	 * @param SevdeskContacts  $contacts  Contact lookups.
	 * @param SevdeskLines     $lines     Position builder.
	 * @param SevdeskSearch    $search    Duplicate search.
	 * @param SevdeskReference $reference Reference data.
	 * @param Amounts          $amounts   Conversion helper.
	 */
	public function __construct(
		private readonly SevdeskContacts $contacts,
		private readonly SevdeskLines $lines,
		private readonly SevdeskSearch $search,
		private readonly SevdeskReference $reference,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * Send an order.
	 *
	 * @param ApiClient    $api        Client.
	 * @param Connection   $connection Connection.
	 * @param Item         $item       Queue item.
	 * @param OrderPayload $order      Mapped order.
	 * @param Options      $options    Settings.
	 *
	 * @throws ApiException On failure.
	 */
	public function sendOrder( ApiClient $api, Connection $connection, Item $item, OrderPayload $order, Options $options ): SendResult {
		if ( 'EUR' !== $order->currency ) {
			return SendResult::failure( $this->currencyRefusal( $order->currency ) );
		}
		$contact = $this->contacts->resolve( $api, $order->customer, $options, $item->clientReference() );
		if ( $contact instanceof ConnectorError ) {
			return SendResult::failure( $contact );
		}
		$timezone = $options->text( 'timezone' );
		$marker   = $item->clientReference();
		$warnings = $contact['warnings'];
		$existing = $this->search->find( $api, 'Invoice', $contact['id'], $marker, $order->date, $timezone );
		if ( null !== $existing ) {
			$id     = (string) $existing['id'];
			$result = $this->finish( $api, $id, $order, $options, $warnings, false );
			return $result->isSuccess() ? SendResult::existing( $result->ref ) : $result;
		}
		$version = $this->reference->version( $api, $connection );
		$built   = $this->lines->build( $api, $order, $options, $version );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$userId    = $this->reference->userId( $api );
		$countryId = $this->reference->countryId( $api, $order->customer['country'] );
		if ( '' === $userId || '' === $countryId ) {
			return SendResult::failure(
				Refusal::value(
					SevdeskConnector::LABEL,
					'' === $userId ? 'contactPerson' : 'addressCountry',
					'' === $userId ? __( 'sevDesk has no user to name as contact person.', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'sevDesk does not know the country of the billing address.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reference data missing'
				)
			);
		}
		$date    = LocalDate::dmy( $order->date, $timezone );
		$invoice = array(
			'objectName'           => 'Invoice',
			'mapAll'               => true,
			'invoiceType'          => 'RE',
			'status'               => self::DRAFT,
			'contact'              => SevdeskJson::ref( $contact['id'], 'Contact' ),
			'contactPerson'        => SevdeskJson::ref( $userId, 'SevUser' ),
			'invoiceDate'          => $date,
			'deliveryDate'         => $date,
			'discount'             => 0,
			'addressCountry'       => SevdeskJson::ref( $countryId, 'StaticCountry' ),
			'address'              => $this->address( $order->customer ),
			'currency'             => 'EUR',
			'showNet'              => true,
			'smallSettlement'      => $options->is( 'small_settlement', 'yes' ),
			'taxText'              => $built['tax_text'],
			'taxRate'              => 0,
			'header'               => '' !== $order->texts['header'] ? $order->texts['header'] . ' ' . $marker : 'Rechnung zu Bestellung ' . $marker,
			'headText'             => $order->texts['introduction'],
			'footText'             => $this->footText( $order ),
			'customerInternalNote' => $marker,
			'timeToPay'            => $options->int( 'time_to_pay' ),
		);
		if ( '1.0' === $version ) {
			$invoice['taxType'] = $built['code'];
		} else {
			$invoice['taxRule'] = array(
				'id'         => $built['code'],
				'objectName' => 'TaxRule',
			);
		}
		$body    = array(
			'invoice'            => $invoice,
			'invoicePosSave'     => $built['positions'],
			'invoicePosDelete'   => null,
			'discountSave'       => array(),
			'discountDelete'     => null,
			'takeDefaultAddress' => false,
		);
		$created = $api->post( 'Invoice/Factory/saveInvoice', array(), $body )->json();
		$objects = (array) ( $created['objects'] ?? array() );
		$id      = (string) ( $objects['invoice']['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( SevdeskConnector::LABEL, 'invoice', __( 'sevDesk did not return the new invoice.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no invoice id' ) );
		}
		$warnings = array_merge( $warnings, $built['warnings'] );
		return $this->finish( $api, $id, $order, $options, $warnings, true );
	}

	/**
	 * The steps after the invoice exists: read back, check currency and total, mark as sent, book the payment.
	 *
	 * @param ApiClient         $api      Client.
	 * @param string            $id       Invoice id.
	 * @param OrderPayload      $order    Order.
	 * @param Options           $options  Settings.
	 * @param array<int,string> $warnings Warnings so far.
	 * @param bool              $fresh    True when this pass created the invoice (a rejected draft is then removed again).
	 *
	 * @throws ApiException On failure.
	 */
	private function finish( ApiClient $api, string $id, OrderPayload $order, Options $options, array $warnings, bool $fresh ): SendResult {
		$invoice = $this->read( $api, $id );
		if ( null === $invoice ) {
			return SendResult::failure( Refusal::value( SevdeskConnector::LABEL, 'invoice', __( 'sevDesk could not read the invoice back.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'read back failed' ) );
		}
		$problem = $this->check( $invoice, $order->totalMinor, $options, $warnings, $order->rows(), $order->date );
		if ( null !== $problem ) {
			if ( $fresh && self::DRAFT === (int) ( $invoice['status'] ?? 0 ) ) {
				$api->delete( 'Invoice/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
			}
			return SendResult::failure( $problem );
		}
		$wantsOpen = $options->is( 'invoice_state', 'open' );
		if ( $wantsOpen && self::DRAFT === (int) ( $invoice['status'] ?? 0 ) ) {
			$api->put(
				'Invoice/' . rawurlencode( $id ) . '/sendBy',
				array(),
				array(
					'sendType'  => 'VPR',
					'sendDraft' => false,
				)
			);
			$invoice = $this->read( $api, $id ) ?? $invoice;
			if ( self::DRAFT === (int) ( $invoice['status'] ?? 0 ) ) {
				$warnings[] = 'stays_draft';
			}
			if ( LocalDate::differs( $invoice['invoiceDate'] ?? null, $order->date, $options->text( 'timezone' ) ) ) {
				$warnings[] = 'date_differs';
			}
		}
		$account = $options->text( 'payment_account' );
		if ( '' !== $account && $order->isPaid() && (int) ( $invoice['status'] ?? 0 ) === self::OPEN ) {
			$date = $this->paymentDate( $order, $options );
			if ( null === $date ) {
				$warnings[] = 'payment_date_unreadable';
			} else {
				$invoice = $this->book( $api, $id, $invoice, $account, $date ) ?? $invoice;
			}
		}
		return SendResult::created( $this->ref( $invoice, $warnings ) );
	}

	/**
	 * Read an invoice: `GET /Invoice/{id}` answers a list with one element or the object itself.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function read( ApiClient $api, string $id ): ?array {
		$response = $api->get( 'Invoice/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() ? SevdeskJson::first( $response->json() ) : null;
	}

	/**
	 * Compare currency and gross total with the shop's.
	 *
	 * @param array<string,mixed> $document Invoice or credit note.
	 * @param int|null            $expected Expected total in minor units, or null for no check.
	 * @param Options             $options  Settings.
	 * @param array<int,string>   $warnings Warnings, extended when the totals differ and the setting is warn, or when the date differs.
	 * @param int                 $rows     Rows of the document, for the default tolerance.
	 * @param string              $utcDate  The date (UTC text) the connector converted into the document date, or empty for no date check.
	 */
	public function check( array $document, ?int $expected, Options $options, array &$warnings, int $rows = 1, string $utcDate = '' ): ?ConnectorError {
		$currency = strtoupper( PayloadReader::text( $document['currency'] ?? 'EUR' ) );
		if ( 'EUR' !== $currency ) {
			return $this->currencyRefusal( $currency );
		}
		if ( '' !== $utcDate && LocalDate::differs( $document['invoiceDate'] ?? $document['creditNoteDate'] ?? null, $utcDate, $options->text( 'timezone' ) ) ) {
			$warnings[] = 'date_differs';
		}
		if ( null === $expected ) {
			return null;
		}
		$actual = SevdeskJson::minor( $document['sumGross'] ?? null );
		if ( null === $actual ) {
			return Refusal::value( SevdeskConnector::LABEL, 'sumGross', __( 'sevDesk returned no total for the document.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no total' );
		}
		if ( Tolerance::accepts( $actual, $expected, $options, $rows ) ) {
			return null;
		}
		if ( $options->is( 'total_mismatch', 'warn' ) ) {
			$warnings[] = 'total_differs';
			return null;
		}
		return Refusal::totals( SevdeskConnector::LABEL, 'sumGross', $this->amounts->decimal( $expected, 2 ) . ' EUR', $this->amounts->decimal( $actual, 2 ) . ' EUR' );
	}

	/**
	 * The booking date for `bookAmount` as a whole number: yyyymmdd by default, a Unix timestamp of local midnight with the option
	 * `payment_date_format` = `unix`. Null when the order's payment date cannot be read.
	 *
	 * @param OrderPayload $order   Order.
	 * @param Options      $options Settings.
	 */
	public function paymentDate( OrderPayload $order, Options $options ): ?int {
		$timezone = $options->text( 'timezone' );
		return $options->is( 'payment_date_format', 'unix' )
			? LocalDate::midnightTimestamp( $order->paymentDate, $timezone )
			: LocalDate::ymdNumber( $order->paymentDate, $timezone );
	}

	/**
	 * Book the payment on an open invoice, once. The invoice is read again first, so a booking that went through but whose answer was lost (or a
	 * payment a person entered meanwhile) is seen and nothing is booked twice.
	 *
	 * @param ApiClient           $api     Client.
	 * @param string              $id      Invoice id.
	 * @param array<string,mixed> $invoice Invoice as read earlier in the pass.
	 * @param string              $account Bank account id.
	 * @param int                 $date    Booking date as `paymentDate()` gives it.
	 * @return array<string,mixed>|null The invoice after booking, or null when nothing was booked.
	 *
	 * @throws ApiException On failure.
	 */
	private function book( ApiClient $api, string $id, array $invoice, string $account, int $date ): ?array {
		$invoice = $this->read( $api, $id ) ?? $invoice;
		$total   = SevdeskJson::minor( $invoice['sumGross'] ?? null );
		$paid    = SevdeskJson::minor( $invoice['paidAmount'] ?? 0 ) ?? 0;
		if ( self::OPEN !== (int) ( $invoice['status'] ?? 0 ) || null === $total || $paid >= $total ) {
			return null;
		}
		$api->put(
			'Invoice/' . rawurlencode( $id ) . '/bookAmount',
			array(),
			array(
				'amount'       => $this->amounts->number( $this->amounts->decimal( $total - $paid, 2 ) ),
				'date'         => $date,
				'type'         => 'FULL_PAYMENT',
				'checkAccount' => SevdeskJson::ref( $account, 'CheckAccount' ),
				'createFeed'   => false,
			)
		);
		return $this->read( $api, $id );
	}

	/**
	 * The reference the engine stores for a document.
	 *
	 * @param array<string,mixed> $document Invoice or credit note.
	 * @param array<int,string>   $warnings Warnings.
	 */
	public function ref( array $document, array $warnings ): ExternalRef {
		$extra = array(
			'total_minor' => SevdeskJson::minor( $document['sumGross'] ?? null ),
			'currency'    => 'EUR',
			'status'      => (int) ( $document['status'] ?? 0 ),
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = array_values( array_unique( $warnings ) );
		}
		$number = PayloadReader::text( $document['invoiceNumber'] ?? $document['creditNoteNumber'] ?? '' );
		return new ExternalRef( PayloadReader::text( $document['id'] ?? '' ), $number, null, $extra );
	}

	/**
	 * SYNC-VAL-002 for a currency other than euro.
	 *
	 * @param string $currency Currency of the order.
	 */
	public function currencyRefusal( string $currency ): ConnectorError {
		return Refusal::value(
			SevdeskConnector::LABEL,
			'currency',
			sprintf(
				/* translators: %s: currency code. */
				__( 'The sevDesk connector supports euro only; the document is in %s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'' === $currency ? '?' : $currency
			),
			'currency not supported'
		);
	}

	/**
	 * The billing address as the text sevDesk prints on the invoice.
	 *
	 * @param array<string,string> $customer Customer block.
	 */
	public function address( array $customer ): string {
		$lines = array( '' !== $customer['company'] ? $customer['company'] : $customer['name'] );
		if ( '' !== $customer['company'] && '' !== trim( $customer['first_name'] . $customer['last_name'] ) ) {
			$lines[] = trim( $customer['first_name'] . ' ' . $customer['last_name'] );
		}
		$lines[] = $customer['street'];
		$lines[] = $customer['supplement'];
		$lines[] = trim( $customer['zip'] . ' ' . $customer['city'] );
		return implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * The text below the items: the mapped text, the coupon codes and the order note.
	 *
	 * @param OrderPayload $order Order.
	 */
	private function footText( OrderPayload $order ): string {
		$parts = array();
		foreach ( array( $order->texts['remark'], '' !== $order->coupons ? 'Gutschein: ' . $order->coupons : '', $order->note ) as $part ) {
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( "\n", $parts );
	}
}
