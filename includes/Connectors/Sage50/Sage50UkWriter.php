<?php
/**
 * Writes the Sage 50 Accounts (UK and Ireland) Audit Trail Transactions rows of one order or refund.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage50;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the reasons of a refusal are plain translated sentences kept in the batch; the admin screen escapes them where it shows them.

use Estasoft\SyncEngine\Connectors\ExportShared\Amount;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\Refusal;
use Estasoft\SyncEngine\Connectors\ExportShared\SafeText;

/**
 * One row for every product, shipping and fee line, with its nominal code, tax code and tax amount (always written). All rows of an
 * order share type, account reference, date and Reference, which is what the Grouped import merges into one transaction. A sale is
 * `SI`, a refund `SC` (amounts stay positive), a payment `SR`.
 */
final class Sage50UkWriter {

	public const HEADINGS = array( 'Type', 'Account Reference', 'Nominal A/C Code', 'Department Code', 'Date', 'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount', 'Exchange Rate', 'Extra Reference', 'User Name' );

	/**
	 * Net Amount holds up to 8 digits before the decimals.
	 */
	private const MAX_INTEGER_DIGITS = 8;

	/**
	 * The columns that hold free text: Reference, Details, Extra Reference and User Name (zero-based). Every other column is a type, a
	 * code, a date or an amount and is written as it is.
	 */
	private const TEXT_COLUMNS = array( 5, 6, 11, 12 );

