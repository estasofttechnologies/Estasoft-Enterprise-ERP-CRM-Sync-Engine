<?php
/**
 * Reading a mapped payload into an export document, and the reasons it is refused.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\ExportShared\DocumentReader;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\Refusal;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\DocumentReader
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument
 */
final class DocumentReaderTest extends TestCase {

	/**
	 * The reason a payload is refused.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param bool                $refund  Whether it is a refund.
	 */
	private function reason( array $payload, bool $refund = false ): string {
		try {
			( new DocumentReader( 'AED' ) )->read( $payload, $refund );
		} catch ( Refusal $refusal ) {
			return $refusal->getMessage();
		}
		return '';
	}

	/**
	 * A complete order reads into the document it describes.
	 */
	public function test_order(): void {
		$payload                     = FakeExportKit::order( ' 1001 ' );
		$payload['customer']['name'] = "  Layla \n Haddad ";
		$payload['fee']              = array( array( 'name' => '', 'net' => 500, 'tax' => 25 ) );
		$payload['document']['excluded_total'] = 1000;
		$document                    = ( new DocumentReader( 'AED' ) )->read( $payload, false );

		$this->assertSame( ExportDocument::INVOICE, $document->kind );
		$this->assertFalse( $document->isCredit() );
		$this->assertSame( '1001', $document->number );
		$this->assertSame( '1001', $document->voucherNumber() );
		$this->assertSame( 'WC- 1001', $document->reference, 'Cleaned like every text.' );
		$this->assertSame( '2026-11-02', $document->date );
		$this->assertSame( 'Layla Haddad', $document->partyName );
		$this->assertSame( 14750, $document->expected, 'The shop total minus what was left out of the sync.' );
		$this->assertCount( 2, $document->lines );
		$this->assertSame( 'SKU-A', $document->lines[0]->sku );
		$this->assertSame( '2', $document->lines[0]->quantity );
		$this->assertSame( 10000, $document->lines[0]->net );
		$this->assertSame( 10500, $document->lines[0]->gross() );
		$this->assertSame( '5', $document->lines[0]->taxRate );
		$this->assertCount( 1, $document->shipping );
		$this->assertSame( 'Fee', $document->fees[0]->name, 'A fee without a name is called Fee.' );
		$this->assertSame( 10500 + 4200 + 1050 + 525, $document->computedGross() );
		$this->assertSame( 14750, $document->gross() );
		$this->assertSame( 14750 - ( 10500 + 4200 + 1050 + 525 ), $document->difference() );
	}

	/**
	 * Without a total the lines are the total.
	 */
	public function test_no_total(): void {
		$payload = FakeExportKit::order();
		unset( $payload['document']['total'] );
		$document = ( new DocumentReader( 'AED' ) )->read( $payload, false );
		$this->assertNull( $document->expected );
		$this->assertSame( 15750, $document->gross() );
		$this->assertSame( 0, $document->difference() );
	}

	/**
	 * Numbers may arrive as digit strings, a payload from a hand-edited mapping.
	 */
	public function test_digit_strings_are_accepted(): void {
		$payload                          = FakeExportKit::order();
		$payload['line'][0]['net']        = '10000';
		$payload['line'][0]['quantity']   = '2';
		$payload['line'][0]['tax_rate']   = '5.0';
		$payload['document']['total']     = '15750';
		$document                         = ( new DocumentReader( 'AED' ) )->read( $payload, false );
		$this->assertSame( 10000, $document->lines[0]->net );
		$this->assertSame( '5', $document->lines[0]->taxRate );
		$this->assertSame( 15750, $document->expected );
	}

	/**
	 * Each missing or odd value has its own short reason.
	 */
	public function test_refusals(): void {
		$base = FakeExportKit::order();

		$p = $base;
		unset( $p['document']['number'] );
		$this->assertSame( 'the order number is missing', $this->reason( $p ) );

		$p = $base;
		$p['document']['date'] = 'yesterday';
		$this->assertSame( 'the order date is missing', $this->reason( $p ) );
		$p['document']['date'] = '2026-02-30';
		$this->assertSame( 'the order date is missing', $this->reason( $p ) );

		$p = $base;
		$p['customer']['name'] = '';
		$this->assertSame( 'the customer name is empty', $this->reason( $p ) );

		$p = $base;
		$p['document']['currency'] = 'usd';
		$this->assertSame( 'its currency (USD) is not the currency of the books (AED)', $this->reason( $p ) );

		$p = $base;
		$p['line'][1]['quantity'] = 0;
		$this->assertSame( 'line 2 has no valid quantity', $this->reason( $p ) );

		$p = $base;
		unset( $p['line'][0]['net'] );
		$this->assertSame( 'line 1 has no amount', $this->reason( $p ) );

		$this->assertSame( '', $this->reason( $base ) );
	}

	/**
	 * A date and time are cut to the date; a missing currency is the books' currency.
	 */
	public function test_date_with_time_and_default_currency(): void {
		$payload = FakeExportKit::order();
		unset( $payload['document']['currency'] );
		$payload['document']['date'] = '2026-11-02 23:59:59';
		$document                    = ( new DocumentReader( 'AED' ) )->read( $payload, false );
		$this->assertSame( '2026-11-02', $document->date );
		$this->assertSame( 'AED', $document->currency );
	}

