<?php
/**
 * Orders as Moneybird sales invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order flow: duplicate search by `reference`, contact, lines, draft invoice, read back, currency and total check, optional send (which gives the
 * invoice its number) and optional payment. Every step starts from a read, so a pass that stopped half way carries on and never repeats the create,
 * the send or the payment.
 */
final class MoneybirdDocuments {

	/**
	 * Set up the helper.
	 *
	 * @param MoneybirdContacts $contacts Contact lookups.
	 * @param MoneybirdLines    $lines    Line builder.
	 * @param Amounts           $amounts  Conversion helper.
	 */
	public function __construct(
		private readonly MoneybirdContacts $contacts,
		private readonly MoneybirdLines $lines,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * The invoice with a reference, or null (`find_by_reference` answers 404 when there is none) [documented].
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference Reference such as WC-1234.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findByReference( ApiClient $api, string $reference ): ?array {
		$response = $api->get( 'sales_invoices/find_by_reference/' . rawurlencode( $reference ) . '.json', array(), null, array( 'allow' => array( 404 ) ) );
		if ( ! $response->isSuccess() ) {
			return null;
		}
		$invoice = $response->json();
		return '' === PayloadReader::text( $invoice['id'] ?? '' ) ? null : $invoice;
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
		$reference = $item->clientReference();
		$existing  = $this->findByReference( $api, $reference );
		if ( null !== $existing ) {
			$result = $this->finish( $api, (string) $existing['id'], $order, $options, array(), false );
			return $result->isSuccess() ? SendResult::existing( $result->ref ) : $result;
		}
		$contact = $this->contacts->resolve( $api, $order->customer, $options );
		if ( $contact instanceof ConnectorError ) {
			return SendResult::failure( $contact );
		}
		$built = $this->lines->build( $api, $order, $options );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$invoice = array(
			'contact_id'          => (int) $contact['id'],
			'reference'           => $reference,
			'invoice_date'        => LocalDate::ymd( $order->date, $options->text( 'timezone' ) ),
			'prices_are_incl_tax' => $order->pricesIncludeTax,
			'currency'            => $order->currency,
			'details_attributes'  => $built['details'],
		);
		foreach ( array( 'workflow_id', 'document_style_id' ) as $key ) {
			if ( '' !== $options->text( $key ) ) {
				$invoice[ $key ] = (int) $options->text( $key );
			}
		}
		$created = $api->post( 'sales_invoices.json', array(), array( 'sales_invoice' => $invoice ) )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return SendResult::failure( Refusal::value( MoneybirdConnector::LABEL, 'invoice', __( 'Moneybird did not return the new invoice.', 'estasoft-erp-crm-sync-engine' ), 'no invoice id' ) );
		}
		return $this->finish( $api, $id, $order, $options, $built['warnings'], true );
	}

	/**
	 * The steps after the invoice exists.
	 *
	 * @param ApiClient         $api      Client.
	 * @param string            $id       Invoice id.
	 * @param OrderPayload      $order    Order.
	 * @param Options           $options  Settings.
	 * @param array<int,string> $warnings Warnings so far.
	 * @param bool              $fresh    True when this pass created the invoice (a rejected draft is then deleted again).
	 *
	 * @throws ApiException On failure.
	 */
	private function finish( ApiClient $api, string $id, OrderPayload $order, Options $options, array $warnings, bool $fresh ): SendResult {
		$invoice = $this->read( $api, $id );
		if ( null === $invoice ) {
			return SendResult::failure( Refusal::value( MoneybirdConnector::LABEL, 'invoice', __( 'Moneybird could not read the invoice back.', 'estasoft-erp-crm-sync-engine' ), 'read back failed' ) );
		}
		$problem = $this->check( $invoice, $order->currency, $order->totalMinor, $options, $warnings );
		if ( null !== $problem ) {
			if ( $fresh && 'draft' === (string) ( $invoice['state'] ?? '' ) ) {
				$api->delete( 'sales_invoices/' . rawurlencode( $id ) . '.json', array(), null, array( 'allow' => array( 404 ) ) );
			}
			return SendResult::failure( $problem );
		}
		if ( $options->is( 'invoice_state', 'sent' ) && 'draft' === (string) ( $invoice['state'] ?? '' ) ) {
			$invoice = $this->send( $api, $id ) ?? $invoice;
		}
		$account = $options->text( 'payment_account' );
		if ( '' !== $account && $order->isPaid() && 'draft' !== (string) ( $invoice['state'] ?? '' ) ) {
			$invoice = $this->pay( $api, $id, $invoice, $account, $order, $options ) ?? $invoice;
		}
		return SendResult::created( $this->ref( $invoice, $warnings ) );
	}

