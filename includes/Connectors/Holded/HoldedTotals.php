<?php
/**
 * Compares the total Holded reports with the total the shop expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A difference of up to one minor unit per document line is rounding (Holded and the shop round tax line by line or on the whole, and the
 * documentation does not say which). More than that, a currency that is not the order's, or a total Holded did not report, is an error
 * (`total_mismatch = fail`, the default) or a warning (`warn`).
 */
final class HoldedTotals {

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly HoldedAmounts $amounts ) {
	}

	/**
	 * Check a document against the order.
	 *
	 * @param HoldedRecord      $record   The document as Holded reports it.
	 * @param string            $currency The order's currency.
	 * @param int|null          $expected What the document should add up to, or null to skip the total check.
	 * @param bool              $fail     True to stop on a difference, false to warn.
	 * @param array<int,string> $warnings Warnings collected so far (added to).
	 * @param int               $lines    Number of lines of the document: the most minor units the totals may differ by without it being a mistake.
	 * @return ConnectorError|null Null when the document passes.
	 */
	public function check( HoldedRecord $record, string $currency, ?int $expected, bool $fail, array &$warnings, int $lines = 1 ): ?ConnectorError {
		$actualCurrency = $record->currency();
		if ( '' !== $actualCurrency && '' !== $currency && $actualCurrency !== $currency ) {
			return $this->refused(
				sprintf(
					/* translators: 1: currency Holded shows, 2: currency of the order. */
					__( 'Holded made the document in %1$s but the order is in %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
			$message = __( 'Holded did not report the total of the document, so it could not be compared with the order.', 'estasoft-enterprise-erpcrm-sync-engine' );
			if ( $fail ) {
				return $this->refused( $message );
			}
			$warnings[] = $message;
			return null;
		}
		if ( abs( $actual - $expected ) <= max( 1, $lines ) ) {
			return null;
		}
		$message = sprintf(
			/* translators: 1: total Holded shows, 2: total of the order, 3: currency code. */
			__( 'Holded shows %1$s but the order is %2$s (%3$s). Check the tax mapping.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
	 * Check what the lines add up to against the order, before anything is sent.
	 *
	 * @param int               $planned  The total the lines add up to, in minor units.
	 * @param int|null          $expected What the order says, or null to skip the check.
	 * @param string            $currency The order's currency.
	 * @param bool              $fail     True to stop on a difference, false to warn.
	 * @param array<int,string> $warnings Warnings collected so far (added to).
	 * @param int               $lines    Number of lines.
	 * @return ConnectorError|null Null when the lines pass.
	 */
	public function plan( int $planned, ?int $expected, string $currency, bool $fail, array &$warnings, int $lines = 1 ): ?ConnectorError {
		if ( null === $expected || abs( $planned - $expected ) <= max( 1, $lines ) ) {
			return null;
		}
		$exponent = $this->amounts->exponent( $currency );
		$message  = sprintf(
			/* translators: 1: total of the lines, 2: total of the order, 3: currency code. */
			__( 'The lines add up to %1$s but the order is %2$s (%3$s). Check the tax mapping.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$this->amounts->decimalString( $planned, $exponent ),
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
	 * Whether a document the connector made still shows what it promised in its notes. A document that does not is one that failed its check
	 * and was left in Holded: it is not a finished record.
	 *
	 * @param HoldedRecord                              $record  The document.
	 * @param array{amount:string,currency:string}|null $promise What the notes promise, or null when they promise nothing.
	 */
	public function kept( HoldedRecord $record, ?array $promise ): bool {
		if ( null === $promise ) {
			return true;
		}
		$exponent = $this->amounts->exponent( $promise['currency'] );
		$expected = $this->amounts->toMinor( $promise['amount'], $exponent );
		$actual   = $record->totalMinor( $exponent );
		if ( null === $expected || null === $actual ) {
			return true;
		}
		$currency = $record->currency();
		if ( '' !== $currency && $currency !== $promise['currency'] ) {
			return false;
		}
		return abs( $actual - $expected ) <= max( 1, $record->lineCount() ?? 5 );
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
				'system' => HoldedConnector::LABEL,
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
