<?php
/**
 * An order or a refund as the export writers see it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Built by DocumentReader from the mapped payload. All amounts are positive minor units in the document's currency; kind() says
 * whether the document is a sale or a credit note. The writers turn it into XML or CSV rows and never read the payload again.
 */
final class ExportDocument {

	public const INVOICE     = 'invoice';
	public const CREDIT_NOTE = 'credit_note';

	/**
	 * Set up with the values.
	 *
	 * @param string                  $kind         INVOICE or CREDIT_NOTE.
	 * @param string                  $number       WooCommerce order number.
	 * @param string                  $reference    Client reference, "WC-" and the order id.
	 * @param int                     $refundId     WooCommerce refund id, 0 for an order.
	 * @param string                  $date         Document date, Y-m-d.
	 * @param string                  $currency     ISO currency code.
	 * @param string                  $partyName    Customer name as typed.
	 * @param string                  $partyAccount Account, ledger or ID of the customer in the system, or empty.
	 * @param int                     $customerId   WooCommerce customer id, 0 for a guest.
	 * @param string                  $note         Customer note or refund reason.
	 * @param int|null                $expected     Gross total the shop expects, or null when unknown.
	 * @param array<int,ExportLine>   $lines        Product lines.
	 * @param array<int,ExportCharge> $shipping     Shipping lines.
	 * @param array<int,ExportCharge> $fees         Fee lines.
	 * @param int                     $adjustment   Refund amount that belongs to no product (gross, no tax split).
	 * @param ExportPayment|null      $payment      Payment to record, or null.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $number,
		public readonly string $reference,
		public readonly int $refundId,
		public readonly string $date,
		public readonly string $currency,
		public readonly string $partyName,
		public readonly string $partyAccount,
		public readonly int $customerId,
		public readonly string $note,
		public readonly ?int $expected,
		public readonly array $lines,
		public readonly array $shipping,
		public readonly array $fees,
		public readonly int $adjustment = 0,
		public readonly ?ExportPayment $payment = null,
	) {
	}

	/**
	 * Whether this is a credit note.
	 */
	public function isCredit(): bool {
		return self::CREDIT_NOTE === $this->kind;
	}

	/**
	 * The number the document carries in the other system: the order number, or the order number and R and the refund id.
	 */
	public function voucherNumber(): string {
		return $this->isCredit() ? $this->number . '-R' . $this->refundId : $this->number;
	}

	/**
	 * The sum of every line with its tax.
	 */
	public function computedGross(): int {
		$sum = $this->adjustment;
		foreach ( $this->lines as $line ) {
			$sum += $line->gross();
		}
		foreach ( array_merge( $this->shipping, $this->fees ) as $charge ) {
			$sum += $charge->gross();
		}
		return $sum;
	}

	/**
	 * The total the file will carry for the customer: what the shop expects when it said so, otherwise the sum of the lines.
	 */
	public function gross(): int {
		return $this->expected ?? $this->computedGross();
	}

	/**
	 * Expected total minus the lines; zero when they agree or when the shop gave no total.
	 */
	public function difference(): int {
		return null === $this->expected ? 0 : $this->expected - $this->computedGross();
	}
}
