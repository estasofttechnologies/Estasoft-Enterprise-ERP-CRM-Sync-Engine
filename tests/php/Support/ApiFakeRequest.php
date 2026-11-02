<?php
/**
 * A request as the fake vendor server sees it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Method, path without the query, decoded query, headers (any case), raw body and the values of `{placeholders}` in the matched route.
 */
final class ApiFakeRequest {

	/**
	 * @param string               $method  Method.
	 * @param string               $path    Path.
	 * @param array<string,mixed>  $query   Query.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @param array<string,string> $params  Route parameters.
	 * @param string               $url     Full URL.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query,
		public readonly array $headers,
		public readonly string $body,
		public array $params = array(),
		public readonly string $url = '',
	) {
	}

	/**
	 * A header, any case.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * The body decoded as JSON (empty array when it is not JSON).
	 *
	 * @return array<mixed>
	 */
	public function json(): array {
		$data = json_decode( $this->body, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * The body decoded as a form.
	 *
	 * @return array<string,mixed>
	 */
	public function form(): array {
		parse_str( $this->body, $fields );
		return $fields;
	}

	/**
	 * The token in the Authorization header after `Bearer `, or an empty string.
	 */
	public function bearer(): string {
		$value = (string) $this->header( 'Authorization' );
		return str_starts_with( $value, 'Bearer ' ) ? substr( $value, 7 ) : '';
	}
}
