<?php
/**
 * Text helper tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	public function test_empty_means_no_information(): void {
		$this->assertTrue( Text::isEmpty( null ) );
		$this->assertTrue( Text::isEmpty( '' ) );
		$this->assertTrue( Text::isEmpty( "  \u{00A0}\u{200B} " ) );
		$this->assertTrue( Text::isEmpty( array() ) );
		$this->assertTrue( Text::isEmpty( array( '', null, ' ' ) ) );
		$this->assertFalse( Text::isEmpty( 0 ) );
		$this->assertFalse( Text::isEmpty( false ) );
		$this->assertFalse( Text::isEmpty( '0' ) );
		$this->assertFalse( Text::isEmpty( array( '', 'x' ) ) );
	}

	public function test_to_string_of_scalars(): void {
		$this->assertSame( 'true', Text::toString( true ) );
		$this->assertSame( 'false', Text::toString( false ) );
		$this->assertSame( '12', Text::toString( 12 ) );
		$this->assertSame( '0.1', Text::toString( 0.1 ) );
		$this->assertSame( '19.99', Text::toString( 19.99 ) );
		$this->assertSame( '0', Text::toString( 0.0 ) );
		$this->assertSame( '', Text::toString( null ) );
		$this->assertSame( '', Text::toString( array( 1 ) ) );
	}

	public function test_trim_removes_unicode_spaces_and_keeps_arabic(): void {
		$this->assertSame( 'نور المنصوري', Text::trim( "\u{00A0} نور المنصوري \u{200B}\n" ) );
	}

	public function test_fold_ignores_case_accents_arabic_marks_and_punctuation(): void {
		$this->assertSame( 'mubarak al kabir', Text::fold( 'Mubārak al-Kabīr' ) );
		$this->assertSame( Text::fold( 'الإمارات' ), Text::fold( 'الامارات' ) );
		$this->assertSame( Text::fold( 'مُحَمَّد' ), Text::fold( 'محمد' ) );
		$this->assertSame( 'a b', Text::fold( 'A.B' ) );
		$this->assertSame( '123', Text::fold( '١٢٣' ) );
		$this->assertSame( 'ras al khaymah', Text::fold( "Ra\u{02BC}s al-Khaymah" ), 'Apostrophes are dropped.' );
	}

	public function test_case_helpers(): void {
		$this->assertSame( 'ABC', Text::upper( 'abc' ) );
		$this->assertSame( 'abc', Text::lower( 'ABC' ) );
		$this->assertSame( 'Al Mansouri', Text::title( 'al mansouri' ) );
		$this->assertSame( 'نور', Text::upper( 'نور' ), 'Arabic has no case.' );
	}
}
