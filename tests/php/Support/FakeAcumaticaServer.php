<?php
/**
 * A small in-memory Acumatica site for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Acumatica connector makes the way the contract-based REST API documents them: every field is `{"value": ...}`,
 * `$filter` / `$expand` / `$select` / `$top` / `$skip` on lists, PUT creates or changes by key, actions answer 202 with a Location to poll,
 * a refused field answers 422 with an `error` next to the value, business errors answer 500 with `exceptionMessage`, and the identity server
 * refreshes tokens. It models on purpose what once hides bugs: a customer accepts only its own currency, the order total is worked out from
 * the lines and their tax categories, an unknown field in `$select` is refused, an item that is not Active is refused, and a credit memo is
 * released only from the Balanced status. It does not model everything Acumatica checks. All data is synthetic.
 */
final class FakeAcumaticaServer {

	public const ADDRESS = 'https://erp.acme-trading.example.test';

	public const TENANT = 'Acme Trading';

	public const CLIENT_ID = '4b1e7c52-0c1d-4a6e-9f10-6a1b2c3d4e5f@Acme Trading';

	public const CLIENT_SECRET = 'acu-secret-71c3e9d05a';

	public const VERSION = '24.200.001';

	/**
	 * Fields each entity knows, for the check on `$select`.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const FIELDS = array(
		'Customer'     => array( 'CustomerID', 'CustomerName', 'CustomerClass', 'Status', 'MainContact', 'CurrencyID', 'id', 'LastModified' ),
		'StockItem'    => array( 'InventoryID', 'Description', 'ItemStatus', 'WarehouseDetails', 'id', 'LastModified' ),
		'NonStockItem' => array( 'InventoryID', 'Description', 'ItemStatus', 'id', 'LastModified' ),
		'TaxCategory'  => array( 'TaxCategoryID', 'Description', 'Active', 'id' ),
		'Warehouse'    => array( 'WarehouseID', 'Description', 'id' ),
		'SalesOrder'   => array( 'OrderType', 'OrderNbr', 'CustomerID', 'CustomerOrder', 'Date', 'CurrencyID', 'Description', 'Hold', 'Status', 'OrderTotal', 'Details', 'Shipments', 'ShipToAddressOverride', 'ShipToAddress', 'id', 'LastModified' ),
		'SalesInvoice' => array( 'Type', 'ReferenceNbr', 'CustomerID', 'Date', 'Currency', 'Description', 'Hold', 'Status', 'Amount', 'Details', 'id', 'LastModified' ),
		'Shipment'     => array( 'ShipmentNbr', 'Status', 'ShipmentDate', 'Packages', 'id', 'LastModified' ),
	);

	/**
	 * Key fields of each entity.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const KEYS = array(
		'Customer'     => array( 'CustomerID' ),
		'StockItem'    => array( 'InventoryID' ),
		'NonStockItem' => array( 'InventoryID' ),
		'TaxCategory'  => array( 'TaxCategoryID' ),
		'Warehouse'    => array( 'WarehouseID' ),
		'SalesOrder'   => array( 'OrderType', 'OrderNbr' ),
		'SalesInvoice' => array( 'Type', 'ReferenceNbr' ),
		'Shipment'     => array( 'ShipmentNbr' ),
	);

	/**
	 * Records by entity and key.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $records = array();

	/**
	 * Every call: method, path, query, body, headers.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $calls = array();

	/**
	 * Tax rate in percent by tax category.
	 *
	 * @var array<string,float>
	 */
	public array $rates = array(
		'VAT5'   => 5.0,
		'EXEMPT' => 0.0,
	);

	/**
	 * The time the site stamps on new records (UTC, ISO).
	 */
	public string $now = '2026-11-02T10:00:00';

	/**
	 * Currency the site answers with whatever was sent (a wrong customer setup), or empty.
	 */
	public string $forceCurrency = '';

	/**
	 * Polls a release action answers 202 for before it is done.
	 */
	public int $releaseDelay = 0;

	/**
	 * Token requests received: form fields.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $tokenRequests = array();

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	private array $accessTokens = array();

	/**
	 * Valid refresh tokens.
	 *
	 * @var array<string,bool>
	 */
	private array $refreshTokens = array();

	/**
	 * Release actions that are running: token to remaining polls and the credit memo key.
	 *
	 * @var array<string,array{polls:int,key:string}>
	 */
	private array $actions = array();

	/**
	 * An answer to give to the next call that matches, once.
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse|callable}>
	 */
	private array $failures = array();

	/**
	 * Calls whose answer is lost after the site has done the work: method, pattern, calls to let pass first.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private array $lost = array();

	/**
	 * Whether the identity server refuses the client (a revoked secret).
	 */
	public bool $refuseClient = false;

