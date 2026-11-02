<?php
/**
 * The fixed transform library.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Transforms\Prefix;
use Estasoft\SyncEngine\Mapping\Transforms\TransformArgumentException;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use PHPUnit\Framework\TestCase;

final class TransformRegistryTest extends TestCase {

	public function test_the_library_has_exactly_the_documented_transforms(): void {
		$expected = array( 'prefix', 'suffix', 'trim', 'case', 'phone_e164', 'iso_country', 'iso_state', 'money_minor', 'date_utc', 'value_map', 'if_then', 'default', 'required', 'join', 'split', 'tax_lookup', 'sku_match' );
		$registry = TransformRegistry::standard();
		$this->assertEqualsCanonicalizing( $expected, $registry->names() );
		foreach ( $expected as $name ) {
			$this->assertTrue( $registry->has( $name ) );
			$this->assertSame( $name, $registry->get( $name )->name() );
		}
	}

	public function test_unknown_transform_is_refused(): void {
		$this->expectException( TransformArgumentException::class );
		TransformRegistry::standard()->get( 'eval' );
	}

	public function test_a_later_registration_replaces_an_earlier_one(): void {
		$registry = new TransformRegistry( array( new Prefix() ) );
		$replace  = new Prefix();
		$registry->register( $replace );
		$this->assertSame( $replace, $registry->get( 'prefix' ) );
		$this->assertSame( array( 'prefix' ), $registry->names() );
	}

	public function test_only_join_handles_whole_lists(): void {
		$registry = TransformRegistry::standard();
		foreach ( $registry->names() as $name ) {
			$this->assertSame( 'join' === $name, $registry->get( $name )->handlesLists(), $name );
		}
	}
}
