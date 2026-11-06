<?php
/**
 * Compares a system's total of a document with the shop's.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * An ERP adds up lines and taxes itself, so its total can differ from the shop's when a tax is set up differently, when a tax is
 * included in the price on one side only, or when a rounding rule differs. A difference of one minor unit is rounding and is accepted.
 * Anything more is reported: by default the document is not finished (total_mismatch = fail), or it goes on with a warning (warn).
 */
final class TotalCheck {

	/**
	 * Largest difference, in minor units, that counts as rounding.
	 */
	public const TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param Options $options Connection options.
	 * @param Amounts $amounts Conversion helper.
	 * @param string  $system  System name.
	 */
	public function __construct( private readonly Options $options, private readonly Amounts $amounts, private readonly string $system ) {
	}

	/**
	 * Whether a difference stops the document (the default) or is only reported.
	 */
	public function isStrict(): bool {
		return 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' );
	}

	/**
	 * Compare the totals.
	 *
	 * @param int|null $expectedMinor The shop's total in minor units, or null when the payload has none (nothing to compare).
	 * @param mixed    $systemTotal   The system's total as read.
	 * @param string   $currency      ISO currency code of the document.
	 * @param string   $document      Number of the document, for the message.
	 * @return array{code:string,expected_minor:int,system_minor:int,currency:string,document:string,message:string}|null The difference, or null when the totals agree.
	 */
	public function difference( ?int $expectedMinor, mixed $systemTotal, string $currency, string $document ): ?array {
		if ( null === $expectedMinor ) {
			return null;
		}
		$exponent = $this->amounts->exponent( $currency );
		$actual   = $this->amounts->toMinor( $systemTotal, $exponent );
		if ( abs( $actual - $expectedMinor ) <= self::TOLERANCE ) {
			return null;
		}
		return array(
			'code'           => 'total_mismatch',
			'expected_minor' => $expectedMinor,
			'system_minor'   => $actual,
			'currency'       => $currency,
			'document'       => $document,
			'message'        => sprintf(
				/* translators: 1: system name, 2: document number, 3: the system's total with currency, 4: the shop's total with currency. */
				__( '%1$s calculates %3$s for %2$s but the shop total is %4$s. Check that the taxes there have the same rates as the shop and that prices with or without tax are set the same way on both sides.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$this->system,
				$document,
				$this->amounts->toDecimalString( $actual, $exponent ) . ' ' . $currency,
				$this->amounts->toDecimalString( $expectedMinor, $exponent ) . ' ' . $currency
			),
		);
	}

	/**
	 * The error that stops a document.
	 *
	 * @param array{message:string} $difference Result of difference().
	 * @param string                $outcome    What happened to the document: a sentence for the person.
	 */
	public function error( array $difference, string $outcome ): ConnectorError {
		return Problems::refused( $this->system, 'total', $difference['message'] . ' ' . $outcome, 'total mismatch' );
	}
}
