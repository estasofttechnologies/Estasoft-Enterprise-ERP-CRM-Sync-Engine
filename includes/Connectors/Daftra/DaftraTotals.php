<?php
/**
 * Compares the total Daftra reports with the total the shop expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One minor unit of difference is rounding. More than that, a currency that is not the order's, or a total Daftra did not report, stops the
 * document before it is finalized (`total_mismatch = fail`, the default) or is carried as a warning (`warn`).
 */
final class DaftraTotals {

	/**
	 * Largest difference, in minor units, that counts as rounding.
	 */
	public const TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly DaftraAmounts $amounts ) {
	}

	/**
	 * Check a document against the order.
	 *
	 * @param DaftraRecord      $record   The document as Daftra reports it.
	 * @param string            $currency The order's currency.
	 * @param int|null          $expected What the document should add up to, or null to skip the total check.
	 * @param bool              $fail     True to stop on a difference, false to warn.
	 * @param array<int,string> $warnings Warnings collected so far (added to).
	 * @return ConnectorError|null Null when the document passes.
	 */
	public function check( DaftraRecord $record, string $currency, ?int $expected, bool $fail, array &$warnings ): ?ConnectorError {
		$actualCurrency = $record->currency();
		if ( '' !== $actualCurrency && '' !== $currency && $actualCurrency !== $currency ) {
			return $this->refused(
				sprintf(
					/* translators: 1: currency Daftra shows, 2: currency of the order. */
					__( 'Daftra made the document in %1$s but the order is in %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$actualCurrency,
					$currency
				)
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$exponent = $this->amounts->exponent( '' !== $currency ? $currency : $actualCurrency );
		$actual   = $record->totalMinor( $exponent );
		if ( null === $actual ) {
			$message = __( 'Daftra did not report the total of the document, so it could not be compared with the order.', 'estasoft-enterprise-erpcrm-sync-engine' );
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
			/* translators: 1: total Daftra shows, 2: total of the order, 3: currency code. */
			__( 'Daftra shows %1$s but the order is %2$s (%3$s). Check the tax settings and the tax mapping.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$this->amounts->decimalString( $actual, $exponent ),
			$this->amounts->decimalString( $expected, $exponent ),
			'' !== $currency ? $currency : $actualCurrency
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
				'system' => DaftraConnector::LABEL,
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
