<?php
/**
 * A small in-memory SAP Business One Service Layer for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Behaviour follows the pages listed in docs/connectors/sap-b1.md: `POST /Login` with CompanyDB, UserName and Password answering a
 * SessionId and the B1SESSION and ROUTEID cookies; a session that has ended answers 401; lists with `$filter`, `$select`, `$orderby`,
 * `$top`, 20 rows per page unless `Prefer: odata.maxpagesize` says otherwise, and a next link; errors as {"error":{"code","message":{"lang","value"}}}.
 * It models on purpose what once hid bugs elsewhere: a tax that SAP adds to the price, a customer with a card code that already exists,
 * a foreign-currency document whose total is in DocTotalFc, and a session that ends between two requests.
 */
final class FakeSapB1Server {

	/**
	 * The client the connector is built on.
	 */
	public FakeHttpClient $http;

	/**
	 * Address as the merchant types it.
	 */
	public string $address = 'https://sap.example.test:50000';

	/**
	 * Company database.
	 */
	public string $database = 'SBO_TEST';

	/**
	 * User and password.
	 */
	public string $user = 'webshop';

	/**
	 * Password.
	 */
	public string $password = 'p4ss-w0rd-test';

	/**
	 * The company's local currency.
	 */
	public string $localCurrency = 'AED';

	/**
	 * OData version: v1 or v2.
	 */
	public string $version = 'v2';

	/**
	 * When set, documents are saved in this currency whatever was asked.
	 */
	public ?string $forceCurrency = null;

	/**
	 * Rate of the one tax code (percent) added to the net lines that carry it.
	 *
	 * @var array<string,float>
	 */
	public array $vatRates = array( 'S1' => 5.0 );

	/**
	 * Local currency per unit of a foreign currency.
	 *
	 * @var array<string,float>
	 */
	public array $fx = array( 'USD' => 3.6725 );

	/**
	 * Entities by name then by key.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $data = array(
		'BusinessPartners' => array(),
		'Items'            => array(),
		'Orders'           => array(),
		'Invoices'         => array(),
		'CreditNotes'      => array(),
		'Warehouses'       => array(),
		'VatGroups'        => array(),
	);

	/**
	 * Active session ids.
	 *
	 * @var array<string,bool>
	 */
	public array $sessions = array();

	/**
	 * Number of logins.
	 */
	public int $logins = 0;

	/**
	 * Answers scripted for the next write calls.
	 *
	 * @var array<int,HttpResponse>
	 */
	private array $failures = array();

	/**
	 * Next DocEntry per entity.
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
		$this->addItem( 'CS-BOWL-02', 'Stoneware bowl set', array( 'WH1' => array( 14, 2 ), 'WH2' => array( 5, 0 ) ) );
		$this->addItem( 'CS-MUG-01', 'Ceramic mug, sand', array( 'WH1' => array( 42, 4 ) ) );
		$this->data['Warehouses']['WH1'] = array( 'WarehouseCode' => 'WH1', 'WarehouseName' => 'Dubai', 'Inactive' => 'tNO' );
		$this->data['Warehouses']['WH2'] = array( 'WarehouseCode' => 'WH2', 'WarehouseName' => 'Old store', 'Inactive' => 'tNO' );
		$this->data['VatGroups']['S1']   = array( 'Code' => 'S1', 'Name' => 'Output VAT 5%', 'Category' => 'bovcOutputTax', 'Rate' => 5.0, 'Inactive' => 'tNO' );
		$this->data['VatGroups']['X0']   = array( 'Code' => 'X0', 'Name' => 'Exempt', 'Category' => 'bovcOutputTax', 'Rate' => 0.0, 'Inactive' => 'tNO' );
		$this->data['VatGroups']['I1']   = array( 'Code' => 'I1', 'Name' => 'Input VAT', 'Category' => 'bovcInputTax', 'Rate' => 5.0, 'Inactive' => 'tNO' );
	}

	/**
	 * The host name of the address.
	 */
	public function host(): string {
		return (string) wp_parse_url( $this->address, PHP_URL_HOST );
	}

