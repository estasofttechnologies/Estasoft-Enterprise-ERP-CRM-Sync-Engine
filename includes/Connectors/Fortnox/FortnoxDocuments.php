<?php
/**
 * Invoices, orders and credit invoices in Fortnox.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * The create-and-verify flow. `ExternalInvoiceReference1` carries `WC-{id}` and is searched before every create; the list answer of Fortnox
 * returns both external references, the total and the booked state, so a search needs no second call. Until the check of the total has
 * passed, `ExternalInvoiceReference2` holds a pending marker: `isComplete()` is false for a marked document, so a crash or a lost answer
 * between the create and the check is picked up again by `send()` and never linked unchecked. Every later step (bookkeeping, payment) is
 * read first and run only when it is missing, so a replay cannot repeat it.
 */
final class FortnoxDocuments {

	/**
	 * Value of `ExternalInvoiceReference2` while the check is open.
	 */
	public const PENDING = 'sync-pending';

	/**
	 * Warnings of the current send.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param FortnoxApi     $api     API.
	 * @param FortnoxLookups $lookups Lookups.
	 * @param CrmOptions     $options Options.
	 */
	public function __construct( private readonly FortnoxApi $api, private readonly FortnoxLookups $lookups, private readonly CrmOptions $options ) {
	}

	/**
	 * Whether orders go to Fortnox orders instead of invoices.
	 */
	public function usesOrders(): bool {
		return 'order' === $this->options->choice( 'document', array( 'invoice', 'order' ), 'invoice' );
	}

	/**
	 * The list row of an order or refund reference, or null. Cancelled documents do not count. Several: the lowest document number.
	 *
	 * @param string $reference  WC-{id}.
	 * @param bool   $creditNote True for a refund, which is always an invoice.
	 * @return array<string,mixed>|null
	 */
	public function find( string $reference, bool $creditNote = false ): ?array {
		$orders = ! $creditNote && $this->usesOrders();
		$found  = null;
		foreach ( $this->api->rows( $orders ? 'orders' : 'invoices', $orders ? 'Orders' : 'Invoices', array( 'externalinvoicereference1' => $reference ) ) as $row ) {
			if ( trim( (string) ( $row['ExternalInvoiceReference1'] ?? '' ) ) !== $reference || true === ( $row['Cancelled'] ?? false ) ) {
				continue;
			}
			if ( null === $found || (int) ( $row['DocumentNumber'] ?? 0 ) < (int) ( $found['DocumentNumber'] ?? 0 ) ) {
				$found = $row;
			}
		}
		return $found;
	}

	/**
	 * Whether a document is finished: its check passed and the steps the options ask for are done.
	 *
	 * @param array<string,mixed> $row        Row from find().
	 * @param bool                $creditNote Whether it is a refund.
	 */
	public function isComplete( array $row, bool $creditNote = false ): bool {
		if ( self::PENDING === trim( (string) ( $row['ExternalInvoiceReference2'] ?? '' ) ) ) {
			return false;
		}
		$invoiceLike = $creditNote || ! $this->usesOrders();
		if ( ! $invoiceLike ) {
			return true;
		}
		if ( $this->wantsBookkeeping() && true !== ( $row['Booked'] ?? false ) ) {
			return false;
		}
		if ( ! $creditNote && $this->wantsPayment() ) {
			$currency = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
			return 0 === Amounts::minor( $row['Balance'] ?? null, $currency );
		}
		return true;
	}

	/**
	 * A reference to a document.
	 *
	 * @param array<string,mixed> $row      Row or full record.
	 * @param bool                $order    True when the row is a Fortnox order.
	 */
	public function ref( array $row, bool $order = false ): ExternalRef {
		$number   = (string) ( $row['DocumentNumber'] ?? '' );
		$currency = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
		return new ExternalRef(
			$number,
			$number,
			null,
			array(
				'currency'    => $currency,
				'total_minor' => abs( Amounts::minor( $row['Total'] ?? null, $currency ) ?? 0 ),
				'document'    => $order ? 'order' : 'invoice',
				'booked'      => true === ( $row['Booked'] ?? false ),
				'warnings'    => $this->warnings,
			)
		);
	}

