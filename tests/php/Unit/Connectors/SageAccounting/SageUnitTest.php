<?php
/**
 * The small pure parts of the Sage connector: amounts, tax, payload reading, error shapes and the document bodies.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Connectors\SageAccounting\SageAmounts;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageApi;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageBuilder;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageBusiness;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageContacts;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageErrors;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageInvoices;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageOptions;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageOrderPayload;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageRefundPayload;
use Estasoft\SyncEngine\Connectors\SageAccounting\SageTotals;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

final class SageUnitTest extends TestCase {

	public function test_amounts_are_decimal_strings_by_the_currency_exponent(): void {
		$amounts = new SageAmounts();
		$this->assertSame( '19.99', $amounts->text( 1999, 'GBP' ) );
		$this->assertSame( '12.345', $amounts->text( 12345, 'KWD' ) );
		$this->assertSame( '-3.00', $amounts->text( -300, 'GBP' ) );
		$this->assertSame( '0.05', $amounts->text( 5, 'GBP' ) );
		$this->assertSame( 5997, $amounts->fromNumber( '59.97', 'GBP' ) );
		$this->assertSame( -5997, $amounts->fromNumber( '-59.97', 'GBP' ) );
		$this->assertNull( $amounts->fromNumber( 'abc', 'GBP' ) );
		$this->assertTrue( $amounts->agree( 1000, 1001 ) );
		$this->assertFalse( $amounts->agree( 1000, 1002 ) );
	}

	public function test_tax_is_worked_out_in_whole_minor_units_half_away_from_zero(): void {
		$amounts = new SageAmounts();
		$this->assertSame( 1680, $amounts->taxOn( 8400, 200000 ), '20 percent of 84.00.' );
		$this->assertSame( 50, $amounts->taxOn( 999, 50000 ), '5 percent of 9.99 is 0.4995, which rounds to 0.50.' );
		$this->assertSame( -60, $amounts->taxOn( -300, 200000 ) );
		$this->assertSame( -1, $amounts->taxOn( -5, 200000 ), '-0.01 rounds away from zero.' );
		$this->assertSame( 1, $amounts->taxOn( 5, 200000 ) );
		$this->assertSame( 0, $amounts->taxOn( 4, 100000 ), '10 percent of 0.04 is 0.004.' );
		$this->assertSame( 1050, $amounts->percentOf( 8400, 12.5 ) );
		$this->assertSame( 0, $amounts->percentOf( 8400, 0.0 ) );
		$this->assertSame( -3, $amounts->percentOf( -25, 10.0 ) );
	}

	public function test_a_list_answer_is_read_from_items_or_as_a_plain_list(): void {
		$this->assertSame( array( array( 'a' => 1 ) ), SageApi::items( new ApiResponse( 200, '{"$items":[{"a":1},"x"],"$next":null,"$total":1}' ) ) );
		$this->assertSame( array( array( 'id' => 'x' ) ), SageApi::items( new ApiResponse( 200, '[{"id":"x"}]' ) ) );
		$this->assertSame( array(), SageApi::items( new ApiResponse( 200, '{}' ) ) );
	}

	public function test_the_business_and_country_come_from_the_config_in_a_safe_shape(): void {
		$kit = \Estasoft\SyncEngine\Tests\Support\ApiTestKit::make();
		try {
			$this->assertSame( 'abc-123_X', SageBusiness::businessOf( $kit->unsaved( 'sage-accounting', array( 'business_id' => ' abc-123_X ' ), array() ) ) );
			$this->assertSame( '', SageBusiness::businessOf( $kit->unsaved( 'sage-accounting', array( 'business_id' => 'a b/c' ), array() ) ), 'No slash or space in a header value.' );
			$this->assertSame( 'ie', SageBusiness::countryOf( $kit->unsaved( 'sage-accounting', array( 'country' => 'IE' ), array() ) ) );
			$this->assertSame( 'gb', SageBusiness::countryOf( $kit->unsaved( 'sage-accounting', array( 'country' => 'us' ), array() ) ) );
		} finally {
			$kit->cleanup();
		}
	}

	public function test_options_come_from_the_options_array_or_the_config_with_safe_defaults(): void {
		$options = new SageOptions( array( 'documents' => 'invoice_paid', 'due_days' => '9999', 'sales_ledger_account' => ' 4000 ', 'fee_ledger_account' => '4900', 'refund_allocation' => 'invoice' ) );
		$this->assertTrue( $options->pays() );
		$this->assertTrue( $options->releases() );
		$this->assertTrue( $options->allocates() );
		$this->assertSame( 365, $options->integer( 'due_days', 0, 0, 365 ) );
		$this->assertSame( '4000', $options->ledgerFor( 'sales' ) );
		$this->assertSame( '4900', $options->ledgerFor( 'fee' ) );
		$this->assertSame( '4000', $options->ledgerFor( 'discount' ), 'A kind with no account of its own uses the sales account.' );
		$draft = new SageOptions( array( 'documents' => 'invoice_draft' ) );
		$this->assertFalse( $draft->releases() );
		$this->assertFalse( $draft->pays() );
		$this->assertSame( 'invoice', ( new SageOptions( array( 'documents' => 'nonsense' ) ) )->documents() );
		$this->assertFalse( ( new SageOptions() )->allocates() );
	}

	private function item(): Item {
		return new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing );
	}

	public function test_the_order_payload_names_the_first_thing_that_is_wrong(): void {
		$base = array(
			'date_order' => '2026-11-01',
			'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
			'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
		);
		$this->assertInstanceOf( SageOrderPayload::class, SageOrderPayload::parse( $base, $this->item() ) );

		$cases = array(
			'no email'      => array( array( 'partner' => array( 'name' => 'A' ) ) + $base, ErrorCode::Map003 ),
			'no name'       => array( array( 'partner' => array( 'email' => 'a@example.test' ) ) + $base, ErrorCode::Val002 ),
			'no date'       => array( array( 'date_order' => '' ) + $base, ErrorCode::Val002 ),
			'no lines'      => array( array( 'order_line' => array() ) + $base, ErrorCode::Val002 ),
			'no product'    => array( array( 'order_line' => array( array( 'quantity' => 1, 'price_unit' => 100 ) ) ) + $base, ErrorCode::Map001 ),
			'no quantity'   => array( array( 'order_line' => array( array( 'product' => 'X', 'price_unit' => 100 ) ) ) + $base, ErrorCode::Val002 ),
			'decimal price' => array( array( 'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => '1.50' ) ) ) + $base, ErrorCode::Val002 ),
			'two shipping'  => array( array( 'shipping' => array( 'total' => 100, 'tax' => array( 'A', 'B' ) ) ) + $base, ErrorCode::Val002 ),
		);
		foreach ( $cases as $name => $case ) {
			$result = SageOrderPayload::parse( $case[0], $this->item() );
			$this->assertInstanceOf( ConnectorError::class, $result, $name );
			$this->assertSame( $case[1], $result->code, $name );
		}
	}

	public function test_the_client_reference_always_comes_from_the_queue_item_and_the_excluded_total_is_taken_off(): void {
		$item    = new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing, attempt: 2 );
		$payload = SageOrderPayload::parse(
			array(
				'client_order_ref' => 'WC-EVIL',
				'date_order'       => '2026-11-01',
				'partner'          => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line'       => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
				'shipping'         => array( 'total' => 250, 'tax' => '' ),
				'total'            => 5000,
				'excluded_total'   => 1200,
			),
			$item
		);
		$this->assertSame( 'WC-9', $payload->clientRef );
		$this->assertSame( 3800, $payload->totalMinor );
		$this->assertSame( 250, $payload->shippingMinor );
		$this->assertSame( '', $payload->shippingTax );
		$this->assertSame( 2, $payload->attempt );
	}

	public function test_a_refund_payload_needs_the_order_it_belongs_to(): void {
		$item = new Item( 77, 1, EntityType::Refund, '77', 'refunded', 'order:9', 'k', ItemState::Syncing );
		$this->assertInstanceOf( ConnectorError::class, SageRefundPayload::parse( array( 'amount' => 100 ), $item ) );
		$refund = SageRefundPayload::parse( array( 'order_ref' => 'WC-9', 'amount' => 100 ), $item );
		$this->assertInstanceOf( SageRefundPayload::class, $refund );
		$this->assertSame( 'WC-77', $refund->clientRef );
	}

	public function test_errors_are_read_from_both_shapes_and_mapped(): void {
		$list = '[{"$severity":"error","$dataCode":"RecordInvalid","$message":"can\'t find contact","$source":"contact_id"},{"$severity":"error","$dataCode":"Other","$message":"second"}]';

		$this->assertSame( array( array( 'code' => 'RecordInvalid', 'message' => "can't find contact", 'source' => 'contact_id' ), array( 'code' => 'Other', 'message' => 'second', 'source' => '' ) ), SageErrors::entries( ( new ApiResponse( 422, $list ) )->json() ) );
		$this->assertCount( 1, SageErrors::entries( array( '$errors' => array( array( '$message' => 'x' ) ) ) ) );
		$this->assertSame( array(), SageErrors::entries( array( 'message' => 'plain' ) ) );

		$mapped = SageErrors::fromResponse( new ApiResponse( 422, $list ) );
		$this->assertSame( ErrorCode::Val002, $mapped->code );
		$this->assertSame( 'contact_id', $mapped->context['field'] );
		$this->assertSame( "can't find contact second", $mapped->context['reason'] );
		$this->assertSame( ErrorCode::Auth004, SageErrors::fromResponse( new ApiResponse( 403, '[]' ) )->code );
		$this->assertNull( SageErrors::fromResponse( new ApiResponse( 429, $list ) ), 'A 429 is left to the base.' );
		$this->assertNull( SageErrors::fromResponse( new ApiResponse( 500, $list ) ), 'A server error is left to the base.' );
		$this->assertNull( SageErrors::fromResponse( new ApiResponse( 401, $list ) ), 'A 401 is left to the sign-in refresh.' );
		$this->assertNull( SageErrors::fromResponse( new ApiResponse( 422, 'not json' ) ) );
		$tax = SageErrors::unknownTax( 'GB', 'GB_NOPE' );
		$this->assertSame( ErrorCode::Map002, $tax->code );
		$this->assertSame( 'GB_NOPE', $tax->context['rate'] );
	}

	public function test_the_total_check_names_a_wrong_currency_compares_the_size_and_tolerates_one_minor_unit(): void {
		$totals = new SageTotals( new SageAmounts() );
		$this->assertNull( $totals->check( array( 'currency' => array( 'id' => 'GBP' ), 'total_amount' => '10.01' ), 'GBP', 1000 ) );
		$this->assertNull( $totals->check( array( 'total_amount' => '10.00' ), 'GBP', 1000 ), 'No currency in the answer, none to compare.' );
		$this->assertNull( $totals->check( array( 'total_amount' => '99.00' ), 'GBP', null ), 'No expected total, nothing to compare.' );
		$wrong = $totals->check( array( 'currency' => array( 'id' => 'USD' ), 'total_amount' => '10.00' ), 'GBP', 1000 );
		$this->assertSame( ErrorCode::Val002, $wrong->code );
		$this->assertStringContainsString( 'USD', (string) $wrong->context['reason'] );
		$this->assertNotNull( $totals->check( array( 'total_amount' => '10.02' ), 'GBP', 1000 ) );
		$this->assertNotNull( $totals->check( array(), 'GBP', 1000 ), 'A missing total is reported, not skipped.' );
	}

	public function test_the_status_is_read_from_the_status_object_or_a_plain_value(): void {
		$this->assertSame( 'DRAFT', SageInvoices::status( array( 'status' => array( 'id' => 'draft' ) ) ) );
		$this->assertSame( 'PAID', SageInvoices::status( array( 'status' => 'PAID' ) ) );
		$this->assertSame( '', SageInvoices::status( array() ) );
	}

	public function test_an_address_uses_sages_field_names_and_cuts_long_values(): void {
		$cut = false;
		$this->assertSame( array(), SageContacts::address( array(), 'ACCOUNTS', $cut ) );
		$address = SageContacts::address( array( 'street' => str_repeat( 'S', 80 ), 'zip' => 'N1 9GU', 'country_id' => 'GB', 'state_id' => 'LND' ), 'DELIVERY', $cut );
		$this->assertSame( 'DELIVERY', $address['address_type_id'] );
		$this->assertSame( 50, mb_strlen( $address['address_line_1'] ) );
		$this->assertSame( 'N1 9GU', $address['postal_code'] );
		$this->assertSame( 'GB', $address['country_group_id'] );
		$this->assertSame( 'LND', $address['region'] );
		$this->assertTrue( $cut );
	}

	public function test_the_builder_works_the_tax_per_line_leaves_it_out_for_zero_and_names_what_is_missing(): void {
		$builder = new SageBuilder( new SageOptions( array() ), new SageAmounts() );
		$parsed  = SageOrderPayload::parse(
			array(
				'date_order' => '2026-11-01',
				'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line' => array(
					array( 'product' => 'X', 'name' => 'Thing', 'quantity' => 3, 'price_unit' => 1999, 'tax' => 'GB_STANDARD' ),
					array( 'product' => 'X', 'name' => 'Thing zero', 'quantity' => 1, 'price_unit' => 100, 'tax' => 'GB_ZERO' ),
				),
			),
			$this->item()
		);
		$rates    = array(
			'gb_standard' => array( 'id' => 'GB_STANDARD', 'rate' => 200000 ),
			'gb_zero'     => array( 'id' => 'GB_ZERO', 'rate' => 0 ),
		);
		$warnings = array();

		$body = $builder->invoice( $parsed, 'c-1', 'GBP', array( 'x' => array( 'id' => 'p-1' ) ), $rates, array( 'sales' => 'l-1' ), $warnings );

		$this->assertSame( 'DRAFT', $body['status_id'] );
		$this->assertSame( '11.99', $body['invoice_lines'][0]['tax_amount'], '3 x 19.99 = 59.97, 20 percent = 11.994.' );
		$this->assertArrayNotHasKey( 'tax_amount', $body['invoice_lines'][1] );
		$this->assertSame( 'p-1', $body['invoice_lines'][1]['product_id'] );

		$unknown = $builder->invoice( $parsed, 'c-1', 'GBP', array(), $rates, array( 'sales' => 'l-1' ), $warnings );
		$this->assertSame( ErrorCode::Map001, $unknown->code );
		$noRate = $builder->invoice( $parsed, 'c-1', 'GBP', array( 'x' => array( 'id' => 'p-1' ) ), array(), array( 'sales' => 'l-1' ), $warnings );
		$this->assertSame( ErrorCode::Map002, $noRate->code );

		$this->assertSame( array( 'codes' => array( 'GB_STANDARD', 'GB_ZERO' ), 'none' => false ), SageBuilder::taxCodesUsed( $parsed->lines, $parsed->extras, 'GB_STANDARD' ), 'Each code once, the shipping code included.' );
		$this->assertSame( array( 'codes' => array(), 'none' => true ), SageBuilder::taxCodesUsed( array( array( 'tax' => '' ), array( 'tax' => null ) ), array(), null ) );
	}
}
