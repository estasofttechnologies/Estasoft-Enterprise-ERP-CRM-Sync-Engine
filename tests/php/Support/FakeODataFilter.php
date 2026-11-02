<?php
/**
 * A small evaluator for the OData `$filter` subset the ERP connectors send.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Comparison operators (eq ne gt ge lt le), and / or / parentheses, startswith(), contains(), substringof(), string literals with doubled
 * quotes, numbers, bare dates (OData v4), quoted dates, `datetime'...'` literals (OData v2) and booleans. A filter the evaluator cannot read
 * throws, so a malformed filter fails the test instead of matching everything.
 */
final class FakeODataFilter {

	/**
	 * Tokens of the filter.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $tokens = array();

	/**
	 * Position in the tokens.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * The row being tested.
	 *
	 * @var array<string,mixed>
	 */
	private array $row = array();

	/**
	 * Whether string comparisons ignore case.
	 *
	 * @var bool
	 */
	private bool $insensitive = false;

	/**
	 * Whether a row matches a filter.
	 *
	 * @param string              $filter      The `$filter` text; empty matches everything.
	 * @param array<string,mixed> $row         The row.
	 * @param bool                $insensitive True to compare text without regard to case.
	 */
	public static function matches( string $filter, array $row, bool $insensitive = false ): bool {
		if ( '' === trim( $filter ) ) {
			return true;
		}
		$evaluator              = new self();
		$evaluator->tokens      = $evaluator->tokenise( $filter );
		$evaluator->row         = $row;
		$evaluator->insensitive = $insensitive;
		$result                 = $evaluator->orExpression();
		if ( $evaluator->position < count( $evaluator->tokens ) ) {
			throw new \LogicException( 'Unread filter text near token ' . $evaluator->position . ' in: ' . $filter );
		}
		return (bool) $result;
	}

