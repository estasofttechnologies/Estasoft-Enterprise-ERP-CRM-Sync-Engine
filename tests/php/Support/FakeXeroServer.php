<?php
/**
 * A small in-memory Xero for the connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the calls the Xero connector makes, in the shapes of the examples in Xero's OpenAPI file (https://github.com/XeroAPI/Xero-OpenAPI,
 * xero_accounting.yaml, 19.1.0): list answers with a pagination block, records inside Invoices / Contacts / Items / CreditNotes / Payments /
 * Allocations, validation errors as {"ErrorNumber":10,"Type":"ValidationException","Elements":[{"ValidationErrors":[{"Message":"..."}]}]},
 * dates as /Date(ms+0000)/ next to DateString. It models on purpose what once hid bugs: Xero works out the tax and the total per line (exclusive
 * and inclusive amounts), a contact name must be unique, a tracked item refuses a sale beyond its stock unless backorders are allowed, an
 * Idempotency-Key replays the first answer, a draft can be deleted, only an approved invoice takes a payment, and a credit note is applied only
 * to the same contact. It does not guard everything Xero guards. All data is synthetic.
 */
final class FakeXeroServer {

	public const CLIENT_ID     = 'xero-client-id-0001';
	public const CLIENT_SECRET = 'xero-client-secret-9f3a7c1d';
	public const TENANT        = '8f5e6d1c-1111-4bbb-8ccc-123456789012';
	public const ROOT          = '/api.xro/2.0';

	/**
	 * The routing fake.
	 *
	 * @var ApiFakeServer
	 */
	public ApiFakeServer $server;

	/**
	 * Organisation base currency.
	 *
	 * @var string
	 */
	public string $baseCurrency = 'AED';

	/**
	 * Currencies enabled besides the base currency.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'KWD', 'USD' );

	/**
	 * Invoices by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Credit notes by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $creditNotes = array();

	/**
	 * Contacts by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $contacts = array();

	/**
	 * Items by code.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * Accounts by code.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $accounts = array();

	/**
	 * Tax rates by tax type.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $taxRates = array();

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
	 * Token requests received (decoded form plus the Authorization header).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $tokenRequests = array();

	/**
	 * Replay store of Idempotency-Key.
	 *
	 * @var array<string,HttpResponse>
	 */
	public array $replays = array();

	/**
	 * Idempotency keys seen, in order.
	 *
	 * @var array<int,string>
	 */
	public array $keys = array();

	/**
	 * Make approval move the invoice date to today (a vendor that overwrites it).
	 *
	 * @var bool
	 */
	public bool $moveDateOnApprove = false;

	/**
	 * Day the server believes it is.
	 *
	 * @var string
	 */
	public string $today = '2026-11-02';

	/**
	 * Daily calls left, sent in a header.
	 *
	 * @var int
	 */
	public int $dailyLeft = 4321;

	/**
	 * Refuse the client at the token endpoint (a revoked secret).
	 *
	 * @var bool
	 */
	public bool $refuseClient = false;

	/**
	 * Number of searches by reference that answer "none found" although the document exists (a create that finished late).
	 *
	 * @var int
	 */
	public int $hideDocuments = 0;

	/**
	 * Process the next write but lose the answer (a time-out after Xero did the work).
	 *
	 * @var bool
	 */
	public bool $loseNextAnswer = false;

	/**
	 * Answer every API call with a 401 that names a missing scope.
	 *
	 * @var bool
	 */
	public bool $missingScope = false;

	/**
	 * Failure scripted for the next write.
	 *
	 * @var array{0:string,1:int}|null
	 */
	private ?array $failure = null;

