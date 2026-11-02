<?php
/**
 * Reads the mapped payload of an order or refund into an export document.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the reasons of a refusal are plain translated sentences kept in the batch; the admin screen escapes them where it shows them.

/**
 * The payload is the nested array the mapping engine builds from the target paths in LedgerFields (`document.number`,
 * `line[].net`, ...). Missing or odd values are not guessed: the document is refused with a short reason and the order stays out of the file.
 */
final class DocumentReader {

	/**
	 * Set up with the values.
	 *
	 * @param string $currency Currency of the books (ISO code). Documents in another currency are refused.
	 */
	public function __construct( private readonly string $currency ) {
	}

	/**
	 * Read a payload.
	 *
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param bool                $refund  Whether the payload is a refund.
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	public function read( array $payload, bool $refund ): ExportDocument {
		$document = $this->block( $payload, 'document' );
		$customer = $this->block( $payload, 'customer' );

		$number = Utf8::line( $this->text( $document, 'number' ) );
		if ( '' === $number ) {
			throw Refusal::because( __( 'the order number is missing', 'estasoft-erp-crm-sync-engine' ) );
		}
		$date = $this->date( $this->text( $document, 'date' ), __( 'the order date is missing', 'estasoft-erp-crm-sync-engine' ) );
		$name = Utf8::line( $this->text( $customer, 'name' ) );
		if ( '' === $name ) {
			throw Refusal::because( __( 'the customer name is empty', 'estasoft-erp-crm-sync-engine' ) );
		}
		$currency = strtoupper( trim( $this->text( $document, 'currency' ) ) );
		if ( '' !== $currency && $currency !== $this->currency ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: currency of the order, 2: currency of the books. */
					__( 'its currency (%1$s) is not the currency of the books (%2$s)', 'estasoft-erp-crm-sync-engine' ),
					$currency,
					$this->currency
				)
			);
		}
		$reference = Utf8::line( $this->text( $document, 'reference' ) );
		$total     = Amount::minor( $document['total'] ?? null );
		$excluded  = Amount::minor( $document['excluded_total'] ?? null ) ?? 0;

		$common = array(
			'number'    => $number,
			'reference' => '' === $reference ? 'WC-' . $number : $reference,
			'date'      => $date,
			'currency'  => $this->currency,
			'party'     => $name,
			'account'   => Utf8::line( $this->text( $customer, 'account' ) ),
			'customer'  => (int) ( Amount::minor( $customer['id'] ?? null ) ?? 0 ),
			'note'      => Utf8::line( $this->text( $document, 'note' ) ),
			'expected'  => null === $total ? null : $total - $excluded,
		);
		return $refund ? $this->refund( $payload, $document, $common ) : $this->order( $payload, $document, $common );
	}

	/**
	 * A sale.
	 *
	 * @param array<string,mixed> $payload  Payload.
	 * @param array<string,mixed> $document The document block.
	 * @param array<string,mixed> $common   Values both kinds share.
	 *
	 * @throws Refusal When a line cannot be read.
	 */
	private function order( array $payload, array $document, array $common ): ExportDocument {
		unset( $document );
		$lines = array();
		foreach ( $this->rows( $payload, 'line' ) as $index => $row ) {
			$quantity = Amount::quantity( $row['quantity'] ?? null );
			if ( null === $quantity ) {
				throw Refusal::because( sprintf( /* translators: %d: line number */ __( 'line %d has no valid quantity', 'estasoft-erp-crm-sync-engine' ), $index + 1 ) );
			}
			$net = Amount::minor( $row['net'] ?? null );
			if ( null === $net ) {
				throw Refusal::because( sprintf( /* translators: %d: line number */ __( 'line %d has no amount', 'estasoft-erp-crm-sync-engine' ), $index + 1 ) );
			}
			$lines[] = new ExportLine(
				Utf8::line( $this->text( $row, 'sku' ) ),
				Utf8::line( $this->text( $row, 'name' ) ),
				$quantity,
				Amount::minor( $row['unit_price'] ?? null ) ?? 0,
				$net,
				Amount::minor( $row['tax'] ?? null ) ?? 0,
				Utf8::line( $this->text( $row, 'tax_code' ) ),
				$this->rateText( $row['tax_rate'] ?? null )
			);
		}
		$payment = $this->payment( $payload );
		return new ExportDocument(
			ExportDocument::INVOICE,
			$common['number'],
			$common['reference'],
			0,
			$common['date'],
			$common['currency'],
			$common['party'],
			$common['account'],
			$common['customer'],
			$common['note'],
			$common['expected'],
			$lines,
			$this->charges( $payload, 'shipping', __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) ),
			$this->charges( $payload, 'fee', __( 'Fee', 'estasoft-erp-crm-sync-engine' ) ),
			0,
			$payment
		);
	}

	/**
	 * A refund: its lines take the tax code of the same SKU on the original order.
	 *
	 * @param array<string,mixed> $payload  Payload.
	 * @param array<string,mixed> $document The document block.
	 * @param array<string,mixed> $common   Values both kinds share.
	 *
	 * @throws Refusal When a line cannot be read or its tax code is unknown.
	 */
	private function refund( array $payload, array $document, array $common ): ExportDocument {
		$refundId = Amount::minor( $document['refund_id'] ?? null ) ?? 0;
		if ( $refundId <= 0 ) {
			throw Refusal::because( __( 'the refund number is missing', 'estasoft-erp-crm-sync-engine' ) );
		}
		$original = $this->rows( $payload, 'original_line' );
		$lines    = array();
		foreach ( $this->rows( $payload, 'refund_line' ) as $index => $row ) {
			$quantity = Amount::quantity( $row['quantity'] ?? null ) ?? '1';
			$net      = Amount::minor( $row['net'] ?? null );
			if ( null === $net ) {
				throw Refusal::because( sprintf( /* translators: %d: line number */ __( 'refund line %d has no amount', 'estasoft-erp-crm-sync-engine' ), $index + 1 ) );
			}
			$tax   = Amount::minor( $row['tax'] ?? null ) ?? 0;
			$sku   = Utf8::line( $this->text( $row, 'sku' ) );
			$found = $this->originalTax( $original, $sku );
			if ( '' === $found['code'] && $tax > 0 ) {
				throw Refusal::because( sprintf( /* translators: %d: line number */ __( 'refund line %d has tax but its tax code cannot be told from the original order', 'estasoft-erp-crm-sync-engine' ), $index + 1 ) );
			}
			$rate    = '' !== $found['rate'] ? $found['rate'] : Amount::observedRate( $net, $tax );
			$lines[] = new ExportLine(
				$sku,
				'' === $sku ? sprintf( /* translators: %d: line number */ __( 'Refund line %d', 'estasoft-erp-crm-sync-engine' ), $index + 1 ) : $sku,
				$quantity,
				0,
				$net,
				$tax,
				$found['code'],
				$rate
			);
		}
		$shipping = array();
		$gross    = Amount::minor( $this->block( $payload, 'refund_shipping' )['gross'] ?? null ) ?? 0;
		if ( 0 !== $gross ) {
			$ship       = $this->rows( $payload, 'original_shipping' );
			$code       = Utf8::line( $this->text( $ship[0] ?? array(), 'tax_code' ) );
			$rate       = Amount::rate( $ship[0]['tax_rate'] ?? null );
			$part       = null !== $rate && '' !== $code ? Amount::split( $gross, $rate ) : array(
				'net' => $gross,
				'tax' => 0,
			);
			$shipping[] = new ExportCharge( __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), $part['net'], $part['tax'], $code, null === $rate ? '' : Amount::rateText( $rate ) );
		}
		return new ExportDocument(
			ExportDocument::CREDIT_NOTE,
			$common['number'],
			$common['reference'],
			$refundId,
			$common['date'],
			$common['currency'],
			$common['party'],
			$common['account'],
			$common['customer'],
			$common['note'],
			$common['expected'],
			$lines,
			$shipping,
			array(),
			Amount::minor( $this->block( $payload, 'refund_other' )['gross'] ?? null ) ?? 0,
			null
		);
	}

	/**
	 * The payment of a paid order, or null.
	 *
	 * @param array<string,mixed> $payload Payload.
	 */
	private function payment( array $payload ): ?ExportPayment {
		$block  = $this->block( $payload, 'payment' );
		$method = Utf8::line( $this->text( $block, 'method' ) );
		$paid   = trim( $this->text( $block, 'paid_at' ) );
		if ( '' === $method || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}/', $paid ) ) {
			return null;
		}
		return new ExportPayment( $method, Utf8::line( $this->text( $block, 'title' ) ), substr( $paid, 0, 10 ), Utf8::line( $this->text( $block, 'reference' ) ) );
	}

	/**
	 * Shipping or fee lines.
	 *
	 * @param array<string,mixed> $payload  Payload.
	 * @param string              $key      Payload key.
	 * @param string              $fallback Name when the line has none.
	 * @return array<int,ExportCharge>
	 */
	private function charges( array $payload, string $key, string $fallback ): array {
		$charges = array();
		foreach ( $this->rows( $payload, $key ) as $row ) {
			$net = Amount::minor( $row['net'] ?? null ) ?? 0;
			$tax = Amount::minor( $row['tax'] ?? null ) ?? 0;
			if ( 0 === $net && 0 === $tax ) {
				continue;
			}
			$name      = Utf8::line( $this->text( $row, 'name' ) );
			$charges[] = new ExportCharge( '' === $name ? $fallback : $name, $net, $tax, Utf8::line( $this->text( $row, 'tax_code' ) ), $this->rateText( $row['tax_rate'] ?? null ) );
		}
		return $charges;
	}

	/**
	 * The tax code and rate of the original line with this SKU. Without a SKU match, the one code every original line shares.
	 *
	 * @param array<int,array<string,mixed>> $original Original order lines.
	 * @param string                         $sku      Refund line SKU.
	 * @return array{code:string,rate:string}
	 */
	private function originalTax( array $original, string $sku ): array {
		$codes = array();
		foreach ( $original as $row ) {
			$code  = Utf8::line( $this->text( $row, 'tax_code' ) );
			$rate  = $this->rateText( $row['tax_rate'] ?? null );
			$match = array(
				'code' => $code,
				'rate' => $rate,
			);
			if ( '' !== $sku && Utf8::line( $this->text( $row, 'sku' ) ) === $sku ) {
				return $match;
			}
			$codes[ $code . '|' . $rate ] = $match;
		}
		return 1 === count( $codes ) ? reset( $codes ) : array(
			'code' => '',
			'rate' => '',
		);
	}

	/**
	 * A rate in percent as text, or empty.
	 *
	 * @param mixed $value Rate from the payload.
	 */
	private function rateText( mixed $value ): string {
		$rate = Amount::rate( $value );
		return null === $rate ? '' : Amount::rateText( $rate );
	}

	/**
	 * The first ten characters of a date text as Y-m-d.
	 *
	 * @param string $text   Date or date and time.
	 * @param string $reason Reason when it is not a date.
	 *
	 * @throws Refusal When the text does not start with a date.
	 */
	private function date( string $text, string $reason ): string {
		$text = trim( $text );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $text, $found ) || ! checkdate( (int) $found[2], (int) $found[3], (int) $found[1] ) ) {
			throw Refusal::because( $reason );
		}
		return substr( $text, 0, 10 );
	}

	/**
	 * A sub-array of the payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param string              $key     Key.
	 * @return array<string,mixed>
	 */
	private function block( array $payload, string $key ): array {
		return isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ? $payload[ $key ] : array();
	}

	/**
	 * The rows of a list in the payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param string              $key     Key.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $payload, string $key ): array {
		$rows = array();
		foreach ( $this->block( $payload, $key ) as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * A value as text.
	 *
	 * @param array<string,mixed> $row Block or row.
	 * @param string              $key Key.
	 */
	private function text( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}
}
