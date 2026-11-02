<?php
/**
 * Writes the Sage 50 U.S. Sales Journal rows of one order or refund.
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
 * One transaction is one invoice or credit memo; every distribution (product, fee, freight, tax) is a row that repeats the header
 * fields. Amounts follow Sage's rule "positive = debit, negative = credit": an invoice's income and tax lines are negative, a credit
 * memo's are positive. The field names and limits are those of the Sales Journal import/export field list of Sage 50 U.S.
 */
final class Sage50UsWriter {

	public const HEADINGS = array(
		'Customer ID',
		'Invoice/CM #',
		'Apply to Invoice Number',
		'Credit Memo',
		'Date',
		'Date Due',
		'Customer PO',
		'Accounts Receivable Account',
		'Number of Distributions',
		'Invoice/CM Distribution',
		'Quantity',
		'Item ID',
		'Description',
		'G/L Account',
		'Unit Price',
		'Tax Type',
		'Amount',
		'Sales Tax Agency',
		'Recur Number',
		'Recur Frequency',
	);

	/**
	 * Valid values of Number of Distributions in the Sales Journal field list.
	 */
	private const MAX_DISTRIBUTIONS = 147;

	/**
	 * Tax Type of a freight line, and of a tax distribution.
	 */
	private const TAX_TYPE_FREIGHT = 27;

	/**
	 * The columns that hold free text: Invoice/CM #, Apply to Invoice Number, Customer PO and Description (zero-based). The customer ID,
	 * item ID, G/L accounts, sales tax agency, dates, quantities, tax type and amounts are written as they are.
	 */
	private const TEXT_COLUMNS = array( 1, 2, 6, 12 );

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
		$customer = '' !== $document->partyAccount ? $document->partyAccount : $settings->customerId();
		if ( strlen( $customer ) > 20 ) {
			throw Refusal::because( __( 'the customer ID is longer than 20 characters', 'estasoft-erp-crm-sync-engine' ) );
		}
		$number = SafeText::safe( $document->voucherNumber() );
		if ( strlen( $number ) > 20 ) {
			throw Refusal::because( __( 'the order number is longer than 20 characters', 'estasoft-erp-crm-sync-engine' ) );
		}

