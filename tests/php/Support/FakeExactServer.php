<?php
/**
 * A small in-memory Exact Online for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Exact Online connector makes, in the shapes of the Exact REST reference (see docs/connectors/exact-online.md,
 * section 1): the `d` envelope with `results` and `__next`, the error body `{"error":{"message":{"value":…}}}`, the rate-limit headers,
 * the token endpoint with a refresh token that works once, and a total computed from the lines and the VAT codes. It models on purpose:
 * paging (a small page size), a filter that compares text without regard to case, an invoice that gets its total from Exact, and
 * a credit note whose total is negative.
 */
final class FakeExactServer {

	/**
	 * Host the server answers for.
	 */
	public const HOST = 'start.exactonline.nl';

	/**
	 * Division the user is in.
	 */
	public const DIVISION = '4242';

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	public int $pageSize = 60;

	/**
	 * Currency of the administration.
	 *
	 * @var string
	 */
	public string $currency = 'EUR';

	/**
	 * When true, the server records documents in this currency whatever the request says.
	 *
	 * @var string|null
	 */
	public ?string $forceCurrency = null;

	/**
	 * When set, every total is multiplied by this factor (a wrong VAT setup).
	 *
	 * @var float
	 */
	public float $totalFactor = 1.0;

	/**
	 * Whether the delete of an invoice is allowed.
	 *
	 * @var bool
	 */
	public bool $allowDelete = true;

	/**
	 * Whether a posted Type 8021 is kept (false: Exact ignores it and makes an invoice).
	 *
	 * @var bool
	 */
	public bool $keepCreditType = true;

	/**
	 * Accounts by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $accounts = array();

	/**
	 * Items by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * VAT codes.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $vatCodes = array();

	/**
	 * Journals.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $journals = array();

	/**
	 * Sales invoices by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Sales orders by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $orders = array();

	/**
	 * Item-warehouse rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $itemWarehouses = array();

	/**
	 * Divisions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $divisions = array();

	/**
	 * The client secret the token endpoint accepts.
	 *
	 * @var string
	 */
	public string $clientSecret = 'exact-client-secret-0123456789';

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
	 * Every non-token request as "METHOD path?query", for assertions.
	 *
	 * @var array<int,string>
	 */
	public array $log = array();

	/**
	 * Counter for ids and numbers.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Start with a division, a user, a sales journal, a return journal and two VAT codes.
	 */
	public function __construct() {
		$this->divisions = array( array( 'Code' => self::DIVISION, 'Description' => 'Test BV', 'Currency' => 'EUR', 'Country' => 'NL' ) );
		$this->journals  = array(
			array( 'Code' => '70', 'Description' => 'Sales', 'Type' => 20, 'IsBlocked' => false ),
			array( 'Code' => '71', 'Description' => 'Returns', 'Type' => 21, 'IsBlocked' => false ),
		);
		$this->vatCodes  = array(
			array( 'Code' => '0', 'Description' => 'VAT 0%', 'Percentage' => 0.0, 'Type' => 'B', 'VATTransactionType' => 'B', 'IsBlocked' => false ),
			array( 'Code' => '2', 'Description' => 'VAT high 21%', 'Percentage' => 0.21, 'Type' => 'E', 'VATTransactionType' => 'B', 'IsBlocked' => false ),
			array( 'Code' => '4', 'Description' => 'VAT low 9%', 'Percentage' => 0.09, 'Type' => 'E', 'VATTransactionType' => 'S', 'IsBlocked' => false ),
			array( 'Code' => '9', 'Description' => 'Purchase only', 'Percentage' => 0.21, 'Type' => 'E', 'VATTransactionType' => 'P', 'IsBlocked' => false ),
		);
	}

	/**
	 * Give the server a valid refresh token and access token pair.
	 *
	 * @param string $access  Access token.
	 * @param string $refresh Refresh token.
	 */
	public function allowTokens( string $access, string $refresh ): void {
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
	}

