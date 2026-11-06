<?php
/**
 * Compares the total Qoyod reports with the total the shop expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One minor unit of difference is rounding. More than that, a currency that is not the order's, or a total Qoyod did not report, stops the
 * document before it is finalized (`total_mismatch = fail`, the default) or is carried as a warning (`warn`).
 */
final class QoyodTotals {

	/**
	 * Largest difference, in minor units, that counts as rounding.
	 */
	public const TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly QoyodAmounts $amounts ) {
	}

	/**
	 * Check a document against the order.
	 *
	 * @param QoyodRecord       $record   The document as Qoyod reports it.
	 * @param string            $currency The currency of the document (Qoyod's answer carries none, so it is the account currency).
	 * @param int|null          $expected What the document should add up to, or null to skip the total check.
	 * @param bool              $fail     True to stop on a difference, false to warn.
	 * @param array<int,string> $warnings Warnings collected so far (added to).
	 * @return ConnectorError|null Null when the document passes.
	 */
	public function check( QoyodRecord $record, string $currency, ?int $expected, bool $fail, array &$warnings ): ?ConnectorError {
		if ( null === $expected ) {
			return null;
		}
		$exponent = $this->amounts->exponent( $currency );
		$actual   = $record->totalMinor( $exponent );
		if ( null === $actual ) {
			$message = __( 'Qoyod did not report the total of the document, so it could not be compared with the order.', 'estasoft-enterprise-erpcrm-sync-engine' );
			if ( $fail ) {
				return $this->refused( $message );
			}
			$warnings[] = $message;
			return null;
		}
		if ( abs( $actual - $expected ) <= self::TOLERANCE ) {
			return null;
		}
		$message = sprintf(
			/* translators: 1: total Qoyod shows, 2: total of the order, 3: currency code. */
			__( 'Qoyod shows %1$s but the order is %2$s (%3$s). Check the tax settings and the tax mapping.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$this->amounts->decimalString( $actual, $exponent ),
			$this->amounts->decimalString( $expected, $exponent ),
			$currency
		);
		if ( $fail ) {
			return $this->refused( $message );
		}
		$warnings[] = $message;
		return null;
	}

	/**
	 * A SYNC-VAL-002 about a total.
	 *
	 * @param string $reason Reason.
	 */
	private function refused( string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => 'total',
				'reason' => $reason,
			),
			null,
			null,
			null,
			'total check'
		);
	}
}
