<?php
/**
 * Free text for a delimited export file, made safe for a spreadsheet before it is cut to a field length.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter;

/**
 * A text that a customer or a merchant typed (a name, a description, a reference) can start with `=`, `+`, `-` or `@`, and a spreadsheet then
 * runs it as a formula when the accountant opens the file. `Support\Export\CsvWriter` puts one space in front of such a text; the accounting
 * packages read the space as padding. The space counts toward the field length of the importer, so the length is checked after it is added:
 * these helpers add it first and cut second, and the writer later finds nothing left to change. Amounts, dates, account numbers and tax keys
 * never go through here.
 */
final class SafeText {

	/**
	 * The text as the writer will write it: one space in front when it starts like a formula and is not a plain number.
	 *
	 * @param string $text One line of text (see Utf8::line()).
	 */
	public static function safe( string $text ): string {
		return CsvWriter::neutralise( $text, CsvWriter::SPACE );
	}

	/**
	 * The safe text shortened to a field length.
	 *
	 * @param string $text One line of text.
	 * @param int    $max  Largest number of characters of the importer's field.
	 * @return array{text:string,cut:int} The text and 1 when it was shortened.
	 */
	public static function fit( string $text, int $max ): array {
		$safe = self::safe( $text );
		$cut  = Utf8::cut( $safe, $max );
		return array(
			'text' => $cut,
			'cut'  => $cut === $safe ? 0 : 1,
		);
	}
}
