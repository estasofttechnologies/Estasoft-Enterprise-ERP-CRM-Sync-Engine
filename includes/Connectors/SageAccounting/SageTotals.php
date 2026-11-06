<?php
/**
 * The check of a created document against the shop's total.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Sage works out the net, the tax and the total from the lines it was sent, so a draft is read back before it is released: it must be in the business currency and its
 * total must agree with the shop's within one minor unit.
 */
final class SageTotals {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly SageAmounts $amounts ) {
	}

	/**
	 * Check a document.
	 *
	 * @param array<string,mixed> $document Invoice or credit note as Sage returned it.
	 * @param string              $currency Currency of the business.
	 * @param int|null            $expected  Total the shop expects, or null to skip the total.
	 * @param int                 $tolerance Most the totals may differ, in minor units (more for prices that include tax, which are converted line by line).
	 * @return ConnectorError|null The failure, or null when all agrees.
	 */
	public function check( array $document, string $currency, ?int $expected, int $tolerance = 1 ): ?ConnectorError {
		$actualCurrency = strtoupper( SagePayloadReader::text( $document['currency']['id'] ?? ( $document['currency'] ?? '' ) ) );
		if ( '' !== $actualCurrency && '' !== $currency && $actualCurrency !== $currency ) {
			return SageErrors::refused(
				'currency',
				sprintf(
					/* translators: 1: currency the business works in, 2: currency Sage used. */
					__( 'The business works in %1$s but Sage made the document in %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$currency,
					$actualCurrency
				)
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$actual = $this->amounts->fromNumber( $document['total_amount'] ?? null, '' !== $currency ? $currency : 'GBP' );
		if ( null === $actual ) {
			return SageErrors::refused( 'total_amount', __( 'Sage did not report a total to compare.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( $this->amounts->agree( abs( $expected ), abs( $actual ), $tolerance ) ) {
			return null;
		}
		return SageErrors::refused(
			'total_amount',
			sprintf(
				/* translators: 1: total of the shop, 2: total Sage worked out. */
				__( 'The shop total is %1$s but Sage worked out %2$s. Check the tax mapping.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$this->amounts->show( abs( $expected ), $currency ),
				$this->amounts->show( abs( $actual ), $currency )
			)
		);
	}
}