	/**
	 * Create or finish the document of an order.
	 *
	 * @param FortnoxOrder $order Order.
	 * @throws FortnoxFailure When the order cannot be sent as it is.
	 */
	public function sendOrder( FortnoxOrder $order ): SendResult {
		$this->warnings = array();
		$orders         = $this->usesOrders();
		$row            = $this->find( $order->reference );
		$created        = false;
		if ( null === $row ) {
			$row     = $this->create( $order, $orders );
			$created = true;
		}
		$this->verify( $row, $order->currency, $order->totalMinor, $created, $orders );
		$row = $this->finalise( $row, $orders, 'WooCommerce' );
		if ( ! $orders ) {
			$row = $this->steps( $row, $order );
		}
		$ref = $this->ref( $row, $orders );
		return $created ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create or finish the credit invoice of a refund.
	 *
	 * @param FortnoxRefund $refund Refund.
	 * @throws FortnoxFailure When the refund cannot be sent as it is.
	 */
	public function sendRefund( FortnoxRefund $refund ): SendResult {
		$this->warnings = array();
		$row            = $this->find( $refund->reference, true );
		$created        = false;
		$currency       = $refund->currency;
		if ( null === $row ) {
			$original = $this->find( $refund->orderRef );
			if ( null === $original || '' === (string) ( $original['CustomerNumber'] ?? '' ) ) {
				FortnoxFailure::refuse( 'order_ref', __( 'The order of this refund is not in Fortnox.', 'estasoft-erp-crm-sync-engine' ) );
			}
			if ( '' === $currency ) {
				$currency = '' !== (string) ( $original['Currency'] ?? '' ) ? (string) $original['Currency'] : 'SEK';
			}
			$row     = $this->createCreditInvoice( $refund, (string) $original['CustomerNumber'], $currency );
			$created = true;
		} elseif ( '' === $currency ) {
			$currency = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
		}
		$this->verify( $row, $currency, -$refund->amountMinor, $created, false );
		$row = $this->finalise( $row, false, $refund->orderRef );
		if ( $this->wantsBookkeeping() && true !== ( $row['Booked'] ?? false ) ) {
			$row = $this->bookkeep( $row );
		}
		$ref = $this->ref( $row );
		return $created ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Whether the sync books invoices.
	 */
	private function wantsBookkeeping(): bool {
		return 'yes' === $this->options->choice( 'bookkeep', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * Whether the sync records the payment of paid orders.
	 */
	private function wantsPayment(): bool {
		return 'yes' === $this->options->choice( 'record_payment', array( 'no', 'yes' ), 'no' );
	}

	/**
	 * Create the invoice or order of an order.
	 *
	 * @param FortnoxOrder $order  Order.
	 * @param bool         $orders True for a Fortnox order.
	 * @return array<string,mixed>
	 */
	private function create( FortnoxOrder $order, bool $orders ): array {
		$rows     = $this->rows( $order, $orders );
		$customer = $this->lookups->customerByEmail( $order->partner['email'] );
		if ( null === $customer ) {
			if ( 'match_only' === $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) ) {
				FortnoxFailure::refuse( 'partner.email', __( 'No customer with this email address exists in Fortnox, and the connection only matches existing customers.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$customer = $this->lookups->createCustomer( $order->partner );
		}
		$number = (string) ( $customer['CustomerNumber'] ?? '' );
		if ( '' === $number ) {
			FortnoxFailure::refuse( 'customer', __( 'Fortnox did not return a customer number.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$days = max( 0, min( 90, (int) $this->options->text( 'due_days', '0' ) ) );
		$due  = ( new \DateTimeImmutable( $order->date . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$body = array(
			'CustomerNumber'            => $number,
			'ExternalInvoiceReference1' => $order->reference,
			'ExternalInvoiceReference2' => self::PENDING,
			'VATIncluded'               => $order->pricesIncludeTax,
		);
		if ( 'SEK' !== $order->currency ) {
			$body['Currency'] = $order->currency;
		}
		$comment = trim( $order->note . ( '' !== $order->paymentReference ? "\n" . $order->paymentReference : '' ) );
		if ( '' !== $comment ) {
			$body['Comments'] = Text::limit( $comment, 1000 )[0];
		}
		if ( $orders ) {
			$body['OrderDate'] = $order->date;
			$body['OrderRows'] = $rows;
			$made              = $this->api->create( 'orders', 'Order', $body );
		} else {
			$body['InvoiceDate'] = $order->date;
			$body['DueDate']     = $due;
			$body['InvoiceRows'] = $rows;
			$made                = $this->api->create( 'invoices', 'Invoice', $body );
		}
		return $this->completeRecord( $made, $orders );
	}

	/**
	 * Create the credit invoice of a refund: the refunded rows with negative quantities.
	 *
	 * @param FortnoxRefund $refund         Refund.
	 * @param string        $customerNumber Customer of the original document.
	 * @param string        $currency       Currency.
	 * @return array<string,mixed>
	 */
	private function createCreditInvoice( FortnoxRefund $refund, string $customerNumber, string $currency ): array {
		$rows = array();
		foreach ( $refund->lines as $line ) {
			$rows[] = $this->refundRow( (string) $line['sku'], (string) $line['name'], (string) $line['quantity'], (int) $line['amount'], (string) $line['tax'], $currency );
		}
		if ( $refund->shippingMinor > 0 ) {
			$rows[] = $this->refundRow( '', __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), '1', $refund->shippingMinor, $refund->shippingTax, $currency );
		}
		if ( array() === $rows ) {
			$rows[] = $this->refundRow( '', __( 'Refund', 'estasoft-erp-crm-sync-engine' ), '1', $refund->amountMinor, '', $currency );
		}
		$body = array(
			'CustomerNumber'            => $customerNumber,
			'ExternalInvoiceReference1' => $refund->reference,
			'ExternalInvoiceReference2' => self::PENDING,
			'VATIncluded'               => $refund->pricesIncludeTax,
			'InvoiceDate'               => $refund->date,
			'DueDate'                   => $refund->date,
			'InvoiceRows'               => $rows,
		);
		if ( 'SEK' !== $currency ) {
			$body['Currency'] = $currency;
		}
		/* translators: 1: refund reference, 2: order reference */
		$comment          = trim( sprintf( __( 'Refund %1$s of %2$s', 'estasoft-erp-crm-sync-engine' ), $refund->reference, $refund->orderRef ) . ( '' !== $refund->reason ? "\n" . $refund->reason : '' ) );
		$body['Comments'] = Text::limit( $comment, 1000 )[0];
		return $this->completeRecord( $this->api->create( 'invoices', 'Invoice', $body ), false );
	}

	/**
	 * A refund row: negative quantity, positive price, so the invoice total is negative.
	 *
	 * @param string $sku      Article number or empty.
	 * @param string $name     Text.
	 * @param string $quantity Quantity text.
	 * @param int    $amount   Amount of the whole row in minor units, positive.
	 * @param string $taxCode  VAT percentage or empty.
	 * @param string $currency Currency.
	 * @return array<string,mixed>
	 */
	private function refundRow( string $sku, string $name, string $quantity, int $amount, string $taxCode, string $currency ): array {
		$unit = $amount;
		if ( ctype_digit( $quantity ) && (int) $quantity > 0 && 0 === $amount % (int) $quantity ) {
			$unit = intdiv( $amount, (int) $quantity );
		} else {
			$quantity = '1';
		}
		return $this->row( $sku, $name, '-' . $quantity, $unit, $taxCode, $currency, false );
	}

	/**
	 * The rows of an order: product rows, then shipping, fees and discount as rows of text.
	 *
	 * @param FortnoxOrder $order  Order.
	 * @param bool         $orders True for a Fortnox order.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( FortnoxOrder $order, bool $orders ): array {
		$rows = array();
		foreach ( $order->lines as $line ) {
			$sku  = (string) $line['sku'];
			$name = (string) $line['name'];
			if ( '' !== $sku && null === $this->lookups->article( $sku ) ) {
				if ( 'free_text' !== $this->options->choice( 'unknown_sku', array( 'fail', 'free_text' ), 'fail' ) ) {
					FortnoxFailure::refuseArticle( $sku );
				}
				$name = '' !== $name ? $name : $sku;
				$sku  = '';
			}
			$rows[] = $this->row( $sku, $name, (string) $line['quantity'], (int) $line['unit'], (string) $line['tax'], $order->currency, $orders );
		}
		foreach ( $order->extras as $extra ) {
			$amount = 'discount' === $extra['kind'] ? -abs( (int) $extra['amount'] ) : (int) $extra['amount'];
			$rows[] = $this->row( '', (string) $extra['name'], '1', $amount, (string) $extra['tax'], $order->currency, $orders );
		}
		return $rows;
	}

	/**
	 * One row of an invoice or order.
	 *
	 * @param string $sku      Article number or empty for a row of text.
	 * @param string $name     Text.
	 * @param string $quantity Quantity text (may start with a minus).
	 * @param int    $unit     Price per unit in minor units, as the shop shows it (with VAT when the shop's prices include it).
	 * @param string $taxCode  VAT percentage or empty.
	 * @param string $currency Currency.
	 * @param bool   $order    True for an order row.
	 * @return array<string,mixed>
	 */
	private function row( string $sku, string $name, string $quantity, int $unit, string $taxCode, string $currency, bool $order ): array {
		$negative = str_starts_with( $quantity, '-' );
		$number   = FortnoxPayload::quantityNumber( ltrim( $quantity, '-' ) );
		$row      = array(
			$order ? 'OrderedQuantity' : 'DeliveredQuantity' => $negative ? -$number : $number,
			'Price' => Amounts::number( $unit, $currency ),
		);
		if ( '' !== $sku ) {
			$row['ArticleNumber'] = $sku;
		}
		if ( '' !== $name ) {
			list( $text, $cut ) = Text::limit( $name, 50 );
			if ( $cut ) {
				$this->warnings[] = __( 'A row text was cut to 50 characters.', 'estasoft-erp-crm-sync-engine' );
			}
			$row['Description'] = $text;
		}
		if ( '' !== $taxCode ) {
			if ( 1 !== preg_match( '/^\d{1,2}(\.\d{1,2})?$/', $taxCode ) ) {
				FortnoxFailure::refuseVat( $taxCode );
			}
			$row['VAT'] = str_contains( $taxCode, '.' ) ? (float) $taxCode : (int) $taxCode;
		}
		return $row;
	}

	/**
	 * The full record of a document just created (the answer to the create when it holds the total, else a read).
	 *
	 * @param array<string,mixed> $made   The answer to the create.
	 * @param bool                $orders True for an order.
	 * @return array<string,mixed>
	 */
	private function completeRecord( array $made, bool $orders ): array {
		$number = (string) ( $made['DocumentNumber'] ?? '' );
		if ( '' === $number ) {
			FortnoxFailure::refuse( 'document', __( 'Fortnox created the document but did not return its number.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( isset( $made['Total'] ) ) {
			return $made;
		}
		return $this->api->one( $orders ? 'orders' : 'invoices', $number, $orders ? 'Order' : 'Invoice' ) ?? $made;
	}

	/**
	 * Compare a document with the order or refund. A document that is wrong stays marked as pending, so the retry finds it, checks it again
	 * and fails the same way instead of making another one; a person cancels it in Fortnox. A company that rounds its totals to whole
	 * kronor (öresavrundning) makes `Total` differ from the shop's total by the rounding, `RoundOff` on the record, at most half a unit: that
	 * difference is accepted and noted in the result.
	 *
	 * @param array<string,mixed> $row         Document.
	 * @param string              $currency    Expected currency.
	 * @param int|null            $totalMinor  Expected signed total including VAT, or null to skip.
	 * @param bool                $justCreated Whether this pass made it.
	 * @param bool                $orders      Whether the document is a Fortnox order.
	 * @throws FortnoxFailure When it differs and the options say fail.
	 */
	private function verify( array $row, string $currency, ?int $totalMinor, bool $justCreated, bool $orders ): void {
		$reason = '';
		$actual = '' !== (string) ( $row['Currency'] ?? '' ) ? strtoupper( (string) $row['Currency'] ) : 'SEK';
		if ( $actual !== $currency ) {
			/* translators: 1: currency of the order, 2: currency in Fortnox */
			$reason = sprintf( __( 'The order is in %1$s but Fortnox recorded it in %2$s.', 'estasoft-erp-crm-sync-engine' ), $currency, $actual );
		} elseif ( null !== $totalMinor ) {
			$recorded = Amounts::minor( $row['Total'] ?? null, $currency );
			if ( null !== $recorded && ! Amounts::agree( $totalMinor, $recorded ) ) {
				$rounding = $this->roundingOf( $row, $currency, $orders );
				if ( 0 !== $rounding && abs( $rounding ) <= intdiv( 10 ** Money::exponent( $currency ), 2 ) && abs( $totalMinor - $recorded ) <= abs( $rounding ) + Amounts::TOLERANCE ) {
					/* translators: 1: rounding amount, 2: currency */
					$this->warnings[] = sprintf( __( 'Fortnox rounded the total by %1$s %2$s (the company rounds its totals).', 'estasoft-erp-crm-sync-engine' ), Amounts::decimal( $rounding, $currency ), $currency );
				} else {
					/* translators: 1: total of the order, 2: total in Fortnox, 3: currency */
					$reason = sprintf( __( 'The total is %1$s but Fortnox recorded %2$s %3$s.', 'estasoft-erp-crm-sync-engine' ), Amounts::decimal( $totalMinor, $currency ), Amounts::decimal( $recorded, $currency ), $currency );
				}
			}
		}
		if ( '' === $reason ) {
			return;
		}
		if ( 'warn' === $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			$this->warnings[] = $reason;
			return;
		}
		if ( $justCreated ) {
			$reason .= ' ' . __( 'The document stays in Fortnox marked as pending; cancel it there, correct the settings and try again.', 'estasoft-erp-crm-sync-engine' );
		}
		FortnoxFailure::refuse( 'total', $reason );
	}

	/**
	 * The rounding Fortnox applied to a document, in minor units (0 when none or unknown). A list row does not carry `RoundOff`, so the
	 * record is read once.
	 *
	 * @param array<string,mixed> $row      Row or record.
	 * @param string              $currency Currency.
	 * @param bool                $orders   Whether the document is a Fortnox order.
	 */
	private function roundingOf( array $row, string $currency, bool $orders ): int {
		if ( ! array_key_exists( 'RoundOff', $row ) && '' !== (string) ( $row['DocumentNumber'] ?? '' ) ) {
			$row = $this->api->one( $orders ? 'orders' : 'invoices', (string) $row['DocumentNumber'], $orders ? 'Order' : 'Invoice' ) ?? $row;
		}
		return Amounts::minor( $row['RoundOff'] ?? null, $currency ) ?? 0;
	}

	/**
	 * Take the pending marker off a document that passed its check.
	 *
	 * @param array<string,mixed> $row    Document.
	 * @param bool                $orders True for an order.
	 * @param string              $marker Value of the second reference when final.
	 * @return array<string,mixed>
	 */
	private function finalise( array $row, bool $orders, string $marker ): array {
		if ( self::PENDING !== trim( (string) ( $row['ExternalInvoiceReference2'] ?? '' ) ) ) {
			return $row;
		}
		$number = (string) $row['DocumentNumber'];
		$this->api->update( $orders ? 'orders' : 'invoices', $number, $orders ? 'Order' : 'Invoice', array( 'ExternalInvoiceReference2' => $marker ) );
		$row['ExternalInvoiceReference2'] = $marker;
		return $row;
	}

	/**
	 * Book the invoice and record its payment when the options ask for it. Each step is read first and skipped when it is done.
	 *
	 * @param array<string,mixed> $row   Invoice (list row or record).
	 * @param FortnoxOrder        $order Order.
	 * @return array<string,mixed> The invoice as it stands.
	 */
	private function steps( array $row, FortnoxOrder $order ): array {
		$number       = (string) $row['DocumentNumber'];
		$wantsPayment = $this->wantsPayment() && '' !== $order->paidDate;
		if ( ! $this->wantsBookkeeping() && ! $wantsPayment ) {
			return $row;
		}
		$row = $this->api->one( 'invoices', $number, 'Invoice' ) ?? $row;
		if ( true !== ( $row['Booked'] ?? false ) ) {
			$row = $this->bookkeep( $row );
			$row = $this->api->one( 'invoices', $number, 'Invoice' ) ?? $row;
		}
		if ( $wantsPayment ) {
			$this->pay( $row, $order );
			$row = $this->api->one( 'invoices', $number, 'Invoice' ) ?? $row;
		}
		return $row;
	}

	/**
	 * Book an invoice. The answer of the action is the booked invoice; when it comes back empty the invoice is read again by the caller.
	 *
	 * @param array<string,mixed> $row Invoice.
	 * @return array<string,mixed>
	 */
	private function bookkeep( array $row ): array {
		$booked = $this->api->action( 'invoices', (string) $row['DocumentNumber'], 'bookkeep', 'Invoice' );
		return array() !== $booked ? $booked : $row;
	}

	/**
	 * Record the payment of an invoice that is booked and still has a balance.
	 *
	 * @param array<string,mixed> $row   Invoice record.
	 * @param FortnoxOrder        $order Order.
	 * @throws FortnoxFailure When the payment cannot be recorded as it is.
	 */
	private function pay( array $row, FortnoxOrder $order ): void {
		if ( 'SEK' !== $order->currency ) {
			FortnoxFailure::refuse( 'currency', __( 'Payments in a foreign currency are not recorded automatically. Turn off record_payment or record the payment in Fortnox.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$mode = $this->options->text( 'payment_mode' );
		if ( '' === $mode ) {
			FortnoxFailure::refuse( 'payment_mode', __( 'Set the mode of payment code in the connection option payment_mode.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$balance = Amounts::minor( $row['Balance'] ?? null, 'SEK' );
		if ( null === $balance || $balance <= 0 || true !== ( $row['Booked'] ?? false ) ) {
			return;
		}
		$this->api->create(
			'invoicepayments',
			'InvoicePayment',
			array(
				'InvoiceNumber' => (int) $row['DocumentNumber'],
				'Amount'        => Amounts::number( $balance, 'SEK' ),
				'PaymentDate'   => $order->paidDate,
				'ModeOfPayment' => $mode,
			)
		);
	}
}
