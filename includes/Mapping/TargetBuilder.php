<?php
/**
 * Builds the nested target payload from rule results.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Writes values at target paths such as "partner.name" or "order_line[].sku". Rules that write the same list line up by row number;
 * rows that stay empty are dropped when the payload is finished.
 */
final class TargetBuilder {

	/**
	 * The payload so far.
	 *
	 * @var array<string,mixed>
	 */
	private array $data = array();

	/**
	 * Write a value.
	 *
	 * @param string   $path  Target path.
	 * @param mixed    $value Value.
	 * @param int|null $row   Row for the "[]" part of the path.
	 */
	public function set( string $path, mixed $value, ?int $row = null ): void {
		$cursor = &$this->data;
		foreach ( explode( '.', $path ) as $segment ) {
			$isList = '[]' === substr( $segment, -2 );
			$key    = $isList ? substr( $segment, 0, -2 ) : $segment;
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = array();
			}
			$cursor = &$cursor[ $key ];
			if ( $isList ) {
				$index = $row ?? 0;
				if ( ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
					$cursor[ $index ] = array();
				}
				$cursor = &$cursor[ $index ];
			}
		}
		$cursor = $value;
	}

	/**
	 * Number of rows the list in a target path has so far (the part of the path up to its "[]").
	 *
	 * @param string $path Target path such as "order_line[].sku".
	 */
	public function rows( string $path ): int {
		$cursor = $this->data;
		foreach ( explode( '.', $path ) as $segment ) {
			$isList = '[]' === substr( $segment, -2 );
			$key    = $isList ? substr( $segment, 0, -2 ) : $segment;
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				return 0;
			}
			$cursor = $cursor[ $key ];
			if ( $isList ) {
				return array() === $cursor ? 0 : max( array_keys( $cursor ) ) + 1;
			}
		}
		return 0;
	}

	/**
	 * The finished payload: lists in row order and without empty rows.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		return self::tidy( $this->data );
	}

	/**
	 * Put rows in order and drop empty ones, at every depth.
	 *
	 * @param array<mixed> $node Node.
	 * @return array<mixed>
	 */
	private static function tidy( array $node ): array {
		$integerKeys = array() !== $node && array_keys( $node ) === array_filter( array_keys( $node ), 'is_int' );
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) ) {
				$node[ $key ] = self::tidy( $child );
			}
		}
		if ( ! $integerKeys ) {
			return $node;
		}
		ksort( $node );
		return array_values(
			array_filter(
				$node,
				static fn( mixed $child ): bool => array() !== $child
			)
		);
	}
}
