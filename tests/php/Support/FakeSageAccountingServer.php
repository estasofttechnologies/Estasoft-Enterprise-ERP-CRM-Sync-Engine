<?php
/**
 * A small in-memory Sage Business Cloud Accounting API for the connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the calls the Sage connector makes, in the shapes the Sage guides show: lists as {"$items":[...],"$next":null,"$total":n} read with page and
 * items_per_page, creates wrapped in the resource name (`sales_invoice`, `contact`, ...), errors as a list of {"$severity","$dataCode","$message","$source"}.
 * It models on purpose what once hid bugs: a draft invoice has no number and takes no payment, tax_amount is the caller's job and must be exact and absent for a zero
 * rate, amounts are decimal strings, an idempotency_id replays the first answer (and on another resource is IdempotencyResourceMismatch), the X-Business header must name
 * a business of the signed-in user, a DELETE on a draft removes it and on a credit note voids it with the reason, and a search returns short rows so the connector has to
 * read the document by id. All data is synthetic.
 */
final class FakeSageAccountingServer {

	public const CLIENT_ID     = 'sage-client-id-0001';
	public const CLIENT_SECRET = 'sage-client-secret-44bd90ee';
	public const BUSINESS      = '4a6e1c5d9b2f4c3e8a7b0d1e2f3a4b5c';

	/**
	 * The routing fake.
	 *
	 * @var ApiFakeServer
	 */
	public ApiFakeServer $server;

	/**
	 * Base currency of the business.
	 *
	 * @var string
	 */
	public string $currency = 'GBP';

	/**
	 * Entities by name and id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $store = array(
		'ledger'     => array(),
		'tax'        => array(),
		'bank'       => array(),
		'product'    => array(),
		'contact'    => array(),
		'invoice'    => array(),
		'credit'     => array(),
		'payment'    => array(),
		'allocation' => array(),
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
	 * Replay store of idempotency ids: id => [resource, response].
	 *
	 * @var array<string,array{0:string,1:HttpResponse}>
	 */
	public array $replays = array();

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
	 * Answer every call with 403 (the user has no role in the business).
	 *
	 * @var bool
	 */
	public bool $noAccess = false;

	/**
	 * Whether a voided credit note is reported with its void reason.
	 *
	 * @var bool
	 */
	public bool $showVoidReason = true;

	/**
	 * Country of the business (lower-case ISO code). It decides how tax works: gb, ie and fr work tax out of a price that includes it, ca and es do not, and es
	 * refuses a tax_amount.
	 *
	 * @var string
	 */
	public string $country = 'gb';

	/**
	 * Whether a DELETE with a void_reason is accepted for a draft invoice (the guide documents voiding from UNPAID only).
	 *
	 * @var bool
	 */
	public bool $draftsCanBeVoided = false;

	/**
	 * Whether a DELETE with a void_reason is accepted for a credit note (the guide documents none).
	 *
	 * @var bool
	 */
	public bool $creditNotesCanBeVoided = false;

	/**
	 * Number of searches by reference that answer "none found" although the document exists (a create that finished late).
	 *
	 * @var int
	 */
	public int $hideDocuments = 0;

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
	 * Counters for document numbers.
	 *
	 * @var array<string,int>
	 */
	private array $numbers = array( 'invoice' => 0, 'credit' => 0 );

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
		$this->server = ApiFakeServer::on( $http, array( 'api.accounting.sage.com', 'oauth.accounting.sage.com', 'www.sageone.com' ) );
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
		$access  = 'sage-access-' . ( ++$this->issued ) . '-aaaaaaaa';
		$refresh = 'sage-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'            => self::CLIENT_SECRET,
			'oauth_access_token'       => $access,
			'oauth_refresh_token'      => $refresh,
			'oauth_expires_at'         => (string) ( $now + 300 ),
			'oauth_lifetime'           => '300',
			'oauth_refresh_expires_at' => (string) ( $now + 31 * 86400 ),
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
	 * Add a product.
	 *
	 * @param string              $code  Item code.
	 * @param array<string,mixed> $extra More fields.
	 * @return array<string,mixed>
	 */
	public function addProduct( string $code, array $extra = array() ): array {
		$id                           = 'prod-' . ( ++$this->sequence );
		$this->store['product'][ $id ] = $extra + array(
			'id'          => $id,
			'item_code'   => $code,
			'description' => 'Product ' . $code,
			'active'      => true,
		);
		return $this->store['product'][ $id ];
	}

