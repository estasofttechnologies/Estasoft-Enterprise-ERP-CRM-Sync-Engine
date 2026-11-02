<?php
/**
 * Round trip: the file the TallyPrime connector writes is read back with an independent parser and every field is compared.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Tally;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallyConnector
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallyVoucherWriter
 */
final class TallyRoundTripTest extends TallyTestCase {

	/**
	 * Every element path of the documented invoice sample appears in the voucher the connector writes.
	 */
	public function test_voucher_has_every_element_of_the_documented_sample(): void {
		$sample = new \DOMDocument();
		$sample->load( dirname( __DIR__, 3 ) . '/fixtures/tally/documented-sales-invoice.xml' );
		$wanted = $this->paths( $sample->documentElement );

		$this->assertTrue( $this->send( $this->connection(), FakeExportKit::order() )->isSuccess() );
		$file  = $this->file();
		$found = $this->paths( $this->dom( $file['bytes'] )->documentElement );

		foreach ( array_keys( $wanted ) as $path ) {
			$this->assertArrayHasKey( $path, $found, 'Missing from the written file: ' . $path );
		}
	}

	/**
	 * A two-line order with shipping: every value in the file equals the value that went in.
	 */
	public function test_sales_voucher_round_trip(): void {
		$this->send( $this->connection(), FakeExportKit::order() );
		$file = $this->file();
		$dom  = $this->dom( $file['bytes'] );
		$xp   = $this->xpath( $dom );

		$this->assertSame( '1', $this->text( $xp, '/ENVELOPE/HEADER/VERSION' ) );
		$this->assertSame( 'Import', $this->text( $xp, '/ENVELOPE/HEADER/TALLYREQUEST' ) );
		$this->assertSame( 'Data', $this->text( $xp, '/ENVELOPE/HEADER/TYPE' ) );
		$this->assertSame( 'Vouchers', $this->text( $xp, '/ENVELOPE/HEADER/ID' ) );
		$this->assertSame( 'Cedar & Stone Trading LLC', $this->text( $xp, '/ENVELOPE/BODY/DESC/STATICVARIABLES/SVCURRENTCOMPANY' ) );

		$voucher = $xp->query( '/ENVELOPE/BODY/DATA/TALLYMESSAGE/VOUCHER' )->item( 0 );
		$this->assertSame( 'Sales', $voucher->getAttribute( 'VCHTYPE' ) );
		$this->assertSame( 'Create', $voucher->getAttribute( 'ACTION' ) );
		$this->assertSame( 'Invoice Voucher View', $voucher->getAttribute( 'OBJVIEW' ) );
		$this->assertSame( '20261102', $this->text( $xp, 'DATE', $voucher ) );
		$this->assertSame( 'Sales', $this->text( $xp, 'VOUCHERTYPENAME', $voucher ) );
		$this->assertSame( '1001', $this->text( $xp, 'VOUCHERNUMBER', $voucher ) );
		$this->assertSame( 'WooCommerce customers', $this->text( $xp, 'PARTYLEDGERNAME', $voucher ) );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISINVOICE', $voucher ) );
		$this->assertSame( 'Order WC-1001', $this->text( $xp, 'NARRATION', $voucher ) );

		// Party: debit of the whole order, with its bill.
		$party = $xp->query( 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]', $voucher )->item( 0 );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $party ) );
		$this->assertSame( '-157.50', $this->text( $xp, 'AMOUNT', $party ) );
		$this->assertSame( '1001', $this->text( $xp, 'BILLALLOCATIONS.LIST/NAME', $party ) );
		$this->assertSame( 'New Ref', $this->text( $xp, 'BILLALLOCATIONS.LIST/BILLTYPE', $party ) );
		$this->assertSame( '-157.50', $this->text( $xp, 'BILLALLOCATIONS.LIST/AMOUNT', $party ) );

		// Shipping and tax.
		$shipping = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Shipping charged"]', $voucher )->item( 0 );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $shipping ) );
		$this->assertSame( '10.00', $this->text( $xp, 'AMOUNT', $shipping ) );
		$tax = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Output VAT 5%"]', $voucher )->item( 0 );
		$this->assertSame( '7.50', $this->text( $xp, 'AMOUNT', $tax ) );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $tax ) );
		$this->assertSame( '5', $this->text( $xp, 'BASICRATEOFINVOICETAX.LIST/BASICRATEOFINVOICETAX', $tax ) );
		$this->assertSame( 'Number', $xp->query( 'BASICRATEOFINVOICETAX.LIST', $tax )->item( 0 )->getAttribute( 'TYPE' ) );

		// Inventory.
		$items = $xp->query( 'ALLINVENTORYENTRIES.LIST', $voucher );
		$this->assertSame( 2, $items->length );
		$first = $items->item( 0 );
		$this->assertSame( 'SKU-A', $this->text( $xp, 'STOCKITEMNAME', $first ) );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $first ) );
		$this->assertSame( '50.00/nos', $this->text( $xp, 'RATE', $first ) );
		$this->assertSame( '100.00', $this->text( $xp, 'AMOUNT', $first ) );
		$this->assertSame( '2 nos', $this->text( $xp, 'ACTUALQTY', $first ) );
		$this->assertSame( '2 nos', $this->text( $xp, 'BILLEDQTY', $first ) );
		$this->assertSame( 'Main Location', $this->text( $xp, 'BATCHALLOCATIONS.LIST/GODOWNNAME', $first ) );
		$this->assertSame( 'Primary Batch', $this->text( $xp, 'BATCHALLOCATIONS.LIST/BATCHNAME', $first ) );
		$this->assertSame( '100.00', $this->text( $xp, 'BATCHALLOCATIONS.LIST/AMOUNT', $first ) );
		$this->assertSame( 'Sales', $this->text( $xp, 'ACCOUNTINGALLOCATIONS.LIST/LEDGERNAME', $first ) );
		$this->assertSame( '100.00', $this->text( $xp, 'ACCOUNTINGALLOCATIONS.LIST/AMOUNT', $first ) );
		$second = $items->item( 1 );
		$this->assertSame( 'SKU-B', $this->text( $xp, 'STOCKITEMNAME', $second ) );
		$this->assertSame( '40.00/nos', $this->text( $xp, 'RATE', $second ) );
		$this->assertSame( '1 nos', $this->text( $xp, 'ACTUALQTY', $second ) );

		// Debits equal credits, to the last unit.
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->inventorySum( $xp, $voucher ) );
		$this->assertSame( array(), $file['left_out'] );
	}

	/**
	 * A refund is a credit note with every sign reversed, and it balances too.
	 */
	public function test_refund_is_a_credit_note_with_reversed_signs(): void {
		$payload = FakeExportKit::refund();
		$payload['refund_shipping'] = array( 'gross' => 1050 );
		$payload['document']['total'] = 6300;
		$result  = $this->send( $this->connection(), $payload, EntityType::Refund );
		$this->assertTrue( $result->isSuccess() );

		$dom     = $this->dom( $this->file()['bytes'] );
		$xp      = $this->xpath( $dom );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( 'Credit Note', $voucher->getAttribute( 'VCHTYPE' ) );
		$this->assertSame( '20261103', $this->text( $xp, 'DATE', $voucher ) );
		$this->assertSame( '1001-R55', $this->text( $xp, 'VOUCHERNUMBER', $voucher ) );
		$this->assertSame( 'Refund R55 of order WC-1001: Damaged in transit', $this->text( $xp, 'NARRATION', $voucher ) );

		$party = $xp->query( 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]', $voucher )->item( 0 );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $party ) );
		$this->assertSame( '63.00', $this->text( $xp, 'AMOUNT', $party ) );
		$shipping = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Shipping charged"]', $voucher )->item( 0 );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $shipping ) );
		$this->assertSame( '-10.00', $this->text( $xp, 'AMOUNT', $shipping ) );
		$tax = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Output VAT 5%"]', $voucher )->item( 0 );
		$this->assertSame( '-3.00', $this->text( $xp, 'AMOUNT', $tax ) ); // 2.50 on the line plus 0.50 inside the shipping.
		$item = $xp->query( 'ALLINVENTORYENTRIES.LIST', $voucher )->item( 0 );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $item ) );
		$this->assertSame( '-50.00', $this->text( $xp, 'AMOUNT', $item ) );
		$this->assertSame( '50.00/nos', $this->text( $xp, 'RATE', $item ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->inventorySum( $xp, $voucher ) );
	}

	/**
	 * Arabic, German and Nordic text survives in both encodings.
	 *
	 * @dataProvider encodings
	 *
	 * @param string $encoding Setting value.
	 * @param string $prefix   First bytes of the file.
	 */
	public function test_arabic_german_and_nordic_text_is_preserved( string $encoding, string $prefix ): void {
		$payload                         = FakeExportKit::order( '2002' );
		$payload['customer']['name']     = 'شركة الأرز والحجر';
		$payload['line'][0]['sku']       = 'Größe-Ø-åäö-ß';
		$payload['line'][1]['sku']       = 'مقعد خشبي';
		$payload['document']['note']     = 'Grüße aus Århus — شكرا';
		$connection                      = $this->connection(
			array(
				'encoding'              => $encoding,
				'customer_ledger_mode'  => 'per_customer',
				'company'               => 'Gebrüder Müller & Søn',
			)
		);
		$this->assertTrue( $this->send( $connection, $payload )->isSuccess() );
		$file = $this->file();
		$this->assertSame( $prefix, substr( $file['bytes'], 0, strlen( $prefix ) ) );
		$this->assertStringContainsString( 'charset=' . ( 'utf-16' === $encoding ? 'utf-16' : 'utf-8' ), $file['mime'] );

		$xp      = $this->xpath( $this->dom( $file['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( 'Gebrüder Müller & Søn', $this->text( $xp, '//SVCURRENTCOMPANY' ) );
		$this->assertSame( 'شركة الأرز والحجر', $this->text( $xp, 'PARTYLEDGERNAME', $voucher ) );
		$this->assertSame( 'Größe-Ø-åäö-ß', $this->text( $xp, 'ALLINVENTORYENTRIES.LIST[1]/STOCKITEMNAME', $voucher ) );
		$this->assertSame( 'مقعد خشبي', $this->text( $xp, 'ALLINVENTORYENTRIES.LIST[2]/STOCKITEMNAME', $voucher ) );
		$this->assertSame( 'Order WC-2002: Grüße aus Århus — شكرا', $this->text( $xp, 'NARRATION', $voucher ) );
	}

	/**
	 * The two encodings.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function encodings(): array {
		return array(
			'utf-16 with a byte order mark' => array( 'utf-16', "\xFF\xFE" ),
			'utf-8 without one'             => array( 'utf-8', '<?xml version="1.0" encoding="UTF-8"?>' ),
		);
	}

	/**
	 * A three-decimal currency keeps three decimals and balances.
	 */
	public function test_kuwaiti_dinar_keeps_three_decimals(): void {
		$payload                       = FakeExportKit::order( '3003' );
		$payload['document']['currency'] = 'KWD';
		$payload['document']['total']  = 15750;
		$connection                    = $this->connection( array( 'currency' => 'KWD' ) );
		$this->assertTrue( $this->send( $connection, $payload )->isSuccess() );

		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( '-15.750', $this->text( $xp, 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT', $voucher ) );
		$this->assertSame( '5.000/nos', $this->text( $xp, 'ALLINVENTORYENTRIES.LIST[1]/RATE', $voucher ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->inventorySum( $xp, $voucher ) );
	}

	/**
	 * Sum of the inventory amounts of a voucher. They post to the sales ledger inside the entry, so together with the ledger entries
	 * they make the whole voucher.
	 *
	 * @param \DOMXPath $xp      XPath.
	 * @param \DOMNode  $voucher Voucher.
	 */
	private function inventorySum( \DOMXPath $xp, \DOMNode $voucher ): int {
		$sum = 0;
		foreach ( $xp->query( 'ALLINVENTORYENTRIES.LIST/ACCOUNTINGALLOCATIONS.LIST/AMOUNT', $voucher ) as $node ) {
			$sum += $this->minor( $node->textContent );
		}
		return $sum;
	}

	/**
	 * The element paths of a document (without positions).
	 *
	 * @param \DOMNode $node Root.
	 * @return array<string,true>
	 */
	private function paths( \DOMNode $node, string $prefix = '' ): array {
		$paths = array();
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$path           = $prefix . '/' . $child->nodeName;
			$paths[ $path ] = true;
			$paths         += $this->paths( $child, $path );
		}
		return $paths;
	}
}
