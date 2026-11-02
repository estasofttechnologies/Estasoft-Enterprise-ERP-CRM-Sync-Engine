<?php
/**
 * OData query evaluation for the fake Microsoft servers.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Evaluates the part of OData v4 the connectors use: `$filter` with eq, ne, ge, le, gt, lt, and, or, parentheses, startswith, contains,
 * tolower, toupper; `$select`, `$orderby` (one field), `$top`, and server-driven paging with `@odata.nextLink`. A test sets how the
 * fake treats plain `eq` on text (case sensitive, as a Business Central text filter may be, or insensitive, as Dataverse documents).
 */
final class FakeMicrosoftOData {

	/**
	 * Whether `eq` on text ignores case when the filter does not use tolower().
	 *
	 * @var bool
	 */
	public bool $caseInsensitiveEq = false;

	/**
	 * Whether tolower() and toupper() are understood (when false the fake answers 400 as a service without them would).
	 *
	 * @var bool
	 */
	public bool $supportsCase = true;

	/**
	 * Whether a `*` in a string `eq` is a wildcard (Finance and Operations documents this).
	 *
	 * @var bool
	 */
	public bool $wildcardEq = false;

	/**
	 * Rows per page; 0 sends everything in one page.
	 *
	 * @var int
	 */
	public int $pageSize = 0;

	/**
	 * Evaluate a request's query options against rows.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string                         $url    Request URL (its query holds the options).
	 * @param string                         $base   URL without query, for the next link.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function query( array $rows, string $url, string $base ): array {
		$options = $this->options( $url );
		try {
			if ( isset( $options['$filter'] ) ) {
				$rows = array_values( array_filter( $rows, fn( array $row ): bool => (bool) $this->evaluate( $this->tokens( $options['$filter'] ), $row ) ) );
			}
		} catch ( \UnexpectedValueException $exception ) {
			return array(
				'status' => 400,
				'body'   => array(
					'error' => array(
						'code'    => 'BadRequest',
						'message' => $exception->getMessage(),
					),
				),
			);
		}
		if ( isset( $options['$orderby'] ) ) {
			$field = trim( explode( ' ', $options['$orderby'] )[0] );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a[ $field ] ?? '' ), (string) ( $b[ $field ] ?? '' ) ) );
		}
		if ( isset( $options['$top'] ) ) {
			$rows = array_slice( $rows, 0, (int) $options['$top'] );
		}
		$offset = (int) ( $options['$skiptoken'] ?? 0 );
		$next   = null;
		if ( $this->pageSize > 0 && count( $rows ) - $offset > $this->pageSize ) {
			$next = $base . '?' . $this->rebuild( $options, $offset + $this->pageSize );
		}
		if ( $this->pageSize > 0 ) {
			$rows = array_slice( $rows, $offset, $this->pageSize );
		}
		$select = isset( $options['$select'] ) ? array_map( 'trim', explode( ',', $options['$select'] ) ) : array();
		$out    = array();
		foreach ( $rows as $row ) {
			$out[] = array() === $select ? $row : array_intersect_key( $row, array_flip( $select ) + array( '@odata.etag' => 1 ) + $this->expanded( $options ) );
		}
		$body = array( 'value' => $out );
		if ( null !== $next ) {
			$body['@odata.nextLink'] = $next;
		}
		return array(
			'status' => 200,
			'body'   => $body,
		);
	}

	/**
	 * Names of the properties an `$expand` asks for.
	 *
	 * @param array<string,string> $options Options.
	 * @return array<string,int>
	 */
	private function expanded( array $options ): array {
		if ( ! isset( $options['$expand'] ) ) {
			return array();
		}
		return array( (string) preg_replace( '/\(.*/', '', $options['$expand'] ) => 1 );
	}