	/**
	 * Add a customer contact.
	 *
	 * @param string              $name      Name.
	 * @param string              $reference Reference.
	 * @param array<string,mixed> $extra     More fields.
	 * @return array<string,mixed>
	 */
	public function addContact( string $name, string $reference, array $extra = array() ): array {
		$id                           = 'con-' . ( ++$this->sequence );
		$this->store['contact'][ $id ] = $extra + array(
			'id'           => $id,
			'name'         => $name,
			'displayed_as' => $name,
			'reference'    => $reference,
		);
		return $this->store['contact'][ $id ];
	}

	/**
	 * Requests received for a method and a path pattern under the API root.
	 *
	 * @param string|null $method Method.
	 * @param string|null $regex  Regular expression for the path after /v3.1.
	 * @return array<int,ApiFakeRequest>
	 */
	public function calls( ?string $method = null, ?string $regex = null ): array {
		return $this->server->requests( $method, null === $regex ? null : '#^/v3\.1' . $regex . '$#' );
	}

	/**
	 * Writes received (POST and DELETE calls to the API, not the token endpoint), oldest first.
	 *
	 * @return array<int,ApiFakeRequest>
	 */
	public function writes(): array {
		return array_values( array_filter( $this->server->received, static fn( ApiFakeRequest $r ): bool => in_array( $r->method, array( 'POST', 'DELETE' ), true ) && 0 === strpos( $r->path, '/v3.1/' ) ) );
	}

	/**
	 * The first document of an entity with a reference.
	 *
	 * @param string $entity invoice or credit.
	 * @param string $ref    Reference.
	 * @return array<string,mixed>|null
	 */
	public function byReference( string $entity, string $ref ): ?array {
		foreach ( $this->store[ $entity ] as $document ) {
			if ( ( $document['reference'] ?? '' ) === $ref ) {
				return $document;
			}
		}
		return null;
	}

	/**
	 * Seed the business data.
	 */
	private function seed(): void {
		foreach ( array( array( 'GB_STANDARD', 'Standard 20.00%', 20.0, false ), array( 'GB_REDUCED', 'Reduced 5.00%', 5.0, false ), array( 'GB_ZERO', 'Zero 0.00%', 0.0, false ), array( 'GB_NO_TAX', 'No tax 0.00%', 0.0, false ), array( 'GB_OLD', 'Old 17.50%', 17.5, true ) ) as $row ) {
			$this->store['tax'][ $row[0] ] = array( 'id' => $row[0], 'name' => $row[1], 'displayed_as' => $row[1], 'percentage' => number_format( $row[2], 2, '.', '' ), 'retired' => $row[3] );
		}
		foreach ( array( array( 'led-4000', 4000, 'Sales Type A' ), array( 'led-4010', 4010, 'Sales Type B' ), array( 'led-4900', 4900, 'Other income' ) ) as $row ) {
			$this->store['ledger'][ $row[0] ] = array( 'id' => $row[0], 'nominal_code' => $row[1], 'name' => $row[2], 'displayed_as' => $row[2] . ' (' . $row[1] . ')' );
		}
		$this->store['bank']['bank-1200'] = array( 'id' => 'bank-1200', 'displayed_as' => 'Current Account (1200)' );
		$this->addProduct( 'CS-MUG-01', array( 'description' => 'Mug' ) );
		$this->addProduct( 'WC-RETIRED', array( 'active' => false ) );
	}

