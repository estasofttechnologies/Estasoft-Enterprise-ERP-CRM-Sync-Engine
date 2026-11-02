<?php
/**
 * The 125 columns of a DATEV-Format Buchungsstapel.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;

/**
 * Position, heading and kind of every field of the booking record, in the order of DATEV document 1003221 ("ASCII-Import
 * Buchungsstapel", format version 13 has 125 fields). The kind decides the quoting: T is text and is written in double quotes
 * when it has a value, everything else (amount, number, date, account) is written bare. DATEV imports by position; the heading
 * row only helps the person who opens the file.
 */
final class DatevColumns {

	public const COUNT = 125;

	public const AMOUNT  = 'A';
	public const TEXT    = 'T';
	public const NUMBER  = 'N';
	public const DATE    = 'D';
	public const ACCOUNT = 'K';

	// Positions the connector fills.
	public const UMSATZ         = 1;
	public const SOLL_HABEN     = 2;
	public const KONTO          = 7;
	public const GEGENKONTO     = 8;
	public const BU_SCHLUESSEL  = 9;
	public const BELEGDATUM     = 10;
	public const BELEGFELD_1    = 11;
	public const BUCHUNGSTEXT   = 14;
	public const ZUSATZ_ART_1   = 48;
	public const ZUSATZ_INHALT  = 49;
	public const FESTSCHREIBUNG = 114;

