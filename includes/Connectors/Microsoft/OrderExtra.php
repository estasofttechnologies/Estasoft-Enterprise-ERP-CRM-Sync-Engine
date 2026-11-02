<?php
/**
 * Shipping, a fee or a discount on a mapped order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * An amount that is not a product: shipping, a fee or the order discount. Amounts are in minor units, without tax unless the order says
 * the prices include tax.
 */
final class OrderExtra {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string $name       Text of the line.
	 * @param int    $totalMinor Amount in minor units (above zero).
	 * @param string $taxCode    System tax code, or an empty string.
	 * @param int    $taxMinor   Tax in minor units (0 when not mapped).
	 */
	public function __construct(
		public readonly string $name,
		public readonly int $totalMinor,
		public readonly string $taxCode = '',
		public readonly int $taxMinor = 0,
	) {
	}
}
