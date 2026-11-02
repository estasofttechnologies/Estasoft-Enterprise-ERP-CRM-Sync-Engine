<?php
/**
 * The rules of the DATEV file: financial years, debtor accounts, account checks, rounding, payments and the reasons an order is left out.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Datev;

use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevPostingWriter
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevSettings
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevAccount
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevYear
 */
final class DatevRulesTest extends DatevTestCase {

	/**
	 * The reasons orders were left out, in order.
	 *
	 * @return array<int,string>
	 */
	private function reasons(): array {
		return array_column( $this->deps->exports->openBatch( 7 )->leftOut(), 'reason' );
	}

	/**
	 * A file belongs to one financial year: the first order decides, a later one from another year is left out and named.
	 */
	public function test_a_file_never_mixes_two_financial_years(): void {
		$connection = $this->connection( array( 'fiscal_year_start' => '04-01' ) );
		$a          = $this->order( '3001' );
		$a['document']['date'] = '2026-03-31';
		$b          = $this->order( '3002' );
		$b['document']['date'] = '2026-04-01';
		$c          = $this->order( '3003' );
		$c['document']['date'] = '2026-01-15';
		foreach ( array( $a, $b, $c ) as $payload ) {
			$this->send( $connection, $payload );
		}
		$file = $this->file();
		$rows = $this->records( $file['bytes'] );
		$this->assertSame( '20250401', $rows[0][12], 'The year of 31 March 2026 starts on 1 April 2025.' );
		$this->assertSame( '20260115', $rows[0][14] );
		$this->assertSame( '20260331', $rows[0][15] );
		$this->assertSame( array( '3001', '3003' ), array_column( array_slice( $rows, 2 ), 10 ) );
		$this->assertSame(
			array( 'dated 2026-04-01, a different financial year from this file (which starts 2025-04-01); export it again after this file' ),
			array_column( $file['left_out'], 'reason' )
		);
		$this->assertSame( array( '3002' ), array_column( $file['left_out'], 'order' ) );
	}

