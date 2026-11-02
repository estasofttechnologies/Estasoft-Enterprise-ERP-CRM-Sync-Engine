<?php
/**
 * A tiny routing fake for the wire behaviour of a vendor API.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Built on FakeHttpClient. Routes are `route( 'POST', '/v1/invoices/{id}', fn( ApiFakeRequest $request ): HttpResponse|array|null => ... )`: a handler
 * returns an HttpResponse, an array (sent as a 200 JSON answer) or null (404). Every request is recorded. Failures are scripted per route with
 * `failNext()`. A request to an unknown route answers 404 and is recorded, so a test can assert that nothing unexpected was called.
 *
 *     $server = new ApiFakeServer( 'api.acme.test' );
 *     $server->route( 'GET', '/v1/company', fn() => array( 'name' => 'Acme' ) );
 *     $connector = new AcmeConnector( $kit->deps );   // $kit->http is $server->http when built with ApiFakeServer::on( $kit->http, ... )
 */
final class ApiFakeServer {

	/**
	 * The fake network.
	 */
	public FakeHttpClient $http;

	/**
	 * Requests received, oldest first.
	 *
	 * @var array<int,ApiFakeRequest>
	 */
	public array $received = array();

	/**
	 * Routes: [method, regex, handler].
	 *
	 * @var array<int,array{0:string,1:string,2:\Closure}>
	 */
	private array $routes = array();

	/**
	 * Scripted failures: [method, regex or empty, response, times left].
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse,3:int}>
	 */
	private array $failures = array();

	/**
	 * @param string|array<int,string> $host Host name(s) the server answers for; requests to another host answer 404.
	 * @param FakeHttpClient|null $http The fake network to attach to; a new one by default.
	 */
	public function __construct( private readonly string|array $host, ?FakeHttpClient $http = null ) {
		$this->http = $http ?? new FakeHttpClient();
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Attach a server to an existing fake network (the one in an ApiTestKit).
	 *
	 * @param FakeHttpClient $http Network.
	 * @param string|array<int,string> $host Host(s).
	 */
	public static function on( FakeHttpClient $http, string|array $host ): self {
		return new self( $host, $http );
	}

	/**
	 * Add a route. `{name}` in the path matches one path segment and is passed in `$request->params`.
	 *
	 * @param string   $method  Method or `*`.
	 * @param string   $path    Path pattern.
	 * @param \Closure $handler Receives ApiFakeRequest; returns HttpResponse, an array or null.
	 */
	public function route( string $method, string $path, \Closure $handler ): self {
		$regex          = '#^' . preg_replace( '/\\\{([a-z_]+)\\\}/', '(?P<$1>[^/]+)', preg_quote( $path, '#' ) ) . '$#';
		$this->routes[] = array( strtoupper( $method ), (string) $regex, $handler );
		return $this;
	}

	/**
	 * Make the next requests to a route fail.
	 *
	 * @param string       $method   Method or `*`.
	 * @param string       $path     Path (exact) or empty for any path.
	 * @param HttpResponse $response What to answer.
	 * @param int          $times    How many requests fail.
	 */
	public function failNext( string $method, string $path, HttpResponse $response, int $times = 1 ): self {
		$this->failures[] = array( strtoupper( $method ), $path, $response, $times );
		return $this;
	}

	/**
	 * Requests received for a method and a path pattern.
	 *
	 * @param string|null $method Method, or any.
	 * @param string|null $regex  Regular expression for the path, or any.
	 * @return array<int,ApiFakeRequest>
	 */
	public function requests( ?string $method = null, ?string $regex = null ): array {
		return array_values(
			array_filter(
				$this->received,
				static fn( ApiFakeRequest $request ): bool => ( null === $method || strtoupper( $method ) === $request->method ) && ( null === $regex || 1 === preg_match( $regex, $request->path ) )
			)
		);
	}

	/**
	 * The most recent request.
	 */
	public function last(): ?ApiFakeRequest {
		return array() === $this->received ? null : $this->received[ count( $this->received ) - 1 ];
	}

	/**
	 * Page through a list the way cursor APIs do.
	 *
	 * @param array<int,mixed> $items    All items.
	 * @param int              $perPage  Page size.
	 * @param string|null      $cursor   Cursor from the request (an offset), or null for the first page.
	 * @return array{0:array<int,mixed>,1:string|null} The page and the next cursor (null on the last page).
	 */
	public static function page( array $items, int $perPage, ?string $cursor ): array {
		$offset = null === $cursor || '' === $cursor ? 0 : (int) $cursor;
		$slice  = array_slice( $items, $offset, $perPage );
		$next   = $offset + $perPage < count( $items ) ? (string) ( $offset + $perPage ) : null;
		return array( $slice, $next );
	}

	/**
	 * Answer a request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $raw Raw request.
	 */
	private function handle( array $raw ): HttpResponse {
		$parts = (array) wp_parse_url( $raw['url'] );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$request          = new ApiFakeRequest( $raw['method'], (string) ( $parts['path'] ?? '/' ), $query, $raw['headers'], (string) $raw['body'], array(), $raw['url'] );
		$this->received[] = $request;
		if ( ! in_array( $parts['host'] ?? '', (array) $this->host, true ) ) {
			return FakeHttpClient::status( 404, 'unknown host' );
		}
		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $request->method ) && ( '' === $failure[1] || $failure[1] === $request->path ) && $failure[3] > 0 ) {
				--$this->failures[ $index ][3];
				return $failure[2];
			}
		}
		foreach ( $this->routes as $route ) {
			if ( ( '*' === $route[0] || $route[0] === $request->method ) && 1 === preg_match( $route[1], $request->path, $matches ) ) {
				$request->params = array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY );
				$answer          = ( $route[2] )( $request );
				if ( $answer instanceof HttpResponse ) {
					return $answer;
				}
				return null === $answer ? FakeHttpClient::status( 404, '{"message":"Not found"}' ) : FakeHttpClient::json( $answer );
			}
		}
		return FakeHttpClient::status( 404, '{"message":"No route"}' );
	}
}
