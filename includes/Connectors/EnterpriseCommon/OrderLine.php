<?php
/**
 * One product line of a mapped order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * A product, a quantity and a unit price (minor units, before tax unless the order says otherwise).
 */
final class OrderLine {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                 $sku        Product code the shop sells under.
	 * @param string                 $name       Product name, or empty.
	 * @param string                 $quantity   Plain decimal text ("2", "1.5").
	 * @param int                    $priceMinor Unit price in minor units.
	 * @param array<int,string>|null $taxCodes   Tax codes the mapping chose; null when the mapping gave none at all.
	 */
	public function __construct(
		public readonly string $sku,
		public readonly string $name,
		public readonly string $quantity,
		public readonly int $priceMinor,
		public readonly ?array $taxCodes = null,
	) {
	}

	/**
	 * The first tax code, or an empty string.
	 */
	public function taxCode(): string {
		return null !== $this->taxCodes && isset( $this->taxCodes[0] ) ? $this->taxCodes[0] : '';
	}
}
