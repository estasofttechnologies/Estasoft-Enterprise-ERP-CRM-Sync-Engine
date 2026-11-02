<?php
/**
 * Compares what Xero worked out with what the shop expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Xero works out tax and totals itself, per line, so a document is read back before it is approved: it must be in the order's currency and
 * its total must agree with the shop's within one minor unit.
 */
final class XeroTotals {

	/**
	 * Set up with what it depends on.
	 *
	 * @param XeroAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly XeroAmounts $amounts ) {
	}

	/**
	 * Check a document.
	 *
	 * @param array<string,mixed> $document Invoice or credit note as Xero returned it.
	 * @param string              $currency Currency the document must be in.
	 * @param int|null            $expected Total the shop expects, or null to skip the total.
	 * @return ConnectorError|null The failure, or null when all agrees.
	 */
	public function check( array $document, string $currency, ?int $expected ): ?ConnectorError {
		$actualCurrency = strtoupper( XeroPayloadReader::text( $document['CurrencyCode'] ?? '' ) );
		if ( '' !== $actualCurrency && '' !== $currency && $actualCurrency !== $currency ) {
			return XeroErrors::refused(
				'CurrencyCode',
				sprintf(
					/* translators: 1: currency the shop used, 2: currency Xero used. */
					__( 'The shop sold in %1$s but Xero made the document in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$currency,
					$actualCurrency
				)
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$use    = '' === $actualCurrency ? $currency : $actualCurrency;
		$actual = $this->amounts->fromNumber( $document['Total'] ?? null, $use );
		if ( null === $actual ) {
			return XeroErrors::refused( 'Total', __( 'Xero did not report a total to compare.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( $this->amounts->agree( $expected, $actual ) ) {
			return null;
		}
		return XeroErrors::refused(
			'Total',
			sprintf(
				/* translators: 1: total of the shop, 2: total Xero worked out. */
				__( 'The shop total is %1$s but Xero worked out %2$s. Check the tax mapping and whether prices include tax.', 'estasoft-erp-crm-sync-engine' ),
				$this->amounts->show( $expected, $use ),
				$this->amounts->show( $actual, $use )
			)
		);
	}
}