	/**
	 * The rows of a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param Sage50Settings $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int}
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	public function render( ExportDocument $document, Sage50Settings $settings ): array {
		$account = strtoupper( '' !== $document->partyAccount ? $document->partyAccount : $settings->customerAccount() );
		if ( 1 !== preg_match( '/^[A-Z0-9]{1,8}$/', $account ) ) {
			throw Refusal::because(
				sprintf(
					/* translators: %s: account reference */
					__( 'account reference "%s" is not 1 to 8 letters or digits', 'estasoft-erp-crm-sync-engine' ),
					$account
				)
			);
		}
		$reference = SafeText::safe( $document->voucherNumber() );
		if ( strlen( $reference ) > 30 ) {
			throw Refusal::because( __( 'the order number is longer than 30 characters', 'estasoft-erp-crm-sync-engine' ) );
		}

		$rows = array();
		foreach ( $document->lines as $index => $line ) {
			$what   = sprintf( /* translators: %d: line number */ __( 'line %d', 'estasoft-erp-crm-sync-engine' ), $index + 1 );
			$code   = $this->code( $line->taxCode, $line->tax, $what, $settings );
			$rows[] = array(
				'nominal' => '' !== $code['nominal'] ? $code['nominal'] : $settings->salesNominal(),
				'details' => '' !== $line->name ? $line->name : ( '' !== $line->sku ? $line->sku : __( 'Product', 'estasoft-erp-crm-sync-engine' ) ),
				'net'     => $line->net,
				'tax'     => $line->tax,
				'code'    => $code['code'],
			);
		}
		foreach ( $document->shipping as $charge ) {
			$code   = $this->code( $charge->taxCode, $charge->tax, __( 'shipping', 'estasoft-erp-crm-sync-engine' ), $settings );
			$rows[] = array(
				'nominal' => '' !== $settings->shippingNominal() ? $settings->shippingNominal() : ( '' !== $code['nominal'] ? $code['nominal'] : $settings->salesNominal() ),
				'details' => $charge->name,
				'net'     => $charge->net,
				'tax'     => $charge->tax,
				'code'    => $code['code'],
			);
		}
		foreach ( $document->fees as $charge ) {
			$code   = $this->code( '' !== $charge->taxCode ? $charge->taxCode : $settings->feeTaxCode(), $charge->tax, __( 'fee', 'estasoft-erp-crm-sync-engine' ), $settings );
			$rows[] = array(
				'nominal' => '' !== $code['nominal'] ? $code['nominal'] : $settings->salesNominal(),
				'details' => $charge->name,
				'net'     => $charge->net,
				'tax'     => $charge->tax,
				'code'    => $code['code'],
			);
		}
		if ( 0 !== $document->adjustment ) {
			$nominal = $settings->adjustmentAccount();
			if ( 1 !== preg_match( '/^\d{1,8}$/', $nominal ) ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: amount */
						__( 'refund amount %s has no adjustment nominal', 'estasoft-erp-crm-sync-engine' ),
						Amount::decimal( $document->adjustment, $document->currency )
					)
				);
			}
			$code   = $this->code( '', 0, __( 'refund adjustment', 'estasoft-erp-crm-sync-engine' ), $settings );
			$rows[] = array(
				'nominal' => $nominal,
				'details' => __( 'Refund adjustment', 'estasoft-erp-crm-sync-engine' ),
				'net'     => $document->adjustment,
				'tax'     => 0,
				'code'    => $code['code'],
			);
		}

		$gross = $document->gross();
		$sum   = 0;
		foreach ( $rows as $row ) {
			$sum += $row['net'] + $row['tax'];
		}
		if ( 0 === $gross && 0 === $sum ) {
			throw Refusal::because( __( 'the total is zero, so there is nothing to post', 'estasoft-erp-crm-sync-engine' ) );
		}
		$diff = $gross - $sum;
		if ( abs( $diff ) > 1 ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: sum of the lines, 2: total of the order */
					__( 'the lines add up to %1$s but the order total is %2$s', 'estasoft-erp-crm-sync-engine' ),
					Amount::decimal( $sum, $document->currency ),
					Amount::decimal( $gross, $document->currency )
				)
			);
		}
		if ( 0 !== $diff ) {
			$largest = 0;
			foreach ( $rows as $index => $row ) {
				if ( abs( $row['net'] ) > abs( $rows[ $largest ]['net'] ) ) {
					$largest = $index;
				}
			}
			$rows[ $largest ]['net'] += $diff;
		}

		return $this->text( $document, $settings, $account, $reference, $rows );
	}

	/**
	 * The CSV text of the rows, with the masters they use.
	 *
	 * @param ExportDocument                                                              $document  Document.
	 * @param Sage50Settings                                                              $settings  Settings.
	 * @param string                                                                      $account   Account reference.
	 * @param string                                                                      $reference Reference.
	 * @param array<int,array{nominal:string,details:string,net:int,tax:int,code:string}> $rows      Rows.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int}
	 *
	 * @throws Refusal When an amount is too large for the field.
	 */
	private function text( ExportDocument $document, Sage50Settings $settings, string $account, string $reference, array $rows ): array {
		$currency = $document->currency;
		$type     = $document->isCredit() ? 'SC' : 'SI';
		$date     = $this->date( $document->date, $settings->dateFormat() );
		$cut      = 0;
		$fitted   = SafeText::fit( $document->reference, 30 );
		$extra    = $fitted['text'];
		$cut     += $fitted['cut'];
		$lines    = array();
		$nominals = array();
		$codes    = array();
		foreach ( $rows as $row ) {
			$this->assertFits( $row['net'], $currency );
			$this->assertFits( $row['tax'], $currency );
			$fitted     = SafeText::fit( $row['details'], 60 );
			$details    = $fitted['text'];
			$cut       += $fitted['cut'];
			$lines[]    = Sage50Csv::line(
				array(
					$type,
					$account,
					$row['nominal'],
					$settings->department(),
					$date,
					$reference,
					$details,
					Amount::decimal( $row['net'], $currency ),
					$row['code'],
					Amount::decimal( $row['tax'], $currency ),
					'1',
					$extra,
					$settings->userName(),
				),
				self::TEXT_COLUMNS
			);
			$nominals[] = $row['nominal'];
			$codes[]    = $row['code'];
		}
		if ( $settings->recordPayments() && ! $document->isCredit() && null !== $document->payment ) {
			$bank = $settings->paymentAccount( $document->payment->method );
			if ( '' === $bank ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: payment method such as Tabby */
						__( 'payment method %s has no bank nominal', 'estasoft-erp-crm-sync-engine' ),
						'' === $document->payment->title ? $document->payment->method : $document->payment->title
					)
				);
			}
			if ( 1 !== preg_match( '/^\d{1,8}$/', $bank ) ) {
				throw Refusal::because( __( 'the bank nominal of the payment method is not a number of up to 8 digits', 'estasoft-erp-crm-sync-engine' ) );
			}
			$this->assertFits( $document->gross(), $currency );
			$lines[]    = Sage50Csv::line(
				array(
					'SR',
					$account,
					$bank,
					$settings->department(),
					$this->date( $document->payment->date, $settings->dateFormat() ),
					$reference,
					SafeText::fit( sprintf( /* translators: %s: payment method title */ __( 'Payment %s', 'estasoft-erp-crm-sync-engine' ), '' === $document->payment->title ? $document->payment->method : $document->payment->title ), 60 )['text'],
					Amount::decimal( $document->gross(), $currency ),
					'T9',
					Amount::decimal( 0, $currency ),
					'1',
					$extra,
					$settings->userName(),
				),
				self::TEXT_COLUMNS
			);
			$nominals[] = $bank;
			$codes[]    = 'T9';
		}
		return array(
			'text'    => implode( "\n", $lines ),
			'masters' => array(
				'accounts'  => array( $account ),
				'nominals'  => array_values( array_unique( $nominals ) ),
				'tax_codes' => array_values( array_unique( $codes ) ),
			),
			'cut'     => $cut,
		);
	}

	/**
	 * Read a Sage tax code, optionally followed by a colon and a nominal code for this rate.
	 *
	 * @param string         $code     "T1" or "T1:4001", or empty.
	 * @param int            $tax      Tax of the line.
	 * @param string         $what     Words for the line in messages.
	 * @param Sage50Settings $settings Settings.
	 * @return array{code:string,nominal:string}
	 *
	 * @throws Refusal When the code is missing or not a Sage tax code.
	 */
	private function code( string $code, int $tax, string $what, Sage50Settings $settings ): array {
		if ( '' === $code ) {
			if ( 0 !== $tax ) {
				throw Refusal::because( sprintf( /* translators: %s: line, for example "line 2" or "shipping" */ __( '%s has tax but no tax code', 'estasoft-erp-crm-sync-engine' ), $what ) );
			}
			$code = $settings->untaxedCode();
		}
		if ( 1 !== preg_match( '/^[Tt](\d{1,2})(?::(\d{1,8}))?$/', trim( $code ), $found ) ) {
			throw Refusal::because( sprintf( /* translators: %s: tax code */ __( 'tax code "%s" is not a Sage tax code (T0 to T99)', 'estasoft-erp-crm-sync-engine' ), $code ) );
		}
		return array(
			'code'    => 'T' . (int) $found[1],
			'nominal' => $found[2] ?? '',
		);
	}

	/**
	 * The date in the chosen format.
	 *
	 * @param string $date   Y-m-d.
	 * @param string $format One of Sage50Settings::DATE_FORMATS.
	 */
	private function date( string $date, string $format ): string {
		$year = substr( $date, 0, 4 );
		$out  = array(
			'dd/mm/yyyy' => substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . $year,
			'dd/mm/yy'   => substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $year, 2 ),
			'ddmmyyyy'   => substr( $date, 8, 2 ) . substr( $date, 5, 2 ) . $year,
			'ddmmyy'     => substr( $date, 8, 2 ) . substr( $date, 5, 2 ) . substr( $year, 2 ),
		);
		return $out[ $format ] ?? $out['dd/mm/yyyy'];
	}

	/**
	 * Net Amount and Tax Amount hold 8 digits before the decimals.
	 *
	 * @param int    $minor    Amount.
	 * @param string $currency Currency.
	 *
	 * @throws Refusal When the amount is larger.
	 */
	private function assertFits( int $minor, string $currency ): void {
		$whole = ltrim( explode( '.', Amount::decimal( abs( $minor ), $currency ) )[0], '0' );
		if ( strlen( $whole ) > self::MAX_INTEGER_DIGITS ) {
			throw Refusal::because( __( 'an amount is larger than Sage 50 can import (8 digits before the decimals)', 'estasoft-erp-crm-sync-engine' ) );
		}
	}
}
