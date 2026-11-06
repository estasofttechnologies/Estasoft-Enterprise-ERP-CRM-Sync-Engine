<?php
/**
 * Writes the DATEV booking records of one order or refund.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the reasons of a refusal are plain translated sentences kept in the batch; the admin screen escapes them where it shows them.

use Estasoft\SyncEngine\Connectors\ExportShared\Amount;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\Refusal;
use Estasoft\SyncEngine\Connectors\ExportShared\SafeText;
use Estasoft\SyncEngine\Connectors\ExportShared\Utf8;

/**
 * One posting for every pair of revenue account and BU key: Konto is the debtor, Gegenkonto the revenue account, the amount is
 * the gross of the lines in that group (net plus tax, which DATEV's automatic accounts or the BU key split), `S` for a sale and
 * `H` for a credit note. Belegfeld 1 is the order number. Tax is split per line because every line brings its own tax code.
 */
final class DatevPostingWriter {

	/**
	 * Largest difference in minor units between the order total and the postings that is added to the largest posting.
	 */
	private const TOLERANCE = 2;

	/**
	 * The records of a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param DatevSettings  $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int,extra:array<string,mixed>} Records separated by line feeds.
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	public function render( ExportDocument $document, DatevSettings $settings ): array {
		$length = $settings->accountLength();
		$debtor = $this->debtor( $document, $settings );

		$buckets = array();
		foreach ( $document->lines as $index => $line ) {
			$what = sprintf( /* translators: %d: line number */ __( 'line %d', 'estasoft-enterprise-erpcrm-sync-engine' ), $index + 1 );
			$this->bucket( $buckets, $this->code( $line->taxCode, $line->tax, $what, $settings ), $line->gross() );
		}
		foreach ( $document->shipping as $charge ) {
			$code = $this->code( $charge->taxCode, $charge->tax, __( 'shipping', 'estasoft-enterprise-erpcrm-sync-engine' ), $settings );
			if ( '' !== $settings->shippingAccount() ) {
				$code['account'] = $this->general( $settings->shippingAccount(), $length, __( 'the shipping account', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$this->bucket( $buckets, $code, $charge->gross() );
		}
		foreach ( $document->fees as $charge ) {
			$code = '' !== $charge->taxCode ? $charge->taxCode : $settings->feeTaxCode();
			$this->bucket( $buckets, $this->code( $code, $charge->tax, __( 'fee', 'estasoft-enterprise-erpcrm-sync-engine' ), $settings ), $charge->gross() );
		}
		if ( 0 !== $document->adjustment ) {
			if ( '' === $settings->adjustmentAccount() ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: amount */
						__( 'refund amount %s has no adjustment account', 'estasoft-enterprise-erpcrm-sync-engine' ),
						Amount::decimal( $document->adjustment, $document->currency )
					)
				);
			}
			$this->bucket(
				$buckets,
				array(
					'account' => $this->general( $settings->adjustmentAccount(), $length, __( 'the adjustment account', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
					'bu'      => '',
				),
				$document->adjustment
			);
		}

		$gross = $document->gross();
		$sum   = array_sum( array_column( $buckets, 'gross' ) );
		if ( 0 === $gross && 0 === $sum ) {
			throw Refusal::because( __( 'the total is zero, so there is nothing to post', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$diff = $gross - $sum;
		if ( abs( $diff ) > self::TOLERANCE ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: sum of the postings, 2: total of the order */
					__( 'the postings add up to %1$s but the order total is %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ),
					Amount::decimal( $sum, $document->currency ),
					Amount::decimal( $gross, $document->currency )
				)
			);
		}
		if ( 0 !== $diff ) {
			$largest                       = $this->largest( $buckets );
			$buckets[ $largest ]['gross'] += $diff;
		}

		$beleg   = $this->belegfeld( $document->voucherNumber() );
		$text    = $this->bookingText( $document, $settings );
		$year    = DatevYear::start( $document->date, $settings->fiscalYearStart() );
		$records = array();
		$dates   = array( $document->date );
		foreach ( $buckets as $bucket ) {
			if ( 0 === $bucket['gross'] ) {
				continue;
			}
			$debit     = ( $bucket['gross'] > 0 ) !== $document->isCredit();
			$records[] = $this->record( abs( $bucket['gross'] ), $debit, $debtor, $bucket['account'], $bucket['bu'], $document->date, $beleg, $text['text'], $document, $settings );
		}
		$masters = array(
			'debtors' => array( $debtor ),
			'revenue' => array_values( array_unique( array_column( $buckets, 'account' ) ) ),
			'bu_keys' => array_values( array_filter( array_unique( array_column( $buckets, 'bu' ) ), static fn( string $key ): bool => '' !== $key ) ),
			'banks'   => array(),
		);

		if ( $settings->recordPayments() && ! $document->isCredit() && null !== $document->payment ) {
			$bank = $settings->paymentAccount( $document->payment->method );
			if ( '' === $bank ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: payment method such as Tabby */
						__( 'payment method %s has no account', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'' === $document->payment->title ? $document->payment->method : $document->payment->title
					)
				);
			}
			$bank = $this->general( $bank, $length, sprintf( /* translators: %s: payment method */ __( 'the account of payment method %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $document->payment->method ) );
			if ( DatevYear::start( $document->payment->date, $settings->fiscalYearStart() ) !== $year ) {
				throw Refusal::because( __( 'it was paid in another financial year than the order', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$records[]          = $this->record( $gross, true, $bank, $debtor, '', $document->payment->date, $beleg, $text['text'], $document, $settings );
			$masters['banks'][] = $bank;
			$dates[]            = $document->payment->date;
		}

		sort( $dates, SORT_STRING );
		return array(
			'text'    => implode( "\n", $records ),
			'masters' => $masters,
			'cut'     => $text['cut'],
			'extra'   => array(
				'fy'   => $year,
				'from' => $dates[0],
				'to'   => $dates[ count( $dates ) - 1 ],
			),
		);
	}

	/**
	 * One booking record.
	 *
	 * @param int            $amount    Positive amount in minor units.
	 * @param bool           $debit     Whether Konto is debited (S).
	 * @param string         $konto     Konto.
	 * @param string         $counter   Gegenkonto.
	 * @param string         $bu        BU key or empty.
	 * @param string         $date      Booking date, Y-m-d.
	 * @param string         $beleg     Belegfeld 1.
	 * @param string         $text      Buchungstext.
	 * @param ExportDocument $document  Document.
	 * @param DatevSettings  $settings  Settings.
	 */
	private function record( int $amount, bool $debit, string $konto, string $counter, string $bu, string $date, string $beleg, string $text, ExportDocument $document, DatevSettings $settings ): string {
		return DatevColumns::record(
			array(
				DatevColumns::UMSATZ         => Amount::decimal( $amount, $document->currency, ',' ),
				DatevColumns::SOLL_HABEN     => $debit ? 'S' : 'H',
				DatevColumns::KONTO          => $konto,
				DatevColumns::GEGENKONTO     => $counter,
				DatevColumns::BU_SCHLUESSEL  => $bu,
				DatevColumns::BELEGDATUM     => substr( $date, 8, 2 ) . substr( $date, 5, 2 ),
				DatevColumns::BELEGFELD_1    => $beleg,
				DatevColumns::BUCHUNGSTEXT   => $text,
				DatevColumns::ZUSATZ_ART_1   => 'WooCommerce',
				DatevColumns::ZUSATZ_INHALT  => SafeText::fit( $document->reference, 210 )['text'],
				DatevColumns::FESTSCHREIBUNG => $settings->lock() ? '1' : '0',
			)
		);
	}

	/**
	 * The debtor account of a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param DatevSettings  $settings Settings.
	 *
	 * @throws Refusal When the customer does not fit the range.
	 */
	private function debtor( ExportDocument $document, DatevSettings $settings ): string {
		$length = $settings->accountLength();
		if ( $settings->debtorPerCustomer() && $document->customerId > 0 ) {
			$base    = $settings->personalBase();
			$account = $base + $document->customerId;
			if ( $account >= 7 * $base ) {
				throw Refusal::because(
					sprintf(
						/* translators: %d: WooCommerce customer id */
						__( 'customer %d does not fit the debtor range', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$document->customerId
					)
				);
			}
			return (string) $account;
		}
		$account = $settings->debtorPerCustomer() ? $settings->collectiveAccount() : $settings->debtorAccount();
		if ( ! DatevAccount::isPersonal( $account, $length ) ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: account, 2: number of digits */
					__( 'debtor account %1$s is not a number with %2$d digits', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$account,
					$length + 1
				)
			);
		}
		return $account;
	}

	/**
	 * The account and BU key of a tax code. A line without a code and without tax uses the code for untaxed sales.
	 *
	 * @param string        $code     Tax code, or empty.
	 * @param int           $tax      Tax of the line.
	 * @param string        $what     Words for the line in messages ("line 2", "shipping").
	 * @param DatevSettings $settings Settings.
	 * @return array{account:string,bu:string}
	 *
	 * @throws Refusal When the code is missing or not an account.
	 */
	private function code( string $code, int $tax, string $what, DatevSettings $settings ): array {
		if ( '' === $code ) {
			if ( 0 !== $tax ) {
				throw Refusal::because( sprintf( /* translators: %s: line, for example "line 2" or "shipping" */ __( '%s has tax but no tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), $what ) );
			}
			$code = $settings->untaxedCode();
			if ( '' === $code ) {
				throw Refusal::because( sprintf( /* translators: %s: line, for example "line 2" or "shipping" */ __( '%s has no tax code and no account for untaxed sales is set', 'estasoft-enterprise-erpcrm-sync-engine' ), $what ) );
			}
		}
		$parsed = DatevAccount::parse( $code, $settings->accountLength() );
		if ( null === $parsed ) {
			throw Refusal::because( sprintf( /* translators: %s: tax code */ __( 'tax code "%s" is not an account number', 'estasoft-enterprise-erpcrm-sync-engine' ), $code ) );
		}
		return $parsed;
	}

	/**
	 * A G/L account from a setting.
	 *
	 * @param string $account Digits.
	 * @param int    $length  Account length.
	 * @param string $what    Words for the account in messages.
	 *
	 * @throws Refusal When it is not an account of that length.
	 */
	private function general( string $account, int $length, string $what ): string {
		if ( ! DatevAccount::isGeneral( $account, $length ) ) {
			throw Refusal::because( sprintf( /* translators: 1: which account, 2: maximum digits */ __( '%1$s is not a number of at most %2$d digits', 'estasoft-enterprise-erpcrm-sync-engine' ), $what, $length ) );
		}
		return ltrim( $account, '0' );
	}

	/**
	 * Add an amount to the bucket of an account and BU key.
	 *
	 * @param array<string,array{account:string,bu:string,gross:int}> $buckets Buckets.
	 * @param array{account:string,bu:string}                         $code    Account and key.
	 * @param int                                                     $gross   Amount.
	 */
	private function bucket( array &$buckets, array $code, int $gross ): void {
		$key = $code['account'] . '|' . $code['bu'];
		if ( ! isset( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'account' => $code['account'],
				'bu'      => $code['bu'],
				'gross'   => 0,
			);
		}
		$buckets[ $key ]['gross'] += $gross;
	}

	/**
	 * The key of the bucket with the largest amount (the first one on a tie).
	 *
	 * @param array<string,array{account:string,bu:string,gross:int}> $buckets Buckets.
	 */
	private function largest( array $buckets ): string {
		$best  = (string) array_key_first( $buckets );
		$value = -1;
		foreach ( $buckets as $key => $bucket ) {
			if ( abs( $bucket['gross'] ) > $value ) {
				$best  = (string) $key;
				$value = abs( $bucket['gross'] );
			}
		}
		return $best;
	}

	/**
	 * Belegfeld 1: digits, letters and $ & % * + - / only, at most 36 characters.
	 *
	 * @param string $number Order number (or credit note number).
	 *
	 * @throws Refusal When nothing usable is left or it is too long.
	 */
	private function belegfeld( string $number ): string {
		$clean = preg_replace( '/[^A-Za-z0-9$&%*+\-\/]/u', '-', $number ) ?? '';
		$clean = trim( (string) preg_replace( '/-{2,}/', '-', $clean ), '-' );
		// A number that starts with a plus sign would be read as a formula by a spreadsheet; the sign means nothing in a document number.
		$clean = ltrim( $clean, '+-' );
		if ( '' === $clean ) {
			throw Refusal::because( __( 'the order number has no characters DATEV accepts in Belegfeld 1', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( strlen( $clean ) > 36 ) {
			throw Refusal::because( __( 'the order number is longer than 36 characters', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $clean;
	}

	/**
	 * The Buchungstext: "WC", the number and the customer name, at most 60 characters.
	 *
	 * @param ExportDocument $document Document.
	 * @param DatevSettings  $settings Settings.
	 * @return array{text:string,cut:int}
	 */
	private function bookingText( ExportDocument $document, DatevSettings $settings ): array {
		$text = 'WC ' . $document->number . ( $document->isCredit() ? ' R' . $document->refundId : '' ) . ( $settings->textWithName() ? ' ' . $document->partyName : '' );
		$cut  = Utf8::cut( $text, 60 );
		return array(
			'text' => $cut,
			'cut'  => $cut === $text ? 0 : 1,
		);
	}
}
