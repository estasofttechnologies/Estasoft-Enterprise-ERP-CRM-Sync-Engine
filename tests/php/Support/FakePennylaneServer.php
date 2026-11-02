<?php
/**
 * An in-memory Pennylane, as far as the connector uses it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls of the Pennylane connector with the shapes the Pennylane documentation shows: cursor paging (`items`, `has_more`, `next_cursor`),
 * the JSON `filter` parameter with `field`, `operator`, `value`, amounts as text (a number is a 400), a draft that gets its number only when it is
 * finalised, 422 for an invalid VAT code with `details`, 403 with "Missing scope: ..." and 429 with `retry-after`. The finalise call is the inferred
 * `PUT /customer_invoices/{id}/finalize`. Totals are worked out in cents with whole numbers.
 */
final class FakePennylaneServer {

	public const TOKEN = 'pl-test-token-b71c4e0d92f35a68';

	/**
	 * The routing fake.
	 */
	public ApiFakeServer $server;

	/**
	 * Customers by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $customers = array();

	/**
	 * Products by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $products = array();

	/**
	 * Customer invoices by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Scopes the token has.
	 *
	 * @var array<int,string>
	 */
	public array $scopes = array( 'customers:all', 'customer_invoices:all', 'products:readonly' );

	/**
	 * When true every request answers 401 "Token expired".
	 */
	public bool $expired = false;

	/**
	 * When true the lists ignore the `filter` parameter and answer every row (a server that does not honour a filter).
	 */
	public bool $ignoreFilters = false;

	/**
	 * When set, every invoice is stored with this date (Y-m-d), as a vendor that overwrites the date would.
	 */
	public ?string $overrideDate = null;

	/**
	 * Whether the finalise route exists.
	 */
	public bool $canFinalize = true;

	/**
	 * VAT codes the fake accepts: code => rate in basis points.
	 *
	 * @var array<string,int>
	 */
	public array $vat = array(
		'FR_200' => 2000,
		'FR_100' => 1000,
		'FR_55'  => 550,
		'exempt' => 0,
	);

	/**
	 * Counter for ids.
	 */
	private int $counter = 8000;

	/**
	 * Last invoice number given.
	 */
	private int $numbered = 0;

	/**
	 * @param FakeHttpClient $http The fake network of the test kit.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, 'app.pennylane.com' );
		$this->routes();
	}

	/**
	 * Add a product.
	 *
	 * @param string $reference The SKU.
	 * @param string $label     Label.
	 */
	public function addProduct( string $reference, string $label ): int {
		$id                    = ++$this->counter;
		$this->products[ $id ] = array( 'id' => $id, 'reference' => $reference, 'label' => $label );
		return $id;
	}

