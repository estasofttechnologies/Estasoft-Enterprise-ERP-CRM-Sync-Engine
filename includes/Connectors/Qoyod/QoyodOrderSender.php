<?php
/**
 * Sends a WooCommerce order to Qoyod as a sales invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Every pass starts from a read. The invoice is searched by its reference (WC-{order id}, which Qoyod keeps unique); when it exists the pass
 * carries on from where it stands, when it does not it is created as a draft. A draft is checked (total, line count, date) and only then
 * approved, so a wrong figure never reaches the ledger or the tax authority. The draft's description carries "(unchecked)" until it has
 * passed; only a document without the mark counts as finished, so a draft that failed the check is never linked by a later pass.
 */
final class QoyodOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodDocuments $docs     Document calls.
	 * @param QoyodContacts  $contacts Customers.
	 * @param QoyodCatalog   $catalog  Branches, products, tax codes.
	 * @param QoyodLines     $lines    Line builder.
	 * @param QoyodTotals    $totals   Total check.
	 * @param QoyodAmounts   $amounts  Conversion helper.
	 * @param QoyodIds       $ids      References.
	 * @param Clock          $clock    Time source.
	 */
	public function __construct(
		private readonly QoyodDocuments $docs,
		private readonly QoyodContacts $contacts,
		private readonly QoyodCatalog $catalog,
		private readonly QoyodLines $lines,
		private readonly QoyodTotals $totals,
		private readonly QoyodAmounts $amounts,
		private readonly QoyodIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The invoice of an order when the connector is finished with it: approved (or a checked draft when drafts are wanted), with its payment
	 * when payments are switched on. An invoice still waiting for its check, its approval or its payment is not returned, so send() finishes it.
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @param Item       $item       Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Connection $connection, Item $item ): ?ExternalRef {
		$options   = QoyodOptions::fromConnection( $connection );
		$reference = $this->ids->reference( $item );
		$records   = $this->docs->findInvoices( $api, $reference );
		if ( array() === $records ) {
			return null;
		}
		$record = $records[0];
		if ( $record->isUnchecked() || ! ( $record->isPosted() || ( ! $options->approve() && $record->isDraft() ) ) ) {
			return null;
		}
		if ( $options->recordPayment() && array() === $this->docs->findPayments( $api, $this->ids->paymentReference( $item ) ) ) {
			return null;
		}
		return $this->reference( $record, $options->currency(), array() );
	}

	/**
	 * Send an order.
	 *
	 * @param ApiClient           $api        Client.
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Queue item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( ApiClient $api, Connection $connection, Item $item, array $payload ): SendResult {
		$options = QoyodOptions::fromConnection( $connection );
		$parsed  = QoyodOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( '' !== $parsed->currency && $parsed->currency !== $options->currency() ) {
			return SendResult::failure(
				$this->refused(
					'currency',
					sprintf(
						/* translators: 1: currency of the order, 2: currency of the Qoyod account. */
						__( 'The order is in %1$s but the Qoyod account works in %2$s. Qoyod invoices are made in the account currency.', 'estasoft-erp-crm-sync-engine' ),
						$parsed->currency,
						$options->currency()
					)
				)
			);
		}
		$currency  = $options->currency();
		$reference = $this->ids->reference( $item );
		$found     = $this->docs->findInvoices( $api, $reference );
		$warnings  = array();
		$lineCount = null;

		if ( array() !== $found ) {
			$existed = true;
			$record  = $this->docs->invoice( $api, $found[0]->id() );
		} else {
			$existed = false;
			$made    = $this->create( $api, $connection, $options, $parsed, $reference );
			if ( $made instanceof ConnectorError ) {
				return SendResult::failure( $made );
			}
			if ( null === $made['record'] ) {
				$again   = $this->docs->findInvoices( $api, $reference );
				$existed = true;
				$record  = array() !== $again ? $this->docs->invoice( $api, $again[0]->id() ) : null;
				if ( null === $record ) {
					return SendResult::failure( $this->refused( 'reference', __( 'Qoyod says the reference is taken, but no invoice has it.', 'estasoft-erp-crm-sync-engine' ) ) );
				}
			} else {
				$record    = $made['record'];
				$lineCount = $made['lines'];
			}
		}

		$settled = $this->settle( $api, $options, $parsed, $currency, $record, $reference, $lineCount, $warnings );
		if ( $settled instanceof ConnectorError ) {
			return SendResult::failure( $settled );
		}
		$record = $settled;

		$paid = $this->pay( $api, $options, $item, $parsed, $currency, $record, $warnings );
		if ( $paid instanceof ConnectorError ) {
			return SendResult::failure( $paid );
		}

		$ref = $this->reference( $record, $currency, $warnings );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Create the invoice as a draft. Products, taxes, the branch and the customer are settled first: nothing is created until they are.
	 *
	 * @param ApiClient         $api        Client.
	 * @param Connection        $connection Connection.
	 * @param QoyodOptions      $options    Options.
	 * @param QoyodOrderPayload $parsed     Payload.
	 * @param string            $reference  WC-{id}.
	 * @return array{record:QoyodRecord|null,lines:int}|ConnectorError A null record means Qoyod says the reference is taken.
	 *
	 * @throws ApiException On failure other than a taken reference.
	 */
	private function create( ApiClient $api, Connection $connection, QoyodOptions $options, QoyodOrderPayload $parsed, string $reference ): array|ConnectorError {
		$branch = $this->catalog->branchId( $api, $connection->id, $options->inventoryId() );
		if ( $branch instanceof ConnectorError ) {
			return $branch;
		}
		$rows = $this->rows( $api, $options, $parsed );
		if ( $rows instanceof ConnectorError ) {
			return $rows;
		}
		$built = $this->lines->build( $rows, $options->currency(), $parsed->pricesIncludeTax, $options->taxField(), $branch, $this->catalog->standardTaxes() );
		if ( $built instanceof ConnectorError ) {
			return $built;
		}
		$contact = $this->contacts->resolve( $api, $parsed->customer );
		if ( $contact instanceof ConnectorError ) {
			return $contact;
		}
		$date = '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' );
		$body = array(
			'contact_id'            => (int) $contact,
			'reference'             => $reference,
			'issue_date'            => $date,
			'due_date'              => $date,
			'status'                => 'Draft',
			'inventory_id'          => (int) $branch,
			'description'           => $this->describe( $reference, $parsed->note, true ),
			'line_items_attributes' => $built['lines'],
		);
		try {
			$created = $this->docs->createInvoice( $api, $body );
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Dup001 === $exception->error()->code ) {
				return array(
					'record' => null,
					'lines'  => 0,
				);
			}
			throw $exception;
		}
		if ( $created instanceof ConnectorError ) {
			return $created;
		}
		return array(
			'record' => $this->docs->invoice( $api, $created->id() ),
			'lines'  => count( $built['lines'] ),
		);
	}

	/**
	 * The rows of the invoice: product lines with their Qoyod product, then shipping and fees on the service products named in the options.
	 *
	 * @param ApiClient         $api     Client.
	 * @param QoyodOptions      $options Options.
	 * @param QoyodOrderPayload $parsed  Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, QoyodOptions $options, QoyodOrderPayload $parsed ): array|ConnectorError {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' === (string) $line['item'] ) {
				$found = '' !== (string) $line['sku'] ? $this->catalog->productBySku( $api, (string) $line['sku'] ) : null;
				if ( null === $found || '' === QoyodPayloadReader::text( $found['id'] ?? '' ) ) {
					return $this->unknownSku( '' !== (string) $line['sku'] ? (string) $line['sku'] : (string) $line['name'] );
				}
				$line['item'] = QoyodPayloadReader::text( $found['id'] );
			}
			$rows[] = $line;
		}
		foreach ( $parsed->extras as $extra ) {
			$isShipping = 'shipping' === $extra['kind'];
			$sku        = $isShipping ? $options->shippingSku() : $options->feeSku();
			if ( '' === $sku ) {
				return $this->refused(
					$isShipping ? 'shipping_product_sku' : 'fee_product_sku',
					__( 'Qoyod has no lines without a product. Name a service product for shipping and fees in the connection settings.', 'estasoft-erp-crm-sync-engine' )
				);
			}
			$product = $this->catalog->productBySku( $api, $sku );
			if ( null === $product ) {
				return $this->unknownSku( $sku );
			}
			$extra['item'] = QoyodPayloadReader::text( $product['id'] ?? '' );
			$rows[]        = $extra;
		}
		return $rows;
	}

	/**
	 * Check a draft, then approve it. A posted invoice is left alone.
	 *
	 * @param ApiClient         $api       Client.
	 * @param QoyodOptions      $options   Options.
	 * @param QoyodOrderPayload $parsed    Payload.
	 * @param string            $currency  Account currency.
	 * @param QoyodRecord       $record    The invoice as Qoyod reports it.
	 * @param string            $reference WC-{id}.
	 * @param int|null          $lineCount Lines sent when this pass created the invoice, else null.
	 * @param array<int,string> $warnings  Warnings (added to).
	 * @return QoyodRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function settle( ApiClient $api, QoyodOptions $options, QoyodOrderPayload $parsed, string $currency, QoyodRecord $record, string $reference, ?int $lineCount, array &$warnings ): QoyodRecord|ConnectorError {
		if ( ! $record->isDraft() || ! $record->isUnchecked() ) {
			return $record;
		}
		$failure = $this->totals->check( $record, $currency, $parsed->totalMinor, $options->failOnMismatch(), $warnings );
		if ( null !== $failure ) {
			return $failure;
		}
		if ( null !== $lineCount && null !== $record->lineCount() && $record->lineCount() !== $lineCount ) {
			return $this->refused(
				'line_items',
				sprintf(
					/* translators: 1: number of lines Qoyod kept, 2: number of lines sent. */
					__( 'Qoyod kept %1$d lines of the %2$d that were sent.', 'estasoft-erp-crm-sync-engine' ),
					$record->lineCount(),
					$lineCount
				)
			);
		}
		$changes = array( 'description' => $this->describe( $reference, $parsed->note, false ) );
		if ( '' !== $parsed->date && '' !== $record->date() && $record->date() !== $parsed->date ) {
			$changes['issue_date'] = $parsed->date;
			$changes['due_date']   = $parsed->date;
		}
		$approve = ( $options->approve() && 'draft' !== $parsed->flowInvoice ) || 'approved' === $parsed->flowInvoice;
		if ( $approve ) {
			$changes['status'] = 'Approved';
		}
		$this->docs->patchInvoice( $api, $record->id(), $changes );
		$after = $this->docs->invoice( $api, $record->id() );
		if ( $approve && ! $after->isPosted() ) {
			return $this->refused( 'status', __( 'Qoyod did not approve the invoice.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( '' !== $parsed->date && '' !== $after->date() && $after->date() !== $parsed->date ) {
			$warnings[] = __( 'Qoyod changed the date of the invoice.', 'estasoft-erp-crm-sync-engine' );
		}
		return $after;
	}

	/**
	 * Record the payment of a paid order against the approved invoice.
	 *
	 * @param ApiClient         $api      Client.
	 * @param QoyodOptions      $options  Options.
	 * @param Item              $item     Queue item.
	 * @param QoyodOrderPayload $parsed   Payload.
	 * @param string            $currency Account currency.
	 * @param QoyodRecord       $record   The approved invoice.
	 * @param array<int,string> $warnings Warnings (added to).
	 * @return ConnectorError|null
	 *
	 * @throws ApiException On failure.
	 */
	private function pay( ApiClient $api, QoyodOptions $options, Item $item, QoyodOrderPayload $parsed, string $currency, QoyodRecord $record, array &$warnings ): ?ConnectorError {
		$wanted = $parsed->flowPayment ?? $options->recordPayment();
		if ( ! $wanted || ! $parsed->paid || ! $record->isPosted() ) {
			return null;
		}
		$reference = $this->ids->paymentReference( $item );
		if ( array() !== $this->docs->findPayments( $api, $reference ) ) {
			return null;
		}
		if ( '' === $options->paymentAccountId() ) {
			return $this->refused( 'payment_account_id', __( 'Choose the payment account in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$exponent = $this->amounts->exponent( $currency );
		$total    = $record->totalMinor( $exponent ) ?? $parsed->totalMinor;
		if ( null === $total || $total <= 0 ) {
			$warnings[] = __( 'The payment was not recorded because the invoice total is not known.', 'estasoft-erp-crm-sync-engine' );
			return null;
		}
		$this->docs->createPayment(
			$api,
			array(
				'account_id' => $options->paymentAccountId(),
				'amount'     => $this->amounts->decimalString( $total, $exponent ),
				'date'       => '' !== $parsed->paymentDate ? $parsed->paymentDate : $this->clock->now()->format( 'Y-m-d' ),
				'invoice_id' => $record->id(),
				'reference'  => $reference,
			)
		);
		return null;
	}

	/**
	 * The description of a document: the reference, the "(unchecked)" mark while it has not passed the check, then the order note.
	 *
	 * @param string $reference WC-{id}.
	 * @param string $note      Order note.
	 * @param bool   $unchecked Whether the mark is added.
	 */
	private function describe( string $reference, string $note, bool $unchecked ): string {
		$text = $reference . ( $unchecked ? QoyodIds::UNCHECKED : '' );
		return '' !== $note ? $text . "\n" . $note : $text;
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param QoyodRecord       $record   Invoice.
	 * @param string            $currency Account currency.
	 * @param array<int,string> $warnings Warnings.
	 */
	private function reference( QoyodRecord $record, string $currency, array $warnings ): ExternalRef {
		$extra = array(
			'currency' => $currency,
			'state'    => $record->isPosted() ? 'approved' : 'draft',
		);
		$total = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $record->id(), $record->reference(), null, $extra );
	}

	/**
	 * SYNC-MAP-001 for a product Qoyod does not have.
	 *
	 * @param string $sku SKU or the name of the line.
	 */
	private function unknownSku( string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'sku'    => $sku,
				'system' => QoyodConnector::LABEL,
			),
			null,
			null,
			null,
			'sku not found'
		);
	}

	/**
	 * A SYNC-VAL-002.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 */
	private function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'refused'
		);
	}
}