	/**
	 * Heading and kind of every field.
	 *
	 * @return array<int,array{0:string,1:string}> By position (1 to 125).
	 */
	public static function all(): array {
		$columns = array(
			1  => array( 'Umsatz (ohne Soll/Haben-Kz)', self::AMOUNT ),
			2  => array( 'Soll/Haben-Kennzeichen', self::TEXT ),
			3  => array( 'WKZ Umsatz', self::TEXT ),
			4  => array( 'Kurs', self::NUMBER ),
			5  => array( 'Basis-Umsatz', self::AMOUNT ),
			6  => array( 'WKZ Basis-Umsatz', self::TEXT ),
			7  => array( 'Konto', self::ACCOUNT ),
			8  => array( 'Gegenkonto (ohne BU-Schlüssel)', self::ACCOUNT ),
			9  => array( 'BU-Schlüssel', self::TEXT ),
			10 => array( 'Belegdatum', self::DATE ),
			11 => array( 'Belegfeld 1', self::TEXT ),
			12 => array( 'Belegfeld 2', self::TEXT ),
			13 => array( 'Skonto', self::AMOUNT ),
			14 => array( 'Buchungstext', self::TEXT ),
			15 => array( 'Postensperre', self::NUMBER ),
			16 => array( 'Diverse Adressnummer', self::TEXT ),
			17 => array( 'Geschäftspartnerbank', self::NUMBER ),
			18 => array( 'Sachverhalt', self::NUMBER ),
			19 => array( 'Zinssperre', self::NUMBER ),
			20 => array( 'Beleglink', self::TEXT ),
		);
		for ( $n = 1; $n <= 8; $n++ ) {
			$columns[ 19 + ( 2 * $n ) ] = array( 'Beleginfo - Art ' . $n, self::TEXT );
			$columns[ 20 + ( 2 * $n ) ] = array( 'Beleginfo - Inhalt ' . $n, self::TEXT );
		}
		$columns[37] = array( 'KOST1 - Kostenstelle', self::TEXT );
		$columns[38] = array( 'KOST2 - Kostenstelle', self::TEXT );
		$columns[39] = array( 'Kost-Menge', self::NUMBER );
		$columns[40] = array( 'EU-Land u. UStID (Bestimmung)', self::TEXT );
		$columns[41] = array( 'EU-Steuersatz (Bestimmung)', self::NUMBER );
		$columns[42] = array( 'Abw. Versteuerungsart', self::TEXT );
		$columns[43] = array( 'Sachverhalt L+L', self::NUMBER );
		$columns[44] = array( 'Funktionsergänzung L+L', self::NUMBER );
		$columns[45] = array( 'BU 49 Hauptfunktionstyp', self::NUMBER );
		$columns[46] = array( 'BU 49 Hauptfunktionsnummer', self::NUMBER );
		$columns[47] = array( 'BU 49 Funktionsergänzung', self::NUMBER );
		for ( $n = 1; $n <= 20; $n++ ) {
			$columns[ 46 + ( 2 * $n ) ] = array( 'Zusatzinformation - Art ' . $n, self::TEXT );
			$columns[ 47 + ( 2 * $n ) ] = array( 'Zusatzinformation- Inhalt ' . $n, self::TEXT );
		}
		$rest     = array(
			88  => array( 'Stück', self::NUMBER ),
			89  => array( 'Gewicht', self::NUMBER ),
			90  => array( 'Zahlweise', self::NUMBER ),
			91  => array( 'Forderungsart', self::TEXT ),
			92  => array( 'Veranlagungsjahr', self::NUMBER ),
			93  => array( 'Zugeordnete Fälligkeit', self::DATE ),
			94  => array( 'Skontotyp', self::NUMBER ),
			95  => array( 'Auftragsnummer', self::TEXT ),
			96  => array( 'Buchungstyp', self::TEXT ),
			97  => array( 'USt-Schlüssel (Anzahlungen)', self::NUMBER ),
			98  => array( 'EU-Land u. UStID (Anzahlungen)', self::TEXT ),
			99  => array( 'Sachverhalt L+L (Anzahlungen)', self::NUMBER ),
			100 => array( 'EU-Steuersatz (Anzahlungen)', self::NUMBER ),
			101 => array( 'Erlöskonto (Anzahlungen)', self::ACCOUNT ),
			102 => array( 'Herkunft-Kz', self::TEXT ),
			103 => array( 'Leerfeld', self::TEXT ),
			104 => array( 'KOST-Datum', self::DATE ),
			105 => array( 'SEPA-Mandatsreferenz', self::TEXT ),
			106 => array( 'Skontosperre', self::NUMBER ),
			107 => array( 'Gesellschaftername', self::TEXT ),
			108 => array( 'Beteiligtennummer', self::NUMBER ),
			109 => array( 'Identifikationsnummer', self::TEXT ),
			110 => array( 'Zeichnernummer', self::TEXT ),
			111 => array( 'Postensperre bis', self::DATE ),
			112 => array( 'Bezeichnung SoBil-Sachverhalt', self::TEXT ),
			113 => array( 'Kennzeichen SoBil-Buchung', self::NUMBER ),
			114 => array( 'Festschreibung', self::NUMBER ),
			115 => array( 'Leistungsdatum', self::DATE ),
			116 => array( 'Datum Zuord.Steuerperiode', self::DATE ),
			117 => array( 'Fälligkeit', self::DATE ),
			118 => array( 'Generalumkehr (GU)', self::TEXT ),
			119 => array( 'Steuersatz', self::NUMBER ),
			120 => array( 'Land', self::TEXT ),
			121 => array( 'Abrechnungsreferenz', self::TEXT ),
			122 => array( 'BVV-Position', self::NUMBER ),
			123 => array( 'EU-Land u. UStID (Ursprung)', self::TEXT ),
			124 => array( 'EU-Steuersatz (Ursprung)', self::NUMBER ),
			125 => array( 'Abw. Skontokonto', self::ACCOUNT ),
		);
		$columns += $rest;
		ksort( $columns );
		return $columns;
	}

	/**
	 * The heading row.
	 */
	public static function headings(): string {
		return implode( ';', array_map( static fn( array $column ): string => '"' . $column[0] . '"', self::all() ) );
	}

	/**
	 * One record: values by position, all other fields empty. A text is written in double quotes with inner quotes doubled, through the
	 * engine's CsvWriter: a line break inside it becomes a space, and a text that starts with `=`, `+`, `-` or `@`, a tab or a carriage return
	 * gets one space in front so a spreadsheet does not run it as a formula. Amounts, numbers, dates and accounts are written as they are
	 * given, so the import rules for those columns stay as documented.
	 *
	 * @param array<int,string> $values Values by position (1 to 125).
	 */
	public static function record( array $values ): string {
		$writer  = ExportFormat::csv( 'DATEV-Format Buchungsstapel', false, ';' )->writer( CsvWriter::SPACE, "\r\n", true );
		$columns = self::all();
		$fields  = array();
		foreach ( $columns as $position => $column ) {
			$value = $values[ $position ] ?? '';
			if ( '' === $value ) {
				$fields[] = '';
			} elseif ( self::TEXT === $column[1] ) {
				$fields[] = $writer->field( $value, true );
			} else {
				$fields[] = $value;
			}
		}
		return implode( ';', $fields );
	}
}
