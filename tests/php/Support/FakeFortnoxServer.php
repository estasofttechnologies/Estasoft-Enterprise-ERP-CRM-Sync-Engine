<?php
/**
 * A small in-memory Fortnox for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Fortnox connector makes, in the shapes of the Fortnox developer pages and the SDK reference they were checked against
 * (docs/connectors/fortnox.md, section 1): wrapped records (`{"Invoice":{…}}`), lists with `MetaInformation`, `ErrorInformation`, the token
 * endpoint with a Basic client login and a refresh token that works once. It models on purpose: list filters that match by "contains" (so a
 * connector that does not compare exactly finds the wrong record), a total computed from the rows, a payment that needs a booked invoice, and
 * a balance that falls when a payment is recorded.
 */
final class FakeFortnoxServer {

	/**
	 * Client id and secret the token endpoint accepts.
	 */
	public const CLIENT_ID = 'fx-client-id';

	/**
	 * Client secret.
	 */
	public const CLIENT_SECRET = 'fx-client-secret-0123456789';

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	public int $pageSize = 100;

	/**
	 * Multiplier applied to every computed total (a VAT setup that differs).
	 *
	 * @var float
	 */
	public float $totalFactor = 1.0;

	/**
	 * Rounding of the company to whole units: '' none, 'in_total' (Total holds the rounded amount) or 'beside_total' (Total is the exact amount
	 * and RoundOff names the rounding). The list of documents never carries RoundOff.
	 *
	 * @var string
	 */
	public string $rounding = '';

	/**
	 * Customers by number.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $customers = array();

	/**
	 * Articles by number.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $articles = array();

	/**
	 * Invoices by document number.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Orders by document number.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $orders = array();

	/**
	 * Payments recorded.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $payments = array();

	/**
	 * Stock balances of the warehouse API.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $stockBalances = array();

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	public array $accessTokens = array();

	/**
	 * Valid refresh tokens (each works once).
	 *
	 * @var array<string,bool>
	 */
	public array $refreshTokens = array();

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
	 * Bodies of the creates, by path, for assertions.
	 *
	 * @var array<int,array{path:string,body:array<string,mixed>}>
	 */
	public array $posted = array();

	/**
	 * Counter for numbers.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Give the server a valid access token and refresh token.
	 *
	 * @param string $access  Access token.
	 * @param string $refresh Refresh token.
	 */
	public function allowTokens( string $access, string $refresh ): void {
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
	}

	/**
	 * Add an article.
	 *
	 * @param string     $number   Article number.
	 * @param float|null $stock    Quantity in stock, or null for a service.
	 * @param float      $reserved Reserved quantity.
	 */
	public function addArticle( string $number, ?float $stock = null, float $reserved = 0.0 ): void {
		$this->articles[ $number ] = array( 'ArticleNumber' => $number, 'Description' => 'Article ' . $number ) + ( null === $stock ? array() : array( 'QuantityInStock' => $stock, 'ReservedQuantity' => $reserved ) );
	}

