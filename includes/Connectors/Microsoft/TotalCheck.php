<?php
/**
 * Compares a total the system worked out with the shop's total.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * One minor unit is rounding. More is a refusal that names both totals (or, when the connection says so, a warning in the result). A total
 * the system did not fill in cannot be compared; that is a warning, never a failure, because the order itself may be right.
 */
final class TotalCheck {

	/**
	 * Compare.
	 *
	 * @param int|null $found    The system's total in minor units, or null when it is not there.
	 * @param int|null $expected The shop's total in minor units, or null to skip the comparison.
	 * @param string   $currency ISO code.
	 * @param bool     $fail     True to refuse a difference; false to accept it with a warning.
	 * @param string   $system   System name.
	 * @param string   $hint     What to look at when the totals differ.
	 * @return array{error:ConnectorError|null,warning:string}
	 */
	public static function compare( ?int $found, ?int $expected, string $currency, bool $fail, string $system, string $hint ): array {
		if ( null === $expected || 0 === $expected ) {
			return array(
				'error'   => null,
				'warning' => '',
			);
		}
		if ( null === $found || 0 === $found ) {
			return array(
				'error'   => null,
				'warning' => __( 'The system has not calculated a total for this order yet, so it could not be compared with the shop total.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			);
		}
		if ( Amounts::near( $found, $expected ) ) {
			return array(
				'error'   => null,
				'warning' => '',
			);
		}
		$shop   = Amounts::text( $expected, $currency ) . ' ' . $currency;
		$theirs = Amounts::text( $found, $currency ) . ' ' . $currency;
		if ( ! $fail ) {
			return array(
				'error'   => null,
				'warning' => sprintf(
					/* translators: 1: the shop's total, 2: the other system's total. */
					__( 'The shop total is %1$s and the total in the system is %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$shop,
					$theirs
				),
			);
		}
		return array(
			'error'   => Failures::totalDiffers( $system, $shop, $theirs, $hint ),
			'warning' => '',
		);
	}
}