	/**
	 * Register the routes.
	 */
	private function routes(): void {
		$s    = $this->server;
		$root = '/v3.1';
		$s->route( 'POST', '/token', fn( ApiFakeRequest $r ) => $this->token( $r ) );
		$s->route( 'GET', $root . '/businesses', fn( ApiFakeRequest $r ) => $this->read( $r, false, fn() => $this->listOf( $r, array( array( 'id' => self::BUSINESS, 'name' => 'Demo Trading Ltd', 'displayed_as' => 'Demo Trading Ltd', 'country' => array( 'id' => 'GB' ) ), array( 'id' => 'ffffffffffffffffffffffffffffffff', 'name' => 'Other Business', 'displayed_as' => 'Other Business' ) ) ) ) );
		$s->route( 'GET', $root . '/financial_settings', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => FakeHttpClient::json( array( 'base_currency' => array( 'id' => $this->currency, 'displayed_as' => $this->currency ), 'year_end_date' => '2026-03-31' ) ) ) );
		$s->route( 'GET', $root . '/tax_rates', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, array_values( $this->store['tax'] ) ) ) );
		$s->route( 'GET', $root . '/ledger_accounts', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, array_values( $this->store['ledger'] ) ) ) );
		$s->route( 'GET', $root . '/bank_accounts', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, array_values( $this->store['bank'] ) ) ) );
		$s->route( 'GET', $root . '/products', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, $this->searched( $this->store['product'], $r, array( 'item_code', 'description' ) ) ) ) );
		$s->route( 'GET', $root . '/contacts', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, $this->searched( $this->store['contact'], $r, array( 'name', 'reference', 'email' ) ) ) ) );
		$s->route( 'POST', $root . '/contacts', fn( ApiFakeRequest $r ) => $this->write( $r, 'contact', fn() => $this->postContact( $r ) ) );
		$s->route( 'GET', $root . '/sales_invoices', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, $this->shortRows( $this->searched( $this->store['invoice'], $r, array( 'reference' ) ), $r, array( 'id', 'reference', 'invoice_number', 'status', 'date', 'total_amount' ) ) ) ) );
		$s->route( 'GET', $root . '/sales_invoices/{id}', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->one( 'invoice', $r->params['id'] ) ) );
		$s->route( 'POST', $root . '/sales_invoices', fn( ApiFakeRequest $r ) => $this->write( $r, 'sales_invoice', fn() => $this->postDocument( $r, 'invoice' ) ) );
		$s->route( 'POST', $root . '/sales_invoices/{id}/release', fn( ApiFakeRequest $r ) => $this->write( $r, '', fn() => $this->release( $r->params['id'] ) ) );
		$s->route( 'DELETE', $root . '/sales_invoices/{id}', fn( ApiFakeRequest $r ) => $this->write( $r, '', fn() => $this->removeDocument( $r, 'invoice' ) ) );
		$s->route( 'GET', $root . '/sales_credit_notes', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->listOf( $r, $this->shortRows( $this->searched( $this->store['credit'], $r, array( 'reference' ) ), $r, array( 'id', 'reference', 'credit_note_number', 'status', 'date', 'total_amount', 'notes' ) ) ) ) );
		$s->route( 'GET', $root . '/sales_credit_notes/{id}', fn( ApiFakeRequest $r ) => $this->read( $r, true, fn() => $this->one( 'credit', $r->params['id'] ) ) );
		$s->route( 'POST', $root . '/sales_credit_notes', fn( ApiFakeRequest $r ) => $this->write( $r, 'sales_credit_note', fn() => $this->postDocument( $r, 'credit' ) ) );
		$s->route( 'DELETE', $root . '/sales_credit_notes/{id}', fn( ApiFakeRequest $r ) => $this->write( $r, '', fn() => $this->removeDocument( $r, 'credit' ) ) );
		$s->route( 'POST', $root . '/contact_payments', fn( ApiFakeRequest $r ) => $this->write( $r, 'contact_payment', fn() => $this->postPayment( $r ) ) );
		$s->route( 'POST', $root . '/contact_allocations', fn( ApiFakeRequest $r ) => $this->write( $r, 'contact_allocation', fn() => $this->postAllocation( $r ) ) );
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
			if ( 'good-code' !== ( $form['code'] ?? '' ) || '' === (string) ( $form['code_verifier'] ?? '' ) ) {
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
		$access  = 'sage-access-' . $this->issued . '-cccccccc';
		$refresh = 'sage-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => 300, 'refresh_token_expires_in' => 2678400, 'scope' => 'full_access' ) );
	}

	/**
	 * An error answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Data code.
	 * @param string $message Message.
	 * @param string $source  Source.
	 */
	private function error( int $status, string $code, string $message, string $source = '' ): HttpResponse {
		return FakeHttpClient::json( array( array( '$severity' => 'error', '$dataCode' => $code, '$message' => $message, '$source' => $source ) ), $status );
	}

	/**
	 * Check the sign-in and the business header.
	 *
	 * @param ApiFakeRequest $request  Request.
	 * @param bool           $business Whether the call needs X-Business.
	 */
	private function denied( ApiFakeRequest $request, bool $business ): ?HttpResponse {
		if ( empty( $this->accessTokens[ $request->bearer() ] ) ) {
			return $this->error( 401, 'Unauthorized', 'The access token is invalid or has expired.' );
		}
		if ( $this->noAccess ) {
			return $this->error( 403, 'Forbidden', 'You do not have access to this resource.', 'Role' );
		}
		if ( $business && self::BUSINESS !== (string) $request->header( 'X-Business' ) ) {
			return $this->error( 403, 'Forbidden', 'The business is not available to this user.', 'X-Business' );
		}
		return null;
	}

	/**
	 * Answer a read.
	 *
	 * @param ApiFakeRequest $request  Request.
	 * @param bool           $business Whether the call needs X-Business.
	 * @param \Closure       $answer   Returns the response.
	 */
	private function read( ApiFakeRequest $request, bool $business, \Closure $answer ): HttpResponse {
		return $this->denied( $request, $business ) ?? $answer();
	}

	/**
	 * Answer a write, after the scripted failure and the idempotency replay.
	 *
	 * @param ApiFakeRequest $request  Request.
	 * @param string         $resource Wrapper key of the body (empty when the call carries none).
	 * @param \Closure       $answer   Returns the response.
	 */
	private function write( ApiFakeRequest $request, string $resource, \Closure $answer ): HttpResponse {
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
		$key = '' !== $resource ? (string) ( $request->json()[ $resource ]['idempotency_id'] ?? '' ) : '';
		if ( '' !== $key && isset( $this->replays[ $key ] ) ) {
			return $this->replays[ $key ][0] === $resource ? $this->replays[ $key ][1] : $this->error( 422, 'IdempotencyResourceMismatch', 'The idempotency id belongs to another kind of record.', 'idempotency_id' );
		}
		$response = $answer();
		if ( '' !== $key && $response->status < 300 ) {
			$this->replays[ $key ] = array( $resource, $response );
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
	 * @param int    $seconds Wait for rate_limit.
	 */
	private function failed( string $kind, int $seconds ): ?HttpResponse {
		switch ( $kind ) {
			case 'timeout':
				return FakeHttpClient::timeout();
			case 'certificate':
				return FakeHttpClient::certificateError();
			case 'server_error':
				return $this->error( 500, 'InternalError', 'Something went wrong.' );
			case 'rate_limit':
				return new HttpResponse( 429, '[{"$severity":"error","$dataCode":"TooManyRequests","$message":"Rate limit exceeded"}]', array( 'retry-after' => (string) $seconds ) );
			case 'unknown_fault':
				return FakeHttpClient::status( 418, '[{"$severity":"error","$dataCode":"Teapot","$message":"Something no catalog knows"}]' );
			case 'auth':
				$this->accessTokens = array();
				$this->refuseClient = true;
				return null;
		}
		return null;
	}

	/**
	 * A list answer, paged with page and items_per_page.
	 *
	 * @param ApiFakeRequest                 $request Request.
	 * @param array<int,array<string,mixed>> $rows    All rows.
	 */
	private function listOf( ApiFakeRequest $request, array $rows ): HttpResponse {
		$per  = min( 200, max( 1, (int) ( $request->query['items_per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $request->query['page'] ?? 1 ) );
		return FakeHttpClient::json( array( '$total' => count( $rows ), '$page' => $page, '$next' => $page * $per < count( $rows ) ? '/' . ltrim( $request->path, '/' ) . '?page=' . ( $page + 1 ) : null, '$back' => null, '$itemsPerPage' => $per, '$items' => array_slice( $rows, ( $page - 1 ) * $per, $per ) ) );
	}

	/**
	 * Rows that match the search text (contained in one of the fields, ignoring case), from_date and contact_type_id.
	 *
	 * @param array<string,array<string,mixed>> $rows    Rows.
	 * @param ApiFakeRequest                    $request Request.
	 * @param array<int,string>                 $fields  Fields the search looks at.
	 * @return array<int,array<string,mixed>>
	 */
	private function searched( array $rows, ApiFakeRequest $request, array $fields ): array {
		$search = strtolower( (string) ( $request->query['search'] ?? '' ) );
		$from   = (string) ( $request->query['from_date'] ?? '' );
		$out    = array();
		if ( $this->hideDocuments > 0 && '' !== $search && in_array( 'reference', $fields, true ) && 1 === count( $fields ) ) {
			--$this->hideDocuments;
			return array();
		}
		foreach ( $rows as $row ) {
			if ( false === ( $row['active'] ?? true ) ) {
				continue;
			}
			if ( '' !== $from && (string) ( $row['date'] ?? '9999' ) < $from ) {
				continue;
			}
			if ( '' !== $search ) {
				$hit = false;
				foreach ( $fields as $field ) {
					$hit = $hit || false !== strpos( strtolower( (string) ( $row[ $field ] ?? '' ) ), $search );
				}
				if ( ! $hit ) {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Cut documents to the short row a list returns.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param ApiFakeRequest                 $request Request.
	 * @param array<int,string>              $keep    Fields kept.
	 * @return array<int,array<string,mixed>>
	 */
	private function shortRows( array $rows, ApiFakeRequest $request, array $keep ): array {
		unset( $request );
		return array_map( static fn( array $row ): array => array_intersect_key( $row, array_flip( $keep ) ), $rows );
	}

	/**
	 * One record.
	 *
	 * @param string $entity Entity.
	 * @param string $id     Id.
	 */
	private function one( string $entity, string $id ): HttpResponse {
		return isset( $this->store[ $entity ][ $id ] ) ? FakeHttpClient::json( $this->store[ $entity ][ $id ] ) : $this->error( 404, 'RecordNotFound', 'The record was not found.' );
	}

	/**
	 * Create a contact.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postContact( ApiFakeRequest $request ): HttpResponse {
		$body = (array) ( $request->json()['contact'] ?? array() );
		if ( '' === (string) ( $body['name'] ?? '' ) || mb_strlen( (string) $body['name'] ) > 200 ) {
			return $this->error( 422, 'RequiredFieldMissing', 'name is required (up to 200 characters).', 'name' );
		}
		if ( ! in_array( 'CUSTOMER', (array) ( $body['contact_type_ids'] ?? array() ), true ) ) {
			return $this->error( 422, 'RequiredFieldMissing', 'contact_type_ids is required.', 'contact_type_ids' );
		}
		if ( mb_strlen( (string) ( $body['reference'] ?? '' ) ) > 50 || mb_strlen( (string) ( $body['main_address']['address_line_1'] ?? '' ) ) > 50 ) {
			return $this->error( 422, 'ValueTooLong', 'A value is too long.', 'reference' );
		}
		$contact = $this->addContact( (string) $body['name'], (string) ( $body['reference'] ?? '' ), array_diff_key( $body, array( 'idempotency_id' => true ) ) );
		return FakeHttpClient::json( $contact, 201 );
	}

	/**
	 * Create a sales invoice (as a draft) or a sales credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $entity  invoice or credit.
	 */
	private function postDocument( ApiFakeRequest $request, string $entity ): HttpResponse {
		$key  = 'invoice' === $entity ? 'sales_invoice' : 'sales_credit_note';
		$body = (array) ( $request->json()[ $key ] ?? array() );
		if ( ! isset( $this->store['contact'][ (string) ( $body['contact_id'] ?? '' ) ] ) ) {
			return $this->error( 422, 'RecordNotFound', "can't find contact", 'contact_id' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $body['date'] ?? '' ) ) ) {
			return $this->error( 422, 'RequiredFieldMissing', 'date is required (YYYY-MM-DD).', 'date' );
		}
		if ( 'invoice' === $entity && isset( $body['status_id'] ) && 'DRAFT' !== $body['status_id'] ) {
			return $this->error( 422, 'InvalidValue', 'status_id may only be DRAFT on create.', 'status_id' );
		}
		$linesKey = 'invoice' === $entity ? 'invoice_lines' : 'credit_note_lines';
		$lines    = (array) ( $body[ $linesKey ] ?? array() );
		if ( array() === $lines ) {
			return $this->error( 422, 'RequiredFieldMissing', $linesKey . ' is required.', $linesKey );
		}
		$net   = 0;
		$tax   = 0;
		$count = 0;
		foreach ( $lines as $line ) {
			++$count;
			$worked = $this->workLine( (array) $line, $count );
			if ( $worked instanceof HttpResponse ) {
				return $worked;
			}
			$net += $worked[0];
			$tax += $worked[1];
		}
		if ( isset( $body['shipping_net_amount'] ) ) {
			$ship = $this->workShipping( $body );
			if ( $ship instanceof HttpResponse ) {
				return $ship;
			}
			$net += $ship[0];
			$tax += $ship[1];
		}
		$id = ( 'invoice' === $entity ? 'si-' : 'sc-' ) . ( ++$this->sequence );
		$document = array(
			'id'                 => $id,
			'displayed_as'       => (string) ( $body['reference'] ?? $id ),
			'reference'          => (string) ( $body['reference'] ?? '' ),
			'contact'            => array( 'id' => $body['contact_id'], 'displayed_as' => $this->store['contact'][ $body['contact_id'] ]['name'] ),
			'date'               => $body['date'],
			'due_date'           => (string) ( $body['due_date'] ?? $body['date'] ),
			'notes'              => (string) ( $body['notes'] ?? '' ),
			'currency'           => array( 'id' => $this->currency ),
			'net_amount'         => $this->decimal( $net ),
			'tax_amount'         => $this->decimal( $tax ),
			'total_amount'       => $this->decimal( $net + $tax ),
			'outstanding_amount' => $this->decimal( $net + $tax ),
			$linesKey            => $lines,
			'status'             => array( 'id' => 'invoice' === $entity ? 'DRAFT' : 'UNPAID' ),
		);
		if ( 'invoice' === $entity ) {
			$document['invoice_number'] = null;
		} else {
			$document['credit_note_number'] = sprintf( 'SCN-%d', ++$this->numbers['credit'] );
		}
		$this->store[ $entity ][ $id ] = $document;
		return FakeHttpClient::json( $document, 201 );
	}

	/**
	 * Work out a line the way Sage checks it: net, tax rate, and the tax_amount the caller sent.
	 *
	 * @param array<string,mixed> $line   Line.
	 * @param int                 $number Position.
	 * @return array{0:int,1:int}|HttpResponse Net and tax in minor units.
	 */
	private function workLine( array $line, int $number ): array|HttpResponse {
		$source = 'lines[' . $number . ']';
		if ( ! isset( $this->store['ledger'][ (string) ( $line['ledger_account_id'] ?? '' ) ] ) ) {
			return $this->error( 422, 'RecordNotFound', "can't find ledger account", $source . '.ledger_account_id' );
		}
		if ( isset( $line['product_id'] ) && ! isset( $this->store['product'][ (string) $line['product_id'] ] ) ) {
			return $this->error( 422, 'RecordNotFound', "can't find product", $source . '.product_id' );
		}
		$rate = $this->store['tax'][ (string) ( $line['tax_rate_id'] ?? '' ) ] ?? null;
		if ( null === $rate ) {
			return $this->error( 422, 'RecordNotFound', "can't find tax rate", $source . '.tax_rate_id' );
		}
		foreach ( array( 'quantity', 'unit_price' ) as $field ) {
			if ( ! isset( $line[ $field ] ) || ! is_string( $line[ $field ] ) || ! is_numeric( $line[ $field ] ) ) {
				return $this->error( 422, 'InvalidValue', $field . ' must be a decimal string.', $source . '.' . $field );
			}
		}
		$unit     = $this->minor( (string) $line['unit_price'] );
		$gross    = $this->roundDiv( $this->scaled( (string) $line['quantity'] ) * $unit, 1000000 );
		$discount = isset( $line['discount_amount'] ) ? $this->minor( (string) $line['discount_amount'] ) : 0;
		$net      = $gross - $discount;
		if ( ! empty( $line['unit_price_includes_tax'] ) ) {
			if ( ! in_array( $this->country, array( 'gb', 'ie', 'fr' ), true ) ) {
				return $this->error( 422, 'InvalidValue', 'unit_price_includes_tax is not applicable in this region.', $source . '.unit_price_includes_tax' );
			}
			$percentage = (int) round( (float) $rate['percentage'] * 10000 );
			$tax        = $this->roundDiv( $net * $percentage, 1000000 + $percentage );
			if ( isset( $line['tax_amount'] ) && $this->minor( (string) $line['tax_amount'] ) !== $tax ) {
				return $this->error( 422, 'InvalidValue', 'tax_amount is not correct for the price and the tax rate.', $source . '.tax_amount' );
			}
			return array( $net - $tax, $tax );
		}
		return $this->tax( $net, $rate, $line['tax_amount'] ?? null, $source );
	}

	/**
	 * Work out the shipping of a document.
	 *
	 * @param array<string,mixed> $body Document body.
	 * @return array{0:int,1:int}|HttpResponse
	 */
	private function workShipping( array $body ): array|HttpResponse {
		$rate = $this->store['tax'][ (string) ( $body['shipping_tax_rate_id'] ?? '' ) ] ?? null;
		if ( null === $rate ) {
			return $this->error( 422, 'RecordNotFound', "can't find tax rate", 'shipping_tax_rate_id' );
		}
		return $this->tax( $this->minor( (string) $body['shipping_net_amount'] ), $rate, $body['shipping_tax_amount'] ?? null, 'shipping_tax_amount' );
	}

	/**
	 * The tax of a net amount, checked against the tax_amount the caller sent.
	 *
	 * @param int                 $net    Net amount in minor units.
	 * @param array<string,mixed> $rate   Tax rate row.
	 * @param mixed               $sent   The tax_amount in the request, or null.
	 * @param string              $source Where the amount came from.
	 * @return array{0:int,1:int}|HttpResponse
	 */
	private function tax( int $net, array $rate, mixed $sent, string $source ): array|HttpResponse {
		$percentage = (int) round( (float) $rate['percentage'] * 10000 );
		if ( 'es' === $this->country ) {
			return null === $sent ? array( $net, $this->roundDiv( $net * $percentage, 1000000 ) ) : $this->error( 422, 'InvalidValue', 'tax_amount is not allowed for a Spanish business.', $source );
		}
		if ( 0 === $percentage ) {
			return null === $sent ? array( $net, 0 ) : $this->error( 422, 'InvalidValue', 'tax_amount must be left out for a rate of zero.', $source );
		}
		$expected = $this->roundDiv( $net * $percentage, 1000000 );
		if ( null === $sent || $this->minor( (string) $sent ) !== $expected ) {
			return $this->error( 422, 'InvalidValue', 'tax_amount is not correct for the net amount and the tax rate.', $source );
		}
		return array( $net, $expected );
	}

	/**
	 * Release a draft invoice.
	 *
	 * @param string $id Invoice id.
	 */
	private function release( string $id ): HttpResponse {
		$invoice = $this->store['invoice'][ $id ] ?? null;
		if ( null === $invoice ) {
			return $this->error( 404, 'RecordNotFound', 'The record was not found.' );
		}
		if ( 'DRAFT' !== $invoice['status']['id'] ) {
			return $this->error( 422, 'InvalidTransition', 'Only a draft can be released.', 'status' );
		}
		$invoice['status']                 = array( 'id' => 'UNPAID' );
		$invoice['invoice_number']         = sprintf( 'SI-%d', ++$this->numbers['invoice'] );
		$this->store['invoice'][ $id ]     = $invoice;
		return FakeHttpClient::json( $invoice, 200 );
	}

	/**
	 * Delete a draft invoice; void a credit note.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $entity  invoice or credit.
	 */
	private function removeDocument( ApiFakeRequest $request, string $entity ): HttpResponse {
		$id       = $request->params['id'];
		$document = $this->store[ $entity ][ $id ] ?? null;
		if ( null === $document ) {
			return $this->error( 404, 'RecordNotFound', 'The record was not found.' );
		}
		if ( '' === (string) ( $request->json()['void_reason'] ?? '' ) ) {
			return $this->error( 422, 'RequiredFieldMissing', 'The body must include a void_reason.', 'void_reason' );
		}
		if ( 'invoice' === $entity ) {
			if ( 'DRAFT' === $document['status']['id'] && ! $this->draftsCanBeVoided ) {
				return $this->error( 422, 'InvalidTransition', 'Only an unpaid invoice can be voided.', 'status' );
			}
			if ( ! in_array( $document['status']['id'], array( 'DRAFT', 'UNPAID' ), true ) ) {
				return $this->error( 422, 'InvalidTransition', 'You cannot directly void an invoice that has payments or allocations.', 'status' );
			}
		} elseif ( ! $this->creditNotesCanBeVoided ) {
			return $this->error( 405, 'MethodNotAllowed', 'A credit note cannot be deleted or voided.' );
		}
		$document['status']             = array( 'id' => 'VOID' );
		$document['outstanding_amount'] = $this->decimal( 0 );
		if ( $this->showVoidReason ) {
			$document['void_reason'] = (string) $request->json()['void_reason'];
		}
		$this->store[ $entity ][ $id ] = $document;
		return FakeHttpClient::status( 204, '' );
	}

	/**
	 * Record a customer receipt.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postPayment( ApiFakeRequest $request ): HttpResponse {
		$body = (array) ( $request->json()['contact_payment'] ?? array() );
		if ( 'CUSTOMER_RECEIPT' !== ( $body['transaction_type_id'] ?? '' ) ) {
			return $this->error( 422, 'InvalidValue', 'transaction_type_id must be CUSTOMER_RECEIPT.', 'transaction_type_id' );
		}
		if ( ! isset( $this->store['bank'][ (string) ( $body['bank_account_id'] ?? '' ) ] ) ) {
			return $this->error( 422, 'RecordNotFound', "can't find bank account", 'bank_account_id' );
		}
		if ( mb_strlen( (string) ( $body['reference'] ?? '' ) ) > 25 ) {
			return $this->error( 422, 'ValueTooLong', 'reference is longer than 25 characters.', 'reference' );
		}
		$applied = 0;
		foreach ( (array) ( $body['allocated_artefacts'] ?? array() ) as $row ) {
			$invoice = $this->store['invoice'][ (string) ( $row['artefact_id'] ?? '' ) ] ?? null;
			if ( null === $invoice ) {
				return $this->error( 422, 'RecordNotFound', "can't find invoice", 'allocated_artefacts' );
			}
			if ( ! in_array( $invoice['status']['id'], array( 'UNPAID', 'PART_PAID' ), true ) ) {
				return $this->error( 422, 'InvalidTransition', 'A draft takes no payment.', 'allocated_artefacts' );
			}
			if ( $this->minor( (string) $row['amount'] ) > $this->minor( (string) $invoice['outstanding_amount'] ) ) {
				return $this->error( 422, 'InvalidValue', 'The payment is more than the amount outstanding.', 'allocated_artefacts' );
			}
			$applied += $this->minor( (string) $row['amount'] );
		}
		if ( $applied !== $this->minor( (string) ( $body['total_amount'] ?? '0' ) ) ) {
			return $this->error( 422, 'InvalidValue', 'total_amount must equal the allocated amounts.', 'total_amount' );
		}
		foreach ( (array) $body['allocated_artefacts'] as $row ) {
			$this->settle( 'invoice', (string) $row['artefact_id'], $this->minor( (string) $row['amount'] ) );
		}
		$id                           = 'pay-' . ( ++$this->sequence );
		$this->store['payment'][ $id ] = array( 'id' => $id ) + array_diff_key( $body, array( 'idempotency_id' => true ) );
		return FakeHttpClient::json( $this->store['payment'][ $id ], 201 );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	private function postAllocation( ApiFakeRequest $request ): HttpResponse {
		$body = (array) ( $request->json()['contact_allocation'] ?? array() );
		if ( 'CUSTOMER_ALLOCATION' !== ( $body['transaction_type_id'] ?? '' ) ) {
			return $this->error( 422, 'InvalidValue', 'transaction_type_id must be CUSTOMER_ALLOCATION.', 'transaction_type_id' );
		}
		$rows = (array) ( $body['allocated_artefacts'] ?? array() );
		$sum  = 0;
		foreach ( $rows as $row ) {
			$amount = $this->minor( (string) ( $row['amount'] ?? '0' ) );
			$sum   += $amount;
			$entity = isset( $this->store['invoice'][ (string) ( $row['artefact_id'] ?? '' ) ] ) ? 'invoice' : ( isset( $this->store['credit'][ (string) ( $row['artefact_id'] ?? '' ) ] ) ? 'credit' : '' );
			if ( '' === $entity ) {
				return $this->error( 422, 'RecordNotFound', "can't find the document", 'allocated_artefacts' );
			}
			if ( 'invoice' === $entity && $amount <= 0 || 'credit' === $entity && $amount >= 0 ) {
				return $this->error( 422, 'InvalidValue', 'The invoice takes a positive amount and the credit note a negative one.', 'allocated_artefacts' );
			}
			$open = $this->minor( (string) $this->store[ $entity ][ $row['artefact_id'] ]['outstanding_amount'] );
			if ( abs( $amount ) > $open ) {
				return $this->error( 422, 'InvalidValue', 'The allocation is more than the amount outstanding.', 'allocated_artefacts' );
			}
		}
		if ( 0 !== $sum || 2 !== count( $rows ) ) {
			return $this->error( 422, 'InvalidValue', 'The allocation must balance to zero.', 'allocated_artefacts' );
		}
		foreach ( $rows as $row ) {
			$entity = isset( $this->store['invoice'][ (string) $row['artefact_id'] ] ) ? 'invoice' : 'credit';
			$this->settle( $entity, (string) $row['artefact_id'], abs( $this->minor( (string) $row['amount'] ) ) );
		}
		$id                              = 'alloc-' . ( ++$this->sequence );
		$this->store['allocation'][ $id ] = array( 'id' => $id ) + array_diff_key( $body, array( 'idempotency_id' => true ) );
		return FakeHttpClient::json( $this->store['allocation'][ $id ], 201 );
	}

	/**
	 * Reduce the amount outstanding of a document and move its status.
	 *
	 * @param string $entity invoice or credit.
	 * @param string $id     Document id.
	 * @param int    $amount Minor units.
	 */
	private function settle( string $entity, string $id, int $amount ): void {
		$document                       = $this->store[ $entity ][ $id ];
		$left                           = $this->minor( (string) $document['outstanding_amount'] ) - $amount;
		$document['outstanding_amount'] = $this->decimal( $left );
		$document['status']             = array( 'id' => 0 === $left ? 'PAID' : 'PART_PAID' );
		$this->store[ $entity ][ $id ]  = $document;
	}

	/**
	 * Minor units of a decimal string.
	 *
	 * @param string $text Decimal text.
	 */
	private function minor( string $text ): int {
		return Money::fromDecimalString( number_format( (float) $text, Money::exponent( $this->currency ), '.', '' ), $this->currency );
	}

	/**
	 * A quantity text scaled to millionths.
	 *
	 * @param string $text Decimal text.
	 */
	private function scaled( string $text ): int {
		$parts = explode( '.', $text . '.' );
		return (int) ( $parts[0] . str_pad( substr( $parts[1], 0, 6 ), 6, '0' ) );
	}

	/**
	 * A minor-unit amount as the decimal string Sage returns.
	 *
	 * @param int $minor Minor units.
	 */
	private function decimal( int $minor ): string {
		return Money::toDecimalString( $minor, $this->currency );
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
