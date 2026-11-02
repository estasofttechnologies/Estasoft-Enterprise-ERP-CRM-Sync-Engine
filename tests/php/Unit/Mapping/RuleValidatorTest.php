<?php
/**
 * Validation of a rule set against a connector's fields (MAP-3), and the sanitiser.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleSanitizer;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use PHPUnit\Framework\TestCase;

final class RuleValidatorTest extends TestCase {

	/**
	 * Fields a connector might offer.
	 *
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	private function fields(): array {
		return array(
			array( 'path' => 'partner.name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'group' => 'Partner' ),
			array( 'path' => 'partner.email', 'label' => 'Email', 'type' => 'text', 'required' => false, 'group' => 'Partner' ),
			array( 'path' => 'order_line[].sku', 'label' => 'SKU', 'type' => 'text', 'required' => false, 'group' => 'Lines' ),
			array( 'path' => 'order_line[].qty', 'label' => 'Quantity', 'type' => 'number', 'required' => false, 'group' => 'Lines' ),
		);
	}

	private function engine(): RuleEngine {
		return new RuleEngine( TransformRegistry::standard() );
	}

	/**
	 * Codes of the problems found, with the rule they belong to.
	 *
	 * @param array<int,mixed>                  $rules Rules.
	 * @param array<int,array<string,mixed>>|null $fields Fields.
	 * @return array<int,string>
	 */
	private function problems( array $rules, ?array $fields = null ): array {
		return array_map(
			static fn( $error ): string => $error->ruleId . ':' . $error->field . ':' . $error->code,
			$this->engine()->validate( $rules, $fields ?? $this->fields() )
		);
	}

	public function test_a_good_rule_set_has_no_problems(): void {
		$rules = array(
			Presets::rule( 'name', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( Presets::transform( 'join' ) ), true, 'SYNC-VAL-002' ),
			Presets::rule( 'email', 'partner.email', 'billing.email', array( Presets::transform( 'trim' ) ), false, null, array( 'source' => 'customer.is_guest', 'equals' => 'false' ) ),
			Presets::rule( 'sku', 'order_line[].sku', 'lines[].sku' ),
			Presets::rule( 'qty', 'order_line[].qty', 'lines[0].quantity' ),
			Presets::rule( 'gift', 'partner.name', 'meta._gift_message' ),
			Presets::rule( 'fixed', 'partner.email', array( 'const' => 'sales@example.com' ) ),
		);
		$this->assertSame( array(), $this->problems( $rules ) );
	}

	public function test_an_empty_rule_set_is_valid_without_connector_fields_but_misses_required_ones_with_them(): void {
		$this->assertSame( array(), $this->problems( array(), array() ) );
		$this->assertSame( array( ':target:required_target_missing' ), $this->problems( array() ) );
	}

	public function test_unknown_target(): void {
		$this->assertContains( 'r1:target:target_unknown', $this->problems( array( Presets::rule( 'r1', 'partner.shoe_size', 'billing.email' ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
	}

	public function test_malformed_target(): void {
		$this->assertContains( 'r1:target:target_invalid', $this->problems( array( Presets::rule( 'r1', 'partner..name', 'billing.email' ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
		$this->assertContains( 'r1:target:target_invalid', $this->problems( array( Presets::rule( 'r1', 'a[].b[].c', 'lines[].sku' ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ), 'Only one list level in a target.' );
	}

	public function test_unknown_source_and_condition(): void {
		$problems = $this->problems(
			array(
				Presets::rule( 'r1', 'partner.name', 'billing.shoe_size' ),
				Presets::rule( 'r2', 'partner.email', array( 'billing.email', 'nope.field' ) ),
				Presets::rule( 'r3', 'partner.email', 'billing.email', array(), false, null, array( 'source' => 'nope.field', 'equals' => 'x' ) ),
			)
		);
		$this->assertContains( 'r1:source:source_unknown', $problems );
		$this->assertContains( 'r2:source:source_unknown', $problems );
		$this->assertContains( 'r3:when:when_unknown', $problems );
	}

	public function test_missing_source(): void {
		$this->assertContains( 'r1:source:source_invalid', $this->problems( array( array( 'id' => 'r1', 'target' => 'partner.name', 'transforms' => array() ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
		$this->assertContains( 'r1:source:source_invalid', $this->problems( array( Presets::rule( 'r1', 'partner.name', array( 'const' => array( 1 ) ) ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
	}

	public function test_unknown_transform_and_bad_arguments(): void {
		$problems = $this->problems(
			array(
				Presets::rule( 'r1', 'partner.name', 'billing.email', array( Presets::transform( 'trim' ), Presets::transform( 'exec', array( 'cmd' => 'ls' ) ) ) ),
				Presets::rule( 'r2', 'partner.email', 'billing.email', array( Presets::transform( 'case', array( 'mode' => 'shout' ) ) ) ),
				Presets::rule( 'r3', 'order_line[].sku', 'lines[].sku', array( Presets::transform( 'value_map' ) ) ),
			)
		);
		$this->assertContains( 'r1:transforms[1]:transform_unknown', $problems );
		$this->assertContains( 'r2:transforms[0]:transform_args', $problems );
		$this->assertContains( 'r3:transforms[0]:transform_args', $problems );
	}

	public function test_bad_transform_list(): void {
		$this->assertContains( 'r1:transforms:transforms_invalid', $this->problems( array( array( 'id' => 'r1', 'target' => 'partner.name', 'source' => 'billing.email', 'transforms' => 'trim' ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
		$this->assertContains( 'r1:transforms:transforms_invalid', $this->problems( array( array( 'id' => 'r1', 'target' => 'partner.name', 'source' => 'billing.email', 'transforms' => array( 'trim' ) ), Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
	}

	public function test_ids_must_be_present_well_formed_and_unique(): void {
		$problems = $this->problems(
			array(
				Presets::rule( 'dup', 'partner.name', 'billing.email' ),
				Presets::rule( 'dup', 'partner.email', 'billing.email' ),
				Presets::rule( '', 'order_line[].sku', 'lines[].sku' ),
				Presets::rule( 'bad id!', 'order_line[].qty', 'lines[].quantity' ),
			)
		);
		$this->assertContains( 'dup:id:id_duplicate', $problems );
		$this->assertContains( ':id:id_invalid', $problems );
		$this->assertContains( 'bad id!:id:id_invalid', $problems );
	}

	public function test_error_code_must_be_in_the_catalog(): void {
		$this->assertContains( 'r1:error_code:error_code_unknown', $this->problems( array( Presets::rule( 'r1', 'partner.name', 'billing.email', array(), true, 'SYNC-NOPE-1' ) ) ) );
		$this->assertNotContains( 'r1:error_code:error_code_unknown', $this->problems( array( Presets::rule( 'r1', 'partner.name', 'billing.email', array(), true, 'SYNC-MAP-003' ) ) ) );
	}

	public function test_required_connector_fields_must_be_filled_by_some_rule(): void {
		$problems = $this->problems( array( Presets::rule( 'e', 'partner.email', 'billing.email' ) ) );
		$this->assertSame( array( ':target:required_target_missing' ), $problems );
	}

	public function test_rules_must_be_a_list_of_objects(): void {
		$this->assertSame( array( ':rules:rules_not_a_list' ), $this->problems( array( 'a' => Presets::rule( 'r1', 'partner.name', 'billing.email' ) ) ) );
		$this->assertContains( ':rules:rule_not_an_object', $this->problems( array( 'nope', Presets::rule( 'n', 'partner.name', 'billing.email' ) ) ) );
	}

	public function test_inbound_rules_can_read_the_connected_systems_fields(): void {
		$rules = array( Presets::rule( 'q', 'product.stock_quantity', 'qty_available' ) );
		$this->assertSame( array(), array_map( static fn( $e ): string => $e->code, $this->engine()->validate( $rules, array(), false ) ) );
		$this->assertContains( 'source_unknown', array_map( static fn( $e ): string => $e->code, $this->engine()->validate( $rules, array(), true ) ) );
	}

	public function test_the_sanitiser_keeps_only_the_rule_model(): void {
		$clean = ( new RuleSanitizer() )->clean(
			array(
				'7' => array(
					'id'         => " r1\n",
					'target'     => 'partner.name',
					'source'     => array( 'billing.first_name', 'billing.last_name' ),
					'transforms' => array(
						array( 'type' => 'join', 'args' => array( 'separator' => ' ', 'evil' => array( 'a' => array( 'b' ) ), 'skip_empty' => true ), 'extra' => 'x' ),
						'not a step',
					),
					'required'   => 1,
					'error_code' => 'SYNC-MAP-003',
					'when'       => array( 'source' => 'customer.is_guest', 'equals' => false, 'extra' => 1 ),
					'changed'    => true,
					'added'      => true,
					'script'     => '<script>alert(1)</script>',
				),
				'bad',
			)
		);
		$this->assertSame(
			array(
				array(
					'id'         => 'r1',
					'target'     => 'partner.name',
					'source'     => array( 'billing.first_name', 'billing.last_name' ),
					'transforms' => array(
						array( 'type' => 'join', 'args' => array( 'separator' => ' ', 'evil' => array(), 'skip_empty' => true ) ),
					),
					'required'   => true,
					'error_code' => 'SYNC-MAP-003',
					'when'       => array( 'source' => 'customer.is_guest', 'equals' => '' ),
				),
			),
			$clean
		);
	}

	public function test_the_sanitiser_handles_constants_tables_and_limits(): void {
		$clean = ( new RuleSanitizer() )->clean(
			array(
				array(
					'id'         => 'r1',
					'target'     => 'x',
					'source'     => array( 'const' => 5 ),
					'transforms' => array( array( 'type' => 'value_map', 'args' => array( 'map' => array( 'cod' => 'دفع عند الاستلام', 'x' => array( 'nested' ) ) ) ) ),
				),
				array( 'id' => 'r2', 'target' => 'y', 'source' => array( 'const' => array( 'bad' ) ) ),
				array( 'id' => "r3\x00", 'target' => "bad\x07target", 'source' => 'a' ),
			)
		);
		$this->assertSame( array( 'const' => 5 ), $clean[0]['source'] );
		$this->assertSame( array( 'cod' => 'دفع عند الاستلام' ), $clean[0]['transforms'][0]['args']['map'] );
		$this->assertSame( array( 'const' => '' ), $clean[1]['source'] );
		$this->assertSame( 'r3', $clean[2]['id'] );
		$this->assertSame( 'badtarget', $clean[2]['target'] );

		$many = array_fill( 0, RuleSanitizer::MAX_RULES + 50, array( 'id' => 'r', 'target' => 't', 'source' => 's' ) );
		$this->assertCount( RuleSanitizer::MAX_RULES, ( new RuleSanitizer() )->clean( $many ) );
	}
}
