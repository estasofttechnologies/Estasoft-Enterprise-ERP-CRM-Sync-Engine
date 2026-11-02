<?php
/**
 * Text limits and connection options shared by the Microsoft connectors.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\Options;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;
use PHPUnit\Framework\TestCase;

final class TextAndOptionsTest extends TestCase {

	public function test_a_short_text_is_only_trimmed(): void {
		$this->assertSame( array( 'Mug', false ), Text::fit( '  Mug ', 10 ) );
		$this->assertSame( array( '1234567890', false ), Text::fit( '1234567890', 10 ) );
	}

	public function test_a_long_text_is_cut_with_an_ellipsis_within_the_limit(): void {
		list( $text, $cut ) = Text::fit( 'Stoneware bowl set, large', 12 );
		$this->assertTrue( $cut );
		$this->assertSame( 12, mb_strlen( $text ) );
		$this->assertStringEndsWith( '…', $text );
		$this->assertSame( 'Stoneware b…', $text );
	}

	public function test_arabic_text_is_cut_by_characters_not_bytes_and_stays_valid(): void {
		$text = Text::cut( 'شارع الشيخ زايد، برج الأمل، الطابق الثالث', 15 );
		$this->assertLessThanOrEqual( 15, mb_strlen( $text ) );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertStringStartsWith( 'شارع الشيخ', $text );
	}

	public function test_a_limit_too_small_to_hold_an_ellipsis_leaves_the_text_alone(): void {
		$this->assertSame( array( 'abc', false ), Text::fit( 'abc', 1 ) );
	}

	public function test_fields_are_cut_by_their_own_limit_and_the_cut_ones_are_named(): void {
		list( $body, $cut ) = Text::fitFields(
			array(
				'name'  => 'abcdefghij',
				'city'  => 'Dubai',
				'count' => 5,
				'other' => str_repeat( 'x', 500 ),
			),
			array( 'name' => 5, 'city' => 60, 'count' => 1, 'missing' => 3 )
		);
		$this->assertSame( 'abcd…', $body['name'] );
		$this->assertSame( 'Dubai', $body['city'] );
		$this->assertSame( 5, $body['count'] );
		$this->assertSame( 500, strlen( $body['other'] ) );
		$this->assertSame( array( 'name' ), $cut );
	}

	public function test_a_choice_must_be_in_the_list(): void {
		$options = new Options( array( 'mode' => 'warn', 'bad' => 'explode', 'number' => 3 ) );
		$this->assertSame( 'warn', $options->choice( 'mode', array( 'fail', 'warn' ), 'fail' ) );
		$this->assertSame( 'fail', $options->choice( 'bad', array( 'fail', 'warn' ), 'fail' ) );
		$this->assertSame( 'fail', $options->choice( 'number', array( 'fail', 'warn' ), 'fail' ) );
		$this->assertSame( 'fail', $options->choice( 'absent', array( 'fail', 'warn' ), 'fail' ) );
	}

	public function test_a_text_is_trimmed_and_a_number_is_accepted(): void {
		$options = new Options( array( 'a' => '  SHIP ', 'b' => 77, 'c' => array( 'x' ), 'd' => null ) );
		$this->assertSame( 'SHIP', $options->text( 'a' ) );
		$this->assertSame( '77', $options->text( 'b' ) );
		$this->assertSame( 'fallback', $options->text( 'c', 'fallback' ) );
		$this->assertSame( '', $options->text( 'd' ) );
		$this->assertSame( '', $options->text( 'absent' ) );
	}

	public function test_a_flag_reads_the_usual_words_and_keeps_the_default_for_anything_else(): void {
		$options = new Options( array( 'a' => true, 'b' => 'Yes', 'c' => 'off', 'd' => '0', 'e' => 'maybe', 'f' => 5 ) );
		$this->assertTrue( $options->flag( 'a', false ) );
		$this->assertTrue( $options->flag( 'b', false ) );
		$this->assertFalse( $options->flag( 'c', true ) );
		$this->assertFalse( $options->flag( 'd', true ) );
		$this->assertTrue( $options->flag( 'e', true ) );
		$this->assertFalse( $options->flag( 'e', false ) );
		$this->assertTrue( $options->flag( 'f', true ) );
		$this->assertTrue( $options->flag( 'absent', true ) );
	}
}