	/**
	 * Make the next write request fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$response = match ( $kind ) {
			'rate_limit'    => $seconds > 0 ? FakeHttpClient::json( array( 'message' => 'Rate limit exceeded. Please retry in ' . $seconds . ' seconds.' ), 429, array( 'retry-after' => (string) $seconds ) ) : FakeHttpClient::status( 429 ),
			'timeout'       => FakeHttpClient::timeout(),
			'server_error'  => FakeHttpClient::json( array( 'error' => 'internal_server_error', 'message' => 'Unexpected failure' ), 500 ),
			'unknown_fault' => FakeHttpClient::status( 418, 'teapot' ),
			'auth'          => FakeHttpClient::json( array( 'error' => 'unauthorized', 'message' => 'Invalid token' ), 401 ),
			default         => FakeHttpClient::certificateError(),
		};
		$this->server->failNext( 'POST', '', $response );
	}

	/**
	 * Define the routes.
	 */
	private function routes(): void {
		$s    = $this->server;
		$auth = fn( \Closure $handler ): \Closure => function ( ApiFakeRequest $request ) use ( $handler ) {
			if ( 'Bearer ' . self::TOKEN !== (string) $request->header( 'Authorization' ) ) {
				return FakeHttpClient::json( array( 'error' => 'unauthorized', 'message' => 'Invalid token' ), 401 );
			}
			if ( $this->expired ) {
				return FakeHttpClient::json( array( 'error' => 'unauthorized', 'message' => 'Token expired' ), 401 );
			}
			return $handler( $request );
		};
		$scoped = fn( string $scope, \Closure $handler ): \Closure => function ( ApiFakeRequest $request ) use ( $scope, $handler ) {
			if ( ! in_array( $scope, $this->scopes, true ) ) {
				return FakeHttpClient::json( array( 'error' => 'forbidden', 'message' => 'Missing scope: ' . $scope ), 403 );
			}
			return $handler( $request );
		};
		$v    = '/api/external/v2';
		$s->route( 'GET', $v . '/me', $auth( fn() => array( 'scopes' => $this->scopes, 'company' => array( 'name' => 'Exemple SAS' ) ) ) );
		$s->route( 'GET', $v . '/customers', $auth( fn( ApiFakeRequest $r ) => $this->listOf( $r, $this->customers ) ) );
		$s->route( 'GET', $v . '/customers/{id}', $auth( fn( ApiFakeRequest $r ) => $this->customers[ (int) $r->params['id'] ] ?? null ) );
		$s->route( 'POST', $v . '/company_customers', $auth( $scoped( 'customers:all', fn( ApiFakeRequest $r ) => $this->createCustomer( $r, 'company' ) ) ) );
		$s->route( 'POST', $v . '/individual_customers', $auth( $scoped( 'customers:all', fn( ApiFakeRequest $r ) => $this->createCustomer( $r, 'individual' ) ) ) );
		$s->route( 'GET', $v . '/products', $auth( fn( ApiFakeRequest $r ) => $this->listOf( $r, $this->products ) ) );
		$s->route( 'GET', $v . '/customer_invoices', $auth( fn( ApiFakeRequest $r ) => $this->listOf( $r, $this->invoices ) ) );
		$s->route( 'POST', $v . '/customer_invoices', $auth( $scoped( 'customer_invoices:all', fn( ApiFakeRequest $r ) => $this->createInvoice( $r ) ) ) );
		$s->route( 'GET', $v . '/customer_invoices/{id}', $auth( fn( ApiFakeRequest $r ) => $this->invoices[ (int) $r->params['id'] ] ?? null ) );
		$s->route( 'PUT', $v . '/customer_invoices/{id}/finalize', $auth( fn( ApiFakeRequest $r ) => $this->finalize( (int) $r->params['id'] ) ) );
	}

