<?php
/**
 * The wire behaviour of "Acme Books", an invented accounting API used to prove the shared base.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors;

use Estasoft\SyncEngine\Core\Support\Money;
use Estasoft\SyncEngine\Tests\Support\ApiFakeRequest;
use Estasoft\SyncEngine\Tests\Support\ApiFakeServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

/**
 * Key in the `X-Api-Key` header, cursor pagination (two items a page), server-side `reference` search, decimal strings for money, a plan gate on
 * POST /invoices (code plan_required, 402) and duplicate references (409). Invented for the tests; it follows no real vendor.
 */
final class ReferenceBooksVendor {

	public const KEY = 'ak-live-9f8e7d6c5b4a';

	/**
	 * Contacts.
	 *
	 * @var array<int,array{id:string,name:string,email:string}>
	 */
	public array $contacts = array();

	/**
	 * Items by code.
	 *
	 * @var array<string,array{id:string,code:string,name:string}>
	 */
	public array $items = array(
		'CS-MUG-01' => array( 'id' => 'it-1', 'code' => 'CS-MUG-01', 'name' => 'Ceramic mug, sand' ),
		'CS-BOWL-02' => array( 'id' => 'it-2', 'code' => 'CS-BOWL-02', 'name' => 'Stoneware bowl set' ),
	);

	/**
	 * Invoices.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Whether the account's plan allows API writes.
	 */
	public bool $planAllowsWrites = true;

	/**
	 * Server.
	 */
	public ApiFakeServer $server;

	/**
	 * @param FakeHttpClient $http Network to attach to.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, 'api.acme-books.test' );
		$this->routes();
	}

	/**
	 * Make the next invoice creation fail.
	 *
	 * @param string $kind rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$response = match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 500, '<html>Internal error</html>' ),
			'auth'         => FakeHttpClient::status( 401, '{"message":"Invalid API key"}' ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => FakeHttpClient::status( 400, '{"weird":"shape ' . self::KEY . '"}' ),
		};
		$this->server->failNext( 'POST', '/v1/invoices', $response );
	}

	/**
	 * The routes.
	 */
	private function routes(): void {
		$auth = fn( ApiFakeRequest $request ) => self::KEY === $request->header( 'X-Api-Key' ) ? null : FakeHttpClient::status( 401, '{"message":"Invalid API key"}' );

		$this->server->route( 'GET', '/v1/company', fn( $r ) => $auth( $r ) ?? array( 'name' => 'Acme Trading LLC', 'version' => '2.4', 'plan' => 'standard', 'currency' => 'AED', 'permissions' => array( 'invoices:write' => $this->planAllowsWrites ) ) );
		$this->server->route(
			'GET',
			'/v1/contacts',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				$email = strtolower( (string) ( $r->query['email'] ?? '' ) );
				return array( 'items' => array_values( array_filter( $this->contacts, static fn( $c ) => '' === $email || strtolower( $c['email'] ) === $email ) ), 'next_cursor' => null );
			}
		);
		$this->server->route(
			'POST',
			'/v1/contacts',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				$body = $r->json();
				$id   = 'c-' . ( count( $this->contacts ) + 1 );
				$this->contacts[] = array( 'id' => $id, 'name' => (string) $body['name'], 'email' => (string) $body['email'] );
				return FakeHttpClient::json( end( $this->contacts ), 201 );
			}
		);
		$this->server->route(
			'GET',
			'/v1/items',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				$code  = (string) ( $r->query['code'] ?? '' );
				$query = strtolower( (string) ( $r->query['q'] ?? '' ) );
				$found = array_values( array_filter( $this->items, static fn( $i ) => ( '' === $code || $i['code'] === $code ) && ( '' === $query || str_contains( strtolower( $i['name'] . ' ' . $i['code'] ), $query ) ) ) );
				return array( 'items' => $found, 'next_cursor' => null );
			}
		);
		$this->server->route( 'GET', '/v1/tax-rates', fn( $r ) => $auth( $r ) ?? array( 'items' => array( array( 'code' => 'AE5', 'name' => 'VAT 5%', 'rate' => '5.0' ), array( 'code' => 'AE0', 'name' => 'Zero rated', 'rate' => '0' ) ) ) );
		$this->server->route(
			'GET',
			'/v1/invoices',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				$reference = (string) ( $r->query['reference'] ?? '' );
				$since     = (string) ( $r->query['since'] ?? '' );
				$list      = array_values( array_filter( $this->invoices, static fn( $i ) => ( '' === $reference || $i['reference'] === $reference ) && ( '' === $since || $i['created_at'] >= $since ) ) );
				list( $page, $next ) = ApiFakeServer::page( $list, 2, isset( $r->query['cursor'] ) ? (string) $r->query['cursor'] : null );
				return array( 'items' => $page, 'next_cursor' => $next );
			}
		);
		$this->server->route(
			'GET',
			'/v1/invoices/{id}',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				foreach ( $this->invoices as $invoice ) {
					if ( $invoice['id'] === $r->params['id'] ) {
						return $invoice;
					}
				}
				return null;
			}
		);
		$this->server->route(
			'POST',
			'/v1/invoices',
			function ( $r ) use ( $auth ) {
				$bad = $auth( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				if ( ! $this->planAllowsWrites ) {
					return FakeHttpClient::json( array( 'code' => 'plan_required', 'message' => 'Upgrade your plan to use the API.' ), 402 );
				}
				$body = $r->json();
				foreach ( $this->invoices as $invoice ) {
					if ( $invoice['reference'] === ( $body['reference'] ?? null ) ) {
						return FakeHttpClient::json( array( 'code' => 'duplicate_reference', 'message' => 'Reference already used' ), 409 );
					}
				}
				$currency = (string) ( $body['currency'] ?? '' );
				$exponent = Money::exponent( $currency );
				$cents    = 0;
				foreach ( (array) ( $body['lines'] ?? array() ) as $index => $line ) {
					if ( empty( $line['item_id'] ) ) {
						return FakeHttpClient::json( array( 'code' => 'validation', 'errors' => array( array( 'field' => 'lines[' . $index . '].item_id', 'message' => 'Item is required' ) ) ), 422 );
					}
					$cents += (int) $line['quantity'] * Money::fromDecimalString( (string) $line['unit_price'], $currency );
				}
				$invoice = array(
					'id'         => 'inv-' . ( count( $this->invoices ) + 1 ),
					'number'     => 'INV-' . ( 1000 + count( $this->invoices ) ),
					'reference'  => (string) $body['reference'],
					'currency'   => $currency,
					'total'      => Money::toDecimalString( $cents, $currency ),
					'refunded'   => Money::toDecimalString( 0, $currency ),
					'status'     => 'draft',
					'contact'    => array( 'id' => (string) $body['contact_id'], 'email' => $this->emailOf( (string) $body['contact_id'] ) ),
					'created_at' => '2026-11-02T12:00:00Z',
					'idempotency' => $r->header( 'Idempotency-Key' ),
				);
				unset( $exponent );
				$this->invoices[] = $invoice;
				return FakeHttpClient::json( $invoice, 201 );
			}
		);
	}

	/**
	 * Email of a contact.
	 *
	 * @param string $id Contact id.
	 */
	private function emailOf( string $id ): string {
		foreach ( $this->contacts as $contact ) {
			if ( $contact['id'] === $id ) {
				return $contact['email'];
			}
		}
		return '';
	}
}
