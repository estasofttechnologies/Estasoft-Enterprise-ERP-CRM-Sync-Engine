<?php
/**
 * An in-memory Moneybird, as far as the connector uses it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Answers the calls of the Moneybird connector with the shapes the Moneybird documentation shows: the administration id in every path, `.json`
 * suffixes, amounts as decimal text, `find_by_reference` answering 404 with `{"error": ..., "symbolic": ...}`, a draft that becomes open when sent
 * (and only then gets an invoice number), 402 for an account over its limit, 422 for a rejected document, and a credit invoice made by
 * `duplicate_creditinvoice` that copies the original and its reference. Totals are worked out in the currency's minor units with whole numbers.
 */
final class FakeMoneybirdServer {

	public const TOKEN = 'mb-test-token-4f2e9c71a05d8b36';

	public const ADMINISTRATION = '123456789012345678';

	/**
	 * The routing fake.
	 */
	public ApiFakeServer $server;

	/**
	 * Contacts by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $contacts = array();

	/**
	 * Products by identifier.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $products = array();

	/**
	 * Sales invoices (and credit invoices) by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Payments made: [invoice id, body].
	 *
	 * @var array<int,array{0:int,1:array<string,mixed>}>
	 */
	public array $payments = array();

	/**
	 * Tax rates: id => [name, percentage].
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	public array $taxRates = array(
		'500' => array( '0% vrijgesteld', '0.0' ),
		'501' => array( '21% btw', '21.0' ),
		'502' => array( '9% btw', '9.0' ),
	);

	/**
	 * When set, every invoice is made in this currency whatever the request says (an administration that does not offer the currency).
	 */
	public ?string $forcedCurrency = null;

	/**
	 * When true `find_by_reference` ignores drafts (nothing documents that it finds them), so only the list endpoint shows them.
	 */
	public bool $hideDraftsFromReference = false;

	/**
	 * When true the next update of a reference fails with a 500 (the second call of a credit invoice), then the switch turns itself off.
	 */
	public bool $failReferenceUpdate = false;

	/**
	 * When set, every invoice is stored with this date (Y-m-d), as a vendor that overwrites the date would.
	 */
	public ?string $overrideDate = null;

	/**
	 * Counter for ids and numbers.
	 */
	private int $counter = 4000;

	/**
	 * @param FakeHttpClient $http The fake network of the test kit.
	 */
	public function __construct( FakeHttpClient $http ) {
		$this->server = ApiFakeServer::on( $http, 'moneybird.com' );
		$this->routes();
	}

