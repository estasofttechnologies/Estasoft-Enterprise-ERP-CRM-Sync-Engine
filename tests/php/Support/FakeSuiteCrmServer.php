<?php
/**
 * A small in-memory SuiteCRM 8 (API v8) for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Written from the SuiteCRM-Core source (docs/connectors/suitecrm.md, section 1): the token endpoint with the client credentials and password
 * grants, `GET /V8/module/{Module}` with `filter[field][op]`, `filter[operator]`, `page[size]`, `page[number]` and `sort`, records as
 * `{type, id, attributes}` where the type is the object name, `POST /V8/module` with `{data:{type, attributes}}`, relationships by link
 * name, and errors as `{"errors":{"status","title","detail"}}`. It models on purpose: a list without a page size returning every record,
 * a filter on a field the module does not have being an error, the email filter matching any address of a record regardless of case, a
 * currency amount returned as text with six decimals, and a server where URL rewriting is off answering with an HTML page.
 */
final class FakeSuiteCrmServer {

	/**
	 * Host of the server.
	 */
	public const HOST = 'crm.example.test';

	/**
	 * Client id and secret the token endpoint accepts.
	 */
	public const CLIENT_ID = 'crm-client-id';

	/**
	 * Client secret.
	 */
	public const CLIENT_SECRET = 'crm-client-secret-0123456789';

	/**
	 * Sync user of the password grant.
	 */
	public const USER = 'sync.user';

	/**
	 * Password of the sync user.
	 */
	public const PASSWORD = 'sync-user-password-abcdef';

	/**
	 * Path prefix of the API.
	 *
	 * @var string
	 */
	public string $prefix = '/legacy/Api';

	/**
	 * When true, URL rewriting is off: every API path answers with an HTML 404 page.
	 *
	 * @var bool
	 */
	public bool $rewriteOff = false;

	/**
	 * Factor applied to the amount of a created opportunity (a currency set-up that differs).
	 *
	 * @var float
	 */
	public float $amountFactor = 1.0;

	/**
	 * Records by module, then id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $modules = array(
		'Accounts'      => array(),
		'Contacts'      => array(),
		'Opportunities' => array(),
		'Notes'         => array(),
		'Currencies'    => array(),
		'AOS_Products'  => array(),
	);

	/**
	 * Links: "Module|id|link" to related ids.
	 *
	 * @var array<string,array<int,string>>
	 */
	public array $links = array();

	/**
	 * Custom fields a module has besides the stock ones (Studio fields end in `_c`): module => field names. A filter on any other field is an error.
	 *
	 * @var array<string,array<int,string>>
	 */
	public array $customFields = array();

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	public array $accessTokens = array();

	/**
	 * Scripted failures: [method, pattern, response, times left].
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse,3:int}>
	 */
	public array $failures = array();

	/**
	 * Every API request as "METHOD path?query".
	 *
	 * @var array<int,string>
	 */
	public array $log = array();

	/**
	 * Grants asked of the token endpoint, oldest first.
	 *
	 * @var array<int,string>
	 */
	public array $grants = array();

	/**
	 * Counter for ids and dates.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Object names of the modules, as SuiteCRM returns them in `type`.
	 *
	 * @var array<string,string>
	 */
	private const OBJECTS = array(
		'Accounts'      => 'Account',
		'Contacts'      => 'Contact',
		'Opportunities' => 'Opportunity',
		'Notes'         => 'Note',
		'Currencies'    => 'Currency',
		'AOS_Products'  => 'AOS_Products',
	);

	/**
	 * Fields a filter may name, by module.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const FIELDS = array(
		'Accounts'      => array( 'id', 'name', 'email1', 'date_entered' ),
		'Contacts'      => array( 'id', 'first_name', 'last_name', 'email1', 'date_entered' ),
		'Opportunities' => array( 'id', 'name', 'date_entered', 'sales_stage', 'amount' ),
		'Notes'         => array( 'id', 'name', 'parent_id', 'date_entered' ),
		'Currencies'    => array( 'id', 'iso4217', 'name', 'status' ),
		'AOS_Products'  => array( 'id', 'name', 'part_number', 'date_entered' ),
	);

	/**
	 * Give the server a valid access token.
	 *
	 * @param string $token Access token.
	 */
	public function allowToken( string $token ): void {
		$this->accessTokens[ $token ] = true;
	}

