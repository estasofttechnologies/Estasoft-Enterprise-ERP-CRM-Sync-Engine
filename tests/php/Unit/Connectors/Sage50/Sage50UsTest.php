<?php
/**
 * The Sage 50 U.S. Sales Journal file, read back and compared field by field.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage50;

use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50UsWriter
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Settings
 */
final class Sage50UsTest extends Sage50TestCase {

	/**
	 * The fields of the Sales Journal import/export list, in the order of the import guide.
	 */
	private const HEADINGS = array(
		'Customer ID',
		'Invoice/CM #',
		'Apply to Invoice Number',
		'Credit Memo',
		'Date',
		'Date Due',
		'Customer PO',
		'Accounts Receivable Account',
		'Number of Distributions',
		'Invoice/CM Distribution',
		'Quantity',
		'Item ID',
		'Description',
		'G/L Account',
		'Unit Price',
		'Tax Type',
		'Amount',
		'Sales Tax Agency',
		'Recur Number',
		'Recur Frequency',
	);

	/**
	 * The reasons orders were left out.
	 *
	 * @return array<int,string>
	 */
	private function reasons(): array {
		return array_column( $this->deps->exports->openBatch( 7 )->leftOut(), 'reason' );
	}

	/**
	 * Heading names, header fields repeated on every distribution, numbering, freight and tax lines, and the sign of the amounts.
	 */
	public function test_invoice_round_trip(): void {
		$connection = $this->us( array( 'due_days' => 14, 'use_items' => 'yes' ) );
		$this->assertTrue( $this->send( $connection, $this->order( '1001', 'USD', 'STATE' ) )->isSuccess() );
		$file = $this->file();
		$rows = $this->records( $file['bytes'] );
		$this->assertSame( self::HEADINGS, $rows[0] );
		$this->assertCount( 5, $rows, 'Heading, two products, freight and one tax line.' );

		$head = array( 'WEB', '1001', '', 'FALSE', '11/02/2026', '11/16/2026', 'WC-1001', '11000', '4' );
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$this->assertSame( $head, array_slice( $row, 0, 9 ) );
			$this->assertCount( 20, $row );
			$this->assertSame( array( '0', '0' ), array_slice( $row, 18, 2 ) );
		}
		// Distribution, quantity, item, description, G/L, unit price, tax type, amount, agency.
		$this->assertSame( array( '1', '2', 'SKU-A', 'Cedar shelf', '40000', '50.00', '1', '-100.00', 'STATE' ), array_slice( $rows[1], 9, 9 ) );
		$this->assertSame( array( '2', '1', 'SKU-B', 'Stone coaster', '40000', '40.00', '1', '-40.00', 'STATE' ), array_slice( $rows[2], 9, 9 ) );
		$this->assertSame( array( '0', '', '', 'freight charge', '40000', '', '27', '-10.00', '' ), array_slice( $rows[3], 9, 9 ) );
		$this->assertSame( array( '0', '', '', 'Sales tax STATE', '23000', '', '0', '-30.00', 'STATE' ), array_slice( $rows[4], 9, 9 ) );
		$total = 0;
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$total += $this->minor( $row[16] );
		}
		$this->assertSame( -18000, $total, 'The credits add up to the order total; the receivable debit is implied.' );
		$this->assertSame( array(), $file['left_out'] );
	}

	/**
	 * Without items the Item ID stays empty.
	 */
	public function test_item_id_only_when_asked(): void {
		$this->send( $this->us(), $this->order( '1002', 'USD', 'STATE' ) );
		$this->assertSame( '', $this->records( $this->file()['bytes'] )[1][11] );
	}

	/**
	 * A refund is a credit memo that applies to the order's invoice, with positive amounts.
	 */
	public function test_credit_memo(): void {
		$payload                         = FakeExportKit::refund( '1001', 55 );
		$payload['document']['currency'] = 'USD';
		$payload['document']['total']    = 6000;
		$payload['refund_line'][0]['net'] = 5000;
		$payload['refund_line'][0]['tax'] = 1000;
		$payload['original_line'][0]['tax_code'] = 'STATE:24000';
		$this->assertTrue( $this->send( $this->us(), $payload, EntityType::Refund )->isSuccess() );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'WEB', '1001-R55', '1001', 'TRUE', '11/03/2026', '11/03/2026', 'WC-1001', '11000', '2' ), array_slice( $rows[1], 0, 9 ) );
		$this->assertSame( array( '1', '1', '', 'SKU-A', '40000', '50.00', '1', '50.00', 'STATE' ), array_slice( $rows[1], 9, 9 ) );
		$this->assertSame( array( '0', '', '', 'Sales tax STATE', '24000', '', '0', '10.00', 'STATE' ), array_slice( $rows[2], 9, 9 ) );
	}

	/**
	 * A tax line needs a G/L account, from the code or the setting.
	 */
	public function test_tax_line_needs_an_account(): void {
		$this->send( $this->us( array( 'tax_gl_account' => '' ) ), $this->order( '1003', 'USD', 'STATE' ) );
		$this->assertSame( array( 'sales tax agency STATE has no G/L account for its tax' ), $this->reasons() );
		$this->send( $this->us( array( 'tax_gl_account' => '' ) ), $this->order( '1004', 'USD', 'STATE:23100' ) );
		$this->assertSame( '23100', $this->records( $this->file()['bytes'] )[4][13] );
	}

	/**
	 * Tax codes: lines with tax need one, long agency IDs are refused.
	 */
	public function test_tax_code_checks(): void {
		$missing = $this->order( '1005', 'USD', 'STATE' );
		unset( $missing['shipping'][0]['tax_code'] );
		$this->send( $this->us(), $missing );
		$this->send( $this->us(), $this->order( '1006', 'USD', str_repeat( 'A', 21 ) ) );
		$this->assertSame(
			array(
				'shipping has tax but no tax code',
				'tax code "' . str_repeat( 'A', 21 ) . '" is not a sales tax agency ID, optionally with a G/L account',
			),
			$this->reasons()
		);
	}

	/**
	 * An order with no tax at all writes no tax line.
	 */
	public function test_untaxed_order_has_no_tax_line(): void {
		$payload                      = $this->order( '1007', 'USD', '' );
		foreach ( array_keys( $payload['line'] ) as $i ) {
			$payload['line'][ $i ]['tax']      = 0;
			unset( $payload['line'][ $i ]['tax_code'], $payload['line'][ $i ]['tax_rate'] );
		}
		$payload['shipping'][0]['tax'] = 0;
		unset( $payload['shipping'][0]['tax_code'] );
		$payload['document']['total']  = 15000;
		$this->send( $this->us(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 4, $rows );
		$this->assertSame( '3', $rows[1][8] );
		$this->assertSame( '', $rows[1][17] );
	}

	/**
	 * Double quotes in text become single quotes; commas are quoted; the description is cut to 160 characters.
	 */
	public function test_text_rules(): void {
		$payload                      = $this->order( '1008', 'USD', 'STATE' );
		$payload['line'][0]['name']   = 'Table, "oak" 90cm';
		$payload['line'][1]['name']   = str_repeat( 'x', 200 );
		$this->send( $this->us(), $payload );
		$file = $this->file();
		$this->assertStringContainsString( ",\"Table, 'oak' 90cm\",", $file['bytes'] );
		$rows = $this->records( $file['bytes'] );
		$this->assertSame( "Table, 'oak' 90cm", $rows[1][12] );
		$this->assertSame( 160, strlen( $rows[2][12] ) );
		$this->assertStringEndsWith( '...', $rows[2][12] );
	}

	/**
	 * The order number is the key: over twenty characters the order is left out.
	 */
	public function test_invoice_number_limit(): void {
		$this->send( $this->us(), $this->order( str_repeat( '7', 21 ), 'USD', 'STATE' ) );
		$this->assertSame( array( 'the order number is longer than 20 characters' ), $this->reasons() );
	}

	/**
	 * More than 147 distributions are refused.
	 */
	public function test_distribution_limit(): void {
		$payload                      = $this->order( '1009', 'USD', 'STATE' );
		$line                         = $payload['line'][1];
		$payload['line']              = array_fill( 0, 150, array_merge( $line, array( 'net' => 100, 'tax' => 20 ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 150 * 120;
		$this->send( $this->us(), $payload );
		$this->assertSame( array( 'the order has 151 lines, more than the 147 Sage 50 takes in one invoice' ), $this->reasons() );
	}

	/**
	 * A one-unit difference goes into the tax line; a larger one leaves the order out.
	 */
	public function test_rounding_difference(): void {
		$payload                      = $this->order( '1010', 'USD', 'STATE' );
		$payload['document']['total'] = 18001;
		$this->send( $this->us(), $payload );
		$this->assertSame( '-30.01', $this->records( $this->file()['bytes'] )[4][16] );

		$payload                      = $this->order( '1011', 'USD', 'STATE' );
		$payload['document']['total'] = 18005;
		$this->send( $this->us(), $payload );
		$this->assertSame( array( 'the lines add up to 180.00 but the order total is 180.05' ), $this->reasons() );
	}

	/**
	 * A price per unit that does not divide evenly keeps extra decimals so quantity times price gives the amount.
	 */
	public function test_unit_price_with_extra_decimals(): void {
		$payload                       = $this->order( '1012', 'USD', 'STATE' );
		$payload['line']               = array( array_merge( $payload['line'][0], array( 'quantity' => 3, 'net' => 1999, 'tax' => 0, 'tax_code' => 'STATE' ) ) );
		$payload['shipping']           = array();
		$payload['document']['total']  = 1999;
		$this->send( $this->us(), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '6.6633', $rows[1][14] );
		$this->assertSame( '-19.99', $rows[1][16] );
	}

	/**
	 * The US edition needs the receivable account, the sales account and a customer ID.
	 */
	public function test_settings_problems(): void {
		$bad    = $this->connector->test( FakeExportKit::connection( 'sage50-export', array( 'edition' => 'us', 'currency' => 'USD', 'customer_id' => '' ) ) );
		$reason = (string) $bad->error->context['reason'];
		$this->assertFalse( $bad->ok );
		$this->assertStringContainsString( 'Accounts Receivable account', $reason );
		$this->assertStringContainsString( 'G/L account for sales', $reason );
		$this->assertTrue( $this->connector->test( $this->us() )->ok );
		$this->expectException( ExportException::class );
		$this->send( FakeExportKit::connection( 'sage50-export', array( 'edition' => 'us', 'currency' => 'USD' ) ), $this->order( '1', 'USD' ) );
	}

	/**
	 * Receipts are not written for the US edition, even when asked.
	 */
	public function test_no_receipts_in_the_us_edition(): void {
		$payload            = $this->order( '1013', 'USD', 'STATE' );
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04', 'reference' => '' );
		$this->send( $this->us( array( 'record_payments' => 'yes' ) ), $payload );
		$this->assertCount( 5, $this->records( $this->file()['bytes'] ) );
		$this->assertSame( array(), $this->reasons() );
	}

	/**
	 * The preview carries the field order for the import template; the masters list names customers, accounts, agencies and items.
	 */
	public function test_preview_and_masters(): void {
		$this->send( $this->us( array( 'use_items' => 'yes' ) ), $this->order( '1014', 'USD', 'STATE' ) );
		$batch = $this->deps->exports->openBatch( 7 );
		$text  = $this->connector->previewText( $batch );
		$this->assertStringStartsWith( 'Sage 50 U.S. edition CSV: 1 order, 4 rows, written in windows-1252.', $text );
		$this->assertStringContainsString( 'Import template fields, in this order: ' . implode( ', ', self::HEADINGS ), $text );
		$list = $this->connector->mastersList( $batch );
		$this->assertStringContainsString( "Customer IDs (1)\n  WEB\n", $list );
		$this->assertStringContainsString( "Accounts and nominal or G/L codes (3)\n  11000\n  23000\n  40000\n", $list );
		$this->assertStringContainsString( "Sales tax agencies (1)\n  STATE\n", $list );
		$this->assertStringContainsString( "Item IDs (2)\n  SKU-A\n  SKU-B\n", $list );
	}
}
