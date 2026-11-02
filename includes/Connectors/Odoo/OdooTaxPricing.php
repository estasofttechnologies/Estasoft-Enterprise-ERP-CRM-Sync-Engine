<?php
/**
 * Makes a price from the shop agree with the way Odoo's taxes treat prices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A tax in Odoo is either "included in price" (the unit price already holds the tax) or not (the tax is added on top). The
 * prices in the payload either exclude tax (what the WooCommerce reader supplies: line subtotals are stored without tax) or
 * include it (a mapping that sends gross prices sets prices_include_tax). When the two disagree the order total in Odoo would be
 * wrong, so the price is converted with whole-number arithmetic (minor units, percentages in ten-thousandths of a percent,
 * rounding half away from zero) when the line has one plain percentage tax, and refused with SYNC-VAL-002, naming the tax,
 * when it cannot be converted exactly (several taxes, fixed, division or group taxes).
 */
final class OdooTaxPricing {

	/**
	 * Largest amount the integer arithmetic can handle without overflow (minor units).
	 */
	private const MAX_MINOR = 900000000000;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooCatalog $catalog Lookups.
	 */
	public function __construct( private readonly OdooCatalog $catalog ) {
	}

	/**
	 * The price to send to Odoo for a line.
	 *
	 * @param int               $minor              Price in minor units as the payload gives it.
	 * @param array<int,string> $codes              Tax codes of the line.
	 * @param array<string,int> $taxIds             Tax id by code.
	 * @param bool              $payloadIncludesTax True when the payload's prices include tax.
	 * @return int Price in minor units for the unit price field of the Odoo line.
	 *
	 * @throws OdooException With SYNC-VAL-002 when the price cannot be made to agree with the taxes.
	 */
	public function price( int $minor, array $codes, array $taxIds, bool $payloadIncludesTax ): int {
		$ids = array();
		foreach ( $codes as $code ) {
			if ( isset( $taxIds[ $code ] ) ) {
				$ids[] = $taxIds[ $code ];
			}
		}
		if ( array() === $ids || 0 === $minor ) {
			return $minor;
		}
		$details  = $this->catalog->taxDetails( $ids );
		$mismatch = array();
		foreach ( $details as $tax ) {
			if ( $tax['included'] !== $payloadIncludesTax && ( ! $tax['percent'] || 0 !== $tax['rate'] ) ) {
				$mismatch[] = $tax;
			}
		}
		if ( array() === $mismatch ) {
			return $minor;
		}
		$first = $mismatch[0];
		if ( count( $details ) > 1 || ! $first['percent'] || abs( $minor ) > self::MAX_MINOR ) {
			$exception = $this->refused( $first['name'], $payloadIncludesTax );
			throw $exception;
		}
		return $payloadIncludesTax ? $this->withoutTax( $minor, $first['rate'] ) : $this->withTax( $minor, $first['rate'] );
	}

	/**
	 * Add a percentage to an amount.
	 *
	 * @param int $minor Amount in minor units without tax.
	 * @param int $rate  Percentage in ten-thousandths of a percent.
	 */
	private function withTax( int $minor, int $rate ): int {
		$sign = $minor < 0 ? -1 : 1;
		$abs  = abs( $minor );
		return $sign * ( $abs + intdiv( $abs * $rate + 500000, 1000000 ) );
	}

	/**
	 * Take a percentage out of an amount that includes it.
	 *
	 * @param int $minor Amount in minor units with tax.
	 * @param int $rate  Percentage in ten-thousandths of a percent.
	 */
	private function withoutTax( int $minor, int $rate ): int {
		$sign        = $minor < 0 ? -1 : 1;
		$abs         = abs( $minor );
		$denominator = 1000000 + $rate;
		return $sign * intdiv( $abs * 1000000 + intdiv( $denominator, 2 ), $denominator );
	}

	/**
	 * The error for a tax that cannot be matched to the shop's prices.
	 *
	 * @param string $tax                Tax name.
	 * @param bool   $payloadIncludesTax Whether the payload's prices include tax.
	 */
	private function refused( string $tax, bool $payloadIncludesTax ): OdooException {
		return new OdooException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => OdooErrorMapper::SYSTEM,
					'field'  => 'tax',
					'reason' => sprintf(
						/* translators: 1: tax name in Odoo, 2: how the shop's prices are described. */
						__( 'The tax "%1$s" in Odoo does not treat prices the way the shop does (the shop\'s prices %2$s). Change "Included in Price" on that tax in Odoo, or use a single plain percentage tax for the line.', 'estasoft-erp-crm-sync-engine' ),
						$tax,
						$payloadIncludesTax ? __( 'include tax', 'estasoft-erp-crm-sync-engine' ) : __( 'do not include tax', 'estasoft-erp-crm-sync-engine' )
					),
				),
				null,
				null,
				null,
				'tax price mismatch'
			)
		);
	}
}