	/**
	 * The base path of the service.
	 */
	public function base(): string {
		return '/b1s/' . $this->version . '/';
	}

	/**
	 * Add an item with stock per warehouse.
	 *
	 * @param string                       $code  Item code.
	 * @param string                       $name  Name.
	 * @param array<string,array<int,int>> $stock Warehouse => [in stock, committed].
	 */
	public function addItem( string $code, string $name, array $stock = array() ): void {
		$collection = array();
		foreach ( $stock as $warehouse => $figures ) {
			$collection[] = array(
				'WarehouseCode' => $warehouse,
				'InStock'       => (float) $figures[0],
				'Committed'     => (float) $figures[1],
				'Ordered'       => 0.0,
			);
		}
		$this->data['Items'][ $code ] = array(
			'ItemCode'                    => $code,
			'ItemName'                    => $name,
			'Frozen'                      => 'tNO',
			'ItemWarehouseInfoCollection' => $collection,
		);
	}

	/**
	 * Add a customer.
	 *
	 * @param string $code  Card code.
	 * @param string $name  Name.
	 * @param string $email Email.
	 */
	public function addCustomer( string $code, string $name, string $email ): void {
		$this->data['BusinessPartners'][ $code ] = array(
			'CardCode'     => $code,
			'CardName'     => $name,
			'CardType'     => 'cCustomer',
			'EmailAddress' => $email,
			'Frozen'       => 'tNO',
		);
	}

	/**
	 * End every session (the idle time ran out).
	 */
	public function expireSessions(): void {
		$this->sessions = array();
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
	 * Documents of an entity.
	 *
	 * @param string $entity Orders, Invoices or CreditNotes.
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
		if ( strtolower( (string) ( $parts['host'] ?? '' ) ) !== $this->host() || ! str_starts_with( (string) ( $parts['path'] ?? '' ), $this->base() ) ) {
			return $this->error( 404, 0, 'Unknown service.' );
		}
		$path = substr( (string) $parts['path'], strlen( $this->base() ) );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( 'Login' === $path ) {
			return $this->login( $request );
		}
		if ( ! $this->sessionValid( $request ) ) {
			return $this->error( 401, 301, 'Invalid session.' );
		}
		if ( 'GET' !== $request['method'] && array() !== $this->failures ) {
			return array_shift( $this->failures );
		}
		if ( 1 === preg_match( '#^(\w+)\((.+)\)$#', $path, $m ) ) {
			return 'GET' === $request['method'] ? $this->one( $m[1], rawurldecode( $m[2] ), $query ) : $this->error( 400, -1, 'Unsupported.' );
		}
		if ( 'GET' === $request['method'] ) {
			return $this->listing( $path, $query, $request );
		}
		if ( 'POST' === $request['method'] ) {
			return $this->create( $path, json_decode( (string) $request['body'], true ) ?: array() );
		}
		return $this->error( 400, -1, 'Unsupported.' );
	}

