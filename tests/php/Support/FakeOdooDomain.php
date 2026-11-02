<?php
/**
 * Evaluates Odoo search domains against plain arrays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Supports the operators the connector uses: =, !=, in, not in, like, =like, ilike, =ilike, >, >=, <, <=, with the prefix
 * operators | & and !. A field path may cross a many2one ("currency_id.name") through the resolver.
 */
final class FakeOdooDomain {

	/**
	 * @param callable $resolve Receives (record, path) and returns the value at the path (many2one as an id, empty as false).
	 */
	public function __construct( private $resolve ) {
	}

	/**
	 * Whether a record matches a domain.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<int,mixed>    $domain Domain.
	 */
	public function matches( array $record, array $domain ): bool {
		$tokens = array_values( $domain );
		$result = true;
		while ( array() !== $tokens ) {
			$matched = $this->expression( $record, $tokens );
			$result  = $result && $matched;
		}
		return $result;
	}

	/**
	 * Consume one expression from the token list.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<int,mixed>    $tokens Tokens (modified).
	 */
	private function expression( array $record, array &$tokens ): bool {
		$token = array_shift( $tokens );
		if ( '|' === $token ) {
			$left  = $this->expression( $record, $tokens );
			$right = $this->expression( $record, $tokens );
			return $left || $right;
		}
		if ( '&' === $token ) {
			$left  = $this->expression( $record, $tokens );
			$right = $this->expression( $record, $tokens );
			return $left && $right;
		}
		if ( '!' === $token ) {
			return ! $this->expression( $record, $tokens );
		}
		return $this->leaf( $record, (array) $token );
	}

	/**
	 * One condition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<int,mixed>    $leaf   [field, operator, value].
	 */
	private function leaf( array $record, array $leaf ): bool {
		list( $path, $operator, $expected ) = $leaf;
		$actual = call_user_func( $this->resolve, $record, (string) $path );

		switch ( $operator ) {
			case '=':
				return $this->equal( $actual, $expected );
			case '!=':
				return ! $this->equal( $actual, $expected );
			case 'in':
				foreach ( (array) $expected as $candidate ) {
					if ( $this->equal( $actual, $candidate ) ) {
						return true;
					}
				}
				return false;
			case 'not in':
				foreach ( (array) $expected as $candidate ) {
					if ( $this->equal( $actual, $candidate ) ) {
						return false;
					}
				}
				return true;
			case '=like':
				return $this->like( $actual, (string) $expected, false );
			case '=ilike':
				return $this->like( $actual, (string) $expected, true );
			case 'like':
				return $this->like( $actual, '%' . $expected . '%', false );
			case 'ilike':
				return $this->like( $actual, '%' . $expected . '%', true );
			case '>=':
				return $this->compare( $actual, $expected ) >= 0;
			case '<=':
				return $this->compare( $actual, $expected ) <= 0;
			case '>':
				return $this->compare( $actual, $expected ) > 0;
			case '<':
				return $this->compare( $actual, $expected ) < 0;
		}
		throw new \LogicException( 'FakeOdooDomain does not support the operator ' . (string) $operator );
	}

	/**
	 * Equality as Odoo sees it: False matches every empty value.
	 *
	 * @param mixed $actual   Value in the record.
	 * @param mixed $expected Value in the domain.
	 */
	private function equal( mixed $actual, mixed $expected ): bool {
		if ( false === $expected || null === $expected ) {
			return false === $actual || null === $actual || '' === $actual || array() === $actual;
		}
		if ( is_array( $actual ) ) {
			return in_array( $expected, $actual, true );
		}
		if ( is_numeric( $actual ) && is_numeric( $expected ) ) {
			return (float) $actual === (float) $expected;
		}
		return $actual === $expected;
	}

	/**
	 * SQL LIKE.
	 *
	 * @param mixed  $actual          Value.
	 * @param string $pattern         Pattern with % and _.
	 * @param bool   $caseInsensitive Whether case is ignored.
	 */
	private function like( mixed $actual, string $pattern, bool $caseInsensitive ): bool {
		if ( ! is_string( $actual ) ) {
			return false;
		}
		$regex = '';
		$chars = preg_split( '//u', $pattern, -1, PREG_SPLIT_NO_EMPTY );
		for ( $i = 0, $n = count( (array) $chars ); $i < $n; $i++ ) {
			$char = $chars[ $i ];
			if ( '\\' === $char && $i + 1 < $n ) {
				$regex .= preg_quote( $chars[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return 1 === preg_match( '/^' . $regex . '$/su' . ( $caseInsensitive ? 'i' : '' ), $actual );
	}

	/**
	 * Three-way comparison of numbers or strings.
	 *
	 * @param mixed $actual   Value.
	 * @param mixed $expected Value.
	 */
	private function compare( mixed $actual, mixed $expected ): int {
		if ( false === $actual || null === $actual ) {
			return -1;
		}
		return is_numeric( $actual ) && is_numeric( $expected ) ? (float) $actual <=> (float) $expected : strcmp( (string) $actual, (string) $expected );
	}
}
