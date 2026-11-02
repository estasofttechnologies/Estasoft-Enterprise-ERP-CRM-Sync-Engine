<?php
/**
 * CSV text for the Sage 50 import files.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage50;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;

/**
 * Comma-separated records the way RFC 4180 writes them, through the engine's CsvWriter: a field that holds a comma, a double quote, a line
 * break or a space at either end is put in double quotes with inner quotes doubled, and a line break inside a value becomes a space (one
 * record is one line). The columns that hold free text (names, descriptions, references) get one space in front when the text starts with
 * `=`, `+`, `-` or `@`, a tab or a carriage return, so a spreadsheet does not run it as a formula; Sage reads the space as padding. Every
 * other column (type, account reference, nominal or G/L code, dates, amounts, tax codes, quantities) is written as it is, so account
 * references with leading zeros stay text and the import rules for those columns stay as documented.
 */
final class Sage50Csv {

	/**
	 * One record.
	 *
	 * @param array<int,string> $fields Field values.
	 * @param array<int,int>    $text   Zero-based columns that hold free text.
	 */
	public static function line( array $fields, array $text = array() ): string {
		$format = ExportFormat::csv( 'Sage 50 CSV import' );
		$free   = $format->writer( CsvWriter::SPACE, "\r\n", true );
		$plain  = $format->writer( CsvWriter::NONE, "\r\n", true );
		$out    = array();
		foreach ( array_values( $fields ) as $column => $value ) {
			$out[] = in_array( $column, $text, true ) ? $free->field( $value ) : $plain->field( $value );
		}
		return implode( ',', $out );
	}
}
