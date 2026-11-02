<?php
/**
 * A product line of an export document.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * One product line, in minor units. Amounts are never negative here: the direction (sale or credit note) belongs to the document.
 */
final class ExportLine {

	/**
	 * Set up with the values.
	 *
	 * @param string $sku       SKU, or empty.
	 * @param string $name      Product name, or empty.
	 * @param string $quantity  Quantity as a decimal string (at most four decimals), above zero.
	 * @param int    $unitPrice Unit price before discount and without tax, or 0 when unknown.
	 * @param int    $net       Line amount after discount, without tax.
	 * @param int    $tax       Tax of the line.
	 * @param string $taxCode   Code from the tax mapping, or empty when the line has none.
	 * @param string $taxRate   Rate in percent as text ("5", "7.5"), or empty when unknown.
	 */
	public function __construct(
		public readonly string $sku,
		public readonly string $name,
		public readonly string $quantity,
		public readonly int $unitPrice,
		public readonly int $net,
		public readonly int $tax,
		public readonly string $taxCode,
		public readonly string $taxRate,
	) {
	}

	/**
	 * Net plus tax.
	 */
	public function gross(): int {
		return $this->net + $this->tax;
	}
}
