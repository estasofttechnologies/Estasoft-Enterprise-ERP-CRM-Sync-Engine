<?php
/**
 * A response from a vendor API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Status, headers and body, with decoders. A body that is not JSON decodes to an empty array.
 */
final class ApiResponse {

	/**
	 * Set up with what it depends on.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $body    Raw body.
	 * @param array<string,string> $headers Headers (any case).
	 */
	public function __construct(
		private readonly int $status,
		private readonly string $body = '',
		private readonly array $headers = array(),
	) {
	}

	/**
	 * From the engine's HTTP response.
	 *
	 * @param HttpResponse $response Response.
	 */
	public static function fromHttp( HttpResponse $response ): self {
		return new self( $response->status, $response->body, $response->headers );
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * A header value, or null. The name is matched without regard to case.
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
	 * Raw body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * The body decoded as JSON. An empty, invalid or non-array body gives an empty array.
	 *
	 * @return array<mixed>
	 */
	public function json(): array {
		$value = $this->jsonValue();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The body decoded as JSON, whatever its type (array, string, number, null).
	 */
	public function jsonValue(): mixed {
		if ( '' === trim( $this->body ) ) {
			return null;
		}
		$decoded = json_decode( $this->body, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	/**
	 * The body parsed as XML, or null when it is not XML. Documents with a DOCTYPE are refused (no entity tricks).
	 */
	public function xml(): ?\SimpleXMLElement {
		$text = trim( $this->body );
		if ( '' === $text || false !== stripos( $text, '<!DOCTYPE' ) || false !== stripos( $text, '<!ENTITY' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $text, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return false === $xml ? null : $xml;
	}

	/**
	 * Whether the status is 2xx.
	 */
	public function isSuccess(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Seconds from a Retry-After header (a number of seconds or an HTTP date), or null.
	 */
	public function retryAfterSeconds(): ?int {
		$value = $this->header( 'retry-after' );
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		if ( '' !== $value && ctype_digit( $value ) ) {
			return (int) $value;
		}
		$time = strtotime( $value );
		if ( false === $time ) {
			return null;
		}
		return max( 0, $time - time() );
	}

	/**
	 * Printing a response shows the status only.
	 *
	 * @return array<string,int>
	 */
	public function __debugInfo(): array {
		return array( 'status' => $this->status );
	}
}
