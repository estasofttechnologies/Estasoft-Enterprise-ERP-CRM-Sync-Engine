<?php
/**
 * Character sets an export file can be written in.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * The text of a file is built as UTF-8 and converted here, one piece at a time, so no extension is needed and a whole file is never
 * converted in one go. Windows-1252 is the "ANSI" of DATEV and Sage 50; UTF-16 little endian with a byte order mark is what
 * TallyPrime writes itself. Characters that Windows-1252 cannot hold become a question mark and are counted by lossy().
 */
enum Charset: string {

	case Utf8        = 'utf-8';
	case Utf8Bom     = 'utf-8-bom';
	case Windows1252 = 'windows-1252';
	case Utf16       = 'utf-16';

	/**
	 * Windows-1252 bytes 0x80 to 0x9F by code point.
	 */
	private const SPECIAL = array(
		0x20AC => 0x80,
		0x201A => 0x82,
		0x0192 => 0x83,
		0x201E => 0x84,
		0x2026 => 0x85,
		0x2020 => 0x86,
		0x2021 => 0x87,
		0x02C6 => 0x88,
		0x2030 => 0x89,
		0x0160 => 0x8A,
		0x2039 => 0x8B,
		0x0152 => 0x8C,
		0x017D => 0x8E,
		0x2018 => 0x91,
		0x2019 => 0x92,
		0x201C => 0x93,
		0x201D => 0x94,
		0x2022 => 0x95,
		0x2013 => 0x96,
		0x2014 => 0x97,
		0x02DC => 0x98,
		0x2122 => 0x99,
		0x0161 => 0x9A,
		0x203A => 0x9B,
		0x0153 => 0x9C,
		0x017E => 0x9E,
		0x0178 => 0x9F,
	);

	/**
	 * A setting value, or the fallback when it is not one of the sets.
	 *
	 * @param string $value    Value from the connection settings.
	 * @param self   $fallback Used when the value is unknown.
	 */
	public static function fromSetting( string $value, self $fallback ): self {
		$found = self::tryFrom( strtolower( trim( $value ) ) );
		return $found ?? $fallback;
	}

	/**
	 * Bytes at the very start of the file.
	 */
	public function bom(): string {
		return match ( $this ) {
			self::Utf8Bom => "\xEF\xBB\xBF",
			self::Utf16   => "\xFF\xFE",
			default       => '',
		};
	}

	/**
	 * The name used in an XML declaration or a content type.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Utf8, self::Utf8Bom => 'UTF-8',
			self::Windows1252         => 'windows-1252',
			self::Utf16               => 'UTF-16',
		};
	}

	/**
	 * Convert a piece of UTF-8 text. The byte order mark is not added here.
	 *
	 * @param string $text Valid UTF-8.
	 * @return string Bytes in this character set.
	 */
	public function encode( string $text ): string {
		return match ( $this ) {
			self::Utf8, self::Utf8Bom => $text,
			self::Windows1252         => self::toWindows1252( $text ),
			self::Utf16               => self::toUtf16( $text ),
		};
	}

	/**
	 * How many characters of a text this set cannot hold (they are written as a question mark).
	 *
	 * @param string $text Valid UTF-8.
	 */
	public function lossy( string $text ): int {
		if ( self::Windows1252 !== $this || 1 === preg_match( '/^[\x00-\x7F]*$/', $text ) ) {
			return 0;
		}
		$lost = 0;
		foreach ( Utf8::chars( $text ) as $char ) {
			if ( null === self::byteFor( Utf8::codePoint( $char ) ) ) {
				++$lost;
			}
		}
		return $lost;
	}

	/**
	 * Windows-1252 byte of a code point, or null.
	 *
	 * @param int $code Code point.
	 */
	private static function byteFor( int $code ): ?int {
		if ( $code < 0x80 || ( $code >= 0xA0 && $code <= 0xFF ) ) {
			return $code;
		}
		return self::SPECIAL[ $code ] ?? null;
	}

	/**
	 * UTF-8 to Windows-1252.
	 *
	 * @param string $text Valid UTF-8.
	 */
	private static function toWindows1252( string $text ): string {
		if ( 1 === preg_match( '/^[\x00-\x7F]*$/', $text ) ) {
			return $text;
		}
		$out = '';
		foreach ( Utf8::chars( $text ) as $char ) {
			$byte = self::byteFor( Utf8::codePoint( $char ) );
			$out .= null === $byte ? '?' : chr( $byte );
		}
		return $out;
	}

	/**
	 * UTF-8 to UTF-16 little endian, with surrogate pairs above U+FFFF.
	 *
	 * @param string $text Valid UTF-8.
	 */
	private static function toUtf16( string $text ): string {
		if ( 1 === preg_match( '/^[\x00-\x7F]*$/', $text ) ) {
			return '' === $text ? '' : implode( "\0", str_split( $text ) ) . "\0";
		}
		$out = '';
		foreach ( Utf8::chars( $text ) as $char ) {
			$code = Utf8::codePoint( $char );
			if ( $code >= 0x10000 ) {
				$code -= 0x10000;
				$out  .= pack( 'vv', 0xD800 | ( $code >> 10 ), 0xDC00 | ( $code & 0x3FF ) );
				continue;
			}
			$out .= pack( 'v', $code );
		}
		return $out;
	}
}