	/**
	 * A cursor-paged, filtered list.
	 *
	 * @param ApiFakeRequest                 $request Request.
	 * @param array<int,array<string,mixed>> $store   Rows.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function listOf( ApiFakeRequest $request, array $store ): array|HttpResponse {
		$limit = (int) ( $request->query['limit'] ?? 20 );
		if ( $limit < 1 || $limit > 100 ) {
			return FakeHttpClient::json( array( 'error' => 'bad_request', 'message' => 'limit must be between 1 and 100' ), 400 );
		}
		$filters = array();
		if ( isset( $request->query['filter'] ) ) {
			$filters = json_decode( (string) $request->query['filter'], true );
			if ( ! is_array( $filters ) ) {
				return FakeHttpClient::json( array( 'error' => 'bad_request', 'message' => 'filter must be a JSON array' ), 400 );
			}
		}
		$rows = array();
		foreach ( $store as $row ) {
			if ( $this->ignoreFilters || $this->matches( $row, $filters ) ) {
				$rows[] = $row;
			}
		}
		list( $items, $next ) = ApiFakeServer::page( $rows, $limit, isset( $request->query['cursor'] ) ? (string) $request->query['cursor'] : null );
		return array(
			'items'       => $items,
			'has_more'    => null !== $next,
			'next_cursor' => $next,
		);
	}

	/**
	 * Whether a row passes every filter.
	 *
	 * @param array<string,mixed>            $row     Row.
	 * @param array<int,array<string,mixed>> $filters Filters.
	 */
	private function matches( array $row, array $filters ): bool {
		foreach ( $filters as $filter ) {
			$field = (string) ( $filter['field'] ?? '' );
			$value = $filter['value'] ?? null;
			$have  = $row[ $field ] ?? null;
			$ok    = match ( (string) ( $filter['operator'] ?? '' ) ) {
				'eq'   => 'credit_note' === $field ? (bool) $have === (bool) $value : (string) $have === (string) $value,
				'gteq' => (string) $have >= (string) $value,
				'lteq' => (string) $have <= (string) $value,
				default => false,
			};
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Create a company or an individual customer.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @param string         $type    company or individual.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function createCustomer( ApiFakeRequest $request, string $type ): array|HttpResponse {
		$body = $request->json();
		if ( ( 'company' === $type && empty( $body['name'] ) ) || ( 'individual' === $type && ( empty( $body['first_name'] ) || empty( $body['last_name'] ) ) ) ) {
			return FakeHttpClient::json( array( 'error' => 'unprocessable_entity', 'message' => 'Name is required', 'details' => array( 'field' => 'name', 'issue' => 'blank' ) ), 422 );
		}
		$id                       = ++$this->counter;
		$body['id']               = $id;
		$body['customer_type']    = $type;
		$this->customers[ $id ]   = $body;
		return FakeHttpClient::json( $body, 201 );
	}

	/**
	 * Create a draft invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return HttpResponse
	 */
	private function createInvoice( ApiFakeRequest $request ): HttpResponse {
		$body = $request->json();
		if ( ! isset( $this->customers[ (int) ( $body['customer_id'] ?? 0 ) ] ) || empty( $body['invoice_lines'] ) || empty( $body['date'] ) || empty( $body['deadline'] ) ) {
			return FakeHttpClient::json( array( 'error' => 'bad_request', 'message' => 'customer_id, date, deadline and invoice_lines are required' ), 400 );
		}
		$groups = array();
		foreach ( $body['invoice_lines'] as $line ) {
			if ( ! is_string( $line['raw_currency_unit_price'] ?? null ) ) {
				return FakeHttpClient::json( array( 'error' => 'bad_request', 'message' => 'Amounts must be sent as strings' ), 400 );
			}
			if ( ! isset( $this->vat[ (string) ( $line['vat_rate'] ?? '' ) ] ) ) {
				return FakeHttpClient::json( array( 'error' => 'unprocessable_entity', 'message' => 'Invalid VAT code', 'details' => array( 'field' => 'vat_rate', 'issue' => 'invalid' ) ), 422 );
			}
			$cents = (int) round( (float) $line['raw_currency_unit_price'] * (float) $line['quantity'] * 100 );
			$rate  = $this->vat[ (string) $line['vat_rate'] ];
			$groups[ $rate ] = ( $groups[ $rate ] ?? 0 ) + $cents;
		}
		$net = 0;
		$tax = 0;
		foreach ( $groups as $rate => $sum ) {
			$net += $sum;
			$tax += intdiv( $sum * $rate + 5000, 10000 );
		}
		$id      = ++$this->counter;
		$invoice = array(
			'id'                         => $id,
			'invoice_number'             => null,
			'status'                     => ! empty( $body['draft'] ) ? 'draft' : 'upcoming',
			'draft'                      => ! empty( $body['draft'] ),
			'credit_note'                => false,
			'external_reference'         => (string) ( $body['external_reference'] ?? '' ),
			'customer'                   => array( 'id' => (int) $body['customer_id'] ),
			'date'                       => $this->overrideDate ?? (string) $body['date'],
			'deadline'                   => (string) $body['deadline'],
			'currency'                   => 'EUR',
			'currency_amount_before_tax' => number_format( $net / 100, 2, '.', '' ),
			'currency_tax'               => number_format( $tax / 100, 2, '.', '' ),
			'currency_amount'            => number_format( ( $net + $tax ) / 100, 2, '.', '' ),
			'invoice_lines'              => $body['invoice_lines'],
		);
		if ( empty( $body['draft'] ) ) {
			$invoice['invoice_number'] = $this->nextNumber();
		}
		$this->invoices[ $id ] = $invoice;
		return FakeHttpClient::json( $invoice, 201 );
	}

	/**
	 * Finalise a draft: it gets its number.
	 *
	 * @param int $id Invoice id.
	 * @return array<string,mixed>|HttpResponse|null
	 */
	private function finalize( int $id ): array|HttpResponse|null {
		if ( ! $this->canFinalize || ! isset( $this->invoices[ $id ] ) ) {
			return null;
		}
		$this->invoices[ $id ]['draft']          = false;
		$this->invoices[ $id ]['status']         = 'upcoming';
		$this->invoices[ $id ]['invoice_number'] = $this->nextNumber();
		return $this->invoices[ $id ];
	}

	/**
	 * The next invoice number: numbers are given when an invoice is finalised, in that order.
	 */
	private function nextNumber(): string {
		return 'F-2026-' . sprintf( '%04d', ++$this->numbered );
	}
}
