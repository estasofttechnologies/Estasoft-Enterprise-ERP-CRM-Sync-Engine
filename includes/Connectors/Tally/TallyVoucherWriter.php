<?php
/**
 * Writes the TallyPrime XML of one order or refund.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Tally;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the reasons of a refusal are plain translated sentences kept in the batch; the admin screen escapes them where it shows them.

use Estasoft\SyncEngine\Connectors\ExportShared\Amount;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportCharge;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportLine;
use Estasoft\SyncEngine\Connectors\ExportShared\Refusal;
use Estasoft\SyncEngine\Connectors\ExportShared\Utf8;
use Estasoft\SyncEngine\Connectors\ExportShared\XmlBuilder;

/**
 * One order becomes one sales voucher in invoice view (TallyPrime's documented "Sales Transaction - As Invoice"): a party ledger
 * with a bill allocation, ledger entries for shipping, fees, tax and round-off, and one inventory entry for every product with an
 * accounting allocation to the sales ledger. A refund is the same voucher as a credit note with every sign reversed. Every ledger
 * entry follows the rule of the documentation: ISDEEMEDPOSITIVE is Yes exactly for the negative amounts, and the amounts of one
 * voucher add up to zero, which the writer checks before it returns.
 */
final class TallyVoucherWriter {

	/**
	 * The text of a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param TallySettings  $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int}
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	public function render( ExportDocument $document, TallySettings $settings ): array {
		$plan    = $this->plan( $document, $settings );
		$text    = $this->voucher( $document, $settings, $plan );
		$masters = $plan['masters'];
		if ( $settings->recordPayments() && ! $document->isCredit() && null !== $document->payment ) {
			$ledger = $settings->paymentLedger( $document->payment->method );
			if ( '' === $ledger ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: payment method such as Tabby */
						__( 'payment method %s has no ledger', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'' === $document->payment->title ? $document->payment->method : $document->payment->title
					)
				);
			}
			$text                      .= $this->receipt( $document, $settings, $plan['party'], $ledger );
			$masters['ledgers'][]       = $ledger;
			$masters['voucher_types'][] = $settings->receiptType();
		}
		return array(
			'text'    => $text,
			'masters' => $masters,
			'cut'     => $plan['cut'],
		);
	}

	/**
	 * Work out every entry of the voucher before any text is written, so a refusal leaves nothing half written.
	 *
	 * @param ExportDocument $document Document.
	 * @param TallySettings  $settings Settings.
	 * @return array<string,mixed>
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	private function plan( ExportDocument $document, TallySettings $settings ): array {
		$asLedger = $settings->taxAsLedger();
		$currency = $document->currency;
		$party    = $settings->perCustomer() ? ( '' !== $document->partyAccount ? $document->partyAccount : $document->partyName ) : $settings->sharedLedger();
		$sales    = $settings->salesLedger();

		$stock    = array();
		$ledgers  = array();
		$taxes    = array();
		$discount = 0;

		foreach ( $document->lines as $index => $line ) {
			if ( $asLedger && 0 !== $line->tax && '' === $line->taxCode ) {
				throw Refusal::because( sprintf( /* translators: %d: line number */ __( 'line %d has tax but no tax code', 'estasoft-enterprise-erpcrm-sync-engine' ), $index + 1 ) );
			}
			$amount = $line->net + ( $asLedger ? 0 : $line->tax );
			if ( $asLedger && '' !== $settings->discountLedger() && $line->unitPrice > 0 ) {
				$before = Amount::times( $line->unitPrice, $line->quantity );
				if ( $before > $amount ) {
					$discount += $before - $amount;
					$amount    = $before;
				}
			}
			$name = $this->stockName( $line, $settings );
			if ( $settings->useInventory() && '' !== $name ) {
				$stock[] = array(
					'name'     => $name,
					'quantity' => $line->quantity,
					'amount'   => $amount,
				);
			} elseif ( $settings->useInventory() && ! $settings->ledgerOnlyWhenMissing() ) {
				throw Refusal::because(
					sprintf(
						/* translators: %d: line number */
						$settings->stockBySku() ? __( 'line %d has no SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'line %d has no product name', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$index + 1
					)
				);
			} else {
				$this->add( $ledgers, $sales, $amount );
			}
			$this->addTax( $taxes, $asLedger, $line->taxCode, $line->tax, $line->taxRate, $line->net );
		}
		foreach ( $document->shipping as $charge ) {
			$this->charge( $ledgers, $taxes, $charge, $charge->taxCode, $settings->shippingLedger(), $asLedger, __( 'shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		foreach ( $document->fees as $charge ) {
			$code = '' !== $charge->taxCode ? $charge->taxCode : $settings->feeTaxCode();
			$this->charge( $ledgers, $taxes, $charge, $code, $settings->feeLedger(), $asLedger, __( 'fee', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( 0 !== $document->adjustment ) {
			if ( '' === $settings->adjustmentLedger() ) {
				throw Refusal::because(
					sprintf(
						/* translators: %s: amount */
						__( 'refund amount %s has no adjustment ledger', 'estasoft-enterprise-erpcrm-sync-engine' ),
						Amount::decimal( $document->adjustment, $currency )
					)
				);
			}
			$this->add( $ledgers, $settings->adjustmentLedger(), $document->adjustment );
		}

		$sum = 0 - $discount;
		foreach ( $stock as $entry ) {
			$sum += $entry['amount'];
		}
		foreach ( $ledgers as $amount ) {
			$sum += $amount;
		}
		foreach ( $taxes as $entry ) {
			$sum += $entry['amount'];
		}
		$gross = $document->gross();
		if ( 0 === $gross && 0 === $sum ) {
			throw Refusal::because( __( 'the total is zero, so there is nothing to post', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$diff = $gross - $sum;
		if ( abs( $diff ) > $settings->roundOffMax() ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: sum of the lines, 2: total of the order */
					__( 'the lines add up to %1$s but the order total is %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ),
					Amount::decimal( $sum, $currency ),
					Amount::decimal( $gross, $currency )
				)
			);
		}

		$masters = array(
			'ledgers'       => array_merge( array( $party ), array_map( 'strval', array_keys( $ledgers ) ), array_map( 'strval', array_keys( $taxes ) ) ),
			'stock_items'   => array_column( $stock, 'name' ),
			'units'         => array() === $stock ? array() : array( $settings->unit() ),
			'godowns'       => array() === $stock ? array() : array( $settings->godown() ),
			'batches'       => array() === $stock ? array() : array( $settings->batch() ),
			'voucher_types' => array( $document->isCredit() ? $settings->creditNoteType() : $settings->salesType() ),
		);
		if ( array() !== $stock ) {
			$masters['ledgers'][] = $sales;
		}
		if ( $discount > 0 ) {
			$masters['ledgers'][] = $settings->discountLedger();
		}
		if ( 0 !== $diff ) {
			$masters['ledgers'][] = $settings->roundOffLedger();
		}

		$narration = $this->narration( $document );
		return array(
			'party'     => $party,
			'stock'     => $stock,
			'ledgers'   => $ledgers,
			'taxes'     => $taxes,
			'discount'  => $discount,
			'diff'      => $diff,
			'gross'     => $gross,
			'masters'   => $masters,
			'narration' => $narration['text'],
			'cut'       => $narration['cut'],
		);
	}

	/**
	 * The voucher text.
	 *
	 * @param ExportDocument      $document Document.
	 * @param TallySettings       $settings Settings.
	 * @param array<string,mixed> $plan     Plan from plan().
	 */
	private function voucher( ExportDocument $document, TallySettings $settings, array $plan ): string {
		$dir      = $document->isCredit() ? -1 : 1;
		$currency = $document->currency;
		$type     = $document->isCredit() ? $settings->creditNoteType() : $settings->salesType();
		$number   = $settings->prefix() . $document->voucherNumber();
		$view     = $settings->useInventory() ? 'Invoice Voucher View' : 'Accounting Voucher View';
		$attrs    = array(
			'VCHTYPE' => $type,
			'ACTION'  => 'Create',
			'OBJVIEW' => $view,
		);
		if ( $settings->stableId() ) {
			$attrs = array( 'REMOTEID' => $this->uid( $settings, $document, 'voucher' ) ) + $attrs;
		}
		$x = new XmlBuilder();
		$x->open( 'TALLYMESSAGE' )->open( 'VOUCHER', $attrs );
		if ( $settings->stableId() ) {
			$x->leaf( 'GUID', $attrs['REMOTEID'] );
		}
		$x->leaf( 'DATE', str_replace( '-', '', $document->date ) )
			->leaf( 'VOUCHERTYPENAME', $type )
			->leaf( 'VOUCHERNUMBER', $number )
			->leaf( 'PARTYLEDGERNAME', $plan['party'] )
			->leaf( 'PERSISTEDVIEW', $view )
			->leaf( 'ISINVOICE', 'Yes' )
			->leaf( 'NARRATION', $plan['narration'] );

		$partyAmount = -$dir * $plan['gross'];
		$x->open( 'LEDGERENTRIES.LIST' )
			->leaf( 'LEDGERNAME', $plan['party'] )
			->leaf( 'ISDEEMEDPOSITIVE', $this->yes( $partyAmount ) )
			->leaf( 'ISPARTYLEDGER', 'Yes' )
			->leaf( 'ISLASTDEEMEDPOSITIVE', $this->yes( $partyAmount ) )
			->leaf( 'AMOUNT', Amount::decimal( $partyAmount, $currency ) )
			->open( 'BILLALLOCATIONS.LIST' )
			->leaf( 'NAME', $number )
			->leaf( 'BILLTYPE', 'New Ref' )
			->leaf( 'AMOUNT', Amount::decimal( $partyAmount, $currency ) )
			->close( 'BILLALLOCATIONS.LIST' )
			->close( 'LEDGERENTRIES.LIST' );

		foreach ( $plan['ledgers'] as $ledger => $credit ) {
			$this->ledgerEntry( $x, (string) $ledger, $dir * $credit, $currency );
		}
		if ( $plan['discount'] > 0 ) {
			$this->ledgerEntry( $x, $settings->discountLedger(), -$dir * $plan['discount'], $currency );
		}
		foreach ( $plan['taxes'] as $code => $entry ) {
			$amount = $dir * $entry['amount'];
			$x->open( 'LEDGERENTRIES.LIST' );
			if ( '' !== $entry['rate'] ) {
				$x->open( 'BASICRATEOFINVOICETAX.LIST', array( 'TYPE' => 'Number' ) )->leaf( 'BASICRATEOFINVOICETAX', $entry['rate'] )->close( 'BASICRATEOFINVOICETAX.LIST' );
			}
			$x->leaf( 'ROUNDTYPE', '' )
				->leaf( 'LEDGERNAME', (string) $code )
				->leaf( 'ISDEEMEDPOSITIVE', $this->yes( $amount ) )
				->leaf( 'AMOUNT', Amount::decimal( $amount, $currency ) )
				->close( 'LEDGERENTRIES.LIST' );
		}
		if ( 0 !== $plan['diff'] ) {
			$this->ledgerEntry( $x, $settings->roundOffLedger(), $dir * $plan['diff'], $currency );
		}
		foreach ( $plan['stock'] as $entry ) {
			$amount = $dir * $entry['amount'];
			$rate   = Amount::unitPrice( $entry['amount'], $entry['quantity'], $currency ) . '/' . $settings->unit();
			$qty    = $entry['quantity'] . ' ' . $settings->unit();
			$x->open( 'ALLINVENTORYENTRIES.LIST' )
				->leaf( 'STOCKITEMNAME', $entry['name'] )
				->leaf( 'ISDEEMEDPOSITIVE', $this->yes( $amount ) )
				->leaf( 'RATE', $rate )
				->leaf( 'AMOUNT', Amount::decimal( $amount, $currency ) )
				->leaf( 'ACTUALQTY', $qty )
				->leaf( 'BILLEDQTY', $qty )
				->open( 'BATCHALLOCATIONS.LIST' )
				->leaf( 'GODOWNNAME', $settings->godown() )
				->leaf( 'BATCHNAME', $settings->batch() )
				->leaf( 'DESTINATIONGODOWNNAME', $settings->godown() )
				->leaf( 'AMOUNT', Amount::decimal( $amount, $currency ) )
				->leaf( 'ACTUALQTY', $qty )
				->leaf( 'BILLEDQTY', $qty )
				->close( 'BATCHALLOCATIONS.LIST' )
				->open( 'ACCOUNTINGALLOCATIONS.LIST' )
				->leaf( 'LEDGERNAME', $settings->salesLedger() )
				->leaf( 'ISDEEMEDPOSITIVE', $this->yes( $amount ) )
				->leaf( 'AMOUNT', Amount::decimal( $amount, $currency ) )
				->close( 'ACCOUNTINGALLOCATIONS.LIST' )
				->close( 'ALLINVENTORYENTRIES.LIST' );
		}
		$x->close( 'VOUCHER' )->close( 'TALLYMESSAGE' );
		return $x->toString();
	}

	/**
	 * The receipt voucher of a paid order: the customer is credited against the bill and the payment ledger is debited.
	 *
	 * @param ExportDocument $document Document.
	 * @param TallySettings  $settings Settings.
	 * @param string         $party    Party ledger.
	 * @param string         $ledger   Bank or gateway ledger.
	 */
	private function receipt( ExportDocument $document, TallySettings $settings, string $party, string $ledger ): string {
		$payment  = $document->payment;
		$currency = $document->currency;
		$gross    = $document->gross();
		$type     = $settings->receiptType();
		$attrs    = array(
			'VCHTYPE' => $type,
			'ACTION'  => 'Create',
			'OBJVIEW' => 'Accounting Voucher View',
		);
		if ( $settings->stableId() ) {
			$attrs = array( 'REMOTEID' => $this->uid( $settings, $document, 'receipt' ) ) + $attrs;
		}
		$x = new XmlBuilder();
		$x->open( 'TALLYMESSAGE' )->open( 'VOUCHER', $attrs );
		if ( $settings->stableId() ) {
			$x->leaf( 'GUID', $attrs['REMOTEID'] );
		}
		$narration = sprintf(
			/* translators: %s: client reference such as WC-1001 */
			__( 'Payment for order %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$document->reference
		) . ( null !== $payment && '' !== $payment->reference ? ' (' . $payment->reference . ')' : '' );
		$x->leaf( 'DATE', str_replace( '-', '', null === $payment ? $document->date : $payment->date ) )
			->leaf( 'VOUCHERTYPENAME', $type )
			->leaf( 'NARRATION', Utf8::cut( $narration, 250 ) )
			->open( 'ALLLEDGERENTRIES.LIST' )
			->leaf( 'LEDGERNAME', $party )
			->leaf( 'ISDEEMEDPOSITIVE', 'No' )
			->leaf( 'AMOUNT', Amount::decimal( $gross, $currency ) )
			->open( 'BILLALLOCATIONS.LIST' )
			->leaf( 'NAME', $settings->prefix() . $document->voucherNumber() )
			->leaf( 'BILLTYPE', 'Agst Ref' )
			->leaf( 'AMOUNT', Amount::decimal( $gross, $currency ) )
			->close( 'BILLALLOCATIONS.LIST' )
			->close( 'ALLLEDGERENTRIES.LIST' )
			->open( 'ALLLEDGERENTRIES.LIST' )
			->leaf( 'LEDGERNAME', $ledger )
			->leaf( 'ISDEEMEDPOSITIVE', 'Yes' )
			->leaf( 'AMOUNT', Amount::decimal( -$gross, $currency ) )
			->close( 'ALLLEDGERENTRIES.LIST' )
			->close( 'VOUCHER' )
			->close( 'TALLYMESSAGE' );
		return $x->toString();
	}

	/**
	 * A plain ledger entry.
	 *
	 * @param XmlBuilder $x        Builder.
	 * @param string     $ledger   Ledger name.
	 * @param int        $amount   Signed amount: negative is a debit.
	 * @param string     $currency Currency.
	 */
	private function ledgerEntry( XmlBuilder $x, string $ledger, int $amount, string $currency ): void {
		$x->open( 'LEDGERENTRIES.LIST' )
			->leaf( 'LEDGERNAME', $ledger )
			->leaf( 'ISDEEMEDPOSITIVE', $this->yes( $amount ) )
			->leaf( 'AMOUNT', Amount::decimal( $amount, $currency ) )
			->close( 'LEDGERENTRIES.LIST' );
	}

	/**
	 * Add a shipping or fee line to the ledgers and the taxes.
	 *
	 * @param array<string,int>                           $ledgers  Ledger amounts, by name.
	 * @param array<string,array{amount:int,rate:string}> $taxes    Tax amounts, by code.
	 * @param ExportCharge                                $charge   The line.
	 * @param string                                      $code     Tax code to use.
	 * @param string                                      $ledger   Ledger for the amount.
	 * @param bool                                        $asLedger Whether tax is written as ledger entries.
	 * @param string                                      $what     Word for the message ("shipping", "fee").
	 *
	 * @throws Refusal When the line has tax and no code.
	 */
	private function charge( array &$ledgers, array &$taxes, ExportCharge $charge, string $code, string $ledger, bool $asLedger, string $what ): void {
		if ( $asLedger && 0 !== $charge->tax && '' === $code ) {
			throw Refusal::because(
				sprintf(
					/* translators: 1: shipping or fee, 2: text of the line */
					__( '%1$s "%2$s" has tax but no tax ledger', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$what,
					$charge->name
				)
			);
		}
		$this->add( $ledgers, $ledger, $charge->net + ( $asLedger ? 0 : $charge->tax ) );
		$this->addTax( $taxes, $asLedger, $code, $charge->tax, $charge->taxRate, $charge->net );
	}

	/**
	 * Add an amount to a ledger; zero amounts add nothing.
	 *
	 * @param array<string,int> $ledgers Ledger amounts, by name.
	 * @param string            $name    Ledger name.
	 * @param int               $amount  Credit-side amount.
	 */
	private function add( array &$ledgers, string $name, int $amount ): void {
		if ( 0 === $amount ) {
			return;
		}
		$ledgers[ $name ] = ( $ledgers[ $name ] ?? 0 ) + $amount;
	}

	/**
	 * Add tax to the entry of its code.
	 *
	 * @param array<string,array{amount:int,rate:string}> $taxes    Tax amounts, by code.
	 * @param bool                                        $asLedger Whether tax is written as ledger entries.
	 * @param string                                      $code     Tax code (the ledger name).
	 * @param int                                         $tax      Tax amount.
	 * @param string                                      $rate     Rate in percent, or empty.
	 * @param int                                         $net      Net amount of the line (for a rate worked out from the amounts).
	 */
	private function addTax( array &$taxes, bool $asLedger, string $code, int $tax, string $rate, int $net ): void {
		if ( ! $asLedger || 0 === $tax || '' === $code ) {
			return;
		}
		$known          = $taxes[ $code ] ?? array(
			'amount' => 0,
			'rate'   => '',
		);
		$taxes[ $code ] = array(
			'amount' => $known['amount'] + $tax,
			'rate'   => '' !== $known['rate'] ? $known['rate'] : ( '' !== $rate ? $rate : Amount::observedRate( $net, $tax ) ),
		);
	}

	/**
	 * The stock item name of a line: the SKU or the product name, by setting.
	 *
	 * @param ExportLine    $line     Line.
	 * @param TallySettings $settings Settings.
	 */
	private function stockName( ExportLine $line, TallySettings $settings ): string {
		return $settings->stockBySku() ? $line->sku : $line->name;
	}

	/**
	 * The narration: the client reference, the refund number and the note, cut at 250 characters.
	 *
	 * @param ExportDocument $document Document.
	 * @return array{text:string,cut:int}
	 */
	private function narration( ExportDocument $document ): array {
		$base = $document->isCredit()
			? sprintf(
				/* translators: 1: refund id, 2: client reference such as WC-1001 */
				__( 'Refund R%1$d of order %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$document->refundId,
				$document->reference
			)
			: sprintf(
				/* translators: %s: client reference such as WC-1001 */
				__( 'Order %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$document->reference
			);
		$text = '' === $document->note ? $base : $base . ': ' . $document->note;
		$cut  = Utf8::cut( $text, 250 );
		return array(
			'text' => $cut,
			'cut'  => $cut === $text ? 0 : 1,
		);
	}

	/**
	 * Yes for a debit (negative amount), No for a credit.
	 *
	 * @param int $amount Signed amount.
	 */
	private function yes( int $amount ): string {
		return $amount < 0 ? 'Yes' : 'No';
	}

	/**
	 * A stable, UUID-shaped ID of a voucher: the same order always gets the same one.
	 *
	 * @param TallySettings  $settings Settings.
	 * @param ExportDocument $document Document.
	 * @param string         $kind     voucher or receipt.
	 */
	private function uid( TallySettings $settings, ExportDocument $document, string $kind ): string {
		$hash = sha1( 'estasoft-sync:tally:' . $settings->connectionId() . ':' . $document->reference . ':' . $document->refundId . ':' . $kind );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}
}