	/**
	 * The query options of a URL, decoded.
	 *
	 * @param string $url URL.
	 * @return array<string,string>
	 */
	public function options( string $url ): array {
		$query   = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$options = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$options[ rawurldecode( $parts[0] ) ] = rawurldecode( $parts[1] ?? '' );
		}
		return $options;
	}

	/**
	 * The query string of the next page.
	 *
	 * @param array<string,string> $options Options of the first request.
	 * @param int                  $offset  Offset of the next page.
	 */
	private function rebuild( array $options, int $offset ): string {
		$options['$skiptoken'] = (string) $offset;
		$parts                 = array();
		foreach ( $options as $name => $value ) {
			$parts[] = $name . '=' . rawurlencode( $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * Split a filter into tokens.
	 *
	 * @param string $filter Filter text.
	 * @return array<int,string>
	 */
	private function tokens( string $filter ): array {
		preg_match_all( "/'(?:[^']|'')*'|\\(|\\)|,|[^\\s(),']+/", $filter, $found );
		return $found[0];
	}

	/**
	 * Evaluate tokens as `or` of `and` of terms.
	 *
	 * @param array<int,string>   $tokens Tokens.
	 * @param array<string,mixed> $row    Row.
	 */
	private function evaluate( array $tokens, array $row ): bool {
		$pos = 0;
		return $this->orExpr( $tokens, $pos, $row );
	}

	/**
	 * Or-expression.
	 *
	 * @param array<int,string>   $t   Tokens.
	 * @param int                 $pos Position.
	 * @param array<string,mixed> $row Row.
	 */
	private function orExpr( array $t, int &$pos, array $row ): bool {
		$value = $this->andExpr( $t, $pos, $row );
		while ( isset( $t[ $pos ] ) && 'or' === strtolower( $t[ $pos ] ) ) {
			++$pos;
			$right = $this->andExpr( $t, $pos, $row );
			$value = $value || $right;
		}
		return $value;
	}

	/**
	 * And-expression.
	 *
	 * @param array<int,string>   $t   Tokens.
	 * @param int                 $pos Position.
	 * @param array<string,mixed> $row Row.
	 */
	private function andExpr( array $t, int &$pos, array $row ): bool {
		$value = $this->term( $t, $pos, $row );
		while ( isset( $t[ $pos ] ) && 'and' === strtolower( $t[ $pos ] ) ) {
			++$pos;
			$right = $this->term( $t, $pos, $row );
			$value = $value && $right;
		}
		return $value;
	}

	/**
	 * One term: a group, a function call or a comparison.
	 *
	 * @param array<int,string>   $t   Tokens.
	 * @param int                 $pos Position.
	 * @param array<string,mixed> $row Row.
	 */
	private function term( array $t, int &$pos, array $row ): bool {
		if ( '(' === ( $t[ $pos ] ?? '' ) ) {
			++$pos;
			$value = $this->orExpr( $t, $pos, $row );
			++$pos;
			return $value;
		}
		$name = $t[ $pos ] ?? '';
		if ( in_array( $name, array( 'startswith', 'contains', 'endswith' ), true ) && '(' === ( $t[ $pos + 1 ] ?? '' ) ) {
			$field = $t[ $pos + 2 ];
			$text  = $this->literal( $t[ $pos + 4 ] );
			$pos  += 6;
			$have  = mb_strtolower( (string) ( $row[ $field ] ?? '' ) );
			$text  = mb_strtolower( (string) $text );
			return match ( $name ) {
				'startswith' => str_starts_with( $have, $text ),
				'endswith'   => str_ends_with( $have, $text ),
				default      => str_contains( $have, $text ),
			};
		}
		$wrapper = '';
		if ( in_array( $name, array( 'tolower', 'toupper' ), true ) && '(' === ( $t[ $pos + 1 ] ?? '' ) ) {
			if ( ! $this->supportsCase ) {
				throw new \UnexpectedValueException( 'Unknown function: ' . $name );
			}
			$wrapper = $name;
			$field   = $t[ $pos + 2 ];
			$pos    += 4;
		} else {
			$field = $name;
			++$pos;
		}
		$operator = strtolower( $t[ $pos ] ?? '' );
		$right    = $this->literal( $t[ $pos + 1 ] ?? '' );
		$pos     += 2;
		$left     = $row[ $field ] ?? null;
		if ( 'tolower' === $wrapper ) {
			$left = mb_strtolower( (string) $left );
		} elseif ( 'toupper' === $wrapper ) {
			$left = mb_strtoupper( (string) $left );
		}
		return $this->compare( $left, $operator, $right, '' === $wrapper );
	}

	/**
	 * Compare two values.
	 *
	 * @param mixed  $left     Value of the row.
	 * @param string $operator eq, ne, ge, gt, le, lt.
	 * @param mixed  $right    Literal.
	 * @param bool   $plain    True when no case function was used.
	 */
	private function compare( mixed $left, string $operator, mixed $right, bool $plain ): bool {
		if ( $this->wildcardEq && 'eq' === $operator && is_string( $right ) && str_contains( $right, '*' ) ) {
			$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $right, '/' ) ) . '$/ui';
			return 1 === preg_match( $pattern, (string) $left );
		}
		if ( is_string( $left ) && is_string( $right ) && $plain && $this->caseInsensitiveEq && in_array( $operator, array( 'eq', 'ne' ), true ) ) {
			$left  = mb_strtolower( $left );
			$right = mb_strtolower( $right );
		}
		if ( is_bool( $left ) || is_bool( $right ) ) {
			$left  = (bool) $left;
			$right = (bool) $right;
		}
		return match ( $operator ) {
			'eq'    => $left === $right || ( is_numeric( $left ) && is_numeric( $right ) && (float) $left === (float) $right ),
			'ne'    => ! ( $left === $right || ( is_numeric( $left ) && is_numeric( $right ) && (float) $left === (float) $right ) ),
			'ge'    => (string) $left >= (string) $right,
			'gt'    => (string) $left > (string) $right,
			'le'    => (string) $left <= (string) $right,
			'lt'    => (string) $left < (string) $right,
			default => throw new \UnexpectedValueException( 'Unknown operator: ' . $operator ),
		};
	}

	/**
	 * A literal: a quoted string, a number, a boolean, null or a bare date.
	 *
	 * @param string $token Token.
	 * @return mixed
	 */
	private function literal( string $token ): mixed {
		if ( str_starts_with( $token, "'" ) ) {
			return str_replace( "''", "'", substr( $token, 1, -1 ) );
		}
		return match ( strtolower( $token ) ) {
			'true'  => true,
			'false' => false,
			'null'  => null,
			default => is_numeric( $token ) ? $token + 0 : $token,
		};
	}
}