	/**
	 * Add a record to a module.
	 *
	 * @param string              $module     Module.
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string The new id.
	 */
	public function add( string $module, array $attributes ): string {
		$id = $this->newId();
		$this->modules[ $module ][ $id ] = array( 'id' => $id, 'attributes' => $attributes + array( 'date_entered' => $this->stamp() ) );
		return $id;
	}

	/**
	 * Script a failure for the next requests that match.
	 *
	 * @param string       $method  Method or *.
	 * @param string       $pattern Regular expression for the path and query.
	 * @param HttpResponse $answer  Answer.
	 * @param int          $times   How many requests it applies to.
	 */
	public function fail( string $method, string $pattern, HttpResponse $answer, int $times = 1 ): void {
		$this->failures[] = array( strtoupper( $method ), $pattern, $answer, $times );
	}

	/**
	 * The request handler for FakeHttpClient::fallback().
	 *
	 * @return callable
	 */
	public function handler(): callable {
		return fn( array $request ): HttpResponse => $this->respond( $request );
	}

	/**
	 * Answer a request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function respond( array $request ): HttpResponse {
		$parts  = parse_url( $request['url'] );
		$path   = rawurldecode( (string) ( $parts['path'] ?? '' ) );
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = $request['method'];
		$token  = $this->prefix . '/access_token' === $path;
		$this->log[] = $method . ' ' . $path . ( isset( $parts['query'] ) ? '?' . rawurldecode( $parts['query'] ) : '' );
		if ( $this->rewriteOff && str_starts_with( $path, $this->prefix ) ) {
			return new HttpResponse( 404, '<html><body>Not Found</body></html>', array( 'content-type' => 'text/html' ) );
		}
		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path . '?' . ( $parts['query'] ?? '' ) ) && $failure[3] > 0 ) {
				--$this->failures[ $index ][3];
				return $failure[2];
			}
		}
		if ( $token ) {
			return $this->token( (string) $request['body'] );
		}
		$auth = $this->header( $request['headers'], 'authorization' );
		if ( 1 !== preg_match( '/^Bearer (.+)$/', $auth, $m ) || empty( $this->accessTokens[ $m[1] ] ) ) {
			return $this->error( 401, 'Unauthorized', 'The resource owner or authorization server denied the request.' );
		}
		$body = json_decode( (string) $request['body'], true );
		$body = is_array( $body ) ? $body : array();
		$base = $this->prefix . '/V8/';
		if ( ! str_starts_with( $path, $base ) ) {
			return $this->error( 404, 'Not Found', 'No route' );
		}
		$rest = substr( $path, strlen( $base ) );
		if ( 'current-user' === $rest ) {
			return $this->json( array( 'data' => array( 'type' => 'User', 'id' => 'u1', 'attributes' => array( 'user_name' => 'sync.user', 'full_name' => 'Sync User' ) ) ) );
		}
		if ( 'module' === $rest && 'POST' === $method ) {
			return $this->create( $body );
		}
		if ( 1 === preg_match( '#^module/([A-Za-z_]+)/([\w-]+)/relationships/(\w+)$#', $rest, $m ) ) {
			return $this->relationship( $method, $m[1], $m[2], $m[3], $body );
		}
		if ( 1 === preg_match( '#^module/([A-Za-z_]+)/([\w-]+)$#', $rest, $m ) ) {
			return $this->one( $m[1], $m[2] );
		}
		if ( 1 === preg_match( '#^module/([A-Za-z_]+)$#', $rest, $m ) ) {
			return $this->listing( $m[1], $query );
		}
		return $this->error( 404, 'Not Found', 'No route' );
	}

	/**
	 * The token endpoint.
	 *
	 * @param string $body Form body.
	 */
	private function token( string $body ): HttpResponse {
		$form = array();
		parse_str( $body, $form );
		$this->grants[] = (string) ( $form['grant_type'] ?? '' );
		if ( self::CLIENT_ID !== ( $form['client_id'] ?? '' ) || self::CLIENT_SECRET !== ( $form['client_secret'] ?? '' ) ) {
			return new HttpResponse( 401, '{"error":"invalid_client","message":"Client authentication failed"}', array( 'content-type' => 'application/json' ) );
		}
		if ( 'password' === ( $form['grant_type'] ?? '' ) && ( self::USER !== ( $form['username'] ?? '' ) || self::PASSWORD !== ( $form['password'] ?? '' ) ) ) {
			return new HttpResponse( 400, '{"error":"invalid_grant","message":"The user credentials were incorrect."}', array( 'content-type' => 'application/json' ) );
		}
		if ( ! in_array( $form['grant_type'] ?? '', array( 'client_credentials', 'password' ), true ) ) {
			return new HttpResponse( 400, '{"error":"unsupported_grant_type"}', array( 'content-type' => 'application/json' ) );
		}
		++$this->counter;
		$access = 'crm-access-' . $this->counter;
		$this->allowToken( $access );
		return $this->json( array( 'token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => $access ) );
	}

	/**
	 * Create a record.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function create( array $body ): HttpResponse {
		$data   = (array) ( $body['data'] ?? array() );
		$module = (string) ( $data['type'] ?? '' );
		if ( ! isset( $this->modules[ $module ] ) ) {
			return $this->error( 400, 'Bad Request', 'Module ' . $module . ' not found' );
		}
		$attributes = (array) ( $data['attributes'] ?? array() );
		if ( 'Opportunities' === $module ) {
			$attributes['amount'] = number_format( (float) ( $attributes['amount'] ?? 0 ) * $this->amountFactor, 6, '.', '' );
			if ( ! isset( $attributes['name'], $attributes['sales_stage'], $attributes['date_closed'] ) ) {
				return $this->error( 400, 'Bad Request', 'Required fields are missing' );
			}
			if ( mb_strlen( (string) $attributes['name'] ) > 50 ) {
				return $this->error( 400, 'Bad Request', 'The name is longer than 50 characters' );
			}
		}
		if ( 'Contacts' === $module && '' === (string) ( $attributes['last_name'] ?? '' ) ) {
			return $this->error( 400, 'Bad Request', 'last_name is required' );
		}
		$id = $this->add( $module, $attributes );
		return $this->json( array( 'data' => $this->resource( $module, $this->modules[ $module ][ $id ] ) ), 201 );
	}

	/**
	 * One record.
	 *
	 * @param string $module Module.
	 * @param string $id     Id.
	 */
	private function one( string $module, string $id ): HttpResponse {
		if ( ! isset( $this->modules[ $module ][ $id ] ) ) {
			return $this->error( 404, 'Not Found', 'Record not found' );
		}
		return $this->json( array( 'data' => $this->resource( $module, $this->modules[ $module ][ $id ] ) ) );
	}

	/**
	 * A relationship: read or add.
	 *
	 * @param string              $method Method.
	 * @param string              $module Module.
	 * @param string              $id     Id.
	 * @param string              $link   Link name.
	 * @param array<string,mixed> $body   Body.
	 */
	private function relationship( string $method, string $module, string $id, string $link, array $body ): HttpResponse {
		if ( ! isset( $this->modules[ $module ][ $id ] ) ) {
			return $this->error( 404, 'Not Found', 'Record not found' );
		}
		$key = $module . '|' . $id . '|' . $link;
		if ( 'POST' === $method ) {
			$other = (string) ( $body['data']['id'] ?? '' );
			$this->links[ $key ][] = $other;
			return $this->json( array( 'meta' => array( 'message' => 'related' ) ) );
		}
		$data = array();
		foreach ( $this->links[ $key ] ?? array() as $other ) {
			$data[] = array( 'type' => 'Contact', 'id' => $other );
		}
		return $this->json( array( 'data' => $data ) );
	}

	/**
	 * A list after filter, sort and paging.
	 *
	 * @param string              $module Module.
	 * @param array<string,mixed> $query  Query.
	 */
	private function listing( string $module, array $query ): HttpResponse {
		if ( ! isset( $this->modules[ $module ] ) ) {
			return $this->error( 400, 'Bad Request', 'Module ' . $module . ' not found' );
		}
		$rows   = array_values( $this->modules[ $module ] );
		$filter = (array) ( $query['filter'] ?? array() );
		$or     = 'or' === strtolower( (string) ( $filter['operator'] ?? 'and' ) );
		unset( $filter['operator'] );
		foreach ( array_keys( $filter ) as $field ) {
			if ( ! in_array( $field, array_merge( self::FIELDS[ $module ], $this->customFields[ $module ] ?? array() ), true ) ) {
				return $this->error( 400, 'Bad Request', 'Filter field ' . $field . ' in ' . self::OBJECTS[ $module ] . ' module is not found' );
			}
		}
		if ( array() !== $filter ) {
			$rows = array_values( array_filter( $rows, fn( array $row ): bool => $this->matches( $row, $filter, $or ) ) );
		}
		$sort = (string) ( $query['sort'] ?? '' );
		if ( '' !== $sort ) {
			$field = ltrim( $sort, '-' );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['attributes'][ $field ] ?? '' ), (string) ( $b['attributes'][ $field ] ?? '' ) ) * ( str_starts_with( $sort, '-' ) ? -1 : 1 ) );
		}
		$page = (array) ( $query['page'] ?? array() );
		if ( isset( $page['size'] ) ) {
			$size = (int) $page['size'];
			if ( $size < 1 || (int) ( $page['number'] ?? 1 ) < 1 ) {
				return $this->error( 400, 'Bad Request', 'Page values must be greater than 0' );
			}
			$rows = array_slice( $rows, ( (int) ( $page['number'] ?? 1 ) - 1 ) * $size, $size );
		}
		return $this->json( array( 'data' => array_map( fn( array $row ): array => $this->resource( $module, $row ), $rows ) ) );
	}

	/**
	 * Whether a record matches the conditions.
	 *
	 * @param array<string,mixed>  $row    Record.
	 * @param array<string,mixed>  $filter Conditions: field to operator to value.
	 * @param bool                 $or     True when any condition is enough.
	 */
	private function matches( array $row, array $filter, bool $or ): bool {
		foreach ( $filter as $field => $conditions ) {
			foreach ( (array) $conditions as $op => $value ) {
				$actual = 'id' === $field ? $row['id'] : (string) ( $row['attributes'][ $field ] ?? '' );
				$left   = strtolower( $actual );
				$right  = strtolower( (string) $value );
				$ok     = match ( $op ) {
					'eq'    => $left === $right,
					'neq'   => $left !== $right,
					'like'  => 1 === preg_match( '/^' . str_replace( '%', '.*', preg_quote( $right, '/' ) ) . '$/s', $left ),
					'gte'   => $actual >= (string) $value,
					'gt'    => $actual > (string) $value,
					'lte'   => $actual <= (string) $value,
					'lt'    => $actual < (string) $value,
					default => false,
				};
				if ( $or && $ok ) {
					return true;
				}
				if ( ! $or && ! $ok ) {
					return false;
				}
			}
		}
		return ! $or;
	}

	/**
	 * A record in the JSON:API shape.
	 *
	 * @param string              $module Module.
	 * @param array<string,mixed> $row    Record.
	 * @return array<string,mixed>
	 */
	private function resource( string $module, array $row ): array {
		return array(
			'type'       => self::OBJECTS[ $module ],
			'id'         => $row['id'],
			'attributes' => $row['attributes'],
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status.
	 */
	private function json( mixed $data, int $status = 200 ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ), array( 'content-type' => 'application/vnd.api+json' ) );
	}

	/**
	 * A SuiteCRM error answer.
	 *
	 * @param int    $status Status.
	 * @param string $title  Title.
	 * @param string $detail Detail.
	 */
	public function error( int $status, string $title, string $detail ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( array( 'errors' => array( 'status' => $status, 'title' => $title, 'detail' => $detail ) ) ), array( 'content-type' => 'application/vnd.api+json' ) );
	}

	/**
	 * A header value from a request, in any case.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param string               $name    Name in lower case.
	 */
	private function header( array $headers, string $name ): string {
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * A new id.
	 */
	private function newId(): string {
		++$this->counter;
		return sprintf( '%08x-1111-4111-8111-%012x', $this->counter, $this->counter * 104729 );
	}

	/**
	 * A date-entered stamp that grows with every call, so "oldest first" is well defined.
	 */
	private function stamp(): string {
		++$this->counter;
		return gmdate( 'Y-m-d H:i:s', 1790000000 + $this->counter );
	}
}
