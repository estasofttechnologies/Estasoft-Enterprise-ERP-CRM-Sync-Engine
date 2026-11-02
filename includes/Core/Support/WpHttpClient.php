<?php
/**
 * Outgoing HTTP through WordPress.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * The only class that calls wp_remote_request. It enforces the engine's network rules: 30 second
 * limit, certificate checks always on, unsafe addresses refused, no redirects, bounded answer size.
 * It never logs a request or an answer.
 */
final class WpHttpClient implements HttpClientInterface {

	/**
	 * The longest a request may take, in seconds.
	 */
	public const MAX_TIMEOUT = 30;

	/**
	 * The largest answer kept, in bytes.
	 */
	private const MAX_RESPONSE_BYTES = 8388608;

	/**
	 * Send a request.
	 *
	 * Transport failures come back as a response with status 0 and an error that starts with one of
	 * "timeout", "certificate", "unsafe_url" or "connection". HTTP error statuses are ordinary responses.
	 *
	 * @param string               $method  GET, POST, PATCH, PUT or DELETE.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw body.
	 * @param int                  $timeout Seconds; never more than 30.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = self::MAX_TIMEOUT ): HttpResponse {
		$args = array(
			'method'              => strtoupper( $method ),
			'timeout'             => max( 1, min( self::MAX_TIMEOUT, $timeout ) ),
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => ! (bool) apply_filters( 'estasoft_sync_allow_private_hosts', false, $url ),
			'headers'             => $headers,
			'user-agent'          => 'Estasoft-Sync/' . ( defined( 'ESTASOFT_SYNC_VERSION' ) ? ESTASOFT_SYNC_VERSION : '0' ),
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'decompress'          => true,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		/*
		 * WordPress refuses ports other than 80, 443 and 8080 when reject_unsafe_urls is on. A system such as
		 * Odoo often listens on another public port. The address was typed by an administrator, so allow
		 * exactly the port of this request, for this request only. Private and loopback hosts stay refused.
		 */
		$port_filter = $this->allowPortFilter( $url );
		add_filter( 'http_allowed_safe_ports', $port_filter, 10, 1 );
		try {
			$result = wp_remote_request( $url, $args );
		} finally {
			remove_filter( 'http_allowed_safe_ports', $port_filter, 10 );
		}

		if ( is_wp_error( $result ) ) {
			return new HttpResponse( 0, '', array(), self::describeError( $result->get_error_message() ), self::isTimeout( $result->get_error_message() ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $result );
		$raw     = wp_remote_retrieve_headers( $result );
		$headers = array();
		foreach ( ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) ? $raw->getAll() : (array) $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return new HttpResponse( $status, (string) wp_remote_retrieve_body( $result ), $headers );
	}

	/**
	 * A filter callback that adds the port of one URL to the allowed list.
	 *
	 * @param string $url Request URL.
	 */
	private function allowPortFilter( string $url ): \Closure {
		$port = wp_parse_url( $url, PHP_URL_PORT );
		return static function ( $ports ) use ( $port ) {
			$ports = (array) $ports;
			if ( is_int( $port ) && $port > 0 && $port < 65536 ) {
				$ports[] = $port;
			}
			return $ports;
		};
	}

	/**
	 * Whether a transport error was a timeout.
	 *
	 * @param string $message WP_Error message.
	 */
	private static function isTimeout( string $message ): bool {
		return 1 === preg_match( '/timed out|timeout|cURL error 28/i', $message );
	}

	/**
	 * Turn a WordPress transport error into a short classified text without any URL or body in it.
	 *
	 * @param string $message WP_Error message.
	 */
	private static function describeError( string $message ): string {
		if ( self::isTimeout( $message ) ) {
			return 'timeout: the system did not answer in time';
		}
		if ( 1 === preg_match( '/ssl|certificate|cURL error (35|51|58|60|77|83|90|91)/i', $message ) ) {
			return 'certificate: the security certificate could not be checked';
		}
		if ( 1 === preg_match( '/not valid|unsafe|a valid URL was not provided|blocked/i', $message ) ) {
			return 'unsafe_url: the address is not allowed';
		}
		return 'connection: the system could not be reached';
	}
}
