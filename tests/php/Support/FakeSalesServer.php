<?php
/**
 * Base of the small in-memory vendor servers used by the CRM and accounting connector tests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Parses a request, applies a scripted one-shot failure to the next write, and hands the rest to the vendor fake.
 */
abstract class FakeSalesServer {

	/**
	 * Every request as "METHOD /path", for assertions on what was called.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();

	/**
	 * Writes that reached the vendor (not counting failed ones).
	 */
	public int $writes = 0;

	/**
	 * Scripted failure for the next write.
	 *
	 * @var array{0:string,1:int}|null
	 */
	protected ?array $failure = null;

	/**
	 * Writes that pass before the scripted failure strikes.
	 */
	protected int $passFirst = 0;

	/**
	 * Route a request to the vendor's behaviour.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $path    Path without query.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param array<string,mixed> $body    Decoded JSON body.
	 * @param array<string,string> $headers Request headers, lower-case names.
	 */
	abstract protected function route( string $method, string $path, array $query, array $body, array $headers ): HttpResponse;

	/**
	 * Whether the request is a write that a scripted failure may hit.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 */
	protected function isWrite( string $method, string $path ): bool {
		return 'GET' !== $method && ! str_contains( $path, '/search' );
	}

	/**
	 * The vendor's body for a failure kind other than the generic ones.
	 *
	 * @param string $kind    unknown_fault or another vendor-specific kind.
	 * @param int    $seconds Seconds for a rate limit.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => new HttpResponse( 429, '{"message":"slow down"}', array( 'retry-after' => (string) $seconds ) ),
			'server_error' => new HttpResponse( 500, '{"message":"internal error"}' ),
			'auth'         => new HttpResponse( 401, '{"message":"unauthorized"}' ),
			default        => new HttpResponse( 418, '{"unexpected":"shape"}' ),
		};
	}

	/**
	 * Make the next write fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 * @param int    $afterWrites Writes that succeed first.
	 */
	public function failNextWrite( string $kind, int $seconds = 0, int $afterWrites = 0 ): void {
		$this->failure   = array( $kind, $seconds );
		$this->passFirst = $afterWrites;
	}

	/**
	 * Plug the server into a FakeHttpClient.
	 *
	 * @param FakeHttpClient $http Client.
	 */
	public function install( FakeHttpClient $http ): void {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Answer one request.
	 *
	 * @param array<string,mixed> $request method, url, headers, body, timeout.
	 */
	public function handle( array $request ): HttpResponse {
		$parts   = parse_url( (string) $request['url'] );
		$path    = rawurldecode( (string) ( $parts['path'] ?? '' ) );
		$query   = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$headers = array();
		foreach ( (array) $request['headers'] as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = (string) $value;
		}
		$raw  = (string) ( $request['body'] ?? '' );
		$body = array();
		if ( '' !== $raw && in_array( $raw[0], array( '{', '[' ), true ) ) {
			$body = (array) json_decode( $raw, true );
		} elseif ( '' !== $raw ) {
			parse_str( $raw, $body );
		}
		$method        = strtoupper( (string) $request['method'] );
		$this->calls[] = $method . ' ' . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( null !== $this->failure && $this->isWrite( $method, $path ) && $this->passFirst > 0 ) {
			--$this->passFirst;
		} elseif ( null !== $this->failure && $this->isWrite( $method, $path ) ) {
			list( $kind, $seconds ) = $this->failure;
			$this->failure          = null;
			return match ( $kind ) {
				'timeout'     => FakeHttpClient::timeout(),
				'certificate' => FakeHttpClient::certificateError(),
				default       => $this->failureResponse( $kind, $seconds ),
			};
		}
		$response = $this->route( $method, $path, $query, $body, $headers );
		if ( $this->isWrite( $method, $path ) && $response->status < 300 ) {
			++$this->writes;
		}
		return $response;
	}

	/**
	 * A JSON answer.
	 *
	 * @param mixed                $data    Data.
	 * @param int                  $status  Status.
	 * @param array<string,string> $headers Headers.
	 */
	protected function json( mixed $data, int $status = 200, array $headers = array() ): HttpResponse {
		return FakeHttpClient::json( $data, $status, $headers );
	}

	/**
	 * Count calls whose "METHOD /path" starts with a prefix.
	 *
	 * @param string $prefix Prefix such as "POST /crm/objects".
	 */
	public function countCalls( string $prefix ): int {
		return count( array_filter( $this->calls, static fn( string $call ): bool => str_starts_with( $call, $prefix ) ) );
	}
}
