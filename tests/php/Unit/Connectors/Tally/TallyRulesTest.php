<?php
/**
 * The rules of the TallyPrime file: round-off, discounts, tax modes, ledgers, receipts, ids and the reasons an order is left out.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Tally;

use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallyVoucherWriter
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallySettings
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 */
final class TallyRulesTest extends TallyTestCase {

	/**
	 * The reason an order was left out, or an empty string.
	 */
	private function reason(): string {
		$left = $this->deps->exports->openBatch( 7 )->leftOut();
		return $left[0]['reason'] ?? '';
	}

	/**
	 * One minor unit between the order total and the lines goes to the round-off ledger, and the voucher still balances.
	 */
	public function test_small_difference_goes_to_the_round_off_ledger(): void {
		$payload                      = FakeExportKit::order();
		$payload['document']['total'] = 15751;
		$this->send( $this->connection(), $payload );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$entry   = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Round Off"]', $voucher )->item( 0 );
		$this->assertNotNull( $entry );
		$this->assertSame( '0.01', $this->text( $xp, 'AMOUNT', $entry ) );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $entry ) );
		$this->assertSame( '-157.51', $this->text( $xp, 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT', $voucher ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->stockSum( $xp, $voucher ) );
	}

	/**
	 * A negative difference is a debit round-off.
	 */
	public function test_negative_difference_is_a_debit(): void {
		$payload                      = FakeExportKit::order();
		$payload['document']['total'] = 15749;
		$this->send( $this->connection(), $payload );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$entry   = $xp->query( '//LEDGERENTRIES.LIST[LEDGERNAME="Round Off"]' )->item( 0 );
		$this->assertSame( '-0.01', $this->text( $xp, 'AMOUNT', $entry ) );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $entry ) );
	}

	/**
	 * More than the allowed difference leaves the order out and names both totals.
	 */
	public function test_large_difference_leaves_the_order_out(): void {
		$payload                      = FakeExportKit::order();
		$payload['document']['total'] = 16750;
		$result                       = $this->send( $this->connection(), $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'the lines add up to 157.50 but the order total is 167.50', $this->reason() );
	}

	/**
	 * The largest round-off is a setting.
	 */
	public function test_round_off_limit_is_a_setting(): void {
		$payload                      = FakeExportKit::order();
		$payload['document']['total'] = 15755;
		$this->send( $this->connection( array( 'roundoff_max_minor' => 4 ) ), $payload );
		$this->assertStringContainsString( 'the lines add up to', $this->reason() );
	}

	/**
	 * An order in another currency is left out.
	 */
	public function test_other_currency_is_left_out(): void {
		$payload                         = FakeExportKit::order();
		$payload['document']['currency'] = 'SAR';
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'its currency (SAR) is not the currency of the books (AED)', $this->reason() );
	}

	/**
	 * A line with tax and no tax code is named by its number.
	 */
	public function test_line_with_tax_and_no_code_is_left_out(): void {
		$payload = FakeExportKit::order();
		unset( $payload['line'][1]['tax_code'] );
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'line 2 has tax but no tax code', $this->reason() );
	}

	/**
	 * A line without tax needs no code at all.
	 */
	public function test_untaxed_line_needs_no_tax_code(): void {
		$payload                      = FakeExportKit::order();
		$payload['line']              = array( array_merge( $payload['line'][0], array( 'tax' => 0, 'tax_code' => '', 'tax_rate' => '' ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 10000;
		$this->send( $this->connection(), $payload );
		$xp = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertSame( 0, $xp->query( '//LEDGERENTRIES.LIST[BASICRATEOFINVOICETAX.LIST]' )->length );
		$this->assertSame( array(), $this->deps->exports->openBatch( 7 )->leftOut() );
	}

	/**
	 * A product without a SKU is refused by default and posted to the sales ledger when the setting says so.
	 */
	public function test_missing_sku(): void {
		$payload                  = FakeExportKit::order();
		$payload['line'][1]['sku'] = '';
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'line 2 has no SKU', $this->reason() );

		$other = $this->connection( array( 'missing_sku' => 'ledger_only' ) );
		$again = FakeExportKit::order( '1002' );
		$again['line'][1]['sku'] = '';
		$this->send( $other, $again );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER[VOUCHERNUMBER="1002"]' )->item( 0 );
		$this->assertSame( 1, $xp->query( 'ALLINVENTORYENTRIES.LIST', $voucher )->length );
		$this->assertSame( '40.00', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Sales"]/AMOUNT', $voucher ) );
	}

	/**
	 * The stock item can be named after the product instead of the SKU.
	 */
	public function test_stock_item_by_product_name(): void {
		$this->send( $this->connection( array( 'stock_item_source' => 'name' ) ), FakeExportKit::order() );
		$xp = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertSame( 'Cedar shelf', $this->text( $xp, '//ALLINVENTORYENTRIES.LIST[1]/STOCKITEMNAME' ) );
	}

	/**
	 * Without stock items every product is a sales ledger entry, in the accounting view.
	 */
	public function test_without_inventory(): void {
		$this->send( $this->connection( array( 'use_inventory' => 'no' ) ), FakeExportKit::order() );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( 0, $xp->query( 'ALLINVENTORYENTRIES.LIST', $voucher )->length );
		$this->assertSame( 'Accounting Voucher View', $voucher->getAttribute( 'OBJVIEW' ) );
		$this->assertSame( '140.00', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Sales"]/AMOUNT', $voucher ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) );
	}

	/**
	 * With "no tax in the file" the prices carry their tax and there is no tax ledger.
	 */
	public function test_no_tax_mode_writes_gross_prices(): void {
		$this->send( $this->connection( array( 'tax_mode' => 'none' ) ), FakeExportKit::order() );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( 0, $xp->query( 'LEDGERENTRIES.LIST[BASICRATEOFINVOICETAX.LIST]', $voucher )->length );
		$this->assertSame( '105.00', $this->text( $xp, 'ALLINVENTORYENTRIES.LIST[1]/AMOUNT', $voucher ) );
		$this->assertSame( '10.50', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Shipping charged"]/AMOUNT', $voucher ) );
		$this->assertSame( '-157.50', $this->text( $xp, 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT', $voucher ) );
	}

	/**
	 * With a discount ledger products are written at the price before discount and the discount is a debit.
	 */
	public function test_discount_ledger(): void {
		$payload                       = FakeExportKit::order();
		$payload['line'][0]['net']     = 9000; // 10.00 discount on 2 x 50.00.
		$payload['line'][0]['tax']     = 450;
		$payload['document']['total']  = 14700;
		$this->send( $this->connection( array( 'discount_ledger' => 'Discount allowed' ) ), $payload );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( '100.00', $this->text( $xp, 'ALLINVENTORYENTRIES.LIST[1]/AMOUNT', $voucher ) );
		$discount = $xp->query( 'LEDGERENTRIES.LIST[LEDGERNAME="Discount allowed"]', $voucher )->item( 0 );
		$this->assertSame( '-10.00', $this->text( $xp, 'AMOUNT', $discount ) );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $discount ) );
		$this->assertSame( '-147.00', $this->text( $xp, 'LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT', $voucher ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->stockSum( $xp, $voucher ) );
	}

	/**
	 * A fee with tax needs the tax ledger of the setting; without tax it does not.
	 */
	public function test_fees(): void {
		$payload                      = FakeExportKit::order();
		$payload['fee']               = array( array( 'name' => 'Gift wrap', 'net' => 500, 'tax' => 25 ) );
		$payload['document']['total'] = 16275;
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'fee "Gift wrap" has tax but no tax ledger', $this->reason() );

		$this->send( $this->connection( array( 'fee_tax_code' => 'Output VAT 5%' ) ), FakeExportKit::order( '1002', array( 'fee' => $payload['fee'], 'document' => array_merge( $payload['document'], array( 'number' => '1002', 'reference' => 'WC-1002' ) ) ) ) );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER[VOUCHERNUMBER="1002"]' )->item( 0 );
		$this->assertSame( '5.00', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Sales"]/AMOUNT', $voucher ) );
		$this->assertSame( '7.75', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Output VAT 5%"]/AMOUNT', $voucher ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $voucher ) + $this->stockSum( $xp, $voucher ) );
	}

	/**
	 * A refund amount that belongs to no product needs an adjustment ledger.
	 */
	public function test_refund_other_amount(): void {
		$payload                      = FakeExportKit::refund( '1001', 56, array( 'refund_other' => array( 'gross' => 500 ) ) );
		$payload['document']['total'] = 5750;
		$this->send( $this->connection(), $payload, EntityType::Refund );
		$this->assertSame( 'refund amount 5.00 has no adjustment ledger', $this->reason() );

		$this->send( $this->connection( array( 'adjustment_ledger' => 'Other adjustments' ) ), FakeExportKit::refund( '1002', 57, array( 'refund_other' => array( 'gross' => 500 ), 'document' => array_merge( $payload['document'], array( 'number' => '1002', 'reference' => 'WC-1002', 'refund_id' => 57 ) ) ) ), EntityType::Refund );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER[VOUCHERNUMBER="1002-R57"]' )->item( 0 );
		$this->assertSame( '-5.00', $this->text( $xp, 'LEDGERENTRIES.LIST[LEDGERNAME="Other adjustments"]/AMOUNT', $voucher ) );
	}

	/**
	 * A paid order gets a receipt when payments are recorded and the method has a ledger.
	 */
	public function test_receipt_voucher(): void {
		$payload            = FakeExportKit::order();
		$payload['payment'] = array( 'method' => 'stripe', 'title' => 'Card', 'paid_at' => '2026-11-02', 'reference' => 'pi_123' );
		$connection         = $this->connection( array( 'record_payments' => 'yes', 'payment_ledgers' => array( 'stripe' => 'Stripe account' ) ) );
		$this->send( $connection, $payload );
		$xp       = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$vouchers = $xp->query( '//VOUCHER' );
		$this->assertSame( 2, $vouchers->length );
		$receipt = $vouchers->item( 1 );
		$this->assertSame( 'Receipt', $receipt->getAttribute( 'VCHTYPE' ) );
		$this->assertSame( '20261102', $this->text( $xp, 'DATE', $receipt ) );
		$this->assertSame( 'Payment for order WC-1001 (pi_123)', $this->text( $xp, 'NARRATION', $receipt ) );
		$party = $xp->query( 'ALLLEDGERENTRIES.LIST', $receipt )->item( 0 );
		$this->assertSame( 'WooCommerce customers', $this->text( $xp, 'LEDGERNAME', $party ) );
		$this->assertSame( '157.50', $this->text( $xp, 'AMOUNT', $party ) );
		$this->assertSame( 'No', $this->text( $xp, 'ISDEEMEDPOSITIVE', $party ) );
		$this->assertSame( 'Agst Ref', $this->text( $xp, 'BILLALLOCATIONS.LIST/BILLTYPE', $party ) );
		$bank = $xp->query( 'ALLLEDGERENTRIES.LIST', $receipt )->item( 1 );
		$this->assertSame( 'Stripe account', $this->text( $xp, 'LEDGERNAME', $bank ) );
		$this->assertSame( '-157.50', $this->text( $xp, 'AMOUNT', $bank ) );
		$this->assertSame( 'Yes', $this->text( $xp, 'ISDEEMEDPOSITIVE', $bank ) );
		$this->assertSame( 0, $this->ledgerSum( $xp, $receipt ) );
	}

	/**
	 * A paid order whose method has no ledger is left out, and the method is named.
	 */
	public function test_payment_method_without_ledger_is_left_out(): void {
		$payload            = FakeExportKit::order();
		$payload['payment'] = array( 'method' => 'tabby', 'title' => 'Tabby', 'paid_at' => '2026-11-02', 'reference' => '' );
		$this->send( $this->connection( array( 'record_payments' => 'yes' ) ), $payload );
		$this->assertSame( 'payment method Tabby has no ledger', $this->reason() );
	}

	/**
	 * Payments are not recorded unless the setting is on, and refunds never get a receipt.
	 */
	public function test_payments_are_off_by_default(): void {
		$payload            = FakeExportKit::order();
		$payload['payment'] = array( 'method' => 'tabby', 'title' => 'Tabby', 'paid_at' => '2026-11-02', 'reference' => '' );
		$this->send( $this->connection(), $payload );
		$xp = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertSame( 1, $xp->query( '//VOUCHER' )->length );
		$this->assertSame( array(), $this->deps->exports->openBatch( 7 )->leftOut() );
	}

	/**
	 * The voucher id is the same every time for the same order, differs between orders, and can be switched off.
	 */
	public function test_stable_voucher_id(): void {
		$this->send( $this->connection(), FakeExportKit::order( '1001' ) );
		$this->send( $this->connection(), FakeExportKit::order( '1002' ) );
		$first = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$a     = $first->query( '//VOUCHER' )->item( 0 );
		$b     = $first->query( '//VOUCHER' )->item( 1 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $a->getAttribute( 'REMOTEID' ) );
		$this->assertSame( $a->getAttribute( 'REMOTEID' ), $this->text( $first, 'GUID', $a ) );
		$this->assertNotSame( $a->getAttribute( 'REMOTEID' ), $b->getAttribute( 'REMOTEID' ) );

		// A second file for the same order (another run) carries the same id.
		$this->deps->exports->closeBatch( 7 );
		$this->send( $this->connection(), FakeExportKit::order( '1001' ) );
		$again = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertSame( $a->getAttribute( 'REMOTEID' ), $again->query( '//VOUCHER' )->item( 0 )->getAttribute( 'REMOTEID' ) );

		$this->deps->exports->closeBatch( 7 );
		$this->send( $this->connection( array( 'stable_id' => 'no' ) ), FakeExportKit::order( '1001' ) );
		$off = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertFalse( $off->query( '//VOUCHER' )->item( 0 )->hasAttribute( 'REMOTEID' ) );
		$this->assertSame( 0, $off->query( '//GUID' )->length );
	}

	/**
	 * One ledger for each customer uses the customer's account when the mapping gives one, otherwise the name.
	 */
	public function test_ledger_for_each_customer(): void {
		$connection = $this->connection( array( 'customer_ledger_mode' => 'per_customer' ) );
		$this->send( $connection, FakeExportKit::order( '1001' ) );
		$payload                        = FakeExportKit::order( '1002' );
		$payload['customer']['account'] = 'LAYLA-01';
		$this->send( $connection, $payload );
		$xp = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$this->assertSame( 'Layla Haddad', $this->text( $xp, '//VOUCHER[VOUCHERNUMBER="1001"]/PARTYLEDGERNAME' ) );
		$this->assertSame( 'LAYLA-01', $this->text( $xp, '//VOUCHER[VOUCHERNUMBER="1002"]/PARTYLEDGERNAME' ) );
	}

	/**
	 * Characters XML forbids are removed and specials are escaped, so the file always parses and the text comes back unchanged.
	 */
	public function test_xml_specials_and_control_characters(): void {
		$payload                     = FakeExportKit::order();
		$payload['customer']['name'] = "Tom \"T\" O'Neil & <Sons>\x01\x08";
		$payload['document']['note'] = "line one\nline two\ttab";
		$this->send( $this->connection( array( 'customer_ledger_mode' => 'per_customer' ) ), $payload );
		$xp      = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$voucher = $xp->query( '//VOUCHER' )->item( 0 );
		$this->assertSame( "Tom \"T\" O'Neil & <Sons>", $this->text( $xp, 'PARTYLEDGERNAME', $voucher ) );
		$this->assertSame( 'Order WC-1001: line one line two tab', $this->text( $xp, 'NARRATION', $voucher ) );
	}

	/**
	 * A long note is shortened with an ellipsis and the cut is counted.
	 */
	public function test_long_narration_is_cut_with_an_ellipsis(): void {
		$payload                     = FakeExportKit::order();
		$payload['document']['note'] = str_repeat( 'ب', 400 );
		$this->send( $this->connection(), $payload );
		$xp   = $this->xpath( $this->dom( $this->file()['bytes'] ) );
		$text = $this->text( $xp, '//NARRATION' );
		$this->assertSame( 250, mb_strlen( $text ) );
		$this->assertStringEndsWith( '...', $text );
		$this->assertSame( 1, $this->deps->exports->openBatch( 7 )->rows()[0]['row']['cut'] );
	}

	/**
	 * A customer without a name is left out.
	 */
	public function test_empty_customer_name_is_left_out(): void {
		$payload                     = FakeExportKit::order();
		$payload['customer']['name'] = " \t ";
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'the customer name is empty', $this->reason() );
	}

	/**
	 * A zero-value order has nothing to post.
	 */
	public function test_zero_total_is_left_out(): void {
		$payload                      = FakeExportKit::order();
		$payload['line']              = array( array_merge( $payload['line'][0], array( 'net' => 0, 'tax' => 0, 'unit_price' => 0, 'tax_code' => '' ) ) );
		$payload['shipping']          = array();
		$payload['document']['total'] = 0;
		$this->send( $this->connection(), $payload );
		$this->assertSame( 'the total is zero, so there is nothing to post', $this->reason() );
	}

	/**
	 * Settings that are not complete stop the export instead of leaving every order out one by one.
	 */
	public function test_incomplete_settings_stop_the_export(): void {
		$this->expectException( ExportException::class );
		$this->send( FakeExportKit::connection( 'tallyprime-export', array( 'currency' => 'AED' ) ), FakeExportKit::order() );
	}

	/**
	 * Test connection reports the incomplete settings and passes when they are complete.
	 */
	public function test_connection_test_checks_the_settings(): void {
		$bad = $this->connector->test( FakeExportKit::connection( 'tallyprime-export', array() ) );
		$this->assertFalse( $bad->ok );
		$this->assertNotNull( $bad->error );
		$this->assertStringContainsString( 'company name', (string) $bad->error->context['reason'] );

		$good = $this->connector->test( $this->connection() );
		$this->assertTrue( $good->ok );
		$this->assertSame( 'TallyPrime XML import', $good->version );
	}

	/**
	 * Sending the same queue item again does not add a second voucher.
	 */
	public function test_same_item_twice_is_one_voucher(): void {
		$connection = $this->connection();
		$item       = FakeExportKit::item( 9, '1001' );
		$first      = $this->connector->send( $connection, $item, FakeExportKit::order() );
		$second     = $this->connector->send( $connection, $item, FakeExportKit::order() );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
		$this->assertNull( $this->connector->findExisting( $connection, FakeExportKit::item( 10, '1002' ) ) );
		$this->assertSame( 1, $this->xpath( $this->dom( $this->file()['bytes'] ) )->query( '//VOUCHER' )->length );
	}

	/**
	 * The masters list names every ledger, stock item and unit the file uses, once, and no amounts.
	 */
	public function test_masters_list(): void {
		$this->send( $this->connection(), FakeExportKit::order( '1001' ) );
		$this->send( $this->connection(), FakeExportKit::order( '1002' ) );
		$list = $this->connector->mastersList( $this->deps->exports->openBatch( 7 ) );
		$this->assertStringContainsString( "Ledgers (4)\n  Output VAT 5%\n  Sales\n  Shipping charged\n  WooCommerce customers\n", $list );
		$this->assertStringContainsString( "Stock items (2)\n  SKU-A\n  SKU-B\n", $list );
		$this->assertStringContainsString( "Units (1)\n  nos\n", $list );
		$this->assertStringContainsString( "Voucher types (1)\n  Sales\n", $list );
		$this->assertDoesNotMatchRegularExpression( '/\d+\.\d\d/', $list );
	}

	/**
	 * The preview shows what the file is and the start of the first voucher.
	 */
	public function test_preview_text(): void {
		$this->send( $this->connection(), FakeExportKit::order( '1001' ) );
		$this->send( $this->connection(), FakeExportKit::order( '1002' ) );
		$text = $this->connector->previewText( $this->deps->exports->openBatch( 7 ) );
		$this->assertStringStartsWith( 'TallyPrime XML: 2 orders for the company "Cedar & Stone Trading LLC", written in UTF-16.', $text );
		$this->assertStringContainsString( '<VOUCHERNUMBER>1001</VOUCHERNUMBER>', $text );
		$this->assertStringNotContainsString( '1002', $text );
		$this->assertSame( '', $this->connector->previewText( $this->deps->exports->openBatch( 99 ) ) );
	}

	/**
	 * The sum of the accounting allocations of the inventory entries.
	 *
	 * @param \DOMXPath $xp      XPath.
	 * @param \DOMNode  $voucher Voucher.
	 */
	private function stockSum( \DOMXPath $xp, \DOMNode $voucher ): int {
		$sum = 0;
		foreach ( $xp->query( 'ALLINVENTORYENTRIES.LIST/ACCOUNTINGALLOCATIONS.LIST/AMOUNT', $voucher ) as $node ) {
			$sum += $this->minor( $node->textContent );
		}
		return $sum;
	}
}