	private int $issued = 0;

	private int $sequence = 0;

	/**
	 * Attach the site to a fake network.
	 *
	 * @param FakeHttpClient $http Network.
	 */
	public function __construct( FakeHttpClient $http ) {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
		$this->seed();
	}

	/**
	 * Secrets for a connection that is signed in.
	 *
	 * @param int $now Epoch seconds now.
	 * @return array<string,string>
	 */
	public function signedIn( int $now ): array {
		++$this->issued;
		$access                          = 'acu-access-' . $this->issued . '-aaaaaaaa';
		$refresh                         = 'acu-refresh-' . $this->issued . '-bbbbbbbb';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return array(
			'client_secret'            => self::CLIENT_SECRET,
			'oauth_access_token'       => $access,
			'oauth_refresh_token'      => $refresh,
			'oauth_expires_at'         => (string) ( $now + 3000 ),
			'oauth_refresh_expires_at' => (string) ( $now + 30 * 86400 ),
		);
	}

	/**
	 * Give a failure to the next call whose method and path pattern match.
	 *
	 * @param string                $method  HTTP method or *.
	 * @param string                $pattern Regular expression for the path.
	 * @param HttpResponse|callable $answer  Answer.
	 */
	public function failNext( string $method, string $pattern, HttpResponse|callable $answer ): void {
		$this->failures[] = array( strtoupper( $method ), $pattern, $answer );
	}

	/**
	 * Make the next write fail the way a failure kind says; `auth` ends the session and revokes the client.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, certificate or auth.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$response = match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 503, 'Service unavailable, secret ' . self::CLIENT_SECRET ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => null,
		};
		if ( 'auth' === $kind ) {
			$this->failNext(
				'PUT',
				'#/SalesOrder$#',
				function (): HttpResponse {
					$this->accessTokens = array();
					$this->refuseClient = true;
					return new HttpResponse( 401, '', array() );
				}
			);
			return;
		}
		$this->failNext( 'PUT', '#/SalesOrder$#', $response ?? FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => 'boom ' . self::CLIENT_SECRET, 'exceptionType' => 'System.Exception' ), 500 ) );
	}

	/**
	 * Let the next matching call do its work on the site and then time out, so the connector never sees the answer.
	 *
	 * @param string $method  HTTP method.
	 * @param string $pattern Regular expression for the path.
	 * @param int    $skip    Matching calls to let through first.
	 */
	public function loseAnswerOf( string $method, string $pattern, int $skip = 0 ): void {
		$this->lost[] = array( strtoupper( $method ), $pattern, $skip );
	}