	/**
	 * The first day of the year is the start of the new financial year and the last day belongs to the old one.
	 */
	public function test_year_boundaries(): void {
		$connection = $this->connection();
		$new        = $this->order( '3101' );
		$new['document']['date'] = '2027-01-01';
		$old        = $this->order( '3102' );
		$old['document']['date'] = '2026-12-31';
		$this->send( $connection, $old );
		$this->send( $connection, $new );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '20260101', $rows[0][12] );
		$this->assertSame( '3112', $rows[2][9] );
		$this->assertCount( 3, $rows );
	}

	/**
	 * Registered customers get the account of the range plus their number; guests use the collective account.
	 */
	public function test_debtor_account_for_each_customer(): void {
		$connection = $this->connection( array( 'debtor_mode' => 'customer_id', 'collective_account' => '10001' ) );
		$a          = $this->order( '3201' );
		$a['customer']['id'] = 41922;
		$this->send( $connection, $a );
		$this->send( $connection, $this->order( '3202' ) );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '51922', $rows[2][6] );
		$this->assertSame( '10001', $rows[3][6] );
	}

	/**
	 * A customer number that leaves the debtor range (10000 to 69999) is left out.
	 */
	public function test_customer_outside_the_debtor_range(): void {
		$payload             = $this->order( '3203' );
		$payload['customer']['id'] = 60000;
		$this->send( $this->connection( array( 'debtor_mode' => 'customer_id' ) ), $payload );
		$this->assertSame( array( 'customer 60000 does not fit the debtor range' ), $this->reasons() );
	}

	/**
	 * The debtor account must have one digit more than the account length.
	 */
	public function test_debtor_account_digits(): void {
		$this->expectException( ExportException::class );
		$this->send( $this->connection( array( 'debtor_account' => '1000' ) ), $this->order() );
	}

	/**
	 * Tax codes that are not accounts, accounts that are too long, and lines without a code are named.
	 */
	public function test_tax_code_checks(): void {
		$connection = $this->connection();

		$bad                            = $this->order( '3301' );
		$bad['line'][0]['tax_code']     = 'VAT19';
		$this->send( $connection, $bad );

		$long                           = $this->order( '3302' );
		$long['line'][0]['tax_code']    = '84000';
		$this->send( $connection, $long );

		$missing                        = $this->order( '3303' );
		unset( $missing['line'][1]['tax_code'] );
		$this->send( $connection, $missing );

		$shipping                       = $this->order( '3304' );
		unset( $shipping['shipping'][0]['tax_code'] );
		$this->send( $connection, $shipping );

		$this->assertSame(
			array(
				'tax code "VAT19" is not an account number',
				'tax code "84000" is not an account number',
				'line 2 has tax but no tax code',
				'shipping has tax but no tax code',
			),
			$this->reasons()
		);
	}

	/**
	 * Lines without tax use the account for untaxed sales, with its BU key; without that setting the order is left out.
	 */
	public function test_untaxed_sales(): void {
		$payload                      = $this->order( '3401' );
		$payload['line']              = array( array_merge( $payload['line'][0], array( 'tax' => 0, 'tax_code' => '', 'tax_rate' => '', 'net' => 10000 ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 10000;
		$this->send( $this->connection(), $payload );
		$this->assertSame( array( 'line 1 has no tax code and no account for untaxed sales is set' ), $this->reasons() );

		$this->send( $this->connection( array( 'untaxed_code' => '8120:2' ) ), array_merge( $payload, array( 'document' => array_merge( $payload['document'], array( 'number' => '3402', 'reference' => 'WC-3402' ) ) ) ) );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '100,00', $rows[2][0] );
		$this->assertSame( '8120', $rows[2][7] );
		$this->assertSame( '2', $rows[2][8] );
	}

	/**
	 * Shipping can go to an own revenue account, keeping the BU key of its tax code.
	 */
	public function test_shipping_account(): void {
		$payload                           = $this->order( '3501' );
		$payload['shipping'][0]['tax_code'] = '8400:9';
		$this->send( $this->connection( array( 'shipping_account' => '8190' ) ), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$by   = array();
		foreach ( array_slice( $rows, 2 ) as $row ) {
			$by[ $row[7] ] = $row;
		}
		$this->assertSame( '11,90', $by['8190'][0] );
		$this->assertSame( '9', $by['8190'][8] );
		$this->assertSame( '178,50', $by['8400'][0] );
	}

	/**
	 * A small difference between the order total and the postings goes into the largest posting; a large one leaves the order out.
	 */
	public function test_rounding_difference(): void {
		$payload                      = $this->order( '3601' );
		$payload['document']['total'] = 19042;
		$this->send( $this->connection(), $payload );
		$this->assertSame( '190,42', $this->records( $this->file()['bytes'] )[2][0] );

		$payload                      = $this->order( '3602' );
		$payload['document']['total'] = 19050;
		$this->send( $this->connection(), $payload );
		$this->assertSame( array( 'the postings add up to 190.40 but the order total is 190.50' ), $this->reasons() );
	}

	/**
	 * The postings of an order add up to its total (with two tax rates and a fee).
	 */
	public function test_postings_add_up_to_the_order_total(): void {
		$payload                      = $this->order( '3701' );
		$payload['line'][1]['tax_code'] = '8300';
		$payload['line'][1]['tax']    = 350;
		$payload['fee']               = array( array( 'name' => 'Gift wrap', 'net' => 500, 'tax' => 95 ) );
		$payload['document']['total'] = 11900 + 5350 + 1190 + 595;
		$this->send( $this->connection( array( 'fee_tax_code' => '8400' ) ), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$sum  = 0;
		foreach ( array_slice( $rows, 2 ) as $row ) {
			$sum += (int) str_replace( ',', '', $row[0] );
		}
		$this->assertSame( 19035, $sum );
	}

	/**
	 * A fee with tax and no account for fees is left out.
	 */
	public function test_fee_without_account(): void {
		$payload                      = $this->order( '3801' );
		$payload['fee']               = array( array( 'name' => 'Gift wrap', 'net' => 500, 'tax' => 95 ) );
		$payload['document']['total'] = 19040 + 595;
		$this->send( $this->connection(), $payload );
		$this->assertSame( array( 'fee has tax but no tax code' ), $this->reasons() );
	}

	/**
	 * Orders in another currency are left out: DATEV files here carry no foreign-currency postings.
	 */
	public function test_other_currency_is_left_out(): void {
		$payload                         = $this->order( '3901' );
		$payload['document']['currency'] = 'CHF';
		$this->send( $this->connection(), $payload );
		$this->assertSame( array( 'its currency (CHF) is not the currency of the books (EUR)' ), $this->reasons() );
	}

	/**
	 * A paid order gets a payment posting from the method's account to the debtor, with the same Belegfeld 1 (the open item key).
	 */
	public function test_payment_posting(): void {
		$payload            = $this->order( '4001' );
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04', 'reference' => 'pi_1' );
		$connection         = $this->connection( array( 'record_payments' => 'yes', 'payment_accounts' => "stripe = 1360\npaypal = 1361" ) );
		$this->send( $connection, $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertCount( 4, $rows );
		$pay = $rows[3];
		$this->assertSame( '190,40', $pay[0] );
		$this->assertSame( 'S', $pay[1] );
		$this->assertSame( '1360', $pay[6] );
		$this->assertSame( '10000', $pay[7] );
		$this->assertSame( '0411', $pay[9] );
		$this->assertSame( '4001', $pay[10] );
		$this->assertSame( '20261104', $rows[0][15], 'The header range covers the payment date.' );
	}

	/**
	 * A payment method with no account leaves the order out; a payment in another financial year too.
	 */
	public function test_payment_problems(): void {
		$payload            = $this->order( '4101' );
		$payload['payment'] = array( 'method' => 'tabby', 'title' => 'Tabby', 'paid_at' => '2026-11-02', 'reference' => '' );
		$connection         = $this->connection( array( 'record_payments' => 'yes', 'payment_accounts' => 'stripe = 1360' ) );
		$this->send( $connection, $payload );

		$late                         = $this->order( '4102' );
		$late['document']['date']     = '2026-12-31';
		$late['payment']              = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2027-01-02', 'reference' => '' );
		$this->send( $connection, $late );
		$this->assertSame( array( 'payment method Tabby has no account', 'it was paid in another financial year than the order' ), $this->reasons() );
	}

	/**
	 * Payments are not written unless the setting is on, and a refund never gets a payment posting.
	 */
	public function test_payments_are_off_by_default(): void {
		$payload            = $this->order( '4201' );
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04', 'reference' => '' );
		$this->send( $this->connection(), $payload );
		$this->assertCount( 3, $this->records( $this->file()['bytes'] ) );
	}

	/**
	 * A refund amount that belongs to no product needs the adjustment account.
	 */
	public function test_refund_other_amount(): void {
		$payload                          = FakeExportKit::refund( '1001', 56, array( 'refund_other' => array( 'gross' => 500 ) ) );
		$payload['document']['currency']  = 'EUR';
		$payload['document']['total']     = 6450;
		$payload['original_line'][0]['tax_code'] = '8400';
		$payload['refund_line'][0]['tax'] = 950;
		$payload['refund_line'][0]['net'] = 5000;
		$this->send( $this->connection(), $payload, EntityType::Refund );
		$this->assertSame( array( 'refund amount 5.00 has no adjustment account' ), $this->reasons() );

		$again                           = $payload;
		$again['document']['number']     = '1002';
		$again['document']['reference']  = 'WC-1002';
		$again['document']['refund_id']  = 57;
		$this->send( $this->connection( array( 'adjustment_account' => '8590' ) ), $again, EntityType::Refund );
		$rows = $this->records( $this->file()['bytes'] );
		$by   = array();
		foreach ( array_slice( $rows, 2 ) as $row ) {
			$by[ $row[7] ] = $row;
		}
		$this->assertSame( '5,00', $by['8590'][0] );
		$this->assertSame( 'H', $by['8590'][1] );
		$this->assertSame( '1002-R57', $by['8590'][10] );
	}

	/**
	 * Without the numbers of the consultant and the client, nothing is exported and the test says why.
	 */
	public function test_incomplete_settings(): void {
		$bad = $this->connector->test( FakeExportKit::connection( 'datev-export', array() ) );
		$this->assertFalse( $bad->ok );
		$reason = (string) $bad->error->context['reason'];
		$this->assertStringContainsString( 'consultant number', $reason );
		$this->assertStringContainsString( 'client number', $reason );
		$this->assertTrue( $this->connector->test( $this->connection() )->ok );

		$this->expectException( ExportException::class );
		$this->send( FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 1000, 'client_number' => 1 ) ), $this->order() );
	}

	/**
	 * Impossible dates for the financial year are a setting problem, not a silent default.
	 */
	public function test_fiscal_year_start_must_be_a_real_day(): void {
		foreach ( array( '02-30', '13-01', '1-1', 'April' ) as $bad ) {
			$result = $this->connector->test( $this->connection( array( 'fiscal_year_start' => $bad ) ) );
			$this->assertFalse( $result->ok, $bad );
		}
	}

	/**
	 * Sending the same item twice makes one posting group; the order is found in the open batch.
	 */
	public function test_duplicate_protection(): void {
		$connection = $this->connection();
		$item       = FakeExportKit::item( 5, '4301' );
		$this->assertFalse( $this->connector->send( $connection, $item, $this->order( '4301' ) )->alreadyExisted );
		$this->assertTrue( $this->connector->send( $connection, $item, $this->order( '4301' ) )->alreadyExisted );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
		$this->assertCount( 3, $this->records( $this->file()['bytes'] ) );
	}

	/**
	 * Only orders and refunds go into a DATEV file.
	 */
	public function test_other_entities_are_refused(): void {
		$result = $this->connector->send( $this->connection(), FakeExportKit::item( 6, '1', EntityType::Customer ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'SYNC-VAL-002', $result->error->code->value );
	}

	/**
	 * The masters list names the accounts and keys and carries no amounts; the preview shows the start of the file.
	 */
	public function test_masters_list_and_preview(): void {
		$payload                         = $this->order( '4401' );
		$payload['line'][1]['tax_code']  = '8300:2';
		$this->send( $this->connection(), $payload );
		$this->send( $this->connection(), $this->order( '4402' ) );
		$batch = $this->deps->exports->openBatch( 7 );
		$list  = $this->connector->mastersList( $batch );
		$this->assertStringContainsString( "Debtor accounts (Debitoren) (1)\n  10000\n", $list );
		$this->assertStringContainsString( "Revenue accounts (Erlöskonten) (2)\n  8300\n  8400\n", $list );
		$this->assertStringContainsString( "BU keys (Buchungsschlüssel) (1)\n  2\n", $list );
		$this->assertDoesNotMatchRegularExpression( '/\d,\d\d/', $list );

		$text = $this->connector->previewText( $batch );
		$this->assertStringStartsWith( 'DATEV-Format Buchungsstapel: 2 orders, 3 postings, written in windows-1252.', $text );
		$this->assertStringContainsString( 'Consultant 29098, client 55003, financial year from 2026-01-01.', $text );
		$this->assertStringContainsString( ';"WC-4401"', $text );
	}

	/**
	 * The decimals follow the currency of the books: three for the Kuwaiti dinar, with the decimal comma.
	 */
	public function test_currency_decimals(): void {
		$payload                         = $this->order( '4501' );
		$payload['document']['currency'] = 'KWD';
		$this->send( $this->connection( array( 'currency' => 'KWD' ) ), $payload );
		$rows = $this->records( $this->file()['bytes'] );
		$this->assertSame( '19,040', $rows[2][0] );
		$this->assertSame( 'KWD', $rows[0][21] );
	}
}
