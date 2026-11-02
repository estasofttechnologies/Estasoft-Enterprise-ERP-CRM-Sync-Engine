<?php
/**
 * The format of an export file.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Extension, content type and the name shown on the Exports screen. Contents are UTF-8 text unless `rawBytes` is true: then the connector has
 * already encoded them (Windows-1252 for DATEV, UTF-16LE for Tally) and the store writes them as they are, without adding a byte order mark.
 */
final class ExportFormat {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $extension Lower-case file extension without the dot ([a-z0-9]).
	 * @param string $mime      Content type sent with the download.
	 * @param string $label     Name shown to the person ("Sage 50 CSV import").
	 * @param bool   $bom       Whether the file starts with a UTF-8 byte order mark (some importers need one). Ignored when `rawBytes` is true.
	 * @param string $charset   Character set of the file, for the content type when `$mime` has none ("utf-8", "windows-1252", "utf-16le").
	 * @param bool   $rawBytes  True when the contents are already encoded: nothing is added or converted.
	 * @param string $delimiter Field delimiter of a delimited text format (comma, semicolon or tab); used by writer().
	 */
	public function __construct(
		public readonly string $extension,
		public readonly string $mime,
		public readonly string $label,
		public readonly bool $bom = false,
		public readonly string $charset = 'utf-8',
		public readonly bool $rawBytes = false,
		public readonly string $delimiter = ',',
	) {
	}

	/**
	 * A comma-separated file.
	 *
	 * @param string $label     Name shown to the person.
	 * @param bool   $bom       UTF-8 byte order mark.
	 * @param string $delimiter Field delimiter.
	 */
	public static function csv( string $label = 'CSV', bool $bom = false, string $delimiter = ',' ): self {
		return new self( 'csv', 'text/csv; charset=utf-8', $label, $bom, 'utf-8', false, $delimiter );
	}

	/**
	 * The writer for this format's delimited text: the delimiter of the format, quotes doubled, a text that starts like a formula neutralised.
	 * Write every field of a delimited export file with it instead of joining strings by hand.
	 *
	 * @param string $neutralise How a text that starts like a formula is made safe: CsvWriter::QUOTE (default), SPACE, STRIP or NONE.
	 * @param string $lineEnd    End of a line.
	 * @param bool   $flatten    Replace line breaks inside a value with a space.
	 */
	public function writer( string $neutralise = CsvWriter::QUOTE, string $lineEnd = "\r\n", bool $flatten = false ): CsvWriter {
		return new CsvWriter( $this->delimiter, '"', $lineEnd, $neutralise, $flatten );
	}

	/**
	 * A tab-separated or IIF-style text file.
	 *
	 * @param string $label     Name shown to the person.
	 * @param string $extension Extension (txt, iif, tsv).
	 */
	public static function text( string $label = 'Text', string $extension = 'txt' ): self {
		return new self( $extension, 'text/plain; charset=utf-8', $label );
	}

	/**
	 * An XML file.
	 *
	 * @param string $label Name shown to the person.
	 */
	public static function xml( string $label = 'XML' ): self {
		return new self( 'xml', 'application/xml; charset=utf-8', $label );
	}

	/**
	 * A JSON file.
	 *
	 * @param string $label Name shown to the person.
	 */
	public static function json( string $label = 'JSON' ): self {
		return new self( 'json', 'application/json; charset=utf-8', $label );
	}

	/**
	 * The extension, cleaned.
	 */
	public function safeExtension(): string {
		$extension = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', $this->extension ) );
		return '' === $extension ? 'txt' : substr( $extension, 0, 8 );
	}

	/**
	 * Whether the store adds a UTF-8 byte order mark to the file.
	 */
	public function addsByteOrderMark(): bool {
		return $this->bom && ! $this->rawBytes && 'utf-8' === strtolower( $this->charset );
	}
}
