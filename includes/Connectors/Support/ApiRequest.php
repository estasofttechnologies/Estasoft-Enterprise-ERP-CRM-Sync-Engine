<?php
/**
 * A request about to be sent.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable. Authentication strategies receive one and return a changed copy.
 */
final class ApiRequest {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string               $method  GET, POST, PUT, PATCH or DELETE.
	 * @param string               $url     Absolute https URL, query string included.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Encoded body.
	 * @param int                  $timeout Seconds (1 to 30).
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
		public readonly array $headers = array(),
		public readonly ?string $body = null,
		public readonly int $timeout = 30,
	) {
	}

	/**
	 * A copy with one header set (an existing header of the same name, in any case, is replaced).
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 */
	public function withHeader( string $name, string $value ): self {
		// A line break in a header name or value would let a pasted secret add headers of its own.
		$name    = self::singleLine( $name );
		$value   = self::singleLine( $value );
		$headers = array();
		foreach ( $this->headers as $key => $existing ) {
			if ( 0 !== strcasecmp( (string) $key, $name ) ) {
				$headers[ $key ] = $existing;
			}
		}
		$headers[ $name ] = $value;
		return new self( $this->method, $this->url, $headers, $this->body, $this->timeout );
	}

	/**
	 * A copy with a query parameter added (or replaced).
	 *
	 * @param string $name  Parameter name.
	 * @param string $value Parameter value.
	 */
	public function withQuery( string $name, string $value ): self {
		$parts    = explode( '#', $this->url, 2 );
		$base     = explode( '?', $parts[0], 2 );
		$existing = array();
		if ( isset( $base[1] ) && '' !== $base[1] ) {
			parse_str( $base[1], $existing );
		}
		$existing[ $name ] = $value;
		$url               = $base[0] . '?' . http_build_query( $existing, '', '&', PHP_QUERY_RFC3986 );
		return new self( $this->method, $url . ( isset( $parts[1] ) ? '#' . $parts[1] : '' ), $this->headers, $this->body, $this->timeout );
	}

	/**
	 * A copy with another body.
	 *
	 * @param string|null $body Encoded body.
	 */
	public function withBody( ?string $body ): self {
		return new self( $this->method, $this->url, $this->headers, $body, $this->timeout );
	}

	/**
	 * A header value, looked up without regard to case.
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
	 * Printing a request never shows its headers or body, which may hold secrets.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'method' => $this->method,
			'url'    => (string) strtok( $this->url, '?' ),
		);
	}

	/**
	 * Remove carriage returns, line feeds and NUL bytes from a text that goes into a header.
	 *
	 * @param string $text Header name or value.
	 */
	public static function singleLine( string $text ): string {
		return str_replace( array( "\r", "\n", "\0" ), '', $text );
	}
}
