<?php
/**
 * A shipping or fee line of an export document.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping and fees carry an amount and a tax but no quantity.
 */
final class ExportCharge {

	/**
	 * Set up with the values.
	 *
	 * @param string $name    Text of the charge.
	 * @param int    $net     Amount without tax, minor units.
	 * @param int    $tax     Tax, minor units.
	 * @param string $taxCode Code from the tax mapping, or empty.
	 * @param string $taxRate Rate in percent as text, or empty.
	 */
	public function __construct(
		public readonly string $name,
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
