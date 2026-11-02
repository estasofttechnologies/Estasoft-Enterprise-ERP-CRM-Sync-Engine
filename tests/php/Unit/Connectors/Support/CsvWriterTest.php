<?php
/**
 * The shared writer of delimited export files.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

use Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter
 * @covers \Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat
 */
final class CsvWriterTest extends TestCase {

	public function test_a_text_that_starts_like_a_formula_is_neutralised_with_a_quote(): void {
		$writer = new CsvWriter();
		foreach ( array( '=1+1', '=HYPERLINK("http://evil.test","x")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tTabbed", "\rReturn", '=cmd|\' /C calc\'!A0' ) as $text ) {
			$read = str_getcsv( $writer->field( $text ), ',', '"', '' )[0];
			$this->assertSame( "'" . $text, $read, 'Read back by a CSV reader, the cell starts with a quote and not with the formula character.' );
			$this->assertNotContains( $read[0], array( '=', '+', '-', '@', "\t", "\r" ) );
		}
		$this->assertSame( "'=1+1", $writer->field( '=1+1' ) );
		$this->assertSame( "'@SUM(A1)", $writer->field( '@SUM(A1)' ) );
		$this->assertSame( "'+49 30 1234567", $writer->field( '+49 30 1234567' ) );
		$this->assertSame( '"\'=HYPERLINK(""http://evil.test"",""x"")"', $writer->field( '=HYPERLINK("http://evil.test","x")' ), 'Neutralised first, then quoted.' );
	}

	public function test_a_plain_number_is_not_touched_and_neither_is_ordinary_text(): void {
		$writer = new CsvWriter();
		foreach ( array( '-12.50', '+3', '-1', '+0.5', '100', '0' ) as $number ) {
			$this->assertSame( $number, $writer->field( $number ), $number );
		}
		$this->assertSame( '"-1.234,50"', $writer->field( '-1.234,50' ), 'A number with a comma is a number: quoted for the comma, not neutralised.' );
		$this->assertSame( '-1.234,50', ( new CsvWriter( ';' ) )->field( '-1.234,50' ) );
		foreach ( array( 'Smith', 'a=b', 'x-y', 'Mary @ Home', '1-2', '12-', '--', '-', '+', '-5 EUR', '-1.2.3' ) as $text ) {
			$expected = in_array( $text[0], array( '-', '+' ), true ) ? "'" . $text : $text;
			$this->assertSame( $expected, $writer->field( $text ), $text );
		}
		$this->assertSame( '-5', $writer->field( -5 ), 'A PHP number is never changed.' );
		$this->assertSame( '-12.5', $writer->field( -12.5 ) );
		$this->assertSame( '1', $writer->field( true ) );
		$this->assertSame( '', $writer->field( null ) );
	}

	public function test_the_other_modes_suit_importers_that_would_show_a_quote(): void {
		$this->assertSame( '" =1+1"', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::SPACE ) )->field( '=1+1' ), 'A leading space gets quoted so it survives.' );
		$this->assertSame( '1+1', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::STRIP ) )->field( '=1+1' ) );
		$this->assertSame( 'cmd', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::STRIP ) )->field( '+-=@cmd' ) );
		$this->assertSame( '=1+1', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::NONE ) )->field( '=1+1' ) );
		$this->assertSame( '-12.50', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::STRIP ) )->field( '-12.50' ), 'Numbers stay numbers in every mode.' );
	}

	public function test_quoting_and_escaping(): void {
		$writer = new CsvWriter();
		$this->assertSame( '"a,b"', $writer->field( 'a,b' ) );
		$this->assertSame( '"say ""hi"""', $writer->field( 'say "hi"' ) );
		$this->assertSame( "\"line1\nline2\"", $writer->field( "line1\nline2" ) );
		$this->assertSame( "\"a\r\nb\"", $writer->field( "a\r\nb" ) );
		$this->assertSame( '" padded "', $writer->field( ' padded ' ) );
		$this->assertSame( 'abc', $writer->field( "a\0bc" ), 'NUL bytes are removed.' );
		$this->assertSame( '""', $writer->field( '', true ), 'An always-quoted empty field is two quotes.' );
		$this->assertSame( '"plain"', $writer->field( 'plain', true ) );
		$semicolon = new CsvWriter( ';' );
		$this->assertSame( 'a,b', $semicolon->field( 'a,b' ) );
		$this->assertSame( '"a;b"', $semicolon->field( 'a;b' ) );
		$this->assertSame( 'a b', ( new CsvWriter( ',', '"', "\r\n", CsvWriter::QUOTE, true ) )->field( "a\r\nb" ), 'Flattened.' );
	}

	public function test_rows_round_trip_through_a_csv_reader(): void {
		$values = array( 'Name', 'Smith, J', 'says "hi"', "two\nlines", '', '=BAD()', '-12.50', 7, 1.25 );
		foreach ( array( ',', ';', "\t" ) as $delimiter ) {
			$writer = new CsvWriter( $delimiter );
			$line   = $writer->row( $values );
			$read   = str_getcsv( $line, $delimiter, '"', '' );
			$this->assertSame( array( 'Name', 'Smith, J', 'says "hi"', "two\nlines", '', "'=BAD()", '-12.50', '7', '1.25' ), $read, 'Delimiter ' . json_encode( $delimiter ) );
		}
		$this->assertSame( 'a,b' . "\r\n", ( new CsvWriter() )->line( array( 'a', 'b' ) ) );
		$this->assertSame( '"a",b', ( new CsvWriter() )->row( array( 'a', 'b' ), array( 0 ) ) );
	}

	public function test_the_format_hands_out_a_writer_with_its_own_delimiter(): void {
		$format = ExportFormat::csv( 'DATEV', false, ';' );
		$this->assertSame( ';', $format->delimiter );
		$this->assertSame( 'x;"y;z";\'=1', $format->writer()->row( array( 'x', 'y;z', '=1' ) ) );
		$this->assertSame( ',', ExportFormat::csv()->delimiter );
		$this->assertSame( '=1', ExportFormat::csv()->writer( CsvWriter::NONE )->field( '=1' ) );
	}

	public function test_text_is_converted_to_the_character_set_the_importer_wants(): void {
		$this->assertSame( "Gr\xFC\xDFe", CsvWriter::encode( 'Grüße', 'windows-1252' ) );
		$this->assertSame( 'Gr??e', str_replace( array( "\xFC", "\xDF" ), '?', CsvWriter::encode( 'Grüße', 'windows-1252' ) ) );
		$this->assertSame( '?', CsvWriter::encode( '€', 'iso-8859-1' ), 'A character the set cannot hold becomes a question mark.' );
		$this->assertSame( 'Grüße', CsvWriter::encode( 'Grüße', 'utf-8' ) );
		$this->assertSame( "A\0", CsvWriter::encode( 'A', 'utf-16le' ) );
	}

	public function test_a_delimiter_and_an_enclosure_are_one_different_character_each(): void {
		$this->expectException( \InvalidArgumentException::class );
		new CsvWriter( ';;' );
	}
}
