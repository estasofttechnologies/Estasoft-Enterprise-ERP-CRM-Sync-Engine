<?php
/**
 * A small in-memory Oracle Fusion Cloud ERP pod (Receivables and item REST APIs, identity domain) for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Behaviour follows the pages listed in docs/connectors/oracle-fusion.md: the identity domain token endpoint (client credentials, Basic client
 * sign-in, the Fusion scope), Basic sign-in of a Fusion user, `fscmRestApi/resources/11.13.18.05/<resource>`, the header
 * `REST-Framework-Version` (the `q` syntax of version 2 is refused without it), `items`/`hasMore`/`limit`/`offset` pages (25 rows when no
 * limit is given, at most 499), `q` with `and`, `or`, `=`, `!=`, `>=`, `like`, the content type `application/vnd.oracle.adf.resourceitem+json` on
 * bodies, and `o:errorDetails` errors. It models on purpose what once hid bugs elsewhere: tax added by the system, a pod that does not
 * return a total, an invoice left Incomplete, a role that is missing, a token that is replaced.
 *
 * Which attributes exist (TotalAmount, receivablesCreditMemos and its lines) is the connector's assumption, not a fact read from Oracle; see
 * the "Not verified" section of the documentation file.
 */
final class FakeOracleFusionServer {

	/**
	 * The client the connector is built on.
	 */
	public FakeHttpClient $http;

	/**
	 * Pod address as the merchant types it.
	 */
	public string $address = 'https://example.fa.us2.oraclecloud.com';

	/**
	 * Identity domain address.
	 */
	public string $identity = 'https://idcs-abc123def.identity.oraclecloud.com';

	/**
	 * The scope the application grants.
	 */
	public string $scope = 'urn:opc:resource:fusion:EXAMPLE:erp/';

	/**
	 * OAuth client id and secret.
	 */
	public string $clientId = 'oci-client-id-0001';

	/**
	 * OAuth client secret.
	 */
	public string $clientSecret = 'oci-client-secret-0001';

	/**
	 * Fusion user and password (Basic sign-in).
	 */
	public string $user = 'SHOP_INTEGRATION';

	/**
	 * Fusion user password.
	 */
	public string $password = 'fusion-password-0001';

	/**
	 * The access token it issues.
	 */
	public string $token = 'oci-access-token-0001';

	/**
	 * How many of the next token requests are refused with invalid_client.
	 */
	public int $tokenFailures = 0;

	/**
	 * Business unit, source and type that exist.
	 */
	public string $businessUnit = 'Shop Business Unit';

	/**
	 * Transaction source that exists.
	 */
	public string $source = 'Web Orders';

	/**
	 * Invoice transaction type that exists.
	 */
	public string $type = 'Invoice';

	/**
	 * Customer accounts that exist.
	 *
	 * @var array<int,string>
	 */
	public array $accounts = array( '1001' );

	/**
	 * Currencies the ledger can book.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'AED', 'USD', 'KWD' );

	/**
	 * Tax in percent the system adds to the lines when an invoice is completed.
	 */
	public float $taxRate = 0.0;

	/**
	 * Whether invoices show a TotalAmount (a pod may not).
	 */
	public bool $showsTotal = true;

	/**
	 * Status an invoice gets: Complete, or Incomplete when required setup is missing.
	 */
	public string $status = 'Complete';

	/**
	 * Whether this pod wants NEGATIVE credit memo lines to credit the customer. When false, positive lines credit (the total comes out
	 * negative); when true, positive lines book a debit (the total comes out positive). The Oracle pages do not say which a pod does.
	 */
	public bool $creditLinesNegative = false;

	/**
	 * Whether completing a credit memo (PATCH InvoiceStatus Complete) is refused.
	 */
	public bool $refuseCompletion = false;

	/**
	 * Whether DELETE of a credit memo is refused.
	 */
	public bool $refuseMemoDelete = false;

	/**
	 * When set, invoices are saved in this currency whatever was asked.
	 */
	public ?string $forceCurrency = null;

	/**
	 * Item numbers with their descriptions.
	 *
	 * @var array<string,string>
	 */
	public array $items = array(
		'CS-BOWL-02' => 'Ceramic bowl, olive',
		'CS-MUG-01'  => 'Ceramic mug, sand',
	);

	/**
	 * Resources the user has no role for (they answer 403).
	 *
	 * @var array<int,string>
	 */
	public array $blocked = array();

	/**
	 * Records by resource then by id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $data = array(
		'receivablesInvoices'    => array(),
		'receivablesCreditMemos' => array(),
	);

	/**
	 * Calls of the token endpoint.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $tokenCalls = array();

	/**
	 * Answers scripted for the next write calls.
	 *
	 * @var array<int,HttpResponse>
	 */
	private array $failures = array();

