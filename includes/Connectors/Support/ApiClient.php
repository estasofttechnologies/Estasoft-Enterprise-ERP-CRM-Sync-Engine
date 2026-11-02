<?php
/**
 * Calls a vendor API for one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\Auth\SessionAuth;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One client per call site, created by AbstractApiConnector::api(). It builds the URL, applies the authentication, encodes the body, paces
 * the call, sends it through the engine's HttpClientInterface (the only way out), and turns anything that is not a success into an
 * ApiException that carries the ConnectorError. It never retries on its own: the queue retries. The single exception is a 401, where the
 * authentication strategy may refresh its token or sign in again once.
 *
 * Options accepted by every call:
 * - `format`: `json` (default), `form`, `xml` or `raw` (the body is a string sent as given);
 * - `content_type`, `accept`: override the headers the format sets;
 * - `headers`: extra request headers;
 * - `timeout`: seconds, 1 to 30 (default 30);
 * - `allow`: list of non-2xx statuses that are returned as a normal response (for example `array( 404 )`);
 * - `idempotency_key`: sent in the header the connector names (default `Idempotency-Key`);
 * - `action`: what a 403 refused, for the message.
 */
final class ApiClient {

	/**
	 * Largest answer accepted, in bytes.
	 */
	public const MAX_RESPONSE_BYTES = 5242880;

	/**
	 * Longest request time, in seconds.
	 */
	public const MAX_TIMEOUT = 30;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface  $http              The engine's HTTP client.
	 * @param Connection           $connection        Connection with its secrets.
	 * @param string               $baseUrl           Base address; paths are added to it.
	 * @param AuthStrategy         $auth              Authentication.
	 * @param HostPolicy           $policy            Which hosts may be contacted.
	 * @param string               $system            System name for messages.
	 * @param Redaction            $redaction         Removes secrets from every message.
	 * @param RateLimitHints       $limits            The system's limits.
	 * @param RequestPacer|null    $pacer             Shared pacing, or null for none.
	 * @param array<string,string> $defaultHeaders   Headers sent with every call.
	 * @param \Closure|null        $vendorError       Receives an ApiResponse that is not a success, returns a ConnectorError or null for the default mapping.
	 * @param string               $idempotencyHeader Header that carries `idempotency_key`.
	 * @param string               $userAgent         User agent.
	 */
	public function __construct(
		private readonly HttpClientInterface $http,
		private readonly Connection $connection,
		private readonly string $baseUrl,
		private readonly AuthStrategy $auth,
		private readonly HostPolicy $policy,
		private readonly string $system,
		private readonly Redaction $redaction,
		private readonly RateLimitHints $limits,
		private readonly ?RequestPacer $pacer = null,
		private readonly array $defaultHeaders = array(),
		private readonly ?\Closure $vendorError = null,
		private readonly string $idempotencyHeader = 'Idempotency-Key',
		private readonly string $userAgent = '',
	) {
	}

	/**
	 * GET.
	 *
	 * @param string              $path    Path under the base address, or an absolute https URL (a next-page link).
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Ignored for GET, kept so all methods share a signature.
	 * @param array<string,mixed> $options See the class description.
	 *
	 * @throws ApiException On failure.
	 */
	public function get( string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		return $this->request( 'GET', $path, $query, $body, $options );
	}

	/**
	 * POST.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body: an array (encoded by `format`) or a string for `raw`.
	 * @param array<string,mixed> $options See the class description.
	 *
	 * @throws ApiException On failure.
	 */
	public function post( string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		return $this->request( 'POST', $path, $query, $body, $options );
	}

	/**
	 * PUT.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body.
	 * @param array<string,mixed> $options See the class description.
	 *
	 * @throws ApiException On failure.
	 */
	public function put( string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		return $this->request( 'PUT', $path, $query, $body, $options );
	}

	/**
	 * PATCH.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body.
	 * @param array<string,mixed> $options See the class description.
	 *
	 * @throws ApiException On failure.
	 */
	public function patch( string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		return $this->request( 'PATCH', $path, $query, $body, $options );
	}

	/**
	 * DELETE.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body.
	 * @param array<string,mixed> $options See the class description.
	 *
	 * @throws ApiException On failure.
	 */
	public function delete( string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		return $this->request( 'DELETE', $path, $query, $body, $options );
	}