	/**
	 * Count of calls that match.
	 *
	 * @param string $method  Method.
	 * @param string $pattern Pattern on the path.
	 */
	public function count( string $method, string $pattern ): int {
		$n = 0;
		foreach ( $this->calls as $call ) {
			if ( $call['method'] === strtoupper( $method ) && 1 === preg_match( $pattern, (string) $call['path'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Records of an entity.
	 *
	 * @param string $entity Entity.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $entity ): array {
		return array_values( $this->records[ $entity ] ?? array() );
	}

	/**
	 * Add a record directly (plain values, not wrapped).
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $record Fields.
	 * @return array<string,mixed>
	 */
	public function put( string $entity, array $record ): array {
		$record['id']           = $record['id'] ?? $this->guid();
		$record['LastModified'] = $record['LastModified'] ?? $this->now . '+00:00';
		$this->records[ $entity ][ $this->key( $entity, $record ) ] = $record;
		return $record;
	}

	/**
	 * Add a customer.
	 *
	 * @param string              $id    Customer ID.
	 * @param string              $name  Name.
	 * @param string              $email Email.
	 * @param array<string,mixed> $extra More fields; `_strictCurrency` makes the customer refuse another currency.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $id, string $name, string $email, array $extra = array() ): array {
		return $this->put(
			'Customer',
			$extra + array(
				'CustomerID'   => $id,
				'CustomerName' => $name,
				'Status'       => 'Active',
				'CurrencyID'   => 'AED',
				'MainContact'  => array( 'Email' => $email ),
			)
		);
	}

	/**
	 * Add a shipment to an order.
	 *
	 * @param string              $orderKey Order key such as SO/000001.
	 * @param string              $number   Shipment number.
	 * @param string              $tracking Tracking number of the package.
	 * @param string              $status   Shipment status.
	 * @param string              $invoice  Invoice number the shipment was invoiced on.
	 * @param array<string,mixed> $extra    More fields.
	 */
	public function addShipment( string $orderKey, string $number, string $tracking, string $status = 'Confirmed', string $invoice = '', array $extra = array() ): void {
		$this->records['SalesOrder'][ $orderKey ]['Shipments'][] = array(
			'ShipmentNbr' => $number,
			'InvoiceNbr'  => $invoice,
		);
		$this->records['SalesOrder'][ $orderKey ]['LastModified'] = $this->now . '+00:00';
		$this->put(
			'Shipment',
			$extra + array(
				'ShipmentNbr'  => $number,
				'Status'       => $status,
				'ShipmentDate' => '2026-11-03T00:00:00',
				'Packages'     => '' === $tracking ? array() : array( array( 'TrackingNbr' => $tracking ) ),
			)
		);
	}

	/**
	 * Master data of a small shop in the Emirates.
	 */
	private function seed(): void {
		foreach ( array( 'CS-MUG-01' => array( 'MAIN' => 40.0, 'SHOWROOM' => 5.0 ), 'CS-BOWL-02' => array( 'MAIN' => 12.0 ) ) as $id => $stock ) {
			$details = array();
			foreach ( $stock as $warehouse => $quantity ) {
				$details[] = array(
					'WarehouseID' => $warehouse,
					'QtyOnHand'   => $quantity,
				);
			}
			$this->put( 'StockItem', array( 'InventoryID' => $id, 'Description' => 'Item ' . $id, 'ItemStatus' => 'Active', 'WarehouseDetails' => $details ) );
		}
		$this->put( 'StockItem', array( 'InventoryID' => 'OLD-ITEM', 'Description' => 'Retired', 'ItemStatus' => 'Inactive', 'WarehouseDetails' => array() ) );
		foreach ( array( 'SHIPPING', 'FEE', 'DISCOUNT', 'REFUND' ) as $id ) {
			$this->put( 'NonStockItem', array( 'InventoryID' => $id, 'Description' => ucfirst( strtolower( $id ) ), 'ItemStatus' => 'Active' ) );
		}
		$this->put( 'TaxCategory', array( 'TaxCategoryID' => 'VAT5', 'Description' => 'VAT 5%', 'Active' => true ) );
		$this->put( 'TaxCategory', array( 'TaxCategoryID' => 'EXEMPT', 'Description' => 'Exempt', 'Active' => true ) );
		$this->put( 'TaxCategory', array( 'TaxCategoryID' => 'OLDTAX', 'Description' => 'Retired', 'Active' => false ) );
		$this->put( 'Warehouse', array( 'WarehouseID' => 'MAIN', 'Description' => 'Main warehouse' ) );
		$this->put( 'Warehouse', array( 'WarehouseID' => 'SHOWROOM', 'Description' => 'Showroom' ) );
	}

	/**
	 * Answer one request.
	 *
	 * @param array<string,mixed> $request Request from the client.
	 */
	private function handle( array $request ): HttpResponse {
		$response = $this->dispatch( $request );
		$path     = rawurldecode( (string) ( parse_url( (string) $request['url'], PHP_URL_PATH ) ?? '/' ) );
		foreach ( $this->lost as $index => $lost ) {
			if ( $lost[0] === (string) $request['method'] && 1 === preg_match( $lost[1], $path ) ) {
				if ( $lost[2] > 0 ) {
					--$this->lost[ $index ][2];
					continue;
				}
				unset( $this->lost[ $index ] );
				return FakeHttpClient::timeout();
			}
		}
		return $response;
	}

	/**
	 * Work out the answer to one request.
	 *
	 * @param array<string,mixed> $request Request from the client.
	 */
	private function dispatch( array $request ): HttpResponse {
		$parts  = parse_url( (string) $request['url'] );
		$path   = rawurldecode( (string) ( $parts['path'] ?? '/' ) );
		$folder = '';
		if ( 1 === preg_match( '#^/(AcumaticaDB)(?=/)#', $path, $found ) ) {
			$folder = '/' . $found[1];
			$path   = substr( $path, strlen( $folder ) );
		}
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = (string) $request['method'];
		$body   = null === $request['body'] ? array() : (array) json_decode( (string) $request['body'], true );
		$form   = array();
		if ( '/identity/connect/token' === $path ) {
			parse_str( (string) $request['body'], $form );
		}
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'headers' => (array) $request['headers'], 'form' => $form, 'folder' => $folder );

		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path ) ) {
				unset( $this->failures[ $index ] );
				return $failure[2] instanceof HttpResponse ? $failure[2] : ( $failure[2] )( $request );
			}
		}
		if ( '/identity/connect/token' === $path ) {
			return $this->token( $form );
		}
		$authorization = (string) ( $request['headers']['Authorization'] ?? '' );
		if ( ! str_starts_with( $authorization, 'Bearer ' ) || empty( $this->accessTokens[ substr( $authorization, 7 ) ] ) ) {
			return new HttpResponse( 401, '', array( 'www-authenticate' => 'Bearer error="invalid_token"' ) );
		}
		if ( '/entity' === $path || '/entity/' === $path ) {
			return FakeHttpClient::json( array( 'endpoints' => array( array( 'name' => 'Default', 'version' => self::VERSION ), array( 'name' => 'Default', 'version' => '23.200.001' ), array( 'name' => 'Default', 'version' => '22.200.001' ) ) ) );
		}
		$prefix = '/entity/Default/' . self::VERSION . '/';
		if ( ! str_starts_with( $path, $prefix ) ) {
			return new HttpResponse( 404, '<!DOCTYPE html><html><body>Not Found</body></html>', array( 'content-type' => 'text/html' ) );
		}
		$segments = explode( '/', substr( $path, strlen( $prefix ) ) );
		$entity   = array_shift( $segments );
		if ( ! isset( self::FIELDS[ $entity ] ) ) {
			return $this->business( 404, 'Endpoint Default/' . self::VERSION . ' does not contain the entity ' . $entity );
		}
		if ( 'POST' === $method ) {
			return $this->action( $entity, (string) ( $segments[0] ?? '' ), $body );
		}
		if ( 'GET' === $method && 'ReleaseSalesInvoice' === ( $segments[0] ?? '' ) && 'status' === ( $segments[1] ?? '' ) ) {
			return $this->actionStatus( (string) ( $segments[2] ?? '' ) );
		}
		if ( 'GET' === $method ) {
			return array() === $segments ? $this->listing( $entity, $query ) : $this->single( $entity, $segments, $query );
		}
		if ( 'PUT' === $method ) {
			return $this->write( $entity, $body, $query );
		}
		return $this->business( 405, 'Method not supported by the fake' );
	}

