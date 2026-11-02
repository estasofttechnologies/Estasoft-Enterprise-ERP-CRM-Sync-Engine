<?php
/**
 * A small in-memory SAP S/4HANA tenant (OData V2 APIs) for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Behaviour follows the pages listed in docs/connectors/sap-s4hana.md: the OAuth token endpoint (client credentials, Basic client sign-in),
 * Basic sign-in of a communication user, `GET <service>/` with `X-CSRF-Token: Fetch` answering the token and session cookies, a write without
 * a valid token answering 403 with `x-csrf-token: Required`, `d.results` and `d` envelopes, `$filter`/`$top`/`$skip`/`$select`/`$orderby`,
 * ETags and `If-Match`. It models on purpose what once hid bugs elsewhere: a price list that differs from the shop's, a tax added by the
 * system, a token that expires between two writes, an order that cannot be deleted.
 */
final class FakeSapS4Server {

	/**
	 * The client the connector is built on.
	 */
	public FakeHttpClient $http;

	/**
	 * API address as the merchant types it.
	 */
	public string $address = 'https://my300000-api.s4hana.cloud.sap';

	/**
	 * Token service URL shown on the communication arrangement.
	 */
	public string $tokenUrl = 'https://my300000-api.s4hana.cloud.sap/sap/bc/sec/oauth2/token';

	/**
	 * OAuth client id and secret.
	 */
	public string $clientId = 's4-client-id-0001';

	/**
	 * OAuth client secret.
	 */
	public string $clientSecret = 's4-client-secret-0001';

	/**
	 * Communication user and password (Basic sign-in).
	 */
	public string $user = 'TECH_SHOP';

	/**
	 * Communication user password.
	 */
	public string $password = 's4-password-0001';

	/**
	 * Scope the arrangement grants.
	 */
	public string $scope = 'API_SALES_ORDER_SRV_0001';

	/**
	 * The access token it issues.
	 */
	public string $token = 's4-access-token-0001';

	/**
	 * How many of the next token requests are refused with invalid_client.
	 */
	public int $tokenFailures = 0;

	/**
	 * Tax in percent the system adds to the net amount (not part of TotalNetAmount).
	 */
	public float $taxRate = 0.0;

	/**
	 * Whether DELETE of a sales order is refused.
	 */
	public bool $refuseDelete = false;

	/**
	 * When set, orders are saved in this currency whatever was asked.
	 */
	public ?string $forceCurrency = null;

	/**
	 * Products: number => list price per unit as a decimal string.
	 *
	 * @var array<string,string>
	 */
	public array $products = array(
		'CS-BOWL-02' => '210.00',
		'CS-MUG-01'  => '40.00',
	);

	/**
	 * Customers the system knows.
	 *
	 * @var array<int,string>
	 */
	public array $customers = array( '17100001' );

	/**
	 * Entities by name then by key.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $data = array(
		'A_SalesOrder'         => array(),
		'A_CreditMemoRequest'  => array(),
		'A_BillingDocument'    => array(),
		'A_BillingDocumentItem' => array(),
	);

	/**
	 * The CSRF token and session cookie that are valid now.
	 */
	public string $csrf = 'csrf-token-0001';

	/**
	 * Number of CSRF tokens handed out.
	 */
	public int $csrfFetches = 0;

	/**
	 * How many of the next CSRF tokens handed out are already stale (the session ended between the fetch and the write).
	 */
	public int $staleTokens = 0;

	/**
	 * Calls of the token endpoint.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $tokenCalls = array();

	/**
	 * Services that have no communication arrangement (they answer 403).
	 *
	 * @var array<int,string>
	 */
	public array $blocked = array();

	/**
	 * Answers scripted for the next write calls.
	 *
	 * @var array<int,HttpResponse>
	 */
	private array $failures = array();

	/**
	 * Next number per entity.
	 *
	 * @var array<string,int>
	 */
	private array $next = array();

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	private Amounts $amounts;

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
	 * The host of the address.
	 */
	public function host(): string {
		return (string) wp_parse_url( $this->address, PHP_URL_HOST );
	}