	/**
	 * Login.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function login( array $request ): HttpResponse {
		$body = json_decode( (string) $request['body'], true );
		$body = is_array( $body ) ? $body : array();
		if ( ($body['CompanyDB'] ?? '') !== $this->database ) {
			return $this->error( 401, -1000, 'Company database was not found.' );
		}
		if ( ( $body['UserName'] ?? '' ) !== $this->user || ( $body['Password'] ?? '' ) !== $this->password ) {
			return $this->error( 401, -2, 'Invalid user name or password.' );
		}
		++$this->logins;
		$id                    = 'sess-' . bin2hex( random_bytes( 6 ) );
		$this->sessions[ $id ] = true;
		return FakeHttpClient::json(
			array(
				'SessionId'      => $id,
				'Version'        => '1000160',
				'SessionTimeout' => 30,
			),
			200,
			array( 'set-cookie' => 'B1SESSION=' . $id . '; path=/; HttpOnly, ROUTEID=.node1; path=/' )
		);
	}

	/**
	 * Whether the request carries a live session cookie.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function sessionValid( array $request ): bool {
		foreach ( (array) $request['headers'] as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'cookie' ) && 1 === preg_match( '/B1SESSION=([^;]+)/', (string) $value, $m ) ) {
				return isset( $this->sessions[ $m[1] ] );
			}
		}
		return false;
	}

	/**
	 * A list with filter, select, order and paging.
	 *
	 * @param string              $entity  Entity.
	 * @param array<string,mixed> $query   Query.
	 * @param array<string,mixed> $request Request.
	 */
	private function listing( string $entity, array $query, array $request ): HttpResponse {
		if ( ! isset( $this->data[ $entity ] ) ) {
			return $this->error( 404, -1, 'Unknown entity ' . $entity );
		}
		$insensitive = 'true' === strtolower( $this->header( $request, 'b1s-caseinsensitive' ) );
		$rows        = array();
		foreach ( $this->data[ $entity ] as $row ) {
			if ( FakeODataFilter::matches( (string) ( $query['$filter'] ?? '' ), $row, $insensitive ) ) {
				$rows[] = $row;
			}
		}
		$order = (string) ( $query['$orderby'] ?? '' );
		if ( '' !== $order ) {
			usort( $rows, static fn( array $a, array $b ): int => ( $a[ $order ] ?? 0 ) <=> ( $b[ $order ] ?? 0 ) );
		}
		$skip = (int) ( $query['$skip'] ?? 0 );
		$page = 20;
		if ( 1 === preg_match( '/odata\.maxpagesize=(\d+)/', $this->header( $request, 'prefer' ), $m ) ) {
			$page = max( 1, (int) $m[1] );
		}
		if ( isset( $query['$top'] ) ) {
			$page = min( $page, max( 1, (int) $query['$top'] ) );
		}
		$slice = array_slice( $rows, $skip, $page );
		$body  = array( ( 'v1' === $this->version ? 'odata.metadata' : '@odata.context' ) => '$metadata#' . $entity );
		$body['value'] = array_map( fn( array $row ): array => $this->select( $row, (string) ( $query['$select'] ?? '' ) ), $slice );
		if ( $skip + $page < count( $rows ) && ! isset( $query['$top'] ) ) {
			$next                                                        = $query;
			$next['$skip']                                               = $skip + $page;
			$body[ 'v1' === $this->version ? 'odata.nextLink' : '@odata.nextLink' ] = $entity . '?' . http_build_query( $next, '', '&', PHP_QUERY_RFC3986 );
		}
		return FakeHttpClient::json( $body );
	}

	/**
	 * One entity by key.
	 *
	 * @param string              $entity Entity.
	 * @param string              $key    Key text (a number, or a quoted string).
	 * @param array<string,mixed> $query  Query.
	 */
	private function one( string $entity, string $key, array $query ): HttpResponse {
		$key = str_starts_with( $key, "'" ) ? str_replace( "''", "'", substr( $key, 1, -1 ) ) : $key;
		$row = $this->data[ $entity ][ $key ] ?? null;
		return null === $row ? $this->error( 404, -2028, 'No matching records found (ODBC -2028)' ) : FakeHttpClient::json( $this->select( $row, (string) ( $query['$select'] ?? '' ) ) );
	}

	/**
	 * Create a record.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $body   Body.
	 */
	private function create( string $entity, array $body ): HttpResponse {
		if ( 'BusinessPartners' === $entity ) {
			$code = (string) ( $body['CardCode'] ?? 'C' . $this->sequence( $entity ) );
			if ( isset( $this->data[ $entity ][ $code ] ) ) {
				return $this->error( 400, -10, 'This entry already exists in the following tables (ODBC -2035)' );
			}
			$this->data[ $entity ][ $code ] = $body + array( 'CardCode' => $code, 'Frozen' => 'tNO' );
			return FakeHttpClient::json( $this->data[ $entity ][ $code ], 201 );
		}
		if ( ! in_array( $entity, array( 'Orders', 'Invoices', 'CreditNotes' ), true ) ) {
			return $this->error( 404, -1, 'Unknown entity ' . $entity );
		}
		return $this->document( $entity, $body );
	}