	/**
	 * The identity server.
	 *
	 * @param array<string,string> $form Form fields.
	 */
	private function token( array $form ): HttpResponse {
		$this->tokenRequests[] = $form;
		if ( $this->refuseClient || self::CLIENT_SECRET !== ( $form['client_secret'] ?? '' ) || self::CLIENT_ID !== ( $form['client_id'] ?? '' ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_client' ), 400 );
		}
		if ( 'refresh_token' !== ( $form['grant_type'] ?? '' ) ) {
			return FakeHttpClient::json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
		$old = (string) ( $form['refresh_token'] ?? '' );
		if ( empty( $this->refreshTokens[ $old ] ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_grant' ), 400 );
		}
		unset( $this->refreshTokens[ $old ] );
		++$this->issued;
		$access                          = 'acu-access-' . $this->issued . '-cccccccc';
		$refresh                         = 'acu-refresh-' . $this->issued . '-dddddddd';
		$this->accessTokens[ $access ]   = true;
		$this->refreshTokens[ $refresh ] = true;
		return FakeHttpClient::json( array( 'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	}

	/**
	 * GET a list.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $query  Query.
	 */
	private function listing( string $entity, array $query ): HttpResponse {
		$problem = $this->checkSelect( $entity, $query );
		if ( null !== $problem ) {
			return $problem;
		}
		$filter = preg_replace( "/datetimeoffset'/", "'", (string) ( $query['$filter'] ?? '' ) );
		$rows   = array();
		foreach ( $this->records[ $entity ] ?? array() as $record ) {
			if ( FakeODataFilter::matches( (string) $filter, $this->flatten( $record ), true ) ) {
				$rows[] = $record;
			}
		}
		$rows = array_slice( $rows, (int) ( $query['$skip'] ?? 0 ), isset( $query['$top'] ) ? (int) $query['$top'] : null );
		return FakeHttpClient::json( array_map( fn( array $record ): array => $this->shape( $record, $query ), $rows ) );
	}

	/**
	 * GET one record by key.
	 *
	 * @param string              $entity   Entity.
	 * @param array<int,string>   $segments Key values.
	 * @param array<string,mixed> $query    Query.
	 */
	private function single( string $entity, array $segments, array $query ): HttpResponse {
		$problem = $this->checkSelect( $entity, $query );
		if ( null !== $problem ) {
			return $problem;
		}
		$record = $this->records[ $entity ][ implode( '/', $segments ) ] ?? null;
		if ( null === $record ) {
			return FakeHttpClient::json( array( 'message' => 'No entity satisfies the condition.' ), 404 );
		}
		return FakeHttpClient::json( $this->shape( $record, $query ) );
	}

	/**
	 * Refuse a `$select` with a field the entity does not have.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $query  Query.
	 */
	private function checkSelect( string $entity, array $query ): ?HttpResponse {
		foreach ( array_filter( explode( ',', (string) ( $query['$select'] ?? '' ) ) ) as $field ) {
			$top = explode( '/', trim( $field ) )[0];
			if ( ! in_array( $top, self::FIELDS[ $entity ], true ) ) {
				return FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => "Could not find the property '" . $top . "' of the entity " . $entity, 'exceptionType' => 'System.InvalidOperationException' ), 500 );
			}
		}
		return null;
	}

	/**
	 * A record as the API answers: expanded, selected and wrapped.
	 *
	 * @param array<string,mixed> $record Plain record.
	 * @param array<string,mixed> $query  Query.
	 * @return array<string,mixed>
	 */
	private function shape( array $record, array $query ): array {
		$expand = array_map( 'trim', explode( ',', (string) ( $query['$expand'] ?? '' ) ) );
		$select = array_filter( array_map( 'trim', explode( ',', (string) ( $query['$select'] ?? '' ) ) ) );
		$out    = array();
		foreach ( $record as $name => $value ) {
			if ( str_starts_with( (string) $name, '_' ) ) {
				continue;
			}
			if ( in_array( $name, array( 'Details', 'Shipments', 'WarehouseDetails', 'Packages' ), true ) && ! in_array( $name, $expand, true ) ) {
				continue;
			}
			if ( 'MainContact' === $name && ! in_array( 'MainContact', $expand, true ) ) {
				continue;
			}
			if ( array() !== $select && 'id' !== $name && ! $this->selected( $name, $select ) ) {
				continue;
			}
			if ( is_array( $value ) && $this->isList( $value ) ) {
				$children = array();
				$only     = $this->nested( $name, $select );
				foreach ( $value as $row ) {
					$row = array_intersect_key( (array) $row, array_flip( array() === $only ? array_keys( (array) $row ) : $only ) );
					$children[] = $this->wrap( $row );
				}
				$out[ $name ] = $children;
				continue;
			}
			if ( is_array( $value ) ) {
				$only         = $this->nested( $name, $select );
				$out[ $name ] = $this->wrap( array() === $only ? $value : array_intersect_key( $value, array_flip( $only ) ) );
				continue;
			}
			$out[ $name ] = 'id' === $name ? $value : array( 'value' => $value );
		}
		return $out;
	}

	/**
	 * Whether a top-level field is selected.
	 *
	 * @param string            $name   Field.
	 * @param array<int,string> $select Select entries.
	 */
	private function selected( string $name, array $select ): bool {
		foreach ( $select as $entry ) {
			if ( explode( '/', $entry )[0] === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The sub-fields selected inside a child.
	 *
	 * @param string            $name   Child name.
	 * @param array<int,string> $select Select entries.
	 * @return array<int,string>
	 */
	private function nested( string $name, array $select ): array {
		$out = array();
		foreach ( $select as $entry ) {
			$bits = explode( '/', $entry );
			if ( $bits[0] === $name && isset( $bits[1] ) ) {
				$out[] = $bits[1];
			}
		}
		return $out;
	}

	/**
	 * Wrap the values of a plain array.
	 *
	 * @param array<string,mixed> $plain Plain fields.
	 * @return array<string,mixed>
	 */
	private function wrap( array $plain ): array {
		$out = array();
		foreach ( $plain as $name => $value ) {
			if ( 'id' === $name ) {
				$out[ $name ] = $value;
			} elseif ( is_array( $value ) && $this->isList( $value ) ) {
				$out[ $name ] = array_map( fn( $row ): array => $this->wrap( (array) $row ), $value );
			} elseif ( is_array( $value ) ) {
				$out[ $name ] = $this->wrap( $value );
			} else {
				$out[ $name ] = array( 'value' => $value );
			}
		}
		return $out;
	}

	/**
	 * Remove the value wrappers of a request body.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return mixed
	 */
	private function unwrap( mixed $node ): mixed {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		if ( array_key_exists( 'value', $node ) ) {
			return $node['value'];
		}
		$out = array();
		foreach ( $node as $name => $child ) {
			$out[ $name ] = $this->unwrap( $child );
		}
		return $out;
	}

	/**
	 * The row a filter sees: top-level values and one level of nested ones as Parent/Child.
	 *
	 * @param array<string,mixed> $record Plain record.
	 * @return array<string,mixed>
	 */
	private function flatten( array $record ): array {
		$row = array();
		foreach ( $record as $name => $value ) {
			if ( is_array( $value ) && ! $this->isList( $value ) ) {
				foreach ( $value as $child => $inner ) {
					if ( ! is_array( $inner ) ) {
						$row[ $name . '/' . $child ] = $inner;
					}
				}
			} elseif ( ! is_array( $value ) ) {
				$row[ $name ] = $value;
			}
		}
		return $row;
	}

	/**
	 * PUT: create or change a record.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $body   Wrapped body.
	 * @param array<string,mixed> $query  Query.
	 */
	private function write( string $entity, array $body, array $query ): HttpResponse {
		$plain = (array) $this->unwrap( $body );
		return match ( $entity ) {
			'Customer'     => $this->writeCustomer( $plain, $body, $query ),
			'SalesOrder'   => $this->writeOrder( $plain, $body, $query ),
			'SalesInvoice' => $this->writeInvoice( $plain, $body, $query ),
			default        => $this->business( 500, 'Error: the fake does not write ' . $entity ),
		};
	}

	/**
	 * A 422 answer: the submitted record with an error on one field.
	 *
	 * @param array<string,mixed> $body  Wrapped body.
	 * @param string              $path  Field path such as CustomerID or Details/0/InventoryID.
	 * @param string              $error Error text.
	 */
	private function refuse( array $body, string $path, string $error ): HttpResponse {
		$node = &$body;
		foreach ( explode( '/', $path ) as $step ) {
			if ( ! isset( $node[ $step ] ) ) {
				$node[ $step ] = array( 'value' => null );
			}
			$node = &$node[ $step ];
		}
		$node['error'] = $error;
		unset( $node );
		return FakeHttpClient::json( $body + array( 'message' => 'See the error field.' ), 422 );
	}

	/**
	 * A 500 business error.
	 *
	 * @param int    $status  Status.
	 * @param string $message Message.
	 */
	private function business( int $status, string $message ): HttpResponse {
		return FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => $message, 'exceptionType' => 'PX.Data.PXException' ), $status );
	}

	/**
	 * PUT Customer.
	 *
	 * @param array<string,mixed> $plain Plain body.
	 * @param array<string,mixed> $body  Wrapped body.
	 * @param array<string,mixed> $query Query.
	 */
	private function writeCustomer( array $plain, array $body, array $query ): HttpResponse {
		if ( '' === (string) ( $plain['CustomerName'] ?? '' ) ) {
			return $this->refuse( $body, 'CustomerName', 'Error: \'Customer Name\' cannot be empty.' );
		}
		$id = (string) ( $plain['CustomerID'] ?? '' );
		if ( '' === $id ) {
			$id = 'C' . str_pad( (string) ( 1 + count( $this->records['Customer'] ?? array() ) ), 6, '0', STR_PAD_LEFT );
		}
		$record = $plain + array( 'Status' => 'Active', 'CurrencyID' => 'AED' );
		unset( $record['CustomerID'] );
		$stored = $this->put( 'Customer', array( 'CustomerID' => $id ) + $record );
		return FakeHttpClient::json( $this->shape( $stored, $query + array( '$expand' => 'MainContact' ) ) );
	}

	/**
	 * PUT SalesOrder.
	 *
	 * @param array<string,mixed> $plain Plain body.
	 * @param array<string,mixed> $body  Wrapped body.
	 * @param array<string,mixed> $query Query.
	 */
	private function writeOrder( array $plain, array $body, array $query ): HttpResponse {
		$type = (string) ( $plain['OrderType'] ?? 'SO' );
		$nbr  = (string) ( $plain['OrderNbr'] ?? '' );
		if ( '' !== $nbr ) {
			$key = $type . '/' . $nbr;
			if ( empty( $this->records['SalesOrder'][ $key ] ) ) {
				return $this->business( 500, 'Error: Sales Order ' . $nbr . ' cannot be found in the system.' );
			}
			$record = $this->records['SalesOrder'][ $key ];
			if ( array_key_exists( 'Hold', $plain ) ) {
				$record['Hold']   = (bool) $plain['Hold'];
				$record['Status'] = $record['Hold'] ? 'Hold' : 'Open';
			}
			$record['LastModified']                  = $this->now . '+00:00';
			$this->records['SalesOrder'][ $key ]     = $record;
			return FakeHttpClient::json( $this->shape( $record, $query ) );
		}
		if ( empty( $this->records['Customer'][ (string) ( $plain['CustomerID'] ?? '' ) ] ) ) {
			return $this->refuse( $body, 'CustomerID', 'Error: Customer cannot be found in the system.' );
		}
		$customer = $this->records['Customer'][ (string) $plain['CustomerID'] ];
		$currency = (string) ( $plain['CurrencyID'] ?? $customer['CurrencyID'] );
		if ( ! empty( $customer['_strictCurrency'] ) && $currency !== (string) $customer['CurrencyID'] ) {
			return $this->business( 500, 'Error: The currency of the document cannot be different from the currency of the customer.' );
		}
		$details = $this->details( $plain, $body, 'OrderQty', 'Details' );
		if ( $details instanceof HttpResponse ) {
			return $details;
		}
		$total  = $this->total( $details, 'OrderQty', $currency );
		$stored = $this->put(
			'SalesOrder',
			array(
				'OrderType'     => $type,
				'OrderNbr'      => str_pad( (string) ( 1 + count( $this->records['SalesOrder'] ?? array() ) ), 6, '0', STR_PAD_LEFT ),
				'CustomerID'    => $plain['CustomerID'],
				'CustomerOrder' => $plain['CustomerOrder'] ?? '',
				'Date'          => $plain['Date'] ?? $this->now,
				'CurrencyID'    => '' !== $this->forceCurrency ? $this->forceCurrency : $currency,
				'Description'   => $plain['Description'] ?? '',
				'Hold'          => ! empty( $plain['Hold'] ),
				'Status'        => ! empty( $plain['Hold'] ) ? 'Hold' : 'Open',
				'OrderTotal'    => $total,
				'Details'       => $details,
				'Shipments'     => array(),
			) + array_intersect_key( $plain, array_flip( array( 'ShipToAddressOverride', 'ShipToAddress' ) ) )
		);
		return FakeHttpClient::json( $this->shape( $stored, $query ) );
	}

	/**
	 * PUT SalesInvoice.
	 *
	 * @param array<string,mixed> $plain Plain body.
	 * @param array<string,mixed> $body  Wrapped body.
	 * @param array<string,mixed> $query Query.
	 */
	private function writeInvoice( array $plain, array $body, array $query ): HttpResponse {
		$type = (string) ( $plain['Type'] ?? 'Invoice' );
		$nbr  = (string) ( $plain['ReferenceNbr'] ?? '' );
		if ( '' !== $nbr ) {
			$key = $type . '/' . $nbr;
			if ( empty( $this->records['SalesInvoice'][ $key ] ) ) {
				return $this->business( 500, 'Error: Document ' . $nbr . ' cannot be found in the system.' );
			}
			$record = $this->records['SalesInvoice'][ $key ];
			if ( array_key_exists( 'Hold', $plain ) ) {
				$record['Hold']   = (bool) $plain['Hold'];
				$record['Status'] = $record['Hold'] ? 'Hold' : 'Balanced';
			}
			$this->records['SalesInvoice'][ $key ] = $record;
			return FakeHttpClient::json( $this->shape( $record, $query ) );
		}
		if ( empty( $this->records['Customer'][ (string) ( $plain['CustomerID'] ?? '' ) ] ) ) {
			return $this->refuse( $body, 'CustomerID', 'Error: Customer cannot be found in the system.' );
		}
		$details = $this->details( $plain, $body, 'Qty', 'Details' );
		if ( $details instanceof HttpResponse ) {
			return $details;
		}
		foreach ( $details as $index => $row ) {
			if ( '' !== (string) ( $row['OrigInvNbr'] ?? '' ) && empty( $this->records['SalesInvoice'][ 'Invoice/' . $row['OrigInvNbr'] ] ) ) {
				return $this->refuse( $body, 'Details/' . $index . '/OrigInvNbr', 'Error: Original document cannot be found.' );
			}
		}
		$number = 'CM' . str_pad( (string) ( 1 + $this->sequence++ ), 6, '0', STR_PAD_LEFT );
		$stored = $this->put(
			'SalesInvoice',
			array(
				'Type'         => $type,
				'ReferenceNbr' => $number,
				'CustomerID'   => $plain['CustomerID'],
				'Date'         => $plain['Date'] ?? $this->now,
				'Currency'     => $plain['Currency'] ?? 'AED',
				'Description'  => $plain['Description'] ?? '',
				'Hold'         => ! empty( $plain['Hold'] ),
				'Status'       => ! empty( $plain['Hold'] ) ? 'Hold' : 'Balanced',
				'Amount'       => $this->total( $details, 'Qty', (string) ( $plain['Currency'] ?? 'AED' ) ),
				'Details'      => $details,
			)
		);
		return FakeHttpClient::json( $this->shape( $stored, $query ) );
	}

	/**
	 * The detail rows of a document, checked.
	 *
	 * @param array<string,mixed> $plain    Plain body.
	 * @param array<string,mixed> $body     Wrapped body.
	 * @param string              $quantity Name of the quantity field.
	 * @param string              $key      Name of the detail list.
	 * @return array<int,array<string,mixed>>|HttpResponse
	 */
	private function details( array $plain, array $body, string $quantity, string $key ): array|HttpResponse {
		$rows = array();
		foreach ( (array) ( $plain[ $key ] ?? array() ) as $index => $row ) {
			$row = (array) $row;
			$id  = (string) ( $row['InventoryID'] ?? '' );
			if ( '' !== $id ) {
				$item = $this->records['StockItem'][ $id ] ?? ( $this->records['NonStockItem'][ $id ] ?? null );
				if ( null === $item ) {
					return $this->refuse( $body, $key . '/' . $index . '/InventoryID', "Error: Inventory item '" . $id . "' cannot be found in the system." );
				}
				if ( 'Active' !== $item['ItemStatus'] ) {
					return $this->refuse( $body, $key . '/' . $index . '/InventoryID', 'Error: Inventory item is not active.' );
				}
			}
			$category = (string) ( $row['TaxCategory'] ?? '' );
			if ( '' !== $category && ! isset( $this->rates[ $category ] ) ) {
				return $this->refuse( $body, $key . '/' . $index . '/TaxCategory', 'Error: Tax category cannot be found.' );
			}
			$row['id'] = $this->guid();
			$rows[]    = $row;
		}
		unset( $quantity );
		return $rows;
	}

	/**
	 * The document total: lines plus the tax of their categories, rounded per line to two decimals.
	 *
	 * @param array<int,array<string,mixed>> $details  Detail rows.
	 * @param string                         $quantity Name of the quantity field.
	 * @param string                         $currency Currency, which sets the decimals.
	 */
	private function total( array $details, string $quantity, string $currency ): float {
		$places = match ( $currency ) {
			'KWD', 'BHD', 'OMR' => 3,
			'JPY'               => 0,
			default             => 2,
		};
		$total  = 0.0;
		foreach ( $details as $row ) {
			$extended = round( (float) ( $row[ $quantity ] ?? 0 ) * (float) ( $row['UnitPrice'] ?? 0 ), $places );
			$rate     = $this->rates[ (string) ( $row['TaxCategory'] ?? '' ) ] ?? 0.0;
			$total   += $extended + round( $extended * $rate / 100, $places );
		}
		return round( $total, $places );
	}

	/**
	 * POST an action.
	 *
	 * @param string              $entity Entity.
	 * @param string              $name   Action name.
	 * @param array<string,mixed> $body   Body.
	 */
	private function action( string $entity, string $name, array $body ): HttpResponse {
		if ( 'SalesInvoice' !== $entity || 'ReleaseSalesInvoice' !== $name ) {
			return $this->business( 404, 'The action ' . $name . ' does not exist.' );
		}
		$record = (array) $this->unwrap( $body['entity'] ?? array() );
		$key    = (string) ( $record['Type'] ?? '' ) . '/' . (string) ( $record['ReferenceNbr'] ?? '' );
		if ( empty( $this->records['SalesInvoice'][ $key ] ) ) {
			return $this->business( 500, 'Error: Document cannot be found in the system.' );
		}
		if ( 'Balanced' !== $this->records['SalesInvoice'][ $key ]['Status'] ) {
			return $this->business( 500, 'Error: The document status is invalid for processing.' );
		}
		$token                   = 'run' . ( ++$this->issued );
		$this->actions[ $token ] = array( 'polls' => $this->releaseDelay, 'key' => $key );
		return new HttpResponse( 202, '', array( 'location' => '/entity/Default/' . self::VERSION . '/SalesInvoice/ReleaseSalesInvoice/status/' . $token ) );
	}

	/**
	 * GET the status of a running action.
	 *
	 * @param string $token Action token.
	 */
	private function actionStatus( string $token ): HttpResponse {
		if ( ! isset( $this->actions[ $token ] ) ) {
			return $this->business( 404, 'No such operation.' );
		}
		if ( $this->actions[ $token ]['polls'] > 0 ) {
			--$this->actions[ $token ]['polls'];
			return new HttpResponse( 202, '', array() );
		}
		$this->records['SalesInvoice'][ $this->actions[ $token ]['key'] ]['Status'] = 'Open';
		unset( $this->actions[ $token ] );
		return new HttpResponse( 204, '', array() );
	}

	/**
	 * The key text of a record.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $record Record.
	 */
	private function key( string $entity, array $record ): string {
		return implode( '/', array_map( static fn( string $field ): string => (string) ( $record[ $field ] ?? '' ), self::KEYS[ $entity ] ) );
	}

	/**
	 * Whether an array is a list.
	 *
	 * @param array<mixed> $value Array.
	 */
	private function isList( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * A GUID.
	 */
	private function guid(): string {
		return sprintf( '%08x-0000-4000-8000-%012x', ++$this->issued, $this->issued * 7919 );
	}
}
