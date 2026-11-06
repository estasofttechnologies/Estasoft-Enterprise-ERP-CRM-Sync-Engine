<?php
/**
 * Sends a WooCommerce refund to Sage 300 as an Order Entry credit note.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Text;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A credit note (`OECreditDebitNotes`, type CreditNote) with the refund number in `Reference`. It is not linked to the original invoice and
 * a person posts it in Sage 300. Its total is not compared: the property that holds it is not documented.
 */
final class Sage300RefundSender {

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage300Client      $client  Client.
	 * @param Options            $options Connection options.
	 * @param Amounts            $amounts Money helper.
	 * @param Sage300Lookups     $lookups Setup lookups.
	 * @param Sage300OrderSender $orders  Order reads.
	 * @param string             $system  System name.
	 */
	public function __construct(
		private readonly Sage300Client $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly Sage300Lookups $lookups,
		private readonly Sage300OrderSender $orders,
		private readonly string $system
	) {
	}

	/**
	 * The record of a refund that was made; null otherwise.
	 *
	 * @param Item $item The queue item (a refund).
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$note = $this->find( $item->clientReference() );
		return null === $note ? null : $this->reference( $note );
	}

	/**
	 * Send a refund.
	 *
	 * @param Item          $item    The queue item.
	 * @param RefundPayload $payload The refund.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, RefundPayload $payload ): SendResult {
		unset( $item );
		$unchecked = $this->unchecked();
		if ( $unchecked instanceof ConnectorError ) {
			return SendResult::failure( $unchecked );
		}
		$existing = $this->find( $payload->clientRef );
		if ( null === $existing ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$note = $created;
		} else {
			$note = $existing;
		}
		$warnings = array();
		$problem  = $this->verify( $note, $payload, $warnings );
		if ( $problem instanceof ConnectorError ) {
			return SendResult::failure( $problem );
		}
		$ref = $this->reference( $note, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The refusal for a connection that cannot compare credit note totals, unless the person chose to continue with a warning.
	 *
	 * The property that holds the total of a credit note is not documented, so it is a setting; without it the credit note would be made
	 * with whatever prices Sage 300 puts on it.
	 */
	public function unchecked(): ?ConnectorError {
		if ( '' !== $this->options->text( 'refund_total_property' ) || ! ( new TotalCheck( $this->options, $this->amounts, $this->system ) )->isStrict() ) {
			return null;
		}
		return Problems::refused(
			$this->system,
			'refund_total_property',
			__( 'Enter the property that holds the total of a credit note in "refund_total_property", so the credit note can be compared with the refund. Or choose "Continue with a warning" for totals that differ, and accept that refunds are not compared.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'refund total not verifiable'
		);
	}

	/**
	 * Compare the total of a credit note with the refund.
	 *
	 * @param array<string,mixed> $note     Credit note record.
	 * @param RefundPayload       $payload  The refund.
	 * @param array<int,string>   $warnings Receives warnings.
	 *
	 * @throws ApiException On failure.
	 */
	private function verify( array $note, RefundPayload $payload, array &$warnings ): ?ConnectorError {
		$name  = $this->options->text( 'refund_total_property' );
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$total = '' !== $name && isset( $note[ $name ] ) && is_numeric( $note[ $name ] ) ? abs( (float) $note[ $name ] ) : null;
		if ( null === $total ) {
			if ( $check->isStrict() ) {
				return Problems::refused(
					$this->system,
					'refund_total_property',
					__( 'The credit note was made but its answer has no value in the "refund_total_property" property, so it could not be compared with the refund. It stays in Sage 300; check the property name, or choose "Continue with a warning".', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'refund total missing'
				);
			}
			$warnings[] = __( 'The credit note total was not compared with the refund.', 'estasoft-enterprise-erpcrm-sync-engine' );
			return null;
		}
		$customer = trim( (string) ( $note['CustomerNumber'] ?? '' ) );
		$known    = '' === $customer ? null : $this->lookups->customer( $customer );
		$currency = null === $known ? '' : $known['currency'];
		$diff     = $check->difference( $payload->amountMinor, $total, $currency, trim( (string) ( $note['CreditDebitNoteNumber'] ?? $payload->clientRef ) ) );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The credit note stays in Sage 300. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}

	/**
	 * The credit note of a client reference.
	 *
	 * @param string $clientRef WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		foreach ( $this->client->rows( 'OE/OECreditDebitNotes', 'Reference eq ' . Sage300Client::literal( $clientRef ), 10 ) as $row ) {
			if ( strtolower( trim( (string) ( $row['Reference'] ?? '' ) ) ) === strtolower( $clientRef ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The record for a credit note.
	 *
	 * @param array<string,mixed> $note     Credit note record.
	 * @param array<int,string>   $warnings Warnings to carry.
	 */
	private function reference( array $note, array $warnings = array() ): ExternalRef {
		$number = isset( $note['CreditDebitNoteNumber'] ) && is_scalar( $note['CreditDebitNoteNumber'] ) ? trim( (string) $note['CreditDebitNoteNumber'] ) : '';
		$id     = isset( $note['CreditDebitNoteUniquifier'] ) && is_scalar( $note['CreditDebitNoteUniquifier'] ) && '' !== (string) $note['CreditDebitNoteUniquifier'] ? (string) $note['CreditDebitNoteUniquifier'] : ( '' !== $number ? $number : trim( (string) ( $note['Reference'] ?? '' ) ) );
		return new ExternalRef( $id, '' !== $number ? $number : $id, null, array() === $warnings ? array() : array( 'warnings' => $warnings ) );
	}

	/**
	 * Create the credit note.
	 *
	 * @param RefundPayload $payload The refund.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( RefundPayload $payload ): array|ConnectorError {
		$order = $this->orders->find( $payload->orderRef );
		if ( null === $order ) {
			return Problems::refused(
				$this->system,
				'order_ref',
				sprintf(
					/* translators: %s: order reference. */
					__( 'The order %s is not in Sage 300, so it cannot be refunded there. Send the order first.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$payload->orderRef
				),
				'order not found'
			);
		}
		$shipping = $this->options->text( 'shipping_charge_code' );
		$generic  = $this->options->text( 'refund_charge_code' );
		if ( $payload->shippingMinor > 0 && '' === $shipping ) {
			return Problems::refused( $this->system, 'shipping_charge_code', __( 'The refund includes shipping, so the connection settings need the shipping charge code.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( $payload->isAmountOnly() && ( '' === $generic || null === $payload->amountMinor || $payload->amountMinor <= 0 ) ) {
			return Problems::refused( $this->system, 'refund_line', __( 'This refund has no product lines. Enter a miscellaneous charge code for refunds without lines in the connection settings ("refund_charge_code"), or refund by line.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$codes = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		$items = $this->lookups->items( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $items[ strtolower( $code ) ] ) ) {
				return Problems::missingProduct( $this->system, $code );
			}
		}

		$currency = '';
		$customer = trim( (string) ( $order['CustomerNumber'] ?? '' ) );
		if ( '' === $customer ) {
			$customer = $this->options->text( 'customer_number' );
		}
		$known = '' === $customer ? null : $this->lookups->customer( $customer );
		if ( null !== $known ) {
			$currency = $known['currency'];
		}
		$exponent = $this->amounts->exponent( '' !== $currency ? $currency : strtoupper( $this->options->text( 'currency' ) ) );
		$location = $this->options->text( 'location' );
		$details  = array();
		foreach ( $payload->lines as $line ) {
			$item = $items[ strtolower( (string) $line['sku'] ) ];
			$unit = $this->amounts->perUnit( (int) $line['amount_minor'], (float) $line['quantity'] );
			$row  = array(
				'LineType'         => 'Item',
				'Item'             => $item['id'],
				'QuantityReturned' => (float) $line['quantity'],
			);
			if ( '' !== $location ) {
				$row['Location'] = $location;
			}
			if ( '' !== $item['unit'] ) {
				$row['CreditDebitNoteUOM'] = $item['unit'];
			}
			$row['UnitPrice'] = $this->amounts->toDecimal( $unit, $exponent );
			$details[]        = $row;
		}
		if ( $payload->shippingMinor > 0 ) {
			$details[] = $this->miscellaneous( $shipping, $payload->shippingMinor, $exponent );
		}
		if ( $payload->isAmountOnly() ) {
			$details[] = $this->miscellaneous( $generic, (int) $payload->amountMinor, $exponent );
		}

		$stored = $this->client->create(
			'OE/OECreditDebitNotes',
			array(
				'CustomerNumber'      => $customer,
				'Reference'           => $payload->clientRef,
				'Description'         => Text::fit( 'Refund of ' . $payload->orderRef . ( '' !== $payload->reason ? ': ' . $payload->reason : '' ), 60 ),
				'CreditDebitNoteDate' => '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ),
				'CreditDebitNoteType' => 'CreditNote',
				'CreditDebitDetails'  => $details,
			)
		);
		if ( array() === $stored ) {
			$found = $this->find( $payload->clientRef );
			if ( null === $found ) {
				return Problems::refused( $this->system, 'OECreditDebitNotes', __( 'Sage 300 did not return the new credit note.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			return $found;
		}
		return $stored + array( 'Reference' => $payload->clientRef );
	}

	/**
	 * A miscellaneous charge line.
	 *
	 * @param string $code     Charge code.
	 * @param int    $minor    Amount in minor units.
	 * @param int    $exponent Decimals of the currency.
	 * @return array<string,mixed>
	 */
	private function miscellaneous( string $code, int $minor, int $exponent ): array {
		return array(
			'LineType'                          => 'Miscellaneous',
			'MiscellaneousChargesCode'          => $code,
			'ExtendedAmountMiscellaneousCharge' => $this->amounts->toDecimal( $minor, $exponent ),
		);
	}
}
