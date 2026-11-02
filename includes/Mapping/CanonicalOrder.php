<?php
/**
 * The canonical model an order, refund or customer is mapped from.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, typed wrapper around the canonical array described in docs/ARCHITECTURE.md. Rules read it by
 * path: "billing.email", "lines[].sku" (one value per line), "lines[0].sku" (one line), "meta._gift_message".
 * Missing data reads as null; a path through a list returns a list with one entry per row, null where the row lacks the key.
 */
final class CanonicalOrder {

	/**
	 * The canonical data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,mixed> $data Canonical data: nested arrays of scalars (money as integer minor units).
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * The whole canonical array (a copy).
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * Read a value by path.
	 *
	 * @param string $path Path such as "order.total", "lines[].sku" or "lines[1].name".
	 * @return mixed Scalar, nested array, a list for paths that fan out over "[]", or null when absent.
	 */
	public function get( string $path ): mixed {
		$segments = self::segments( $path );
		if ( array() === $segments ) {
			return null;
		}
		return self::walk( $this->data, $segments );
	}

	/**
	 * Read a path, but for a path that fans out over "[]" return only the entry for one row.
	 *
	 * @param string $path Path such as "lines[].tax_class".
	 * @param int    $row  Zero-based row.
	 * @return mixed
	 */
	public function getRow( string $path, int $row ): mixed {
		$value = $this->get( $path );
		if ( self::fansOut( $path ) ) {
			return is_array( $value ) ? ( $value[ $row ] ?? null ) : null;
		}
		return $value;
	}

	/**
	 * Whether the path holds a non-empty value.
	 *
	 * @param string $path Path.
	 */
	public function has( string $path ): bool {
		$value = $this->get( $path );
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( null !== $item && '' !== $item ) {
					return true;
				}
			}
			return false;
		}
		return null !== $value && '' !== $value;
	}

	/**
	 * Number of rows in a list such as "lines".
	 *
	 * @param string $path Path of the list, with or without a trailing "[]".
	 */
	public function count( string $path ): int {
		$value = $this->get( rtrim( $path, '[]' ) );
		return is_array( $value ) ? count( $value ) : 0;
	}

	/**
	 * A copy with one value replaced or added. The original is never changed.
	 *
	 * @param string $path  Plain dotted path without "[]".
	 * @param mixed  $value New value.
	 */
	public function with( string $path, mixed $value ): self {
		$copy     = $this->data;
		$segments = explode( '.', $path );
		$cursor   = &$copy;
		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor = &$cursor[ $segment ];
		}
		$cursor = $value;
		return new self( $copy );
	}

	/**
	 * Whether a path fans out over a list ("[]" without an index).
	 *
	 * @param string $path Path.
	 */
	public static function fansOut( string $path ): bool {
		return false !== strpos( $path, '[]' );
	}

	/**
	 * Whether a string is a well-formed path: names of letters, digits, underscore or hyphen separated by dots,
	 * each optionally followed by "[]" or "[n]".
	 *
	 * @param string $path Path.
	 */
	public static function isValidPath( string $path ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-]+(\[\d*\])?(\.[A-Za-z0-9_\-]+(\[\d*\])?)*$/', $path );
	}

	/**
	 * Split a path into segments: a key and an index marker (null: none, "": fan out, "n": one row).
	 *
	 * @param string $path Path.
	 * @return array<int,array{0:string,1:?string}>
	 */
	private static function segments( string $path ): array {
		if ( ! self::isValidPath( $path ) ) {
			return array();
		}
		$segments = array();
		foreach ( explode( '.', $path ) as $part ) {
			if ( preg_match( '/^(.+?)\[(\d*)\]$/', $part, $found ) ) {
				$segments[] = array( $found[1], $found[2] );
			} else {
				$segments[] = array( $part, null );
			}
		}
		return $segments;
	}

	/**
	 * Follow segments through the data.
	 *
	 * @param mixed                                $node     Current node.
	 * @param array<int,array{0:string,1:?string}> $segments Remaining segments.
	 * @return mixed
	 */
	private static function walk( mixed $node, array $segments ): mixed {
		$segment = array_shift( $segments );
		if ( null === $segment ) {
			return $node;
		}
		list( $key, $index ) = $segment;
		if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
			return self::fansOutBelow( $index, $segments ) ? array() : null;
		}
		$child = $node[ $key ];
		if ( null === $index ) {
			return self::walk( $child, $segments );
		}
		if ( ! is_array( $child ) ) {
			return self::fansOutBelow( $index, $segments ) ? array() : null;
		}
		if ( '' === $index ) {
			$rows = array();
			foreach ( array_values( $child ) as $row ) {
				$rows[] = self::walk( $row, $segments );
			}
			return self::flatten( $rows, $segments );
		}
		$row = array_values( $child )[ (int) $index ] ?? null;
		return self::walk( $row, $segments );
	}

	/**
	 * Whether the part of a path from here on fans out over a list, so that a missing branch reads as an empty list instead of null.
	 *
	 * @param string|null                          $index    Index marker of the current segment.
	 * @param array<int,array{0:string,1:?string}> $segments Segments after the current one.
	 */
	private static function fansOutBelow( ?string $index, array $segments ): bool {
		if ( '' === $index ) {
			return true;
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * When a later segment fans out again ("a[].b[].c"), join the nested lists into one flat list.
	 *
	 * @param array<int,mixed>                     $rows     Values for each row.
	 * @param array<int,array{0:string,1:?string}> $segments Segments after the fan-out.
	 * @return array<int,mixed>
	 */
	private static function flatten( array $rows, array $segments ): array {
		$nested = false;
		foreach ( $segments as $segment ) {
			if ( '' === $segment[1] ) {
				$nested = true;
				break;
			}
		}
		if ( ! $nested ) {
			return $rows;
		}
		$flat = array();
		foreach ( $rows as $row ) {
			foreach ( is_array( $row ) ? $row : array() as $item ) {
				$flat[] = $item;
			}
		}
		return $flat;
	}
}