	/**
	 * Add a customer.
	 *
	 * @param string $email Email address.
	 * @param string $name  Name.
	 * @return string Customer number.
	 */
	public function addCustomer( string $email, string $name = 'Customer' ): string {
		$number                     = (string) ( 1 + count( $this->customers ) );
		$this->customers[ $number ] = array( 'CustomerNumber' => $number, 'Name' => $name, 'Email' => $email );
		return $number;
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
		$path   = (string) ( $parts['path'] ?? '' );
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = $request['method'];
		$token  = 'apps.fortnox.se' === ( $parts['host'] ?? '' ) && '/oauth-v1/token' === $path;
		if ( ! $token ) {
			$this->log[] = $method . ' ' . $path . ( isset( $parts['query'] ) ? '?' . rawurldecode( $parts['query'] ) : '' );
		}
		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path . '?' . ( $parts['query'] ?? '' ) ) && $failure[3] > 0 ) {
				--$this->failures[ $index ][3];
				return $failure[2];
			}
		}
		if ( $token ) {
			return $this->token( $request );
		}
		$auth = $this->header( $request['headers'], 'authorization' );
		if ( 1 !== preg_match( '/^Bearer (.+)$/', $auth, $m ) || empty( $this->accessTokens[ $m[1] ] ) ) {
			return $this->error( 403, 2000310, 'Ogiltig inloggning' );
		}
		$body = json_decode( (string) $request['body'], true );
		$body = is_array( $body ) ? $body : array();
		if ( '/api/warehouse/status-v1/stockbalance' === $path ) {
			return $this->stock( $query );
		}
		if ( 1 !== preg_match( '#^/3/([a-z]+)(?:/(\d+))?(?:/([a-z]+))?$#', $path, $m ) ) {
			return $this->error( 404, 2000423, 'Kan inte hitta resursen' );
		}
		$resource = $m[1];
		$id       = $m[2] ?? '';
		$action   = $m[3] ?? '';
		return match ( $resource ) {
			'companyinformation' => $this->json( array( 'CompanyInformation' => array( 'CompanyName' => 'Test AB', 'DatabaseNumber' => 1234567 ) ) ),
			'customers'          => $this->customersRoute( $method, $body, $query ),
			'articles'           => $this->listing( array_values( $this->articles ), 'Articles', $query, array( 'articlenumber' => 'ArticleNumber', 'description' => 'Description' ) ),
			'invoices'           => $this->documents( $method, $this->invoices, 'Invoice', 'Invoices', $id, $action, $body, $query, true ),
			'orders'             => $this->documents( $method, $this->orders, 'Order', 'Orders', $id, $action, $body, $query, false ),
			'invoicepayments'    => $this->payment( $body ),
			default              => $this->error( 404, 2000423, 'Kan inte hitta resursen' ),
		};
	}

	/**
	 * The token endpoint.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	private function token( array $request ): HttpResponse {
		$basic = 'Basic ' . base64_encode( self::CLIENT_ID . ':' . self::CLIENT_SECRET );
		if ( $this->header( $request['headers'], 'authorization' ) !== $basic ) {
			return new HttpResponse( 401, '{"error":"invalid_client"}', array( 'content-type' => 'application/json' ) );
		}
		$form = array();
		parse_str( (string) $request['body'], $form );
		if ( 'refresh_token' === ( $form['grant_type'] ?? '' ) ) {
			$old = (string) ( $form['refresh_token'] ?? '' );
			if ( empty( $this->refreshTokens[ $old ] ) ) {
				return new HttpResponse( 400, '{"error":"invalid_grant"}', array( 'content-type' => 'application/json' ) );
			}
			unset( $this->refreshTokens[ $old ] );
		} elseif ( 'authorization_code' !== ( $form['grant_type'] ?? '' ) || 'good-code' !== ( $form['code'] ?? '' ) ) {
			return new HttpResponse( 400, '{"error":"invalid_grant"}', array( 'content-type' => 'application/json' ) );
		}
		++$this->counter;
		$access  = 'fx-access-' . $this->counter;
		$refresh = 'fx-refresh-' . $this->counter;
		$this->allowTokens( $access, $refresh );
		return $this->json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'scope' => 'customer', 'expires_in' => 3600, 'token_type' => 'bearer' ) );
	}

	/**
	 * Customers: list and create.
	 *
	 * @param string              $method Method.
	 * @param array<string,mixed> $body   Body.
	 * @param array<string,mixed> $query  Query.
	 */
	private function customersRoute( string $method, array $body, array $query ): HttpResponse {
		if ( 'POST' === $method ) {
			$record                              = (array) ( $body['Customer'] ?? array() );
			$this->posted[]                      = array( 'path' => 'customers', 'body' => $record );
			$number                              = (string) ( 1 + count( $this->customers ) );
			$this->customers[ $number ]          = array( 'CustomerNumber' => $number ) + $record;
			return $this->json( array( 'Customer' => $this->customers[ $number ] ), 201 );
		}
		return $this->listing( array_values( $this->customers ), 'Customers', $query, array( 'email' => 'Email', 'name' => 'Name' ) );
	}

	/**
	 * Invoices and orders.
	 *
	 * @param string                           $method  Method.
	 * @param array<int,array<string,mixed>>   $store   Documents by number (changed in place).
	 * @param string                           $one     Name of one record.
	 * @param string                           $many    Name of the list.
	 * @param string                           $id      Document number from the path.
	 * @param string                           $action  Action from the path.
	 * @param array<string,mixed>              $body    Body.
	 * @param array<string,mixed>              $query   Query.
	 * @param bool                             $invoice True for invoices.
	 */
	private function documents( string $method, array &$store, string $one, string $many, string $id, string $action, array $body, array $query, bool $invoice ): HttpResponse {
		if ( 'POST' === $method ) {
			$record = (array) ( $body[ $one ] ?? array() );
			$rows   = (array) ( $record[ $invoice ? 'InvoiceRows' : 'OrderRows' ] ?? array() );
			if ( ! isset( $this->customers[ (string) ( $record['CustomerNumber'] ?? '' ) ] ) ) {
				return $this->error( 400, 2000204, 'Kunde inte hitta kund' );
			}
			foreach ( $rows as $row ) {
				if ( isset( $row['ArticleNumber'] ) && ! isset( $this->articles[ (string) $row['ArticleNumber'] ] ) ) {
					return $this->error( 400, 2000428, 'Kunde inte hitta artikel' );
				}
			}
			$this->posted[] = array( 'path' => $invoice ? 'invoices' : 'orders', 'body' => $record );
			$number         = ++$this->counter + 100;
			$total          = $this->total( $rows, true === ( $record['VATIncluded'] ?? false ), (string) ( $record['Currency'] ?? 'SEK' ) ) * $this->totalFactor;
			$doc            = $record + array( 'DocumentNumber' => $number );
			$doc['Currency'] = (string) ( $record['Currency'] ?? 'SEK' );
			$doc['Total']    = $total;
			if ( '' !== $this->rounding ) {
				$whole           = round( $total );
				$doc['RoundOff'] = round( $whole - $total, 2 );
				$doc['Total']    = 'in_total' === $this->rounding ? $whole : $total;
				$total           = $doc['Total'];
			}
			$doc['Cancelled'] = false;
			if ( $invoice ) {
				$doc['Booked']  = false;
				$doc['Balance'] = $total;
			}
			$store[ $number ] = $doc;
			return $this->json( array( $one => $doc ), 201 );
		}
		if ( '' !== $id ) {
			$key = (int) $id;
			if ( ! isset( $store[ $key ] ) ) {
				return $this->error( 404, 2000423, 'Kan inte hitta posten' );
			}
			if ( 'PUT' === $method && 'bookkeep' === $action ) {
				$store[ $key ]['Booked'] = true;
				return $this->json( array( $one => $store[ $key ] ) );
			}
			if ( 'PUT' === $method ) {
				foreach ( (array) ( $body[ $one ] ?? array() ) as $field => $value ) {
					$store[ $key ][ $field ] = $value;
				}
				return $this->json( array( $one => $store[ $key ] ) );
			}
			return $this->json( array( $one => $store[ $key ] ) );
		}
		$rows = array_values( $store );
		if ( isset( $query['fromdate'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row[ $invoice ? 'InvoiceDate' : 'OrderDate' ] ?? '' ) >= (string) $query['fromdate'] ) );
		}
		$rows = array_map(
			static function ( array $row ): array {
				unset( $row['RoundOff'] );
				return $row;
			},
			$rows
		);
		return $this->listing( $rows, $many, $query, array( 'externalinvoicereference1' => 'ExternalInvoiceReference1' ) );
	}

	/**
	 * The total of rows including VAT.
	 *
	 * @param array<int,mixed> $rows      Rows.
	 * @param bool             $inclusive Whether prices include VAT.
	 * @param string           $currency  Currency.
	 */
	private function total( array $rows, bool $inclusive, string $currency ): float {
		$decimals = in_array( $currency, array( 'KWD', 'BHD', 'OMR' ), true ) ? 3 : 2;
		$total    = 0.0;
		foreach ( $rows as $row ) {
			$row    = (array) $row;
			$qty    = (float) ( $row['DeliveredQuantity'] ?? $row['OrderedQuantity'] ?? 1 );
			$net    = round( $qty * (float) ( $row['Price'] ?? 0 ), $decimals );
			$total += $inclusive ? $net : round( $net * ( 1 + (float) ( $row['VAT'] ?? 0 ) / 100 ), $decimals );
		}
		return round( $total, $decimals );
	}

	/**
	 * Record a payment: the invoice must be booked.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function payment( array $body ): HttpResponse {
		$payment = (array) ( $body['InvoicePayment'] ?? array() );
		$number  = (int) ( $payment['InvoiceNumber'] ?? 0 );
		if ( ! isset( $this->invoices[ $number ] ) ) {
			return $this->error( 400, 2000204, 'Kunde inte hitta fakturan' );
		}
		if ( true !== $this->invoices[ $number ]['Booked'] ) {
			return $this->error( 400, 2001392, 'Fakturan maste vara bokford' );
		}
		$this->payments[]                    = $payment;
		$this->invoices[ $number ]['Balance'] = round( (float) $this->invoices[ $number ]['Balance'] - (float) ( $payment['Amount'] ?? 0 ), 2 );
		return $this->json( array( 'InvoicePayment' => $payment ), 201 );
	}

	/**
	 * The warehouse stock balance.
	 *
	 * @param array<string,mixed> $query Query.
	 */
	private function stock( array $query ): HttpResponse {
		$ids    = array_map( 'trim', explode( ',', (string) ( $query['itemIds'] ?? '' ) ) );
		$points = '' === (string) ( $query['stockPointCodes'] ?? '' ) ? array() : array_map( 'trim', explode( ',', (string) $query['stockPointCodes'] ) );
		$rows   = array_values( array_filter( $this->stockBalances, static fn( array $row ): bool => in_array( $row['itemId'], $ids, true ) && ( array() === $points || in_array( $row['stockPointCode'], $points, true ) ) ) );
		return $this->json( $rows );
	}

	/**
	 * A page of a list after the filters, with MetaInformation.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param string                         $key     List name.
	 * @param array<string,mixed>            $query   Query.
	 * @param array<string,string>           $filters Query parameter to field, matched by "contains".
	 */
	private function listing( array $rows, string $key, array $query, array $filters ): HttpResponse {
		foreach ( $filters as $param => $field ) {
			if ( isset( $query[ $param ] ) && '' !== (string) $query[ $param ] ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( strtolower( (string) ( $row[ $field ] ?? '' ) ), strtolower( (string) $query[ $param ] ) ) ) );
			}
		}
		$limit = min( (int) ( $query['limit'] ?? 100 ), $this->pageSize );
		$page  = max( 1, (int) ( $query['page'] ?? 1 ) );
		$slice = array_slice( $rows, ( $page - 1 ) * $limit, $limit );
		$pages = max( 1, (int) ceil( count( $rows ) / $limit ) );
		return $this->json( array( 'MetaInformation' => array( '@TotalResources' => count( $rows ), '@TotalPages' => $pages, '@CurrentPage' => $page ), $key => $slice ) );
	}

	/**
	 * A JSON answer.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status.
	 */
	private function json( mixed $data, int $status = 200 ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ), array( 'content-type' => 'application/json' ) );
	}

	/**
	 * A Fortnox error answer.
	 *
	 * @param int    $status  Status.
	 * @param int    $code    Fortnox error code.
	 * @param string $message Message.
	 */
	public function error( int $status, int $code, string $message ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( array( 'ErrorInformation' => array( 'error' => 1, 'message' => $message, 'code' => $code ) ), JSON_UNESCAPED_UNICODE ), array( 'content-type' => 'application/json' ) );
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
}
