<?php
/**
 * The check of a created document against the shop's total.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * MYOB works out tax and totals itself, and an invoice is posted when it is created, so the document is read back at once: it must be in the
 * file's own currency (no ForeignCurrency block) and its total must agree with the shop's within one minor unit. A credit may be reported with a
 * negative total, so the size of the total is compared.
 */
final class MyobTotals {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobAmounts $amounts Conversion helper.
	 */
	public function __construct( private readonly MyobAmounts $amounts ) {
	}

	/**
	 * Check a document.
	 *
	 * @param array<string,mixed> $document Invoice as MYOB returned it.
	 * @param string              $currency Currency of the file.
	 * @param int|null            $expected Total the shop expects, or null to skip the total.
	 * @return ConnectorError|null The failure, or null when all agrees.
	 */
	public function check( array $document, string $currency, ?int $expected ): ?ConnectorError {
		if ( ! empty( $document['ForeignCurrency'] ) ) {
			$foreign = is_array( $document['ForeignCurrency'] ) ? MyobPayloadReader::text( $document['ForeignCurrency']['CurrencyCode'] ?? ( $document['ForeignCurrency']['Code'] ?? '' ) ) : '';
			return MyobErrors::refused(
				'ForeignCurrency',
				sprintf(
					/* translators: 1: currency the file works in, 2: currency MYOB made the document in. */
					__( 'The company file works in %1$s but MYOB made the document in %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$currency,
					'' !== $foreign ? $foreign : __( 'a foreign currency', 'estasoft-enterprise-erpcrm-sync-engine' )
				)
			);
		}
		if ( null === $expected ) {
			return null;
		}
		$actual = $this->amounts->fromNumber( $document['TotalAmount'] ?? null, $currency );
		if ( null === $actual ) {
			return MyobErrors::refused( 'TotalAmount', __( 'MYOB did not report a total to compare.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$actual = abs( $actual );
		if ( $this->amounts->agree( abs( $expected ), $actual ) ) {
			return null;
		}
		return MyobErrors::refused(
			'TotalAmount',
			sprintf(
				/* translators: 1: total of the shop, 2: total MYOB worked out. */
				__( 'The shop total is %1$s but MYOB worked out %2$s. Check the tax mapping and whether prices include tax.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$this->amounts->show( abs( $expected ), $currency ),
				$this->amounts->show( $actual, $currency )
			)
		);
	}
}
