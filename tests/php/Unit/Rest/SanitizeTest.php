<?php
/**
 * The sanitise callbacks of the route arguments.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Rest;

use Estasoft\SyncEngine\Rest\Support\Sanitize;
use Estasoft\SyncEngine\Tests\Support\RestStubCase;

/**
 * Runs in its own process because it relies on global WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SanitizeTest extends RestStubCase {

	public function test_a_text_map_keeps_scalars_cleans_keys_and_strips_tags(): void {
		$clean = Sanitize::textMap( array( 'Address' => " <b>https://erp.test</b> ", 'bad key!' => 'x', 'nested' => array( 'a' ), '' => 'empty key', 'n' => 5 ) );
		$this->assertSame( array( 'address' => 'https://erp.test', 'badkey' => 'x', 'n' => '5' ), $clean );
		$this->assertSame( array(), Sanitize::textMap( 'not an array' ) );
	}

	public function test_a_text_map_cuts_long_values(): void {
		$this->assertSame( Sanitize::MAX_TEXT, strlen( Sanitize::textMap( array( 'a' => str_repeat( 'x', 10000 ) ) )['a'] ) );
	}

	public function test_a_secret_map_keeps_the_value_exactly_as_typed(): void {
		$secret = "  <p>sk-live \"x\" & 'y' \\ é\n";
		$this->assertSame( array( 'api_key' => $secret ), Sanitize::secretMap( array( 'API_KEY' => $secret, 'list' => array( 1 ) ) ) );
		$this->assertSame( array( 'n' => '12' ), Sanitize::secretMap( array( 'n' => 12 ) ) );
	}

	public function test_an_id_list_keeps_positive_whole_numbers_once_each(): void {
		$this->assertSame( array( 3, 5, 9 ), Sanitize::idList( array( 3, '5', 3, 0, -2, 'abc', 5.5, array( 1 ), '9' ) ) );
		$this->assertSame( array(), Sanitize::idList( 'nope' ) );
	}

	public function test_line_text_loses_tags_and_is_cut_and_other_types_become_empty(): void {
		$this->assertSame( 'Test order bold', Sanitize::line( "  Test order <b>bold</b> \n", 500 ) );
		$this->assertSame( 'abc', Sanitize::line( 'abcdef', 3 ) );
		$this->assertSame( '', Sanitize::line( array( 'x' ) ) );
		$this->assertSame( 'ab', Sanitize::text( "ab<i></i>", 1000 ) );
	}

	public function test_a_document_is_kept_exactly_and_anything_but_a_string_is_dropped(): void {
		$json = "{\n \"a\": \"<b>\"\n}";
		$this->assertSame( $json, Sanitize::document( $json ) );
		$this->assertSame( '', Sanitize::document( array( 'a' ) ) );
	}

	public function test_a_name_keeps_lower_case_letters_digits_dash_and_underscore(): void {
		$this->assertSame( 'wc-completed_2', Sanitize::name( 'WC-Completed_2!' ) );
		$this->assertSame( 64, strlen( Sanitize::name( str_repeat( 'a', 100 ) ) ) );
		$this->assertSame( '', Sanitize::name( array() ) );
	}

	public function test_a_structure_is_an_object_or_a_list(): void {
		$this->assertTrue( Sanitize::isStructure( array() ) );
		$this->assertFalse( Sanitize::isStructure( 'x' ) );
	}
}
