<?php
/**
 * One order line as a connector reads it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts are integer minor units; the quantity is a decimal text so that 1.5 stays 1.5.
 */
final class OrderLine {

	/**
	 * Create the object.
	 *
	 * @param string $name       Product name as typed in the shop.
	 * @param string $sku        SKU, may be empty.
	 * @param string $quantity   Decimal text, greater than zero.
	 * @param int    $unitMinor  Unit price without tax, minor units.
	 * @param int    $totalMinor Line total without tax, minor units.
	 * @param int    $taxMinor   Line tax, minor units.
	 * @param string $taxCode    The system's tax code for the line, from the tax mapping; empty when the mapping gives none.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $sku,
		public readonly string $quantity,
		public readonly int $unitMinor,
		public readonly int $totalMinor,
		public readonly int $taxMinor = 0,
		public readonly string $taxCode = '',
	) {
	}

	/**
	 * Whether the quantity is a whole number.
	 */
	public function isWhole(): bool {
		return 1 === preg_match( '/^\d+(\.0+)?$/', $this->quantity );
	}

	/**
	 * The quantity as a whole number when it is one, else as a float for a JSON number.
	 */
	public function quantityNumber(): int|float {
		return $this->isWhole() ? (int) $this->quantity : (float) $this->quantity;
	}
}
