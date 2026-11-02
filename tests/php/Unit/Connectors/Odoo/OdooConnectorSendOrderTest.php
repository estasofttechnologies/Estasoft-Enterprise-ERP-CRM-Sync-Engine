<?php
/**
 * Sending orders to Odoo.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeOdooFault;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooConnectorSendOrderTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array(
			'odoo 17 json-rpc' => array( 17 ),
			'odoo 18 json-rpc' => array( 18 ),
			'odoo 19 json-2'   => array( 19 ),
		);
	}

	private function send( FakeOdooServer $server, array $payload, array $config = array(), string $event = 'created', string $id = '10490' ): SendResult {
		return $this->connector( $server )->send( $this->connection( $server, $config ), $this->item( EntityType::Order, $id, $event ), $payload );
	}

	/**
	 * The text that makes a create on a model the request to lose.
	 *
	 * @param int    $major Version.
	 * @param string $model Model.
	 */
	private function createOf( int $major, string $model ): string {
		return $major >= 19 ? $model . '/create' : '"' . $model . '","create"';
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_an_order_becomes_a_confirmed_sale_order_with_the_right_total( int $major ): void {
		$server = $this->server( $major );
		$result = $this->send( $server, $this->orderPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$ref = $result->ref;
		$this->assertSame( 'S00490', $ref->ref );
		$this->assertSame( 48615, $ref->extra['total_minor'], '2 x 219.00 + 25.00 shipping + 5% VAT = 486.15' );
		$this->assertSame( 'AED', $ref->extra['currency'] );
		$this->assertSame( 'sale', $ref->extra['state'] );
		$this->assertSame( $major >= 18 ? 'https://erp.example.com/odoo/sale.order/1' : 'https://erp.example.com/web#id=1&model=sale.order&view_type=form', $ref->url );

		$order = $server->db->all( 'sale.order' )[0];
		$this->assertSame( 'WC-10490', $order['client_order_ref'] );
		$this->assertSame( 'WooCommerce #10490', $order['origin'] );
		$this->assertSame( '2026-11-02 07:40:00', $order['date_order'] );
		$this->assertCount( 2, $server->db->all( 'sale.order.line' ), 'The product line and the shipping line.' );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_the_tax_field_follows_the_version( int $major ): void {
		$server = $this->server( $major );
		$this->send( $server, $this->orderPayload() );
		$line = $server->db->all( 'sale.order.line' )[0];
		$this->assertSame( array( 1 ), $line[ $major >= 19 ? 'tax_ids' : 'tax_id' ] );
		$this->assertArrayNotHasKey( $major >= 19 ? 'tax_id' : 'tax_ids', $line );
	}

	public function test_one_create_call_makes_the_order_with_all_its_lines(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$this->assertCount( 1, $server->callsTo( 'sale.order', 'create' ) );
		$this->assertCount( 0, $server->callsTo( 'sale.order.line', 'create' ) );
	}

	public function test_sending_the_same_order_twice_creates_one_order(): void {
		$server = $this->server( 18 );
		$first  = $this->send( $server, $this->orderPayload() );
		$second = $this->send( $server, $this->orderPayload() );

		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( 'S00490', $second->ref->ref );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
		$this->assertCount( 1, $server->db->all( 'res.partner' ) );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_an_unknown_outcome_after_a_lost_answer_never_creates_a_second_order( int $major ): void {
		$server    = $this->server( $major );
		$connector = $this->connector( $server );
		$connection = $this->connection( $server );
		$item       = $this->item( EntityType::Order, '10490' );

		$server->loseNextAnswer( $this->createOf( $major, 'sale.order' ) );
		$first = $connector->send( $connection, $item, $this->orderPayload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertSame( ErrorClass::Retry, $first->error->errorClass() );
		$this->assertCount( 1, $server->db->all( 'sale.order' ), 'Odoo did create the order; only the answer was lost.' );

		// The engine links whatever findExisting returns and does not call send() for it. The order is only a quotation (it was
		// never confirmed), so it is not reported as found: send() finds it, confirms it and says it already existed.
		$this->assertNull( $connector->findExisting( $connection, $item ), 'An unfinished order is not reported as found, or its steps would never be done.' );

		$second = $connector->send( $connection, $item, $this->orderPayload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $server->db->all( 'sale.order' ), 'Still one order.' );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'], 'The retry carried on from the quotation.' );

		$found = $connector->findExisting( $connection, $item );
		$this->assertInstanceOf( ExternalRef::class, $found, 'Once its steps are done the order is found.' );
		$this->assertSame( $found->id, $second->ref->id );
	}

	public function test_find_existing_looks_up_the_client_reference_and_nothing_else(): void {
		$server    = $this->server( 19 );
		$connector = $this->connector( $server );
		$connection = $this->connection( $server );
		$this->assertNull( $connector->findExisting( $connection, $this->item( EntityType::Order, '10490' ) ) );
		$this->send( $server, $this->orderPayload() );
		$found = $connector->findExisting( $connection, $this->item( EntityType::Order, '10490' ) );
		$this->assertSame( 'S00490', $found->ref );
		$this->assertNull( $connector->findExisting( $connection, $this->item( EntityType::Order, '10491' ) ), 'WC-10491 is a different order.' );
		$this->assertNull( $connector->findExisting( $connection, $this->item( EntityType::Order, '1049' ) ), 'WC-1049 must not match WC-10490.' );
		$search = $server->callsTo( 'sale.order', 'search_read' );
		$this->assertSame( array( array( 'client_order_ref', '=', 'WC-1049' ), array( 'company_id', 'in', array( false, 1 ) ) ), $search[ count( $search ) - 1 ]['params']['domain'] );
	}

	public function test_the_client_reference_comes_from_the_item_not_from_the_mapping(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'client_order_ref' => 'SOMETHING-ELSE' ) ) );
		$this->assertSame( 'WC-10490', $server->db->all( 'sale.order' )[0]['client_order_ref'] );
		$this->assertTrue( $result->isSuccess() );
	}

	public function test_a_repeat_buyer_is_matched_by_email_ignoring_case_and_never_duplicated(): void {
		$server = $this->server( 18 );
		$known  = $server->db->addPartner( 'Noor (old record)', 'Noor@Example.COM' );
		$result = $this->send( $server, $this->orderPayload() );

		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $server->db->all( 'res.partner' ) );
		$this->assertSame( $known, $server->db->all( 'sale.order' )[0]['partner_id'] );
		$this->assertSame( $known, $result->ref->extra['partner_id'] );
	}

	public function test_an_underscore_in_an_email_does_not_match_a_different_address(): void {
		$server = $this->server( 18 );
		$server->db->addPartner( 'Someone else', 'john_doe@example.com' );
		$payload                     = $this->orderPayload();
		$payload['partner']['email'] = 'johnxdoe@example.com';
		$this->send( $server, $payload );
		$this->assertCount( 2, $server->db->all( 'res.partner' ), 'The pattern character _ is not a wildcard here.' );
	}

	public function test_a_new_customer_is_created_with_the_mapped_fields_and_arabic_text_unchanged(): void {
		$server  = $this->server( 19 );
		$payload = $this->orderPayload();
		$payload['partner']['name']   = 'نور المنصوري';
		$payload['partner']['street'] = 'شارع الشيخ زايد';
		$payload['partner']['vat']    = '100000000000003';
		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess() );
		$partner = $server->db->all( 'res.partner' )[0];
		$this->assertSame( 'نور المنصوري', $partner['name'] );
		$this->assertSame( 'شارع الشيخ زايد', $partner['street'] );
		$this->assertSame( 'noor@example.com', $partner['email'] );
		$this->assertSame( '+971501234567', $partner['phone'] );
		$this->assertSame( '100000000000003', $partner['vat'] );
		$this->assertSame( 1, $partner['country_id'], 'AE' );
		$this->assertSame( 1, $partner['state_id'], 'AE-DU' );
		$this->assertFalse( $partner['is_company'] );
	}

	public function test_a_state_odoo_does_not_have_is_left_out_instead_of_failing_the_order(): void {
		$server = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['partner']['state_id'] = 'AE-ZZ';
		$this->assertTrue( $this->send( $server, $payload )->isSuccess() );
		$this->assertArrayNotHasKey( 'state_id', $server->db->all( 'res.partner' )[0] );
	}

	public function test_customer_policy_match_only_refuses_an_unknown_customer(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'customer_policy' => 'match_only' ) ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'partner.email', $result->error->context['field'] );
		$this->assertCount( 0, $server->db->all( 'sale.order' ) );
	}

	public function test_customer_policy_always_create_makes_a_new_contact_every_time(): void {
		$server = $this->server( 18 );
		$server->db->addPartner( 'Noor', 'noor@example.com' );
		$this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'customer_policy' => 'always_create' ) ) );
		$this->assertCount( 2, $server->db->all( 'res.partner' ) );
	}

	public function test_two_customers_with_the_same_email_use_the_oldest_unless_told_to_fail(): void {
		$server = $this->server( 18 );
		$oldest = $server->db->addPartner( 'Noor 1', 'noor@example.com' );
		$server->db->addPartner( 'Noor 2', 'NOOR@example.com' );
		$this->send( $server, $this->orderPayload() );
		$this->assertSame( $oldest, $server->db->all( 'sale.order' )[0]['partner_id'] );

		$strict = $this->server( 18 );
		$strict->db->addPartner( 'Noor 1', 'noor@example.com' );
		$strict->db->addPartner( 'Noor 2', 'noor@example.com' );
		$result = $this->send( $strict, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'duplicate_customer' => 'fail' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'More than one customer', $result->error->context['reason'] );
	}

	public function test_a_separate_delivery_address_becomes_a_delivery_contact_that_is_reused(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['delivery'] = array( 'name' => 'Layla K.', 'street' => 'Marina Walk 5', 'city' => 'Dubai', 'zip' => '00000', 'country_id' => 'AE', 'phone' => '+971500000001' );
		$this->send( $server, $payload );
		$partners = $server->db->all( 'res.partner' );
		$this->assertCount( 2, $partners );
		$this->assertSame( 'delivery', $partners[1]['type'] );
		$this->assertSame( 1, $partners[1]['parent_id'] );
		$this->assertSame( 'Layla K.', $partners[1]['name'] );
		$this->assertSame( 2, $server->db->all( 'sale.order' )[0]['partner_shipping_id'] );

		$this->send( $server, $payload, array(), 'created', '10491' );
		$this->assertCount( 2, $server->db->all( 'res.partner' ), 'The same delivery address is not created twice.' );
		$this->assertSame( 2, $server->db->all( 'sale.order' )[1]['partner_shipping_id'] );
	}

	public function test_a_delivery_address_equal_to_the_billing_address_adds_no_contact(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['delivery'] = array( 'street' => 'sheikh zayed road', 'city' => 'DUBAI' );
		$this->send( $server, $payload );
		$this->assertCount( 1, $server->db->all( 'res.partner' ) );
		$this->assertArrayNotHasKey( 'partner_shipping_id', $server->db->all( 'sale.order' )[0] );
	}

	public function test_a_product_odoo_does_not_have_is_map_001_naming_the_sku(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][] = array( 'product' => 'CS-LAMP-07', 'quantity' => 1, 'price_unit' => 9900, 'tax' => 'VAT 5% (AE)' );
		$result = $this->send( $server, $payload );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'CS-LAMP-07', $result->error->context['sku'] );
		$this->assertSame( 'Product CS-LAMP-07 was not found in Odoo.', $result->error->message() );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		$this->assertCount( 0, $server->db->all( 'sale.order' ), 'Nothing is created for an order that cannot be completed.' );
		$this->assertCount( 0, $server->db->all( 'res.partner' ), 'Not even the customer: the lines are checked first.' );
	}

	public function test_an_archived_product_is_not_a_match(): void {
		$server = $this->server( 18 );
		$server->db->addProduct( 'CS-OLD-01', 'Old', 5.0, array(), array(), true, false );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['product'] = 'CS-OLD-01';
		$this->assertSame( ErrorCode::Map001, $this->send( $server, $payload )->error->code );
	}

	public function test_the_odoo_product_id_from_a_manual_mapping_wins_over_the_sku(): void {
		$server = $this->server( 18 );
		$mug    = $server->db->idOf( 'product.product', 'default_code', 'CS-MUG-01' );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['product']    = 'WHATEVER-IN-WOO';
		$payload['order_line'][0]['product_id'] = (string) $mug;
		$this->assertTrue( $this->send( $server, $payload )->isSuccess() );
		$this->assertSame( $mug, $server->db->all( 'sale.order.line' )[0]['product_id'] );
	}

	public function test_a_manual_product_id_that_odoo_no_longer_has_is_map_001(): void {
		$server = $this->server( 18 );
		$payload = $this->orderPayload();
		unset( $payload['order_line'][0]['product'] );
		$payload['order_line'][0]['product_id'] = 9999;
		$this->assertSame( ErrorCode::Map001, $this->send( $server, $payload )->error->code );
	}

	public function test_missing_products_can_be_created_when_the_option_says_so(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][] = array( 'product' => 'CS-NEW-09', 'name' => 'New thing', 'quantity' => 1, 'price_unit' => 1250, 'tax' => 'VAT 5% (AE)' );
		$result = $this->send( $server, $payload, array( 'options' => array( 'call_interval_ms' => 0, 'missing_product' => 'create' ) ) );
		$this->assertTrue( $result->isSuccess() );
		$id = $server->db->idOf( 'product.product', 'default_code', 'CS-NEW-09' );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'New thing', $server->db->records['product.product'][ $id ]['name'] );
		$this->assertSame( 12.5, $server->db->records['product.product'][ $id ]['list_price'] );
	}

	public function test_a_line_without_any_product_is_map_001(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['product'] = '';
		$this->assertSame( ErrorCode::Map001, $this->send( $server, $payload )->error->code );
	}

	public function test_a_tax_code_odoo_does_not_have_is_map_002(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['tax'] = 'Mystery tax';
		$result = $this->send( $server, $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertSame( 'Mystery tax', $result->error->context['rate'] );
		$this->assertSame( 'AE', $result->error->context['country'] );
		$this->assertCount( 0, $server->db->all( 'sale.order' ) );
	}

	public function test_a_tax_can_be_given_by_id_or_as_a_list(): void {
		$server  = $this->server( 18 );
		$server->db->addTax( 'Tourism fee 2%', 2.0 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['tax'] = array( '1', 'Tourism fee 2%' );
		$payload['shipping']['tax']      = '1';
		$result = $this->send( $server, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array( 1, 3 ), $server->db->all( 'sale.order.line' )[0]['tax_id'] );
	}

	public function test_a_purchase_tax_is_not_a_sale_tax(): void {
		$server = $this->server( 18 );
		$server->db->addTax( 'Input VAT 5%', 5.0, 'purchase' );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['tax'] = 'Input VAT 5%';
		$this->assertSame( ErrorCode::Map002, $this->send( $server, $payload )->error->code );
	}

	public function test_an_empty_tax_code_means_no_tax_when_the_mapping_supplies_taxes(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['tax'] = '';
		$payload['shipping']['tax']      = '';
		$result = $this->send( $server, $payload );
		$this->assertSame( array(), $server->db->all( 'sale.order.line' )[0]['tax_id'] );
		$this->assertSame( 46300, $result->ref->extra['total_minor'], '2 x 219.00 + 25.00 with no tax at all.' );
	}

	public function test_without_any_tax_key_odoo_applies_its_own_defaults(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		unset( $payload['order_line'][0]['tax'], $payload['shipping']['tax'] );
		$this->send( $server, $payload );
		$this->assertArrayNotHasKey( 'tax_id', $server->db->all( 'sale.order.line' )[0] );
	}

	public function test_a_missing_email_is_map_003_before_any_request(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		unset( $payload['partner']['email'] );
		$result = $this->send( $server, $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertSame( 'Customer email is missing on order #10490.', $result->error->message() );
		$this->assertCount( 0, $server->requests );
	}

	/**
	 * @return array<string,array{0:callable,1:string}>
	 */
	public static function provide_bad_payloads(): array {
		return array(
			'no name'          => array( static function ( array $p ): array { unset( $p['partner']['name'] ); return $p; }, 'partner.name' ),
			'no lines'         => array( static function ( array $p ): array { $p['order_line'] = array(); return $p; }, 'order_line' ),
			'zero quantity'    => array( static function ( array $p ): array { $p['order_line'][0]['quantity'] = 0; return $p; }, 'order_line[].quantity' ),
			'no price'         => array( static function ( array $p ): array { unset( $p['order_line'][0]['price_unit'] ); return $p; }, 'order_line[].price_unit' ),
			'price in decimals' => array( static function ( array $p ): array { $p['order_line'][0]['price_unit'] = '219.50'; return $p; }, 'order_line[].price_unit' ),
		);
	}

	/**
	 * @dataProvider provide_bad_payloads
	 *
	 * @param callable $break Breaks a good payload.
	 * @param string   $field Field the error names.
	 */
	public function test_a_payload_that_cannot_be_sent_is_val_002_before_any_request( callable $break, string $field ): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $break( $this->orderPayload() ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( $field, $result->error->context['field'] );
		$this->assertCount( 0, $server->requests );
	}

	public function test_prices_may_arrive_as_whole_floats_or_digit_strings(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['price_unit'] = 21900.0;
		$payload['shipping']['total']           = '2500';
		$this->assertSame( 48615, $this->send( $server, $payload )->ref->extra['total_minor'] );
	}

	public function test_shipping_fees_and_discounts_become_lines_on_service_products(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['fee']      = array( array( 'name' => 'Gift wrap', 'total' => 1000 ) );
		$payload['discount'] = array( 'total' => 5000, 'name' => 'SUMMER10' );
		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $server->db->all( 'sale.order.line' );
		$this->assertCount( 4, $lines );
		$this->assertSame( 'Shipping', $lines[1]['name'] );
		$this->assertSame( 25.0, $lines[1]['price_unit'] );
		$this->assertSame( 'Gift wrap', $lines[2]['name'] );
		$this->assertSame( 10.0, $lines[2]['price_unit'] );
		$this->assertSame( 'SUMMER10', $lines[3]['name'] );
		$this->assertSame( -50.0, $lines[3]['price_unit'], 'A discount is a negative line.' );
		foreach ( array( 'SHIPPING', 'FEE', 'DISCOUNT' ) as $code ) {
			$id = $server->db->idOf( 'product.product', 'default_code', $code );
			$this->assertGreaterThan( 0, $id, $code );
			$this->assertSame( 'service', $server->db->records['product.product'][ $id ]['type'] );
		}
	}

	public function test_the_service_products_are_found_not_created_again_on_the_next_order(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$this->send( $server, $this->orderPayload(), array(), 'created', '10491' );
		$shipping = array_filter( $server->db->all( 'product.product' ), static fn( array $p ): bool => 'SHIPPING' === $p['default_code'] );
		$this->assertCount( 1, $shipping );
	}

	public function test_service_products_use_the_references_the_merchant_chose(): void {
		$server = $this->server( 18 );
		$ship   = $server->db->addProduct( 'DELIVERY-FEE', 'Delivery', 0.0, array(), array(), false );
		$this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'shipping_product' => 'DELIVERY-FEE' ) ) );
		$this->assertSame( $ship, $server->db->all( 'sale.order.line' )[1]['product_id'] );
		$this->assertSame( 0, $server->db->idOf( 'product.product', 'default_code', 'SHIPPING' ) );
	}

	public function test_a_missing_service_product_fails_cleanly_when_creating_is_switched_off(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'create_service_products' => 'no' ) ) );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'SHIPPING', $result->error->context['sku'] );
	}

	public function test_a_zero_shipping_amount_adds_no_line(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['shipping']['total'] = 0;
		$this->send( $server, $payload );
		$this->assertCount( 1, $server->db->all( 'sale.order.line' ) );
		$this->assertSame( 0, $server->db->idOf( 'product.product', 'default_code', 'SHIPPING' ) );
	}

	public function test_a_line_discount_percent_and_description_are_kept(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload();
		$payload['order_line'][0]['discount'] = 10;
		$payload['order_line'][0]['name']     = 'Bowl, hand made';
		$this->send( $server, $payload );
		$line = $server->db->all( 'sale.order.line' )[0];
		$this->assertSame( 10.0, $line['discount'] );
		$this->assertSame( 'Bowl, hand made', $line['name'] );
	}

	public function test_a_note_is_stored_as_escaped_html(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'note' => "Gift <b>wrap</b> please\nThanks" ) );
		$this->send( $server, $payload );
		$this->assertSame( "<p>Gift &lt;b&gt;wrap&lt;/b&gt; please<br>\nThanks</p>", $server->db->all( 'sale.order' )[0]['note'] );
	}

	public function test_a_currency_other_than_the_company_currency_needs_a_price_list(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'currency' => 'USD' ) );
		$result  = $this->send( $server, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'no active price list in USD', $result->error->context['reason'] );
		$this->assertCount( 0, $server->db->all( 'sale.order' ), 'Nothing is created when the price list is missing.' );
		$this->assertCount( 0, $server->db->all( 'res.partner' ), 'Not even the customer.' );

		$server->db->insert( 'product.pricelist', array( 'name' => 'USD list', 'currency_id' => 2, 'active' => true ) );
		$ok = $this->send( $server, $payload );
		$this->assertTrue( $ok->isSuccess() );
		$this->assertSame( 'USD', $ok->ref->extra['currency'] );
	}

	public function test_three_decimal_currencies_keep_every_fil(): void {
		$server  = $this->server( 18, array( 'currency' => 'KWD', 'country' => 'KW' ) );
		$payload = $this->orderPayload( array( 'currency' => 'KWD' ) );
		unset( $payload['order_line'][0]['tax'], $payload['shipping'] );
		$payload['order_line'][0]['price_unit'] = 12345;
		$payload['order_line'][0]['quantity']   = 3;
		$payload['order_line'][0]['tax']        = '';
		$result = $this->send( $server, $payload );

		$this->assertSame( 12.345, $server->db->all( 'sale.order.line' )[0]['price_unit'] );
		$this->assertSame( 37035, $result->ref->extra['total_minor'], '3 x 12.345 KWD' );
		$this->assertSame( 'KWD', $result->ref->extra['currency'] );
	}

	public function test_without_a_currency_in_the_payload_the_company_currency_sets_the_decimals(): void {
		$server  = $this->server( 18, array( 'currency' => 'KWD', 'country' => 'KW' ) );
		$payload = $this->orderPayload();
		unset( $payload['currency'], $payload['shipping'], $payload['order_line'][0]['tax'] );
		$payload['order_line'][0]['price_unit'] = 1500;
		$payload['order_line'][0]['quantity']   = 1;
		$this->send( $server, $payload );
		$this->assertSame( 1.5, $server->db->all( 'sale.order.line' )[0]['price_unit'] );
	}

	public function test_a_payment_term_that_odoo_does_not_have_is_val_002_and_one_that_exists_is_used(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'payment_term' => 'Pay on delivery' ) );
		$result  = $this->send( $server, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'payment_term', $result->error->context['field'] );

		$term = $server->db->insert( 'account.payment.term', array( 'name' => 'Pay on delivery', 'active' => true ) );
		$this->assertTrue( $this->send( $server, $payload )->isSuccess() );
		$this->assertSame( $term, $server->db->all( 'sale.order' )[0]['payment_term_id'] );
	}

	public function test_a_warehouse_is_found_by_code_or_name_and_a_wrong_one_is_val_002(): void {
		$server = $this->server( 18 );
		$this->assertTrue( $this->send( $server, $this->orderPayload( array( 'warehouse' => 'ruh' ) ) )->isSuccess() );
		$this->assertSame( 2, $server->db->all( 'sale.order' )[0]['warehouse_id'] );
		$bad = $this->send( $server, $this->orderPayload( array( 'warehouse' => 'NOPE' ) ), array(), 'created', '10491' );
		$this->assertSame( 'warehouse', $bad->error->context['field'] );
	}

	public function test_confirmation_can_be_switched_off(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'flow' => array( 'confirm' => false ) ) );
		$result  = $this->send( $server, $payload );
		$this->assertSame( 'draft', $result->ref->extra['state'] );
		$this->assertCount( 0, $server->callsTo( 'sale.order', 'action_confirm' ) );
	}

	public function test_an_order_is_not_invoiced_by_default(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload() );
		$this->assertSame( array(), $server->db->all( 'account.move' ) );
		$this->assertSame( array(), $result->ref->extra['invoice_ids'] );
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_the_invoice_option_creates_and_posts_an_invoice_with_the_order_total( int $major ): void {
		$server = $this->server( $major );
		$result = $this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoices = $server->db->all( 'account.move' );
		$this->assertCount( 1, $invoices );
		$this->assertSame( 'out_invoice', $invoices[0]['move_type'] );
		$this->assertSame( 'posted', $invoices[0]['state'] );
		$this->assertSame( 'INV/2026/00001', $invoices[0]['name'] );
		$this->assertSame( 486.15, $invoices[0]['amount_total'] );
		$this->assertSame( array( 1 ), $result->ref->extra['invoice_ids'] );
		$this->assertCount( 0, $server->callsTo( 'account.move', 'action_process_edi_web_services' ), 'No tax authority step outside Saudi e-invoicing.' );
	}

	public function test_invoices_can_stay_drafts_for_a_person_to_check(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload(), array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice', 'invoice_state' => 'draft' ) ) );
		$this->assertSame( 'draft', $server->db->all( 'account.move' )[0]['state'] );
	}

	public function test_the_mapping_can_decide_the_document_flow_per_order(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload( array( 'flow' => array( 'invoice' => 'draft' ) ) ) );
		$this->assertSame( 'draft', $server->db->all( 'account.move' )[0]['state'] );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'], 'An invoice needs a confirmed order.' );
	}

	public function test_payment_is_registered_on_a_posted_invoice_when_asked(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'payment' => array( 'reference' => 'pi_3Abc123' ) ) );
		$result  = $this->send( $server, $payload, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice_payment' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = $server->db->all( 'account.move' )[0];
		$this->assertSame( 'posted', $invoice['state'] );
		$this->assertSame( 'paid', $invoice['payment_state'] );
		$this->assertSame( 'pi_3Abc123', $invoice['_payment_memo'] );
		$wizard = $server->callsTo( 'account.payment.register', 'action_create_payments' )[0];
		$this->assertSame( array( 1 ), $wizard['context']['active_ids'] );
		$this->assertSame( 'account.move', $wizard['context']['active_model'] );
	}

	public function test_payment_is_off_by_default_even_for_paid_orders(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload( array( 'payment' => array( 'reference' => 'pi_3Abc123' ) ) ), array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) );
		$this->assertSame( 'not_paid', $server->db->all( 'account.move' )[0]['payment_state'] );
		$this->assertCount( 0, $server->callsTo( 'account.payment.register', 'action_create_payments' ) );
	}

	public function test_saudi_invoices_are_sent_to_the_tax_service_step_after_posting(): void {
		$server = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$server->db->addTax( 'VAT 15%', 15.0 );
		$payload = $this->orderPayload( array( 'currency' => 'SAR' ) );
		$payload['order_line'][0]['tax'] = 'VAT 15%';
		$payload['shipping']['tax']      = 'VAT 15%';
		$result = $this->send( $server, $payload, array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $server->callsTo( 'account.move', 'action_process_edi_web_services' ) );
		$this->assertSame( 'sent', $server->db->all( 'account.move' )[0]['_edi_state'] );
	}

	public function test_a_draft_invoice_is_never_sent_to_the_tax_service(): void {
		$server = $this->server( 19, array( 'zatca' => true, 'country' => 'SA', 'currency' => 'SAR' ) );
		$this->send( $server, $this->orderPayload( array( 'currency' => 'SAR' ) ), array( 'options' => array( 'call_interval_ms' => 0, 'documents' => 'order_invoice', 'invoice_state' => 'draft' ) ) );
		$this->assertCount( 0, $server->callsTo( 'account.move', 'action_post' ) );
		$this->assertCount( 0, $server->callsTo( 'account.move', 'action_process_edi_web_services' ) );
	}

	public function test_a_step_after_the_creation_that_fails_leaves_the_order_and_the_retry_finishes_the_job(): void {
		$server = $this->server( 18 );
		$server->db->confirmFault = FakeOdooFault::user( 'Some products are not available.' );
		$first = $this->send( $server, $this->orderPayload() );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertStringContainsString( 'not available', $first->error->context['reason'] );
		$this->assertSame( 'draft', $server->db->all( 'sale.order' )[0]['state'], 'The order is in Odoo as a quotation.' );

		$second = $this->send( $server, $this->orderPayload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( 'sale', $second->ref->extra['state'], 'The retry confirms the quotation it finds.' );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	public function test_a_passing_error_on_a_later_step_is_tried_once_more_at_once(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::status( 502, 'bad gateway' ), 'action_confirm' );
		$result = $this->send( $server, $this->orderPayload() );
		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'sale', $result->ref->extra['state'] );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	public function test_a_quotation_is_cancelled_when_the_order_is_cancelled_before_it_was_confirmed(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload( array( 'flow' => array( 'confirm' => false ) ) ) );
		$result = $this->send( $server, $this->orderPayload(), array(), 'cancelled' );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 'cancel', $server->db->all( 'sale.order' )[0]['state'] );
		$this->assertSame( 'cancel', $result->ref->extra['state'] );
	}

	public function test_a_confirmed_order_is_never_cancelled_by_the_plugin(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$result = $this->send( $server, $this->orderPayload(), array(), 'cancelled' );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'] );
		$this->assertCount( 0, $server->callsTo( 'sale.order', 'action_cancel' ) );
	}

	public function test_cancelling_an_order_odoo_never_had_is_a_clear_error(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload(), array(), 'cancelled' );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $server->db->all( 'sale.order' ) );
	}

	public function test_an_update_of_an_order_that_needs_nothing_changes_nothing(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$writes = count( $server->callsTo( 'sale.order', 'write' ) ) + count( $server->callsTo( 'sale.order', 'action_confirm' ) );
		$result = $this->send( $server, $this->orderPayload(), array(), 'updated' );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( $writes, count( $server->callsTo( 'sale.order', 'write' ) ) + count( $server->callsTo( 'sale.order', 'action_confirm' ) ) );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	public function test_a_named_company_gets_the_order(): void {
		$server = $this->server( 18 );
		$server->db->insert( 'res.company', array( 'name' => 'Cedar Riyadh LLC', 'currency_id' => 1, 'country_id' => 1 ) );
		$server->db->insert( 'account.tax', array( 'name' => 'VAT 5% (AE)', 'amount' => 5.0, 'amount_type' => 'percent', 'type_tax_use' => 'sale', 'active' => true, 'company_id' => 2 ) );
		$result = $this->send( $server, $this->orderPayload(), array( 'company' => 'Cedar Riyadh LLC', 'options' => array( 'call_interval_ms' => 0 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 2, $server->db->all( 'sale.order' )[0]['company_id'] );
		$this->assertSame( array( 3 ), $server->db->all( 'sale.order.line' )[0]['tax_id'], 'The tax of the chosen company, not the other one.' );
		$create = $server->callsTo( 'sale.order', 'create' )[0];
		$this->assertSame( array( 2 ), $create['context']['allowed_company_ids'] );
	}

	public function test_an_access_error_is_auth_004_naming_the_action(): void {
		foreach ( array( 17, 18, 19 ) as $major ) {
			$server = $this->server( $major );
			$server->db->deny( 'sale.order', 'create' );
			$result = $this->send( $server, $this->orderPayload() );
			$this->assertSame( ErrorCode::Auth004, $result->error->code, "Odoo {$major}" );
			$this->assertSame( 'create sale orders', $result->error->context['action'], "Odoo {$major}" );
			$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		}
	}

	public function test_a_value_odoo_refuses_is_val_002_with_odoos_reason(): void {
		foreach ( array( 18, 19 ) as $major ) {
			$server  = $this->server( $major );
			$payload = $this->orderPayload();
			$payload['partner']['vat'] = 'INVALID-VAT';
			$result = $this->send( $server, $payload );
			$this->assertSame( ErrorCode::Val002, $result->error->code, "Odoo {$major}" );
			$this->assertStringContainsString( 'VAT number', $result->error->context['reason'] );
			$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		}
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_network_and_server_failures_are_classified_and_retry( int $major ): void {
		$cases = array(
			array( FakeHttpClient::timeout(), ErrorCode::Net001, null ),
			array( FakeHttpClient::status( 502, 'bad gateway' ), ErrorCode::Net002, null ),
			array( FakeHttpClient::status( 500, 'oops' ), ErrorCode::Net002, null ),
			array( FakeHttpClient::tooManyRequests( 37 ), ErrorCode::Rate001, 37 ),
			array( FakeHttpClient::malformedJson(), ErrorCode::Net001, null ),
		);
		foreach ( $cases as $case ) {
			$server = $this->server( $major );
			$server->failNext( $case[0], $this->createOf( $major, 'sale.order' ) );
			$result = $this->send( $server, $this->orderPayload() );
			$this->assertFalse( $result->isSuccess() );
			$this->assertSame( $case[1], $result->error->code );
			$this->assertSame( $case[2], $result->error->retryAfter );
			$this->assertSame( ErrorClass::Retry, $result->error->errorClass() );
		}
	}

	public function test_a_key_revoked_in_between_is_auth_001(): void {
		$server = $this->server( 19 );
		$server->failNext( FakeHttpClient::json( array( 'name' => 'werkzeug.exceptions.Unauthorized', 'message' => 'Invalid apikey', 'arguments' => array(), 'context' => array(), 'debug' => '' ), 401 ), 'sale.order/create' );
		$result = $this->send( $server, $this->orderPayload() );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
	}

	public function test_the_key_is_never_in_an_error_or_a_reference(): void {
		$server = $this->server( 18 );
		$server->failNext( FakeHttpClient::status( 500, 'sk-test-0123456789abcdef leaked by a proxy' ), $this->createOf( 18, 'sale.order' ) );
		$result = $this->send( $server, $this->orderPayload() );
		$text   = $result->error->message() . $result->error->detail . json_encode( $result->error->context );
		$this->assertStringNotContainsString( $server->apiKey, $text );
	}

	public function test_requests_keep_to_about_one_a_second_by_default(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => array() ) ),
			$this->item( EntityType::Order, '10490' ),
			$this->orderPayload()
		);
		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( count( $server->requests ) - 1, $this->slept, 'A wait before every request but the first.' );
		$this->assertSame( array( 1000 ), array_values( array_unique( $this->slept ) ) );
	}

	public function test_the_pace_can_be_changed_for_a_server_that_allows_more(): void {
		$server = $this->server( 18 );
		$this->connector( $server )->send( $this->connection( $server, array( 'options' => array( 'call_interval_ms' => 250 ) ) ), $this->item( EntityType::Order, '10490' ), $this->orderPayload() );
		$this->assertSame( array( 250 ), array_values( array_unique( $this->slept ) ) );
	}

	public function test_requests_are_made_one_at_a_time_in_order(): void {
		$server = $this->server( 19 );
		$this->send( $server, $this->orderPayload() );
		$this->assertGreaterThan( 5, count( $server->requests ) );
		foreach ( $server->requests as $request ) {
			$this->assertSame( 30, $request['timeout'] );
		}
	}

	public function test_a_second_order_in_the_same_run_reuses_the_signed_in_client(): void {
		$server    = $this->server( 18 );
		$connector = $this->connector( $server );
		$connection = $this->connection( $server );
		$connector->send( $connection, $this->item( EntityType::Order, '10490' ), $this->orderPayload() );
		$before = count( $server->requests );
		$connector->send( $connection, $this->item( EntityType::Order, '10491' ), $this->orderPayload() );
		$this->assertSame( 1, count( array_filter( $server->services, static fn( string $s ): bool => 'common.version' === $s ) ) );
		$this->assertSame( 1, count( array_filter( $server->services, static fn( string $s ): bool => 'common.authenticate' === $s ) ) );
		$this->assertGreaterThan( $before, count( $server->requests ) );
	}

	public function test_the_database_service_is_never_called(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$this->connector( $server )->test( $this->connection( $server ) );
		foreach ( $server->services as $service ) {
			$this->assertStringStartsNotWith( 'db.', $service );
		}
	}

	public function test_an_entity_the_connector_does_not_send_is_refused(): void {
		$server = $this->server( 18 );
		$result = $this->connector( $server )->send( $this->connection( $server ), $this->item( EntityType::Stock, '5' ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}
}
