<?php
/**
 * The rule engine: ordering, conditions, required values, lists, Arabic (AS-17), money (AS-18).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase {

	private function engine(): RuleEngine {
		return new RuleEngine( TransformRegistry::standard() );
	}

	private function t( string $type, array $args = array() ): array {
		return Presets::transform( $type, $args );
	}

	public function test_a_plain_rule_copies_a_value_to_its_target(): void {
		$output = $this->engine()->apply(
			array( Presets::rule( 'r1', 'reference', 'order.client_ref' ) ),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( array( 'reference' => 'WC-10490' ), $output->payload );
		$this->assertSame( array(), $output->changed, 'A value copied as it is is not "changed".' );
	}

	public function test_targets_can_be_nested(): void {
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'partner.address.city', 'billing.city' ),
				Presets::rule( 'r2', 'partner.address.zip', 'billing.postcode' ),
				Presets::rule( 'r3', 'partner.name', 'billing.first_name' ),
			),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame(
			array(
				'partner' => array(
					'address' => array( 'city' => 'Dubai' ),
					'name'    => 'نور',
				),
			),
			$output->payload,
			'The empty postcode is left out.'
		);
	}

	public function test_rules_run_in_order_and_a_later_rule_overwrites_an_earlier_one(): void {
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'note', array( 'const' => 'first' ) ),
				Presets::rule( 'r2', 'note', array( 'const' => 'second' ) ),
			),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( 'second', $output->payload['note'] );
	}

	public function test_a_constant_source(): void {
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'company', array( 'const' => 'Cedar & Stone' ) ),
				Presets::rule( 'r2', 'draft', array( 'const' => false ) ),
				Presets::rule( 'r3', 'priority', array( 'const' => 0 ) ),
			),
			new CanonicalOrder( array() )
		);
		$this->assertSame( array( 'company' => 'Cedar & Stone', 'draft' => false, 'priority' => 0 ), $output->payload );
	}

	public function test_several_sources_are_passed_as_a_list_and_joined(): void {
		$output = $this->engine()->apply(
			array( Presets::rule( 'r1', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( $this->t( 'join', array( 'separator' => ' ' ) ), $this->t( 'trim' ) ) ) ),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( 'نور المنصوري', $output->payload['partner']['name'] );
		$this->assertSame( array( 'partner.name' ), $output->changed );
	}

	public function test_transforms_run_in_order(): void {
		$output = $this->engine()->apply(
			array( Presets::rule( 'r1', 'email', 'billing.email', array( $this->t( 'trim' ), $this->t( 'case', array( 'mode' => 'lower' ) ) ) ) ),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( 'noor@example.com', $output->payload['email'] );
	}

	public function test_empty_results_are_left_out_but_zero_and_false_are_kept(): void {
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'company', 'billing.company' ),
				Presets::rule( 'r2', 'paid_at', 'order.paid_at' ),
				Presets::rule( 'r3', 'discount', 'order.discount_total' ),
				Presets::rule( 'r4', 'is_cod', 'order.payment.is_cod' ),
				Presets::rule( 'r5', 'guest', 'customer.is_guest' ),
			),
			new CanonicalOrder( Orders::dubai( array( 'order' => array( 'payment' => array( 'is_cod' => false ) ) ) ) )
		);
		$this->assertSame( array( 'discount' => 0, 'is_cod' => false, 'guest' => true ), $output->payload );
	}

	public function test_the_when_condition_compares_text_including_booleans(): void {
		$rules = array(
			Presets::rule( 'r1', 'registered_only', array( 'const' => 'x' ), array(), false, null, array( 'source' => 'customer.is_guest', 'equals' => 'false' ) ),
			Presets::rule( 'r2', 'guest_only', array( 'const' => 'y' ), array(), false, null, array( 'source' => 'customer.is_guest', 'equals' => 'true' ) ),
			Presets::rule( 'r3', 'cod_only', array( 'const' => 'z' ), array(), false, null, array( 'source' => 'order.payment.method_id', 'equals' => 'cod' ) ),
		);
		$output = $this->engine()->apply( $rules, new CanonicalOrder( Orders::dubai() ) );
		$this->assertSame( array( 'guest_only' => 'y', 'cod_only' => 'z' ), $output->payload );
		$this->assertCount( 1, $output->notes, 'The skipped rule leaves a note.' );
		$this->assertSame( 'registered_only', $output->notes[0]['target'] );
	}

	public function test_a_required_rule_with_a_value_passes(): void {
		$output = $this->engine()->apply(
			array( Presets::rule( 'r1', 'email', 'billing.email', array( $this->t( 'trim' ) ), true, 'SYNC-MAP-003' ) ),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( array( 'email' => 'Noor@Example.com' ), $output->payload );
	}

	public function test_a_required_rule_without_a_value_raises_its_error_code_before_anything_is_sent(): void {
		$rules = array( Presets::rule( 'r1', 'email', 'billing.email', array( $this->t( 'trim' ) ), true, 'SYNC-MAP-003' ) );
		try {
			$this->engine()->apply( $rules, new CanonicalOrder( Orders::dubai( array( 'billing' => array( 'email' => '  ' ) ) ) ) );
			$this->fail( 'A missing required value must stop the order.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map003, $e->errorCode );
			$this->assertSame( 'r1', $e->ruleId );
			$this->assertSame( 'email', $e->target );
			$this->assertSame( 'Customer email is missing on order #10490.', $e->toConnectorError()->message() );
		}
	}

	public function test_a_required_rule_without_an_error_code_uses_val_002(): void {
		try {
			$this->engine()->apply( array( Presets::rule( 'r1', 'x', 'order.paid_at', array(), true ) ), new CanonicalOrder( Orders::dubai() ) );
			$this->fail( 'Expected a failure.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Val002, $e->errorCode );
			$this->assertSame( 'x', $e->context['field'] );
		}
	}

	public function test_an_unknown_error_code_falls_back_to_val_002(): void {
		try {
			$this->engine()->apply( array( Presets::rule( 'r1', 'x', 'order.paid_at', array(), true, 'SYNC-NOPE-1' ) ), new CanonicalOrder( array() ) );
			$this->fail( 'Expected a failure.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Val002, $e->errorCode );
		}
	}

	public function test_apply_stops_at_the_first_failure_but_apply_all_collects_every_one(): void {
		$rules = array(
			Presets::rule( 'r1', 'a', 'order.paid_at', array(), true, 'SYNC-MAP-003' ),
			Presets::rule( 'r2', 'b', 'billing.company', array(), true, 'SYNC-VAL-002' ),
			Presets::rule( 'r3', 'c', 'order.number' ),
		);
		$order  = new CanonicalOrder( Orders::dubai() );
		$output = $this->engine()->applyAll( $rules, $order );
		$this->assertFalse( $output->isValid() );
		$this->assertCount( 2, $output->errors );
		$this->assertSame( array( 'c' => '10490' ), $output->payload, 'Rules after a failure still run.' );
		$this->expectException( MappingException::class );
		$this->engine()->apply( $rules, $order );
	}

	public function test_a_required_transform_in_the_middle_of_a_pipeline_stops_the_order(): void {
		$rules = array( Presets::rule( 'r1', 'phone', 'billing.phone', array( $this->t( 'default', array( 'from' => 'customer.phone' ) ), $this->t( 'required', array( 'error_code' => 'SYNC-VAL-002' ) ) ) ) );
		$order = new CanonicalOrder( Orders::dubai( array( 'billing' => array( 'phone' => '' ), 'customer' => array( 'phone' => '' ) ) ) );
		$this->expectException( MappingException::class );
		$this->engine()->apply( $rules, $order );
	}

	public function test_list_rules_line_up_by_row(): void {
		$order                     = Orders::dubai();
		$order['lines'][]          = array( 'product_id' => 502, 'variation_id' => 0, 'sku' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 3, 'unit_price' => 4500, 'line_total' => 13500, 'line_tax' => 675, 'tax_rate' => 5, 'tax_class' => '' );
		$rules                     = array(
			Presets::rule( 'r1', 'order_line[].sku', 'lines[].sku', array( $this->t( 'trim' ) ) ),
			Presets::rule( 'r2', 'order_line[].qty', 'lines[].quantity' ),
			Presets::rule( 'r3', 'order_line[].price', 'lines[].unit_price' ),
			Presets::rule( 'r4', 'order_line[].label', array( 'lines[].name', 'lines[].sku' ), array( $this->t( 'join', array( 'separator' => ' / ' ) ) ) ),
		);
		$output                    = $this->engine()->apply( $rules, new CanonicalOrder( $order ) );
		$this->assertSame(
			array(
				array( 'sku' => 'CS-BOWL-02', 'qty' => 2, 'price' => 21900, 'label' => 'Stoneware bowl set / CS-BOWL-02' ),
				array( 'sku' => 'CS-MUG-01', 'qty' => 3, 'price' => 4500, 'label' => 'Mug / CS-MUG-01' ),
			),
			$output->payload['order_line']
		);
	}

	public function test_a_list_rule_with_single_value_sources_applies_to_every_row_made_before_it(): void {
		$order            = Orders::dubai();
		$order['lines'][] = array( 'sku' => 'B', 'quantity' => 1 );
		$output           = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'order_line[].sku', 'lines[].sku', array( $this->t( 'trim' ) ) ),
				Presets::rule( 'r2', 'order_line[].currency', 'order.currency' ),
			),
			new CanonicalOrder( $order )
		);
		$this->assertSame( 'AED', $output->payload['order_line'][0]['currency'] );
		$this->assertSame( 'AED', $output->payload['order_line'][1]['currency'] );
	}

	public function test_rows_with_no_data_are_dropped_and_the_rest_keep_their_order(): void {
		$order          = Orders::dubai();
		$order['lines'] = array(
			array( 'sku' => 'A', 'note' => '' ),
			array( 'sku' => '', 'note' => '' ),
			array( 'sku' => 'C', 'note' => 'x' ),
		);
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'l[].sku', 'lines[].sku' ),
				Presets::rule( 'r2', 'l[].note', 'lines[].note' ),
			),
			new CanonicalOrder( $order )
		);
		$this->assertSame( array( array( 'sku' => 'A' ), array( 'sku' => 'C', 'note' => 'x' ) ), $output->payload['l'] );
	}

	public function test_an_order_without_lines_gives_no_list(): void {
		$data          = Orders::dubai();
		$data['lines'] = array();
		$output        = $this->engine()->apply( array( Presets::rule( 'r1', 'l[].sku', 'lines[].sku' ) ), new CanonicalOrder( $data ) );
		$this->assertArrayNotHasKey( 'l', $output->payload );
		$this->assertCount( 1, $output->notes );
	}

	public function test_a_list_source_with_a_plain_target_gives_a_list_value(): void {
		$order            = Orders::dubai();
		$order['lines'][] = array( 'sku' => ' B ' );
		$output           = $this->engine()->apply( array( Presets::rule( 'r1', 'skus', 'lines[].sku', array( $this->t( 'trim' ) ) ) ), new CanonicalOrder( $order ) );
		$this->assertSame( array( 'CS-BOWL-02', 'B' ), $output->payload['skus'] );
	}

	public function test_a_list_source_can_be_joined_into_one_value(): void {
		$order            = Orders::dubai( array( 'coupons' => array( array( 'code' => 'A' ), array( 'code' => 'B' ) ) ) );
		$output           = $this->engine()->apply( array( Presets::rule( 'r1', 'coupon_codes', 'coupons[].code', array( $this->t( 'join', array( 'separator' => ', ' ) ) ) ) ), new CanonicalOrder( $order ) );
		$this->assertSame( 'A, B', $output->payload['coupon_codes'] );
	}

	public function test_a_required_list_rule_fails_when_one_row_is_empty(): void {
		$order          = Orders::dubai();
		$order['lines'] = array( array( 'sku' => 'A' ), array( 'sku' => '' ) );
		$this->expectException( MappingException::class );
		$this->engine()->apply( array( Presets::rule( 'r1', 'l[].sku', 'lines[].sku', array(), true, 'SYNC-MAP-001' ) ), new CanonicalOrder( $order ) );
	}

	public function test_lookups_are_row_aware(): void {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'ae' === strtolower( $country ) && '5' === $rateKey ? 'VAT5' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return new ExternalRef( (string) ( $productId * 10 ), $sku );
			}
		};
		$order            = Orders::dubai();
		$order['lines'][] = array( 'product_id' => 502, 'variation_id' => 0, 'sku' => 'M', 'quantity' => 1, 'tax_rate' => 5, 'tax_class' => '' );
		$output           = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'l[].product', 'lines[].sku', array( $this->t( 'sku_match' ) ) ),
				Presets::rule( 'r2', 'l[].tax', 'lines[].tax_rate', array( $this->t( 'tax_lookup' ) ) ),
			),
			new CanonicalOrder( $order ),
			new MappingEnvironment( 1, 'Odoo', $taxes, $products )
		);
		$this->assertSame(
			array(
				array( 'product' => '5010', 'tax' => 'VAT5' ),
				array( 'product' => '5020', 'tax' => 'VAT5' ),
			),
			$output->payload['l']
		);
	}

	public function test_a_lookup_failure_inside_a_list_rule_is_a_mapping_failure(): void {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return null;
			}
		};
		try {
			$this->engine()->apply( array( Presets::rule( 'r1', 'l[].tax', 'lines[].tax_rate', array( $this->t( 'tax_lookup' ) ) ) ), new CanonicalOrder( Orders::dubai() ), new MappingEnvironment( 1, 'Odoo', $taxes ) );
			$this->fail( 'An unmapped tax rate must stop the order.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map002, $e->errorCode );
		}
	}

	public function test_changed_targets_are_reported_by_rule_target(): void {
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'r1', 'reference', 'order.client_ref' ),
				Presets::rule( 'r2', 'partner.phone', 'billing.phone', array( $this->t( 'phone_e164', array( 'default_country' => 'AE' ) ) ) ),
				Presets::rule( 'r3', 'partner.state', 'billing.state', array( $this->t( 'iso_state' ) ) ),
				Presets::rule( 'r4', 'lines[].x', 'lines[].sku' ),
				Presets::rule( 'r5', 'order_line[].sku', 'lines[].sku', array( $this->t( 'trim' ) ) ),
			),
			new CanonicalOrder( Orders::dubai() )
		);
		$this->assertSame( array( 'partner.phone', 'partner.state', 'order_line[].sku' ), $output->changed );
	}

	public function test_warnings_are_collected_with_the_rule_that_caused_them(): void {
		$output = $this->engine()->apply(
			array( Presets::rule( 'phone', 'partner.phone', 'billing.phone', array( $this->t( 'phone_e164', array( 'default_country' => 'AE' ) ) ) ) ),
			new CanonicalOrder( Orders::dubai( array( 'billing' => array( 'phone' => '12' ) ) ) )
		);
		$this->assertArrayNotHasKey( 'partner', $output->payload, 'An unreadable phone number is not sent as garbage.' );
		$this->assertCount( 1, $output->warnings );
		$this->assertSame( 'phone', $output->warnings[0]['rule'] );
		$this->assertSame( 'partner.phone', $output->warnings[0]['target'] );
		$this->assertCount( 1, $output->warningMessages() );
	}

	public function test_an_unknown_transform_is_a_clear_mapping_failure(): void {
		$output = $this->engine()->applyAll( array( Presets::rule( 'r1', 'x', 'order.number', array( $this->t( 'eval', array( 'code' => 'phpinfo()' ) ) ) ) ), new CanonicalOrder( Orders::dubai() ) );
		$this->assertCount( 1, $output->errors );
		$this->assertSame( ErrorCode::Val002, $output->errors[0]->errorCode );
	}

	public function test_bad_transform_arguments_are_a_clear_mapping_failure(): void {
		$output = $this->engine()->applyAll( array( Presets::rule( 'r1', 'x', 'order.number', array( $this->t( 'case', array( 'mode' => 'shout' ) ) ) ) ), new CanonicalOrder( Orders::dubai() ) );
		$this->assertCount( 1, $output->errors );
		$this->assertSame( 'r1', $output->errors[0]->ruleId );
	}

	public function test_malformed_rules_are_ignored_without_a_crash(): void {
		$output = $this->engine()->applyAll( array( 'nope', 5, array( 'id' => 'r1' ), Presets::rule( 'r2', 'ok', 'order.number' ) ), new CanonicalOrder( Orders::dubai() ) );
		$this->assertSame( '10490', $output->payload['ok'] );
	}

	public function test_arabic_text_round_trips_unchanged(): void {
		$rules  = array(
			Presets::rule( 'name', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( $this->t( 'join', array( 'separator' => ' ' ) ), $this->t( 'trim' ) ) ),
			Presets::rule( 'street', 'partner.street', 'billing.address_1', array( $this->t( 'trim' ) ) ),
			Presets::rule( 'note', 'note', 'order.note', array( $this->t( 'trim' ) ) ),
		);
		$order  = Orders::dubai(
			array(
				'billing' => array( 'address_1' => 'شارع الشيخ زايد، برج الإمارات، الطابق ٢٣' ),
				'order'   => array( 'note' => 'يرجى التغليف كهدية 🎁' ),
			)
		);
		$output = $this->engine()->apply( $rules, new CanonicalOrder( $order ) );
		$this->assertSame( 'نور المنصوري', $output->payload['partner']['name'] );
		$this->assertSame( 'شارع الشيخ زايد، برج الإمارات، الطابق ٢٣', $output->payload['partner']['street'] );
		$this->assertSame( 'يرجى التغليف كهدية 🎁', $output->payload['note'] );

		$encoded = wp_json_encode( $output->payload, JSON_UNESCAPED_UNICODE );
		$this->assertSame( $output->payload, json_decode( (string) $encoded, true ), 'JSON keeps the Arabic text exactly.' );
	}

	public function test_kuwaiti_dinar_amounts_stay_exact_through_the_engine(): void {
		$order  = Orders::dubai( array( 'order' => array( 'currency' => 'KWD', 'total' => 12345 ) ) );
		$output = $this->engine()->apply(
			array(
				Presets::rule( 'total', 'total', 'order.total' ),
				Presets::rule( 'custom', 'extra_fee', array( 'const' => '1.250' ), array( $this->t( 'money_minor' ) ) ),
			),
			new CanonicalOrder( $order )
		);
		$this->assertSame( 12345, $output->payload['total'] );
		$this->assertSame( 1250, $output->payload['extra_fee'] );
		$this->assertIsInt( $output->payload['extra_fee'] );
	}

	public function test_the_neutral_preset_builds_a_complete_payload(): void {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'VAT 5% (AE)' : null;
			}
		};
		$output = $this->engine()->apply( Presets::neutralOrders(), new CanonicalOrder( Orders::dubai() ), new MappingEnvironment( 1, 'Odoo', $taxes ) );
		$this->assertSame(
			array(
				'reference' => 'WC-10490',
				'customer'  => array(
					'email'   => 'noor@example.com',
					'name'    => 'نور المنصوري',
					'phone'   => '+971501234567',
					'country' => 'AE',
					'state'   => 'AE-DU',
				),
				'placed_at'    => '2026-11-02 07:34:00',
				'currency'     => 'AED',
				'payment_term' => 'Pay on delivery',
				'note'         => 'Gift wrap please',
				'lines'        => array(
					array( 'sku' => 'CS-BOWL-02', 'quantity' => 2, 'unit_price' => 21900, 'tax_code' => 'VAT 5% (AE)' ),
				),
				'shipping'     => array( array( 'amount' => 2500 ) ),
				'total'        => 48615,
			),
			$output->payload
		);
	}

	public function test_the_engine_does_not_change_the_order(): void {
		$order  = new CanonicalOrder( Orders::dubai() );
		$before = $order->toArray();
		$this->engine()->apply( Presets::neutralOrders(), $order, new MappingEnvironment( 1, 'Odoo', new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'X';
			}
		} ) );
		$this->assertSame( $before, $order->toArray() );
	}
}
