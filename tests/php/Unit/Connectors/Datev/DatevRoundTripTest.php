<?php
/**
 * Round trip: the file the DATEV connector writes is read back with an independent parser and every field is compared.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Datev;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevConnector
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevPostingWriter
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevColumns
 */
final class DatevRoundTripTest extends DatevTestCase {

	/**
	 * The header record: 31 fields, the identifiers of the format, and the numbers the person entered.
	 */
	public function test_header_record(): void {
		$this->assertTrue( $this->send( $this->connection( array( 'fiscal_year_start' => '01-01' ) ), $this->order() )->isSuccess() );
		$rows   = $this->records( $this->file()['bytes'] );
		$header = $rows[0];
		$this->assertCount( 31, $header );
		$this->assertSame( 'EXTF', $header[0] );
		$this->assertSame( '700', $header[1] );
		$this->assertSame( '21', $header[2] );
		$this->assertSame( 'Buchungsstapel', $header[3] );
		$this->assertSame( '13', $header[4] );
		$this->assertSame( '20261102120000000', $header[5] );
		$this->assertSame( '', $header[6] );
		$this->assertSame( 'RE', $header[7] );
		$this->assertSame( 'Estasoft Sync', $header[8] );
		$this->assertSame( '29098', $header[10] );
		$this->assertSame( '55003', $header[11] );
		$this->assertSame( '20260101', $header[12] );
		$this->assertSame( '4', $header[13] );
		$this->assertSame( '20261102', $header[14] );
		$this->assertSame( '20261102', $header[15] );
		$this->assertSame( 'WooCommerce 2026-11', $header[16] );
		$this->assertSame( '1', $header[18] );
		$this->assertSame( '0', $header[19] );
		$this->assertSame( '0', $header[20] );
		$this->assertSame( 'EUR', $header[21] );
		$this->assertSame( '03', $header[26] );
		$this->assertSame( 'Estasoft Sync', $header[30] );

		$raw = $this->lines( $this->file()['bytes'] )[0];
		$this->assertStringStartsWith( '"EXTF";700;21;"Buchungsstapel";13;20261102120000000;;"RE";"Estasoft Sync";;29098;55003;20260101;4;20261102;20261102;"WooCommerce 2026-11";;1;0;0;"EUR";;;;;"03";;;;"Estasoft Sync"', $raw );
		$this->assertStringEndsNotWith( ';', $raw, 'No delimiter at the end of the record.' );
	}

