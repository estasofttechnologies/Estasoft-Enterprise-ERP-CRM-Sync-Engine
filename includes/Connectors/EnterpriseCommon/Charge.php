<?php
/**
 * A shipping, fee or discount amount of a mapped order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * An amount that is not a product: kind `shipping`, `fee` or `discount`. A discount carries a negative amount.
 */
final class Charge {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                 $kind        shipping, fee or discount.
	 * @param string                 $name        Text for the line.
	 * @param int                    $amountMinor Signed amount in minor units.
	 * @param array<int,string>|null $taxCodes    Tax codes the mapping chose; null when it gave none.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $name,
		public readonly int $amountMinor,
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