	/**
	 * Send a request.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $path    Path or absolute URL.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body.
	 * @param array<string,mixed> $options Options.
	 *
	 * @throws ApiException On failure.
	 */
	public function request( string $method, string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		$method  = strtoupper( $method );
		$url     = $this->url( $path, $query );
		$request = $this->build( $method, $url, $body, $options );
		$allow   = array_map( 'intval', (array) ( $options['allow'] ?? array() ) );

		$response = $this->dispatch( $request, $options );
		if ( 401 === $response->status() && ! in_array( 401, $allow, true ) && method_exists( $this->auth, 'onUnauthorized' ) && $this->recover() ) {
			$response = $this->dispatch( $request, $options );
		}
		if ( $response->isSuccess() || in_array( $response->status(), $allow, true ) ) {
			return $response;
		}
		throw new ApiException( $this->errorFor( $response, (string) ( $options['action'] ?? '' ) ), $response );
	}

	/**
	 * The redaction in use, so a connector can add values (a tenant id) to it.
	 */
	public function redaction(): Redaction {
		return $this->redaction;
	}

	/**
	 * The base address.
	 */
	public function baseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * Apply authentication, pace, send and check the answer's size.
	 *
	 * @param ApiRequest          $request Request without credentials.
	 * @param array<string,mixed> $options Options.
	 *
	 * @throws ApiException On a transport failure, an oversized answer or a pacing stop.
	 */
	private function dispatch( ApiRequest $request, array $options ): ApiResponse {
		try {
			$signed = $this->auth->apply( $this->connection, $request );
		} catch ( ApiException $exception ) {
			throw $exception->withSystem( $this->system );
		}
		$this->learn( $signed );
		$this->policy->assert( $signed->url, $this->system );
		$this->pacer?->wait( $this->connection->id, $this->limits, $this->system );

		$raw = $this->http->request( $signed->method, $signed->url, $signed->headers, $signed->body, $signed->timeout );
		if ( 0 === $raw->status ) {
			throw new ApiException( HttpErrorMapper::fromTransport( $raw, $this->system ) );
		}
		if ( strlen( $raw->body ) > self::MAX_RESPONSE_BYTES ) {
			throw new ApiException(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $this->system,
						'field'  => __( 'the answer', 'estasoft-erp-crm-sync-engine' ),
						'reason' => __( 'The answer was larger than 5 MB. Narrow the request.', 'estasoft-erp-crm-sync-engine' ),
					),
					$raw->status,
					null,
					null,
					'response too large'
				)
			);
		}
		unset( $options );
		return ApiResponse::fromHttp( $raw );
	}

	/**
	 * Ask the strategy to recover from a 401 (refresh the token, sign in again).
	 *
	 * @throws ApiException When the refresh fails.
	 */
	private function recover(): bool {
		try {
			return (bool) $this->auth->onUnauthorized( $this->connection ); // @phpstan-ignore-line -- optional method, checked with method_exists().
		} catch ( ApiException $exception ) {
			throw $exception->withSystem( $this->system );
		}
	}

	/**
	 * Remember the secrets this request carries, so no message repeats them.
	 *
	 * @param ApiRequest $request Request with credentials.
	 */
	private function learn( ApiRequest $request ): void {
		foreach ( $request->headers as $name => $value ) {
			if ( 1 === preg_match( '/^(authorization|proxy-authorization|cookie|x-.*(key|token|secret|auth).*|.*api-?key.*|.*token.*)$/i', (string) $name ) ) {
				$this->redaction->add( $value );
				$parts = explode( ' ', $value, 2 );
				if ( isset( $parts[1] ) ) {
					$this->redaction->add( $parts[1] );
				}
			}
		}
		$query = (string) wp_parse_url( $request->url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			foreach ( $params as $name => $value ) {
				if ( is_string( $value ) && 1 === preg_match( '/key|token|secret|sig|auth|pass/i', (string) $name ) ) {
					$this->redaction->add( $value );
				}
			}
		}
	}

	/**
	 * The error for an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $action   What a 403 refused.
	 */
	private function errorFor( ApiResponse $response, string $action ): ConnectorError {
		if ( null !== $this->vendorError ) {
			$mapped = ( $this->vendorError )( $response );
			if ( $mapped instanceof ConnectorError ) {
				return $this->withSystem( $mapped );
			}
		}
		$signIn = $this->auth instanceof OAuth2Auth;
		return HttpErrorMapper::fromResponse( $response, $this->system, $this->redaction, $signIn, $action );
	}

	/**
	 * Put the system name into an error from a vendor mapper that left it out, and scrub its text fields.
	 *
	 * @param ConnectorError $error Error.
	 */
	private function withSystem( ConnectorError $error ): ConnectorError {
		$context = $error->context;
		if ( ! isset( $context['system'] ) || '' === (string) $context['system'] ) {
			$context['system'] = $this->system;
		}
		foreach ( array( 'reason', 'field', 'action' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_string( $context[ $key ] ) ) {
				$context[ $key ] = $this->redaction->reason( $context[ $key ] );
			}
		}
		return new ConnectorError( $error->code, $context, $error->httpStatus, $error->retryAfter, $error->classOverride, $this->redaction->reason( $error->detail, 120 ) );
	}

	/**
	 * Build the URL. An absolute URL is accepted only when it is on the connection's own server (see HostPolicy::assertFollowable()).
	 *
	 * @param string              $path  Path or absolute URL.
	 * @param array<string,mixed> $query Query parameters.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function url( string $path, array $query ): string {
		$absolute = str_starts_with( $path, 'https://' ) || str_starts_with( $path, 'http://' );
		$url      = $absolute ? $path : rtrim( $this->baseUrl, '/' ) . '/' . ltrim( $path, '/' );
		if ( $absolute ) {
			// A link the other side sent (next page, Location) must stay on this connection's server; a hostile one is refused here, before
			// the request is built and so before any credential is added to it.
			$this->policy->assertFollowable( $url, $this->baseUrl, $this->system );
		}
		$this->policy->assert( $url, $this->system );
		$filtered = array_filter( $query, static fn( $value ): bool => null !== $value );
		if ( array() !== $filtered ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( array_map( static fn( $value ) => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value, $filtered ), '', '&', PHP_QUERY_RFC3986 );
		}
		return $url;
	}

	/**
	 * Build the request without credentials.
	 *
	 * @param string              $method  Method.
	 * @param string              $url     URL.
	 * @param mixed               $body    Body.
	 * @param array<string,mixed> $options Options.
	 *
	 * @throws ApiException When the body cannot be encoded.
	 */
	private function build( string $method, string $url, mixed $body, array $options ): ApiRequest {
		$format  = (string) ( $options['format'] ?? 'json' );
		$headers = array_filter(
			array(
				'Accept'     => 'xml' === $format ? 'application/xml' : 'application/json',
				'User-Agent' => '' !== $this->userAgent ? $this->userAgent : 'Estasoft-Sync/' . ( defined( 'ESTASOFT_SYNC_VERSION' ) ? ESTASOFT_SYNC_VERSION : '0' ),
			)
		);
		$encoded = null;
		if ( null !== $body && 'GET' !== $method ) {
			list( $encoded, $type )  = $this->encode( $body, $format );
			$headers['Content-Type'] = (string) ( $options['content_type'] ?? $type );
		}
		if ( isset( $options['accept'] ) ) {
			$headers['Accept'] = (string) $options['accept'];
		}
		$headers = array_merge( $headers, $this->defaultHeaders, array_map( 'strval', (array) ( $options['headers'] ?? array() ) ) );
		if ( isset( $options['idempotency_key'] ) && '' !== (string) $options['idempotency_key'] ) {
			$headers[ $this->idempotencyHeader ] = (string) $options['idempotency_key'];
		}
		$timeout = max( 1, min( self::MAX_TIMEOUT, (int) ( $options['timeout'] ?? self::MAX_TIMEOUT ) ) );
		// A value that came from the merchant's settings must not carry a line break into the request head.
		$clean = array();
		foreach ( $headers as $name => $value ) {
			$clean[ ApiRequest::singleLine( (string) $name ) ] = ApiRequest::singleLine( (string) $value );
		}
		return new ApiRequest( $method, $url, $clean, $encoded, $timeout );
	}

	/**
	 * Encode a body.
	 *
	 * @param mixed  $body   Body.
	 * @param string $format json, form, xml or raw.
	 * @return array{0:string,1:string} Encoded body and content type.
	 *
	 * @throws ApiException When encoding fails.
	 */
	private function encode( mixed $body, string $format ): array {
		if ( 'raw' === $format || is_string( $body ) ) {
			return array( (string) $body, 'xml' === $format ? 'application/xml' : ( 'form' === $format ? 'application/x-www-form-urlencoded' : 'application/octet-stream' ) );
		}
		if ( 'form' === $format ) {
			return array( http_build_query( (array) $body, '', '&', PHP_QUERY_RFC1738 ), 'application/x-www-form-urlencoded' );
		}
		if ( 'xml' === $format ) {
			return array( Xml::encode( (array) $body ), 'application/xml' );
		}
		$json = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json ) {
			throw new ApiException(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $this->system,
						'field'  => __( 'the request', 'estasoft-erp-crm-sync-engine' ),
						'reason' => __( 'The data could not be encoded as JSON.', 'estasoft-erp-crm-sync-engine' ),
					),
					null,
					null,
					null,
					'json encode'
				)
			);
		}
		return array( $json, 'application/json' );
	}
}
