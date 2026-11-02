<?php
/**
 * A tiny in-memory Odoo server that answers over both transports.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Plug it in where the connector expects an HttpClientInterface. It answers GET /web/version (Odoo 19 and later only),
 * POST /jsonrpc (Odoo 17 and later) and POST /json/2/{model}/{method} (Odoo 19 and later), with the same status codes, error
 * bodies and quirks as the real server: a wrong database is a 404 page on JSON-2 and a database error on JSON-RPC; create
 * must be called with its values as the first positional argument over JSON-RPC; model-level methods refuse ids over
 * JSON-2; a wrong key is a 401. Data lives in a FakeOdooDatabase, which tests fill and inspect.
 */
final class FakeOdooServer implements HttpClientInterface {

	/**
	 * The data.
	 *
	 * @var FakeOdooDatabase
	 */
	public FakeOdooDatabase $db;

	/**
	 * Every request made, oldest first.
	 *
	 * @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	public array $requests = array();

	/**
	 * Services called over JSON-RPC, so a test can prove the database service is never used.
	 *
	 * @var array<int,string>
	 */
	public array $services = array();

	/**
	 * Whether the old RPC endpoints have been removed (a future Odoo).
	 *
	 * @var bool
	 */
	public bool $jsonrpcRemoved = false;

	/**
	 * Whether the plan blocks outside access (Odoo Online without the Custom plan): every API request gets a 403 page.
	 *
	 * @var bool
	 */
	public bool $planBlocked = false;

	/**
	 * Whether the server reports the Enterprise edition.
	 *
	 * @var bool
	 */
	public bool $enterprise = true;

	/**
	 * Queued overrides: [substring to match or null for any request, response, or null to process the request and lose the answer].
	 *
	 * @var array<int,array{0:?string,1:?HttpResponse}>
	 */
	private array $overrides = array();

	/**
	 * @param int         $major    Major version: 17, 18 or 19.
	 * @param string      $host     Host name of the server.
	 * @param string      $database Database name.
	 * @param string      $login    Login of the sync user.
	 * @param string      $apiKey   API key.
	 * @param array<string,mixed> $options Options for the database: stock, delivery, zatca, currency, country.
	 * @param int|null    $saasMinor Minor number of an Odoo Online release such as saas~18.4; null for a normal release.
	 */
	public function __construct(
		public readonly int $major = 18,
		public readonly string $host = 'erp.example.com',
		public readonly string $database = 'testdb',
		public readonly string $login = 'sync@example.com',
		public readonly string $apiKey = 'sk-test-0123456789abcdef',
		array $options = array(),
		public readonly ?int $saasMinor = null,
	) {
		$this->db = new FakeOdooDatabase(
			$major,
			(bool) ( $options['stock'] ?? true ),
			(bool) ( $options['delivery'] ?? true ),
			(bool) ( $options['zatca'] ?? false ),
			(string) ( $options['currency'] ?? 'AED' ),
			(string) ( $options['country'] ?? 'AE' )
		);
	}

	/**
	 * The address a connection uses to reach this server.
	 */
	public function baseUrl(): string {
		return 'https://' . $this->host;
	}

	/**
	 * Answer the next request (or the next one whose method, URL or body contains the text) with this response instead.
	 *
	 * @param HttpResponse $response Response.
	 * @param string|null  $match    Text the request must contain, or null for any request.
	 */
	public function failNext( HttpResponse $response, ?string $match = null ): self {
		$this->overrides[] = array( $match, $response );
		return $this;
	}

	/**
	 * Process the next matching request normally, then lose the answer as a time-out would: the change is made in Odoo and the
	 * caller never learns about it (an unknown outcome).
	 *
	 * @param string|null $match Text the request must contain, or null for any request.
	 */
	public function loseNextAnswer( ?string $match = null ): self {
		$this->overrides[] = array( $match, null );
		return $this;
	}

