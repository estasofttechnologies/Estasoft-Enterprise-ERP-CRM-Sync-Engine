<?php
/**
 * Text handling of the export files: clean UTF-8, shortening, and the three file encodings.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\Utf8;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\Utf8
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\Charset
 */
final class Utf8CharsetTest extends TestCase {

	/**
	 * Stray bytes are dropped and valid text, Arabic included, is kept as it is.
	 */
	public function test_valid_drops_only_broken_sequences(): void {
		$this->assertSame( 'Größe ليلى', Utf8::valid( 'Größe ليلى' ) );
		$this->assertSame( 'ab', Utf8::valid( "a\xFFb" ) );
		$this->assertSame( 'Gre', Utf8::valid( "Gr\xF6\xDFe" ), 'Latin-1 bytes are not UTF-8.' );
		$this->assertSame( 'ok', Utf8::valid( "o\xC0\x80k" ), 'Overlong sequences are not valid.' );
		$this->assertSame( '', Utf8::valid( '' ) );
	}

	/**
	 * One clean line: controls out, white space collapsed, ends trimmed.
	 */
	public function test_line(): void {
		$this->assertSame( 'a b c', Utf8::line( "  a\t b\r\n c \u{A0}" ) );
		$this->assertSame( 'ab', Utf8::line( "a\x01\x08b" ) );
		$this->assertSame( 'ليلى حداد', Utf8::line( "ليلى\u{2028}حداد" ) );
		$this->assertSame( '', Utf8::line( " \t\n" ) );
		$this->assertSame( 'x', Utf8::line( "x\u{FFFE}\u{FFFF}" ), 'Characters XML forbids are removed.' );
	}

	/**
	 * Shortening counts characters, not bytes, and marks the cut.
	 */
	public function test_cut(): void {
		$this->assertSame( 'short', Utf8::cut( 'short', 10 ) );
		$this->assertSame( 'exactly10!', Utf8::cut( 'exactly10!', 10 ) );
		$this->assertSame( 'abcdefg...', Utf8::cut( 'abcdefghijklmnop', 10 ) );
		$this->assertSame( 'ب' . 'ب' . 'ب' . '...', Utf8::cut( str_repeat( 'ب', 20 ), 6 ) );
		$this->assertSame( 6, Utf8::length( Utf8::cut( str_repeat( 'Ä', 20 ), 6 ) ) );
		$this->assertSame( 'a...', Utf8::cut( 'a  bcdefgh', 4 ), 'A space before the ellipsis is dropped.' );
		$this->assertSame( 3, Utf8::length( 'äöü' ) );
		$this->assertSame( 5, Utf8::length( 'hello' ) );
	}

	/**
	 * Code points of one to four bytes.
	 */
	public function test_code_point(): void {
		$this->assertSame( 0x41, Utf8::codePoint( 'A' ) );
		$this->assertSame( 0xE4, Utf8::codePoint( 'ä' ) );
		$this->assertSame( 0x628, Utf8::codePoint( 'ب' ) );
		$this->assertSame( 0x20AC, Utf8::codePoint( '€' ) );
		$this->assertSame( 0x1F600, Utf8::codePoint( '😀' ) );
	}

	/**
	 * Windows-1252 holds German, Nordic and French letters, the euro sign and typographic punctuation; nothing else.
	 */
	public function test_windows_1252(): void {
		$set = Charset::Windows1252;
		$this->assertSame( "J\xFCrgen Gro\xDFe \xC5ke \xD8 \xE6\xF8\xE5 \xE9\xE8", $set->encode( 'Jürgen Große Åke Ø æøå éè' ) );
		$this->assertSame( "\x80 \x96 \x97 \x91\x92\x93\x94 \x85 \x99", $set->encode( '€ – — ‘’“” … ™' ) );
		$this->assertSame( 'plain', $set->encode( 'plain' ) );
		$this->assertSame( '? ? ?', $set->encode( 'ل ب 😀' ) );
		$this->assertSame( '????', $set->encode( 'ليلى' ) );
		$this->assertSame( 4, $set->lossy( 'ليلى' ) );
		$this->assertSame( 0, $set->lossy( 'Größe €' ) );
		$this->assertSame( 1, $set->lossy( 'a😀b' ) );
		$this->assertSame( '', $set->bom() );
		$this->assertSame( 'windows-1252', $set->label() );
	}

	/**
	 * UTF-16 little endian: ASCII and non-ASCII, and a pair of surrogates above U+FFFF, equal PHP's own conversion.
	 */
	public function test_utf16(): void {
		$set = Charset::Utf16;
		foreach ( array( '', 'A', '<?xml version="1.0"?>', 'Größe €', 'ليلى حداد', 'a😀b' ) as $text ) {
			$this->assertSame( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ), $set->encode( $text ), $text );
		}
		$this->assertSame( "\xFF\xFE", $set->bom() );
		$this->assertSame( 'UTF-16', $set->label() );
		$this->assertSame( 0, $set->lossy( 'ليلى' ) );
	}

	/**
	 * UTF-8 is passed through; with a byte order mark the mark comes first.
	 */
	public function test_utf8(): void {
		$this->assertSame( 'Größe ليلى', Charset::Utf8->encode( 'Größe ليلى' ) );
		$this->assertSame( '', Charset::Utf8->bom() );
		$this->assertSame( "\xEF\xBB\xBF", Charset::Utf8Bom->bom() );
		$this->assertSame( 'UTF-8', Charset::Utf8Bom->label() );
	}

	/**
	 * Setting values map to sets, unknown values to the fallback.
	 */
	public function test_from_setting(): void {
		$this->assertSame( Charset::Utf16, Charset::fromSetting( ' UTF-16 ', Charset::Utf8 ) );
		$this->assertSame( Charset::Windows1252, Charset::fromSetting( 'windows-1252', Charset::Utf8 ) );
		$this->assertSame( Charset::Utf8, Charset::fromSetting( 'latin1', Charset::Utf8 ) );
		$this->assertSame( Charset::Utf8, Charset::fromSetting( '', Charset::Utf8 ) );
	}
}
