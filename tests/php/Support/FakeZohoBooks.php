<?php
/**
 * A small in-memory Zoho Books (with its accounts server) for the connector tests.
 *
 * Shapes follow what the Zoho Books API summaries and Zoho's Deluge page show: answers {code: 0, message, invoice: {...}}, lists with
 * page_context.has_more_page, errors {code, message}, organization_id on every call, token endpoint on the accounts host. The invoice total
 * is net lines plus line tax minus the discount; no other Zoho rule is modelled.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Support\Money;

final class FakeZohoBooks extends FakeSalesServer {

	public const ACCESS  = 'access-token-synthetic-1001';
	public const REFRESH = 'refresh-token-synthetic-1001';
	public const FRESH   = 'access-token-synthetic-1002';
	public const SECRET  = 'client-secret-synthetic-1001';
	public const ORG     = '7000001';

	/**
	 * Rows by collection: id => row.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $rows = array(
		'contacts'         => array(),
		'items'            => array(),
		'invoices'         => array(),
		'creditnotes'      => array(),
		'customerpayments' => array(),
	);

	/**
	 * Currencies set up in the organisation: code => id.
	 *
	 * @var array<string,string>
	 */
	public array $currencies = array( 'AED' => 'c-aed', 'EUR' => 'c-eur', 'KWD' => 'c-kwd' );

	/**
	 * Tax codes: id => array(name, percentage).
	 *
	 * @var array<string,array{0:string,1:float}>
	 */
	public array $taxes = array( 't-vat5' => array( 'VAT 5%', 5.0 ) );

	/**
	 * Minor units added to every invoice total, to force a mismatch.
	 */
	public int $totalDrift = 0;

	/**
	 * Modules whose reads are refused for lack of scope.
	 *
	 * @var array<int,string>
	 */
	public array $denied = array();

	/**
	 * Access tokens the API accepts.
	 *
	 * @var array<int,string>
	 */
	public array $validTokens = array( self::ACCESS, self::FRESH );

	/**
	 * Token requests seen.
	 */
	public int $tokenCalls = 0;

	/**
	 * When true the token endpoint refuses every refresh.
	 */
	public bool $refreshRevoked = false;

	private int $counter = 6000;

	/**
	 * Add an item the way a merchant would.
	 *
	 * @param string $sku  SKU.
	 * @param string $name Name.
	 * @param float  $rate Price.
	 */
	public function addItem( string $sku, string $name, float $rate = 10.0 ): string {
		return $this->insert( 'items', 'item_id', array( 'name' => $name, 'sku' => $sku, 'rate' => $rate ) );
	}

	/**
	 * Add a contact the way a merchant would.
	 *
	 * @param string $name     Name.
	 * @param string $email    Email.
	 * @param string $currency Currency code.
	 */
	public function addContact( string $name, string $email, string $currency = 'EUR' ): string {
		return $this->insert( 'contacts', 'contact_id', array( 'contact_name' => $name, 'email' => $email, 'currency_code' => $currency, 'currency_id' => $this->currencies[ $currency ] ?? '' ) );
	}

	/**
	 * Add an invoice as an earlier pass or the merchant would.
	 *
	 * @param string $reference Reference.
	 * @param float  $total     Total.
	 * @param string $currency  Currency code.
	 */
	public function addInvoice( string $reference, float $total, string $currency = 'EUR' ): string {
		return $this->insert( 'invoices', 'invoice_id', array( 'reference_number' => $reference, 'total' => $total, 'balance' => $total, 'status' => 'sent', 'currency_code' => $currency, 'date' => '2026-11-01', 'invoice_number' => 'INV-' . $this->counter ) );
	}

	/**
	 * A refused sign-in is not a one-off: the tokens stop working and the refresh is refused too.
	 *
	 * @param string $kind        Kind.
	 * @param int    $seconds     Seconds.
	 * @param int    $afterWrites Writes that succeed first.
	 */
	public function failNextWrite( string $kind, int $seconds = 0, int $afterWrites = 0 ): void {
		if ( 'auth' === $kind ) {
			$this->validTokens    = array();
			$this->refreshRevoked = true;
			return;
		}
		parent::failNextWrite( $kind, $seconds, $afterWrites );
	}

	/**
	 * @param string               $method  Method.
	 * @param string               $path    Path.
	 * @param array<string,mixed>  $query   Query.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	protected function route( string $method, string $path, array $query, array $body, array $headers ): HttpResponse {
		if ( '/oauth/v2/token' === $path ) {
			++$this->tokenCalls;
			return ! $this->refreshRevoked && self::REFRESH === ( $body['refresh_token'] ?? '' ) && self::SECRET === ( $body['client_secret'] ?? '' )
				? $this->json( array( 'access_token' => self::FRESH, 'api_domain' => 'https://www.zohoapis.com', 'token_type' => 'Bearer', 'expires_in' => 3600 ) )
				: $this->json( array( 'error' => 'invalid_code' ), 400 );
		}
		$token = str_starts_with( $headers['authorization'] ?? '', 'Zoho-oauthtoken ' ) ? substr( $headers['authorization'], 16 ) : '';
		if ( ! in_array( $token, $this->validTokens, true ) ) {
			return $this->json( array( 'code' => 57, 'message' => 'You are not authorized to perform this operation' ), 401 );
		}
		if ( '/books/v3/organizations' === $path ) {
			return $this->json( array( 'code' => 0, 'message' => 'success', 'organizations' => array( array( 'organization_id' => self::ORG, 'name' => 'Example Trading LLC', 'currency_code' => 'AED', 'is_default_org' => true ) ) ) );
		}
		if ( ! str_starts_with( $path, '/books/v3/' ) ) {
			return $this->json( array( 'code' => 5, 'message' => 'Invalid URL Passed' ), 404 );
		}
		if ( self::ORG !== ( $query['organization_id'] ?? '' ) ) {
			return $this->json( array( 'code' => 6041, 'message' => 'Invalid value passed for organization_id' ), 400 );
		}
		$tail = substr( $path, strlen( '/books/v3/' ) );
		if ( 1 === preg_match( '~^(\w+)$~', $tail, $m ) && in_array( $m[1], $this->denied, true ) ) {
			return $this->json( array( 'code' => 57, 'message' => 'You are not authorized: scope ZohoBooks.' . $m[1] . '.READ is missing' ), 401 );
		}
		return $this->dispatch( $method, $tail, $query, $body );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => $this->json( array( 'code' => 44, 'message' => 'You have made too many requests continuously. Please try after some time.' ), 429, array( 'retry-after' => (string) $seconds ) ),
			'server_error' => $this->json( array( 'code' => 1000, 'message' => 'internal error' ), 500 ),
			default        => $this->json( array( 'weird' => true ), 418 ),
		};
	}

	/**
	 * @param string              $method Method.
	 * @param string              $tail   Path below /books/v3.
	 * @param array<string,mixed> $query  Query.
	 * @param array<string,mixed> $body   Body.
	 */
	private function dispatch( string $method, string $tail, array $query, array $body ): HttpResponse {
		if ( 'settings/currencies' === $tail ) {
			return $this->ok( array( 'currencies' => array_map( fn( string $code, string $id ): array => array( 'currency_id' => $id, 'currency_code' => $code ), array_keys( $this->currencies ), array_values( $this->currencies ) ) ) );
		}
		if ( 'settings/taxes' === $tail ) {
			return $this->ok( array( 'taxes' => array_map( static fn( string $id, array $tax ): array => array( 'tax_id' => $id, 'tax_name' => $tax[0], 'tax_percentage' => $tax[1] ), array_keys( $this->taxes ), array_values( $this->taxes ) ) ) );
		}
		if ( 1 === preg_match( '~^invoices/(\w+)/status/sent$~', $tail, $m ) ) {
			return $this->setStatus( 'invoices', $m[1], 'sent', 'The invoice has been marked as sent.' );
		}
		if ( 1 === preg_match( '~^creditnotes/(\w+)/status/open$~', $tail, $m ) ) {
			return $this->setStatus( 'creditnotes', $m[1], 'open', 'The credit note status has been changed to open.' );
		}
		if ( 1 === preg_match( '~^(contacts|items|invoices|creditnotes|customerpayments)/(\w+)$~', $tail, $m ) && 'GET' === $method ) {
			$row = $this->rows[ $m[1] ][ $m[2] ] ?? null;
			return null === $row ? $this->json( array( 'code' => -1, 'message' => 'Resource does not exist.' ), 404 ) : $this->ok( array( rtrim( $m[1], 's' ) => $row ) );
		}
		if ( 1 === preg_match( '~^(contacts|items|invoices|creditnotes|customerpayments)$~', $tail, $m ) ) {
			return 'GET' === $method ? $this->listing( $m[1], $query ) : $this->create( $m[1], $body );
		}
		return $this->json( array( 'code' => 5, 'message' => 'Invalid URL Passed' ), 404 );
	}

	/**
	 * @param string              $collection Collection.
	 * @param string              $key        Id field.
	 * @param array<string,mixed> $data       Fields.
	 */
	private function insert( string $collection, string $key, array $data ): string {
		$id = (string) ++$this->counter;
		$this->rows[ $collection ][ $id ] = $data + array( $key => $id, 'created_time' => gmdate( 'Y-m-d\TH:i:sO', 1_800_000_000 + $this->counter ), 'invoice_url' => 'https://books.example.test/portal/' . $id );
		return $id;
	}

	/**
	 * @param array<string,mixed> $data Extra keys of the answer.
	 */
	private function ok( array $data ): HttpResponse {
		return $this->json( array( 'code' => 0, 'message' => 'success' ) + $data );
	}

	/**
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $query      Query.
	 */
	private function listing( string $collection, array $query ): HttpResponse {
		$rows = array_values( $this->rows[ $collection ] );
		foreach ( array( 'email', 'reference_number' ) as $exact ) {
			if ( isset( $query[ $exact ] ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 0 === strcasecmp( (string) ( $row[ $exact ] ?? '' ), (string) $query[ $exact ] ) ) );
			}
		}
		if ( isset( $query['reference_number_startswith'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => str_starts_with( (string) ( $row['reference_number'] ?? '' ), (string) $query['reference_number_startswith'] ) ) );
		}
		if ( isset( $query['date_start'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['date'] ?? '9999' ) >= (string) $query['date_start'] ) );
		}
		if ( isset( $query['search_text'] ) ) {
			$needle = strtolower( (string) $query['search_text'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( strtolower( (string) wp_json_encode( array( $row['name'] ?? '', $row['sku'] ?? '' ) ) ), $needle ) ) );
		}
		$per   = (int) ( $query['per_page'] ?? 200 );
		$page  = (int) ( $query['page'] ?? 1 );
		$slice = array_slice( $rows, ( $page - 1 ) * $per, $per );
		return $this->ok( array( $collection => $slice, 'page_context' => array( 'page' => $page, 'per_page' => $per, 'has_more_page' => $page * $per < count( $rows ) ) ) );
	}

	/**
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $body       Body.
	 */
	private function create( string $collection, array $body ): HttpResponse {
		if ( 'contacts' === $collection ) {
			if ( empty( $body['contact_name'] ) ) {
				return $this->json( array( 'code' => 1, 'message' => 'Invalid value passed for contact_name' ), 400 );
			}
			$email = (string) ( $body['contact_persons'][0]['email'] ?? '' );
			$code  = (string) array_search( (string) ( $body['currency_id'] ?? '' ), $this->currencies, true );
			$id    = $this->insert( 'contacts', 'contact_id', array( 'contact_name' => $body['contact_name'], 'company_name' => $body['company_name'] ?? '', 'email' => $email, 'currency_id' => $body['currency_id'] ?? '', 'currency_code' => '' !== $code ? $code : 'AED', 'billing_address' => $body['billing_address'] ?? array() ) );
			return $this->json( array( 'code' => 0, 'message' => 'The contact has been added.', 'contact' => $this->rows['contacts'][ $id ] ), 201 );
		}
		if ( 'invoices' === $collection || 'creditnotes' === $collection ) {
			return $this->document( $collection, $body );
		}
		if ( 'customerpayments' === $collection ) {
			$invoice = $this->rows['invoices'][ (string) ( $body['invoices'][0]['invoice_id'] ?? '' ) ] ?? null;
			if ( null === $invoice || empty( $body['account_id'] ) ) {
				return $this->json( array( 'code' => 1, 'message' => 'Invalid value passed for invoices' ), 400 );
			}
			$this->rows['invoices'][ $invoice['invoice_id'] ]['status']  = 'paid';
			$this->rows['invoices'][ $invoice['invoice_id'] ]['balance'] = 0;
			$id = $this->insert( 'customerpayments', 'payment_id', array( 'reference_number' => $body['reference_number'] ?? '', 'amount' => $body['amount'] ?? 0 ) );
			return $this->json( array( 'code' => 0, 'message' => 'The payment has been recorded.', 'payment' => $this->rows['customerpayments'][ $id ] ), 201 );
		}
		return $this->json( array( 'code' => 5, 'message' => 'Invalid URL Passed' ), 404 );
	}

	/**
	 * @param string              $collection invoices or creditnotes.
	 * @param array<string,mixed> $body       Body.
	 */
	private function document( string $collection, array $body ): HttpResponse {
		$contact = $this->rows['contacts'][ (string) ( $body['customer_id'] ?? '' ) ] ?? null;
		if ( null === $contact ) {
			return $this->json( array( 'code' => 1, 'message' => 'Invalid value passed for customer_id' ), 400 );
		}
		if ( empty( $body['line_items'] ) ) {
			return $this->json( array( 'code' => 1, 'message' => 'Please add at least one line item for line_items' ), 400 );
		}
		$currencyId = (string) ( $body['currency_id'] ?? $contact['currency_id'] ?? '' );
		$code       = (string) array_search( $currencyId, $this->currencies, true );
		$code       = '' !== $code ? $code : (string) $contact['currency_code'];
		if ( $code !== $contact['currency_code'] ) {
			return $this->json( array( 'code' => 1, 'message' => 'The currency of the customer and the document must be the same for currency_id' ), 400 );
		}
		$inclusive = ! empty( $body['is_inclusive_tax'] );
		$net       = 0;
		$tax       = 0;
		foreach ( $body['line_items'] as $line ) {
			$amount = (int) round( (float) Money::fromDecimalString( (string) json_encode( $line['rate'] ), $code ) * (float) $line['quantity'] );
			$pct    = isset( $line['tax_id'] ) ? ( $this->taxes[ $line['tax_id'] ][1] ?? 0.0 ) : 0.0;
			if ( isset( $line['tax_id'] ) && ! isset( $this->taxes[ $line['tax_id'] ] ) ) {
				return $this->json( array( 'code' => 1, 'message' => 'Invalid value passed for tax_id' ), 400 );
			}
			if ( isset( $line['item_id'] ) && ! isset( $this->rows['items'][ (string) $line['item_id'] ] ) ) {
				return $this->json( array( 'code' => 1, 'message' => 'Invalid value passed for item_id' ), 400 );
			}
			if ( $inclusive ) {
				$lineTax = (int) round( $amount - $amount / ( 1 + $pct / 100 ) );
				$net    += $amount - $lineTax;
				$tax    += $lineTax;
			} else {
				$net += $amount;
				$tax += (int) round( $amount * $pct / 100 );
			}
		}
		$discount = isset( $body['discount'] ) ? Money::fromDecimalString( (string) $body['discount'], $code ) : 0;
		$total    = $net + $tax - $discount + $this->totalDrift;
		$key      = 'invoices' === $collection ? 'invoice_id' : 'creditnote_id';
		$id       = $this->insert(
			$collection,
			$key,
			array(
				'reference_number' => $body['reference_number'] ?? '',
				'customer_id'      => $contact['contact_id'],
				'email'            => $contact['email'],
				'currency_id'      => $currencyId,
				'currency_code'    => $code,
				'status'           => 'draft',
				'total'            => (float) Money::toDecimalString( $total, $code ),
				'balance'          => (float) Money::toDecimalString( $total, $code ),
				'date'             => $body['date'] ?? '',
				'line_items'       => $body['line_items'],
				'notes'            => $body['notes'] ?? '',
				'invoice_number'   => 'INV-' . ( $this->counter + 1 ),
				'creditnote_number' => 'CN-' . ( $this->counter + 1 ),
			)
		);
		$name = 'invoices' === $collection ? 'invoice' : 'creditnote';
		return $this->json( array( 'code' => 0, 'message' => 'The ' . $name . ' has been created.', $name => $this->rows[ $collection ][ $id ] ), 201 );
	}

	/**
	 * @param string $collection Collection.
	 * @param string $id         Id.
	 * @param string $status     New status.
	 * @param string $message    Message.
	 */
	private function setStatus( string $collection, string $id, string $status, string $message ): HttpResponse {
		if ( ! isset( $this->rows[ $collection ][ $id ] ) ) {
			return $this->json( array( 'code' => -1, 'message' => 'Resource does not exist.' ), 404 );
		}
		$this->rows[ $collection ][ $id ]['status'] = $status;
		return $this->json( array( 'code' => 0, 'message' => $message ) );
	}
}
