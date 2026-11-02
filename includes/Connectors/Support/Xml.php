<?php
/**
 * Small XML writer for vendors that take XML.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a nested array into XML. A key becomes an element; a list of values becomes repeated elements; `@attributes` holds the attributes
 * of the element and `#text` its text. Text is escaped; control characters are removed; Arabic and other scripts are written as UTF-8.
 * The result starts with the declaration unless `$declaration` is false.
 */
final class Xml {

	/**
	 * Encode an array as a document with a single root element.
	 *
	 * @param array<mixed> $data        Nested data. When it has one string key, that key is the root; otherwise the root is `$root`.
	 * @param string       $root        Name of the root element.
	 * @param bool         $declaration Whether to start with the XML declaration.
	 */
	public static function encode( array $data, string $root = 'root', bool $declaration = true ): string {
		if ( 1 === count( $data ) && is_string( array_key_first( $data ) ) && ! str_starts_with( (string) array_key_first( $data ), '@' ) && '#text' !== array_key_first( $data ) ) {
			$root = (string) array_key_first( $data );
			$data = $data[ $root ];
			$data = is_array( $data ) ? $data : array( '#text' => $data );
		}
		return ( $declaration ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" : '' ) . self::element( $root, $data );
	}

	/**
	 * Write one element.
	 *
	 * @param string $name  Element name.
	 * @param mixed  $value Value.
	 */
	public static function element( string $name, mixed $value ): string {
		$name = self::name( $name );
		if ( is_array( $value ) && \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) ) {
			return implode( '', array_map( static fn( $item ): string => self::element( $name, $item ), $value ) );
		}
		if ( ! is_array( $value ) ) {
			if ( null === $value ) {
				return '<' . $name . '/>';
			}
			return '<' . $name . '>' . self::text( $value ) . '</' . $name . '>';
		}
		$attributes = '';
		$inner      = '';
		foreach ( $value as $key => $child ) {
			if ( '@attributes' === $key && is_array( $child ) ) {
				foreach ( $child as $attribute => $attributeValue ) {
					$attributes .= ' ' . self::name( (string) $attribute ) . '="' . self::text( $attributeValue ) . '"';
				}
				continue;
			}
			if ( '#text' === $key ) {
				$inner .= self::text( $child );
				continue;
			}
			$inner .= self::element( (string) $key, $child );
		}
		return '' === $inner ? '<' . $name . $attributes . '/>' : '<' . $name . $attributes . '>' . $inner . '</' . $name . '>';
	}

	/**
	 * Escape a text value.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value );
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Make a valid element name: letters, digits, dash, underscore, dot and colon.
	 *
	 * @param string $name Name.
	 */
	private static function name( string $name ): string {
		$name = (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '_', $name );
		return '' === $name || 1 === preg_match( '/^[0-9.\-]/', $name ) ? '_' . $name : $name;
	}
}