	/**
	 * Issue a different access token (the one a client holds stops working).
	 */
	public function rotateToken(): void {
		$this->token .= 'x';
	}

	/**
	 * Invalidate the CSRF token and session cookie a client holds.
	 */
	public function expireCsrf(): void {
		$this->csrf .= 'x';
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
	 * Rows of an entity set.
	 *
	 * @param string $entity Entity set.
	 * @return array<string,array<string,mixed>>
	 */
	public function all( string $entity ): array {
		return $this->data[ $entity ] ?? array();
	}

	/**
	 * Answer a request.
	 *
	 * @param array<string,mixed> $request Request as the FakeHttpClient records it.
	 */
	private function handle( array $request ): HttpResponse {
		$parts = wp_parse_url( (string) $request['url'] );
		if ( strtolower( (string) ( $parts['host'] ?? '' ) ) !== $this->host() ) {
			return $this->error( 404, 'Unknown host.' );
		}
		$path = (string) ( $parts['path'] ?? '' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( '/sap/bc/sec/oauth2/token' === $path ) {
			return $this->tokenEndpoint( $request );
		}
		if ( ! $this->authorised( $request ) ) {
			return $this->error( 401, 'Unauthorized' );
		}
		if ( 1 !== preg_match( '#^/sap/opu/odata/sap/(API_\w+)/(.*)$#', $path, $m ) ) {
			return $this->error( 404, 'Unknown service.' );
		}
		if ( in_array( $m[1], $this->blocked, true ) ) {
			return $this->error( 403, 'No communication arrangement for ' . $m[1] );
		}
		$method = (string) $request['method'];
		if ( '' === $m[2] ) {
			return $this->root( $request );
		}
		if ( 'GET' === $method ) {
			return $this->read( $m[2], $query, $request );
		}
		if ( ! $this->csrfValid( $request ) ) {
			return new HttpResponse( 403, (string) json_encode( array( 'error' => array( 'code' => 'CSRF', 'message' => array( 'lang' => 'en', 'value' => 'CSRF token validation failed' ) ) ) ), array( 'x-csrf-token' => 'Required' ) );
		}
		if ( array() !== $this->failures ) {
			return array_shift( $this->failures );
		}
		if ( 'POST' === $method ) {
			return $this->create( $m[2], json_decode( (string) $request['body'], true ) ?: array() );
		}
		if ( 'DELETE' === $method ) {
			return $this->delete( $m[2], $request );
		}
		return $this->error( 400, 'Unsupported.' );
	}

	/**
	 * The OAuth token endpoint.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function tokenEndpoint( array $request ): HttpResponse {
		parse_str( (string) $request['body'], $form );
		$this->tokenCalls[] = $form;
		$basic              = 'Basic ' . base64_encode( $this->clientId . ':' . $this->clientSecret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a test credential.
		if ( $this->tokenFailures > 0 || $basic !== $this->header( $request, 'authorization' ) || 'client_credentials' !== ( $form['grant_type'] ?? '' ) ) {
			$this->tokenFailures = max( 0, $this->tokenFailures - 1 );
			return FakeHttpClient::json( array( 'error' => 'invalid_client' ), 401 );
		}
		return FakeHttpClient::json(
			array(
				'access_token' => $this->token,
				'token_type'   => 'bearer',
				'expires_in'   => 3600,
				'scope'        => $this->scope,
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
	 * The service root: a CSRF token when asked.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function root( array $request ): HttpResponse {
		if ( 'fetch' !== strtolower( $this->header( $request, 'x-csrf-token' ) ) ) {
			return new HttpResponse( 200, '{}', array() );
		}
		++$this->csrfFetches;
		$given = $this->csrf;
		if ( $this->staleTokens > 0 ) {
			--$this->staleTokens;
			$this->expireCsrf();
		}
		return new HttpResponse(
			200,
			'{}',
			array(
				'x-csrf-token' => $given,
				'set-cookie'   => 'SAP_SESSIONID_S4X_100=' . $given . '; path=/; HttpOnly, sap-usercontext=sap-client=100; path=/',
			)
		);
	}

	/**
	 * Whether the write carries the current token and session cookie.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function csrfValid( array $request ): bool {
		return $this->csrf === $this->header( $request, 'x-csrf-token' ) && str_contains( $this->header( $request, 'cookie' ), 'SAP_SESSIONID_S4X_100=' . $this->csrf );
	}

	/**
	 * A list or one entity.
	 *
	 * @param string              $path    Path under the service.
	 * @param array<string,mixed> $query   Query.
	 * @param array<string,mixed> $request Request.
	 */
	private function read( string $path, array $query, array $request ): HttpResponse {
		unset( $request );
		if ( 1 === preg_match( "#^(\w+)\('((?:[^']|'')*)'\)$#", rawurldecode( $path ), $m ) ) {
			$row = $this->data[ $m[1] ][ str_replace( "''", "'", $m[2] ) ] ?? null;
			if ( null === $row ) {
				return $this->error( 404, 'Resource not found for the segment ' . $m[1] );
			}
			return FakeHttpClient::json( array( 'd' => $this->select( $row, (string) ( $query['$select'] ?? '' ) ) ), 200, array( 'etag' => $this->etag( $row ) ) );
		}
		if ( 'A_Product' === $path ) {
			$rows = array();
			foreach ( $this->products as $code => $price ) {
				$rows[] = array( 'Product' => $code, 'ListPrice' => $price );
			}
		} elseif ( isset( $this->data[ $path ] ) ) {
			$rows = array_values( $this->data[ $path ] );
		} else {
			return $this->error( 404, 'Resource not found for the segment ' . $path );
		}
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => FakeODataFilter::matches( (string) ( $query['$filter'] ?? '' ), $row ) ) );
		if ( '' !== (string) ( $query['$orderby'] ?? '' ) ) {
			$parts = explode( ' ', trim( (string) $query['$orderby'] ) );
			$key   = $parts[0];
			$desc  = 'desc' === strtolower( $parts[1] ?? '' );
			usort( $rows, static fn( array $a, array $b ): int => $desc ? ( $b[ $key ] ?? 0 ) <=> ( $a[ $key ] ?? 0 ) : ( $a[ $key ] ?? 0 ) <=> ( $b[ $key ] ?? 0 ) );
		}
		$rows = array_slice( $rows, (int) ( $query['$skip'] ?? 0 ), isset( $query['$top'] ) ? (int) $query['$top'] : null );
		return FakeHttpClient::json( array( 'd' => array( 'results' => array_map( fn( array $row ): array => $this->select( $row, (string) ( $query['$select'] ?? '' ) ), $rows ) ) ) );
	}

