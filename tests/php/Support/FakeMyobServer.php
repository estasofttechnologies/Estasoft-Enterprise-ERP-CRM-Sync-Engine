<?php
/**
 * A small in-memory MYOB Business API for the connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the calls the MYOB connector makes, in the shapes MYOB documents: lists as {"Items":[...],"NextPageLink":null,"Count":n} with $top, $skip and $filter,
 * errors as {"Errors":[{"Name","Message","AdditionalDetails","ErrorCode","Severity"}]}, a create as 201 with a Location header and no body. It models on purpose
 * what once hid bugs: an invoice is posted when it is created (no draft), a line total must equal quantity times price less the discount, a negative price
 * with a non-zero total is error 12101, an invoice with a payment cannot be deleted, a key over its limit gets 403 RateLimitError (not 429), and the headers
 * x-myobapi-key, x-myobapi-version and (when the file has a login) x-myobapi-cftoken are checked on every call. Tax and totals are worked out per line in whole
 * minor units. All data is synthetic.
 */
final class FakeMyobServer {

	public const CLIENT_ID     = 'myob-key-0001-aaaa';
	public const CLIENT_SECRET = 'myob-secret-5c41aa90';
	public const FILE          = '5b3c7a52-1d3e-4f6a-9b8c-0a1b2c3d4e5f';
	public const FILE_USER     = 'Administrator';
	public const FILE_PASSWORD = 'file-pass-7788';

	/**
	 * The routing fake.
	 *
	 * @var ApiFakeServer
	 */
	public ApiFakeServer $server;

	/**
	 * Currency of the file (MYOB does not name it on documents of the base currency).
	 *
	 * @var string
	 */
	public string $currency = 'AUD';

	/**
	 * Whether the email filter on customers works.
	 *
	 * @var bool
	 */
	public bool $emailFilter = true;

	/**
	 * Whether eq on the email in the filter is case sensitive (MYOB's page does not say).
	 *
	 * @var bool
	 */
	public bool $emailCaseSensitive = false;

	/**
	 * Minor units a payment leaves on the invoice's balance although MYOB answered 201 (0 for a payment that clears the balance).
	 *
	 * @var int
	 */
	public int $paymentShortfall = 0;

	/**
	 * Whether the invoice disappears when a payment is recorded (a read-back that finds nothing).
	 *
	 * @var bool
	 */
	public bool $invoiceGoneAfterPayment = false;

	/**
	 * The user name and password the file asks for, or null when it asks for none.
	 *
	 * @var string|null
	 */
	public ?string $fileLogin = null;

	/**
	 * Whether a credit invoice is reported with the status Credit.
	 *
	 * @var bool
	 */
	public bool $creditStatus = true;

	/**
	 * Whether a create answers without the Location header.
	 *
	 * @var bool
	 */
	public bool $noLocation = false;

	/**
	 * Whether the next created invoices come back with a ForeignCurrency block.
	 *
	 * @var bool
	 */
	public bool $foreignCurrency = false;

	/**
	 * Whether a sale of more than the quantity on hand is refused (error 25002).
	 *
	 * @var bool
	 */
	public bool $strictStock = false;

	/**
	 * Entities by name and UID.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $store = array(
		'TaxCode'  => array(),
		'Account'  => array(),
		'Item'     => array(),
		'Customer' => array(),
		'Invoice'  => array(),
		'Payment'  => array(),
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
	 * Process the next write but lose the answer.
	 *
	 * @var bool
	 */
	public bool $loseNextAnswer = false;

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
	 * Counter for UIDs.
	 *
	 * @var int
	 */
	private int $sequence = 100;

