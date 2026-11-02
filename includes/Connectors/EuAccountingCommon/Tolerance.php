<?php
/**
 * How far the vendor's total may differ from the shop's.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce rounds the tax of every line and the vendors round per tax rate (or per document), so the same order can differ by a cent or two:
 * ten lines of 0.99 EUR at 19 % add up to 2 cents more in one system than in the other. The default allows one minor unit per document row, never
 * more than {@see Tolerance::CAP}. The option `total_tolerance` (a whole number of minor units from 0 to {@see Tolerance::MAX}) replaces the default;
 * an empty or invalid value means the default. A difference above the tolerance is never hidden: the item fails, or with `total_mismatch = warn`
 * it is kept and marked.
 */
final class Tolerance {

	/**
	 * Most the default allows, in minor units.
	 */
	public const CAP = 10;

	/**
	 * Most a merchant can set, in minor units.
	 */
	public const MAX = 50;

	/**
	 * The option key.
	 */
	public const OPTION = 'total_tolerance';

	/**
	 * Allowed difference in minor units.
	 *
	 * @param Options $options Settings.
	 * @param int     $rows    Rows of the document (lines, shipping, fees).
	 */
	public static function minor( Options $options, int $rows ): int {
		$text = $options->text( self::OPTION );
		if ( 1 === preg_match( '/^\d{1,2}$/', $text ) && (int) $text <= self::MAX ) {
			return (int) $text;
		}
		return min( self::CAP, max( 1, $rows ) );
	}

	/**
	 * Whether two totals are close enough.
	 *
	 * @param int     $actual   The vendor's total.
	 * @param int     $expected The shop's total.
	 * @param Options $options  Settings.
	 * @param int     $rows     Rows of the document.
	 */
	public static function accepts( int $actual, int $expected, Options $options, int $rows ): bool {
		return abs( $actual - $expected ) <= self::minor( $options, $rows );
	}
}
