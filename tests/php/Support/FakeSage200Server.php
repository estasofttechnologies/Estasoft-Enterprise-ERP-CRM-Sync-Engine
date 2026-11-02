<?php
/**
 * A small in-memory Sage 200 API and Sage ID for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Sage 200 connector makes in the shapes the third-party clients show: bare JSON arrays, `$orderby` required with `$skip`,
 * 500 rows by default, validation errors as a list of `$message` entries, the gateway refusing a missing subscription key, `X-Site` and
 * `X-Company` pinning every call. It models on purpose what once hides bugs: an order takes the currency of its customer, an account
 * reference must be unique and short, changing the unit price needs the override permission, and totals are worked out from the lines and
 * their tax codes. It does not model everything Sage 200 checks. All data is synthetic.
 */
final class FakeSage200Server {

	public const CLIENT_ID = 'sage200-client-0001';

	public const CLIENT_SECRET = 'sage-secret-4d29b7e1c3';

	public const SUBSCRIPTION_KEY = 'sub-key-0f8a6d2c91b34e7a';

	public const SITE = 'site-1111';

	public const COMPANY = 'comp-2222';

	public const BASE = '/uk/sage200/accounts/v1';

	/**
	 * Records by resource.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $records = array();

	/**
	 * Every call: method, path, query, body, headers.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $calls = array();

	/**
	 * Token requests received.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $tokenRequests = array();

	/**
	 * Whether the user may change prices on order lines.
	 */
	public bool $canOverridePrices = true;

	/**
	 * Whether a POST of a document answers with its totals (the real answer shape is not documented).
	 */
	public bool $postAnswerHasTotals = false;

	/**
	 * Resources the installed version does not have.
	 *
	 * @var array<int,string>
	 */
	public array $missingResources = array();

	/**
	 * Whether the identity server refuses the client (a revoked secret).
	 */
	public bool $refuseClient = false;

	/**
	 * Decimals the site rounds amounts to (the base currency).
	 */
	public int $places = 2;

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	private array $accessTokens = array();

	/**
	 * Valid refresh tokens.
	 *
	 * @var array<string,bool>
	 */
	private array $refreshTokens = array();

	/**
	 * Scripted answers: method, pattern, answer.
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse|callable}>
	 */
	private array $failures = array();

	/**
	 * Calls whose answer is lost after the work is done: method, pattern, calls to let pass first.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private array $lost = array();

	private int $issued = 0;

	private int $next = 1000;

	/**
	 * Attach the site to a fake network.
	 *
	 * @param FakeHttpClient $http Network.
	 */
	public function __construct( FakeHttpClient $http ) {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
		$this->seed();
	}