		$dist  = array();
		$tax   = array();
		$index = 0;
		foreach ( $document->lines as $i => $line ) {
			$what   = sprintf( /* translators: %d: line number */ __( 'line %d', 'estasoft-erp-crm-sync-engine' ), $i + 1 );
			$agent  = $this->agency( $line->taxCode, $line->tax, $what, $settings );
			$dist[] = array(
				'no'       => ++$index,
				'quantity' => $line->quantity,
				'item'     => $settings->useItems() ? $line->sku : '',
				'text'     => '' !== $line->name ? $line->name : ( '' !== $line->sku ? $line->sku : __( 'Product', 'estasoft-erp-crm-sync-engine' ) ),
				'gl'       => $settings->salesAccount(),
				'net'      => $line->net,
				'type'     => $settings->itemTaxType(),
				'agency'   => $agent['agency'],
			);
			$this->addTax( $tax, $agent, $line->tax );
		}
		foreach ( $document->fees as $charge ) {
			$agent  = $this->agency( '' !== $charge->taxCode ? $charge->taxCode : $settings->feeTaxCode(), $charge->tax, __( 'fee', 'estasoft-erp-crm-sync-engine' ), $settings );
			$dist[] = array(
				'no'       => ++$index,
				'quantity' => '1',
				'item'     => '',
				'text'     => $charge->name,
				'gl'       => $settings->salesAccount(),
				'net'      => $charge->net,
				'type'     => $settings->itemTaxType(),
				'agency'   => $agent['agency'],
			);
			$this->addTax( $tax, $agent, $charge->tax );
		}
		if ( 0 !== $document->adjustment ) {
			if ( '' === $settings->adjustmentAccount() ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: amount */
						__( 'refund amount %s has no adjustment account', 'estasoft-erp-crm-sync-engine' ),
						Amount::decimal( $document->adjustment, $document->currency )
					)
				);
			}
			$dist[] = array(
				'no'       => ++$index,
				'quantity' => '1',
				'item'     => '',
				'text'     => __( 'Refund adjustment', 'estasoft-erp-crm-sync-engine' ),
				'gl'       => $settings->adjustmentAccount(),
				'net'      => $document->adjustment,
				'type'     => $settings->itemTaxType(),
				'agency'   => '',
			);
		}
		$freight = array();
		foreach ( $document->shipping as $charge ) {
			$agent     = $this->agency( $charge->taxCode, $charge->tax, __( 'shipping', 'estasoft-erp-crm-sync-engine' ), $settings );
			$freight[] = array(
				'no'       => 0,
				'quantity' => '',
				'item'     => '',
				'text'     => 'freight charge',
				'gl'       => $settings->freightAccount(),
				'net'      => $charge->net,
				'type'     => self::TAX_TYPE_FREIGHT,
				'agency'   => '',
			);
			$this->addTax( $tax, $agent, $charge->tax );
		}
		foreach ( $freight as $row ) {
			$dist[] = $row;
		}
		foreach ( $tax as $agency => $entry ) {
			if ( 0 === $entry['amount'] ) {
				continue;
			}
			$account = '' !== $entry['gl'] ? $entry['gl'] : $settings->taxGlAccount();
			if ( '' === $account ) {
				throw Refusal::because( sprintf( /* translators: %s: sales tax agency */ __( 'sales tax agency %s has no G/L account for its tax', 'estasoft-erp-crm-sync-engine' ), (string) $agency ) );
			}
			$dist[] = array(
				'no'       => 0,
				'quantity' => '',
				'item'     => '',
				/* translators: %s: sales tax agency */
				'text'     => sprintf( __( 'Sales tax %s', 'estasoft-erp-crm-sync-engine' ), (string) $agency ),
				'gl'       => $account,
				'net'      => $entry['amount'],
				'type'     => 0,
				'agency'   => (string) $agency,
			);
		}

		if ( count( $dist ) > self::MAX_DISTRIBUTIONS ) {
			throw Refusal::because( sprintf( /* translators: %d: number of rows */ __( 'the order has %d lines, more than the 147 Sage 50 takes in one invoice', 'estasoft-erp-crm-sync-engine' ), count( $dist ) ) );
		}
		$gross = $document->gross();
		$sum   = 0;
		foreach ( $dist as $row ) {
			$sum += $row['net'];
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
			$target                  = $this->absorber( $dist );
			$dist[ $target ]['net'] += $diff;
		}
		return $this->text( $document, $settings, $customer, $number, $dist );
	}

	/**
	 * The CSV text of the distributions.
	 *
	 * @param ExportDocument                 $document Document.
	 * @param Sage50Settings                 $settings Settings.
	 * @param string                         $customer Customer ID.
	 * @param string                         $number   Invoice or credit memo number.
	 * @param array<int,array<string,mixed>> $dist     Distributions.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int}
	 */
	private function text( ExportDocument $document, Sage50Settings $settings, string $customer, string $number, array $dist ): array {
		$currency = $document->currency;
		$credit   = $document->isCredit();
		$sign     = $credit ? 1 : -1;
		$date     = $this->date( $document->date, 0 );
		$due      = $this->date( $document->date, $settings->dueDays() );
		$fitted   = SafeText::fit( $document->reference, 20 );
		$po       = $fitted['text'];
		$cut      = $fitted['cut'];
		$count    = (string) count( $dist );
		$lines    = array();
		$accounts = array( $settings->arAccount() );
		$items    = array();
		$agencies = array();
		foreach ( $dist as $row ) {
			$fitted     = SafeText::fit( str_replace( '"', "'", (string) $row['text'] ), 160 );
			$text       = $fitted['text'];
			$cut       += $fitted['cut'];
			$unit       = '' === $row['quantity'] ? '' : Amount::unitPrice( (int) $row['net'], (string) $row['quantity'], $currency );
			$lines[]    = Sage50Csv::line(
				array(
					$customer,
					$number,
					$credit ? SafeText::safe( $document->number ) : '',
					$credit ? 'TRUE' : 'FALSE',
					$date,
					$due,
					$po,
					$settings->arAccount(),
					$count,
					(string) $row['no'],
					(string) $row['quantity'],
					(string) $row['item'],
					$text,
					(string) $row['gl'],
					$unit,
					(string) $row['type'],
					Amount::decimal( $sign * (int) $row['net'], $currency ),
					(string) $row['agency'],
					'0',
					'0',
				),
				self::TEXT_COLUMNS
			);
			$accounts[] = (string) $row['gl'];
			if ( '' !== $row['item'] ) {
				$items[] = (string) $row['item'];
			}
			if ( '' !== $row['agency'] ) {
				$agencies[] = (string) $row['agency'];
			}
		}
		return array(
			'text'    => implode( "\n", $lines ),
			'masters' => array(
				'customers' => array( $customer ),
				'accounts'  => array_values( array_unique( $accounts ) ),
				'agencies'  => array_values( array_unique( $agencies ) ),
				'items'     => array_values( array_unique( $items ) ),
			),
			'cut'     => $cut,
		);
	}

	/**
	 * The distribution that takes a rounding difference: the largest tax line, or the largest line when there is no tax.
	 *
	 * @param array<int,array<string,mixed>> $dist Distributions.
	 */
	private function absorber( array $dist ): int {
		$target = null;
		foreach ( $dist as $i => $row ) {
			if ( 0 === $row['type'] && ( null === $target || abs( (int) $dist[ $target ]['net'] ) < abs( (int) $row['net'] ) ) ) {
				$target = $i;
			}
		}
		if ( null !== $target ) {
			return $target;
		}
		$target = 0;
		foreach ( $dist as $i => $row ) {
			if ( abs( (int) $dist[ $target ]['net'] ) < abs( (int) $row['net'] ) ) {
				$target = $i;
			}
		}
		return $target;
	}

	/**
	 * Read a tax code: the sales tax agency ID, optionally a colon and the G/L account that receives its tax.
	 *
	 * @param string         $code     "AGENCY" or "AGENCY:GL", or empty.
	 * @param int            $tax      Tax of the line.
	 * @param string         $what     Words for the line in messages.
	 * @param Sage50Settings $settings Settings.
	 * @return array{agency:string,gl:string}
	 *
	 * @throws Refusal When a taxed line has no code.
	 */
	private function agency( string $code, int $tax, string $what, Sage50Settings $settings ): array {
		if ( '' === $code ) {
			if ( 0 !== $tax ) {
				throw Refusal::because( sprintf( /* translators: %s: line, for example "line 2" or "shipping" */ __( '%s has tax but no tax code', 'estasoft-erp-crm-sync-engine' ), $what ) );
			}
			$code = $settings->untaxedCode();
		}
		$parts  = explode( ':', $code, 2 );
		$agency = trim( $parts[0] );
		$gl     = trim( $parts[1] ?? '' );
		if ( strlen( $agency ) > 20 || ( '' === $agency && 0 !== $tax ) || ( '' !== $gl && 1 !== preg_match( '/^[A-Za-z0-9._-]{1,15}$/', $gl ) ) ) {
			throw Refusal::because( sprintf( /* translators: %s: tax code */ __( 'tax code "%s" is not a sales tax agency ID, optionally with a G/L account', 'estasoft-erp-crm-sync-engine' ), $code ) );
		}
		return array(
			'agency' => $agency,
			'gl'     => $gl,
		);
	}

	/**
	 * Add tax to the entry of its agency.
	 *
	 * @param array<string,array{amount:int,gl:string}> $tax   Tax by agency.
	 * @param array{agency:string,gl:string}            $agent Agency and account of the line.
	 * @param int                                       $amount Tax of the line.
	 */
	private function addTax( array &$tax, array $agent, int $amount ): void {
		if ( 0 === $amount ) {
			return;
		}
		$key         = $agent['agency'];
		$known       = $tax[ $key ] ?? array(
			'amount' => 0,
			'gl'     => '',
		);
		$tax[ $key ] = array(
			'amount' => $known['amount'] + $amount,
			'gl'     => '' !== $known['gl'] ? $known['gl'] : $agent['gl'],
		);
	}

	/**
	 * A date as MM/DD/YYYY, some days later.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add.
	 */
	private function date( string $date, int $days ): string {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $value ) {
			return $date;
		}
		return ( $days > 0 ? $value->modify( '+' . $days . ' days' ) : $value )->format( 'm/d/Y' );
	}
}
