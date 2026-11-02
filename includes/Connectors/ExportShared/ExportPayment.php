<?php
/**
 * A payment that settles an export document.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Written only when the connection records payments and the order is paid.
 */
final class ExportPayment {

	/**
	 * Set up with the values.
	 *
	 * @param string $method    WooCommerce payment method id (for example "stripe").
	 * @param string $title     Payment method title shown to the customer.
	 * @param string $date      Date the order was paid, Y-m-d in the shop's time zone.
	 * @param string $reference Gateway transaction id, or empty.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $title,
		public readonly string $date,
		public readonly string $reference,
	) {
	}
}