	/**
	 * Create an entity.
	 *
	 * @param string              $path Entity set.
	 * @param array<string,mixed> $body Body.
	 */
	private function create( string $path, array $body ): HttpResponse {
		if ( 'A_SalesOrder' === $path ) {
			return $this->createOrder( $body );
		}
		if ( 'A_CreditMemoRequest' === $path ) {
			$number = (string) ( 90000000 + $this->sequence( $path ) );
			$row    = array(
				'CreditMemoRequest'       => $number,
				'PurchaseOrderByCustomer' => (string) ( $body['PurchaseOrderByCustomer'] ?? '' ),
				'TransactionCurrency'     => (string) ( $body['TransactionCurrency'] ?? '' ),
				'ReferenceSDDocument'     => (string) ( $body['ReferenceSDDocument'] ?? '' ),
				'to_Item'                 => $body['to_Item'] ?? array(),
			) + $body;
			$this->data[ $path ][ $number ] = $row;
			return FakeHttpClient::json( array( 'd' => $row ), 201 );
		}
		return $this->error( 404, 'Resource not found for the segment ' . $path );
	}

	/**
	 * Create a sales order from a deep insert.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createOrder( array $body ): HttpResponse {
		foreach ( array( 'SalesOrganization', 'DistributionChannel', 'OrganizationDivision', 'SoldToParty' ) as $required ) {
			if ( '' === (string) ( $body[ $required ] ?? '' ) ) {
				return $this->error( 400, 'Field ' . $required . ' is required.' );
			}
		}
		if ( ! in_array( (string) $body['SoldToParty'], $this->customers, true ) ) {
			return $this->error( 400, 'Customer ' . $body['SoldToParty'] . ' does not exist.' );
		}
		$currency = $this->forceCurrency ?? (string) ( $body['TransactionCurrency'] ?? 'AED' );
		$net      = 0;
		foreach ( (array) ( $body['to_Item'] ?? array() ) as $item ) {
			$material = (string) ( $item['Material'] ?? '' );
			if ( ! isset( $this->products[ $material ] ) ) {
				return $this->error( 400, 'Product ' . $material . ' does not exist.' );
			}
			$price = (int) $this->amounts->fromWire( $this->products[ $material ], $currency );
			foreach ( (array) ( $item['to_PricingElement'] ?? array() ) as $element ) {
				if ( 'PR00' === ( $element['ConditionType'] ?? '' ) ) {
					$price = (int) $this->amounts->fromWire( $element['ConditionRateValue'] ?? 0, $currency );
				}
			}
			$net += $this->amounts->lineTotal( $price, (string) ( $item['RequestedQuantity'] ?? 1 ) );
		}
		$number = (string) ( 1000 + $this->sequence( 'A_SalesOrder' ) );
		$row    = array(
			'SalesOrder'              => $number,
			'SalesOrderType'          => (string) ( $body['SalesOrderType'] ?? 'OR' ),
			'SoldToParty'             => (string) $body['SoldToParty'],
			'PurchaseOrderByCustomer' => (string) ( $body['PurchaseOrderByCustomer'] ?? '' ),
			'TransactionCurrency'     => $currency,
			'TotalNetAmount'          => $this->amounts->toDecimal( $net, $currency ),
			'SalesOrderDate'          => (string) ( $body['SalesOrderDate'] ?? '/Date(1793000000000)/' ),
			'to_Item'                 => $body['to_Item'] ?? array(),
		);
		$this->data['A_SalesOrder'][ $number ] = $row;
		return FakeHttpClient::json( array( 'd' => $row ), 201 );
	}

	/**
	 * Delete an entity with If-Match.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $request Request.
	 */
	private function delete( string $path, array $request ): HttpResponse {
		if ( 1 !== preg_match( "#^(\w+)\('((?:[^']|'')*)'\)$#", rawurldecode( $path ), $m ) ) {
			return $this->error( 400, 'Unsupported.' );
		}
		$key = str_replace( "''", "'", $m[2] );
		$row = $this->data[ $m[1] ][ $key ] ?? null;
		if ( null === $row ) {
			return $this->error( 404, 'Resource not found.' );
		}
		if ( $this->refuseDelete ) {
			return $this->error( 400, 'The sales order cannot be deleted.' );
		}
		$match = $this->header( $request, 'if-match' );
		if ( '' === $match ) {
			return $this->error( 428, 'Precondition required.' );
		}
		if ( '*' !== $match && $match !== $this->etag( $row ) ) {
			return $this->error( 412, 'Precondition failed.' );
		}
		unset( $this->data[ $m[1] ][ $key ] );
		return new HttpResponse( 204 );
	}

	/**
	 * The ETag of a row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function etag( array $row ): string {
		return 'W/"' . substr( md5( (string) json_encode( $row ) ), 0, 12 ) . '"';
	}

	/**
	 * Next number of an entity.
	 *
	 * @param string $entity Entity.
	 */
	private function sequence( string $entity ): int {
		$this->next[ $entity ] = ( $this->next[ $entity ] ?? 0 ) + 1;
		return $this->next[ $entity ];
	}

	/**
	 * Only the properties asked for.
	 *
	 * @param array<string,mixed> $row    Row.
	 * @param string              $select Comma separated properties.
	 * @return array<string,mixed>
	 */
	private function select( array $row, string $select ): array {
		if ( '' === $select ) {
			return $row;
		}
		return array_intersect_key( $row, array_flip( explode( ',', $select ) ) );
	}

	/**
	 * An OData V2 error body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $text   Message.
	 */
	private function error( int $status, string $text ): HttpResponse {
		return FakeHttpClient::json( array( 'error' => array( 'code' => 'E/' . $status, 'message' => array( 'lang' => 'en', 'value' => $text ) ) ), $status );
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
