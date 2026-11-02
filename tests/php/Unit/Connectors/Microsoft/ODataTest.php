<?php
/**
 * OData filter and path building for the Microsoft connectors.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use PHPUnit\Framework\TestCase;

final class ODataTest extends TestCase {

	public function test_a_quote_inside_a_value_is_doubled_and_cannot_end_the_literal(): void {
		$this->assertSame( "'it''s'", OData::string( "it's" ) );
		$this->assertSame( "name eq 'x'' or 1 eq 1 or name eq ''y'", OData::eq( 'name', "x' or 1 eq 1 or name eq 'y" ) );
		$this->assertSame( "startswith(name,'O''Neil')", OData::startsWith( 'name', "O'Neil" ) );
		$this->assertSame( "contains(name,'a''b')", OData::contains( 'name', "a'b" ) );
	}

	public function test_arabic_and_other_text_is_left_as_it_is_in_the_literal(): void {
		$this->assertSame( "name eq 'نور المنصوري'", OData::eq( 'name', 'نور المنصوري' ) );
	}

	public function test_only_a_real_guid_can_be_placed_in_an_address(): void {
		$guid = '00AA11BB-22cc-33dd-44ee-55ff66aa77bb';
		$this->assertTrue( OData::isGuid( $guid ) );
		$this->assertSame( strtolower( $guid ), OData::guid( ' ' . $guid . ' ' ) );
		foreach ( array( '', 'abc', $guid . ')', "x') or 1 eq 1", '00aa11bb-22cc-33dd-44ee-55ff66aa77b' ) as $bad ) {
			$this->assertNull( OData::guid( $bad ), $bad );
			$this->assertFalse( OData::isGuid( $bad ), $bad );
		}
		$this->assertNull( OData::guid( 12 ) );
	}

	public function test_the_id_is_read_from_the_entity_id_header(): void {
		$this->assertSame( '00aa11bb-22cc-33dd-44ee-55ff66aa77bb', OData::guidIn( 'https://contoso.crm4.dynamics.com/api/data/v9.2/accounts(00AA11BB-22CC-33DD-44EE-55FF66AA77BB)' ) );
		$this->assertNull( OData::guidIn( null ) );
		$this->assertNull( OData::guidIn( 'https://contoso.crm4.dynamics.com/api/data/v9.2/accounts' ) );
		$this->assertNull( OData::guidIn( 'accounts(not-a-guid)' ) );
	}

	public function test_a_path_encodes_options_skips_nulls_and_writes_booleans_as_words(): void {
		$this->assertSame( 'customers', OData::path( 'customers', array() ) );
		$this->assertSame( 'customers', OData::path( 'customers', array( '$top' => null ) ) );
		$this->assertSame(
			'customers?%24filter=email%20eq%20%27a%40b.test%27&%24top=5&cross-company=true&x=false',
			str_replace( array( '$filter', '$top' ), array( '%24filter', '%24top' ), OData::path( 'customers', array( '$filter' => "email eq 'a@b.test'", '$top' => 5, 'cross-company' => true, 'x' => false ) ) )
		);
	}

	public function test_conditions_are_joined_with_and_and_empty_ones_are_dropped(): void {
		$this->assertSame( "a eq 'x' and b eq 'y'", OData::all( array( "a eq 'x'", '', "b eq 'y'" ) ) );
		$this->assertSame( '', OData::all( array() ) );
	}

	public function test_a_date_is_written_in_utc(): void {
		$this->assertSame( '2026-11-01', OData::date( new \DateTimeImmutable( '2026-11-02 01:30:00', new \DateTimeZone( 'Asia/Dubai' ) ) ) );
	}
}