	/**
	 * Lines of no value (a free fee) are dropped.
	 */
	public function test_zero_charges_are_dropped(): void {
		$payload                = FakeExportKit::order();
		$payload['fee']         = array( array( 'name' => 'Free', 'net' => 0, 'tax' => 0 ) );
		$payload['shipping'][0] = array( 'name' => 'Free shipping', 'net' => 0, 'tax' => 0 );
		$document               = ( new DocumentReader( 'AED' ) )->read( $payload, false );
		$this->assertSame( array(), $document->fees );
		$this->assertSame( array(), $document->shipping );
	}

	/**
	 * A payment needs a method and a date.
	 */
	public function test_payment(): void {
		$payload            = FakeExportKit::order();
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-04T10:00:00', 'reference' => 'pi_1' );
		$document           = ( new DocumentReader( 'AED' ) )->read( $payload, false );
		$this->assertSame( 'stripe', $document->payment->method );
		$this->assertSame( '2026-11-04', $document->payment->date );
		$this->assertSame( 'pi_1', $document->payment->reference );

		$payload['payment']['paid_at'] = '';
		$this->assertNull( ( new DocumentReader( 'AED' ) )->read( $payload, false )->payment );
		$payload['payment'] = array( 'method' => '', 'paid_at' => '2026-11-04' );
		$this->assertNull( ( new DocumentReader( 'AED' ) )->read( $payload, false )->payment );
	}

	/**
	 * A refund's lines take the tax code of the same SKU on the original order.
	 */
	public function test_refund_tax_from_the_original_order(): void {
		$payload                   = FakeExportKit::refund();
		$payload['original_line']  = array(
			array( 'sku' => 'SKU-A', 'tax_code' => 'VAT5', 'tax_rate' => 5 ),
			array( 'sku' => 'SKU-B', 'tax_code' => 'VAT0', 'tax_rate' => 0 ),
		);
		$document = ( new DocumentReader( 'AED' ) )->read( $payload, true );
		$this->assertTrue( $document->isCredit() );
		$this->assertSame( '1001-R55', $document->voucherNumber() );
		$this->assertSame( 'Damaged in transit', $document->note );
		$this->assertSame( 'VAT5', $document->lines[0]->taxCode );
		$this->assertSame( '5', $document->lines[0]->taxRate );
		$this->assertSame( 5250, $document->lines[0]->gross() );
		$this->assertSame( 5250, $document->gross() );
	}

	/**
	 * Without a SKU match, the one code shared by every original line is used; with several codes a taxed line is refused.
	 */
	public function test_refund_without_sku_match(): void {
		$payload                         = FakeExportKit::refund();
		$payload['refund_line'][0]['sku'] = '';
		$document                        = ( new DocumentReader( 'AED' ) )->read( $payload, true );
		$this->assertSame( 'Output VAT 5%', $document->lines[0]->taxCode );
		$this->assertSame( 'Refund line 1', $document->lines[0]->name );

		$payload['original_line'] = array(
			array( 'sku' => 'SKU-A', 'tax_code' => 'VAT5', 'tax_rate' => 5 ),
			array( 'sku' => 'SKU-B', 'tax_code' => 'VAT7', 'tax_rate' => 7 ),
		);
		$this->assertSame( 'refund line 1 has tax but its tax code cannot be told from the original order', $this->reason( $payload, true ) );

		$payload['refund_line'][0]['tax'] = 0;
		$this->assertSame( '', $this->reason( $payload, true ), 'A refund line without tax needs no code.' );
	}

	/**
	 * Refund shipping with tax is split by the original shipping rate; a refund needs its number and a line amount.
	 */
	public function test_refund_shipping_and_requirements(): void {
		$payload                       = FakeExportKit::refund( '1001', 55, array( 'refund_shipping' => array( 'gross' => 1050 ), 'refund_other' => array( 'gross' => 300 ) ) );
		$document                      = ( new DocumentReader( 'AED' ) )->read( $payload, true );
		$this->assertSame( 1000, $document->shipping[0]->net );
		$this->assertSame( 50, $document->shipping[0]->tax );
		$this->assertSame( 'Output VAT 5%', $document->shipping[0]->taxCode );
		$this->assertSame( 300, $document->adjustment );
		$this->assertSame( 5250 + 1050 + 300, $document->computedGross() );

		$p = $payload;
		unset( $p['document']['refund_id'] );
		$this->assertSame( 'the refund number is missing', $this->reason( $p, true ) );
		$p = $payload;
		unset( $p['refund_line'][0]['net'] );
		$this->assertSame( 'refund line 1 has no amount', $this->reason( $p, true ) );

		$untaxed = $payload;
		unset( $untaxed['original_shipping'][0]['tax_code'], $untaxed['original_shipping'][0]['tax_rate'] );
		$again = ( new DocumentReader( 'AED' ) )->read( $untaxed, true );
		$this->assertSame( 1050, $again->shipping[0]->net, 'Without a rate the whole refund is net.' );
		$this->assertSame( 0, $again->shipping[0]->tax );
	}
}
