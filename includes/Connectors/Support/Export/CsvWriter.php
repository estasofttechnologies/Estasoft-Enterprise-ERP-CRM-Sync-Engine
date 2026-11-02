<?php
/**
 * Writes delimited text files safely.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns values into delimited text for export files. Two things go wrong when each connector does it alone: a text that
 * a customer typed (a name, a note, an address) starts with `=`, `+`, `-` or `@` and a spreadsheet runs it as a formula when the accountant opens
 * the file (CSV injection), and quotes, delimiters or line breaks inside a value break the columns.
 *
 * A text value that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is neutralised, unless it is a plain number (`-12.50`, `+3`,
 * `-1.234,50`): amounts stay amounts. How is the choice of the importer:
 *
 * - `quote` (default) puts a single quote in front, the spreadsheet convention; Excel and LibreOffice show the text without it.
 * - `space` puts one space in front, for an importer that would take a quote as part of the text (the accounting packages read the space
 *   as padding and trim it).
 * - `strip` removes the leading characters.
 * - `none` leaves the text alone; use it only for a column that holds a number or a fixed code, never for free text.
 *
 * PHP integers and floats are numbers and are never changed. A value is quoted when it holds the delimiter, the quote, a line break or
 * spaces at either end, or when the column is always quoted; inside quotes a quote is doubled. NUL bytes are removed. Build a writer for a
 * format with `ExportFormat::writer()`; encode the finished text with `encode()` when the importer wants Windows-1252 or similar.
 */
final class CsvWriter {

	public const QUOTE = 'quote';
	public const SPACE = 'space';
	public const STRIP = 'strip';
	public const NONE  = 'none';

	/**
	 * First characters a spreadsheet may take for the start of a formula.
	 */
	private const DANGEROUS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $delimiter  One character between fields (comma, semicolon, tab).
	 * @param string $enclosure  One character that quotes a field.
	 * @param string $lineEnd    End of a line written by line().
	 * @param string $neutralise One of QUOTE, SPACE, STRIP or NONE.
	 * @param bool   $flatten    True to replace line breaks inside a value with a space (for an importer that reads one line per record).
	 *
	 * @throws \InvalidArgumentException When the delimiter or the enclosure is not one character, or they are the same.
	 */
	public function __construct(
		private readonly string $delimiter = ',',
		private readonly string $enclosure = '"',
		private readonly string $lineEnd = "\r\n",
		private readonly string $neutralise = self::QUOTE,
		private readonly bool $flatten = false,
	) {
		if ( 1 !== strlen( $this->delimiter ) || 1 !== strlen( $this->enclosure ) || $this->delimiter === $this->enclosure ) {
			throw new \InvalidArgumentException( 'The delimiter and the enclosure are one different character each.' );
		}
	}

	/**
	 * One field.
	 *
	 * @param mixed $value      Text, integer, float, bool or null. An integer or a float is a number and is written as it is.
	 * @param bool  $forceQuote Quote the field even when nothing needs it (a text column that an importer expects in quotes).
	 */
	public function field( mixed $value, bool $forceQuote = false ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$text = is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? (string) $value : '' );
		$text = str_replace( "\0", '', $text );
		if ( $this->flatten ) {
			$text = (string) preg_replace( '/\R/u', ' ', $text );
		}
		$text = self::neutralise( $text, $this->neutralise );
		if ( '' === $text ) {
			return $forceQuote ? $this->enclosure . $this->enclosure : '';
		}
		$needs = $forceQuote
			|| str_contains( $text, $this->delimiter )
			|| str_contains( $text, $this->enclosure )
			|| str_contains( $text, "\n" )
			|| str_contains( $text, "\r" )
			|| ' ' === $text[0]
			|| ' ' === substr( $text, -1 );
		return $needs ? $this->enclosure . str_replace( $this->enclosure, $this->enclosure . $this->enclosure, $text ) . $this->enclosure : $text;
	}

	/**
	 * One record without its line end.
	 *
	 * @param array<int,mixed> $values       Values in column order.
	 * @param array<int,int>   $quoteColumns Zero-based columns that are always quoted.
	 */
	public function row( array $values, array $quoteColumns = array() ): string {
		$fields = array();
		foreach ( array_values( $values ) as $column => $value ) {
			$fields[] = $this->field( $value, in_array( $column, $quoteColumns, true ) );
		}
		return implode( $this->delimiter, $fields );
	}

	/**
	 * One record with the line end.
	 *
	 * @param array<int,mixed> $values       Values in column order.
	 * @param array<int,int>   $quoteColumns Zero-based columns that are always quoted.
	 */
	public function line( array $values, array $quoteColumns = array() ): string {
		return $this->row( $values, $quoteColumns ) . $this->lineEnd;
	}

	/**
	 * Make a text safe for a spreadsheet without quoting it (for a place that builds its own fields).
	 *
	 * @param string $text Text.
	 * @param string $mode One of QUOTE, SPACE, STRIP or NONE.
	 */
	public static function neutralise( string $text, string $mode = self::QUOTE ): string {
		if ( self::NONE === $mode || '' === $text || ! in_array( $text[0], self::DANGEROUS, true ) || self::isPlainNumber( $text ) ) {
			return $text;
		}
		return match ( $mode ) {
			self::SPACE => ' ' . $text,
			self::STRIP => ltrim( $text, "=+-@\t\r" ),
			default     => "'" . $text,
		};
	}

	/**
	 * Whether a text is only a number: digits with an optional sign, one decimal separator and thousands separators (`-12.50`, `+3`, `-1.234,50`).
	 *
	 * @param string $text Text.
	 */
	public static function isPlainNumber( string $text ): bool {
		return 1 === preg_match( '/^[+-]?(?:\d+|\d{1,3}(?:[.,]\d{3})+)(?:[.,]\d+)?$/D', $text );
	}

	/**
	 * Convert finished UTF-8 text to the character set an importer wants. A character the set cannot hold becomes a question mark.
	 *
	 * @param string $utf8    Text in UTF-8.
	 * @param string $charset Target character set, for example windows-1252, iso-8859-1 or utf-16le. UTF-8 returns the text unchanged.
	 */
	public static function encode( string $utf8, string $charset ): string {
		if ( in_array( strtolower( $charset ), array( '', 'utf-8', 'utf8' ), true ) ) {
			return $utf8;
		}
		$before = mb_substitute_character();
		mb_substitute_character( 0x3F );
		$converted = mb_convert_encoding( $utf8, $charset, 'UTF-8' );
		mb_substitute_character( $before );
		return $converted;
	}
}
