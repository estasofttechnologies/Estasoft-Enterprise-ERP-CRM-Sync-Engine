<?php
/**
 * The Sage 50 Accounts (UK and Ireland) file: Audit Trail Transactions rows, read back and compared field by field.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage50;

use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50UkWriter
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Settings
 */
final class Sage50UkTest extends Sage50TestCase {

	/**
	 * The reasons orders were left out.
	 *
	 * @return array<int,string>
	 */
	private function reasons(): array {
		return array_column( $this->deps->exports->openBatch( 7 )->leftOut(), 'reason' );
	}

	/**
	 * The heading row and one row for every line, with every field as it went in.
	 */
	public function test_audit_trail_rows_round_trip(): void {
		$this->assertTrue( $this->send( $this->uk(), $this->order() )->isSuccess() );
		$file = $this->file();
		$rows = $this->records( $file['bytes'] );
		$this->assertSame(
			array( 'Type', 'Account Reference', 'Nominal A/C Code', 'Department Code', 'Date', 'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount', 'Exchange Rate', 'Extra Reference', 'User Name' ),
			$rows[0]
		);
		$this->assertCount( 4, $rows );
		$this->assertSame( array( 'SI', 'WEB', '4000', '', '02/11/2026', '1001', 'Cedar shelf', '100.00', 'T1', '20.00', '1', 'WC-1001', '' ), $rows[1] );
		$this->assertSame( array( 'SI', 'WEB', '4000', '', '02/11/2026', '1001', 'Stone coaster', '40.00', 'T1', '8.00', '1', 'WC-1001', '' ), $rows[2] );
		$this->assertSame( array( 'SI', 'WEB', '4000', '', '02/11/2026', '1001', 'Flat rate', '10.00', 'T1', '2.00', '1', 'WC-1001', '' ), $rows[3] );
		$total = 0;
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$total += $this->minor( $row[7] ) + $this->minor( $row[9] );
		}
		$this->assertSame( 18000, $total );
		$this->assertSame( array(), $file['left_out'] );
		$this->assertStringContainsString( 'charset=windows-1252', $file['mime'] );
	}

	/**
	 * A refund is a sales credit with positive amounts; its reference is the order number and the refund id.
	 */
	public function test_refund_is_a_sales_credit(): void {
		$payload                         = FakeExportKit::refund( '1001', 55 );
		$payload['document']['currency'] = 'GBP';
		$payload['document']['total']    = 6000;
		$payload['refund_line'][0]['net'] = 5000;
		$payload['refund_line'][0]['tax'] = 1000;
		$payload['original_line'][0]['tax_code'] = 'T1';
		$payload['original_line'][0]['tax_rate'] = 20;
		$this->assertTrue( $this->send( $this->uk(), $payload, EntityType::Refund )->isSuccess() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'SC', 'WEB', '4000', '', '03/11/2026', '1001-R55', 'SKU-A', '50.00', 'T1', '10.00', '1', 'WC-1001', '' ), $rows[1] );
	}

	/**
	 * The four date formats of the import.
	 *
	 * @dataProvider dateFormats
	 *
	 * @param string $format   Setting.
	 * @param string $expected Date written for 2026-11-02.
	 */
	public function test_date_formats( string $format, string $expected ): void {
		$this->send( $this->uk( array( 'date_format' => $format ) ), $this->order() );
		$this->assertSame( $expected, $this->records( $this->file()['bytes'] )[1][4] );
	}

	/**
	 * Date formats and their output.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function dateFormats(): array {
		return array(
			'dd/mm/yyyy' => array( 'dd/mm/yyyy', '02/11/2026' ),
			'dd/mm/yy'   => array( 'dd/mm/yy', '02/11/26' ),
			'ddmmyyyy'   => array( 'ddmmyyyy', '02112026' ),
			'ddmmyy'     => array( 'ddmmyy', '021126' ),
		);
	}

	/**
	 * A tax code can carry the nominal for its rate; shipping, department, user and account have settings of their own.
	 */
	public function test_nominals_and_columns_from_settings(): void {
		$payload                         = $this->order( '1002', 'GBP', 'T1:4001' );
		$payload['customer']['account']  = 'layla01';
		$connection                      = $this->uk( array( 'shipping_nominal' => '4905', 'department' => '2', 'user_name' => 'webshop' ) );
		$this->send( $connection, $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '4001', $rows[1][2] );
		$this->assertSame( '4905', $rows[3][2] );
		$this->assertSame( 'LAYLA01', $rows[1][1] );
		$this->assertSame( '2', $rows[1][3] );
		$this->assertSame( 'webshop', $rows[1][12] );
		$this->assertSame( 'T1', $rows[1][8] );
	}

	/**
	 * Tax codes must be T0 to T99; anything else is named.
	 */
	public function test_tax_code_checks(): void {
		$this->send( $this->uk(), $this->order( '1003', 'GBP', 'T100' ) );
		$this->send( $this->uk(), $this->order( '1004', 'GBP', 'VAT' ) );
		$missing = $this->order( '1005' );
		unset( $missing['line'][0]['tax_code'] );
		$this->send( $this->uk(), $missing );
		$this->assertSame(
			array(
				'tax code "T100" is not a Sage tax code (T0 to T99)',
				'tax code "VAT" is not a Sage tax code (T0 to T99)',
				'line 1 has tax but no tax code',
			),
			$this->reasons()
		);
	}

	/**
	 * A line without tax gets T0 by default and always shows the tax amount.
	 */
	public function test_untaxed_line_gets_t0_and_a_written_zero_tax(): void {
		$payload                      = $this->order( '1006' );
		$payload['line']              = array( array_merge( $payload['line'][0], array( 'tax' => 0, 'tax_code' => '', 'tax_rate' => '' ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 10000;
		$this->send( $this->uk(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( 'T0', $rows[1][8] );
		$this->assertSame( '0.00', $rows[1][9] );
	}

	/**
	 * Net Amount holds eight digits before the decimals.
	 */
	public function test_amount_limit(): void {
		$payload                      = $this->order( '1007' );
		$payload['line']              = array( array_merge( $payload['line'][0], array( 'net' => 10000000000, 'tax' => 0 ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 10000000000;
		$this->send( $this->uk(), $payload );
		$this->assertSame( array( 'an amount is larger than Sage 50 can import (8 digits before the decimals)' ), $this->reasons() );

		$ok                           = $this->order( '1008' );
		$ok['line']                   = array( array_merge( $ok['line'][0], array( 'net' => 9999999999, 'tax' => 0 ) ) );
		$ok['shipping']               = array();
		$ok['document']['total']      = 9999999999;
		$this->send( $this->uk(), $ok );
		$this->assertSame( '99999999.99', $this->records( $this->file()['bytes'] )[1][7] );
	}

	/**
	 * The reference is the key: one that is too long leaves the order out. Long details are cut with an ellipsis.
	 */
	public function test_reference_and_details_limits(): void {
		$this->send( $this->uk(), $this->order( str_repeat( '9', 31 ) ) );
		$this->assertSame( array( 'the order number is longer than 30 characters' ), $this->reasons() );

		$payload                        = $this->order( '1009' );
		$payload['line'][0]['name']     = str_repeat( 'ب', 70 );
		$this->send( $this->uk(), $payload );
		$details = $this->records( $this->file()['bytes'], 'windows-1252' )[1][6];
		$this->assertSame( str_repeat( '?', 57 ) . '...', $details, 'Arabic cannot be written in Windows-1252; the cut counts characters, not bytes.' );
		$this->assertSame( 1, $this->deps->exports->openBatch( 7 )->rows()[1]['row']['cut'] );
	}

	/**
	 * The account reference must be one to eight letters or digits.
	 */
	public function test_account_reference_check(): void {
		$payload                        = $this->order( '1010' );
		$payload['customer']['account'] = 'TOO-LONG-ACCOUNT';
		$this->send( $this->uk(), $payload );
		$this->assertSame( array( 'account reference "TOO-LONG-ACCOUNT" is not 1 to 8 letters or digits' ), $this->reasons() );
	}

	/**
	 * Text with commas, quotes and accents is written as proper CSV, in Windows-1252 and in UTF-8.
	 */
	public function test_csv_quoting_and_encodings(): void {
		$payload                       = $this->order( '1011' );
		$payload['line'][0]['name']    = 'Tisch, "Eiche" Größe Ø 90 — ليلى';
		$this->send( $this->uk(), $payload );
		$file = $this->file();
		$this->assertStringContainsString( "\"Tisch, \"\"Eiche\"\" Gr\xF6\xDFe \xD8 90 \x97 ????\"", $file['bytes'] );
		$this->assertSame( 'Tisch, "Eiche" Größe Ø 90 — ????', $this->records( $file['bytes'] )[1][6] );

		$this->deps->exports->closeBatch( 7 );
		$this->send( $this->uk( array( 'encoding' => 'utf-8-bom' ) ), $payload );
		$utf8 = $this->file();
		$this->assertStringContainsString( 'charset=utf-8', $utf8['mime'] );
		$this->assertSame( 'Tisch, "Eiche" Größe Ø 90 — ليلى', $this->records( $utf8['bytes'], 'utf-8-bom' )[1][6] );
	}

	/**
	 * Windows-1252 cannot hold Arabic: the file has question marks, and the export says which characters of which order were replaced
	 * (a notes file, and the preview); with UTF-8 nothing is replaced and there is no notes file.
	 */
	public function test_replaced_characters_are_reported(): void {
		$payload                       = $this->order( '1020' );
		$payload['line'][0]['name']    = 'طاولة Oak';
		$this->send( $this->uk(), $payload );
		$file  = $this->file();
		$this->assertSame( '????? Oak', $this->records( $file['bytes'] )[1][6] );
		$this->assertArrayHasKey( 'Notes', $file['attachments'] );
		$this->assertStringContainsString( "Order 1020: ط, ا, و, ل, ة\n", $file['attachments']['Notes'] );
		$preview = $this->connector->previewText( $this->deps->exports->openBatch( 7 ) );
		$this->assertStringContainsString( '5 characters cannot be written in Windows-1252 and become question marks (1 orders).', $preview );
		$this->assertStringContainsString( 'Order 1020: ط, ا, و, ل, ة', $preview );

		$this->deps->exports->closeBatch( 7 );
		$this->send( $this->uk( array( 'encoding' => 'utf-8-bom' ) ), $payload );
		$this->assertArrayNotHasKey( 'Notes', $this->file()['attachments'] );
	}

	/**
	 * No heading row is written unless the person asks for one: no source says the audit-trail import skips the first row, and a heading
	 * row that is not skipped would be read as a transaction. The setting on the screen says No too.
	 */
	public function test_heading_row_is_off_by_default(): void {
		$connection = FakeExportKit::connection( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP' ) );
		$this->send( $connection, $this->order() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 3, $rows, 'Three rows for the three lines and no heading row.' );
		$this->assertSame( 'SI', $rows[0][0] );

		$field = array_values( array_filter( $this->connector->credentialFields(), static fn( $field ): bool => 'header_row' === $field->name ) );
		$this->assertCount( 1, $field );
		$this->assertSame( 'no', $field[0]->default );
		$this->assertStringContainsString( 'confirmed', $field[0]->hint );
	}

	/**
	 * The heading row can be switched on.
	 */
	public function test_with_heading_row(): void {
		$this->send( $this->uk( array( 'header_row' => 'yes' ) ), $this->order() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 4, $rows );
		$this->assertSame( 'Type', $rows[0][0] );
	}

	/**
	 * The heading row can be switched off.
	 */
	public function test_without_heading_row(): void {
		$this->send( $this->uk( array( 'header_row' => 'no' ) ), $this->order() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'SI', $rows[0][0] );
	}

	/**
	 * A difference of one minor unit goes into the largest row; two or more leave the order out.
	 */
	public function test_rounding_difference(): void {
		$payload                      = $this->order( '1012' );
		$payload['document']['total'] = 18001;
		$this->send( $this->uk(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '100.01', $rows[1][7] );

		$payload                      = $this->order( '1013' );
		$payload['document']['total'] = 18002;
		$this->send( $this->uk(), $payload );
		$this->assertSame( array( 'the lines add up to 180.00 but the order total is 180.02' ), $this->reasons() );
	}

	/**
	 * Refund shipping with tax is split with the rate of the original shipping tax code; other amounts need a nominal.
	 */
	public function test_refund_shipping_and_other_amounts(): void {
		$payload                         = FakeExportKit::refund( '1001', 56, array( 'refund_shipping' => array( 'gross' => 1200 ), 'refund_other' => array( 'gross' => 300 ) ) );
		$payload['document']['currency'] = 'GBP';
		$payload['document']['total']    = 7500;
		$payload['refund_line'][0]['net'] = 5000;
		$payload['refund_line'][0]['tax'] = 1000;
		$payload['original_line'][0]['tax_code']     = 'T1';
		$payload['original_shipping'][0]['tax_code'] = 'T1';
		$payload['original_shipping'][0]['tax_rate'] = 20;
		$this->send( $this->uk(), $payload, EntityType::Refund );
		$this->assertSame( array( 'refund amount 3.00 has no adjustment nominal' ), $this->reasons() );

		$again                          = $payload;
		$again['document']['number']    = '1002';
		$again['document']['refund_id'] = 57;
		$this->send( $this->uk( array( 'adjustment_account' => '4900' ) ), $again, EntityType::Refund );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 4, $rows );
		$this->assertSame( array( 'SC', 'WEB', '4000', '', '03/11/2026', '1002-R57', 'Shipping', '10.00', 'T1', '2.00', '1', 'WC-1001', '' ), $rows[2] );
		$this->assertSame( array( 'SC', 'WEB', '4900', '', '03/11/2026', '1002-R57', 'Refund adjustment', '3.00', 'T0', '0.00', '1', 'WC-1001', '' ), $rows[3] );
	}

	/**
	 * A paid order gets a receipt row against the bank nominal of its payment method.
	 */
	public function test_receipt_row(): void {
		$payload            = $this->order( '1014' );
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04', 'reference' => 'pi_1' );
		$connection         = $this->uk( array( 'record_payments' => 'yes', 'payment_accounts' => "stripe = 1200\npaypal = 1210" ) );
		$this->send( $connection, $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 5, $rows );
		$this->assertSame( array( 'SR', 'WEB', '1200', '', '04/11/2026', '1014', 'Payment Card', '180.00', 'T9', '0.00', '1', 'WC-1014', '' ), $rows[4] );

		$other            = $this->order( '1015' );
		$other['payment'] = array( 'method' => 'tabby', 'title' => 'Tabby', 'paid_at' => '2026-11-04', 'reference' => '' );
		$this->send( $connection, $other );
		$this->assertSame( array( 'payment method Tabby has no bank nominal' ), $this->reasons() );
	}

	/**
	 * Payments are off by default.
	 */
	public function test_payments_off_by_default(): void {
		$payload            = $this->order( '1016' );
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04', 'reference' => '' );
		$this->send( $this->uk(), $payload );
		$this->assertCount( 4, $this->records( $this->file()['bytes'] ) );
	}

	/**
	 * Other currencies are left out.
	 */
	public function test_other_currency_is_left_out(): void {
		$this->send( $this->uk(), $this->order( '1017', 'EUR' ) );
		$this->assertSame( array( 'its currency (EUR) is not the currency of the books (GBP)' ), $this->reasons() );
	}

	/**
	 * Incomplete settings stop the export and the test says why.
	 */
	public function test_settings_problems(): void {
		$bad = $this->connector->test( FakeExportKit::connection( 'sage50-export', array( 'edition' => 'uk', 'customer_account' => 'MUCH-TOO-LONG' ) ) );
		$this->assertFalse( $bad->ok );
		$this->assertStringContainsString( 'currency', (string) $bad->error->context['reason'] );
		$this->assertStringContainsString( 'account reference', (string) $bad->error->context['reason'] );
		$this->assertTrue( $this->connector->test( $this->uk() )->ok );
		$this->expectException( ExportException::class );
		$this->send( FakeExportKit::connection( 'sage50-export', array( 'edition' => 'uk' ) ), $this->order() );
	}

	/**
	 * The same item twice is one set of rows.
	 */
	public function test_duplicate_protection(): void {
		$item = FakeExportKit::item( 3, '1018' );
		$this->assertFalse( $this->connector->send( $this->uk(), $item, $this->order( '1018' ) )->alreadyExisted );
		$this->assertTrue( $this->connector->send( $this->uk(), $item, $this->order( '1018' ) )->alreadyExisted );
		$this->assertNotNull( $this->connector->findExisting( $this->uk(), $item ) );
		$this->assertCount( 4, $this->records( $this->file()['bytes'] ) );
	}

	/**
	 * The masters list names the accounts, nominals and tax codes, and the preview shows the heading and the first rows.
	 */
	public function test_masters_list_and_preview(): void {
		$this->send( $this->uk(), $this->order( '1019' ) );
		$this->send( $this->uk(), $this->order( '1020', 'GBP', 'T2:4010' ) );
		$batch = $this->deps->exports->openBatch( 7 );
		$list  = $this->connector->mastersList( $batch );
		$this->assertStringContainsString( "Accounts and nominal or G/L codes (1)\n  WEB\n", $list );
		$this->assertStringContainsString( "Nominal codes (2)\n  4000\n  4010\n", $list );
		$this->assertStringContainsString( "Tax codes (2)\n  T1\n  T2\n", $list );
		$text = $this->connector->previewText( $batch );
		$this->assertStringStartsWith( 'Sage 50 Accounts (UK and Ireland) CSV: 2 orders, 6 rows, written in windows-1252.', $text );
		$this->assertStringContainsString( 'Type,Account Reference,Nominal A/C Code', $text );
	}

	/**
	 * Only orders and refunds go into the file.
	 */
	public function test_other_entities_are_refused(): void {
		$result = $this->connector->send( $this->uk(), FakeExportKit::item( 9, '1', EntityType::Stock ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'SYNC-VAL-002', $result->error->code->value );
	}

	/**
	 * The decimals follow the currency: none for the yen, three for the Kuwaiti dinar, with a decimal point.
	 */
	public function test_currency_decimals(): void {
		$this->send( $this->uk( array( 'currency' => 'JPY' ) ), $this->order( '1030', 'JPY' ) );
		$yen = $this->records( $this->file()['bytes'] );
		$this->assertSame( '10000', $yen[1][7] );
		$this->assertSame( '2000', $yen[1][9] );

		$this->deps->exports->closeBatch( 7 );
		$this->send( $this->uk( array( 'currency' => 'KWD' ) ), $this->order( '1031', 'KWD' ) );
		$kwd = $this->records( $this->file()['bytes'] );
		$this->assertSame( '10.000', $kwd[1][7] );
		$this->assertSame( '2.000', $kwd[1][9] );
	}
}
