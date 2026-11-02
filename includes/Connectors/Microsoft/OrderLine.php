<?php
/**
 * One product line of a mapped order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * A product line as the mapping engine hands it over: SKU, name, quantity, unit price without tax in minor units (or with tax when the
 * order says so), the system's tax code and the tax amount of the line in minor units.
 */
final class OrderLine {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string    $sku         SKU.
	 * @param string    $name        Line text.
	 * @param int|float $quantity    Quantity, above zero.
	 * @param int       $priceMinor  Unit price in minor units.
	 * @param string    $taxCode     System tax code, or an empty string.
	 * @param int       $taxMinor    Tax of the whole line in minor units (0 when not mapped).
	 */
	public function __construct(
		public readonly string $sku,
		public readonly string $name,
		public readonly int|float $quantity,
		public readonly int $priceMinor,
		public readonly string $taxCode = '',
		public readonly int $taxMinor = 0,
	) {
	}
}