	/**
	 * Add a product.
	 *
	 * @param string $identifier The SKU.
	 * @param string $name       Description.
	 */
	public function addProduct( string $identifier, string $name ): int {
		$id                           = ++$this->counter;
		$this->products[ $identifier ] = array( 'id' => (string) $id, 'identifier' => $identifier, 'description' => $name, 'price' => '10.0', 'currency' => 'EUR' );
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
			'rate_limit'    => $seconds > 0 ? FakeHttpClient::tooManyRequests( $seconds ) : FakeHttpClient::status( 429 ),
			'timeout'       => FakeHttpClient::timeout(),
			'server_error'  => FakeHttpClient::json( array( 'error' => 'Internal server error' ), 500 ),
			'unknown_fault' => FakeHttpClient::status( 418, 'teapot' ),
			'auth'          => FakeHttpClient::json( array( 'error' => 'Unauthorized' ), 401 ),
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
				return FakeHttpClient::json( array( 'error' => 'Unauthorized' ), 401 );
			}
			if ( isset( $request->params['adm'] ) && self::ADMINISTRATION !== $request->params['adm'] ) {
				return FakeHttpClient::json( array( 'error' => 'Not found', 'symbolic' => array() ), 404 );
			}
			return $handler( $request );
		};
		$base = '/api/v2/{adm}';
		$s->route( 'GET', '/api/v2/administrations.json', $auth( fn() => array( array( 'id' => self::ADMINISTRATION, 'name' => 'Beispiel B.V.', 'language' => 'nl', 'currency' => 'EUR', 'country' => 'NL', 'time_zone' => 'Europe/Amsterdam' ) ) ) );
		$s->route( 'GET', $base . '/tax_rates.json', $auth( fn() => $this->listTaxRates() ) );
		$s->route( 'GET', $base . '/workflows.json', $auth( fn() => array( array( 'id' => '9', 'type' => 'InvoiceWorkflow', 'name' => 'Standard', 'default' => true ) ) ) );
		$s->route( 'GET', $base . '/contacts.json', $auth( fn( ApiFakeRequest $r ) => $this->listContacts( $r ) ) );
		$s->route( 'POST', $base . '/contacts.json', $auth( fn( ApiFakeRequest $r ) => $this->createContact( $r ) ) );
		$s->route( 'GET', $base . '/products/identifier/{identifier}.json', $auth( fn( ApiFakeRequest $r ) => $this->products[ rawurldecode( $r->params['identifier'] ) ] ?? $this->notFound( 'Product' ) ) );
		$s->route( 'GET', $base . '/products.json', $auth( fn( ApiFakeRequest $r ) => $this->listProducts( $r ) ) );
		$s->route( 'GET', $base . '/sales_invoices.json', $auth( fn( ApiFakeRequest $r ) => $this->listInvoices( $r ) ) );
		$s->route( 'POST', $base . '/sales_invoices.json', $auth( fn( ApiFakeRequest $r ) => $this->createInvoice( $r ) ) );
		$s->route( 'GET', $base . '/sales_invoices/find_by_reference/{reference}.json', $auth( fn( ApiFakeRequest $r ) => $this->byReference( rawurldecode( $r->params['reference'] ) ) ) );
		$s->route( 'GET', $base . '/sales_invoices/{id}.json', $auth( fn( ApiFakeRequest $r ) => $this->invoices[ (int) $r->params['id'] ] ?? $this->notFound( 'Sales invoice' ) ) );
		$s->route( 'PATCH', $base . '/sales_invoices/{id}.json', $auth( fn( ApiFakeRequest $r ) => $this->update( $r ) ) );
		$s->route( 'DELETE', $base . '/sales_invoices/{id}.json', $auth( fn( ApiFakeRequest $r ) => $this->remove( (int) $r->params['id'] ) ) );
		$s->route( 'PATCH', $base . '/sales_invoices/{id}/send_invoice.json', $auth( fn( ApiFakeRequest $r ) => $this->send( $r ) ) );
		$s->route( 'POST', $base . '/sales_invoices/{id}/payments.json', $auth( fn( ApiFakeRequest $r ) => $this->pay( $r ) ) );
		$s->route( 'PATCH', $base . '/sales_invoices/{id}/duplicate_creditinvoice.json', $auth( fn( ApiFakeRequest $r ) => $this->duplicate( $r ) ) );
	}

	/**
	 * Tax rates: the filter replaces the defaults, so the fake only honours what it is given.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listTaxRates(): array {
		$rows = array();
		foreach ( $this->taxRates as $id => $rate ) {
			$rows[] = array( 'id' => (string) $id, 'name' => $rate[0], 'percentage' => $rate[1], 'tax_rate_type' => 'sales_invoice', 'active' => true );
		}
		return $rows;
	}

	/**
	 * Free-text contact search over name, email and phone.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function listContacts( ApiFakeRequest $request ): array {
		$query = strtolower( (string) ( $request->query['query'] ?? '' ) );
		$rows  = array();
		foreach ( $this->contacts as $contact ) {
			if ( '' === $query || str_contains( strtolower( (string) wp_json_encode( $contact ) ), $query ) ) {
				$rows[] = $contact;
			}
		}
		$per = max( 1, min( 100, (int) ( $request->query['per_page'] ?? 50 ) ) );
		return array_slice( $rows, ( max( 1, (int) ( $request->query['page'] ?? 1 ) ) - 1 ) * $per, $per );
	}

	/**
	 * Product search.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function listProducts( ApiFakeRequest $request ): array {
		$query = strtolower( (string) ( $request->query['query'] ?? '' ) );
		return array_values( array_filter( $this->products, static fn( array $p ): bool => '' === $query || str_contains( strtolower( $p['description'] . ' ' . $p['identifier'] ), $query ) ) );
	}

	/**
	 * Create a contact: a company name, or a first and a last name, is required.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function createContact( ApiFakeRequest $request ): array|HttpResponse {
		$body = (array) ( $request->json()['contact'] ?? array() );
		if ( empty( $body['company_name'] ) && ( empty( $body['firstname'] ) && empty( $body['lastname'] ) ) ) {
			return FakeHttpClient::json( array( 'error' => array( 'base' => array( 'company_name or firstname and lastname required' ) ) ), 422 );
		}
		$id                    = ++$this->counter;
		$body['id']            = (string) $id;
		$body['customer_id']   = (string) ( 1000 + count( $this->contacts ) + 1 );
		$this->contacts[ $id ] = $body;
		return FakeHttpClient::json( $body, 201 );
	}

	/**
	 * Create a draft invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return HttpResponse
	 */
	private function createInvoice( ApiFakeRequest $request ): HttpResponse {
		$body = (array) ( $request->json()['sales_invoice'] ?? array() );
		if ( empty( $body['contact_id'] ) || ! isset( $this->contacts[ (int) $body['contact_id'] ] ) || empty( $body['details_attributes'] ) ) {
			return FakeHttpClient::json( array( 'error' => array( 'contact_id' => array( 'is invalid' ) ) ), 422 );
		}
		foreach ( $body['details_attributes'] as $detail ) {
			if ( ! isset( $this->taxRates[ (string) ( $detail['tax_rate_id'] ?? '' ) ] ) || '' === (string) ( $detail['description'] ?? '' ) ) {
				return FakeHttpClient::json( array( 'error' => array( 'details' => array( 'tax rate or description is invalid' ) ) ), 422 );
			}
		}
		$currency = strtoupper( $this->forcedCurrency ?? (string) ( $body['currency'] ?? 'EUR' ) );
		$exponent = Money::exponent( $currency );
		$groups   = array();
		$incl     = ! empty( $body['prices_are_incl_tax'] );
		foreach ( $body['details_attributes'] as $detail ) {
			$unit  = $this->minor( (string) $detail['price'], $exponent );
			$qty4  = (int) round( (float) $detail['amount'] * 10000 );
			$cents = intdiv( $unit * $qty4 + 5000, 10000 );
			$rate  = (int) round( (float) $this->taxRates[ (string) $detail['tax_rate_id'] ][1] * 100 );
			$groups[ $rate ] = ( $groups[ $rate ] ?? 0 ) + $cents;
		}
		$net = 0;
		$tax = 0;
		foreach ( $groups as $rate => $sum ) {
			if ( $incl ) {
				$groupNet = intdiv( $sum * 10000 + intdiv( 10000 + $rate, 2 ), 10000 + $rate );
				$groupTax = $sum - $groupNet;
			} else {
				$groupNet = $sum;
				$groupTax = intdiv( $sum * $rate + 5000, 10000 );
			}
			$net += $groupNet;
			$tax += $groupTax;
		}
		$id                    = ++$this->counter;
		$invoice               = array(
			'id'                       => (string) $id,
			'contact_id'               => (string) $body['contact_id'],
			'reference'                => (string) ( $body['reference'] ?? '' ),
			'invoice_id'               => null,
			'state'                    => 'draft',
			'invoice_date'             => $this->overrideDate ?? (string) ( $body['invoice_date'] ?? '' ),
			'currency'                 => $currency,
			'prices_are_incl_tax'      => $incl,
			'total_price_excl_tax'     => $this->format( $net, $exponent ),
			'total_price_incl_tax'     => $this->format( $net + $tax, $exponent ),
			'total_paid'               => '0.0',
			'total_unpaid'             => $this->format( $net + $tax, $exponent ),
			'original_sales_invoice_id' => null,
			'url'                      => 'https://moneybird.com/' . self::ADMINISTRATION . '/sales_invoices/' . $id,
			'details'                  => $body['details_attributes'],
		);
		$this->invoices[ $id ] = $invoice;
		return FakeHttpClient::json( $invoice, 201 );
	}

	/**
	 * The invoice list with `filter=key:value,key:value` (reference and state are honoured) and page / per_page.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function listInvoices( ApiFakeRequest $request ): array {
		$terms = array();
		foreach ( explode( ',', (string) ( $request->query['filter'] ?? '' ) ) as $term ) {
			$parts = explode( ':', $term, 2 );
			if ( 2 === count( $parts ) ) {
				$terms[ $parts[0] ] = $parts[1];
			}
		}
		$rows = array();
		foreach ( $this->invoices as $invoice ) {
			if ( ( isset( $terms['reference'] ) && $invoice['reference'] !== $terms['reference'] ) || ( isset( $terms['state'] ) && $invoice['state'] !== $terms['state'] ) ) {
				continue;
			}
			$rows[] = $invoice;
		}
		$per = max( 1, min( 100, (int) ( $request->query['per_page'] ?? 50 ) ) );
		return array_slice( $rows, ( max( 1, (int) ( $request->query['page'] ?? 1 ) ) - 1 ) * $per, $per );
	}

	/**
	 * `find_by_reference`: the first invoice with the reference, else 404.
	 *
	 * @param string $reference Reference.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function byReference( string $reference ): array|HttpResponse {
		foreach ( $this->invoices as $invoice ) {
			if ( $invoice['reference'] === $reference && ! ( $this->hideDraftsFromReference && 'draft' === $invoice['state'] ) ) {
				return $invoice;
			}
		}
		return FakeHttpClient::json( array( 'error' => 'Sales invoice with reference: ' . $reference . ' was not found', 'symbolic' => array( 'reference' => 'not_found' ) ), 404 );
	}

	/**
	 * Update the reference of an invoice.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function update( ApiFakeRequest $request ): array|HttpResponse {
		$id = (int) $request->params['id'];
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->notFound( 'Sales invoice' );
		}
		if ( $this->failReferenceUpdate ) {
			$this->failReferenceUpdate = false;
			return FakeHttpClient::json( array( 'error' => 'Internal server error' ), 500 );
		}
		$changes = (array) ( $request->json()['sales_invoice'] ?? array() );
		if ( isset( $changes['reference'] ) ) {
			$this->invoices[ $id ]['reference'] = (string) $changes['reference'];
		}
		return $this->invoices[ $id ];
	}

	/**
	 * Delete a draft.
	 *
	 * @param int $id Invoice id.
	 * @return HttpResponse
	 */
	private function remove( int $id ): HttpResponse {
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->notFound( 'Sales invoice' );
		}
		unset( $this->invoices[ $id ] );
		return FakeHttpClient::status( 204 );
	}

	/**
	 * Send a draft: it becomes open and gets its number.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function send( ApiFakeRequest $request ): array|HttpResponse {
		$id = (int) $request->params['id'];
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->notFound( 'Sales invoice' );
		}
		if ( ! isset( $request->json()['sales_invoice_sending']['delivery_method'] ) ) {
			return FakeHttpClient::json( array( 'error' => 'delivery_method is required' ), 422 );
		}
		$this->invoices[ $id ]['state']      = 'open';
		$this->invoices[ $id ]['invoice_id'] = '2026-' . sprintf( '%04d', ++$this->counter - 4000 );
		return $this->invoices[ $id ];
	}

	/**
	 * Register a payment.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function pay( ApiFakeRequest $request ): array|HttpResponse {
		$id      = (int) $request->params['id'];
		$payment = (array) ( $request->json()['payment'] ?? array() );
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->notFound( 'Sales invoice' );
		}
		if ( 'draft' === $this->invoices[ $id ]['state'] || empty( $payment['financial_account_id'] ) || empty( $payment['price'] ) ) {
			return FakeHttpClient::json( array( 'error' => 'payment refused' ), 422 );
		}
		$this->payments[]                       = array( $id, $payment );
		$this->invoices[ $id ]['total_paid']    = (string) $payment['price'];
		$this->invoices[ $id ]['total_unpaid']  = '0.0';
		$this->invoices[ $id ]['state']         = 'paid';
		return FakeHttpClient::json( $this->invoices[ $id ], 201 );
	}

	/**
	 * `duplicate_creditinvoice`: a draft copy that points at the original and keeps its reference.
	 *
	 * @param ApiFakeRequest $request Request.
	 * @return array<string,mixed>|HttpResponse
	 */
	private function duplicate( ApiFakeRequest $request ): array|HttpResponse {
		$id = (int) $request->params['id'];
		if ( ! isset( $this->invoices[ $id ] ) ) {
			return $this->notFound( 'Sales invoice' );
		}
		$copy                              = $this->invoices[ $id ];
		$new                               = ++$this->counter;
		$copy['id']                        = (string) $new;
		$copy['state']                     = 'draft';
		$copy['invoice_id']                = null;
		$copy['original_sales_invoice_id'] = (string) $id;
		$this->invoices[ $new ]            = $copy;
		return $copy;
	}

	/**
	 * A 404 in the documented shape.
	 *
	 * @param string $what Name of the thing.
	 */
	private function notFound( string $what ): HttpResponse {
		return FakeHttpClient::json( array( 'error' => $what . ' not found', 'symbolic' => array() ), 404 );
	}

	/**
	 * Decimal text to minor units.
	 *
	 * @param string $text     Decimal text.
	 * @param int    $exponent Decimals of the currency.
	 */
	private function minor( string $text, int $exponent ): int {
		return (int) round( (float) $text * ( 10 ** $exponent ) );
	}

	/**
	 * Minor units to decimal text.
	 *
	 * @param int $minor    Amount.
	 * @param int $exponent Decimals.
	 */
	private function format( int $minor, int $exponent ): string {
		return number_format( $minor / ( 10 ** $exponent ), $exponent, '.', '' );
	}
}
