<?php
/**
 * Reading the mapped payload and the connection options.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\OdooAmounts;
use Estasoft\SyncEngine\Connectors\Odoo\OdooOptions;
use Estasoft\SyncEngine\Connectors\Odoo\OdooOrderPayload;
use Estasoft\SyncEngine\Connectors\Odoo\OdooPayloadReader;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class OdooPayloadTest extends OdooTestCase {

	private function parse( array $payload, array $options = array() ): OdooOrderPayload|ConnectorError {
		return OdooOrderPayload::parse( $payload, $this->item( EntityType::Order, '10490' ), new OdooOptions( $options ), new OdooAmounts() );
	}

	public function test_a_complete_payload_is_read_into_typed_values(): void {
		$payload = $this->parse( $this->orderPayload( array( 'payment_term' => ' Net 30 ', 'warehouse' => 'DXB', 'note' => ' Gift ' ) ) );
		$this->assertInstanceOf( OdooOrderPayload::class, $payload );
		$this->assertSame( 'WC-10490', $payload->clientRef );
		$this->assertSame( 'AED', $payload->currency );
		$this->assertSame( 'noor@example.com', $payload->partner['email'] );
		$this->assertSame( 'Net 30', $payload->paymentTerm );
		$this->assertSame( 'DXB', $payload->warehouse );
		$this->assertSame( 'Gift', $payload->note );
		$this->assertSame( '2026-11-02 07:40:00', $payload->dateOrder );
		$this->assertSame( 2.0, $payload->lines[0]['quantity'] );
		$this->assertSame( 21900, $payload->lines[0]['price_minor'] );
		$this->assertSame( array( 'VAT 5% (AE)' ), $payload->lines[0]['tax'] );
		$this->assertTrue( $payload->taxesMapped );
		$this->assertSame( 'shipping', $payload->extras[0]['kind'] );
		$this->assertSame( 2500, $payload->extras[0]['price_minor'] );
	}

	public function test_the_flow_defaults_confirm_only(): void {
		$payload = $this->parse( $this->orderPayload() );
		$this->assertTrue( $payload->confirm );
		$this->assertSame( 'none', $payload->invoice );
		$this->assertFalse( $payload->registerPayment );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool,3:string,4:bool}>
	 */
	public static function provide_flows(): array {
		return array(
			'order only'              => array( array(), array(), true, 'none', false ),
			'invoice option'          => array( array( 'documents' => 'order_invoice' ), array(), true, 'post', false ),
			'invoice option as draft' => array( array( 'documents' => 'order_invoice', 'invoice_state' => 'draft' ), array(), true, 'draft', false ),
			'payment option'          => array( array( 'documents' => 'order_invoice_payment', 'invoice_state' => 'draft' ), array(), true, 'post', true ),
			'rule beats the option'   => array( array( 'documents' => 'order_invoice' ), array( 'invoice' => 'none' ), true, 'none', false ),
			'rule asks for draft'     => array( array(), array( 'invoice' => 'draft' ), true, 'draft', false ),
			'rule asks for payment'   => array( array(), array( 'register_payment' => 'yes' ), true, 'post', true ),
			'confirm off'             => array( array(), array( 'confirm' => 'false' ), false, 'none', false ),
			'invoice forces confirm'  => array( array(), array( 'confirm' => false, 'invoice' => 'post' ), true, 'post', false ),
			'nonsense is ignored'     => array( array( 'documents' => 'everything' ), array( 'invoice' => 'maybe', 'confirm' => 'perhaps' ), true, 'none', false ),
		);
	}

	/**
	 * @dataProvider provide_flows
	 *
	 * @param array<string,mixed> $options  Connection options.
	 * @param array<string,mixed> $flow     Flow values from the mapping.
	 * @param bool                $confirm  Expected.
	 * @param string              $invoice  Expected.
	 * @param bool                $payment  Expected.
	 */
	public function test_the_document_flow_comes_from_the_mapping_first_then_the_options( array $options, array $flow, bool $confirm, string $invoice, bool $payment ): void {
		$payload = $this->parse( $this->orderPayload( array( 'flow' => $flow ) ), $options );
		$this->assertSame( $confirm, $payload->confirm );
		$this->assertSame( $invoice, $payload->invoice );
		$this->assertSame( $payment, $payload->registerPayment );
	}

	public function test_discounts_are_negative_whatever_sign_they_arrive_with(): void {
		foreach ( array( 5000, -5000 ) as $amount ) {
			$payload = $this->parse( $this->orderPayload( array( 'discount' => array( 'total' => $amount ) ) ) );
			$this->assertSame( -5000, $payload->extras[1]['price_minor'] );
			$this->assertSame( 'discount', $payload->extras[1]['kind'] );
		}
	}

	public function test_several_fees_become_several_lines(): void {
		$payload = $this->parse( $this->orderPayload( array( 'fee' => array( array( 'name' => 'Gift wrap', 'total' => 1000 ), array( 'name' => '', 'total' => 300 ), array( 'name' => 'Free', 'total' => 0 ), 'junk' ) ) ) );
		$fees = array_values( array_filter( $payload->extras, static fn( array $e ): bool => 'fee' === $e['kind'] ) );
		$this->assertCount( 2, $fees );
		$this->assertSame( 'Gift wrap', $fees[0]['name'] );
		$this->assertSame( 'Fee', $fees[1]['name'] );
	}

	public function test_lines_that_are_not_arrays_are_skipped_and_a_payload_of_only_those_has_no_lines(): void {
		$payload = $this->parse( $this->orderPayload( array( 'order_line' => array( 'junk', null, 5 ) ) ) );
		$this->assertInstanceOf( ConnectorError::class, $payload );
		$this->assertSame( 'order_line', $payload->context['field'] );
	}

	public function test_a_line_may_name_its_product_as_sku_or_product(): void {
		$payload = $this->parse( $this->orderPayload( array( 'order_line' => array( array( 'sku' => 'A-1', 'quantity' => '2.5', 'price_unit' => '100' ) ) ) ) );
		$this->assertSame( 'A-1', $payload->lines[0]['sku'] );
		$this->assertSame( 2.5, $payload->lines[0]['quantity'] );
		$this->assertNull( $payload->lines[0]['tax'] );
		$this->assertFalse( $payload->taxesMapped );
	}

	public function test_the_discount_percent_is_kept_between_zero_and_a_hundred(): void {
		$payload = $this->parse( $this->orderPayload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => 1, 'discount' => 150 ), array( 'product' => 'B', 'quantity' => 1, 'price_unit' => 1, 'discount' => -5 ) ) ) ) );
		$this->assertSame( 100.0, $payload->lines[0]['discount'] );
		$this->assertSame( 0.0, $payload->lines[1]['discount'] );
	}

	public function test_reader_text_ignores_everything_that_is_not_a_scalar(): void {
		$this->assertSame( 'x', OdooPayloadReader::text( ' x ' ) );
		$this->assertSame( '12', OdooPayloadReader::text( 12 ) );
		$this->assertSame( '', OdooPayloadReader::text( true ) );
		$this->assertSame( '', OdooPayloadReader::text( array( 'a' ) ) );
		$this->assertSame( '', OdooPayloadReader::text( null ) );
	}

	public function test_reader_minor_accepts_whole_numbers_only(): void {
		$this->assertSame( 21900, OdooPayloadReader::minor( 21900 ) );
		$this->assertSame( 21900, OdooPayloadReader::minor( 21900.0 ) );
		$this->assertSame( -5, OdooPayloadReader::minor( '-5' ) );
		$this->assertNull( OdooPayloadReader::minor( 219.5 ) );
		$this->assertNull( OdooPayloadReader::minor( '219.00' ) );
		$this->assertNull( OdooPayloadReader::minor( 'abc' ) );
		$this->assertNull( OdooPayloadReader::minor( null ) );
		$this->assertNull( OdooPayloadReader::minor( '12345678901234567890' ) );
	}

	public function test_reader_tax_codes_drop_blanks_and_repeats(): void {
		$this->assertSame( array( 'a', 'b' ), OdooPayloadReader::taxCodes( array( 'a', '', 'b', 'a', null, array( 'x' ) ) ) );
		$this->assertSame( array(), OdooPayloadReader::taxCodes( '' ) );
		$this->assertSame( array( '3' ), OdooPayloadReader::taxCodes( 3 ) );
	}

	public function test_reader_flags_and_choices(): void {
		$this->assertTrue( OdooPayloadReader::flag( 'Yes', false ) );
		$this->assertFalse( OdooPayloadReader::flag( 'off', true ) );
		$this->assertTrue( OdooPayloadReader::flag( array(), true ) );
		$this->assertSame( 'post', OdooPayloadReader::choice( ' POST ', array( 'none', 'post' ), 'none' ) );
		$this->assertSame( 'none', OdooPayloadReader::choice( 'x', array( 'none', 'post' ), 'none' ) );
	}

	public function test_options_read_both_the_options_array_and_the_top_level_config(): void {
		$connection = $this->connection(
			$this->server(),
			array(
				'documents' => 'order_invoice',
				'options'   => array( 'documents' => 'order_invoice_payment', 'call_interval_ms' => '250' ),
			)
		);
		$options = OdooOptions::fromConnection( $connection );
		$this->assertSame( 'order_invoice_payment', $options->text( 'documents' ), 'The options array wins.' );
		$this->assertSame( 250, $options->integer( 'call_interval_ms', 1000, 0, 10000 ) );

		$flat = OdooOptions::fromConnection( $this->connection( $this->server(), array( 'documents' => 'order_invoice', 'options' => null ) ) );
		$this->assertSame( 'order_invoice', $flat->text( 'documents' ) );
	}

	public function test_options_fall_back_to_safe_defaults(): void {
		$options = new OdooOptions( array( 'customer_policy' => 'whatever', 'call_interval_ms' => 'fast', 'create_service_products' => 'maybe' ) );
		$this->assertSame( 'match_or_create', $options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) );
		$this->assertSame( 1000, $options->integer( 'call_interval_ms', 1000, 0, 10000 ) );
		$this->assertFalse( $options->flag( 'create_service_products', true ) );
		$this->assertTrue( $options->flag( 'absent', true ) );
		$this->assertSame( 'SHIPPING', $options->text( 'shipping_product', 'SHIPPING' ) );
		$this->assertSame( 10000, ( new OdooOptions( array( 'call_interval_ms' => 99999 ) ) )->integer( 'call_interval_ms', 1000, 0, 10000 ) );
		$this->assertSame( 0, ( new OdooOptions( array( 'call_interval_ms' => -5 ) ) )->integer( 'call_interval_ms', 1000, 0, 10000 ) );
	}

	public function test_a_missing_email_names_the_order_in_the_message(): void {
		$payload = $this->parse( $this->orderPayload( array( 'partner' => array( 'name' => 'X' ) ) ) );
		$this->assertSame( ErrorCode::Map003, $payload->code );
		$this->assertSame( 'Customer email is missing on order #10490.', $payload->message() );
	}
}