	/**
	 * Counter for invoice numbers.
	 *
	 * @var int
	 */
	private int $numbers = 0;

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
		$this->server = ApiFakeServer::on( $http, array( 'api.myob.com', 'arl2.api.myob.com', 'secure.myob.com' ) );
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
		$access  = 'myob-access-' . ( ++$this->issued ) . '-aaaaaaaa';
		$refresh = 'myob-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'      => self::CLIENT_SECRET,
			'oauth_access_token' => $access,
			'oauth_refresh_token' => $refresh,
			'oauth_expires_at'   => (string) ( $now + 1200 ),
		);
	}

	/**
	 * Make the next write fail.
	 *
	 * @param string $kind    rate_limit (a plain 429 with Retry-After), myob_rate_limit and myob_rate_limit_daily (MYOB's own 403 RateLimitError), timeout, server_error,
	 *                        gateway_timeout, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->failure = array( $kind, $seconds );
	}

	/**
	 * Add an item.
	 *
	 * @param string              $number Item number.
	 * @param array<string,mixed> $extra  More fields.
	 * @return array<string,mixed>
	 */
	public function addItem( string $number, array $extra = array() ): array {
		$uid                         = $this->uid();
		$this->store['Item'][ $uid ] = $extra + array(
			'UID'               => $uid,
			'Number'            => $number,
			'Name'              => 'Item ' . $number,
			'IsActive'          => true,
			'IsSold'            => true,
			'IsInventoried'     => false,
			'QuantityOnHand'    => null,
			'QuantityCommitted' => null,
		);
		return $this->store['Item'][ $uid ];
	}

	/**
	 * Add a customer card.
	 *
	 * @param string              $lastName Last name.
	 * @param string              $email    Email.
	 * @param array<string,mixed> $extra    More fields.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $lastName, string $email, array $extra = array() ): array {
		$uid                             = $this->uid();
		$this->store['Customer'][ $uid ] = $extra + array(
			'UID'          => $uid,
			'DisplayID'    => sprintf( 'CUS%06d', count( $this->store['Customer'] ) + 1 ),
			'IsIndividual' => true,
			'LastName'     => $lastName,
			'FirstName'    => '',
			'IsActive'     => true,
			'Addresses'    => array( array( 'Location' => 1, 'Email' => $email ) ),
		);
		return $this->store['Customer'][ $uid ];
	}

	/**
	 * Requests received for a method and a path pattern under the company file.
	 *
	 * @param string|null $method Method.
	 * @param string|null $regex  Regular expression for the path after the file (such as /Sale/Invoice/Item).
	 * @return array<int,ApiFakeRequest>
	 */
	public function calls( ?string $method = null, ?string $regex = null ): array {
		return $this->server->requests( $method, null === $regex ? null : '#^/accountright/' . self::FILE . $regex . '$#' );
	}

	/**
	 * Writes received (POST and DELETE calls under the company file), oldest first.
	 *
	 * @return array<int,ApiFakeRequest>
	 */
	public function writes(): array {
		return array_values( array_filter( $this->server->received, static fn( ApiFakeRequest $r ): bool => in_array( $r->method, array( 'POST', 'DELETE' ), true ) && 0 === strpos( $r->path, '/accountright/' ) ) );
	}

	/**
	 * The first invoice with a customer purchase order number.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 */
	public function invoiceByReference( string $reference ): ?array {
		foreach ( $this->store['Invoice'] as $invoice ) {
			if ( ( $invoice['CustomerPurchaseOrderNumber'] ?? '' ) === $reference ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * The next UID.
	 */
	private function uid(): string {
		return sprintf( '00000000-0000-4000-8000-%012d', ++$this->sequence );
	}

	/**
	 * Seed the company file data.
	 */
	private function seed(): void {
		foreach ( array( array( 'GST', 'Goods & Services Tax', 10.0 ), array( 'FRE', 'GST Free', 0.0 ), array( 'N-T', 'Not Reportable', 0.0 ), array( 'WET', 'Wine Equalisation Tax', 29.0 ), array( 'VAT', 'Value Added Tax', 5.0 ) ) as $row ) {
			$uid                           = $this->uid();
			$this->store['TaxCode'][ $uid ] = array( 'UID' => $uid, 'Code' => $row[0], 'Description' => $row[1], 'Type' => 'GST', 'Rate' => $row[2] );
		}
		foreach ( array( array( '1-1110', 'Cheque Account' ), array( '1-1120', 'Savings Account' ) ) as $row ) {
			$uid                           = $this->uid();
			$this->store['Account'][ $uid ] = array( 'UID' => $uid, 'DisplayID' => $row[0], 'Name' => $row[1], 'Type' => 'Bank' );
		}
		$this->addItem(
			'CS-MUG-01',
			array(
				'Name'              => 'Mug',
				'IsInventoried'     => true,
				'QuantityOnHand'    => 40.0,
				'QuantityCommitted' => 3.0,
				'LocationDetails'   => array(
					array( 'Location' => array( 'Identifier' => 'MAIN', 'Name' => 'Main warehouse' ), 'QuantityOnHand' => 30.0, 'QuantityCommitted' => 2.0 ),
					array( 'Location' => array( 'Identifier' => 'DXB', 'Name' => 'Dubai store' ), 'QuantityOnHand' => 10.0, 'QuantityCommitted' => 1.0 ),
				),
			)
		);
		$this->addItem( 'WC-GENERAL', array( 'Name' => 'WooCommerce sale' ) );
		$this->addItem( 'WC-FEE', array( 'Name' => 'WooCommerce fees' ) );
		$this->addItem( 'WC-DISCOUNT', array( 'Name' => 'WooCommerce discounts' ) );
	}

	/**
	 * Register the routes.
	 */
	private function routes(): void {
		$s    = $this->server;
		$root = '/accountright';
		$f    = $root . '/{file}';
		$s->route( 'POST', '/oauth2/v1/authorize', fn( ApiFakeRequest $r ) => $this->token( $r ) );
		$s->route( 'GET', $root . '/', fn( ApiFakeRequest $r ) => $this->guarded( $r, false, fn() => FakeHttpClient::json( array( array( 'Id' => self::FILE, 'Name' => 'Demo Trading Pty Ltd', 'LibraryPath' => 'Demo', 'ProductVersion' => '2026.1', 'ProductLevel' => array( 'Code' => 20, 'Name' => 'Premier' ), 'CheckedOutDate' => null, 'CheckedOutBy' => null, 'Uri' => 'https://api.myob.com/accountright/' . self::FILE, 'Country' => 'AU' ), array( 'Id' => '11111111-2222-4333-8444-555555555555', 'Name' => 'Other Company', 'Country' => 'NZ' ) ) ) ) );
		$s->route( 'GET', $f . '/GeneralLedger/TaxCode', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->lister( $r, 'TaxCode', array( 'Code' ) ) ) );
		$s->route( 'GET', $f . '/GeneralLedger/Account', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->lister( $r, 'Account', array( 'DisplayID', 'Name' ) ) ) );
		$s->route( 'GET', $f . '/Inventory/Item', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->lister( $r, 'Item', array( 'Number', 'Name' ) ) ) );
		$s->route( 'GET', $f . '/Contact/Customer', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->lister( $r, 'Customer', array() ) ) );
		$s->route( 'GET', $f . '/Contact/Customer/{uid}', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->one( 'Customer', $r->params['uid'] ) ) );
		$s->route( 'POST', $f . '/Contact/Customer', fn( ApiFakeRequest $r ) => $this->guardedWrite( $r, fn() => $this->postCustomer( $r ) ) );
		$s->route( 'GET', $f . '/Sale/Invoice/Item', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->lister( $r, 'Invoice', array( 'CustomerPurchaseOrderNumber', 'Number', 'Date' ) ) ) );
		$s->route( 'GET', $f . '/Sale/Invoice/Item/{uid}', fn( ApiFakeRequest $r ) => $this->guarded( $r, true, fn() => $this->one( 'Invoice', $r->params['uid'] ) ) );
		$s->route( 'POST', $f . '/Sale/Invoice/Item', fn( ApiFakeRequest $r ) => $this->guardedWrite( $r, fn() => $this->postInvoice( $r ) ) );
		$s->route( 'DELETE', $f . '/Sale/Invoice/Item/{uid}', fn( ApiFakeRequest $r ) => $this->guardedWrite( $r, fn() => $this->deleteInvoice( $r->params['uid'] ) ) );
		$s->route( 'POST', $f . '/Sale/CustomerPayment', fn( ApiFakeRequest $r ) => $this->guardedWrite( $r, fn() => $this->postPayment( $r ) ) );
	}

	/**
	 * The token endpoint.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function token( ApiFakeRequest $request ): HttpResponse {
		$form                  = $request->form();
		$this->tokenRequests[] = $form + array( '_authorization' => (string) $request->header( 'Authorization' ) );
		if ( $this->refuseClient || self::CLIENT_ID !== ( $form['client_id'] ?? '' ) || self::CLIENT_SECRET !== ( $form['client_secret'] ?? '' ) ) {
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
		$access  = 'myob-access-' . $this->issued . '-cccccccc';
		$refresh = 'myob-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'bearer', 'expires_in' => 1200 ) );
	}

	/**
	 * An error answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $name    Error name.
	 * @param string $message Message.
	 * @param string $details Additional details.
	 * @param int    $code    Error code.
	 */
	private function error( int $status, string $name, string $message, string $details = '', int $code = 0 ): HttpResponse {
		return FakeHttpClient::json( array( 'Errors' => array( array( 'Name' => $name, 'Message' => $message, 'AdditionalDetails' => $details, 'ErrorCode' => $code, 'Severity' => 'Error' ) ) ), $status );
	}

	/**
	 * Check the sign-in and the headers.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param bool           $file    Whether the call is under a company file.
	 */
	private function denied( ApiFakeRequest $request, bool $file ): ?HttpResponse {
		if ( empty( $this->accessTokens[ $request->bearer() ] ) ) {
			return $this->error( 401, 'Unauthorized', 'The access token is invalid or has expired.' );
		}
		if ( self::CLIENT_ID !== (string) $request->header( 'x-myobapi-key' ) ) {
			return $this->error( 403, 'DeveloperInactive', 'The developer key is missing or inactive.', 'Header: x-myobapi-key' );
		}
		if ( 'v2' !== (string) $request->header( 'x-myobapi-version' ) ) {
			return $this->error( 400, 'ValidationError', 'The API version header is missing.', 'Header: x-myobapi-version' );
		}
		if ( $file ) {
			if ( self::FILE !== (string) ( $request->params['file'] ?? '' ) ) {
				return $this->error( 404, 'NotFound', 'The company file was not found.' );
			}
			if ( null !== $this->fileLogin && base64_encode( $this->fileLogin ) !== (string) $request->header( 'x-myobapi-cftoken' ) ) {
				return $this->error( 403, 'AccessDenied', 'Access to the company file was denied.', 'Header: x-myobapi-cftoken' );
			}
		}
		return null;
	}

	/**
	 * Answer a read.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param bool           $file    Whether the call is under a company file.
	 * @param \Closure       $answer  Returns the response.
	 */
	private function guarded( ApiFakeRequest $request, bool $file, \Closure $answer ): HttpResponse {
		return $this->denied( $request, $file ) ?? $answer();
	}

	/**
	 * Answer a write, after the scripted failure.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param \Closure       $answer  Returns the response.
	 */
	private function guardedWrite( ApiFakeRequest $request, \Closure $answer ): HttpResponse {
		if ( null !== $this->failure ) {
			list( $kind, $seconds ) = $this->failure;
			$this->failure          = null;
			$failed                 = $this->failed( $kind, $seconds );
			if ( null !== $failed ) {
				return $failed;
			}
		}
		$denied = $this->denied( $request, true );
		if ( null !== $denied ) {
			return $denied;
		}
		$response = $answer();
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
	 * @param int    $seconds Wait for rate_limit.
	 */
	private function failed( string $kind, int $seconds ): ?HttpResponse {
		switch ( $kind ) {
			case 'timeout':
				return FakeHttpClient::timeout();
			case 'certificate':
				return FakeHttpClient::certificateError();
			case 'server_error':
				return $this->error( 500, 'InternalServerError', 'An unexpected error occurred.' );
			case 'gateway_timeout':
				return $this->error( 504, 'GatewayTimeout', 'The request took too long.' );
			case 'rate_limit':
				return new HttpResponse( 429, '{"Errors":[{"Name":"TooManyRequests","Message":"Slow down."}]}', array( 'retry-after' => (string) $seconds ) );
			case 'myob_rate_limit':
				return $this->error( 403, 'RateLimitError', 'The API key has exceeded the per-second rate limit.', 'Header: x-myobapi-key' );
			case 'myob_rate_limit_daily':
				return $this->error( 403, 'RateLimitError', 'The API key has exceeded the daily rate limit.', 'Header: x-myobapi-key' );
			case 'unknown_fault':
				return FakeHttpClient::status( 418, '{"Errors":[{"Name":"Teapot","Message":"Something no catalog knows"}]}' );
			case 'auth':
				$this->accessTokens = array();
				$this->refuseClient = true;
				return null;
		}
		return null;
	}

	/**
	 * The answer to a create: 201, the address of the new record and no body.
	 *
	 * @param string $path Path of the list the record belongs to.
	 * @param string $uid  UID.
	 */
	private function created( string $path, string $uid ): HttpResponse {
		return $this->noLocation ? FakeHttpClient::status( 201, '' ) : FakeHttpClient::status( 201, '', array( 'Location' => 'https://api.myob.com/accountright/' . self::FILE . '/' . $path . '/' . $uid ) );
	}

	/**
	 * One record.
	 *
	 * @param string $entity Entity.
	 * @param string $uid    UID.
	 */
	private function one( string $entity, string $uid ): HttpResponse {
		return isset( $this->store[ $entity ][ $uid ] ) ? FakeHttpClient::json( $this->store[ $entity ][ $uid ] ) : $this->error( 404, 'NotFound', 'The record was not found.', '', 150 );
	}

	/**
	 * A list with $filter, $top and $skip.
	 *
	 * @param ApiFakeRequest    $request Request.
	 * @param string            $entity  Entity.
	 * @param array<int,string> $fields  Fields a filter may use.
	 */
	private function lister( ApiFakeRequest $request, string $entity, array $fields ): HttpResponse {
		$rows = array_values( $this->store[ $entity ] );
		if ( 'Item' === $entity || 'Customer' === $entity ) {
			$rows = array_values( array_filter( $rows, static fn( array $r ): bool => false !== ( $r['IsActive'] ?? true ) ) );
		}
		$filter = trim( (string) ( $request->query['$filter'] ?? '' ) );
		if ( '' !== $filter ) {
			$rows = $this->filter( $rows, $filter, $fields, $entity );
			if ( $rows instanceof HttpResponse ) {
				return $rows;
			}
		}
		$top  = min( 1000, max( 1, (int) ( $request->query['$top'] ?? 400 ) ) );
		$skip = max( 0, (int) ( $request->query['$skip'] ?? 0 ) );
		return FakeHttpClient::json( array( 'Items' => array_slice( $rows, $skip, $top ), 'NextPageLink' => null, 'Count' => count( $rows ) ) );
	}

	/**
	 * Apply a $filter. Supported: eq, ge on a date, startswith, substringof, Addresses/any for the email, joined by and / or.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string                         $filter Filter.
	 * @param array<int,string>              $fields Fields a filter may use.
	 * @param string                         $entity Entity.
	 * @return array<int,array<string,mixed>>|HttpResponse
	 */
	private function filter( array $rows, string $filter, array $fields, string $entity ): array|HttpResponse {
		if ( 1 === preg_match( "/^Addresses\\/any\\((\\w+): \\1\\/Email eq '((?:[^']|'')*)'\\)$/", $filter, $m ) ) {
			if ( ! $this->emailFilter || 'Customer' !== $entity ) {
				return $this->error( 501, 'NotImplemented', 'The filter is not supported.' );
			}
			$wanted = str_replace( "''", "'", $m[2] );
			$exact  = $this->emailCaseSensitive;
			return array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $wanted, $exact ): bool {
						foreach ( (array) ( $row['Addresses'] ?? array() ) as $address ) {
							$actual = (string) ( $address['Email'] ?? '' );
							if ( $exact ? $actual === $wanted : 0 === strcasecmp( $actual, $wanted ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}
		$matches = array();
		foreach ( $rows as $row ) {
			foreach ( preg_split( '/\s+or\s+/i', $filter ) ?: array() as $group ) {
				$all = true;
				foreach ( preg_split( '/\s+and\s+/i', $group ) ?: array() as $atom ) {
					$result = $this->atom( $row, trim( $atom ), $fields );
					if ( $result instanceof HttpResponse ) {
						return $result;
					}
					$all = $all && $result;
				}
				if ( $all ) {
					$matches[] = $row;
					break;
				}
			}
		}
		return $matches;
	}

	/**
	 * One condition of a filter.
	 *
	 * @param array<string,mixed> $row    Row.
	 * @param string              $atom   Condition.
	 * @param array<int,string>   $fields Fields a filter may use.
	 */
	private function atom( array $row, string $atom, array $fields ): bool|HttpResponse {
		$quoted = "'((?:[^']|'')*)'";
		$field  = null;
		$test   = null;
		if ( 1 === preg_match( '/^(\w+) eq ' . $quoted . '$/i', $atom, $m ) ) {
			$field = $m[1];
			$want  = str_replace( "''", "'", $m[2] );
			$test  = static fn( string $actual ): bool => 0 === strcasecmp( $actual, $want );
		} elseif ( 1 === preg_match( '/^startswith\((\w+),' . $quoted . '\)$/i', $atom, $m ) ) {
			$field = $m[1];
			$want  = str_replace( "''", "'", $m[2] );
			$test  = static fn( string $actual ): bool => 0 === stripos( $actual, $want );
		} elseif ( 1 === preg_match( '/^substringof\(' . $quoted . ',(\w+)\)$/i', $atom, $m ) ) {
			$field = $m[2];
			$want  = str_replace( "''", "'", $m[1] );
			$test  = static fn( string $actual ): bool => false !== stripos( $actual, $want );
		} elseif ( 1 === preg_match( "/^(\\w+) ge datetime'(\\d{4}-\\d{2}-\\d{2})'\$/i", $atom, $m ) ) {
			$field = $m[1];
			$want  = $m[2];
			$test  = static fn( string $actual ): bool => substr( $actual, 0, 10 ) >= $want;
		}
		if ( null === $field || null === $test || ( array() !== $fields && ! in_array( $field, $fields, true ) ) ) {
			return $this->error( 400, 'ValidationError', 'The filter cannot be read: ' . $atom, '', 100 );
		}
		return $test( (string) ( $row[ $field ] ?? '' ) );
	}

	/**
	 * Create a customer card.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postCustomer( ApiFakeRequest $request ): HttpResponse {
		$body = $request->json();
		$max  = array( 'CompanyName' => 50, 'LastName' => 30, 'FirstName' => 20 );
		foreach ( $max as $field => $length ) {
			if ( mb_strlen( (string) ( $body[ $field ] ?? '' ) ) > $length ) {
				return $this->error( 400, 'ValidationError', $field . ' is too long.', 'Maximum ' . $length . ' characters', 100 );
			}
		}
		foreach ( (array) ( $body['Addresses'] ?? array() ) as $address ) {
			if ( mb_strlen( (string) ( $address['PostCode'] ?? '' ) ) > 11 || mb_strlen( (string) ( $address['ContactName'] ?? '' ) ) > 25 || mb_strlen( (string) ( $address['Phone1'] ?? '' ) ) > 21 ) {
				return $this->error( 400, 'ValidationError', 'An address value is too long.', '', 100 );
			}
		}
		if ( ! empty( $body['IsIndividual'] ) ? '' === (string) ( $body['LastName'] ?? '' ) : '' === (string) ( $body['CompanyName'] ?? '' ) ) {
			return $this->error( 400, 'Required', 'The name is required.', '', 100 );
		}
		$uid                             = $this->uid();
		$this->store['Customer'][ $uid ] = $body + array(
			'UID'       => $uid,
			'DisplayID' => sprintf( 'CUS%06d', count( $this->store['Customer'] ) + 1 ),
			'IsActive'  => true,
			'Addresses' => array(),
		);
		return $this->created( 'Contact/Customer', $uid );
	}

	/**
	 * Create an item invoice (or, with negative quantities, a credit).
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postInvoice( ApiFakeRequest $request ): HttpResponse {
		$body     = $request->json();
		$customer = $this->store['Customer'][ (string) ( $body['Customer']['UID'] ?? '' ) ] ?? null;
		if ( null === $customer ) {
			return $this->error( 400, 'NotFound', 'The customer was not found.', '', 150 );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} 00:00:00$/', (string) ( $body['Date'] ?? '' ) ) ) {
			return $this->error( 400, 'SerializationError', 'The date could not be read.', '', 50 );
		}
		$inclusive = ! empty( $body['IsTaxInclusive'] );
		$lines     = array();
		$subtotal  = 0;
		$tax       = 0;
		$credit    = false;
		foreach ( (array) ( $body['Lines'] ?? array() ) as $line ) {
			$item = $this->store['Item'][ (string) ( $line['Item']['UID'] ?? '' ) ] ?? null;
			if ( null === $item ) {
				return $this->error( 400, 'NotFound', 'The item was not found.', '', 150 );
			}
			if ( false === $item['IsSold'] ) {
				return $this->error( 400, 'ValidationError', 'The item is not sold.', '', 100 );
			}
			$rate = 0;
			if ( isset( $line['TaxCode']['UID'] ) ) {
				if ( ! isset( $this->store['TaxCode'][ (string) $line['TaxCode']['UID'] ] ) ) {
					return $this->error( 400, 'NotFound', 'The tax code was not found.', '', 150 );
				}
				$rate = (int) round( (float) $this->store['TaxCode'][ (string) $line['TaxCode']['UID'] ]['Rate'] * 10000 );
			}
			$quantity = (float) ( $line['ShipQuantity'] ?? 0 );
			$price    = (float) ( $line['UnitPrice'] ?? 0 );
			$total    = (float) ( $line['Total'] ?? 0 );
			if ( $price < 0 && 0.0 !== $total ) {
				return $this->error( 400, 'UnitPriceNotValid', 'A negative unit price needs a zero total.', '', 12101 );
			}
			$expected = $quantity * $price * ( 100 - (float) ( $line['DiscountPercent'] ?? 0 ) ) / 100;
			if ( abs( $expected - $total ) > 0.0051 ) {
				return $this->error( 400, 'ValidationError', 'The line total does not equal quantity times unit price less the discount.', 'Total', 100 );
			}
			if ( $this->strictStock && true === $item['IsInventoried'] && $quantity > (float) $item['QuantityOnHand'] ) {
				return $this->error( 400, 'InsufficientItemQuantity', 'There is not enough of this item.', '', 25002 );
			}
			$credit  = $credit || ( $quantity < 0 && $total < 0 && $price > 0 );
			$net     = $this->minor( $total );
			$taxPart = $inclusive ? $this->roundDiv( $net * $rate, 1000000 + $rate ) : $this->roundDiv( $net * $rate, 1000000 );
			$tax    += $taxPart;
			$subtotal += $inclusive ? $net - $taxPart : $net;
			$lines[] = $line + array( 'RowID' => count( $lines ) + 1 );
		}
		if ( array() === $lines ) {
			return $this->error( 400, 'Required', 'The invoice needs at least one line.', 'Lines', 100 );
		}
		if ( isset( $body['Freight'] ) && 0.0 !== (float) $body['Freight'] ) {
			$rate = 0;
			if ( isset( $body['FreightTaxCode']['UID'] ) ) {
				if ( ! isset( $this->store['TaxCode'][ (string) $body['FreightTaxCode']['UID'] ] ) ) {
					return $this->error( 400, 'NotFound', 'The freight tax code was not found.', '', 150 );
				}
				$rate = (int) round( (float) $this->store['TaxCode'][ (string) $body['FreightTaxCode']['UID'] ]['Rate'] * 10000 );
			}
			$freight   = $this->minor( (float) $body['Freight'] );
			$taxPart   = $inclusive ? $this->roundDiv( $freight * $rate, 1000000 + $rate ) : $this->roundDiv( $freight * $rate, 1000000 );
			$tax      += $taxPart;
			$subtotal += $inclusive ? $freight - $taxPart : $freight;
		}
		$whole    = $subtotal + $tax;
		$credit   = $credit && $whole < 0;
		$uid      = $this->uid();
		$status   = $credit && $this->creditStatus ? 'Credit' : 'Open';
		$invoice  = array(
			'UID'                         => $uid,
			'Number'                      => sprintf( '%08d', ++$this->numbers ),
			'Date'                        => substr( (string) $body['Date'], 0, 10 ) . 'T00:00:00',
			'Customer'                    => array( 'UID' => $customer['UID'], 'Name' => (string) ( $customer['CompanyName'] ?? trim( ( $customer['FirstName'] ?? '' ) . ' ' . ( $customer['LastName'] ?? '' ) ) ), 'DisplayID' => $customer['DisplayID'] ),
			'CustomerPurchaseOrderNumber' => (string) ( $body['CustomerPurchaseOrderNumber'] ?? '' ),
			'JournalMemo'                 => (string) ( $body['JournalMemo'] ?? '' ),
			'Comment'                     => (string) ( $body['Comment'] ?? '' ),
			'ShipToAddress'               => (string) ( $body['ShipToAddress'] ?? '' ),
			'IsTaxInclusive'              => $inclusive,
			'Terms'                       => $body['Terms'] ?? null,
			'Lines'                       => $lines,
			'Freight'                     => (float) ( $body['Freight'] ?? 0 ),
			'Subtotal'                    => $this->decimal( $subtotal ),
			'TotalTax'                    => $this->decimal( $tax ),
			'TotalAmount'                 => $this->decimal( $whole ),
			'BalanceDueAmount'            => $this->decimal( $whole ),
			'Status'                      => $status,
			'ForeignCurrency'             => $this->foreignCurrency ? array( 'CurrencyCode' => 'USD', 'ExchangeRate' => 0.65 ) : null,
			'RowVersion'                  => '1',
		);
		$this->store['Invoice'][ $uid ] = $invoice;
		return $this->created( 'Sale/Invoice/Item', $uid );
	}

	/**
	 * Delete an invoice.
	 *
	 * @param string $uid UID.
	 */
	private function deleteInvoice( string $uid ): HttpResponse {
		$invoice = $this->store['Invoice'][ $uid ] ?? null;
		if ( null === $invoice ) {
			return $this->error( 404, 'NotFound', 'The invoice was not found.', '', 150 );
		}
		if ( abs( (float) $invoice['BalanceDueAmount'] - (float) $invoice['TotalAmount'] ) > 0.0001 ) {
			return $this->error( 400, 'ValidationError', 'An invoice with a payment cannot be deleted.', '', 10001 );
		}
		unset( $this->store['Invoice'][ $uid ] );
		return FakeHttpClient::status( 200, '' );
	}

	/**
	 * Record a customer payment.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postPayment( ApiFakeRequest $request ): HttpResponse {
		$body = $request->json();
		$known = array( 'UID', 'DepositTo', 'Account', 'Customer', 'ReceiptNumber', 'Date', 'AmountReceived', 'AmountReceivedForeign', 'PaymentMethod', 'Memo', 'Invoices', 'TransactionID', 'ForeignCurrency', 'CurrencyExchangeRate', 'URI', 'RowVersion' );
		foreach ( array_keys( $body ) as $field ) {
			if ( ! in_array( $field, $known, true ) ) {
				return $this->error( 400, 'SerializationError', 'The field ' . $field . ' is not part of a customer payment.', '', 50 );
			}
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $body['Date'] ?? '' ) ) ) {
			return $this->error( 400, 'Required', 'The date is missing or not YYYY-MM-DD HH:MM:SS.', '', 100 );
		}
		if ( mb_strlen( (string) ( $body['Memo'] ?? '' ) ) > 255 ) {
			return $this->error( 400, 'ValidationError', 'The memo is too long.', 'Maximum 255 characters', 100 );
		}
		if ( ! isset( $this->store['Customer'][ (string) ( $body['Customer']['UID'] ?? '' ) ] ) ) {
			return $this->error( 400, 'NotFound', 'The customer was not found.', '', 150 );
		}
		if ( ! in_array( $body['DepositTo'] ?? '', array( 'Account', 'UndepositedFunds' ), true ) || ( 'Account' === $body['DepositTo'] && ! isset( $this->store['Account'][ (string) ( $body['Account']['UID'] ?? '' ) ] ) ) ) {
			return $this->error( 400, 'Required', 'The deposit account is missing.', '', 100 );
		}
		if ( ! is_numeric( $body['AmountReceived'] ?? null ) ) {
			return $this->error( 400, 'Required', 'The amount received is missing.', '', 100 );
		}
		$applied = 0.0;
		foreach ( (array) ( $body['Invoices'] ?? array() ) as $row ) {
			$invoice = $this->store['Invoice'][ (string) ( $row['UID'] ?? '' ) ] ?? null;
			if ( null === $invoice ) {
				return $this->error( 400, 'NotFound', 'The invoice was not found.', '', 150 );
			}
			if ( ! in_array( $row['Type'] ?? '', array( 'Invoice', 'Order' ), true ) || ! is_numeric( $row['AmountApplied'] ?? null ) ) {
				return $this->error( 400, 'Required', 'An invoice row needs a Type and an AmountApplied.', '', 100 );
			}
			if ( (float) $row['AmountApplied'] > (float) $invoice['BalanceDueAmount'] + 0.0001 ) {
				return $this->error( 400, 'ValidationError', 'The payment is more than the balance.', '', 100 );
			}
			$applied += (float) $row['AmountApplied'];
		}
		if ( abs( $applied - (float) $body['AmountReceived'] ) > 0.0001 ) {
			return $this->error( 400, 'ValidationError', 'The amount received does not equal the amounts applied.', '', 100 );
		}
		foreach ( (array) $body['Invoices'] as $row ) {
			$invoice                                       = $this->store['Invoice'][ (string) $row['UID'] ];
			$left                                          = max( 0, $this->minor( (float) $invoice['BalanceDueAmount'] ) - $this->minor( (float) $row['AmountApplied'] ) + $this->paymentShortfall );
			$invoice['BalanceDueAmount']                   = $this->decimal( $left );
			$invoice['Status']                             = 0 === $left ? 'Closed' : $invoice['Status'];
			$this->store['Invoice'][ (string) $row['UID'] ] = $invoice;
			if ( $this->invoiceGoneAfterPayment ) {
				unset( $this->store['Invoice'][ (string) $row['UID'] ] );
			}
		}
		$uid                           = $this->uid();
		$this->store['Payment'][ $uid ] = $body + array( 'UID' => $uid );
		return $this->created( 'Sale/CustomerPayment', $uid );
	}

	/**
	 * Whole minor units of a decimal amount.
	 *
	 * @param float $amount Amount.
	 */
	private function minor( float $amount ): int {
		return Money::fromDecimalString( number_format( $amount, Money::exponent( $this->currency ), '.', '' ), $this->currency );
	}

	/**
	 * A minor-unit amount as the number MYOB returns.
	 *
	 * @param int $minor Minor units.
	 */
	private function decimal( int $minor ): float {
		return (float) Money::toDecimalString( $minor, $this->currency );
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
}