	/**
	 * Next id.
	 */
	private int $next = 0;

	/**
	 * Net amount (signed, before tax) of every record by id.
	 *
	 * @var array<string,int>
	 */
	private array $nets = array();

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	private Amounts $amounts;

	/**
	 * Attributes each resource knows (a q or fields name outside the list is refused).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const KNOWN = array(
		'receivablesInvoices'    => array( 'CustomerTransactionId', 'TransactionNumber', 'BusinessUnit', 'TransactionSource', 'TransactionType', 'BillToCustomerNumber', 'InvoiceCurrencyCode', 'TransactionDate', 'PaymentTerms', 'PurchaseOrder', 'CrossReference', 'Comments', 'FreightAmount', 'InvoiceStatus', 'TotalAmount', 'InvoiceBalanceAmount' ),
		'receivablesCreditMemos' => array( 'CustomerTransactionId', 'TransactionNumber', 'BusinessUnit', 'TransactionSource', 'TransactionType', 'BillToCustomerNumber', 'InvoiceCurrencyCode', 'TransactionDate', 'PurchaseOrder', 'CrossReference', 'Comments', 'InvoiceStatus', 'TotalAmount' ),
		'itemsV2'                => array( 'ItemId', 'ItemNumber', 'ItemDescription', 'OrganizationCode' ),
	);

	/**
	 * Build the server and wire it into a FakeHttpClient.
	 *
	 * @param FakeHttpClient|null $http Client to install into; a new one when null.
	 */
	public function __construct( ?FakeHttpClient $http = null ) {
		$this->http    = $http ?? new FakeHttpClient();
		$this->amounts = new Amounts();
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Issue a different access token (the one a client holds stops working).
	 */
	public function rotateToken(): void {
		$this->token .= 'x';
	}

	/**
	 * Make the next write calls fail.
	 *
	 * @param HttpResponse $response Answer.
	 * @param int          $times    How many writes get it.
	 */
	public function failNext( HttpResponse $response, int $times = 1 ): void {
		for ( $i = 0; $i < $times; ++$i ) {
			$this->failures[] = $response;
		}
	}

	/**
	 * Records of a resource.
	 *
	 * @param string $resource Resource name.
	 * @return array<string,array<string,mixed>>
	 */
	public function all( string $resource ): array {
		return $this->data[ $resource ] ?? array();
	}

	/**
	 * Answer a request.
	 *
	 * @param array<string,mixed> $request Request as the FakeHttpClient records it.
	 */
	private function handle( array $request ): HttpResponse {
		$parts = wp_parse_url( (string) $request['url'] );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = (string) ( $parts['path'] ?? '' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( $host === (string) wp_parse_url( $this->identity, PHP_URL_HOST ) ) {
			return '/oauth2/v1/token' === $path ? $this->tokenEndpoint( $request ) : $this->error( 404, 'Not found.' );
		}
		if ( $host !== (string) wp_parse_url( $this->address, PHP_URL_HOST ) ) {
			return $this->error( 404, 'Unknown host.' );
		}
		if ( ! $this->authorised( $request ) ) {
			return $this->error( 401, 'Unauthorized' );
		}
		if ( 1 !== preg_match( '#^/fscmRestApi/resources/11\.13\.18\.05/(\w+)(?:/(\w+))?$#', $path, $m ) || ! isset( self::KNOWN[ $m[1] ] ) ) {
			return $this->error( 404, 'Resource not found.' );
		}
		if ( in_array( $m[1], $this->blocked, true ) ) {
			return $this->error( 403, 'The user has no role that grants access to ' . $m[1] );
		}
		$method = (string) $request['method'];
		if ( 'GET' === $method ) {
			return isset( $m[2] ) ? $this->one( $m[1], $m[2] ) : $this->list( $m[1], $query, $request );
		}
		if ( 'DELETE' === $method && isset( $m[2] ) ) {
			return $this->remove( $m[1], $m[2] );
		}
		if ( ! in_array( $method, array( 'POST', 'PATCH' ), true ) || ( 'POST' === $method ) === isset( $m[2] ) ) {
			return $this->error( 405, 'Method not allowed.' );
		}
		if ( 0 !== strcasecmp( $this->header( $request, 'content-type' ), 'application/vnd.oracle.adf.resourceitem+json' ) ) {
			return $this->error( 415, 'Unsupported media type.' );
		}
		if ( array() !== $this->failures ) {
			return array_shift( $this->failures );
		}
		$body = json_decode( (string) $request['body'], true ) ?: array();
		return 'PATCH' === $method ? $this->change( $m[1], $m[2], $body ) : $this->create( $m[1], $body );
	}

	/**
	 * The identity domain token endpoint.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function tokenEndpoint( array $request ): HttpResponse {
		parse_str( (string) $request['body'], $form );
		$this->tokenCalls[] = $form;
		$basic              = 'Basic ' . base64_encode( $this->clientId . ':' . $this->clientSecret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a test credential.
		if ( $this->tokenFailures > 0 || $basic !== $this->header( $request, 'authorization' ) || 'client_credentials' !== ( $form['grant_type'] ?? '' ) ) {
			$this->tokenFailures = max( 0, $this->tokenFailures - 1 );
			return FakeHttpClient::json( array( 'error' => 'invalid_client', 'error_description' => 'Client authentication failed' ), 401 );
		}
		if ( ( $form['scope'] ?? '' ) !== $this->scope ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_scope' ), 400 );
		}
		return FakeHttpClient::json(
			array(
				'access_token' => $this->token,
				'token_type'   => 'Bearer',
				'expires_in'   => 3600,
			)
		);
	}

	/**
	 * Whether the request carries a valid sign-in.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function authorised( array $request ): bool {
		$header = $this->header( $request, 'authorization' );
		$basic  = 'Basic ' . base64_encode( $this->user . ':' . $this->password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a test credential.
		return 'Bearer ' . $this->token === $header || $basic === $header;
	}

	/**
	 * A list page.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $query    Query.
	 * @param array<string,mixed> $request  Request.
	 */
	private function list( string $resource, array $query, array $request ): HttpResponse {
		$limit  = isset( $query['limit'] ) ? (int) $query['limit'] : 25;
		$offset = isset( $query['offset'] ) ? (int) $query['offset'] : 0;
		if ( $limit < 1 || $limit > 499 || $offset < 0 ) {
			return $this->error( 400, 'limit must be between 1 and 499.' );
		}
		$rows = 'itemsV2' === $resource ? $this->itemRows() : array_values( $this->data[ $resource ] );
		if ( '' !== (string) ( $query['q'] ?? '' ) ) {
			if ( (int) $this->header( $request, 'rest-framework-version' ) < 2 ) {
				return $this->error( 400, 'The q parameter syntax of framework version 1 is not supported by this connector.' );
			}
			$matched = $this->filter( $resource, (string) $query['q'], $rows );
			if ( is_string( $matched ) ) {
				return $this->error( 400, $matched );
			}
			$rows = $matched;
		}
		if ( '' !== (string) ( $query['orderBy'] ?? '' ) ) {
			$parts = explode( ':', (string) $query['orderBy'] );
			$key   = $parts[0];
			$desc  = 'desc' === strtolower( $parts[1] ?? 'asc' );
			usort( $rows, static fn( array $a, array $b ): int => $desc ? ( $b[ $key ] ?? 0 ) <=> ( $a[ $key ] ?? 0 ) : ( $a[ $key ] ?? 0 ) <=> ( $b[ $key ] ?? 0 ) );
		}
		$total = count( $rows );
		$page  = array_slice( $rows, $offset, $limit );
		if ( '' !== (string) ( $query['fields'] ?? '' ) ) {
			$wanted = explode( ',', (string) $query['fields'] );
			foreach ( $wanted as $name ) {
				if ( ! in_array( $name, self::KNOWN[ $resource ], true ) ) {
					return $this->error( 400, 'Unknown attribute ' . $name );
				}
			}
			$page = array_map( static fn( array $row ): array => array_intersect_key( $row, array_flip( $wanted ) ), $page );
		}
		return FakeHttpClient::json(
			array(
				'items'   => $page,
				'count'   => count( $page ),
				'hasMore' => $offset + $limit < $total,
				'limit'   => $limit,
				'offset'  => $offset,
			)
		);
	}

	/**
	 * One record.
	 *
	 * @param string $resource Resource.
	 * @param string $id       Id.
	 */
	private function one( string $resource, string $id ): HttpResponse {
		$row = $this->data[ $resource ][ $id ] ?? null;
		return null === $row ? $this->error( 404, 'The resource was not found.' ) : FakeHttpClient::json( $row );
	}

	/**
	 * Item rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function itemRows(): array {
		$rows = array();
		$id   = 100;
		foreach ( $this->items as $number => $description ) {
			$rows[] = array(
				'ItemId'          => ++$id,
				'ItemNumber'      => $number,
				'ItemDescription' => $description,
				'OrganizationCode' => 'M1',
			);
		}
		return $rows;
	}

	/**
	 * Rows that match a q filter, or the error text.
	 *
	 * @param string                           $resource Resource.
	 * @param string                           $q        Filter.
	 * @param array<int,array<string,mixed>>   $rows     Rows.
	 * @return array<int,array<string,mixed>>|string
	 */
	private function filter( string $resource, string $q, array $rows ): array|string {
		$pattern = "/\\s*(and|or)?\\s*(\\w+)\\s*(=|!=|>=|<=|>|<|like)\\s*'((?:[^']|'')*)'\\s*/iA";
		$offset  = 0;
		$groups  = array( array() );
		$length  = strlen( $q );
		while ( $offset < $length ) {
			if ( 1 !== preg_match( $pattern, $q, $m, 0, $offset ) ) {
				return 'Invalid q expression near: ' . substr( $q, $offset, 20 );
			}
			if ( ! in_array( $m[2], self::KNOWN[ $resource ], true ) ) {
				return 'Unknown attribute ' . $m[2] . ' in q.';
			}
			if ( 'or' === strtolower( $m[1] ) ) {
				$groups[] = array();
			}
			$groups[ count( $groups ) - 1 ][] = array( $m[2], strtolower( $m[3] ), str_replace( "''", "'", $m[4] ) );
			$offset                          += strlen( $m[0] );
		}
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $groups ): bool {
					foreach ( $groups as $group ) {
						$all = true;
						foreach ( $group as $condition ) {
							$all = $all && $this->test( $row[ $condition[0] ] ?? null, $condition[1], $condition[2] );
						}
						if ( $all ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * One comparison.
	 *
	 * @param mixed  $actual   Value in the row.
	 * @param string $operator Operator.
	 * @param string $wanted   Value in the filter.
	 */
	private function test( mixed $actual, string $operator, string $wanted ): bool {
		$actual = null === $actual ? '' : (string) $actual;
		return match ( $operator ) {
			'='    => $actual === $wanted,
			'!='   => $actual !== $wanted,
			'>='   => $actual >= $wanted,
			'<='   => $actual <= $wanted,
			'>'    => $actual > $wanted,
			'<'    => $actual < $wanted,
			'like' => 1 === preg_match( '/^' . str_replace( '%', '.*', preg_quote( $wanted, '/' ) ) . '$/s', $actual ),
			default => false,
		};
	}

	/**
	 * Create an invoice or a credit memo.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $body     Body.
	 */
	private function create( string $resource, array $body ): HttpResponse {
		if ( 'itemsV2' === $resource ) {
			return $this->error( 405, 'Method not allowed.' );
		}
		$credit = 'receivablesCreditMemos' === $resource;
		$lines  = $credit ? 'receivablesCreditMemoLines' : 'receivablesInvoiceLines';
		foreach ( array( 'BusinessUnit', 'TransactionSource', 'TransactionType', 'BillToCustomerNumber', 'InvoiceCurrencyCode' ) as $required ) {
			if ( '' === (string) ( $body[ $required ] ?? '' ) ) {
				return $this->error( 400, 'Attribute ' . $required . ' is required.', '/' . $required );
			}
		}
		if ( $this->businessUnit !== $body['BusinessUnit'] ) {
			return $this->error( 400, 'The business unit ' . $body['BusinessUnit'] . ' is not valid.', '/BusinessUnit' );
		}
		if ( $this->source !== $body['TransactionSource'] ) {
			return $this->error( 400, 'The transaction source ' . $body['TransactionSource'] . ' is not valid.', '/TransactionSource' );
		}
		if ( ! in_array( (string) $body['BillToCustomerNumber'], $this->accounts, true ) ) {
			return $this->error( 400, 'The customer account ' . $body['BillToCustomerNumber'] . ' does not exist.', '/BillToCustomerNumber' );
		}
		if ( ! in_array( (string) $body['InvoiceCurrencyCode'], $this->currencies, true ) ) {
			return $this->error( 400, 'The currency ' . $body['InvoiceCurrencyCode'] . ' is not set up.', '/InvoiceCurrencyCode' );
		}
		if ( ! is_array( $body[ $lines ] ?? null ) || array() === $body[ $lines ] ) {
			return $this->error( 400, 'At least one line is required.', '/' . $lines );
		}
		$currency = $this->forceCurrency ?? (string) $body['InvoiceCurrencyCode'];
		$net      = 0;
		foreach ( $body[ $lines ] as $line ) {
			if ( ! is_int( $line['Quantity'] ?? null ) && ! is_float( $line['Quantity'] ?? null ) ) {
				return $this->error( 400, 'Quantity must be a number.', '/' . $lines . '/Quantity' );
			}
			if ( ! is_int( $line['UnitSellingPrice'] ?? null ) && ! is_float( $line['UnitSellingPrice'] ?? null ) ) {
				return $this->error( 400, 'UnitSellingPrice must be a number.', '/' . $lines . '/UnitSellingPrice' );
			}
			$net += $this->amounts->lineTotal( (int) $this->amounts->fromWire( $line['UnitSellingPrice'], $currency ), (string) $line['Quantity'] );
		}
		$net  += (int) $this->amounts->fromWire( $body['FreightAmount'] ?? 0, $currency );
		$sign  = $credit && ! $this->creditLinesNegative ? -1 : 1;
		$net  *= $sign;
		$draft = $credit && 'Incomplete' === ( $body['InvoiceStatus'] ?? '' );
		$gross = $draft ? $net : $net + (int) round( $net * $this->taxRate / 100 );
		++$this->next;
		$id  = (string) ( 300000000000000 + $this->next );
		$row = array(
			'CustomerTransactionId' => (int) $id,
			'TransactionNumber'     => ( $credit ? 'CM-' : 'INV-' ) . $this->next,
			'InvoiceStatus'         => $credit ? (string) ( $body['InvoiceStatus'] ?? $this->status ) : $this->status,
			'InvoiceCurrencyCode'   => $currency,
		) + array_diff_key( $body, array( $lines => true, 'InvoiceStatus' => true ) );
		$this->nets[ $id ] = $net;
		if ( $this->showsTotal ) {
			$row['TotalAmount'] = $this->amounts->toNumber( $gross, $currency );
			if ( ! $credit ) {
				$row['InvoiceBalanceAmount'] = $row['TotalAmount'];
			}
		}
		$this->data[ $resource ][ $id ] = $row;
		return FakeHttpClient::json( $row, 201 );
	}

	/**
	 * Complete a credit memo: the status changes and the tax is added to the total.
	 *
	 * @param string              $resource Resource.
	 * @param string              $id       Id.
	 * @param array<string,mixed> $body     Attributes to change.
	 */
	private function change( string $resource, string $id, array $body ): HttpResponse {
		$row = $this->data[ $resource ][ $id ] ?? null;
		if ( 'receivablesCreditMemos' !== $resource || null === $row ) {
			return $this->error( 404, 'The resource was not found.' );
		}
		if ( $this->refuseCompletion ) {
			return $this->error( 400, 'The credit memo cannot be completed: required accounting setup is missing.', '/InvoiceStatus' );
		}
		if ( 'Complete' === ( $body['InvoiceStatus'] ?? '' ) && 'Incomplete' === $row['InvoiceStatus'] ) {
			$net                              = $this->nets[ $id ] ?? 0;
			$row['InvoiceStatus']             = 'Complete';
			$row['TotalAmount']               = $this->amounts->toNumber( $net + (int) round( $net * $this->taxRate / 100 ), (string) $row['InvoiceCurrencyCode'] );
			$this->data[ $resource ][ $id ]   = $row;
		}
		return FakeHttpClient::json( $row );
	}

	/**
	 * Delete a record: only an Incomplete credit memo can go.
	 *
	 * @param string $resource Resource.
	 * @param string $id       Id.
	 */
	private function remove( string $resource, string $id ): HttpResponse {
		$row = $this->data[ $resource ][ $id ] ?? null;
		if ( 'receivablesCreditMemos' !== $resource || null === $row ) {
			return $this->error( 404, 'The resource was not found.' );
		}
		if ( $this->refuseMemoDelete || 'Incomplete' !== $row['InvoiceStatus'] ) {
			return $this->error( 400, 'The transaction cannot be deleted.' );
		}
		unset( $this->data[ $resource ][ $id ] );
		return new HttpResponse( 204 );
	}

	/**
	 * An Oracle REST error body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $text   Message.
	 * @param string $path   Attribute path, or empty.
	 */
	private function error( int $status, string $text, string $path = '' ): HttpResponse {
		$body = array(
			'title'  => 'Error ' . $status,
			'status' => (string) $status,
			'detail' => $text,
		);
		if ( '' !== $path ) {
			$body['o:errorDetails'] = array( array( 'detail' => $text, 'o:errorPath' => $path, 'o:errorCode' => 'ERR' ) );
		}
		return FakeHttpClient::json( $body, $status );
	}

	/**
	 * A request header, any case.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $name    Header name.
	 */
	private function header( array $request, string $name ): string {
		foreach ( (array) $request['headers'] as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return (string) $value;
			}
		}
		return '';
	}
}
