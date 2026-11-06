<?php
/**
 * Compares what QuickBooks worked out with what the shop expects.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * QuickBooks works out tax and totals itself, and an invoice is posted when it is created, so the document is read back at once: it must be in the
 * order's currency and its total must agree with the shop's within one minor unit.
 */
final class QuickBooksTotals {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly QuickBooksAmounts $amounts ) {
	}

	/**
	 * Check a document.
	 *
	 * @param array<string,mixed> $document Invoice or credit memo as QuickBooks returned it.
	 * @param string              $currency Currency the document must be in (the order's, else the home currency).
	 * @param string              $home     Home currency of the company; a document without CurrencyRef is in it.
	 * @param int|null            $expected Total the shop expects, or null to skip the total.
	 * @return ConnectorError|null The failure, or null when all agrees.
	 */
	public function check( array $document, string $currency, string $home, ?int $expected ): ?ConnectorError {
		$actualCurrency = strtoupper( QuickBooksPayloadReader::text( $document['CurrencyRef']['value'] ?? '' ) );
		$actualCurrency = '' !== $actualCurrency ? $actualCurrency : $home;
		if ( '' !== $actualCurrency && '' !== $currency && $actualCurrency !== $currency ) {
			return QuickBooksErrors::refused(
				'CurrencyRef',
				sprintf(
					/* translators: 1: currency the shop used, 2: currency QuickBooks used. */
					__( 'The shop sold in %1$s but QuickBooks made the document in %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$currency,
					$actualCurrency
				)
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$use    = '' !== $actualCurrency ? $actualCurrency : 'USD';
		$actual = $this->amounts->fromNumber( $document['TotalAmt'] ?? null, $use );
		if ( null === $actual ) {
			return QuickBooksErrors::refused( 'TotalAmt', __( 'QuickBooks did not report a total to compare.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( $this->amounts->agree( $expected, $actual ) ) {
			return null;
		}
		return QuickBooksErrors::refused(
			'TotalAmt',
			sprintf(
				/* translators: 1: total of the shop, 2: total QuickBooks worked out. */
				__( 'The shop total is %1$s but QuickBooks worked out %2$s. Check the tax mapping and whether prices include tax.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$this->amounts->show( $expected, $use ),
				$this->amounts->show( $actual, $use )
			)
		);
	}
}
