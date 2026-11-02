<?php
/**
 * Prefix, suffix, trim, case, default, join, split, value_map, if_then, required.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Transforms\CaseConvert;
use Estasoft\SyncEngine\Mapping\Transforms\DefaultValue;
use Estasoft\SyncEngine\Mapping\Transforms\IfThen;
use Estasoft\SyncEngine\Mapping\Transforms\Join;
use Estasoft\SyncEngine\Mapping\Transforms\Prefix;
use Estasoft\SyncEngine\Mapping\Transforms\Required;
use Estasoft\SyncEngine\Mapping\Transforms\Split;
use Estasoft\SyncEngine\Mapping\Transforms\Suffix;
use Estasoft\SyncEngine\Mapping\Transforms\Trim;
use Estasoft\SyncEngine\Mapping\Transforms\TransformArgumentException;
use Estasoft\SyncEngine\Mapping\Transforms\ValueMap;

final class SimpleTransformsTest extends TransformTestCase {

	public function test_prefix_adds_text_in_front_of_a_number(): void {
		$this->assertSame( 'WC-10490', ( new Prefix() )->apply( 10490, array( 'value' => 'WC-' ), $this->context() ) );
	}

	public function test_prefix_keeps_empty_values_empty(): void {
		$prefix = new Prefix();
		$this->assertNull( $prefix->apply( null, array( 'value' => 'WC-' ), $this->context() ) );
		$this->assertSame( '', $prefix->apply( '', array( 'value' => 'WC-' ), $this->context() ) );
	}

	public function test_prefix_and_suffix_keep_arabic_as_typed(): void {
		$this->assertSame( 'شركة نور', ( new Prefix() )->apply( 'نور', array( 'value' => 'شركة ' ), $this->context() ) );
		$this->assertSame( 'نور (VIP)', ( new Suffix() )->apply( 'نور', array( 'value' => ' (VIP)' ), $this->context() ) );
	}

	public function test_prefix_and_suffix_need_a_value_argument(): void {
		$this->assertCount( 1, ( new Prefix() )->check( array() ) );
		$this->assertCount( 1, ( new Suffix() )->check( array( 'value' => array( 'x' ) ) ) );
		$this->assertSame( array(), ( new Suffix() )->check( array( 'value' => '' ) ) );
	}

	public function test_trim_removes_spaces_but_not_inner_ones_by_default(): void {
		$trim = new Trim();
		$this->assertSame( 'Noor  Al', $trim->apply( "  Noor  Al\u{00A0}", array(), $this->context() ) );
		$this->assertSame( 'Noor Al', $trim->apply( "  Noor  \t Al ", array( 'collapse' => true ), $this->context() ) );
		$this->assertSame( '5', $trim->apply( 5, array(), $this->context() ) );
		$this->assertNull( $trim->apply( null, array(), $this->context() ) );
	}

	public function test_case_converts_latin_and_leaves_arabic_alone(): void {
		$case = new CaseConvert();
		$this->assertSame( 'NOOR@EXAMPLE.COM', $case->apply( 'Noor@Example.com', array( 'mode' => 'upper' ), $this->context() ) );
		$this->assertSame( 'noor@example.com', $case->apply( 'Noor@Example.com', array( 'mode' => 'lower' ), $this->context() ) );
		$this->assertSame( 'Noor Al-Mansouri', $case->apply( 'noor al-mansouri', array( 'mode' => 'title' ), $this->context() ) );
		$this->assertSame( 'نور المنصوري', $case->apply( 'نور المنصوري', array( 'mode' => 'upper' ), $this->context() ) );
		$this->assertSame( 'ÉCOLE', $case->apply( 'école', array( 'mode' => 'upper' ), $this->context() ) );
	}

	public function test_case_with_a_bad_mode_is_refused(): void {
		$this->expectException( TransformArgumentException::class );
		( new CaseConvert() )->apply( 'x', array( 'mode' => 'shout' ), $this->context() );
	}

	public function test_case_check_reports_a_bad_mode(): void {
		$this->assertCount( 1, ( new CaseConvert() )->check( array( 'mode' => 'shout' ) ) );
		$this->assertCount( 1, ( new CaseConvert() )->check( array() ) );
		$this->assertSame( array(), ( new CaseConvert() )->check( array( 'mode' => 'title' ) ) );
	}

	public function test_default_fills_only_empty_values(): void {
		$default = new DefaultValue();
		$this->assertSame( 'n/a', $default->apply( '', array( 'value' => 'n/a' ), $this->context() ) );
		$this->assertSame( 'n/a', $default->apply( null, array( 'value' => 'n/a' ), $this->context() ) );
		$this->assertSame( 'n/a', $default->apply( '   ', array( 'value' => 'n/a' ), $this->context() ) );
		$this->assertSame( 'x', $default->apply( 'x', array( 'value' => 'n/a' ), $this->context() ) );
		$this->assertSame( 0, $default->apply( 0, array( 'value' => 'n/a' ), $this->context() ), 'Zero is a value.' );
	}

	public function test_default_can_read_another_field(): void {
		$context = $this->context( array( 'customer' => array( 'phone' => '+971501234567' ) ) );
		$this->assertSame( '+971501234567', ( new DefaultValue() )->apply( '', array( 'from' => 'customer.phone' ), $context ) );
	}

	public function test_default_check(): void {
		$default = new DefaultValue();
		$this->assertCount( 1, $default->check( array() ) );
		$this->assertCount( 1, $default->check( array( 'from' => 'not a path!' ) ) );
		$this->assertSame( array(), $default->check( array( 'from' => 'customer.phone' ) ) );
		$this->assertSame( array(), $default->check( array( 'value' => '' ) ) );
	}

	public function test_join_puts_parts_together_and_skips_empty_ones(): void {
		$join = new Join();
		$this->assertTrue( $join->handlesLists() );
		$this->assertSame( 'نور المنصوري', $join->apply( array( 'نور', 'المنصوري' ), array(), $this->context() ) );
		$this->assertSame( 'Noor', $join->apply( array( 'Noor', '', null ), array(), $this->context() ) );
		$this->assertSame( 'Noor|Al-Mansouri', $join->apply( array( 'Noor', 'Al-Mansouri' ), array( 'separator' => '|' ), $this->context() ) );
		$this->assertSame( 'Noor, ', $join->apply( array( 'Noor', '' ), array( 'separator' => ', ', 'skip_empty' => false ), $this->context() ) );
		$this->assertSame( '', $join->apply( array( '', null ), array(), $this->context() ) );
		$this->assertSame( 'single', $join->apply( 'single', array(), $this->context() ) );
	}

	public function test_split_returns_a_list_or_one_part(): void {
		$split   = new Split();
		$context = $this->context();
		$this->assertSame( array( 'Noor', 'Al-Mansouri' ), $split->apply( 'Noor Al-Mansouri', array( 'separator' => ' ' ), $context ) );
		$this->assertSame( 'Noor', $split->apply( 'Noor Al-Mansouri', array( 'separator' => ' ', 'index' => 0 ), $context ) );
		$this->assertSame( 'Al-Mansouri', $split->apply( 'Noor Al-Mansouri', array( 'separator' => ' ', 'index' => -1 ), $context ) );
		$this->assertSame( array( 'نور', 'عبدالله المنصوري' ), $split->apply( 'نور عبدالله المنصوري', array( 'separator' => ' ', 'limit' => 2 ), $context ) );
		$this->assertSame( '', $split->apply( 'one', array( 'separator' => ' ', 'index' => 3 ), $context ) );
		$this->assertSame( array( 'a', 'b' ), $split->apply( 'a,, b ,', array( 'separator' => ',' ), $context ), 'Empty parts are dropped.' );
		$this->assertNull( $split->apply( null, array( 'separator' => ' ' ), $context ) );
	}

	public function test_split_needs_a_separator(): void {
		$this->expectException( TransformArgumentException::class );
		( new Split() )->apply( 'a b', array(), $this->context() );
	}

	public function test_split_check(): void {
		$this->assertCount( 1, ( new Split() )->check( array() ) );
		$this->assertCount( 1, ( new Split() )->check( array( 'separator' => ' ', 'index' => 'first' ) ) );
		$this->assertSame( array(), ( new Split() )->check( array( 'separator' => ' ', 'index' => '-1', 'limit' => 2 ) ) );
	}

	public function test_value_map_translates_known_values(): void {
		$map = new ValueMap();
		$this->assertSame( 'Pay on delivery', $map->apply( 'cod', array( 'map' => array( 'cod' => 'Pay on delivery' ) ), $this->context() ) );
		$this->assertSame( 'bacs', $map->apply( 'bacs', array( 'map' => array( 'cod' => 'Pay on delivery' ) ), $this->context() ), 'Unmapped values are kept.' );
		$this->assertSame( 'Other', $map->apply( 'bacs', array( 'map' => array( 'cod' => 'x' ), 'else' => 'Other' ), $this->context() ) );
		$this->assertSame( 'دفع عند الاستلام', $map->apply( 'COD', array( 'map' => array( 'cod' => 'دفع عند الاستلام' ), 'ignore_case' => true ), $this->context() ) );
		$this->assertSame( 'Dubai', $map->apply( 'دبي', array( 'map' => array( 'دبي' => 'Dubai' ) ), $this->context() ) );
	}

	public function test_value_map_strict_leaves_unknown_values_empty_with_a_warning(): void {
		$context = $this->context();
		$this->assertSame( '', ( new ValueMap() )->apply( 'bacs', array( 'map' => array( 'cod' => 'x' ), 'strict' => true ), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_value_map_needs_a_table(): void {
		$this->assertCount( 1, ( new ValueMap() )->check( array() ) );
		$this->assertCount( 1, ( new ValueMap() )->check( array( 'map' => array() ) ) );
		$this->expectException( TransformArgumentException::class );
		( new ValueMap() )->apply( 'x', array(), $this->context() );
	}

	/**
	 * @dataProvider conditions
	 * @param mixed               $value    Value.
	 * @param array<string,mixed> $args     Arguments.
	 * @param mixed               $expected Expected.
	 */
	public function test_if_then( mixed $value, array $args, mixed $expected ): void {
		$this->assertSame( $expected, ( new IfThen() )->apply( $value, $args, $this->context() ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:array<string,mixed>,2:mixed}>
	 */
	public static function conditions(): array {
		return array(
			'equals true'       => array( 'cod', array( 'operator' => 'equals', 'value' => 'COD', 'then' => 'yes', 'else' => 'no' ), 'yes' ),
			'equals false'      => array( 'bacs', array( 'operator' => 'equals', 'value' => 'cod', 'then' => 'yes', 'else' => 'no' ), 'no' ),
			'else keeps value'  => array( 'bacs', array( 'operator' => 'equals', 'value' => 'cod', 'then' => 'yes' ), 'bacs' ),
			'not equals'        => array( 'bacs', array( 'operator' => 'not_equals', 'value' => 'cod', 'then' => 'online' ), 'online' ),
			'contains'          => array( 'Cash on delivery', array( 'operator' => 'contains', 'value' => 'delivery', 'then' => 'cod' ), 'cod' ),
			'empty'             => array( '', array( 'operator' => 'empty', 'then' => 'none' ), 'none' ),
			'not empty'         => array( 'x', array( 'operator' => 'not_empty', 'then' => 'has' ), 'has' ),
			'greater than'      => array( 120, array( 'operator' => 'gt', 'value' => '100', 'then' => 'big' ), 'big' ),
			'less than'         => array( '99.5', array( 'operator' => 'lt', 'value' => '100', 'then' => 'small' ), 'small' ),
			'gt on text'        => array( 'abc', array( 'operator' => 'gt', 'value' => '1', 'then' => 'x', 'else' => 'nan' ), 'nan' ),
			'arabic equals'     => array( 'الإمارات', array( 'operator' => 'equals', 'value' => 'الامارات', 'then' => 'AE' ), 'AE' ),
		);
	}

	public function test_if_then_check(): void {
		$if = new IfThen();
		$this->assertNotEmpty( $if->check( array() ) );
		$this->assertNotEmpty( $if->check( array( 'operator' => 'equals', 'then' => 'x' ) ), 'A comparison needs a value.' );
		$this->assertNotEmpty( $if->check( array( 'operator' => 'equals', 'value' => 'x' ) ), 'It needs a then.' );
		$this->assertSame( array(), $if->check( array( 'operator' => 'empty', 'then' => 'x' ) ) );
		$this->expectException( TransformArgumentException::class );
		$if->apply( 'x', array( 'operator' => 'nope', 'then' => 'x' ), $this->context() );
	}

	public function test_required_passes_values_and_stops_empty_ones(): void {
		$required = new Required();
		$this->assertSame( 'a@b.c', $required->apply( 'a@b.c', array(), $this->context() ) );
		$this->assertSame( 0, $required->apply( 0, array(), $this->context() ) );

		foreach ( array( null, '', '  ', array() ) as $empty ) {
			try {
				$required->apply( $empty, array(), $this->context( array( 'order' => array( 'number' => '10490' ) ), '', null, null, null, ErrorCode::Map003 ) );
				$this->fail( 'An empty value must stop the order.' );
			} catch ( MappingException $e ) {
				$this->assertSame( ErrorCode::Map003, $e->errorCode );
				$this->assertSame( 'r1', $e->ruleId );
				$this->assertStringContainsString( '10490', $e->toConnectorError()->message() );
			}
		}
	}

	public function test_required_error_code_argument_wins_over_the_rule_and_defaults_to_val002(): void {
		try {
			( new Required() )->apply( '', array( 'error_code' => 'SYNC-VAL-001' ), $this->context( array(), '', null, null, null, ErrorCode::Map003 ) );
			$this->fail( 'Expected a failure.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Val001, $e->errorCode );
		}
		try {
			( new Required() )->apply( '', array(), $this->context() );
			$this->fail( 'Expected a failure.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Val002, $e->errorCode );
		}
		$this->assertCount( 1, ( new Required() )->check( array( 'error_code' => 'SYNC-NOPE-9' ) ) );
	}
}
