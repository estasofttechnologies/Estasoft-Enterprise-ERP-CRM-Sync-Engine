<?php
/**
 * One refunded line.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * A product (or none, for an amount-only refund), a quantity and the amount refunded for it, in minor units before tax.
 */
final class RefundLine {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                 $sku         Product code, or empty.
	 * @param string                 $quantity    Plain decimal text.
	 * @param int                    $amountMinor Amount refunded for the line in minor units.
	 * @param array<int,string>|null $taxCodes    Tax codes the mapping chose; null when it gave none.
	 */
	public function __construct(
		public readonly string $sku,
		public readonly string $quantity,
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
