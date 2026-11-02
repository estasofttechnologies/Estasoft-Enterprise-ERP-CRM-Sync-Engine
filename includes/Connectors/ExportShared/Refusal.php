<?php
/**
 * An order that cannot go into an export file.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown while an order is turned into file rows when the file cannot hold it (a ledger is missing, the currency is not the books'
 * currency, the totals disagree). The message is the short sentence the Exports screen shows after SYNC-EXP-002, already translated.
 * The connector catches it and leaves the order out of the file; nothing else is lost.
 */
final class Refusal extends \RuntimeException {

	/**
	 * Build a refusal from a translated reason.
	 *
	 * @param string $reason Short sentence without a trailing full stop.
	 */
	public static function because( string $reason ): self {
		return new self( $reason );
	}
}
