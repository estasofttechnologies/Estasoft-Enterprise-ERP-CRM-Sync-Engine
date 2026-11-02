<?php
/**
 * Writes and reads the total the shop charged inside a document's note.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The engine links whatever findExisting() returns and does not call send() for it, and findExisting() has no payload. A document that
 * was created with a wrong total (a tax setting, a price list) must therefore still be recognised on the next pass. The shop's total is
 * written as the last line of the document's note ("Shop total 59.97 AED"), and findExisting() compares it with the system's total.
 * Editing the note by hand only switches that check off for the document.
 */
final class ShopTotalMarker {

	/**
	 * Set up with what it depends on.
	 *
	 * @param Amounts $amounts Conversion helper.
	 */
	public function __construct( private readonly Amounts $amounts ) {
	}

	/**
	 * A note with the marker as its last line.
	 *
	 * @param string   $note       Note text, may be empty.
	 * @param int|null $totalMinor Total the shop charged, or null to leave the note alone.
	 * @param string   $currency   ISO code.
	 */
	public function append( string $note, ?int $totalMinor, string $currency ): string {
		if ( null === $totalMinor ) {
			return $note;
		}
		$marker = sprintf( 'Shop total %s %s', $this->amounts->toDecimal( $totalMinor, $currency ), strtoupper( $currency ) );
		return '' === $note ? $marker : $note . "\n" . $marker;
	}

	/**
	 * The shop total a note carries.
	 *
	 * @param string $note Note text of the document.
	 * @return array{minor:int,currency:string}|null Null when the note has no marker.
	 */
	public function read( string $note ): ?array {
		if ( 1 !== preg_match( '/Shop total (-?\d+(?:\.\d+)?) ([A-Z]{3})\s*$/', $note, $match ) ) {
			return null;
		}
		$minor = $this->amounts->fromWire( $match[1], $match[2] );
		return null === $minor ? null : array(
			'minor'    => $minor,
			'currency' => $match[2],
		);
	}
}