	/**
	 * Create a marketing document.
	 *
	 * @param string              $entity Orders, Invoices or CreditNotes.
	 * @param array<string,mixed> $body   Body.
	 */
	private function document( string $entity, array $body ): HttpResponse {
		$card = (string) ( $body['CardCode'] ?? '' );
		if ( ! isset( $this->data['BusinessPartners'][ $card ] ) ) {
			return $this->error( 400, -1, 'Business partner ' . $card . ' does not exist.' );
		}
		$currency = $this->forceCurrency ?? (string) ( $body['DocCurrency'] ?? $this->localCurrency );
		$net      = 0;
		$tax      = 0;
		$lines    = array();
		foreach ( (array) ( $body['DocumentLines'] ?? array() ) as $index => $line ) {
			if ( isset( $line['BaseEntry'] ) ) {
				$base = $this->data['Orders'][ (string) $line['BaseEntry'] ]['DocumentLines'][ (int) ( $line['BaseLine'] ?? 0 ) ] ?? null;
				if ( null === $base ) {
					return $this->error( 400, -1, 'Base document line not found.' );
				}
				$line = $base;
			}
			$code = (string) ( $line['ItemCode'] ?? '' );
			if ( ! isset( $this->data['Items'][ $code ] ) ) {
				return $this->error( 400, -1, 'Item ' . $code . ' does not exist.' );
			}
			$quantity = (string) ( $line['Quantity'] ?? 1 );
			if ( isset( $line['LineTotal'] ) ) {
				$lineNet = (int) $this->amounts->fromWire( $line['LineTotal'], $currency );
			} else {
				$unit    = isset( $line['PriceAfterVAT'] ) ? (int) $this->amounts->fromWire( $line['PriceAfterVAT'], $currency ) : (int) $this->amounts->fromWire( $line['UnitPrice'] ?? 0, $currency );
				$lineNet = $this->amounts->lineTotal( $unit, $quantity );
			}
			$group = (string) ( $line['VatGroup'] ?? ( $line['TaxCode'] ?? '' ) );
			$rate  = $this->vatRates[ $group ] ?? 0.0;
			$net  += $lineNet;
			$tax  += (int) round( $lineNet * $rate / 100 );
			$lines[] = $line + array( 'LineNum' => $index );
		}
		$total = $net + $tax;
		$entry = $this->sequence( $entity );
		$doc   = array(
			'DocEntry'       => $entry,
			'DocNum'         => 1000 + $entry,
			'NumAtCard'      => (string) ( $body['NumAtCard'] ?? '' ),
			'CardCode'       => $card,
			'DocDate'        => (string) ( $body['DocDate'] ?? '2026-11-02' ),
			'DocCurrency'    => $currency,
			'DocumentStatus' => 'bost_Open',
			'Cancelled'      => 'tNO',
			'Comments'       => (string) ( $body['Comments'] ?? '' ),
			'PaidToDate'     => 0.0,
			'DocumentLines'  => $lines,
		) + array_diff_key( $body, array( 'DocumentLines' => 1 ) );
		if ( $currency === $this->localCurrency ) {
			$doc['DocTotal']   = $this->amounts->toNumber( $total, $currency );
			$doc['DocTotalFc'] = 0.0;
		} else {
			$doc['DocTotalFc'] = $this->amounts->toNumber( $total, $currency );
			$doc['DocTotal']   = round( $total / 100 * ( $this->fx[ $currency ] ?? 1.0 ), 2 );
		}
		$this->data[ $entity ][ (string) $entry ] = $doc;
		return FakeHttpClient::json( $doc, 201 );
	}

	/**
	 * Next sequence number of an entity.
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
		$out = array();
		foreach ( explode( ',', $select ) as $name ) {
			if ( array_key_exists( $name, $row ) ) {
				$out[ $name ] = $row[ $name ];
			}
		}
		return $out;
	}

	/**
	 * An error body in the Service Layer's shape.
	 *
	 * @param int    $status HTTP status.
	 * @param int    $code   error.code.
	 * @param string $text   error.message.value.
	 */
	private function error( int $status, int $code, string $text ): HttpResponse {
		return FakeHttpClient::json(
			array(
				'error' => array(
					'code'    => $code,
					'message' => array(
						'lang'  => 'en-us',
						'value' => $text,
					),
				),
			),
			$status
		);
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