	/**
	 * Row 2 names 125 columns, and the names the official description gives for the fields we fill are the same.
	 */
	public function test_column_names_follow_the_official_description(): void {
		$official = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/datev/official-fields-1003221.json' ), true );
		$this->send( $this->connection(), $this->order() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( $official['count'], $rows[1] );
		foreach ( $official['fields'] as $position => $field ) {
			$this->assertSame( $field['name'], $rows[1][ (int) $position - 1 ], 'Column ' . $position );
		}
	}

	/**
	 * A posting: every field we fill has the value that went in, in the format of the official description, and every other
	 * field of the 125 is empty.
	 */
	public function test_booking_record_round_trip(): void {
		$this->send( $this->connection(), $this->order( '1001' ) );
		$file = $this->file();
		$rows = $this->records( $file['bytes'] );
		$this->assertCount( 3, $rows, 'Header, column names, and one posting for one revenue account.' );
		$row = $rows[2];
		$this->assertCount( 125, $row );

		$this->assertSame( '190,40', $row[0] );          // 10000 + 1900 + 5000 + 950 + 1000 + 190 minor units.
		$this->assertSame( 'S', $row[1] );
		$this->assertSame( '', $row[2] );                // WKZ empty: the base currency.
		$this->assertSame( '10000', $row[6] );           // Konto: the collective debtor.
		$this->assertSame( '8400', $row[7] );            // Gegenkonto: the revenue account.
		$this->assertSame( '', $row[8] );                // BU key empty: automatic account.
		$this->assertSame( '0211', $row[9] );            // Belegdatum TTMM.
		$this->assertSame( '1001', $row[10] );           // Belegfeld 1.
		$this->assertSame( 'WC 1001 Layla Haddad', $row[13] );
		$this->assertSame( 'WooCommerce', $row[47] );
		$this->assertSame( 'WC-1001', $row[48] );
		$this->assertSame( '0', $row[113] );

		$filled = array( 0, 1, 6, 7, 9, 10, 13, 47, 48, 113 );
		foreach ( $row as $index => $value ) {
			if ( ! in_array( $index, $filled, true ) ) {
				$this->assertSame( '', $value, 'Field ' . ( $index + 1 ) . ' must be empty.' );
			}
		}
		$this->assertSame( array(), $file['left_out'] );

		// Quoting: text is quoted, numbers, accounts and dates are bare, empty fields are empty.
		$expected = array_fill( 1, 125, '' );
		$expected[1]   = '190,40';
		$expected[2]   = '"S"';
		$expected[7]   = '10000';
		$expected[8]   = '8400';
		$expected[10]  = '0211';
		$expected[11]  = '"1001"';
		$expected[14]  = '"WC 1001 Layla Haddad"';
		$expected[48]  = '"WooCommerce"';
		$expected[49]  = '"WC-1001"';
		$expected[114] = '0';
		$this->assertSame( implode( ';', $expected ), $this->lines( $file['bytes'] )[2] );
	}

	/**
	 * Tax is split per line: lines with different rates land in different postings, each with its own account.
	 */
	public function test_one_posting_for_each_tax_rate(): void {
		$payload                           = $this->order( '1002' );
		$payload['line'][1]['tax_code']    = '8300';
		$payload['line'][1]['tax_rate']    = 7;
		$payload['line'][1]['tax']         = 350;
		$payload['document']['total']      = 11900 + 5350 + 1190;
		$this->send( $this->connection(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 4, $rows );
		$by = array();
		foreach ( array_slice( $rows, 2 ) as $row ) {
			$by[ $row[7] ] = $row;
		}
		$this->assertSame( '130,90', $by['8400'][0] ); // Line 1 (119.00) and shipping (11.90).
		$this->assertSame( '53,50', $by['8300'][0] );
		$this->assertSame( 'S', $by['8400'][1] );
		$this->assertSame( '1002', $by['8300'][10] );
	}

	/**
	 * A tax code with a BU key puts the key in field 9.
	 */
	public function test_bu_key_from_the_tax_code(): void {
		$payload = $this->order( '1003' );
		foreach ( array( 'line' => array( 0, 1 ), 'shipping' => array( 0 ) ) as $block => $indexes ) {
			foreach ( $indexes as $i ) {
				$payload[ $block ][ $i ]['tax_code'] = '8200:3';
			}
		}
		$this->send( $this->connection(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '8200', $rows[2][7] );
		$this->assertSame( '3', $rows[2][8] );
		$this->assertStringContainsString( ';8200;"3";0211;', $this->lines( $this->file()['bytes'] )[2] );
	}

	/**
	 * A refund is a posting with H, dated on the refund, with Belegfeld 1 made of the order number and the refund id.
	 */
	public function test_refund_is_a_credit_posting(): void {
		$payload                          = FakeExportKit::refund( '1001', 55 );
		$payload['document']['currency']  = 'EUR';
		$payload['document']['total']     = 5950;
		$payload['original_line'][0]['tax_code'] = '8400';
		$payload['original_line'][0]['tax_rate'] = 19;
		$payload['original_shipping'][0]['tax_code'] = '8400';
		$payload['refund_line'][0]['net'] = 5000;
		$payload['refund_line'][0]['tax'] = 950;
		$this->assertTrue( $this->send( $this->connection(), $payload, EntityType::Refund )->isSuccess() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( '59,50', $rows[2][0] );
		$this->assertSame( 'H', $rows[2][1] );
		$this->assertSame( '10000', $rows[2][6] );
		$this->assertSame( '8400', $rows[2][7] );
		$this->assertSame( '0311', $rows[2][9] );
		$this->assertSame( '1001-R55', $rows[2][10] );
		$this->assertSame( 'WC 1001 R55 Layla Haddad', $rows[2][13] );
		$this->assertSame( 'WC-1001', $rows[2][48] );
	}

	/**
	 * Amounts use the decimal comma and no thousands separator; a large amount keeps every digit.
	 */
	public function test_decimal_comma_and_no_thousands_separator(): void {
		$payload                       = $this->order( '1004' );
		$payload['line']               = array( array_merge( $payload['line'][0], array( 'quantity' => 1, 'net' => 100000000, 'tax' => 19000000, 'unit_price' => 100000000 ) ) );
		$payload['shipping']           = array();
		$payload['document']['total']  = 119000000;
		$this->send( $this->connection(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '1190000,00', $rows[2][0] );
	}

	/**
	 * Windows-1252: German and Nordic letters are single bytes, the euro sign too; Arabic cannot be written and shows as ? .
	 */
	public function test_windows_1252_keeps_german_and_nordic_letters(): void {
		$payload                     = $this->order( '1005' );
		$payload['customer']['name'] = 'Jürgen Großmann-Åkesson Ørsted';
		$this->send( $this->connection(), $payload );
		$file = $this->file();
		$this->assertStringContainsString( 'text/csv; charset=windows-1252', $file['mime'] );
		$this->assertStringContainsString( "WC 1005 J\xFCrgen Gro\xDFmann-\xC5kesson \xD8rsted", $file['bytes'] );
		$rows = $this->records( $file['bytes'] );
		$this->assertSame( 'WC 1005 Jürgen Großmann-Åkesson Ørsted', $rows[2][13] );
		$this->assertStringContainsString( "Gegenkonto (ohne BU-Schl\xFCssel)", $file['bytes'] );
	}

	/**
	 * Arabic text becomes question marks in Windows-1252. The preview names the orders and the characters, and the export carries a
	 * notes file that lists every replaced character, so the loss is never silent.
	 */
	public function test_arabic_in_windows_1252_is_replaced_and_counted(): void {
		$payload                     = $this->order( '1006' );
		$payload['customer']['name'] = 'ليلى';
		$this->send( $this->connection(), $payload );
		$file = $this->file();
		$rows = $this->records( $file['bytes'] );
		$this->assertSame( 'WC 1006 ????', $rows[2][13] );
		$preview = $this->connector->previewText( $this->deps->exports->openBatch( 7 ) );
		$this->assertStringContainsString( '4 characters cannot be written in Windows-1252 and become question marks (1 orders).', $preview );
		$this->assertStringContainsString( 'Order 1006: ل (2), ي, ى', $preview );
		$this->assertArrayHasKey( 'Notes', $file['attachments'] );
		$notes = $file['attachments']['Notes'];
		$this->assertStringContainsString( 'These characters cannot be written in windows-1252', $notes );
		$this->assertStringContainsString( 'Choose UTF-8 with byte order mark', $notes );
		$this->assertStringContainsString( "Order 1006: ل (2), ي, ى\n", $notes );
	}

	/**
	 * The notes name every order with replaced characters, not only the first ones the preview shows.
	 */
	public function test_notes_list_every_order_with_replaced_characters(): void {
		$connection = $this->connection();
		foreach ( array( 'ليلى', 'Jürgen', 'سارة', 'Ørsted', 'محمد', 'نور', 'علي' ) as $i => $name ) {
			$payload                     = $this->order( (string) ( 2000 + $i ) );
			$payload['customer']['name'] = $name;
			$this->send( $connection, $payload );
		}
		$file  = $this->file();
		$notes = $file['attachments']['Notes'];
		foreach ( array( 2000, 2002, 2004, 2005, 2006 ) as $number ) {
			$this->assertStringContainsString( 'Order ' . $number . ': ', $notes );
		}
		$this->assertStringNotContainsString( 'Order 2001: ', $notes, 'Latin letters are written exactly.' );
		$this->assertStringNotContainsString( 'Order 2003: ', $notes );
		$preview = $this->connector->previewText( $this->deps->exports->openBatch( 7 ) );
		$this->assertStringContainsString( '(5 orders)', $preview );
	}

	/**
	 * With UTF-8 nothing is replaced, so there is no notes file.
	 */
	public function test_no_notes_when_nothing_is_replaced(): void {
		$payload                     = $this->order( '1008' );
		$payload['customer']['name'] = 'ليلى';
		$this->send( $this->connection( array( 'encoding' => 'utf-8-bom' ) ), $payload );
		$file = $this->file();
		$this->assertArrayNotHasKey( 'Notes', $file['attachments'] );
		$this->assertStringNotContainsString( 'cannot be written', $this->connector->previewText( $this->deps->exports->openBatch( 7 ) ) );
	}

	/**
	 * UTF-8 with a byte order mark keeps Arabic, German and Nordic text exactly.
	 */
	public function test_utf8_with_byte_order_mark_keeps_every_script(): void {
		$payload                     = $this->order( '1007' );
		$payload['customer']['name'] = 'ليلى حداد Jürgen Åkesson';
		$this->send( $this->connection( array( 'encoding' => 'utf-8-bom' ) ), $payload );
		$file = $this->file();
		$this->assertStringContainsString( 'charset=utf-8', $file['mime'] );
		$rows = $this->records( $file['bytes'], 'utf-8-bom' );
		$this->assertSame( 'WC 1007 ليلى حداد Jürgen Åkesson', $rows[2][13] );
		$this->assertSame( 'Gegenkonto (ohne BU-Schlüssel)', $rows[1][7] );
	}

	/**
	 * Quotes in names are doubled and semicolons stay inside the quotes.
	 */
	public function test_quotes_and_semicolons_in_text(): void {
		$payload                     = $this->order( '1008' );
		$payload['customer']['name'] = 'Müller; "Söhne" & Co';
		$this->send( $this->connection(), $payload );
		$file = $this->file();
		$this->assertStringContainsString( '"WC 1008 Müller; ""Söhne"" & Co"', $this->utf8( $file['bytes'] ) );
		$this->assertSame( 'WC 1008 Müller; "Söhne" & Co', $this->records( $file['bytes'] )[2][13] );
		$this->assertCount( 125, $this->records( $file['bytes'] )[2] );
	}

	/**
	 * Belegfeld 1 keeps only digits, letters and $ & % * + - / : anything else becomes a dash, and a number that is too long is left out.
	 */
	public function test_belegfeld_1_characters_and_length(): void {
		$this->send( $this->connection(), $this->order( '#1009 / B ä' ) );
		$this->assertSame( '1009-/-B', $this->records( $this->file()['bytes'] )[2][10] );

		$this->send( $this->connection(), $this->order( str_repeat( '1', 37 ) ) );
		$reasons = array_column( $this->deps->exports->openBatch( 7 )->leftOut(), 'reason' );
		$this->assertSame( array( 'the order number is longer than 36 characters' ), $reasons );
	}

	/**
	 * Booking text is cut to 60 characters with an ellipsis.
	 */
	public function test_booking_text_is_cut_at_sixty_characters(): void {
		$payload                     = $this->order( '1010' );
		$payload['customer']['name'] = str_repeat( 'Ä', 80 );
		$this->send( $this->connection(), $payload );
		$text = $this->records( $this->file()['bytes'] )[2][13];
		$this->assertSame( 60, mb_strlen( $text ) );
		$this->assertStringEndsWith( '...', $text );
		$this->assertSame( 1, $this->deps->exports->openBatch( 7 )->rows()[0]['row']['cut'] );
	}

	/**
	 * Booking text can carry the order number alone.
	 */
	public function test_booking_text_without_the_name(): void {
		$this->send( $this->connection( array( 'booking_text' => 'number' ) ), $this->order( '1011' ) );
		$this->assertSame( 'WC 1011', $this->records( $this->file()['bytes'] )[2][13] );
	}

	/**
	 * Locking the batch writes Festschreibung 1 in the header and in every posting.
	 */
	public function test_lock_postings(): void {
		$this->send( $this->connection( array( 'lock_postings' => 'yes' ) ), $this->order() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '1', $rows[0][20] );
		$this->assertSame( '1', $rows[2][113] );
	}

	/**
	 * Chart 04 and a five-digit account length change the header and the default debtor account.
	 */
	public function test_chart_and_account_length(): void {
		$payload = $this->order( '1012' );
		foreach ( array( 0, 1 ) as $i ) {
			$payload['line'][ $i ]['tax_code'] = '44000';
		}
		$payload['shipping'][0]['tax_code'] = '44000';
		$this->send( $this->connection( array( 'chart' => 'skr04', 'account_length' => 5 ) ), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '04', $rows[0][26] );
		$this->assertSame( '5', $rows[0][13] );
		$this->assertSame( '100000', $rows[2][6] );
		$this->assertSame( '44000', $rows[2][7] );
	}

	/**
	 * Ten orders from the first day to the last of a month give the right date range in the header.
	 */
	public function test_header_dates_cover_the_orders(): void {
		foreach ( array( '2026-10-05', '2026-10-31', '2026-10-17' ) as $i => $date ) {
			$payload                     = $this->order( (string) ( 2000 + $i ) );
			$payload['document']['date'] = $date;
			$this->send( $this->connection(), $payload );
		}
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '20261005', $rows[0][14] );
		$this->assertSame( '20261031', $rows[0][15] );
		$this->assertSame( 'WooCommerce 2026-10', $rows[0][16] );
	}
}