	/**
	 * Counters for ids and numbers.
	 *
	 * @var int
	 */
	private int $sequence = 0;

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
		$this->server = ApiFakeServer::on( $http, array( 'api.xero.com', 'identity.xero.com' ) );
		$this->seed();
		$this->routes();
	}

	/**
	 * Secrets for a connection that is signed in: an access token that works for 30 minutes and a refresh token.
	 *
	 * @param int $now Epoch seconds now.
	 * @return array<string,string>
	 */
	public function signedIn( int $now ): array {
		$access  = 'xero-access-' . ( ++$this->issued ) . '-aaaaaaaa';
		$refresh = 'xero-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'            => self::CLIENT_SECRET,
			'oauth_access_token'       => $access,
			'oauth_refresh_token'      => $refresh,
			'oauth_expires_at'         => (string) ( $now + 1800 ),
			'oauth_refresh_expires_at' => (string) ( $now + 60 * 86400 ),
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
	 * @param string              $code  Item code.
	 * @param array<string,mixed> $extra More fields.
	 * @return array<string,mixed>
	 */
	public function addItem( string $code, array $extra = array() ): array {
		$this->items[ $code ] = $extra + array(
			'ItemID'               => $this->guid(),
			'Code'                 => $code,
			'Name'                 => 'Item ' . $code,
			'IsSold'               => true,
			'IsTrackedAsInventory' => false,
			'SalesDetails'         => array( 'UnitPrice' => 10.0, 'AccountCode' => '200', 'TaxType' => 'OUTPUT' ),
		);
		return $this->items[ $code ];
	}

	/**
	 * Add a contact.
	 *
	 * @param string              $name  Name.
	 * @param string              $email Email.
	 * @param array<string,mixed> $extra More fields.
	 * @return array<string,mixed>
	 */
	public function addContact( string $name, string $email, array $extra = array() ): array {
		$id                    = $this->guid();
		$this->contacts[ $id ] = $extra + array(
			'ContactID'     => $id,
			'ContactStatus' => 'ACTIVE',
			'Name'          => $name,
			'EmailAddress'  => $email,
			'IsCustomer'    => false,
		);
		return $this->contacts[ $id ];
	}

	/**
	 * Requests received for a method and a path pattern.
	 *
	 * @param string|null $method Method.
	 * @param string|null $regex  Path regular expression (path without the root).
	 * @return array<int,ApiFakeRequest>
	 */
	public function calls( ?string $method = null, ?string $regex = null ): array {
		return $this->server->requests( $method, null === $regex ? null : '#^' . preg_quote( self::ROOT, '#' ) . $regex . '$#' );
	}

	/**
	 * The first invoice with a reference.
	 *
	 * @param string $reference Reference.
	 * @return array<string,mixed>|null
	 */
	public function invoiceByReference( string $reference ): ?array {
		foreach ( $this->invoices as $invoice ) {
			if ( ( $invoice['Reference'] ?? '' ) === $reference ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * Seed the organisation data.
	 */
	private function seed(): void {
		$this->accounts = array(
			'200' => array( 'Code' => '200', 'Name' => 'Sales', 'Class' => 'REVENUE', 'Status' => 'ACTIVE', 'EnablePaymentsToAccount' => false ),
			'260' => array( 'Code' => '260', 'Name' => 'Shipping income', 'Class' => 'REVENUE', 'Status' => 'ACTIVE', 'EnablePaymentsToAccount' => false ),
			'090' => array( 'Code' => '090', 'Name' => 'Business Bank Account', 'Class' => 'ASSET', 'Status' => 'ACTIVE', 'EnablePaymentsToAccount' => true ),
		);
		$this->taxRates = array(
			'OUTPUT'  => array( 'Name' => 'VAT 5%', 'TaxType' => 'OUTPUT', 'Status' => 'ACTIVE', 'DisplayTaxRate' => 5.0, 'CanApplyToRevenue' => true ),
			'OUTPUT2' => array( 'Name' => 'VAT 15%', 'TaxType' => 'OUTPUT2', 'Status' => 'ACTIVE', 'DisplayTaxRate' => 15.0, 'CanApplyToRevenue' => true ),
			'NONE'    => array( 'Name' => 'No Tax', 'TaxType' => 'NONE', 'Status' => 'ACTIVE', 'DisplayTaxRate' => 0.0, 'CanApplyToRevenue' => true ),
			'INPUT'   => array( 'Name' => 'Purchases VAT', 'TaxType' => 'INPUT', 'Status' => 'ACTIVE', 'DisplayTaxRate' => 5.0, 'CanApplyToRevenue' => false ),
			'OLD'     => array( 'Name' => 'Old rate', 'TaxType' => 'OLD', 'Status' => 'ARCHIVED', 'DisplayTaxRate' => 3.0, 'CanApplyToRevenue' => true ),
		);
		$this->addItem( 'CS-MUG-01', array( 'Name' => 'Mug', 'IsTrackedAsInventory' => true, 'QuantityOnHand' => 40.0 ) );
	}

	/**
	 * Register the routes.
	 */
	private function routes(): void {
		$s = $this->server;
		$s->route( 'POST', '/connect/token', fn( ApiFakeRequest $r ) => $this->token( $r ) );
		$s->route( 'GET', '/connections', fn( ApiFakeRequest $r ) => $this->guard( $r, false, fn() => array( array( 'id' => 'conn-1', 'tenantId' => self::TENANT, 'tenantType' => 'ORGANISATION', 'tenantName' => 'Demo Trading LLC' ), array( 'id' => 'conn-2', 'tenantId' => '00000000-2222-4333-8444-555555555555', 'tenantType' => 'PRACTICE', 'tenantName' => 'Some Practice' ) ) ) );

		$root = self::ROOT;
		$s->route( 'GET', $root . '/Organisation', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => array( 'Organisations' => array( array( 'OrganisationID' => self::TENANT, 'Name' => 'Demo Trading LLC', 'LegalName' => 'Demo Trading LLC', 'BaseCurrency' => $this->baseCurrency, 'CountryCode' => 'AE', 'Version' => 'GLOBAL', 'IsDemoCompany' => true, 'OrganisationStatus' => 'ACTIVE' ) ) ) ) );
		$s->route( 'GET', $root . '/Currencies', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => array( 'Currencies' => array_map( static fn( string $code ): array => array( 'Code' => $code, 'Description' => $code ), array_merge( array( $this->baseCurrency ), $this->currencies ) ) ) ) );
		$s->route( 'GET', $root . '/TaxRates', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => array( 'TaxRates' => $this->filterTaxRates( $r ) ) ) );
		$s->route( 'GET', $root . '/Accounts', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => array( 'Accounts' => $this->filterAccounts( $r ) ) ) );
		$s->route( 'GET', $root . '/Items', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => array( 'Items' => $this->filterItems( $r ) ) ) );
		$s->route( 'GET', $root . '/Contacts', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => $this->listContacts( $r ) ) );
		$s->route( 'PUT', $root . '/Contacts', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->putContact( $r ) ) );
		$s->route( 'GET', $root . '/Invoices', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => $this->listDocuments( $r, 'Invoices' ) ) );
		$s->route( 'GET', $root . '/Invoices/{id}', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => $this->getDocument( $r, 'Invoices' ) ) );
		$s->route( 'GET', $root . '/Invoices/{id}/OnlineInvoice', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => isset( $this->invoices[ $r->params['id'] ] ) ? array( 'OnlineInvoices' => array( array( 'OnlineInvoiceUrl' => 'https://in.xero.test/online/' . $r->params['id'] ) ) ) : null ) );
		$s->route( 'PUT', $root . '/Invoices', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->putDocument( $r, 'Invoices' ) ) );
		$s->route( 'POST', $root . '/Invoices/{id}', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postStatus( $r, 'Invoices' ) ) );
		$s->route( 'GET', $root . '/CreditNotes', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => $this->listDocuments( $r, 'CreditNotes' ) ) );
		$s->route( 'GET', $root . '/CreditNotes/{id}', fn( ApiFakeRequest $r ) => $this->guard( $r, true, fn() => $this->getDocument( $r, 'CreditNotes' ) ) );
		$s->route( 'PUT', $root . '/CreditNotes', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->putDocument( $r, 'CreditNotes' ) ) );
		$s->route( 'POST', $root . '/CreditNotes/{id}', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->postStatus( $r, 'CreditNotes' ) ) );
		$s->route( 'PUT', $root . '/CreditNotes/{id}/Allocations', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->putAllocation( $r ) ) );
		$s->route( 'PUT', $root . '/Payments', fn( ApiFakeRequest $r ) => $this->write( $r, fn() => $this->putPayment( $r ) ) );
	}

	/**
	 * The token endpoint.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function token( ApiFakeRequest $request ): HttpResponse {
		$form                = $request->form();
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
		$access  = 'xero-access-' . $this->issued . '-cccccccc';
		$refresh = 'xero-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => 1800 ) );
	}

	/**
	 * Check the sign-in and the tenant, then answer a read.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param bool           $tenant  Whether the tenant header is needed.
	 * @param \Closure       $answer  Returns the answer array or null.
	 */
	private function guard( ApiFakeRequest $request, bool $tenant, \Closure $answer ): HttpResponse {
		$denied = $this->denied( $request, $tenant );
		if ( null !== $denied ) {
			return $denied;
		}
		$data = $answer();
		if ( null === $data ) {
			return FakeHttpClient::status( 404, '{"Type":null,"Title":"Not Found","Status":404}' );
		}
		return FakeHttpClient::json( $data, 200, array( 'x-daylimit-remaining' => (string) $this->dailyLeft, 'x-minlimit-remaining' => '59' ) );
	}

	/**
	 * Answer a write, after the scripted failure and the Idempotency-Key replay.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param \Closure       $answer  Returns the answer array, an HttpResponse, or null.
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
		$denied = $this->denied( $request, true );
		if ( null !== $denied ) {
			return $denied;
		}
		$key = (string) $request->header( 'Idempotency-Key' );
		if ( '' !== $key ) {
			$this->keys[] = $key;
			if ( isset( $this->replays[ $key ] ) ) {
				return $this->replays[ $key ];
			}
		}
		$data = $answer();
		if ( $data instanceof HttpResponse ) {
			return $data;
		}
		if ( null === $data ) {
			return FakeHttpClient::status( 404, '{"Type":null,"Title":"Not Found","Status":404}' );
		}
		$response = FakeHttpClient::json( $data, 200, array( 'x-daylimit-remaining' => (string) $this->dailyLeft ) );
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
				return FakeHttpClient::status( 500, '{"Type":"InternalError","Title":"An error occurred"}' );
			case 'rate_limit':
				return FakeHttpClient::tooManyRequests( $seconds );
			case 'unknown_fault':
				return FakeHttpClient::status( 418, '{"Type":"Teapot","Message":"Something no catalog knows"}' );
			case 'auth':
				$this->accessTokens  = array();
				$this->refuseClient = true;
				return null;
		}
		return null;
	}

	/**
	 * The 401 or 403 for a request that is not allowed, or null.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param bool           $tenant  Whether the tenant header is needed.
	 */
	private function denied( ApiFakeRequest $request, bool $tenant ): ?HttpResponse {
		if ( $this->missingScope && $tenant ) {
			return FakeHttpClient::status( 401, '{"Type":null,"Title":"Unauthorized","Status":401,"Detail":"AuthorizationUnsuccessful"}', array( 'www-authenticate' => 'Bearer error="insufficient_scope"' ) );
		}
		if ( empty( $this->accessTokens[ $request->bearer() ] ) ) {
			return FakeHttpClient::status( 401, '{"Type":null,"Title":"Unauthorized","Status":401,"Detail":"TokenExpired: token expired at 2026-11-02T07:00:00Z","Instance":"fake"}' );
		}
		if ( $tenant && self::TENANT !== (string) $request->header( 'Xero-tenant-id' ) ) {
			return FakeHttpClient::status( 403, '{"Type":null,"Title":"Forbidden","Status":403,"Detail":"AuthenticationUnsuccessful","Instance":"fake"}' );
		}
		return null;
	}

	/**
	 * A Xero validation error.
	 *
	 * @param array<int,string> $messages Messages.
	 * @param string            $kind     Record kind (Invoices, Contacts ...).
	 */
	private function invalid( array $messages, string $kind = 'Invoices' ): HttpResponse {
		unset( $kind );
		return FakeHttpClient::json(
			array(
				'ErrorNumber' => 10,
				'Type'        => 'ValidationException',
				'Message'     => 'A validation exception occurred',
				'Elements'    => array( array( 'ValidationErrors' => array_map( static fn( string $m ): array => array( 'Message' => $m ), $messages ) ) ),
			),
			400
		);
	}

	/**
	 * The conditions of a where filter that are joined with AND.
	 *
	 * @param string $where Filter.
	 * @return array<int,string>
	 */
	private function conditions( string $where ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\s+AND\s+/', $where ) ?: array() ) ) );
	}

	/**
	 * Tax rates filtered by status.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function filterTaxRates( ApiFakeRequest $request ): array {
		$where = (string) ( $request->query['where'] ?? '' );
		$rates = array_values( $this->taxRates );
		if ( 1 === preg_match( '/Status=="(\w+)"/', $where, $m ) ) {
			$rates = array_values( array_filter( $rates, static fn( array $rate ): bool => $rate['Status'] === $m[1] ) );
		}
		return $rates;
	}

	/**
	 * Accounts filtered by code.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function filterAccounts( ApiFakeRequest $request ): array {
		$where = (string) ( $request->query['where'] ?? '' );
		if ( 1 === preg_match( '/Code=="([^"]*)"/', $where, $m ) ) {
			return isset( $this->accounts[ $m[1] ] ) ? array( $this->accounts[ $m[1] ] ) : array();
		}
		return array_values( $this->accounts );
	}

	/**
	 * Items filtered by `Code=="A" OR Code=="B"`.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function filterItems( ApiFakeRequest $request ): array {
		$where = (string) ( $request->query['where'] ?? '' );
		if ( '' === $where ) {
			return array_values( $this->items );
		}
		preg_match_all( '/Code=="([^"]*)"/', $where, $m );
		$found = array();
		foreach ( $m[1] as $code ) {
			foreach ( $this->items as $item ) {
				if ( 0 === strcasecmp( $item['Code'], $code ) ) {
					$found[] = $item;
				}
			}
		}
		return $found;
	}

	/**
	 * Contacts, paged, by search text or contact number.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>
	 */
	private function listContacts( ApiFakeRequest $request ): array {
		$rows = array_values( $this->contacts );
		$term = strtolower( (string) ( $request->query['searchTerm'] ?? '' ) );
		if ( '' !== $term ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $c ) use ( $term ): bool {
						foreach ( array( 'Name', 'FirstName', 'LastName', 'ContactNumber', 'EmailAddress' ) as $field ) {
							if ( false !== strpos( strtolower( (string) ( $c[ $field ] ?? '' ) ), $term ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}
		if ( 1 === preg_match( '/ContactNumber=="([^"]*)"/', (string) ( $request->query['where'] ?? '' ), $m ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $c ): bool => ( $c['ContactNumber'] ?? '' ) === $m[1] ) );
		}
		return $this->paged( $rows, $request, 'Contacts' );
	}

	/**
	 * Create a contact.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function putContact( ApiFakeRequest $request ): array|HttpResponse {
		$body = $request->json()['Contacts'][0] ?? array();
		$name = (string) ( $body['Name'] ?? '' );
		if ( '' === $name ) {
			return $this->invalid( array( 'Please enter a Contact Name' ), 'Contacts' );
		}
		foreach ( $this->contacts as $contact ) {
			if ( 0 === strcasecmp( (string) $contact['Name'], $name ) ) {
				return $this->invalid( array( "The contact name '" . $name . "' is already assigned to another contact. The contact name must be unique across all active contacts." ), 'Contacts' );
			}
		}
		$email = (string) ( $body['EmailAddress'] ?? '' );
		if ( '' !== $email && 1 !== preg_match( '/^[\x20-\x7E]+$/', $email ) ) {
			return $this->invalid( array( 'Email address must be valid.' ), 'Contacts' );
		}
		$contact = $this->addContact( $name, $email, $body );
		return array( 'Contacts' => array( $contact ) );
	}

	/**
	 * Invoices or credit notes, filtered, paged.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Invoices or CreditNotes.
	 * @return array<string,mixed>
	 */
	private function listDocuments( ApiFakeRequest $request, string $kind ): array {
		$rows = array_values( 'Invoices' === $kind ? $this->invoices : $this->creditNotes );
		if ( $this->hideDocuments > 0 && 1 === preg_match( '/Reference=="/', (string) ( $request->query['where'] ?? '' ) ) ) {
			--$this->hideDocuments;
			$rows = array();
		}
		foreach ( $this->conditions( (string) ( $request->query['where'] ?? '' ) ) as $condition ) {
			if ( 1 === preg_match( '/^Type=="(\w+)"$/', $condition, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $d ): bool => $d['Type'] === $m[1] ) );
			} elseif ( 1 === preg_match( '/^Reference=="([^"]*)"$/', $condition, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $d ): bool => $d['Reference'] === $m[1] ) );
			} elseif ( 1 === preg_match( '/^Reference\.StartsWith\("([^"]*)"\)$/', $condition, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $d ): bool => str_starts_with( (string) $d['Reference'], $m[1] ) ) );
			} elseif ( 1 === preg_match( '/^Date>=DateTime\((\d+), (\d+), (\d+)\)$/', $condition, $m ) ) {
				$from = sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
				$rows = array_values( array_filter( $rows, static fn( array $d ): bool => substr( (string) $d['DateString'], 0, 10 ) >= $from ) );
			}
		}
		return $this->paged( $rows, $request, $kind );
	}

	/**
	 * One invoice or credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Invoices or CreditNotes.
	 * @return array<string,mixed>|null
	 */
	private function getDocument( ApiFakeRequest $request, string $kind ): ?array {
		$store = 'Invoices' === $kind ? $this->invoices : $this->creditNotes;
		return isset( $store[ $request->params['id'] ] ) ? array( $kind => array( $store[ $request->params['id'] ] ) ) : null;
	}

	/**
	 * Page a list the way Xero does (100 to a page, a pagination block).
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param ApiFakeRequest                 $request Request.
	 * @param string                         $key     List key.
	 * @return array<string,mixed>
	 */
	private function paged( array $rows, ApiFakeRequest $request, string $key ): array {
		$size  = max( 1, min( 100, (int) ( $request->query['pageSize'] ?? 100 ) ) );
		$page  = max( 1, (int) ( $request->query['page'] ?? 1 ) );
		$count = (int) max( 1, ceil( count( $rows ) / $size ) );
		return array(
			'Status'     => 'OK',
			'pagination' => array( 'page' => $page, 'pageSize' => $size, 'pageCount' => $count, 'itemCount' => count( $rows ) ),
			$key         => array_slice( $rows, ( $page - 1 ) * $size, $size ),
		);
	}

	/**
	 * Create an invoice or a credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Invoices or CreditNotes.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function putDocument( ApiFakeRequest $request, string $kind ): array|HttpResponse {
		$body     = $request->json()[ $kind ][0] ?? array();
		$contact  = $this->contacts[ (string) ( $body['Contact']['ContactID'] ?? '' ) ] ?? null;
		$currency = (string) ( $body['CurrencyCode'] ?? $this->baseCurrency );
		$errors   = array();
		if ( null === $contact ) {
			$errors[] = 'Contact could not be found';
		}
		if ( $currency !== $this->baseCurrency && ! in_array( $currency, $this->currencies, true ) ) {
			$errors[] = "Currency code '" . $currency . "' is not valid for this organisation";
		}
		if ( empty( $body['LineItems'] ) ) {
			$errors[] = 'The document must have at least one line item';
		}
		$allow = 'true' === (string) ( $request->query['allowBackorders'] ?? 'false' );
		$lines = array();
		foreach ( (array) ( $body['LineItems'] ?? array() ) as $line ) {
			$checked = $this->checkLine( (array) $line, $allow, $errors );
			$lines[] = $checked;
		}
		if ( array() !== $errors ) {
			return $this->invalid( $errors, $kind );
		}
		$inclusive = 'Inclusive' === ( $body['LineAmountTypes'] ?? 'Exclusive' );
		$totals    = $this->totals( $lines, $inclusive, $currency );
		$id        = $this->guid();
		$day       = (string) ( $body['Date'] ?? $this->today );
		$number    = 'Invoices' === $kind ? sprintf( 'INV-%04d', ++$this->sequence ) : sprintf( 'CN-%04d', ++$this->sequence );
		$document  = array(
			'Type'            => 'Invoices' === $kind ? 'ACCREC' : 'ACCRECCREDIT',
			'Contact'         => $contact,
			'DateString'      => $day . 'T00:00:00',
			'Date'            => '/Date(' . ( strtotime( $day . ' UTC' ) * 1000 ) . '+0000)/',
			'Status'          => (string) ( $body['Status'] ?? 'DRAFT' ),
			'LineAmountTypes' => $inclusive ? 'Inclusive' : 'Exclusive',
			'LineItems'       => $lines,
			'SubTotal'        => $this->decimal( $totals['subtotal'], $currency ),
			'TotalTax'        => $this->decimal( $totals['tax'], $currency ),
			'Total'           => $this->decimal( $totals['total'], $currency ),
			'CurrencyCode'    => $currency,
			'Reference'       => (string) ( $body['Reference'] ?? '' ),
			'HasErrors'       => false,
		);
		if ( 'Invoices' === $kind ) {
			$document += array(
				'InvoiceID'      => $id,
				'InvoiceNumber'  => $number,
				'DueDateString'  => (string) ( $body['DueDate'] ?? $day ) . 'T00:00:00',
				'AmountDue'      => $this->decimal( $totals['total'], $currency ),
				'AmountPaid'     => 0.0,
				'AmountCredited' => 0.0,
				'Payments'       => array(),
				'CreditNotes'    => array(),
			);
			$this->invoices[ $id ] = $document;
		} else {
			$document += array(
				'CreditNoteID'     => $id,
				'CreditNoteNumber' => $number,
				'RemainingCredit'  => $this->decimal( $totals['total'], $currency ),
				'Allocations'      => array(),
			);
			$this->creditNotes[ $id ] = $document;
		}
		return array( $kind => array( $document ) );
	}

	/**
	 * Check one line and add what Xero adds (the item's description and defaults).
	 *
	 * @param array<string,mixed> $line   Line from the request.
	 * @param bool                $allow  Backorders allowed.
	 * @param array<int,string>   $errors Collected errors.
	 * @return array<string,mixed>
	 */
	private function checkLine( array $line, bool $allow, array &$errors ): array {
		if ( '' === trim( (string) ( $line['Description'] ?? '' ) ) ) {
			$errors[] = 'Description is required';
		}
		$code = (string) ( $line['ItemCode'] ?? '' );
		if ( '' !== $code ) {
			$item = $this->items[ $code ] ?? null;
			if ( null === $item ) {
				$errors[] = "Item code '" . $code . "' is not valid";
			} elseif ( ! empty( $item['IsTrackedAsInventory'] ) && ! $allow && (float) ( $line['Quantity'] ?? 0 ) > (float) $item['QuantityOnHand'] ) {
				$errors[] = 'The quantity on hand of item ' . $code . ' is not enough';
			} else {
				$line['AccountCode'] = $line['AccountCode'] ?? $item['SalesDetails']['AccountCode'];
				$line['TaxType']     = $line['TaxType'] ?? $item['SalesDetails']['TaxType'];
			}
		} elseif ( ! isset( $this->accounts[ (string) ( $line['AccountCode'] ?? '' ) ] ) ) {
			$errors[] = "Account code '" . (string) ( $line['AccountCode'] ?? '' ) . "' is not a valid code";
		}
		$tax = (string) ( $line['TaxType'] ?? 'NONE' );
		if ( ! isset( $this->taxRates[ $tax ] ) ) {
			$errors[] = "Tax type '" . $tax . "' does not exist";
		}
		$line['TaxType'] = $tax;
		return $line;
	}

	/**
	 * Totals the way Xero works them out: per line, whole minor units, half away from zero.
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
			$rate = (int) round( ( (float) ( $this->taxRates[ $line['TaxType'] ]['DisplayTaxRate'] ?? 0 ) ) * 10000 );
			if ( isset( $line['LineAmount'] ) ) {
				$amount = $this->minor( (float) $line['LineAmount'], $currency );
			} else {
				$gross  = $this->scaled( (float) ( $line['Quantity'] ?? 1 ) ) * $this->scaled( (float) ( $line['UnitAmount'] ?? 0 ), 4 );
				$amount = $this->roundDiv( $gross * 10 ** Money::exponent( $currency ), 10 ** 10 );
				$disc   = (float) ( $line['DiscountRate'] ?? 0 );
				if ( $disc > 0 ) {
					$amount = $this->roundDiv( $amount * (int) round( ( 100 - $disc ) * 10000 ), 1000000 );
				}
			}
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
	 * A decimal number as an integer with a number of places (exact text route, no float arithmetic).
	 *
	 * @param float $value  Number.
	 * @param int   $places Places.
	 */
	private function scaled( float $value, int $places = 6 ): int {
		return (int) str_replace( '.', '', number_format( $value, $places, '.', '' ) );
	}

	/**
	 * Minor units from a number.
	 *
	 * @param float  $value    Number.
	 * @param string $currency Currency.
	 */
	private function minor( float $value, string $currency ): int {
		return Money::fromDecimalString( number_format( $value, Money::exponent( $currency ), '.', '' ), $currency );
	}

	/**
	 * A minor-unit amount as the number Xero returns.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 */
	private function decimal( int $minor, string $currency ): float {
		return (float) Money::toDecimalString( $minor, $currency );
	}

	/**
	 * Change the status of an invoice or credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $kind    Invoices or CreditNotes.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function postStatus( ApiFakeRequest $request, string $kind ): array|HttpResponse|null {
		$store = 'Invoices' === $kind ? $this->invoices : $this->creditNotes;
		$id    = $request->params['id'];
		if ( ! isset( $store[ $id ] ) ) {
			return null;
		}
		$status   = (string) ( $request->json()[ $kind ][0]['Status'] ?? '' );
		$document = $store[ $id ];
		$from     = (string) $document['Status'];
		if ( 'AUTHORISED' === $status && in_array( $from, array( 'DRAFT', 'SUBMITTED' ), true ) ) {
			$document['Status'] = 'AUTHORISED';
			if ( $this->moveDateOnApprove ) {
				$document['DateString'] = $this->today . 'T00:00:00';
			}
		} elseif ( 'DELETED' === $status && 'DRAFT' === $from ) {
			$document['Status'] = 'DELETED';
		} else {
			return $this->invalid( array( 'Invalid status change from ' . $from . ' to ' . $status ), $kind );
		}
		if ( 'Invoices' === $kind ) {
			$this->invoices[ $id ] = $document;
		} else {
			$this->creditNotes[ $id ] = $document;
		}
		return array( $kind => array( $document ) );
	}

	/**
	 * Record a payment.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function putPayment( ApiFakeRequest $request ): array|HttpResponse {
		$body    = $request->json()['Payments'][0] ?? array();
		$id      = (string) ( $body['Invoice']['InvoiceID'] ?? '' );
		$account = $this->accounts[ (string) ( $body['Account']['Code'] ?? '' ) ] ?? null;
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->invalid( array( 'Invoice not found' ), 'Payments' );
		}
		if ( null === $account || empty( $account['EnablePaymentsToAccount'] ) ) {
			return $this->invalid( array( 'Account must allow payments' ), 'Payments' );
		}
		$invoice = $this->invoices[ $id ];
		if ( 'AUTHORISED' !== $invoice['Status'] ) {
			return $this->invalid( array( 'Payments can only be made against approved invoices' ), 'Payments' );
		}
		if ( (float) $body['Amount'] > (float) $invoice['AmountDue'] + 0.0001 ) {
			return $this->invalid( array( 'Payment amount exceeds the amount outstanding' ), 'Payments' );
		}
		$currency                    = (string) $invoice['CurrencyCode'];
		$due                         = $this->minor( (float) $invoice['AmountDue'], $currency ) - $this->minor( (float) $body['Amount'], $currency );
		$payment                     = array( 'PaymentID' => $this->guid(), 'Amount' => (float) $body['Amount'], 'Reference' => (string) ( $body['Reference'] ?? '' ), 'Date' => (string) $body['Date'] );
		$invoice['Payments'][]       = $payment;
		$invoice['AmountDue']        = $this->decimal( $due, $currency );
		$invoice['AmountPaid']       = (float) $invoice['AmountPaid'] + (float) $body['Amount'];
		$invoice['Status']           = 0 === $due ? 'PAID' : 'AUTHORISED';
		$this->invoices[ $id ]       = $invoice;
		return array( 'Payments' => array( $payment ) );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function putAllocation( ApiFakeRequest $request ): array|HttpResponse|null {
		$noteId = $request->params['id'];
		if ( ! isset( $this->creditNotes[ $noteId ] ) ) {
			return null;
		}
		$body      = $request->json()['Allocations'][0] ?? array();
		$invoiceId = (string) ( $body['Invoice']['InvoiceID'] ?? '' );
		$note      = $this->creditNotes[ $noteId ];
		if ( ! isset( $this->invoices[ $invoiceId ] ) || 'AUTHORISED' !== $note['Status'] ) {
			return $this->invalid( array( 'The credit note or the invoice cannot take an allocation' ), 'Allocations' );
		}
		$invoice = $this->invoices[ $invoiceId ];
		if ( $invoice['Contact']['ContactID'] !== $note['Contact']['ContactID'] ) {
			return $this->invalid( array( 'The credit note and the invoice must have the same contact' ), 'Allocations' );
		}
		$amount = (float) $body['Amount'];
		if ( $amount > (float) $note['RemainingCredit'] + 0.0001 || $amount > (float) $invoice['AmountDue'] + 0.0001 ) {
			return $this->invalid( array( 'The allocation is more than the credit or the amount due' ), 'Allocations' );
		}
		$currency                      = (string) $invoice['CurrencyCode'];
		$left                          = $this->minor( (float) $invoice['AmountDue'], $currency ) - $this->minor( $amount, $currency );
		$remaining                     = $this->minor( (float) $note['RemainingCredit'], $currency ) - $this->minor( $amount, $currency );
		$invoice['AmountDue']          = $this->decimal( $left, $currency );
		$invoice['AmountCredited']     = (float) $invoice['AmountCredited'] + $amount;
		$invoice['Status']             = 0 === $left ? 'PAID' : $invoice['Status'];
		$note['RemainingCredit']       = $this->decimal( $remaining, $currency );
		$note['Status']                = 0 === $remaining ? 'PAID' : $note['Status'];
		$allocation                    = array( 'AllocationID' => $this->guid(), 'Amount' => $amount, 'Date' => (string) $body['Date'], 'Invoice' => array( 'InvoiceID' => $invoiceId ) );
		$note['Allocations'][]         = $allocation;
		$this->invoices[ $invoiceId ]  = $invoice;
		$this->creditNotes[ $noteId ]  = $note;
		return array( 'Allocations' => array( $allocation ) );
	}

	/**
	 * A GUID that is the same on every run of a test (counted).
	 */
	private function guid(): string {
		return sprintf( '00000000-0000-4000-8000-%012d', ++$this->sequence + 1000 );
	}
}
