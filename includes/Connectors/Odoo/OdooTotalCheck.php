<?php
/**
 * Compares Odoo's total of a document with the shop's.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Odoo adds up the lines and taxes itself, so its total can differ from the shop's when a tax is set up differently, when a
 * tax is included in the price on one side only, or when a rounding rule differs. A difference of one minor unit is rounding
 * and is accepted. Anything more is reported: by default the document is not confirmed (option total_mismatch = fail), or it
 * goes on with a warning on the result (total_mismatch = warn). All amounts are integer minor units.
 */
final class OdooTotalCheck {

	/**
	 * Largest difference, in minor units, that counts as rounding.
	 */
	private const TOLERANCE = 1;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooOptions $options Connection options.
	 * @param OdooAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly OdooOptions $options, private readonly OdooAmounts $amounts ) {
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
	 * @param mixed    $odooTotal     Odoo's amount_total as read.
	 * @param string   $currency      ISO currency code of the document.
	 * @param string   $document      Number of the Odoo document, for the message.
	 * @return array{code:string,expected_minor:int,odoo_minor:int,currency:string,document:string,message:string}|null The difference, or null when the totals agree.
	 */
	public function difference( ?int $expectedMinor, mixed $odooTotal, string $currency, string $document ): ?array {
		if ( null === $expectedMinor ) {
			return null;
		}
		$exponent = $this->amounts->exponent( $currency );
		$actual   = $this->amounts->toMinor( $odooTotal, $exponent );
		if ( abs( $actual - $expectedMinor ) <= self::TOLERANCE ) {
			return null;
		}
		return array(
			'code'           => 'total_mismatch',
			'expected_minor' => $expectedMinor,
			'odoo_minor'     => $actual,
			'currency'       => $currency,
			'document'       => $document,
			'message'        => sprintf(
				/* translators: 1: Odoo document number, 2: Odoo total with currency, 3: shop total with currency. */
				__( 'Odoo calculates %2$s for %1$s but the shop total is %3$s. Check that the taxes in Odoo have the same rates as the shop and that "Included in Price" is set the same way on both sides.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
	 * @param string                $outcome    What happened to the draft: a sentence for the person.
	 */
	public function error( array $difference, string $outcome ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => 'total',
				'reason' => $difference['message'] . ' ' . $outcome,
			),
			null,
			null,
			null,
			'total mismatch'
		);
	}
}
