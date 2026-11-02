<?php
/**
 * Rule-writing helpers and the neutral example.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use PHPUnit\Framework\TestCase;

final class PresetsTest extends TestCase {

	public function test_rule_builds_the_model_of_the_architecture_document(): void {
		$this->assertSame(
			array(
				'id'         => 'r1',
				'target'     => 'partner.name',
				'source'     => array( 'billing.first_name', 'billing.last_name' ),
				'transforms' => array( array( 'type' => 'join', 'args' => array( 'separator' => ' ' ) ), array( 'type' => 'trim', 'args' => array() ) ),
				'required'   => true,
				'error_code' => 'SYNC-MAP-003',
				'when'       => array( 'source' => 'customer.is_guest', 'equals' => 'false' ),
			),
			Presets::rule(
				'r1',
				'partner.name',
				array( 'billing.first_name', 'billing.last_name' ),
				array( Presets::transform( 'join', array( 'separator' => ' ' ) ), Presets::transform( 'trim' ) ),
				true,
				'SYNC-MAP-003',
				array( 'source' => 'customer.is_guest', 'equals' => 'false' )
			)
		);
	}

	public function test_optional_keys_are_left_out(): void {
		$this->assertSame( array( 'id', 'target', 'source', 'transforms', 'required' ), array_keys( Presets::rule( 'r', 't', 's' ) ) );
	}

	public function test_the_neutral_example_is_a_valid_rule_set(): void {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$this->assertSame( array(), $engine->validate( Presets::neutralOrders() ) );
	}

	public function test_the_neutral_example_only_reads_canonical_fields(): void {
		$fields = new CanonicalFields();
		foreach ( Presets::neutralOrders() as $rule ) {
			foreach ( (array) $rule['source'] as $path ) {
				if ( is_string( $path ) ) {
					$this->assertTrue( $fields->has( $path ), $path );
				}
			}
		}
	}

	public function test_the_canonical_field_list_matches_the_architecture_document(): void {
		$paths = array_column( ( new CanonicalFields() )->all(), 'path' );
		foreach ( array( 'order.id', 'order.total', 'order.payment.last4', 'order.buyer_tax_number', 'customer.is_guest', 'billing.state', 'shipping.postcode', 'lines[].tax_class', 'shipping_lines[].tax_rate', 'fees[].tax', 'taxes[].rate_percent', 'coupons[].discount', 'refund.lines[].amount' ) as $path ) {
			$this->assertContains( $path, $paths );
		}
		$this->assertNotContains( 'shipping.email', $paths, 'Only billing has an email.' );
		$this->assertTrue( ( new CanonicalFields() )->has( 'meta._gift_message' ) );
		$this->assertFalse( ( new CanonicalFields() )->has( 'meta.' ) );
		$this->assertSame( array( '_gift', 'x' ), ( new CanonicalFields() )->metaKeys( array( Presets::rule( 'a', 't', 'meta._gift' ), Presets::rule( 'b', 't', array( 'meta.x', 'order.id' ) ), Presets::rule( 'c', 't', 'order.id', array(), false, null, array( 'source' => 'meta._gift', 'equals' => 'y' ) ) ) ) );
	}
}