	/**
	 * Add a sellable item.
	 *
	 * @param string $code    Item code.
	 * @param string $vatCode Default VAT code.
	 * @param bool   $stock   Whether it is a stock item.
	 * @return string The item's GUID.
	 */
	public function addItem( string $code, string $vatCode = '2', bool $stock = true ): string {
		$id                = $this->guid();
		$this->items[ $id ] = array( 'ID' => $id, 'Code' => $code, 'Description' => 'Item ' . $code, 'IsSalesItem' => true, 'IsStockItem' => $stock, 'SalesVatCode' => $vatCode );
		return $id;
	}

	/**
	 * Add an existing customer account.
	 *
	 * @param string $email Email address.
	 * @param string $name  Name.
	 * @return string The account's GUID.
	 */
	public function addAccount( string $email, string $name = 'Customer' ): string {
		$id                   = $this->guid();
		$this->accounts[ $id ] = array( 'ID' => $id, 'Code' => str_pad( (string) ( 1000 + count( $this->accounts ) ), 18, ' ', STR_PAD_LEFT ), 'Name' => $name, 'Email' => $email, 'IsSales' => true, 'Status' => 'C', 'Blocked' => false );
		return $id;
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
		$path   = rawurldecode( (string) ( $parts['path'] ?? '' ) );
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = $request['method'];
		$isToken = '/api/oauth2/token' === $path;
		if ( ! $isToken ) {
			$this->log[] = $method . ' ' . $path . ( isset( $parts['query'] ) ? '?' . rawurldecode( $parts['query'] ) : '' );
		}
		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path . '?' . ( $parts['query'] ?? '' ) ) && $failure[3] > 0 ) {
				--$this->failures[ $index ][3];
				return $failure[2];
			}
		}
		if ( $isToken ) {
			return $this->token( (string) $request['body'] );
		}
		$auth = $this->header( $request['headers'], 'authorization' );
		if ( 1 !== preg_match( '/^Bearer (.+)$/', $auth, $m ) || empty( $this->accessTokens[ $m[1] ] ) ) {
			return $this->error( 401, 'Authentication Required' );
		}
		if ( ! str_starts_with( $path, '/api/v1/' ) ) {
			return $this->error( 404, 'Not found' );
		}
		$rest = substr( $path, strlen( '/api/v1/' ) );
		if ( 'current/Me' === $rest ) {
			return $this->ok( array( 'd' => array( 'results' => array( array( 'CurrentDivision' => (int) self::DIVISION, 'FullName' => 'Test User', 'UserName' => 'test', 'DivisionCustomerName' => 'Test BV' ) ) ) ) );
		}
		if ( 1 !== preg_match( '#^(\d+)/(.+)$#', $rest, $m ) ) {
			return $this->error( 404, 'Not found' );
		}
		if ( self::DIVISION !== $m[1] ) {
			return $this->error( 403, 'User division is not within division scope' );
		}
		return $this->resource( $method, $m[2], $query, json_decode( (string) $request['body'], true ), $request['url'] );
	}

	/**
	 * The token endpoint.
	 *
	 * @param string $body Form body.
	 */
	private function token( string $body ): HttpResponse {
		$form = array();
		parse_str( $body, $form );
		if ( $this->clientSecret !== ( $form['client_secret'] ?? '' ) ) {
			return new HttpResponse( 401, '{"error":"invalid_client"}', array( 'content-type' => 'application/json' ) );
		}
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
		$access  = 'access-' . $this->counter;
		$refresh = 'refresh-' . $this->counter;
		$this->allowTokens( $access, $refresh );
		return new HttpResponse( 200, (string) json_encode( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'bearer', 'expires_in' => '600' ) ), array( 'content-type' => 'application/json' ) );
	}

	/**
	 * Route a resource below the division.
	 *
	 * @param string              $method Method.
	 * @param string              $res    Resource, for example crm/Accounts or salesinvoice/SalesInvoices(guid'…').
	 * @param array<string,mixed> $query  Query.
	 * @param mixed               $body   Decoded body.
	 * @param string              $url    Full URL, used to build the next-page link.
	 */
	private function resource( string $method, string $res, array $query, mixed $body, string $url ): HttpResponse {
		$key = null;
		if ( 1 === preg_match( "#^([A-Za-z/]+)\\(guid'([0-9a-f-]+)'\\)$#", $res, $m ) ) {
			$res = $m[1];
			$key = $m[2];
		}
		$body = is_array( $body ) ? $body : array();
		switch ( $res ) {
			case 'system/Divisions':
			case 'hrm/Divisions':
				return $this->listing( $this->divisions, $query, $url );
			case 'crm/Accounts':
				if ( 'POST' === $method ) {
					$id                     = $this->guid();
					$this->accounts[ $id ] = array( 'ID' => $id, 'Code' => str_pad( (string) ( 1000 + count( $this->accounts ) ), 18, ' ', STR_PAD_LEFT ) ) + $body + array( 'Blocked' => false );
					return $this->ok( array( 'd' => $this->accounts[ $id ] ), 201 );
				}
				return $this->listing( array_values( $this->accounts ), $query, $url );
			case 'logistics/Items':
				return $this->listing( array_values( $this->items ), $query, $url );
			case 'vat/VATCodes':
				return $this->listing( $this->vatCodes, $query, $url );
			case 'financial/Journals':
				return $this->listing( $this->journals, $query, $url );
			case 'inventory/ItemWarehouses':
				return $this->listing( $this->itemWarehouses, $query, $url );
			case 'salesinvoice/SalesInvoices':
				return $this->documents( $method, $this->invoices, 'InvoiceID', $key, $body, $query, $url, true );
			case 'salesorder/SalesOrders':
				return $this->documents( $method, $this->orders, 'OrderID', $key, $body, $query, $url, false );
			case 'salesinvoice/PrintedSalesInvoices':
				$id = (string) ( $body['InvoiceID'] ?? '' );
				if ( ! isset( $this->invoices[ $id ] ) ) {
					return $this->error( 400, 'Invoice not found' );
				}
				$this->invoices[ $id ]['Status']      = 50;
				$this->invoices[ $id ]['InvoiceDate'] = (string) ( $body['InvoiceDate'] ?? $this->invoices[ $id ]['InvoiceDate'] );
				$this->invoices[ $id ]['InvoiceNumber'] = ++$this->counter + 900000;
				return $this->ok( array( 'd' => array( 'InvoiceID' => $id ) ), 201 );
		}
		return $this->error( 404, 'Resource not found: ' . $res );
	}

	/**
	 * Invoices and orders: list, create, change, delete.
	 *
	 * @param string                           $method  Method.
	 * @param array<string,array<string,mixed>> $store   Documents by id (changed in place).
	 * @param string                           $idField Key field.
	 * @param string|null                      $key     Key from the path.
	 * @param array<string,mixed>              $body    Body.
	 * @param array<string,mixed>              $query   Query.
	 * @param string                           $url     URL.
	 * @param bool                             $invoice True for invoices.
	 */
	private function documents( string $method, array &$store, string $idField, ?string $key, array $body, array $query, string $url, bool $invoice ): HttpResponse {
		if ( 'POST' === $method ) {
			$id     = $this->guid();
			$lines  = (array) ( $body[ $invoice ? 'SalesInvoiceLines' : 'SalesOrderLines' ] ?? array() );
			$credit = $invoice && 8021 === (int) ( $body['Type'] ?? 0 ) && $this->keepCreditType;
			$total  = $this->total( $lines, $this->decimalsFor( (string) ( $body['Currency'] ?? $this->currency ) ) ) * $this->totalFactor * ( $credit ? -1 : 1 );
			$number = ++$this->counter + 5000;
			$doc    = array(
				$idField                                    => $id,
				$invoice ? 'InvoiceNumber' : 'OrderNumber' => $invoice ? null : $number,
				'Description'                               => (string) ( $body['Description'] ?? '' ),
				'Status'                                    => $invoice ? 20 : 12,
				'YourRef'                                   => (string) ( $body['YourRef'] ?? '' ),
				'Currency'                                  => $this->forceCurrency ?? (string) ( $body['Currency'] ?? $this->currency ),
				'AmountFC'                                  => round( $total, $this->decimalsFor( (string) ( $body['Currency'] ?? $this->currency ) ) ),
				'OrderedBy'                                 => (string) ( $body['OrderedBy'] ?? '' ),
				'Lines'                                     => $lines,
				'Remarks'                                   => (string) ( $body['Remarks'] ?? '' ),
				'Journal'                                   => (string) ( $body['Journal'] ?? '' ),
			);
			if ( $invoice ) {
				$doc['Type']        = $credit ? 8021 : 8020;
				$doc['InvoiceDate'] = (string) ( $body['InvoiceDate'] ?? '' );
			} else {
				$doc['OrderDate'] = (string) ( $body['OrderDate'] ?? '' );
			}
			$store[ $id ] = $doc;
			return $this->ok( array( 'd' => $doc ), 201 );
		}
		if ( null !== $key ) {
			if ( ! isset( $store[ $key ] ) ) {
				return $this->error( 404, 'Not found' );
			}
			if ( 'PUT' === $method ) {
				foreach ( $body as $field => $value ) {
					$store[ $key ][ $field ] = $value;
				}
				return new HttpResponse( 204, '', array() );
			}
			if ( 'DELETE' === $method ) {
				if ( ! $this->allowDelete || 20 !== (int) $store[ $key ]['Status'] ) {
					return $this->error( 403, 'Delete is not allowed' );
				}
				unset( $store[ $key ] );
				return new HttpResponse( 204, '', array() );
			}
		}
		return $this->listing( array_values( $store ), $query, $url );
	}

	/**
	 * The total of lines including VAT.
	 *
	 * @param array<int,mixed> $lines    Lines.
	 * @param int              $decimals Decimal places of the currency.
	 */
	private function total( array $lines, int $decimals ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$line = (array) $line;
			$code = (string) ( $line['VATCode'] ?? '' );
			if ( '' === $code && isset( $this->items[ (string) ( $line['Item'] ?? '' ) ] ) ) {
				$code = (string) $this->items[ (string) $line['Item'] ]['SalesVatCode'];
			}
			$rate = 0.0;
			foreach ( $this->vatCodes as $vat ) {
				if ( trim( (string) $vat['Code'] ) === trim( $code ) ) {
					$rate = (float) $vat['Percentage'];
				}
			}
			$net    = (float) ( $line['Quantity'] ?? 1 ) * (float) ( $line['UnitPrice'] ?? 0 );
			$total += round( $net, $decimals ) + round( $net * $rate, $decimals );
		}
		return $total;
	}

	/**
	 * A page of rows after the filter, with a next link when more remain.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 * @param array<string,mixed>            $query Query.
	 * @param string                         $url   URL.
	 */
	private function listing( array $rows, array $query, string $url ): HttpResponse {
		$filter = (string) ( $query['$filter'] ?? '' );
		if ( '' !== $filter ) {
			$rows = array_values( array_filter( $rows, fn( array $row ): bool => $this->matches( $row, $filter ) ) );
		}
		$offset = (int) ( $query['$skiptoken'] ?? 0 );
		$page   = array_slice( $rows, $offset, $this->pageSize );
		$select = (string) ( $query['$select'] ?? '' );
		if ( '' !== $select ) {
			$fields = array_map( 'trim', explode( ',', $select ) );
			$page   = array_map( static fn( array $row ): array => array_intersect_key( $row, array_flip( $fields ) ), $page );
		}
		$d = array( 'results' => $page );
		if ( $offset + $this->pageSize < count( $rows ) ) {
			$parts           = parse_url( $url );
			$next            = $query;
			$next['$skiptoken'] = $offset + $this->pageSize;
			$d['__next']     = 'https://' . $parts['host'] . $parts['path'] . '?' . http_build_query( $next );
		}
		return $this->ok( array( 'd' => $d ) );
	}

	/**
	 * Evaluate a filter on a row: conditions joined by "and" or by "or".
	 *
	 * @param array<string,mixed> $row    Row.
	 * @param string              $filter Filter.
	 */
	private function matches( array $row, string $filter ): bool {
		$or = str_contains( $filter, ' or ' );
		foreach ( explode( $or ? ' or ' : ' and ', $filter ) as $condition ) {
			$ok = $this->condition( $row, trim( $condition ) );
			if ( $or && $ok ) {
				return true;
			}
			if ( ! $or && ! $ok ) {
				return false;
			}
		}
		return ! $or;
	}

	/**
	 * Evaluate one condition.
	 *
	 * @param array<string,mixed> $row       Row.
	 * @param string              $condition Condition.
	 */
	private function condition( array $row, string $condition ): bool {
		if ( 1 === preg_match( "/^startswith\\((\\w+),'(.*)'\\) eq true$/", $condition, $m ) ) {
			return str_starts_with( strtolower( trim( (string) ( $row[ $m[1] ] ?? '' ) ) ), strtolower( $m[2] ) );
		}
		if ( 1 === preg_match( "/^substringof\\('(.*)', ?(\\w+)\\) eq true$/", $condition, $m ) ) {
			return str_contains( strtolower( (string) ( $row[ $m[2] ] ?? '' ) ), strtolower( $m[1] ) );
		}
		if ( 1 === preg_match( "/^trim\\((\\w+)\\) eq '(.*)'$/", $condition, $m ) ) {
			return strtolower( trim( (string) ( $row[ $m[1] ] ?? '' ) ) ) === strtolower( str_replace( "''", "'", $m[2] ) );
		}
		if ( 1 !== preg_match( '/^(\w+) (eq|ne|ge|gt|le|lt) (.+)$/', $condition, $m ) ) {
			return true;
		}
		$actual = $row[ $m[1] ] ?? null;
		$raw    = $m[3];
		if ( 1 === preg_match( "/^(?:guid|datetime)'(.*)'$/", $raw, $v ) ) {
			$wanted = $v[1];
		} elseif ( 1 === preg_match( "/^'(.*)'$/", $raw, $v ) ) {
			$wanted = str_replace( "''", "'", $v[1] );
		} elseif ( 'true' === $raw || 'false' === $raw ) {
			return ( 'eq' === $m[2] ) === ( ( true === $actual ) === ( 'true' === $raw ) );
		} else {
			$wanted = $raw;
		}
		if ( str_starts_with( $raw, 'datetime' ) ) {
			$left  = substr( (string) $actual, 0, 10 );
			$right = substr( $wanted, 0, 10 );
			return match ( $m[2] ) { 'ge' => $left >= $right, 'gt' => $left > $right, 'le' => $left <= $right, 'lt' => $left < $right, 'ne' => $left !== $right, default => $left === $right };
		}
		if ( is_numeric( $wanted ) && is_numeric( $actual ) ) {
			$cmp = (float) $actual <=> (float) $wanted;
		} else {
			$cmp = strcasecmp( (string) $actual, (string) $wanted );
		}
		return match ( $m[2] ) { 'ge' => $cmp >= 0, 'gt' => $cmp > 0, 'le' => $cmp <= 0, 'lt' => $cmp < 0, 'ne' => 0 !== $cmp, default => 0 === $cmp };
	}

	/**
	 * Decimal places Exact keeps for a currency.
	 *
	 * @param string $currency ISO code.
	 */
	private function decimalsFor( string $currency ): int {
		return match ( strtoupper( $currency ) ) {
			'KWD', 'BHD', 'OMR' => 3,
			'JPY' => 0,
			default => 2,
		};
	}

	/**
	 * A 200 or 201 answer with rate-limit headers.
	 *
	 * @param array<string,mixed> $data   Body.
	 * @param int                 $status Status.
	 */
	private function ok( array $data, int $status = 200 ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ), array( 'content-type' => 'application/json', 'x-ratelimit-limit' => '5000', 'x-ratelimit-remaining' => '4990', 'x-ratelimit-minutely-limit' => '60', 'x-ratelimit-minutely-remaining' => '50' ) );
	}

	/**
	 * An Exact error answer.
	 *
	 * @param int    $status  Status.
	 * @param string $message Message.
	 */
	public function error( int $status, string $message ): HttpResponse {
		return new HttpResponse( $status, (string) json_encode( array( 'error' => array( 'code' => '', 'message' => array( 'lang' => '', 'value' => $message ) ) ) ), array( 'content-type' => 'application/json' ) );
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

	/**
	 * A new GUID.
	 */
	private function guid(): string {
		++$this->counter;
		return sprintf( '%08x-0000-4000-8000-%012x', $this->counter, $this->counter * 7919 );
	}
}
