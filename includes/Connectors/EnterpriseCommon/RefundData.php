<?php
/**
 * The mapped refund payload, read and checked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Typed view of the nested array the mapping engine builds from the "refunds" rules.
 */
final class RefundData {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                $reference   WC-{refund id}.
	 * @param string                $orderRef    WC-{order id}.
	 * @param string                $date        Refund date (Y-m-d, UTC), or empty.
	 * @param string                $reason      Refund reason, or empty.
	 * @param int|null              $amountMinor Refund amount in minor units, or null when the mapping gave none.
	 * @param array<int,RefundLine> $lines       Refunded lines (may be empty for an amount-only refund).
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $orderRef,
		public readonly string $date,
		public readonly string $reason,
		public readonly ?int $amountMinor,
		public readonly array $lines,
	) {
	}
}