	/**
	 * Secrets for a connection that is signed in.
	 *
	 * @param int $now Epoch seconds now.
	 * @return array<string,string>
	 */
	public function signedIn( int $now ): array {
		++$this->issued;
		$access                          = 'sage-access-' . $this->issued . '-aaaaaaaa';
		$refresh                         = 'sage-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'            => self::CLIENT_SECRET,
			'subscription_key'         => self::SUBSCRIPTION_KEY,
			'oauth_access_token'       => $access,
			'oauth_refresh_token'      => $refresh,
			'oauth_expires_at'         => (string) ( $now + 28000 ),
			'oauth_refresh_expires_at' => (string) ( $now + 90 * 86400 ),
		);
	}

	/**
	 * Give a failure to the next call whose method and path pattern match.
	 *
	 * @param string                $method  HTTP method or *.
	 * @param string                $pattern Regular expression for the path.
	 * @param HttpResponse|callable $answer  Answer.
	 */
	public function failNext( string $method, string $pattern, HttpResponse|callable $answer ): void {
		$this->failures[] = array( strtoupper( $method ), $pattern, $answer );
	}

	/**
	 * Make the next write fail the way a failure kind says; `auth` ends the session and revokes the client.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, certificate or auth.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		if ( 'auth' === $kind ) {
			$this->failNext(
				'POST',
				'#/sop_orders$#',
				function (): HttpResponse {
					$this->accessTokens = array();
					$this->refuseClient = true;
					return new HttpResponse( 401, '', array() );
				}
			);
			return;
		}
		$response = match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 503, 'Service unavailable, secret ' . self::CLIENT_SECRET ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => FakeHttpClient::json( array( array( '$severity' => 'fatal', '$message' => 'boom ' . self::CLIENT_SECRET ) ), 500 ),
		};
		$this->failNext( 'POST', '#/sop_orders$#', $response );
	}

	/**
	 * Let the next matching call do its work and then time out.
	 *
	 * @param string $method  HTTP method.
	 * @param string $pattern Regular expression for the path.
	 * @param int    $skip    Matching calls to let through first.
	 */
	public function loseAnswerOf( string $method, string $pattern, int $skip = 0 ): void {
		$this->lost[] = array( strtoupper( $method ), $pattern, $skip );
	}

	/**
	 * Count of calls that match.
	 *
	 * @param string $method  Method.
	 * @param string $pattern Pattern on the path.
	 */
	public function count( string $method, string $pattern ): int {
		$n = 0;
		foreach ( $this->calls as $call ) {
			if ( $call['method'] === strtoupper( $method ) && 1 === preg_match( $pattern, (string) $call['path'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Records of a resource.
	 *
	 * @param string $resource Resource.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $resource ): array {
		return array_values( $this->records[ $resource ] ?? array() );
	}

	/**
	 * Add a record directly.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $record   Fields.
	 * @return array<string,mixed>
	 */
	public function put( string $resource, array $record ): array {
		$record['id'] = $record['id'] ?? ++$this->next;
		$this->records[ $resource ][ (string) $record['id'] ] = $record;
		return $record;
	}

	/**
	 * Add a customer with one contact.
	 *
	 * @param string              $reference Account reference.
	 * @param string              $name      Name.
	 * @param string              $email     Email of the contact.
	 * @param array<string,mixed> $extra     More customer fields.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $reference, string $name, string $email, array $extra = array() ): array {
		$customer = $this->put( 'customers', $extra + array( 'reference' => $reference, 'name' => $name, 'currency_id' => 1, 'account_status_type' => 'EnumAccountStatusTypeActive' ) );
		$this->put( 'customer_contacts', array( 'customer_id' => $customer['id'], 'default_email' => $email, 'name' => $name ) );
		return $customer;
	}

	/**
	 * Master data of a small UK shop.
	 */
	private function seed(): void {
		$this->put( 'sites', array( 'id' => 1, 'site_id' => self::SITE, 'site_name' => 'Head office', 'site_short_name' => 'HO', 'company_id' => self::COMPANY, 'company_name' => 'Dunes Trading Ltd', 'tenant_id' => 'tenant-1' ) );
		$this->put( 'sites', array( 'id' => 2, 'site_id' => self::SITE, 'site_name' => 'Head office', 'site_short_name' => 'HO', 'company_id' => 'comp-3333', 'company_name' => 'Dunes Export Ltd', 'tenant_id' => 'tenant-1' ) );
		$this->put( 'currencies', array( 'id' => 1, 'currency' => 'GBP', 'is_base_currency' => true ) );
		$this->put( 'currencies', array( 'id' => 2, 'currency' => 'EUR', 'is_base_currency' => false ) );
		$this->put( 'tax_codes', array( 'id' => 1, 'code' => '1', 'name' => 'Standard rate', 'tax_rate' => 20 ) );
		$this->put( 'tax_codes', array( 'id' => 2, 'code' => '0', 'name' => 'Zero rate', 'tax_rate' => 0 ) );
		$this->put( 'tax_codes', array( 'id' => 3, 'code' => '5', 'name' => 'Reduced rate', 'tax_rate' => 5 ) );
		foreach ( array( 101 => 'CS-MUG-01', 102 => 'CS-BOWL-02', 103 => 'SHIPPING', 104 => 'FEE', 105 => 'DISCOUNT', 106 => 'REFUND' ) as $id => $code ) {
			$this->put( 'products', array( 'id' => $id, 'code' => $code, 'name' => 'Product ' . $code, 'free_stock_quantity' => 10 ) );
		}
	}

	/**
	 * Answer one request, then lose the answer when a test asked for it.
	 *
	 * @param array<string,mixed> $request Request from the client.
	 */
	private function handle( array $request ): HttpResponse {
		$response = $this->dispatch( $request );
		$path     = rawurldecode( (string) ( parse_url( (string) $request['url'], PHP_URL_PATH ) ?? '/' ) );
		foreach ( $this->lost as $index => $lost ) {
			if ( $lost[0] === (string) $request['method'] && 1 === preg_match( $lost[1], $path ) ) {
				if ( $lost[2] > 0 ) {
					--$this->lost[ $index ][2];
					continue;
				}
				unset( $this->lost[ $index ] );
				return FakeHttpClient::timeout();
			}
		}
		return $response;
	}

	/**
	 * Work out the answer to one request.
	 *
	 * @param array<string,mixed> $request Request from the client.
	 */
	private function dispatch( array $request ): HttpResponse {
		$parts  = parse_url( (string) $request['url'] );
		$path   = rawurldecode( (string) ( $parts['path'] ?? '/' ) );
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = (string) $request['method'];
		$body   = null === $request['body'] ? array() : (array) json_decode( (string) $request['body'], true );
		$form   = array();
		if ( 'id.sage.com' === ( $parts['host'] ?? '' ) ) {
			parse_str( (string) $request['body'], $form );
		}
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'headers' => (array) $request['headers'], 'form' => $form, 'host' => $parts['host'] ?? '' );

		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path ) ) {
				unset( $this->failures[ $index ] );
				return $failure[2] instanceof HttpResponse ? $failure[2] : ( $failure[2] )( $request );
			}
		}
		if ( 'id.sage.com' === ( $parts['host'] ?? '' ) ) {
			return '/oauth/token' === $path ? $this->token( $form ) : new HttpResponse( 404, '', array() );
		}
		if ( 'api.columbus.sage.com' !== ( $parts['host'] ?? '' ) || ! str_starts_with( $path, self::BASE . '/' ) ) {
			return new HttpResponse( 404, 'not found', array() );
		}
		$headers = array_change_key_case( (array) $request['headers'], CASE_LOWER );
		$bearer  = (string) ( $headers['authorization'] ?? '' );
		if ( ! str_starts_with( $bearer, 'Bearer ' ) || empty( $this->accessTokens[ substr( $bearer, 7 ) ] ) ) {
			return new HttpResponse( 401, '', array() );
		}
		$key = (string) ( $headers['ocp-apim-subscription-key'] ?? '' );
		if ( '' === $key ) {
			return FakeHttpClient::json( array( 'statusCode' => 401, 'message' => 'Access denied due to missing subscription key. Make sure to include subscription key when making requests to an API.' ), 401 );
		}
		if ( self::SUBSCRIPTION_KEY !== $key ) {
			return FakeHttpClient::json( array( 'statusCode' => 401, 'message' => 'Access denied due to invalid subscription key. Make sure to provide a valid key for an active subscription.' ), 401 );
		}
		$segments = explode( '/', substr( $path, strlen( self::BASE ) + 1 ) );
		$resource = $segments[0];
		if ( 'sites' !== $resource && ( self::SITE !== ( $headers['x-site'] ?? '' ) || self::COMPANY !== ( $headers['x-company'] ?? '' ) ) ) {
			return FakeHttpClient::json( array( 'message' => 'X-Site and X-Company are required and must name a company you can reach.' ), 401 );
		}
		if ( ! isset( $this->records[ $resource ] ) && ! in_array( $resource, array( 'sop_orders', 'sop_returns', 'customer_delivery_addresses', 'customer_contacts', 'customers' ), true ) || in_array( $resource, $this->missingResources, true ) ) {
			return FakeHttpClient::json( array( 'message' => 'Resource not found' ), 404 );
		}
		if ( 'GET' === $method ) {
			return isset( $segments[1] ) ? $this->single( $resource, $segments[1] ) : $this->listing( $resource, $query );
		}
		if ( 'POST' === $method && ! isset( $segments[1] ) ) {
			return $this->create( $resource, $body );
		}
		return new HttpResponse( 405, '', array() );
	}

	/**
	 * The token endpoint.
	 *
	 * @param array<string,string> $form Form fields.
	 */
	private function token( array $form ): HttpResponse {
		$this->tokenRequests[] = $form;
		if ( $this->refuseClient || self::CLIENT_SECRET !== ( $form['client_secret'] ?? '' ) || self::CLIENT_ID !== ( $form['client_id'] ?? '' ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_client', 'error_description' => 'Client authentication failed' ), 401 );
		}
		if ( 'refresh_token' !== ( $form['grant_type'] ?? '' ) ) {
			return FakeHttpClient::json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
		$old = (string) ( $form['refresh_token'] ?? '' );
		if ( empty( $this->refreshTokens[ $old ] ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_grant', 'error_description' => 'Unknown or invalid refresh token.' ), 403 );
		}
		unset( $this->refreshTokens[ $old ] );
		++$this->issued;
		$access                          = 'sage-access-' . $this->issued . '-cccccccc';
		$refresh                         = 'sage-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => 28800 ) );
	}

	/**
	 * GET a list.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $query    Query.
	 */
	private function listing( string $resource, array $query ): HttpResponse {
		if ( (int) ( $query['$skip'] ?? 0 ) > 0 && empty( $query['$orderby'] ) ) {
			return FakeHttpClient::json( array( array( '$severity' => 'error', '$message' => '$skip requires $orderby' ) ), 400 );
		}
		$top  = isset( $query['$top'] ) ? min( 5000, (int) $query['$top'] ) : 500;
		$rows = array();
		foreach ( $this->records[ $resource ] ?? array() as $record ) {
			if ( FakeODataFilter::matches( (string) ( $query['$filter'] ?? '' ), $this->row( $record ), true ) ) {
				$rows[] = $this->shape( $resource, $record, false );
			}
		}
		return FakeHttpClient::json( array_slice( $rows, (int) ( $query['$skip'] ?? 0 ), $top ) );
	}

	/**
	 * GET one record.
	 *
	 * @param string $resource Resource.
	 * @param string $id       Id.
	 */
	private function single( string $resource, string $id ): HttpResponse {
		$record = $this->records[ $resource ][ $id ] ?? null;
		return null === $record ? FakeHttpClient::json( array( 'message' => 'Not found' ), 404 ) : FakeHttpClient::json( $this->shape( $resource, $record, true ) );
	}

	/**
	 * The flat row a filter sees: top-level scalars.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	private function row( array $record ): array {
		return array_filter( $record, static fn( mixed $value ): bool => ! is_array( $value ) );
	}

	/**
	 * A record as the API answers.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $record   Record.
	 * @param bool                $detail   Whether this is a single-record read (lines included).
	 * @return array<string,mixed>
	 */
	private function shape( string $resource, array $record, bool $detail ): array {
		unset( $resource );
		if ( ! $detail ) {
			unset( $record['lines'] );
		}
		return array_filter( $record, static fn( string $name ): bool => ! str_starts_with( $name, '_' ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * POST a record.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $body     Body.
	 */
	private function create( string $resource, array $body ): HttpResponse {
		return match ( $resource ) {
			'customers'                   => $this->createCustomer( $body ),
			'customer_contacts'           => $this->createPlain( $resource, $body, array( 'customer_id' ) ),
			'customer_delivery_addresses' => $this->createPlain( $resource, $body, array( 'customer_id', 'address_1' ) ),
			'sop_orders', 'sop_returns'   => $this->createDocument( $resource, $body ),
			default                       => new HttpResponse( 405, '', array() ),
		};
	}

	/**
	 * A refusal in the shape Sage 200 uses.
	 *
	 * @param string $message Message.
	 */
	private function refuse( string $message ): HttpResponse {
		return FakeHttpClient::json( array( array( '$severity' => 'error', '$dataCode' => 'x', '$message' => $message ) ), 400 );
	}

	/**
	 * POST customers.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createCustomer( array $body ): HttpResponse {
		$reference = (string) ( $body['reference'] ?? '' );
		if ( '' === $reference || '' === (string) ( $body['name'] ?? '' ) ) {
			return $this->refuse( 'Account Reference and Name are required.' );
		}
		if ( strlen( $reference ) > 8 ) {
			return $this->refuse( 'Account Reference must be 8 characters or fewer.' );
		}
		foreach ( $this->records['customers'] ?? array() as $other ) {
			if ( strtolower( (string) $other['reference'] ) === strtolower( $reference ) ) {
				return $this->refuse( 'The Account Reference ' . $reference . ' already exists.' );
			}
		}
		$record = $this->put( 'customers', $body + array( 'currency_id' => 1, 'account_status_type' => 'EnumAccountStatusTypeActive' ) );
		return FakeHttpClient::json( array( 'id' => $record['id'], 'reference' => $reference ), 201 );
	}

	/**
	 * POST a simple record.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $body     Body.
	 * @param array<int,string>   $required Required fields.
	 */
	private function createPlain( string $resource, array $body, array $required ): HttpResponse {
		foreach ( $required as $field ) {
			if ( empty( $body[ $field ] ) ) {
				return $this->refuse( 'The field ' . $field . ' is required.' );
			}
		}
		if ( empty( $this->records['customers'][ (string) $body['customer_id'] ] ) ) {
			return $this->refuse( 'The customer does not exist.' );
		}
		$record = $this->put( $resource, $body );
		return FakeHttpClient::json( array( 'id' => $record['id'] ), 201 );
	}

	/**
	 * POST sop_orders and sop_returns.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $body     Body.
	 */
	private function createDocument( string $resource, array $body ): HttpResponse {
		$customer = $this->records['customers'][ (string) ( $body['customer_id'] ?? '' ) ] ?? null;
		if ( null === $customer ) {
			return $this->refuse( 'The customer does not exist.' );
		}
		$lines = array();
		$net   = 0.0;
		$tax   = 0.0;
		foreach ( (array) ( $body['lines'] ?? array() ) as $index => $line ) {
			$line = (array) $line;
			if ( empty( $this->records['products'][ (string) ( $line['product_id'] ?? '' ) ] ) ) {
				return $this->refuse( 'Line ' . ( $index + 1 ) . ': the product does not exist.' );
			}
			$rate = 0.0;
			if ( ! empty( $line['tax_code_id'] ) ) {
				$code = $this->records['tax_codes'][ (string) $line['tax_code_id'] ] ?? null;
				if ( null === $code ) {
					return $this->refuse( 'Line ' . ( $index + 1 ) . ': the tax code does not exist.' );
				}
				$rate = (float) $code['tax_rate'];
			}
			if ( isset( $line['selling_unit_price'] ) && ! $this->canOverridePrices ) {
				return $this->refuse( 'You do not have permission to change the Unit Selling Price on this line.' );
			}
			$value = round( (float) $line['line_quantity'] * (float) ( $line['selling_unit_price'] ?? 0 ) * ( 1 - (float) ( $line['unit_discount_percent'] ?? 0 ) / 100 ), $this->places );
			$vat   = round( $value * $rate / 100, $this->places );
			$net  += $value;
			$tax  += $vat;
			$lines[] = $line + array( 'id' => ++$this->next, 'line_total_value' => $value, 'line_tax_value' => $vat );
		}
		$currency = 1 === (int) $customer['currency_id'] ? array( 'is_base_currency' => true, 'currency' => 'GBP' ) : array( 'is_base_currency' => false, 'currency' => 'EUR' );
		$record   = $this->put(
			$resource,
			array(
				'document_no'          => ( 'sop_orders' === $resource ? 'SOP' : 'RET' ) . str_pad( (string) ( 1 + count( $this->records[ $resource ] ?? array() ) ), 6, '0', STR_PAD_LEFT ),
				'customer_id'          => $body['customer_id'],
				'customer_document_no' => $body['customer_document_no'] ?? '',
				'document_date'        => $body['document_date'] ?? '',
				'currency_id'          => $customer['currency_id'],
				'currency'             => $currency,
				'total_net_value'      => round( $net, $this->places ),
				'total_tax_value'      => round( $tax, $this->places ),
				'total_gross_value'    => round( $net + $tax, $this->places ),
				'lines'                => $lines,
			) + array_intersect_key( $body, array_flip( array( 'customer_delivery_address_id' ) ) )
		);
		$answer = $this->postAnswerHasTotals ? $record : array_intersect_key( $record, array_flip( array( 'id', 'document_no', 'customer_document_no' ) ) );
		return FakeHttpClient::json( $answer, 201 );
	}
}
