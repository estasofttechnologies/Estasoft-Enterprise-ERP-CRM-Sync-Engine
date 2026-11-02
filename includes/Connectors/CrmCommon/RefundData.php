<?php
/**
 * A refund as a connector reads it from the mapped payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of a refund payload.
 */
final class RefundData {

	/**
	 * Create the object.
	 *
	 * @param string $reference   Refund reference WC-{refund id}.
	 * @param string $orderRef    Reference of the refunded order, WC-{order id}.
	 * @param int    $amountMinor Refund amount, minor units, positive.
	 * @param string $currency    ISO currency code, may be empty.
	 * @param string $reason      Reason typed by the shop owner.
	 * @param string $date        Refund date, Y-m-d.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $orderRef,
		public readonly int $amountMinor,
		public readonly string $currency,
		public readonly string $reason,
		public readonly string $date,
	) {
	}
}
