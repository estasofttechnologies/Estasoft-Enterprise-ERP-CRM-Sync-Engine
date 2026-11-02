<?php
/**
 * A scripted HTTP client for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers requests from a queue of scripted responses, or from route handlers, or from one fallback handler, and records every
 * request. It can script the failures a connector must survive: time-outs, certificate errors, 429 with Retry-After, 5xx and
 * answers that are not JSON. A request with no scripted answer throws, so a test never passes by accident.
 */
final class FakeHttpClient implements HttpClientInterface {

	/**
	 * Every request made, oldest first.
	 *
	 * @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	public array $requests = array();

	/**
	 * Scripted answers used in order.
	 *
	 * @var array<int,HttpResponse|callable>
	 */
	private array $queue = array();

	/**
	 * Route handlers: [method, url pattern, handler].
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse|callable}>
	 */
	private array $routes = array();

	/**
	 * Fallback for requests nothing else answers.
	 *
	 * @var callable|null
	 */
	private $fallback = null;

	/**
	 * Add an answer for the next request that has no route.
	 *
	 * @param HttpResponse|callable $response A response, or a callable receiving the request array and returning one.
	 */
	public function queue( HttpResponse|callable $response ): self {
		$this->queue[] = $response;
		return $this;
	}

	/**
	 * Answer requests whose method matches and whose URL matches a regular expression.
	 *
	 * @param string                $method  HTTP method, or * for any.
	 * @param string                $pattern Regular expression for the URL.
	 * @param HttpResponse|callable $handler Response, or a callable receiving the request array.
	 */
	public function route( string $method, string $pattern, HttpResponse|callable $handler ): self {
		$this->routes[] = array( strtoupper( $method ), $pattern, $handler );
		return $this;
	}

	/**
	 * Answer every request that nothing else answers.
	 *
	 * @param callable $handler Receives the request array, returns an HttpResponse.
	 */
	public function fallback( callable $handler ): self {
		$this->fallback = $handler;
		return $this;
	}

	/**
	 * Record the request and answer it.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Headers.
	 * @param string|null          $body    Body.
	 * @param int                  $timeout Seconds.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 30 ): HttpResponse {
		$request          = array(
			'method'  => strtoupper( $method ),
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $timeout,
		);
		$this->requests[] = $request;

		foreach ( $this->routes as $route ) {
			if ( ( '*' === $route[0] || $route[0] === $request['method'] ) && 1 === preg_match( $route[1], $url ) ) {
				return $this->answer( $route[2], $request );
			}
		}
		if ( array() !== $this->queue ) {
			return $this->answer( array_shift( $this->queue ), $request );
		}
		if ( null !== $this->fallback ) {
			return $this->answer( $this->fallback, $request );
		}
		throw new \LogicException( 'FakeHttpClient has no answer for ' . $request['method'] . ' ' . $url );
	}

	/**
	 * Number of requests made.
	 */
	public function count(): int {
		return count( $this->requests );
	}

	/**
	 * The most recent request.
	 *
	 * @return array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}|null
	 */
	public function last(): ?array {
		return array() === $this->requests ? null : $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Requests whose URL matches a regular expression.
	 *
	 * @param string $pattern Regular expression.
	 * @return array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	public function matching( string $pattern ): array {
		return array_values( array_filter( $this->requests, static fn( array $request ): bool => 1 === preg_match( $pattern, $request['url'] ) ) );
	}

	/**
	 * Everything the client was given, as one string. Tests use it to prove that a secret is never repeated somewhere unexpected.
	 */
	public function transcript(): string {
		return (string) json_encode( $this->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * A JSON answer.
	 *
	 * @param mixed                $data    Value to encode.
	 * @param int                  $status  HTTP status.
	 * @param array<string,string> $headers Headers (lower-case names).
	 */
	public static function json( mixed $data, int $status = 200, array $headers = array() ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), $headers + array( 'content-type' => 'application/json' ) );
	}

	/**
	 * A request that timed out (status 0).
	 */
	public static function timeout(): HttpResponse {
		return new HttpResponse( 0, '', array(), 'timeout: the system did not answer in time', true );
	}

	/**
	 * A certificate that could not be checked (status 0).
	 */
	public static function certificateError(): HttpResponse {
		return new HttpResponse( 0, '', array(), 'certificate: the security certificate could not be checked' );
	}

	/**
	 * A server that cannot be reached (status 0).
	 */
	public static function unreachable(): HttpResponse {
		return new HttpResponse( 0, '', array(), 'connection: the system could not be reached' );
	}

	/**
	 * A status with a plain body.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Headers (lower-case names).
	 */
	public static function status( int $status, string $body = '', array $headers = array() ): HttpResponse {
		return new HttpResponse( $status, $body, $headers );
	}

	/**
	 * A 429 with a Retry-After header.
	 *
	 * @param int $seconds Seconds to wait.
	 */
	public static function tooManyRequests( int $seconds ): HttpResponse {
		return new HttpResponse( 429, '', array( 'retry-after' => (string) $seconds ) );
	}

	/**
	 * A 200 answer that is not JSON.
	 */
	public static function malformedJson(): HttpResponse {
		return new HttpResponse( 200, '{"jsonrpc": "2.0", "result": [', array( 'content-type' => 'application/json' ) );
	}

	/**
	 * Resolve a scripted answer.
	 *
	 * @param HttpResponse|callable $answer  Scripted answer.
	 * @param array<string,mixed>   $request Request.
	 */
	private function answer( HttpResponse|callable $answer, array $request ): HttpResponse {
		if ( $answer instanceof HttpResponse ) {
			return $answer;
		}
		$response = $answer( $request );
		if ( ! $response instanceof HttpResponse ) {
			throw new \LogicException( 'A FakeHttpClient handler must return an HttpResponse.' );
		}
		return $response;
	}
}
