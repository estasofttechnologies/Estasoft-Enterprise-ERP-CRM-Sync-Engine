<?php
/**
 * A small in-memory QuickBooks Online for the connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the calls the QuickBooks connector makes, in the shapes of Intuit's own SDK schema (IPPInvoice, IPPCustomer, IPPItem, IPPTaxCode, IPPFault):
 * entities in an envelope named after the entity, a QueryResponse for the query language, faults as {"Fault":{"Error":[{"Message","Detail","code","element"}],
 * "type":"ValidationFault"},"time":"..."}. It models on purpose what once hid bugs: an invoice is posted when it is created (there is no draft), a
 * DocNumber is kept only when Custom transaction numbers is on, a duplicate DocNumber is error 6140, a duplicate customer name is 6240, a requestid replays
 * the first answer, tax and the total are worked out per line by the company's tax codes, a sparse update needs the current SyncToken, and a payment
 * cannot exceed the balance. All data is synthetic.
 */
final class FakeQuickBooksServer {

	public const CLIENT_ID     = 'qbo-client-id-0001';
	public const CLIENT_SECRET = 'qbo-client-secret-77aa31ce';
	public const REALM         = '9130350000000001';

	/**
	 * The routing fake.
	 *
	 * @var ApiFakeServer
	 */
	public ApiFakeServer $server;

	/**
	 * Home currency.
	 *
	 * @var string
	 */
	public string $home = 'GBP';

	/**
	 * Country of the company.
	 *
	 * @var string
	 */
	public string $country = 'GB';

	/**
	 * Whether the company uses more than one currency.
	 *
	 * @var bool
	 */
	public bool $multiCurrency = true;

	/**
	 * Whether Custom transaction numbers is on.
	 *
	 * @var bool
	 */
	public bool $customNumbers = true;

	/**
	 * Sales tax rate of the US code TAX, in percent.
	 *
	 * @var float
	 */
	public float $usRate = 7.0;

	/**
	 * Entities by name and id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $store = array(
		'Invoice'    => array(),
		'CreditMemo' => array(),
		'Customer'   => array(),
		'Item'       => array(),
		'Payment'    => array(),
		'TaxCode'    => array(),
		'TaxRate'    => array(),
	);

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	public array $accessTokens = array();

	/**
	 * Valid refresh tokens.
	 *
	 * @var array<string,bool>
	 */
	public array $refreshTokens = array();

	/**
	 * Token requests received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $tokenRequests = array();

	/**
	 * Replay store of requestid.
	 *
	 * @var array<string,HttpResponse>
	 */
	public array $replays = array();

	/**
	 * Request ids seen, in order.
	 *
	 * @var array<int,string>
	 */
	public array $requestIds = array();

	/**
	 * Process the next write but lose the answer.
	 *
	 * @var bool
	 */
	public bool $loseNextAnswer = false;

	/**
	 * Number of invoice queries that answer "none found" although the invoice exists (another worker made it a moment ago).
	 *
	 * @var int
	 */
	public int $hideInvoices = 0;

	/**
	 * Number of credit memo queries that answer "none found" although the credit memo exists.
	 *
	 * @var int
	 */
	public int $hideCreditMemos = 0;

	/**
	 * Answer every call with "no access to this company" (error 120).
	 *
	 * @var bool
	 */
	public bool $noAccess = false;

	/**
	 * Refuse the client at the token endpoint.
	 *
	 * @var bool
	 */
	public bool $refuseClient = false;

	/**
	 * Failure scripted for the next write.
	 *
	 * @var array{0:string,1:int}|null
	 */
	private ?array $failure = null;

	/**
	 * Counter for ids.
	 *
	 * @var int
	 */
	private int $sequence = 100;

	/**
	 * Tokens issued.
	 *
	 * @var int
	 */
	private int $issued = 0;

