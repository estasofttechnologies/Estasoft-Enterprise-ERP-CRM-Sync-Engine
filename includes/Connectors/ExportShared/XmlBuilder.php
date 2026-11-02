<?php
/**
 * A small writer of XML text.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the text of an XML fragment one element at a time. Text and attribute values are cleaned of characters XML 1.0 forbids and
 * escaped. It writes strings, not a DOM, so a voucher costs a few kilobytes and ten thousand of them never sit in one tree.
 */
final class XmlBuilder {

	/**
	 * Pieces of text in order.
	 *
	 * @var array<int,string>
	 */
	private array $parts = array();

	/**
	 * Depth of the open elements, for indentation.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Escape a text for element content or an attribute value.
	 *
	 * @param string $text Text.
	 */
	public static function escape( string $text ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', Utf8::valid( $text ) );
		return htmlspecialchars( null === $clean ? '' : $clean, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Open an element.
	 *
	 * @param string               $tag        Element name.
	 * @param array<string,string> $attributes Attributes by name.
	 */
	public function open( string $tag, array $attributes = array() ): self {
		$this->parts[] = $this->indent() . '<' . $tag . $this->attributes( $attributes ) . ">\n";
		++$this->depth;
		return $this;
	}

	/**
	 * Close the innermost element.
	 *
	 * @param string $tag Element name.
	 */
	public function close( string $tag ): self {
		$this->depth   = max( 0, $this->depth - 1 );
		$this->parts[] = $this->indent() . '</' . $tag . ">\n";
		return $this;
	}

	/**
	 * An element that holds only text.
	 *
	 * @param string               $tag        Element name.
	 * @param string               $text       Text (escaped here).
	 * @param array<string,string> $attributes Attributes by name.
	 */
	public function leaf( string $tag, string $text, array $attributes = array() ): self {
		$this->parts[] = $this->indent() . '<' . $tag . $this->attributes( $attributes ) . '>' . self::escape( $text ) . '</' . $tag . ">\n";
		return $this;
	}

	/**
	 * The text so far.
	 */
	public function toString(): string {
		return implode( '', $this->parts );
	}

	/**
	 * The indentation of the current depth.
	 */
	private function indent(): string {
		return str_repeat( ' ', $this->depth );
	}

	/**
	 * Attributes as text.
	 *
	 * @param array<string,string> $attributes Attributes by name.
	 */
	private function attributes( array $attributes ): string {
		$text = '';
		foreach ( $attributes as $name => $value ) {
			$text .= ' ' . $name . '="' . self::escape( $value ) . '"';
		}
		return $text;
	}
}
