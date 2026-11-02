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
	 * "timeout", "certificate", "unsafe_url" or "connection". "certificate" means the other side's certificate failed
	 * its check (it needs a person); a secure connection that was reset or dropped is a "connection" error. HTTP error
	 * statuses are ordinary responses.
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
	 * Whether a transport error is a failed check of the other side's certificate (SYNC-NET-003, which needs a person).
	 *
	 * Only real verification failures count: cURL 51 (the name on the certificate does not match), 58 (a local certificate
	 * could not be used), 60 (the certificate is not trusted, expired, self signed or has no known issuer), 77 (the file of
	 * trusted authorities could not be read), 83 (issuer check failed), 90 (pinned key does not match), 91 (invalid
	 * certificate chain), and the texts those and the PHP streams transport use: "certificate verify failed", "unable to get
	 * local issuer certificate" and the like. A secure connection that was reset or dropped while it was being set up (cURL 35,
	 * "SSL connect error", "SSL_ERROR_SYSCALL", "unexpected eof", an alert about the handshake) is not a certificate problem:
	 * it usually passes on its own, so it is a connection error that is tried again.
	 *
	 * @param string $message WP_Error message.
	 */
	private static function isCertificateFailure( string $message ): bool {
		if ( 1 === preg_match( '/cURL error (51|58|60|77|83|90|91)\b/i', $message ) ) {
			return true;
		}
		return 1 === preg_match(
			'/certificate verify failed|unable to get (local )?issuer certificate|unable to verify the first certificate|SSL certificate problem|self[- ]signed certificate|certificate has expired|certificate is not yet valid|does not match target host name|no alternative certificate subject name|issuer check against peer certificate failed|public key does not match pinned/i',
			$message
		);
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
		if ( self::isCertificateFailure( $message ) ) {
			return 'certificate: the security certificate could not be checked';
		}
		if ( 1 === preg_match( '/not valid|unsafe|a valid URL was not provided|blocked/i', $message ) ) {
			return 'unsafe_url: the address is not allowed';
		}
		if ( 1 === preg_match( '/cURL error 35\b|SSL|TLS|handshake/i', $message ) ) {
			return 'connection: the secure connection to the system was interrupted';
		}
		return 'connection: the system could not be reached';
	}
}