	/**
	 * The path of a URL.
	 *
	 * @param string $url URL.
	 */
	private function pathOf( string $url ): string {
		$path = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Requests whose URL ends with a path.
	 *
	 * @param string $path Path such as /jsonrpc.
	 * @return array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	public function requestsTo( string $path ): array {
		return array_values( array_filter( $this->requests, fn( array $request ): bool => str_ends_with( $this->pathOf( $request['url'] ), $path ) ) );
	}

	/**
	 * Calls made on a model and method, in order.
	 *
	 * @param string $model  Model.
	 * @param string $method Method.
	 * @return array<int,array<string,mixed>>
	 */
	public function callsTo( string $model, string $method ): array {
		return array_values( array_filter( $this->db->calls, static fn( array $call ): bool => $model === $call['model'] && $method === $call['method'] ) );
	}

	/**
	 * Answer a request.
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

		foreach ( $this->overrides as $index => $override ) {
			if ( null === $override[0] || str_contains( $request['method'] . ' ' . $url . ' ' . (string) $body, $override[0] ) ) {
				unset( $this->overrides[ $index ] );
				$this->overrides = array_values( $this->overrides );
				if ( null === $override[1] ) {
					$this->route( $request );
					return FakeHttpClient::timeout();
				}
				return $override[1];
			}
		}
		return $this->route( $request );
	}

	/**
	 * Route a request to the right endpoint.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	private function route( array $request ): HttpResponse {
		$url  = $request['url'];
		$body = $request['body'];
		$path = $this->pathOf( $url );
		if ( 'GET' === $request['method'] && '/web/version' === $path ) {
			return $this->major >= 19 ? FakeHttpClient::json( $this->webVersion() ) : $this->notFoundPage();
		}
		if ( 'POST' === $request['method'] && '/jsonrpc' === $path ) {
			return $this->jsonrpcRemoved ? $this->notFoundPage() : $this->jsonRpc( (string) $body );
		}
		if ( str_starts_with( $path, '/json/2/' ) ) {
			return $this->major >= 19 ? $this->json2( $request, $path ) : $this->notFoundPage();
		}
		return $this->notFoundPage();
	}

	/* ------------------------------------------------------------ version */

	/**
	 * The body of GET /web/version.
	 *
	 * @return array<string,mixed>
	 */
	private function webVersion(): array {
		return array(
			'version_info' => $this->versionInfo(),
			'version'      => $this->versionText(),
		);
	}

	/**
	 * The version_info list.
	 *
	 * @return array<int,mixed>
	 */
	private function versionInfo(): array {
		if ( null !== $this->saasMinor ) {
			return array( 'saas~' . $this->major, $this->saasMinor, 0, 'final', 0, $this->enterprise ? 'e' : '' );
		}
		return array( $this->major, 0, 0, 'final', 0, $this->enterprise ? 'e' : '' );
	}

	/**
	 * The version text.
	 */
	private function versionText(): string {
		return null !== $this->saasMinor ? 'saas~' . $this->major . '.' . $this->saasMinor : $this->major . '.0';
	}

	/**
	 * The page Odoo shows when a URL is not found.
	 */
	private function notFoundPage(): HttpResponse {
		return new HttpResponse( 404, '<!DOCTYPE html><title>404 Not Found</title><h1>Not Found</h1><p>The requested URL was not found on the server.</p>', array( 'content-type' => 'text/html' ) );
	}

	/**
	 * The page Odoo shows when no database is selected.
	 */
	private function noDatabasePage(): HttpResponse {
		return new HttpResponse( 404, '<!DOCTYPE html><title>404 Not Found</title><h1>Not Found</h1><p>No database is selected and the requested URL was not found in the server-wide controllers.</p>', array( 'content-type' => 'text/html' ) );
	}

	/* ------------------------------------------------------------ JSON-RPC */

	/**
	 * Handle a JSON-RPC request.
	 *
	 * @param string $body Request body.
	 */
	private function jsonRpc( string $body ): HttpResponse {
		$request = json_decode( $body, true );
		if ( ! is_array( $request ) || ! isset( $request['params'] ) || ! is_array( $request['params'] ) ) {
			return new HttpResponse( 400, 'Invalid JSON data' );
		}
		$id      = $request['id'] ?? null;
		$service = (string) ( $request['params']['service'] ?? '' );
		$method  = (string) ( $request['params']['method'] ?? '' );
		$args    = (array) ( $request['params']['args'] ?? array() );
		$this->services[] = $service . '.' . $method;
		try {
			$result = $this->rpcDispatch( $service, $method, $args );
			$answer = array( 'jsonrpc' => '2.0', 'id' => $id );
			if ( null !== $result ) {
				$answer['result'] = $result;
			}
			return FakeHttpClient::json( $answer );
		} catch ( FakeOdooFault $fault ) {
			return FakeHttpClient::json(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => $this->major >= 19 ? 0 : 200,
						'message' => 'Odoo Server Error',
						'data'    => $this->faultBody( $fault ),
					),
				)
			);
		}
	}

	/**
	 * Run a service method.
	 *
	 * @param string           $service Service: common, object or db.
	 * @param string           $method  Method.
	 * @param array<int,mixed> $args    Arguments.
	 * @return mixed
	 *
	 * @throws FakeOdooFault For refusals.
	 */
	private function rpcDispatch( string $service, string $method, array $args ): mixed {
		if ( 'common' === $service && 'version' === $method ) {
			return array(
				'server_version'      => $this->versionText(),
				'server_version_info' => $this->versionInfo(),
				'server_serie'        => $this->major . '.0',
				'protocol_version'    => 1,
			);
		}
		if ( 'common' === $service && in_array( $method, array( 'authenticate', 'login' ), true ) ) {
			$this->assertDatabase( (string) ( $args[0] ?? '' ) );
			return ( $args[1] ?? '' ) === $this->login && ( $args[2] ?? '' ) === $this->apiKey ? 2 : false;
		}
		if ( 'object' === $service && 'execute_kw' === $method ) {
			return $this->executeKw( $args );
		}
		throw new FakeOdooFault( 'odoo.exceptions.AccessDenied', 'Access Denied', 403 );
	}

	/**
	 * The database must exist.
	 *
	 * @param string $name Database name from the request.
	 *
	 * @throws FakeOdooFault When it does not.
	 */
	private function assertDatabase( string $name ): void {
		if ( $name !== $this->database ) {
			throw new FakeOdooFault( 'psycopg2.OperationalError', 'connection to server at "localhost" (127.0.0.1), port 5432 failed: FATAL:  database "' . $name . '" does not exist', 500 );
		}
	}

	/**
	 * object.execute_kw with Odoo's argument rules.
	 *
	 * @param array<int,mixed> $params db, uid, password, model, method, args, kw.
	 * @return mixed
	 *
	 * @throws FakeOdooFault For refusals.
	 */
	private function executeKw( array $params ): mixed {
		$this->assertDatabase( (string) ( $params[0] ?? '' ) );
		if ( 2 !== ( $params[1] ?? null ) || ( $params[2] ?? '' ) !== $this->apiKey ) {
			throw new FakeOdooFault( 'odoo.exceptions.AccessDenied', 'Access Denied', 403 );
		}
		$model   = (string) ( $params[3] ?? '' );
		$method  = (string) ( $params[4] ?? '' );
		$args    = (array) ( $params[5] ?? array() );
		$kwargs  = (array) ( $params[6] ?? array() );
		$context = (array) ( $kwargs['context'] ?? array() );
		unset( $kwargs['context'] );

		$modelMethods = array( 'search_read', 'search_count', 'create', 'fields_get', 'check_access_rights', 'context_get' );
		if ( in_array( $method, $modelMethods, true ) ) {
			$ids = null;
			if ( 'create' === $method ) {
				if ( ! array_key_exists( 0, $args ) ) {
					throw new FakeOdooFault( 'builtins.IndexError', 'tuple index out of range', 500 );
				}
				$kwargs['vals_list'] = $args[0];
				$result              = $this->db->call( $model, $method, null, $kwargs, $context );
				return $result;
			}
			if ( in_array( $method, array( 'search_read', 'search_count' ), true ) && array_key_exists( 0, $args ) ) {
				if ( array_key_exists( 'domain', $kwargs ) ) {
					throw new FakeOdooFault( 'builtins.TypeError', "{$method}() got multiple values for argument 'domain'", 500 );
				}
				$kwargs['domain'] = $args[0];
			}
			if ( 'check_access_rights' === $method && array_key_exists( 0, $args ) ) {
				$kwargs['operation'] = $args[0];
			}
		} else {
			if ( ! array_key_exists( 0, $args ) || ! is_array( $args[0] ) ) {
				throw new FakeOdooFault( 'builtins.IndexError', 'tuple index out of range', 500 );
			}
			$ids = array_map( 'intval', $args[0] );
			if ( 'write' === $method && array_key_exists( 1, $args ) ) {
				$kwargs['vals'] = $args[1];
			}
			if ( 'read' === $method && array_key_exists( 1, $args ) ) {
				$kwargs['fields'] = $args[1];
			}
			if ( 'has_access' === $method && array_key_exists( 1, $args ) ) {
				$kwargs['operation'] = $args[1];
			}
		}
		return $this->db->call( $model, $method, $ids, $kwargs, $context );
	}

	/* ------------------------------------------------------------ JSON-2 */

	/**
	 * Handle a JSON-2 request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 * @param string                                                                              $path    URL path.
	 */
	private function json2( array $request, string $path ): HttpResponse {
		$headers = array_change_key_case( $request['headers'], CASE_LOWER );
		if ( $this->planBlocked ) {
			return new HttpResponse( 403, '<html><body>Forbidden</body></html>', array( 'content-type' => 'text/html' ) );
		}
		if ( isset( $headers['x-odoo-database'] ) && $headers['x-odoo-database'] !== $this->database ) {
			return $this->noDatabasePage();
		}
		$parts = explode( '/', trim( substr( $path, strlen( '/json/2/' ) ), '/' ) );
		if ( 'POST' !== $request['method'] || 2 !== count( $parts ) ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.NotFound', 'Did you mean POST /json/2/<model>/<method>?', 404 ), 404 );
		}
		$authorization = $headers['authorization'] ?? '';
		if ( '' === $authorization ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.Unauthorized', 'User not authenticated, use an API Key with a Bearer Authorization header.', 401 ), 401 );
		}
		if ( 'bearer ' . $this->apiKey !== $authorization && 'Bearer ' . $this->apiKey !== $authorization ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.Unauthorized', 'Invalid apikey', 401 ), 401 );
		}

		list( $model, $method ) = $parts;
		$params                 = '' === trim( (string) $request['body'] ) ? array() : json_decode( (string) $request['body'], true );
		if ( ! is_array( $params ) ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.BadRequest', 'could not parse the body as json', 400 ), 400 );
		}
		if ( ! $this->db->hasModel( $model ) ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.NotFound', "the model '{$model}' does not exist", 404 ), 404 );
		}
		$ids     = isset( $params['ids'] ) ? array_map( 'intval', (array) $params['ids'] ) : array();
		$context = (array) ( $params['context'] ?? array() );
		unset( $params['ids'], $params['context'] );

		$modelMethods = array( 'search_read', 'search_count', 'create', 'fields_get', 'check_access_rights', 'context_get' );
		$isModel      = in_array( $method, $modelMethods, true );
		if ( $isModel && array() !== $ids ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.UnprocessableEntity', "cannot call {$model}.{$method} with ids", 422 ), 422 );
		}
		if ( 'create' === $method && ! array_key_exists( 'vals_list', $params ) ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.UnprocessableEntity', "missing a required argument: 'vals_list'", 422 ), 422 );
		}
		if ( 'write' === $method && ! array_key_exists( 'vals', $params ) ) {
			return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.UnprocessableEntity', "missing a required argument: 'vals'", 422 ), 422 );
		}
		try {
			$result = $this->db->call( $model, $method, $isModel ? null : $ids, $params, $context );
			return FakeHttpClient::json( $result );
		} catch ( FakeOdooFault $fault ) {
			if ( str_contains( $fault->faultText, "doesn't exist" ) || str_starts_with( $fault->faultName, 'werkzeug' ) ) {
				return FakeHttpClient::json( $this->json2Error( 'werkzeug.exceptions.NotFound', $fault->faultText, 404 ), 404 );
			}
			return FakeHttpClient::json( $this->faultBody( $fault ), $fault->httpStatus );
		}
	}

	/**
	 * The error body of the JSON-2 API.
	 *
	 * @param string $name    Exception name.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 * @return array<string,mixed>
	 */
	private function json2Error( string $name, string $message, int $status ): array {
		return array(
			'name'      => $name,
			'message'   => $message,
			'arguments' => array( $message, $status ),
			'context'   => new \stdClass(),
			'debug'     => "Traceback (most recent call last):\n  File \"odoo/http.py\"\n{$name}: {$message}\n",
		);
	}

	/**
	 * The error body for a fault.
	 *
	 * @param FakeOdooFault $fault Fault.
	 * @return array<string,mixed>
	 */
	private function faultBody( FakeOdooFault $fault ): array {
		return array(
			'name'      => $fault->faultName,
			'message'   => $fault->faultText,
			'arguments' => array( $fault->faultText ),
			'context'   => new \stdClass(),
			'debug'     => "Traceback (most recent call last):\n  File \"odoo/service/model.py\"\n{$fault->faultName}: {$fault->faultText}\n",
		);
	}
}