	/**
	 * Split a filter into tokens.
	 *
	 * @param string $filter Filter text.
	 * @return array<int,array{0:string,1:string}> Pairs of type (str, num, id, op, sym) and text.
	 */
	private function tokenise( string $filter ): array {
		$tokens = array();
		$length = strlen( $filter );
		for ( $i = 0; $i < $length; ) {
			$char = $filter[ $i ];
			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}
			if ( "'" === $char ) {
				$text = '';
				++$i;
				while ( $i < $length ) {
					if ( "'" === $filter[ $i ] ) {
						if ( $i + 1 < $length && "'" === $filter[ $i + 1 ] ) {
							$text .= "'";
							$i    += 2;
							continue;
						}
						break;
					}
					$text .= $filter[ $i++ ];
				}
				++$i;
				$tokens[] = array( 'str', $text );
				continue;
			}
			if ( false !== strpos( '(),', $char ) ) {
				$tokens[] = array( 'sym', $char );
				++$i;
				continue;
			}
			if ( 1 === preg_match( '/\G\d{4}-\d{2}-\d{2}(?:T[0-9:.Z]+)?/', $filter, $m, 0, $i ) ) {
				$tokens[] = array( 'str', $m[0] );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( 1 === preg_match( '/\G-?\d+(?:\.\d+)?/', $filter, $m, 0, $i ) ) {
				$tokens[] = array( 'num', $m[0] );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( 1 === preg_match( '/\G[A-Za-z_][A-Za-z0-9_\/.]*/', $filter, $m, 0, $i ) ) {
				$word = $m[0];
				$i   += strlen( $word );
				if ( 'datetime' === strtolower( $word ) && "'" === ( $filter[ $i ] ?? '' ) ) {
					continue;
				}
				$tokens[] = in_array( strtolower( $word ), array( 'and', 'or', 'eq', 'ne', 'gt', 'ge', 'lt', 'le' ), true ) ? array( 'op', strtolower( $word ) ) : array( 'id', $word );
				continue;
			}
			throw new \LogicException( 'Cannot read the filter at: ' . substr( $filter, $i ) );
		}
		return $tokens;
	}

	/**
	 * Or expression.
	 */
	private function orExpression(): mixed {
		$value = $this->andExpression();
		while ( $this->peek( 'op', 'or' ) ) {
			++$this->position;
			$right = $this->andExpression();
			$value = $value || $right;
		}
		return $value;
	}

	/**
	 * And expression.
	 */
	private function andExpression(): mixed {
		$value = $this->comparison();
		while ( $this->peek( 'op', 'and' ) ) {
			++$this->position;
			$right = $this->comparison();
			$value = $value && $right;
		}
		return $value;
	}

	/**
	 * A comparison, a function call or a parenthesised expression.
	 */
	private function comparison(): mixed {
		if ( $this->peek( 'sym', '(' ) ) {
			++$this->position;
			$value = $this->orExpression();
			$this->expect( 'sym', ')' );
			return $value;
		}
		$left = $this->operand();
		if ( isset( $this->tokens[ $this->position ] ) && 'op' === $this->tokens[ $this->position ][0] && ! in_array( $this->tokens[ $this->position ][1], array( 'and', 'or' ), true ) ) {
			$operator = $this->tokens[ $this->position++ ][1];
			$right    = $this->operand();
			return $this->compare( $left, $operator, $right );
		}
		return $left;
	}

	/**
	 * A literal, a property or a function call.
	 */
	private function operand(): mixed {
		$token = $this->tokens[ $this->position ] ?? null;
		if ( null === $token ) {
			throw new \LogicException( 'The filter ends too early.' );
		}
		++$this->position;
		if ( 'str' === $token[0] ) {
			return $token[1];
		}
		if ( 'num' === $token[0] ) {
			return str_contains( $token[1], '.' ) ? (float) $token[1] : (int) $token[1];
		}
		if ( 'id' === $token[0] && $this->peek( 'sym', '(' ) ) {
			++$this->position;
			$arguments = array( $this->operand() );
			while ( $this->peek( 'sym', ',' ) ) {
				++$this->position;
				$arguments[] = $this->operand();
			}
			$this->expect( 'sym', ')' );
			return $this->call( strtolower( $token[1] ), $arguments );
		}
		if ( 'id' === $token[0] ) {
			if ( in_array( strtolower( $token[1] ), array( 'true', 'false' ), true ) ) {
				return 'true' === strtolower( $token[1] );
			}
			return $this->row[ $token[1] ] ?? null;
		}
		throw new \LogicException( 'Unexpected filter token ' . $token[1] );
	}

	/**
	 * A string function.
	 *
	 * @param string           $name      Function name.
	 * @param array<int,mixed> $arguments Arguments.
	 */
	private function call( string $name, array $arguments ): bool {
		$a = $this->text( $arguments[0] ?? '' );
		$b = $this->text( $arguments[1] ?? '' );
		return match ( $name ) {
			'startswith' => str_starts_with( $a, $b ),
			'contains'   => '' === $b || str_contains( $a, $b ),
			'substringof' => '' === $a || str_contains( $b, $a ),
			default      => throw new \LogicException( 'Unsupported function ' . $name ),
		};
	}

	/**
	 * Compare two values.
	 *
	 * @param mixed  $left     Left value.
	 * @param string $operator eq, ne, gt, ge, lt or le.
	 * @param mixed  $right    Right value.
	 */
	private function compare( mixed $left, string $operator, mixed $right ): bool {
		if ( is_numeric( $left ) && is_numeric( $right ) && ! ( is_string( $left ) && is_string( $right ) && ! is_numeric( $left ) ) ) {
			$order = (float) $left <=> (float) $right;
		} else {
			$order = $this->text( $left ) <=> $this->text( $right );
		}
		return match ( $operator ) {
			'eq'    => 0 === $order,
			'ne'    => 0 !== $order,
			'gt'    => $order > 0,
			'ge'    => $order >= 0,
			'lt'    => $order < 0,
			default => $order <= 0,
		};
	}

	/**
	 * A value as text, lower-cased when comparisons ignore case.
	 *
	 * @param mixed $value Value.
	 */
	private function text( mixed $value ): string {
		$text = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) ( is_scalar( $value ) ? $value : '' );
		return $this->insensitive ? strtolower( $text ) : $text;
	}

	/**
	 * Whether the next token is the given one.
	 *
	 * @param string $type Token type.
	 * @param string $text Token text.
	 */
	private function peek( string $type, string $text ): bool {
		return isset( $this->tokens[ $this->position ] ) && $this->tokens[ $this->position ] === array( $type, $text );
	}

	/**
	 * Require the next token.
	 *
	 * @param string $type Token type.
	 * @param string $text Token text.
	 */
	private function expect( string $type, string $text ): void {
		if ( ! $this->peek( $type, $text ) ) {
			throw new \LogicException( 'Expected ' . $text . ' in the filter.' );
		}
		++$this->position;
	}
}
