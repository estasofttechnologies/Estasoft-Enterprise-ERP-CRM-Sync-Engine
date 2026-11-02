<?php
/**
 * Compares the total a system worked out with the total the shop charged.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * A difference of one minor unit is rounding. More than that stops the order (`fail`, the default) or lets it go on with a warning (`warn`),
 * so a tax setting, a price list or a currency that the connector cannot see never syncs wrong money silently.
 */
final class TotalCheck {

	/**
	 * Set up with what it depends on.
	 *
	 * @param Amounts $amounts Conversion helper.
	 * @param string  $system  System name for messages.
	 * @param string  $mode    fail or warn.
	 */
	public function __construct( private readonly Amounts $amounts, private readonly string $system, private readonly string $mode = 'fail' ) {
	}

	/**
	 * Check the total.
	 *
	 * @param int|null $expected What the shop charged (less excluded products), or null to skip.
	 * @param int|null $actual   What the system shows, or null when it did not say.
	 * @param string   $currency ISO code.
	 * @param Warnings $warnings Receives a warning when the check is skipped or a difference is accepted.
	 * @param string   $what     Words for the figure, for example "total" or "net amount".
	 * @return ConnectorError|null The failure, or null when the total is accepted.
	 */
	public function run( ?int $expected, ?int $actual, string $currency, Warnings $warnings, string $what = '' ): ?ConnectorError {
		$what = '' === $what ? __( 'total', 'estasoft-erp-crm-sync-engine' ) : $what;
		if ( null === $expected ) {
			return null;
		}
		if ( null === $actual ) {
			$warnings->add(
				sprintf(
					/* translators: 1: system name, 2: the word total. */
					__( '%1$s did not return a %2$s, so it could not be compared with the shop.', 'estasoft-erp-crm-sync-engine' ),
					$this->system,
					$what
				)
			);
			return null;
		}
		if ( $this->amounts->agree( $expected, $actual ) ) {
			return null;
		}
		$reason = sprintf(
			/* translators: 1: system name, 2: figure name, 3: amount in the system, 4: amount in the shop, 5: currency. */
			__( '%1$s shows a %2$s of %3$s %5$s but the shop charged %4$s %5$s.', 'estasoft-erp-crm-sync-engine' ),
			$this->system,
			$what,
			$this->amounts->toDecimal( $actual, $currency ),
			$this->amounts->toDecimal( $expected, $currency ),
			$currency
		);
		if ( 'warn' === $this->mode ) {
			$warnings->add( $reason );
			return null;
		}
		return Problem::validation( $this->system, $what, $reason );
	}
}
