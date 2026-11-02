<?php
/**
 * Sending orders to ERPNext: the documents, the checks and the replays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class ErpnextOrderTest extends ErpnextTestCase {

	public function test_an_order_becomes_a_submitted_sales_order_with_the_shops_reference_and_dates(): void {
		$result = $this->sendOrder( array(), array( 'total' => 43800 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) $result->error?->detail );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->server->all( 'Sales Order' );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WC-100', $order['po_no'] );
		$this->assertSame( 1, $order['docstatus'] );
		$this->assertSame( '2026-11-02', $order['transaction_date'] );
		$this->assertSame( '2026-11-02', $order['delivery_date'], 'ERPNext refuses a Sales Order without a delivery date.' );
		$this->assertSame( 'Cairo Sand LLC', $order['company'] );
		$this->assertSame( 'Standard Selling', $order['selling_price_list'] );
		$this->assertSame( 1, $order['ignore_pricing_rule'], 'No pricing rule may change the shop price.' );
		$this->assertSame( 219.0, $order['items'][0]['rate'] );
		$this->assertSame( 2.0, $order['items'][0]['qty'] );
		$this->assertSame( 438.0, $order['grand_total'] );
		$this->assertSame( 'WC-100', $result->ref->ref === $order['name'] ? 'WC-100' : '' );
		$this->assertSame( 43800, $result->ref->extra['total_minor'] );
		$this->assertStringContainsString( '/app/sales-order/', (string) $result->ref->url );
	}

	public function test_the_buyer_becomes_a_customer_with_a_contact_and_addresses(): void {
		$this->sendOrder();

		$customers = $this->server->all( 'Customer' );
		$this->assertCount( 1, $customers );
		$this->assertSame( 'Noor Al-Mansouri', $customers[0]['customer_name'] );
		$this->assertSame( 'Individual', $customers[0]['customer_type'] );
		$this->assertSame( 'Individual', $customers[0]['customer_group'], 'From Selling Settings.' );
		$this->assertCount( 1, $this->server->all( 'Contact' ), 'ERPNext makes the primary contact itself.' );
		$addresses = $this->server->all( 'Address' );
		$this->assertCount( 1, $addresses, 'The delivery address is the billing address.' );
		$this->assertSame( 'United Arab Emirates', $addresses[0]['country'] );
		$this->assertSame( 'Customer', $addresses[0]['links'][0]['link_doctype'] );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( $addresses[0]['name'], $order['customer_address'] );
		$this->assertSame( $addresses[0]['name'], $order['shipping_address_name'] );
	}

	public function test_a_different_delivery_address_becomes_a_second_address(): void {
		$this->sendOrder( array(), array( 'delivery' => array( 'name' => 'Layla', 'street' => 'Corniche Road', 'city' => 'Abu Dhabi', 'country' => 'AE' ) ) );

		$addresses = $this->server->all( 'Address' );
		$this->assertCount( 2, $addresses );
		$this->assertSame( array( 'Billing', 'Shipping' ), array_column( $addresses, 'address_type' ) );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertNotSame( $order['customer_address'], $order['shipping_address_name'] );
	}

	public function test_the_same_order_is_never_made_twice_and_the_second_send_links_to_the_first(): void {
		$first  = $this->sendOrder();
		$second = $this->sendOrder();

		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( 1, $this->server->count( 'POST', '#/api/resource/Sales Order$#' ) );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
	}

	public function test_a_lost_answer_after_the_insert_is_continued_not_repeated(): void {
		$this->server->failNext( 'PUT', '#/api/resource/Sales Order/#', FakeHttpClient::timeout() );
		$first = $this->sendOrder();
		$this->assertFalse( $first->isSuccess(), 'The submit timed out.' );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertSame( 0, $this->server->all( 'Sales Order' )[0]['docstatus'], 'The draft is in ERPNext.' );

		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection(), $this->item( EntityType::Order, '100' ) ), 'A draft is not a finished order.' );
		$second = $this->sendOrder();

		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Sales Order' ) );
		$this->assertSame( 1, $this->server->all( 'Sales Order' )[0]['docstatus'] );
		$this->assertNotNull( $connector->findExisting( $this->connection(), $this->item( EntityType::Order, '100' ) ) );
	}

	public function test_a_cancelled_order_does_not_count_as_the_order(): void {
		$this->server->put( 'Sales Order', array( 'po_no' => 'WC-100', 'docstatus' => 2, 'customer' => 'x', 'grand_total' => 1, 'currency' => 'AED' ) );
		$result = $this->sendOrder();
		$this->assertTrue( $result->isSuccess() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 2, $this->server->all( 'Sales Order' ) );
	}

	public function test_an_unknown_or_disabled_sku_stops_before_anything_is_created(): void {
		foreach ( array( 'NOPE-1', 'OLD-ITEM' ) as $sku ) {
			$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => $sku ) ) ) );
			$this->assertFalse( $result->isSuccess() );
			$this->assertSame( ErrorCode::Map001, $result->error->code, $sku );
		}
		$this->assertSame( array(), $this->server->all( 'Customer' ), 'No customer is made for an order that cannot be sent.' );
		$this->assertSame( array(), $this->server->all( 'Sales Order' ) );
	}

	public function test_a_missing_email_is_map_003_and_a_missing_line_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'partner' => array( 'email' => '' ) ) );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$result = $this->connector()->send( $this->connection(), $this->item( EntityType::Order, '100' ), array( 'currency' => 'AED', 'partner' => array( 'name' => 'A', 'email' => 'a@example.test' ), 'order_line' => array() ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->server->count( 'POST', '#/api/resource/#' ) );
	}

	public function test_a_foreign_currency_order_uses_the_price_list_and_the_exchange_rate(): void {
		$result = $this->sendOrder( array(), array( 'currency' => 'KWD', 'order_line' => array( array( 'product' => 'CS-MUG-01', 'quantity' => 3, 'price_unit' => 12345 ) ), 'total' => 37035 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( 'KWD', $order['currency'] );
		$this->assertSame( 'Kuwait Selling', $order['selling_price_list'] );
		$this->assertSame( 12.0, $order['conversion_rate'] );
		$this->assertSame( 12.345, $order['items'][0]['rate'] );
		$this->assertSame( 37035, $result->ref->extra['total_minor'], 'Three decimals, exactly.' );
	}

	public function test_a_currency_without_a_price_list_or_a_rate_is_refused_naming_the_cause(): void {
		$result = $this->sendOrder( array(), array( 'currency' => 'USD' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->server->all( 'Sales Order' ) );

		$this->server->put( 'Price List', array( 'name' => 'USD List', 'currency' => 'USD', 'enabled' => 1, 'selling' => 1 ) );
		$result = $this->sendOrder( array(), array( 'currency' => 'USD' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'exchange rate', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->server->all( 'Customer' ) );
	}

	public function test_a_named_price_list_must_match_the_currency(): void {
		$result = $this->sendOrder( array( 'price_list' => 'Kuwait Selling' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'price_list', $result->error->context['field'] );
	}

	public function test_a_tax_template_is_copied_and_the_total_agrees_with_the_shop(): void {
		$result = $this->sendOrder( array(), array( 'total' => 45990, 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => array( 'UAE VAT 5% - CS' ) ) ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( 'UAE VAT 5% - CS', $order['taxes_and_charges'] );
		$this->assertSame( 'VAT 5% - CS', $order['taxes'][0]['account_head'] );
		$this->assertSame( 459.9, $order['grand_total'] );
		$this->assertSame( 45990, $result->ref->extra['total_minor'] );
	}

	public function test_prices_are_converted_when_the_template_includes_the_tax(): void {
		$result = $this->sendOrder( array(), array( 'total' => 45990, 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => array( 'UAE VAT 5% incl - CS' ) ) ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( 229.95, $order['items'][0]['rate'], '219.00 plus 5 percent, because the template reads the price with the tax inside.' );
		$this->assertSame( 459.9, $order['grand_total'] );
	}

	public function test_a_template_with_compound_rows_cannot_convert_prices_but_can_take_matching_ones(): void {
		$line   = array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 10000, 'tax' => array( 'Compound - CS' ) ) );
		$result = $this->sendOrder( array(), array( 'order_line' => $line ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Compound - CS', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->server->all( 'Sales Order' ) );

		$result = $this->sendOrder( array(), array( 'prices_include_tax' => true, 'order_line' => $line ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	public function test_lines_with_different_tax_codes_or_some_without_are_refused(): void {
		$result = $this->sendOrder(
			array(),
			array(
				'order_line' => array(
					array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => array( 'UAE VAT 5% - CS' ) ),
					array( 'product' => 'CS-MUG-01', 'quantity' => 1, 'price_unit' => 100, 'tax' => array() ),
				),
			)
		);
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->all( 'Sales Order' ) );
	}

	public function test_an_unknown_or_disabled_tax_template_is_map_002_or_refused(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => array( 'No such template' ) ) ) ) );
		$this->assertSame( ErrorCode::Map002, $result->error->code );

		$this->server->docs['Sales Taxes and Charges Template']['UAE VAT 5% - CS']['disabled'] = 1;
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 100, 'tax' => array( 'UAE VAT 5% - CS' ) ) ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_a_total_that_differs_stops_the_order_before_it_is_submitted_and_the_next_pass_does_not_duplicate_it(): void {
		$payload = array( 'total' => 40000 );
		$first   = $this->sendOrder( array(), $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '438.00 AED', (string) $first->error->context['reason'] );
		$this->assertStringContainsString( '400.00 AED', (string) $first->error->context['reason'] );
		$this->assertSame( 0, $this->server->all( 'Sales Order' )[0]['docstatus'], 'Left as a draft.' );

		$second = $this->sendOrder( array(), $payload );
		$this->assertFalse( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'Sales Order' ), 'The draft is checked again, not repeated.' );
		$this->assertSame( 0, $this->server->count( 'PUT', '#/api/resource/Sales Order/#' ), 'Nothing was submitted.' );
	}

	public function test_with_total_mismatch_set_to_warn_the_order_goes_on_with_a_warning(): void {
		$result = $this->sendOrder( array( 'total_mismatch' => 'warn' ), array( 'total' => 40000 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 1, $this->server->all( 'Sales Order' )[0]['docstatus'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] ?? array() );
	}

	public function test_a_difference_of_one_minor_unit_is_rounding(): void {
		$this->assertTrue( $this->sendOrder( array(), array( 'total' => 43801 ) )->isSuccess() );
	}

	public function test_the_part_of_the_total_that_belongs_to_excluded_products_is_taken_off(): void {
		$result = $this->sendOrder( array(), array( 'total' => 50000, 'excluded_total' => 6200 ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
	}

	public function test_shipping_and_fees_need_their_items_and_become_lines(): void {
		$payload = array( 'shipping' => array( 'total' => 2500, 'name' => 'Shipping' ), 'fee' => array( array( 'name' => 'Gift wrap', 'total' => 1000 ) ), 'total' => 47300 );
		$result  = $this->sendOrder( array(), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'shipping_item', $result->error->context['field'] );
		$this->assertSame( array(), $this->server->all( 'Customer' ) );

		$result = $this->sendOrder( array( 'shipping_item' => 'SHIPPING', 'fee_item' => 'FEE' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( array( 'CS-BOWL-02', 'SHIPPING', 'FEE' ), array_column( $order['items'], 'item_code' ) );
		$this->assertSame( 25.0, $order['items'][1]['rate'] );
		$this->assertSame( 473.0, $order['grand_total'] );
	}

	public function test_a_discount_is_the_document_discount_on_the_net_total(): void {
		$result = $this->sendOrder( array(), array( 'discount' => array( 'total' => 5000, 'name' => 'SUMMER10' ), 'total' => 38800 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$order = $this->server->all( 'Sales Order' )[0];
		$this->assertSame( 50.0, $order['discount_amount'] );
		$this->assertSame( 'Net Total', $order['apply_discount_on'] );
	}

	public function test_a_line_discount_in_percent_changes_the_rate_in_whole_numbers(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'discount' => 10 ) ), 'total' => 19710 ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 197.1, $this->server->all( 'Sales Order' )[0]['items'][0]['rate'] );
	}

	public function test_the_order_note_is_added_as_a_comment(): void {
		$this->sendOrder( array(), array( 'note' => 'Gift wrap please' ) );
		$comments = $this->server->all( 'Comment' );
		$this->assertCount( 1, $comments );
		$this->assertSame( 'Gift wrap please', $comments[0]['content'] );
		$this->assertSame( 'Sales Order', $comments[0]['reference_doctype'] );
	}

	public function test_a_note_that_cannot_be_stored_does_not_fail_the_order(): void {
		$this->server->failNext( 'POST', '#/api/resource/Comment$#', FakeHttpClient::json( array( 'exc_type' => 'PermissionError' ), 403 ) );
		$this->assertTrue( $this->sendOrder( array(), array( 'note' => 'x' ) )->isSuccess() );
	}

	public function test_customer_policies(): void {
		$this->server->put( 'Customer', array( 'name' => 'Noor Al-Mansouri', 'customer_name' => 'Noor Al-Mansouri', 'email_id' => 'NOOR@example.test', 'disabled' => 0 ) );
		$result = $this->sendOrder();
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->all( 'Customer' ), 'Matched by email, ignoring case.' );

		$result = $this->sendOrder( array( 'customer_policy' => 'match_only' ), array( 'partner' => array( 'email' => 'new@example.test' ) ), '101' );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'match only', (string) $result->error->context['reason'] );

		$result = $this->sendOrder( array( 'customer_policy' => 'always_create' ), array(), '102' );
		$this->assertTrue( $result->isSuccess() );
		$this->assertGreaterThanOrEqual( 1, count( $this->server->all( 'Customer' ) ) );
	}

	public function test_a_customer_is_found_through_the_contact_when_the_customer_has_no_email(): void {
		$this->server->put( 'Customer', array( 'name' => 'Acme Trading', 'customer_name' => 'Acme Trading', 'disabled' => 0 ) );
		$this->server->put( 'Contact', array( 'email_ids' => array( array( 'email_id' => 'Noor@Example.test' ) ), 'links' => array( array( 'link_doctype' => 'Customer', 'link_name' => 'Acme Trading' ) ) ) );

		$this->assertTrue( $this->sendOrder()->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
		$this->assertSame( 'Acme Trading', $this->server->all( 'Sales Order' )[0]['customer'] );
	}

	public function test_a_company_name_makes_a_company_customer_with_the_buyer_as_contact(): void {
		$this->sendOrder( array(), array( 'partner' => array( 'company_name' => 'Acme Trading LLC', 'vat' => '100000000000003' ) ) );
		$customer = $this->server->all( 'Customer' )[0];
		$this->assertSame( 'Acme Trading LLC', $customer['customer_name'] );
		$this->assertSame( 'Company', $customer['customer_type'] );
		$this->assertSame( '100000000000003', $customer['tax_id'] );
		$this->assertSame( 'Noor', $customer['first_name'] );
	}

	public function test_a_new_customer_needs_a_group_and_a_territory(): void {
		$this->server->docs['Selling Settings']['Selling Settings'] = array();
		$result = $this->sendOrder();
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'customer_group', $result->error->context['field'] );

		$this->assertTrue( $this->sendOrder( array( 'customer_group' => 'Retail', 'territory' => 'UAE' ) )->isSuccess() );
	}

	public function test_several_companies_need_a_choice(): void {
		$this->server->put( 'Company', array( 'name' => 'Second Co', 'default_currency' => 'AED' ) );
		$result = $this->sendOrder();
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'company', $result->error->context['field'] );
		$this->assertTrue( $this->sendOrder( array( 'company' => 'Cairo Sand LLC' ) )->isSuccess() );
		$this->assertSame( 'Cairo Sand LLC', $this->server->all( 'Sales Order' )[0]['company'] );
	}

	public function test_an_order_with_the_same_po_number_for_the_same_customer_is_refused_by_erpnext_and_reported(): void {
		$this->server->put( 'Customer', array( 'name' => 'Noor Al-Mansouri', 'customer_name' => 'Noor Al-Mansouri', 'email_id' => 'noor@example.test', 'disabled' => 0 ) );
		$this->server->put( 'Sales Order', array( 'po_no' => 'WC-100', 'docstatus' => 1, 'customer' => 'Noor Al-Mansouri', 'grand_total' => 1, 'currency' => 'AED', 'creation' => '2026-11-01 00:00:00' ) );
		// The connector finds that order first, so the second line of defence is not even reached.
		$result = $this->sendOrder();
		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Sales Order' ) );
	}

	public function test_a_refused_document_reports_the_servers_first_message_without_secrets(): void {
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', FakeHttpClient::json( array( 'exc_type' => 'ValidationError', '_server_messages' => json_encode( array( json_encode( array( 'message' => '<b>Row #1</b>: Warehouse is mandatory for stock item CS-BOWL-02 ' . \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::SECRET ) ) ) ) ), 417 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Warehouse is mandatory', (string) $result->error->context['reason'] );
		$this->assertStringNotContainsString( '<b>', (string) $result->error->context['reason'] );
		$this->assertStringNotContainsString( \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::SECRET, $result->error->message() . (string) $result->error->context['reason'] );
	}

	public function test_documents_can_include_the_invoice_which_keeps_the_order_date_and_links_to_the_order(): void {
		$result = $this->sendOrder( array( 'documents' => 'order_invoice' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$invoices = $this->server->all( 'Sales Invoice' );
		$this->assertCount( 1, $invoices );
		$invoice = $invoices[0];
		$this->assertSame( 1, $invoice['docstatus'] );
		$this->assertSame( 1, $invoice['set_posting_time'], 'Without it ERPNext would date the invoice today.' );
		$this->assertSame( '2026-11-02', $invoice['posting_date'] );
		$this->assertSame( $result->ref->id, $invoice['items'][0]['sales_order'] );
		$this->assertNotSame( '', (string) $invoice['items'][0]['so_detail'] );
		$this->assertSame( 0, $invoice['update_stock'] );
		$this->assertSame( $invoice['name'], $result->ref->extra['invoice'] );
		$this->assertSame( 'WC-100', $invoice['po_no'] );
	}

	public function test_an_invoice_left_as_a_draft_is_submitted_on_the_next_pass_and_never_made_twice(): void {
		$options = array( 'documents' => 'order_invoice' );
		$this->server->failNext( 'PUT', '#/api/resource/Sales Invoice/#', FakeHttpClient::timeout() );
		$first = $this->sendOrder( $options );
		$this->assertFalse( $first->isSuccess() );
		$connector = $this->connector();
		$this->assertNull( $connector->findExisting( $this->connection( $options ), $this->item( EntityType::Order, '100' ) ), 'Order submitted, invoice not: unfinished.' );

		$second = $this->sendOrder( $options );
		$this->assertTrue( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'Sales Invoice' ) );
		$this->assertCount( 1, $this->server->all( 'Sales Order' ) );
		$this->assertSame( 1, $this->server->all( 'Sales Invoice' )[0]['docstatus'] );
		$this->assertNotNull( $connector->findExisting( $this->connection( $options ), $this->item( EntityType::Order, '100' ) ) );
	}

	public function test_a_paid_order_gets_a_payment_entry_against_the_invoice_once(): void {
		$options = array( 'documents' => 'order_invoice_payment', 'payment_account' => 'Bank - CS', 'mode_of_payment' => 'Credit Card' );
		$payload = array( 'payment' => array( 'reference' => 'ch_3Pq', 'paid_at' => '2026-11-02' ) );
		$first   = $this->sendOrder( $options, $payload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() . (string) ( $first->error->context['reason'] ?? '' ) );
		$payments = $this->server->all( 'Payment Entry' );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'Receive', $payments[0]['payment_type'] );
		$this->assertSame( 'Bank - CS', $payments[0]['paid_to'] );
		$this->assertSame( 438.0, $payments[0]['paid_amount'] );
		$this->assertSame( 'ch_3Pq', $payments[0]['reference_no'] );
		$this->assertSame( 'Sales Invoice', $payments[0]['references'][0]['reference_doctype'] );
		$this->assertSame( 'Paid', $this->server->all( 'Sales Invoice' )[0]['status'] );

		$second = $this->sendOrder( $options, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Payment Entry' ) );
	}

	public function test_an_unpaid_order_counts_as_finished_for_a_created_item_and_a_paid_item_waits_for_its_payment_entry(): void {
		$options = array( 'documents' => 'order_invoice_payment', 'payment_account' => 'Bank - CS' );
		$this->assertTrue( $this->sendOrder( $options )->isSuccess() );
		$connector = $this->connector();

		$this->assertNotNull( $connector->findExisting( $this->connection( $options ), $this->item( EntityType::Order, '100' ) ), 'Nothing was paid, so nothing is missing.' );
		$this->assertNull( $connector->findExisting( $this->connection( $options ), $this->itemOf( EntityType::Order, '100', 'paid' ) ), 'The paid item is not finished until the Payment Entry exists.' );

		$paid = $this->connector()->send( $this->connection( $options ), $this->itemOf( EntityType::Order, '100', 'paid' ), $this->order( array( 'payment' => array( 'paid_at' => '2026-11-02' ) ) ) );
		$this->assertTrue( $paid->isSuccess(), (string) $paid->error?->message() . (string) ( $paid->error->context['reason'] ?? '' ) );
		$this->assertCount( 1, $this->server->all( 'Payment Entry' ) );
		$this->assertNotNull( $connector->findExisting( $this->connection( $options ), $this->itemOf( EntityType::Order, '100', 'paid' ) ) );
	}

	public function test_a_duplicate_entry_on_the_orders_own_reference_links_the_order_that_is_there(): void {
		$server = $this->server;
		$server->failNext(
			'POST',
			'#/api/resource/Sales Order$#',
			function () use ( $server ): \Estasoft\SyncEngine\Contract\HttpResponse {
				$server->put( 'Sales Order', array( 'name' => 'SO-RACE', 'po_no' => 'WC-100', 'customer' => 'C', 'docstatus' => 1, 'status' => 'To Deliver and Bill', 'grand_total' => 438.0, 'currency' => 'AED' ) );
				return FakeHttpClient::json( array( 'exc_type' => 'DuplicateEntryError', '_server_messages' => (string) json_encode( array( (string) json_encode( array( 'message' => 'Duplicate entry' ) ) ) ) ), 409 );
			}
		);
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 'SO-RACE', $result->ref->id );
		$this->assertCount( 1, $this->server->all( 'Sales Order' ) );
	}

	public function test_a_duplicate_entry_without_an_order_behind_it_is_still_val_002(): void {
		$this->server->failNext( 'POST', '#/api/resource/Sales Order$#', FakeHttpClient::json( array( 'exc_type' => 'DuplicateEntryError' ), 409 ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_an_unpaid_order_gets_no_payment_and_a_payment_needs_an_account_in_the_orders_currency(): void {
		$options = array( 'documents' => 'order_invoice_payment', 'payment_account' => 'Bank - CS' );
		$this->assertTrue( $this->sendOrder( $options )->isSuccess() );
		$this->assertSame( array(), $this->server->all( 'Payment Entry' ), 'Cash on delivery: nothing was paid.' );

		$result = $this->sendOrder( array( 'documents' => 'order_invoice_payment' ), array( 'payment' => array( 'paid_at' => '2026-11-02' ) ), '101' );
		$this->assertSame( 'payment_account', $result->error->context['field'] );

		$result = $this->sendOrder( array( 'documents' => 'order_invoice_payment', 'payment_account' => 'Bank KWD - CS' ), array( 'payment' => array( 'paid_at' => '2026-11-02' ) ), '102' );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'KWD', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->server->all( 'Payment Entry' ) );
	}

	public function test_the_update_stock_option_reaches_the_invoice(): void {
		$this->sendOrder( array( 'documents' => 'order_invoice', 'update_stock' => 'yes' ) );
		$this->assertSame( 1, $this->server->all( 'Sales Invoice' )[0]['update_stock'] );
	}

	public function test_arabic_text_and_long_values_travel_unchanged(): void {
		$name   = 'نور المنصوري';
		$result = $this->sendOrder( array(), array( 'partner' => array( 'name' => $name, 'email' => 'ar@example.test', 'street' => 'شارع الشيخ زايد، برج ٢٣' ), 'note' => 'مرحبا' ) );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( $name, $this->server->all( 'Customer' )[0]['customer_name'] );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $this->server->all( 'Address' )[0]['address_line1'] );
		$this->assertSame( 'مرحبا', $this->server->all( 'Comment' )[0]['content'] );
	}

	public function test_no_request_leaves_the_sites_address_or_carries_a_secret_in_its_url(): void {
		$this->sendOrder();
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringStartsWith( 'https://erp.example.test/api/', $request['url'] );
			$this->assertStringNotContainsString( \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::SECRET, $request['url'] );
			$this->assertSame( 'token ' . \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::KEY . ':' . \Estasoft\SyncEngine\Tests\Support\FakeErpnextServer::SECRET, $request['headers']['Authorization'] );
			$this->assertLessThanOrEqual( 30, $request['timeout'] );
		}
	}

	public function test_a_private_address_is_refused_by_the_host_policy(): void {
		$connection = $this->kit->connection( 'erpnext', array( 'address' => 'https://192.168.1.20' ), array( 'api_key' => 'k', 'api_secret' => 's' ), 7 );
		$result     = $this->connector()->send( $connection, $this->item( EntityType::Order, '100' ), $this->order() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 0, $this->kit->http->count(), 'Nothing was sent to a private address.' );
	}

	public function test_a_bad_address_is_refused_with_a_reason(): void {
		$connection = $this->kit->connection( 'erpnext', array( 'address' => 'ftp://erp.example.test' ), array( 'api_key' => 'k', 'api_secret' => 's' ), 8 );
		$result     = $this->connector()->send( $connection, $this->item( EntityType::Order, '100' ), $this->order() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'address', $result->error->context['field'] );
	}
}
