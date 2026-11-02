<?php
/**
 * Compares the total Business Central worked out with the total of the shop.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Business Central computes tax from its own setup, so the only proof that the books will carry the right money is to read the document back:
 * its currency must be the order's, and its total with tax must be the order's total within one minor unit (rounding). A difference is a
 * refusal that names both totals and, when the document treats prices with or without tax the other way round, says so.
 */
final class BcTotals {

	/**
	 * Check a document header.
	 *
	 * @param array<string,mixed> $header           Header read back after the lines were written.
	 * @param int|null            $expectedMinor    The shop's total with tax in minor units, or null to skip the comparison.
	 * @param string              $currency         Currency of the order.
	 * @param bool                $pricesIncludeTax Whether the prices the connector wrote include tax.
	 * @param bool                $fail             True to refuse a difference; false to accept it with a warning.
	 * @param string              $system           System name.
	 * @return array{error:ConnectorError|null,warning:string,total:int}
	 */
	public static function compare( array $header, ?int $expectedMinor, string $currency, bool $pricesIncludeTax, bool $fail, string $system ): array {
		$found = Amounts::minor( $header['totalAmountIncludingTax'] ?? 0, $currency );
		$came  = strtoupper( trim( (string) ( $header['currencyCode'] ?? '' ) ) );
		if ( '' !== $came && strtoupper( $currency ) !== $came ) {
			return array(
				'error'   => Failures::currencyDiffers( $system, $currency, $came ),
				'warning' => '',
				'total'   => $found,
			);
		}
		if ( null === $expectedMinor || Amounts::near( $found, $expectedMinor ) ) {
			return array(
				'error'   => null,
				'warning' => '',
				'total'   => $found,
			);
		}
		$hint = self::hint( $header, $pricesIncludeTax );
		$text = sprintf( '%1$s %2$s / %3$s %2$s', Amounts::text( $expectedMinor, $currency ), $currency, Amounts::text( $found, $currency ) );
		if ( ! $fail ) {
			return array(
				'error'   => null,
				'warning' => $text,
				'total'   => $found,
			);
		}
		return array(
			'error'   => Failures::totalDiffers( $system, Amounts::text( $expectedMinor, $currency ) . ' ' . $currency, Amounts::text( $found, $currency ) . ' ' . $currency, $hint ),
			'warning' => '',
			'total'   => $found,
		);
	}

	/**
	 * What to look at when the totals differ.
	 *
	 * @param array<string,mixed> $header           Header.
	 * @param bool                $pricesIncludeTax What the connector wrote.
	 */
	private static function hint( array $header, bool $pricesIncludeTax ): string {
		if ( isset( $header['pricesIncludeTax'] ) && (bool) $header['pricesIncludeTax'] !== $pricesIncludeTax ) {
			return $pricesIncludeTax
				? __( 'The shop sent prices with tax, but Business Central treats this customer\'s prices as without tax. Change the customer, then delete the draft document and try again.', 'estasoft-erp-crm-sync-engine' )
				: __( 'The shop sent prices without tax, but Business Central treats this customer\'s prices as with tax. Change the customer, then delete the draft document and try again.', 'estasoft-erp-crm-sync-engine' );
		}
		return __( 'Check the tax groups in the tax mapping and in Business Central, then delete the draft document and try again.', 'estasoft-erp-crm-sync-engine' );
	}
}