	/**
	 * Read an invoice.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function read( ApiClient $api, string $id ): ?array {
		$response = $api->get( 'sales_invoices/' . rawurlencode( $id ) . '.json', array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() ? $response->json() : null;
	}

	/**
	 * Send an invoice without e-mailing the customer (`delivery_method` Manual), which makes it open and gives it its number [recalled].
	 *
	 * @param ApiClient $api Client.
	 * @param string    $id  Invoice id.
	 * @return array<string,mixed>|null The invoice afterwards.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( ApiClient $api, string $id ): ?array {
		$api->patch(
			'sales_invoices/' . rawurlencode( $id ) . '/send_invoice.json',
			array(),
			array(
				'sales_invoice_sending' => array(
					'delivery_method'   => 'Manual',
					'sending_scheduled' => false,
				),
			)
		);
		return $this->read( $api, $id );
	}

	/**
	 * Compare currency and gross total with the shop's.
	 *
	 * @param array<string,mixed> $invoice  Invoice as read.
	 * @param string              $currency Expected currency (empty skips the check).
	 * @param int|null            $expected Expected total in minor units, or null for no check.
	 * @param Options             $options  Settings.
	 * @param array<int,string>   $warnings Warnings, extended when the totals differ and the setting is warn.
	 */
	public function check( array $invoice, string $currency, ?int $expected, Options $options, array &$warnings ): ?ConnectorError {
		$actualCurrency = strtoupper( PayloadReader::text( $invoice['currency'] ?? '' ) );
		if ( '' !== $currency && '' !== $actualCurrency && $actualCurrency !== $currency ) {
			return Refusal::value(
				MoneybirdConnector::LABEL,
				'currency',
				sprintf(
					/* translators: 1: currency of the order, 2: currency of the document Moneybird made. */
					__( 'The order is in %1$s but Moneybird made the invoice in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$currency,
					$actualCurrency
				),
				'currency differs'
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$exponent = $this->amounts->exponent( '' !== $actualCurrency ? $actualCurrency : $currency );
		$actual   = $this->amounts->toMinor( $invoice['total_price_incl_tax'] ?? null, $exponent );
		if ( null === $actual ) {
			return Refusal::value( MoneybirdConnector::LABEL, 'total_price_incl_tax', __( 'Moneybird returned no total for the invoice.', 'estasoft-erp-crm-sync-engine' ), 'no total' );
		}
		if ( abs( $actual - $expected ) <= 1 ) {
			return null;
		}
		if ( $options->is( 'total_mismatch', 'warn' ) ) {
			$warnings[] = 'total_differs';
			return null;
		}
		return Refusal::totals(
			MoneybirdConnector::LABEL,
			'total_price_incl_tax',
			$this->amounts->decimal( $expected, $exponent ) . ' ' . $actualCurrency,
			$this->amounts->decimal( $actual, $exponent ) . ' ' . $actualCurrency
		);
	}

	/**
	 * The reference the engine stores for a document.
	 *
	 * @param array<string,mixed> $invoice  Invoice or credit invoice.
	 * @param array<int,string>   $warnings Warnings.
	 */
	public function ref( array $invoice, array $warnings ): ExternalRef {
		$currency = strtoupper( PayloadReader::text( $invoice['currency'] ?? '' ) );
		$extra    = array(
			'total_minor' => $this->amounts->toMinor( $invoice['total_price_incl_tax'] ?? null, $this->amounts->exponent( $currency ) ),
			'currency'    => $currency,
			'state'       => PayloadReader::text( $invoice['state'] ?? '' ),
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = array_values( array_unique( $warnings ) );
		}
		$url = PayloadReader::text( $invoice['url'] ?? '' );
		return new ExternalRef( PayloadReader::text( $invoice['id'] ?? '' ), PayloadReader::text( $invoice['invoice_id'] ?? '' ), '' === $url ? null : $url, $extra );
	}

	/**
	 * Book the payment on an invoice that is not a draft, once.
	 *
	 * @param ApiClient           $api     Client.
	 * @param string              $id      Invoice id.
	 * @param array<string,mixed> $invoice Invoice as read.
	 * @param string              $account Financial account id.
	 * @param OrderPayload        $order   Order.
	 * @param Options             $options Settings.
	 * @return array<string,mixed>|null The invoice afterwards, or null when nothing was booked.
	 *
	 * @throws ApiException On failure.
	 */
	private function pay( ApiClient $api, string $id, array $invoice, string $account, OrderPayload $order, Options $options ): ?array {
		$exponent = $this->amounts->exponent( strtoupper( PayloadReader::text( $invoice['currency'] ?? $order->currency ) ) );
		$unpaid   = $this->amounts->toMinor( $invoice['total_unpaid'] ?? null, $exponent );
		if ( null === $unpaid || $unpaid <= 0 ) {
			return null;
		}
		$api->post(
			'sales_invoices/' . rawurlencode( $id ) . '/payments.json',
			array(),
			array(
				'payment' => array(
					'payment_date'         => LocalDate::ymd( $order->paymentDate, $options->text( 'timezone' ) ),
					'price'                => $this->amounts->decimal( $unpaid, $exponent ),
					'financial_account_id' => (int) $account,
				),
			)
		);
		return $this->read( $api, $id );
	}
}
