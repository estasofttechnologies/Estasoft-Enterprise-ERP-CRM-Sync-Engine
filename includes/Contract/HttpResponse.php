<?php
/**
 * A response from the external system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal response object so connectors do not depend on WordPress functions.
 */
final class HttpResponse {

	/**
	 * Describe an HTTP response.
	 *
	 * @param int                  $status  HTTP status, or 0 when the request never completed.
	 * @param string               $body    Raw body.
	 * @param array<string,string> $headers Lower-case header names.
	 * @param string               $error   Transport error text (timeout, refused, certificate), empty when none.
	 * @param bool                 $timedOut True when the request hit the time limit.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body = '',
		public readonly array $headers = array(),
		public readonly string $error = '',
		public readonly bool $timedOut = false,
	) {
	}

	/**
	 * Decode the body as JSON.
	 *
	 * @return mixed Decoded value, or null when the body is not JSON.
	 */
	public function json(): mixed {
		$decoded = json_decode( $this->body, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	/**
	 * Seconds from a Retry-After header, or null.
	 */
	public function retryAfter(): ?int {
		$value = $this->headers['retry-after'] ?? null;
		return ( null !== $value && ctype_digit( $value ) ) ? (int) $value : null;
	}
}