	/**
	 * Build the fake on a network.
	 *
	 * @param FakeHttpClient $http Network.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, array( 'quickbooks.api.intuit.com', 'sandbox-quickbooks.api.intuit.com', 'oauth.platform.intuit.com' ) );
		$this->seed();
		$this->routes();
	}

	/**
	 * Secrets for a connection that is signed in.
	 *
	 * @param int $now Epoch seconds now.
	 * @return array<string,string>
	 */
	public function signedIn( int $now ): array {
		$access  = 'qbo-access-' . ( ++$this->issued ) . '-aaaaaaaa';
		$refresh = 'qbo-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'            => self::CLIENT_SECRET,
			'oauth_access_token'       => $access,
			'oauth_refresh_token'      => $refresh,
			'oauth_expires_at'         => (string) ( $now + 3600 ),
			'oauth_refresh_expires_at' => (string) ( $now + 100 * 86400 ),
		);
	}

	/**
	 * Make the next write fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->failure = array( $kind, $seconds );
	}

	/**
	 * Add an item.
	 *
	 * @param string              $sku   SKU.
	 * @param array<string,mixed> $extra More fields.
	 * @return array<string,mixed>
	 */
	public function addItem( string $sku, array $extra = array() ): array {
		$id                         = (string) ( ++$this->sequence );
		$this->store['Item'][ $id ] = $extra + array(
			'Id'             => $id,
			'SyncToken'      => '0',
			'Name'           => 'Item ' . $sku,
			'Sku'            => $sku,
			'Active'         => true,
			'Type'           => 'NonInventory',
			'TrackQtyOnHand' => false,
		);
		return $this->store['Item'][ $id ];
	}

	/**
	 * Add a customer.
	 *
	 * @param string              $name  Display name.
	 * @param string              $email Email.
	 * @param array<string,mixed> $extra More fields.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $name, string $email, array $extra = array() ): array {
		$id                             = (string) ( ++$this->sequence );
		$this->store['Customer'][ $id ] = $extra + array(
			'Id'               => $id,
			'SyncToken'        => '0',
			'DisplayName'      => $name,
			'PrimaryEmailAddr' => array( 'Address' => $email ),
			'Active'           => true,
		);
		return $this->store['Customer'][ $id ];
	}

	/**
	 * Requests received for a method and a path pattern.
	 *
	 * @param string|null $method Method.
	 * @param string|null $regex  Path regular expression (path after the company).
	 * @return array<int,ApiFakeRequest>
	 */
	public function calls( ?string $method = null, ?string $regex = null ): array {
		return $this->server->requests( $method, null === $regex ? null : '#^/v3/company/' . self::REALM . $regex . '$#' );
	}

	/**
	 * Writes received (POST calls to the company, not the token endpoint), oldest first.
	 *
	 * @return array<int,ApiFakeRequest>
	 */
	public function writes(): array {
		return array_values( array_filter( $this->server->received, static fn( ApiFakeRequest $r ): bool => 'POST' === $r->method && 0 === strpos( $r->path, '/v3/company/' ) ) );
	}

	/**
	 * The first live document of an entity with a document number.
	 *
	 * @param string $entity Invoice or CreditMemo.
	 * @param string $number DocNumber.
	 * @return array<string,mixed>|null
	 */
	public function byNumber( string $entity, string $number ): ?array {
		foreach ( $this->store[ $entity ] as $document ) {
			if ( ( $document['DocNumber'] ?? '' ) === $number ) {
				return $document;
			}
		}
		return null;
	}

	/**
	 * Seed the company data.
	 */
	private function seed(): void {
		$this->store['TaxRate'] = array(
			'1' => array( 'Id' => '1', 'Name' => 'VAT 20%', 'RateValue' => 20.0, 'Active' => true ),
			'2' => array( 'Id' => '2', 'Name' => 'VAT 5%', 'RateValue' => 5.0, 'Active' => true ),
			'3' => array( 'Id' => '3', 'Name' => 'Zero', 'RateValue' => 0.0, 'Active' => true ),
		);
		$this->store['TaxCode'] = array(
			'5'   => array( 'Id' => '5', 'Name' => '20.0% S', 'Active' => true, 'SalesTaxRateList' => array( 'TaxRateDetail' => array( array( 'TaxRateRef' => array( 'value' => '1' ) ) ) ) ),
			'4'   => array( 'Id' => '4', 'Name' => '5.0% R', 'Active' => true, 'SalesTaxRateList' => array( 'TaxRateDetail' => array( array( 'TaxRateRef' => array( 'value' => '2' ) ) ) ) ),
			'6'   => array( 'Id' => '6', 'Name' => '0.0% Z', 'Active' => true, 'SalesTaxRateList' => array( 'TaxRateDetail' => array( array( 'TaxRateRef' => array( 'value' => '3' ) ) ) ) ),
			'NON' => array( 'Id' => 'NON', 'Name' => 'Out of scope', 'Active' => true, 'SalesTaxRateList' => array() ),
			'TAX' => array( 'Id' => 'TAX', 'Name' => 'Taxable', 'Active' => true, 'SalesTaxRateList' => array() ),
			'OLD' => array( 'Id' => 'OLD', 'Name' => 'Old', 'Active' => false, 'SalesTaxRateList' => array() ),
		);
		$this->addItem( 'CS-MUG-01', array( 'Name' => 'Mug', 'Type' => 'Inventory', 'TrackQtyOnHand' => true, 'QtyOnHand' => 40.0 ) );
		$this->addItem( 'WC-SERVICE', array( 'Name' => 'WooCommerce sale', 'Sku' => 'WC-SERVICE', 'Type' => 'Service' ) );
	}

	/**
	 * Register the routes.
	 */
	private function routes(): void {
		$s    = $this->server;
		$root = '/v3/company/' . self::REALM;
		$s->route( 'POST', '/oauth2/v1/tokens/bearer', fn( ApiFakeRequest $r ) => $this->token( $r ) );
		$s->route( 'GET', $root . '/companyinfo/{id}', fn( ApiFakeRequest $r ) => $this->read( $r, fn() => array( 'CompanyInfo' => array( 'Id' => $r->params['id'], 'CompanyName' => 'Demo Trading Ltd', 'Country' => $this->country ) ) ) );
		$s->route( 'GET', $root . '/preferences', fn( ApiFakeRequest $r ) => $this->read( $r, fn() => array( 'Preferences' => array( 'SalesFormsPrefs' => array( 'CustomTxnNumbers' => $this->customNumbers ), 'CurrencyPrefs' => array( 'MultiCurrencyEnabled' => $this->multiCurrency, 'HomeCurrency' => array( 'value' => $this->home ) ), 'TaxPrefs' => array( 'UsingSalesTax' => 'US' === $this->country ) ) ) ) );
		$s->route( 'GET', $root . '/query', fn( ApiFakeRequest $r ) => $this->read( $r, fn() => $this->query( (string) ( $r->query['query'] ?? '' ) ) ) );
		$s->route( 'GET', $root . '/invoice/{id}', fn( ApiFakeRequest $r ) => $this->read( $r, fn() => isset( $this->store['Invoice'][ $r->params['id'] ] ) ? array( 'Invoice' => $this->store['Invoice'][ $r->params['id'] ] ) : null ) );
		$s->route( 'GET', $root . '/creditmemo/{id}', fn( ApiFakeRequest $r ) => $this->read( $r, fn() => isset( $this->store['CreditMemo'][ $r->params['id'] ] ) ? array( 'CreditMemo' => $this->store['CreditMemo'][ $r->params['id'] ] ) : null ) );
		$s->route( 'POST', $root . '/customer', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postCustomer( $r ) ) );
		$s->route( 'POST', $root . '/invoice', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postDocument( $r, 'Invoice' ) ) );
		$s->route( 'POST', $root . '/creditmemo', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postDocument( $r, 'CreditMemo' ) ) );
		$s->route( 'POST', $root . '/payment', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postPayment( $r ) ) );
	}

	/**
	 * The token endpoint.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function token( ApiFakeRequest $request ): HttpResponse {
		$form                  = $request->form();
		$this->tokenRequests[] = $form + array( '_authorization' => (string) $request->header( 'Authorization' ) );
		if ( $this->refuseClient || 'Basic ' . base64_encode( self::CLIENT_ID . ':' . self::CLIENT_SECRET ) !== (string) $request->header( 'Authorization' ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_client' ), 401 );
		}
		$grant = (string) ( $form['grant_type'] ?? '' );
		if ( 'authorization_code' === $grant ) {
			if ( 'good-code' !== ( $form['code'] ?? '' ) ) {
				return FakeHttpClient::json( array( 'error' => 'invalid_grant' ), 400 );
			}
		} elseif ( 'refresh_token' === $grant ) {
			$old = (string) ( $form['refresh_token'] ?? '' );
			if ( empty( $this->refreshTokens[ $old ] ) ) {
				return FakeHttpClient::json( array( 'error' => 'invalid_grant' ), 400 );
			}
			unset( $this->refreshTokens[ $old ] );
		} else {
			return FakeHttpClient::json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
		++$this->issued;
		$access  = 'qbo-access-' . $this->issued . '-cccccccc';
		$refresh = 'qbo-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'bearer', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400 ) );
	}

	/**
	 * A fault answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Fault code.
	 * @param string $message Message.
	 * @param string $detail  Detail.
	 * @param string $type    Fault type.
	 * @param string $element Element.
	 */
	private function fault( int $status, string $code, string $message, string $detail = '', string $type = 'ValidationFault', string $element = '' ): HttpResponse {
		return FakeHttpClient::json( array( 'Fault' => array( 'Error' => array( array( 'Message' => $message, 'Detail' => $detail, 'code' => $code, 'element' => $element ) ), 'type' => $type ), 'time' => '2026-11-02T08:00:00.000-07:00' ), $status );
	}

	/**
	 * Check the sign-in.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function denied( ApiFakeRequest $request ): ?HttpResponse {
		if ( empty( $this->accessTokens[ $request->bearer() ] ) ) {
			return $this->fault( 401, '3200', 'message=AuthenticationFailed; errorCode=003200; statusCode=401', 'Token expired', 'AUTHENTICATION' );
		}
		if ( $this->noAccess ) {
			return $this->fault( 403, '120', 'Authorization failure', 'The app has no access to this company', 'AuthorizationFault' );
		}
		return null;
	}

	/**
	 * Answer a read.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param \Closure       $answer  Returns the answer array or null.
	 */
	private function read( ApiFakeRequest $request, \Closure $answer ): HttpResponse {
		$denied = $this->denied( $request );
		if ( null !== $denied ) {
			return $denied;
		}
		if ( '' === (string) ( $request->query['minorversion'] ?? '' ) ) {
			return $this->fault( 400, '2020', 'Required param missing', 'minorversion', 'ValidationFault' );
		}
		$data = $answer();
		if ( $data instanceof HttpResponse ) {
			return $data;
		}
		if ( null === $data ) {
			return $this->fault( 400, '610', 'Object Not Found', 'Object not found', 'ValidationFault' );
		}
		return FakeHttpClient::json( $data + array( 'time' => '2026-11-02T08:00:00.000-07:00' ) );
	}

	/**
	 * Answer a write, after the scripted failure and the requestid replay.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param \Closure       $answer  Returns the answer array, an HttpResponse or null.
	 */
	private function write( ApiFakeRequest $request, \Closure $answer ): HttpResponse {
		if ( null !== $this->failure ) {
			list( $kind, $seconds ) = $this->failure;
			$this->failure          = null;
			$failed                 = $this->failed( $kind, $seconds );
			if ( null !== $failed ) {
				return $failed;
			}
		}
		$denied = $this->denied( $request );
		if ( null !== $denied ) {
			return $denied;
		}
		$key = (string) ( $request->query['requestid'] ?? '' );
		if ( '' !== $key ) {
			$this->requestIds[] = $key;
			if ( isset( $this->replays[ $key ] ) ) {
				return $this->replays[ $key ];
			}
		}
		$data = $answer();
		if ( $data instanceof HttpResponse ) {
			return $data;
		}
		$response = FakeHttpClient::json( ( $data ?? array() ) + array( 'time' => '2026-11-02T08:00:00.000-07:00' ) );
		if ( '' !== $key ) {
			$this->replays[ $key ] = $response;
		}
		if ( $this->loseNextAnswer ) {
			$this->loseNextAnswer = false;
			return FakeHttpClient::timeout();
		}
		return $response;
	}

	/**
	 * The failure a test scripted.
	 *
	 * @param string $kind    Kind.
	 * @param int    $seconds Wait for a rate limit.
	 */
	private function failed( string $kind, int $seconds ): ?HttpResponse {
		switch ( $kind ) {
			case 'timeout':
				return FakeHttpClient::timeout();
			case 'certificate':
				return FakeHttpClient::certificateError();
			case 'server_error':
				return $this->fault( 500, '10000', 'An application error has occurred', 'Internal error', 'SystemFault' );
			case 'rate_limit':
				return new HttpResponse( 429, '{"fault":{"message":"message=ThrottleExceeded; errorCode=003001; statusCode=429"}}', array( 'retry-after' => (string) $seconds ) );
			case 'unknown_fault':
				return FakeHttpClient::status( 418, '{"Fault":{"Error":[{"Message":"Something no catalog knows","code":"99999"}],"type":"Teapot"}}' );
			case 'auth':
				$this->accessTokens = array();
				$this->refuseClient = true;
				return null;
		}
		return null;
	}

	/**
	 * Run a query of the query language.
	 *
	 * @param string $sql Query.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function query( string $sql ): array|HttpResponse {
		if ( 1 !== preg_match( '/^select \* from (\w+)(?: where (.+?))?(?: startposition (\d+))?(?: maxresults (\d+))?$/is', trim( $sql ), $m ) ) {
			return $this->fault( 400, '4000', 'QueryParserError: Invalid query', $sql );
		}
		$entity = $m[1];
		if ( ! isset( $this->store[ $entity ] ) ) {
			return $this->fault( 400, '4000', 'QueryValidationError: Entity not supported', $entity );
		}
		$rows = array_values( $this->store[ $entity ] );
		if ( 'Invoice' === $entity && $this->hideInvoices > 0 ) {
			--$this->hideInvoices;
			$rows = array();
		}
		if ( 'CreditMemo' === $entity && $this->hideCreditMemos > 0 ) {
			--$this->hideCreditMemos;
			$rows = array();
		}
		if ( 'Item' === $entity || 'Customer' === $entity || 'TaxCode' === $entity ) {
			$rows = array_values( array_filter( $rows, static fn( array $r ): bool => false !== ( $r['Active'] ?? true ) ) );
		}
		if ( '' !== ( $m[2] ?? '' ) ) {
			foreach ( preg_split( '/\s+and\s+/i', $m[2] ) ?: array() as $condition ) {
				$filtered = $this->condition( $rows, trim( $condition ), $entity );
				if ( $filtered instanceof HttpResponse ) {
					return $filtered;
				}
				$rows = $filtered;
			}
		}
		$start = max( 1, (int) ( $m[3] ?? 1 ) );
		$max   = min( 1000, max( 1, (int) ( $m[4] ?? 100 ) ) );
		$page  = array_slice( $rows, $start - 1, $max );
		$out   = array( 'startPosition' => $start, 'maxResults' => count( $page ) );
		if ( array() !== $page ) {
			$out[ $entity ] = $page;
		}
		return array( 'QueryResponse' => $out );
	}

	/**
	 * Apply one condition.
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows.
	 * @param string                         $condition Condition.
	 * @param string                         $entity    Entity.
	 * @return array<int,array<string,mixed>>|HttpResponse
	 */
	private function condition( array $rows, string $condition, string $entity ): array|HttpResponse {
		$value = static fn( array $row, string $field ) => 'PrimaryEmailAddr' === $field ? ( $row['PrimaryEmailAddr']['Address'] ?? null ) : ( $row[ $field ] ?? null );
		$known = array( 'Item' => array( 'Sku', 'Name', 'Type', 'Active' ), 'Customer' => array( 'DisplayName', 'GivenName', 'FamilyName', 'PrimaryEmailAddr' ), 'Invoice' => array( 'DocNumber', 'TxnDate' ), 'CreditMemo' => array( 'DocNumber', 'TxnDate' ) );
		if ( 1 === preg_match( '/^(\w+)\s+in\s*\((.+)\)$/i', $condition, $m ) ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $values );
			$wanted = array_map( static fn( string $v ): string => stripslashes( $v ), $values[1] );
			return array_values( array_filter( $rows, static fn( array $r ): bool => in_array( (string) $value( $r, $m[1] ), $wanted, true ) ) );
		}
		if ( 1 === preg_match( "/^(\w+)\s*(=|>=|like)\s*'((?:[^'\\\\]|\\\\.)*)'$/i", $condition, $m ) ) {
			if ( isset( $known[ $entity ] ) && ! in_array( $m[1], $known[ $entity ], true ) ) {
				return $this->fault( 400, '4000', 'QueryValidationError: property ' . $m[1] . ' is not queryable', $condition );
			}
			$wanted = stripslashes( $m[3] );
			$op     = strtolower( $m[2] );
			return array_values(
				array_filter(
					$rows,
					static function ( array $r ) use ( $value, $m, $wanted, $op ): bool {
						$actual = (string) $value( $r, $m[1] );
						if ( '=' === $op ) {
							return 0 === strcasecmp( $actual, $wanted );
						}
						if ( '>=' === $op ) {
							return $actual >= $wanted;
						}
						$pattern = '#^' . str_replace( '%', '.*', preg_quote( $wanted, '#' ) ) . '$#i';
						return 1 === preg_match( $pattern, $actual );
					}
				)
			);
		}
		return $this->fault( 400, '4000', 'QueryParserError: cannot read the condition', $condition );
	}

	/**
	 * Create a customer.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function postCustomer( ApiFakeRequest $request ): array|HttpResponse {
		$body = $request->json();
		$name = (string) ( $body['DisplayName'] ?? '' );
		if ( '' === $name ) {
			return $this->fault( 400, '2020', 'Required param missing, need to supply the required value for the API', 'Required parameter DisplayName is missing', 'ValidationFault', 'DisplayName' );
		}
		if ( false !== strpos( $name, ':' ) || mb_strlen( (string) ( $body['FamilyName'] ?? '' ) ) > 15 || mb_strlen( (string) ( $body['GivenName'] ?? '' ) ) > 25 ) {
			return $this->fault( 400, '2050', 'String length is either too short or too long', 'The name is too long or has a colon', 'ValidationFault', 'DisplayName' );
		}
		if ( isset( $body['CurrencyRef'] ) && ! $this->multiCurrency ) {
			return $this->fault( 400, '6000', 'Business Validation Error', 'Multicurrency is not enabled, so a customer cannot have a currency.', 'ValidationFault', 'CurrencyRef' );
		}
		foreach ( $this->store['Customer'] as $customer ) {
			if ( 0 === strcasecmp( (string) $customer['DisplayName'], $name ) ) {
				return $this->fault( 400, '6240', 'Duplicate Name Exists Error', 'The name supplied already exists. : Another customer, vendor or employee is already using this name.', 'ValidationFault', 'DisplayName' );
			}
		}
		$customer = $this->addCustomer( $name, (string) ( $body['PrimaryEmailAddr']['Address'] ?? '' ), $body );
		return array( 'Customer' => $customer );
	}

	/**
	 * Create, sparsely update or delete an invoice or credit memo.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $entity  Invoice or CreditMemo.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function postDocument( ApiFakeRequest $request, string $entity ): array|HttpResponse {
		$body = $request->json();
		if ( 'delete' === ( $request->query['operation'] ?? '' ) ) {
			$id = (string) ( $body['Id'] ?? '' );
			if ( ! isset( $this->store[ $entity ][ $id ] ) ) {
				return $this->fault( 400, '610', 'Object Not Found', 'Object not found', 'ValidationFault' );
			}
			if ( (string) $this->store[ $entity ][ $id ]['SyncToken'] !== (string) ( $body['SyncToken'] ?? '' ) ) {
				return $this->fault( 400, '5010', 'Stale Object Error', 'Stale object', 'ValidationFault' );
			}
			unset( $this->store[ $entity ][ $id ] );
			return array( $entity => array( 'status' => 'Deleted', 'domain' => 'QBO', 'Id' => $id ) );
		}
		if ( isset( $body['Id'] ) ) {
			return $this->updateDocument( $body, $entity );
		}
		$customer = $this->store['Customer'][ (string) ( $body['CustomerRef']['value'] ?? '' ) ] ?? null;
		if ( null === $customer ) {
			return $this->fault( 400, '2500', 'Invalid Reference Id', 'Invalid Reference Id : Something you are trying to use has been made inactive.', 'ValidationFault', 'CustomerRef' );
		}
		$currency = (string) ( $body['CurrencyRef']['value'] ?? $this->home );
		if ( $currency !== $this->home && ! $this->multiCurrency ) {
			return $this->fault( 400, '2020', 'Required param missing, need to supply the required value for the API', 'Multicurrency is off', 'ValidationFault', 'CurrencyRef' );
		}
		$own = strtoupper( (string) ( $customer['CurrencyRef']['value'] ?? $this->home ) );
		if ( $this->multiCurrency && $own !== strtoupper( $currency ) ) {
			return $this->fault( 400, '6000', 'Business Validation Error', 'The currency of the transaction does not match the currency of the customer.', 'ValidationFault', 'CurrencyRef' );
		}
		$number = (string) ( $body['DocNumber'] ?? '' );
		if ( '' !== $number && null !== $this->byNumber( $entity, $number ) && $this->customNumbers ) {
			return $this->fault( 400, '6140', 'Duplicate Document Number Error', 'You must specify a different number. This number has already been used.', 'ValidationFault', 'DocNumber' );
		}
		$lines  = array();
		$errors = array();
		foreach ( (array) ( $body['Line'] ?? array() ) as $line ) {
			$lines[] = $this->checkLine( (array) $line, $errors );
		}
		if ( array() === $lines ) {
			$errors[] = array( 'code' => '2020', 'message' => 'Required param missing, need to supply the required value for the API', 'detail' => 'Line is required' );
		}
		if ( array() !== $errors ) {
			return $this->fault( 400, $errors[0]['code'], $errors[0]['message'], $errors[0]['detail'], 'ValidationFault', 'Line' );
		}
		$inclusive = 'TaxInclusive' === ( $body['GlobalTaxCalculation'] ?? '' );
		$totals    = $this->totals( $lines, $inclusive, $currency );
		$id        = (string) ( ++$this->sequence );
		$day       = (string) ( $body['TxnDate'] ?? '2026-11-02' );
		$document  = array(
			'Id'          => $id,
			'SyncToken'   => '0',
			'DocNumber'   => $this->customNumbers && '' !== $number ? $number : sprintf( '%04d', $this->sequence ),
			'TxnDate'     => $day,
			'DueDate'     => (string) ( $body['DueDate'] ?? $day ),
			'CustomerRef' => array( 'value' => (string) $customer['Id'], 'name' => (string) $customer['DisplayName'] ),
			'CurrencyRef' => array( 'value' => $currency, 'name' => $currency ),
			'PrivateNote' => (string) ( $body['PrivateNote'] ?? '' ),
			'Line'        => $lines,
			'TxnTaxDetail' => array( 'TotalTax' => $this->decimal( $totals['tax'], $currency ) ),
			'TotalAmt'    => $this->decimal( $totals['total'], $currency ),
			'GlobalTaxCalculation' => $body['GlobalTaxCalculation'] ?? null,
			'BillEmail'   => $body['BillEmail'] ?? null,
		);
		if ( 'Invoice' === $entity ) {
			$document['Balance'] = $this->decimal( $totals['total'], $currency );
		} else {
			$document['RemainingCredit'] = $this->decimal( $totals['total'], $currency );
		}
		$this->store[ $entity ][ $id ] = $document;
		return array( $entity => $document );
	}

	/**
	 * A sparse update of the private note.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param string              $entity Invoice or CreditMemo.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function updateDocument( array $body, string $entity ): array|HttpResponse {
		$id = (string) $body['Id'];
		if ( ! isset( $this->store[ $entity ][ $id ] ) ) {
			return $this->fault( 400, '610', 'Object Not Found', 'Object not found', 'ValidationFault' );
		}
		if ( (string) $this->store[ $entity ][ $id ]['SyncToken'] !== (string) ( $body['SyncToken'] ?? '' ) || empty( $body['sparse'] ) ) {
			return $this->fault( 400, '5010', 'Stale Object Error', 'You and another user were working on this at the same time.', 'ValidationFault' );
		}
		$document                            = $this->store[ $entity ][ $id ];
		$document['PrivateNote']             = (string) ( $body['PrivateNote'] ?? $document['PrivateNote'] );
		$document['SyncToken']               = (string) ( (int) $document['SyncToken'] + 1 );
		$this->store[ $entity ][ $id ]       = $document;
		return array( $entity => $document );
	}

	/**
	 * Check one line and add what QuickBooks adds.
	 *
	 * @param array<string,mixed>                                      $line   Line.
	 * @param array<int,array{code:string,message:string,detail:string}> $errors Collected errors.
	 * @return array<string,mixed>
	 */
	private function checkLine( array $line, array &$errors ): array {
		$detail = (array) ( $line['SalesItemLineDetail'] ?? array() );
		$item   = $this->store['Item'][ (string) ( $detail['ItemRef']['value'] ?? '' ) ] ?? null;
		if ( null === $item || false === ( $item['Active'] ?? true ) ) {
			$errors[] = array( 'code' => '2500', 'message' => 'Invalid Reference Id', 'detail' => 'Invalid Reference Id : Something you are trying to use has been made inactive.' );
		}
		$tax = (string) ( $detail['TaxCodeRef']['value'] ?? '' );
		if ( '' !== $tax && ! isset( $this->store['TaxCode'][ $tax ] ) ) {
			$errors[] = array( 'code' => '2500', 'message' => 'Invalid Reference Id', 'detail' => 'Invalid Tax Code ' . $tax );
		}
		if ( abs( (float) ( $line['Amount'] ?? 0 ) - (float) ( $detail['Qty'] ?? 1 ) * (float) ( $detail['UnitPrice'] ?? 0 ) ) > 0.0011 ) {
			$errors[] = array( 'code' => '6000', 'message' => 'Business Validation Error', 'detail' => 'The line amount is not quantity times unit price' );
		}
		return $line;
	}

	/**
	 * Totals the way QuickBooks works them out: per line, whole minor units, half away from zero.
	 *
	 * @param array<int,array<string,mixed>> $lines     Lines.
	 * @param bool                           $inclusive Whether line amounts include tax.
	 * @param string                         $currency  Currency.
	 * @return array{subtotal:int,tax:int,total:int}
	 */
	private function totals( array $lines, bool $inclusive, string $currency ): array {
		$subtotal = 0;
		$taxTotal = 0;
		foreach ( $lines as $line ) {
			$code   = (string) ( $line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '' );
			$rate   = $this->rate( $code );
			$amount = Money::fromDecimalString( number_format( (float) $line['Amount'], Money::exponent( $currency ), '.', '' ), $currency );
			if ( $inclusive ) {
				$tax       = $this->roundDiv( $amount * $rate, 1000000 + $rate );
				$subtotal += $amount - $tax;
			} else {
				$tax       = $this->roundDiv( $amount * $rate, 1000000 );
				$subtotal += $amount;
			}
			$taxTotal += $tax;
		}
		return array( 'subtotal' => $subtotal, 'tax' => $taxTotal, 'total' => $subtotal + $taxTotal );
	}

	/**
	 * The tax rate of a code in ten-thousandths of a percent.
	 *
	 * @param string $code Tax code Id.
	 */
	private function rate( string $code ): int {
		if ( 'TAX' === $code && 'US' === $this->country ) {
			return (int) round( $this->usRate * 10000 );
		}
		$total = 0.0;
		foreach ( (array) ( $this->store['TaxCode'][ $code ]['SalesTaxRateList']['TaxRateDetail'] ?? array() ) as $detail ) {
			$total += (float) ( $this->store['TaxRate'][ (string) $detail['TaxRateRef']['value'] ]['RateValue'] ?? 0 );
		}
		return (int) round( $total * 10000 );
	}

	/**
	 * Division rounded half away from zero.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator.
	 */
	private function roundDiv( int $numerator, int $denominator ): int {
		$half = intdiv( $denominator, 2 );
		return $numerator >= 0 ? intdiv( $numerator + $half, $denominator ) : -intdiv( -$numerator + $half, $denominator );
	}

	/**
	 * A minor-unit amount as the number QuickBooks returns.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 */
	private function decimal( int $minor, string $currency ): float {
		return (float) Money::toDecimalString( $minor, $currency );
	}

	/**
	 * Record a payment.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function postPayment( ApiFakeRequest $request ): array|HttpResponse {
		$body = $request->json();
		$line = (array) ( $body['Line'][0] ?? array() );
		$id   = (string) ( $line['LinkedTxn'][0]['TxnId'] ?? '' );
		if ( ! isset( $this->store['Invoice'][ $id ] ) ) {
			return $this->fault( 400, '2500', 'Invalid Reference Id', 'The invoice is not found', 'ValidationFault' );
		}
		$invoice  = $this->store['Invoice'][ $id ];
		$currency = (string) $invoice['CurrencyRef']['value'];
		$amount   = (float) ( $body['TotalAmt'] ?? 0 );
		if ( $amount > (float) $invoice['Balance'] + 0.0001 ) {
			return $this->fault( 400, '6000', 'Business Validation Error', 'The payment is more than the balance', 'ValidationFault' );
		}
		$left                          = Money::fromDecimalString( number_format( (float) $invoice['Balance'], Money::exponent( $currency ), '.', '' ), $currency ) - Money::fromDecimalString( number_format( $amount, Money::exponent( $currency ), '.', '' ), $currency );
		$invoice['Balance']            = $this->decimal( $left, $currency );
		$this->store['Invoice'][ $id ] = $invoice;
		$payment                       = array( 'Id' => (string) ( ++$this->sequence ), 'TotalAmt' => $amount, 'PaymentRefNum' => (string) ( $body['PaymentRefNum'] ?? '' ), 'TxnDate' => (string) ( $body['TxnDate'] ?? '' ), 'Line' => $body['Line'] ?? array() );
		$this->store['Payment'][ $payment['Id'] ] = $payment;
		return array( 'Payment' => $payment );
	}
}
