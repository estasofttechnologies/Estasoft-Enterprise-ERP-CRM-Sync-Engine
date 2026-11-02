<?php
/**
 * A small in-memory Sage 300 Web API for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Sage 300 connector makes the way the Web API documents them: `{"value": [...]}` lists, keys written
 * `ARCustomers('1200')`, `$metadata` per module, error bodies `{"error": {"code": ..., "message": {"lang": "en", "value": "..."}}}`, Basic
 * authentication on every request, HTTP 500 without a JSON content type, a page of 100 rows. It models on purpose what once hides bugs: an
 * unknown property in a payload is refused, an order takes the currency of its customer, the order total comes from the lines and the tax
 * group, and the property names of an order can be changed per test. It does not model everything Sage 300 checks. All data is synthetic.
 */
final class FakeSage300Server {

	public const ADDRESS = 'https://sage.example.test/Sage300WebApi';

	public const COMPANY = 'SAMLTD';

	public const USER = 'SYNCBOT';

	public const PASSWORD = 'pw-5e9c1d7a3b';

	/**
	 * Records by module and resource.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $records = array();

	/**
	 * Every call: method, path, query, body, headers.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $calls = array();

	/**
	 * Properties of an order the server describes.
	 *
	 * @var array<int,string>
	 */
	public array $orderProps = array( 'OrderUniquifier', 'OrderNumber', 'CustomerNumber', 'OrderDate', 'PONumber', 'Reference', 'OrderDescription', 'TaxGroup', 'OrderTotal' );

	/**
	 * Properties of an order line the server describes.
	 *
	 * @var array<int,string>
	 */
	public array $detailProps = array( 'LineType', 'Item', 'Location', 'QuantityOrdered', 'UnitPrice', 'MiscellaneousChargesCode', 'ExtendedAmount' );

	/**
	 * Properties of a customer the server describes.
	 *
	 * @var array<int,string>
	 */
	public array $customerProps = array( 'CustomerNumber', 'CustomerName', 'CurrencyCode', 'EmailAddress' );

	/**
	 * Whether `$metadata` answers (an old release may not).
	 */
	public bool $metadataAvailable = true;

	/**
	 * Whether the order answer holds the total.
	 */
	public bool $totalInAnswer = true;

	/**
	 * Whether the server refuses `startswith` (InvalidParameters).
	 */
	public bool $rejectStartswith = false;

	/**
	 * Resources the installed version does not have.
	 *
	 * @var array<int,string>
	 */
	public array $missingResources = array();

	/**
	 * Tax rate in percent by tax group key.
	 *
	 * @var array<string,float>
	 */
	public array $rates = array(
		'UAEVAT' => 5.0,
		'NOTAX'  => 0.0,
	);

	/**
	 * Scripted answers: method, pattern, answer.
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse|callable}>
	 */
	private array $failures = array();

	/**
	 * Calls whose answer is lost after the work is done: method, pattern, calls to let pass first.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private array $lost = array();

	private int $next = 1000;

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
	 * Make the next write fail the way a failure kind says.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, certificate or auth.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$response = match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $seconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 503, 'Service unavailable, ' . self::PASSWORD ),
			'certificate'  => FakeHttpClient::certificateError(),
			'auth'         => $this->error( 401, 'Unauthorized', 'Invalid credentials' ),
			default        => $this->error( 500, 'General', 'boom ' . self::PASSWORD ),
		};
		$this->failNext( 'POST', '#/OE/OEOrders$#', $response );
	}

	/**
	 * Let the next matching call do its work and then time out.
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
	 * Records of a resource, such as `OE/OEOrders`.
	 *
	 * @param string $resource Module and resource.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $resource ): array {
		return array_values( $this->records[ $resource ] ?? array() );
	}

	/**
	 * Add a record directly.
	 *
	 * @param string              $resource Module and resource.
	 * @param array<string,mixed> $record   Fields.
	 * @return array<string,mixed>
	 */
	public function put( string $resource, array $record ): array {
		$this->records[ $resource ][] = $record;
		return $record;
	}

	/**
	 * Master data of a small shop.
	 */
	private function seed(): void {
		$this->put( 'AR/ARCustomers', array( 'CustomerNumber' => '1200', 'CustomerName' => 'Web shop customers', 'CurrencyCode' => 'AED', 'EmailAddress' => 'web@example.test' ) );
		$this->put( 'AR/ARCustomers', array( 'CustomerNumber' => '1300', 'CustomerName' => 'Noor Al-Mansouri', 'CurrencyCode' => 'AED', 'EmailAddress' => 'noor@example.test' ) );
		$this->put( 'AR/ARCustomers', array( 'CustomerNumber' => '1400', 'CustomerName' => 'Dollar customer', 'CurrencyCode' => 'USD', 'EmailAddress' => 'usd@example.test' ) );
		foreach ( array( 'CS-MUG-01', 'CS-BOWL-02' ) as $sku ) {
			$this->put( 'IC/ICItems', array( 'ItemNumber' => $sku, 'Description' => 'Item ' . $sku, 'StockingUnitOfMeasure' => 'EA' ) );
		}
		$this->put( 'IC/ICLocations', array( 'Location' => 'MAIN', 'Description' => 'Main warehouse' ) );
		$this->put( 'IC/ICLocations', array( 'Location' => 'SHOW', 'Description' => 'Showroom' ) );
		$this->put( 'TX/TXTaxGroups', array( 'TaxGroupKey' => 'UAEVAT', 'Description' => 'UAE VAT 5%' ) );
		$this->put( 'TX/TXTaxGroups', array( 'TaxGroupKey' => 'NOTAX', 'Description' => 'No tax' ) );
		$this->records['OE/OEOrders']           = array();
		$this->records['OE/OECreditDebitNotes'] = array();
	}

	/**
	 * Answer one request, then lose the answer when a test asked for it.
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
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = (string) $request['method'];
		$body   = null === $request['body'] ? array() : (array) json_decode( (string) $request['body'], true );
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'headers' => (array) $request['headers'] );

		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path ) ) {
				unset( $this->failures[ $index ] );
				return $failure[2] instanceof HttpResponse ? $failure[2] : ( $failure[2] )( $request );
			}
		}
		$prefix = '/Sage300WebApi/v1.0/-/' . self::COMPANY . '/';
		if ( 'sage.example.test' !== ( $parts['host'] ?? '' ) || ! str_starts_with( $path, '/Sage300WebApi/v1.0/-/' ) ) {
			return new HttpResponse( 404, '<!DOCTYPE html><html><body>Not Found</body></html>', array( 'content-type' => 'text/html' ) );
		}
		$headers = array_change_key_case( (array) $request['headers'], CASE_LOWER );
		if ( 'Basic ' . base64_encode( self::USER . ':' . self::PASSWORD ) !== ( $headers['authorization'] ?? '' ) ) {
			return $this->error( 401, 'Unauthorized', 'Invalid user name or password.' );
		}
		if ( ! str_contains( strtolower( (string) ( $headers['content-type'] ?? '' ) ), 'application/json' ) ) {
			return $this->error( 500, 'InternalServerError', 'Content type not supported.' );
		}
		if ( ! str_starts_with( $path, $prefix ) ) {
			return $this->error( 404, 'ResourceNotFound', 'Company not found.' );
		}
		$rest = substr( $path, strlen( $prefix ) );
		if ( 1 === preg_match( '#^(\w+)/\$metadata$#', $rest, $found ) ) {
			return $this->metadata( $found[1] );
		}
		if ( 1 !== preg_match( "#^(\\w+)/(\\w+)(?:\\('((?:[^']|'')*)'\\))?$#", $rest, $found ) ) {
			return $this->error( 404, 'ResourceNotFound', 'Unknown resource.' );
		}
		$resource = $found[1] . '/' . $found[2];
		if ( ! isset( $this->records[ $resource ] ) || in_array( $resource, $this->missingResources, true ) ) {
			return $this->error( 404, 'ResourceNotFound', 'The resource ' . $resource . ' is not available.' );
		}
		if ( 'GET' === $method ) {
			return isset( $found[3] ) ? $this->single( $resource, str_replace( "''", "'", $found[3] ) ) : $this->listing( $resource, $query );
		}
		if ( 'POST' === $method && ! isset( $found[3] ) ) {
			return $this->create( $resource, $body );
		}
		return $this->error( 405, 'MethodNotAllowed', 'Not allowed.' );
	}

	/**
	 * An error body in the shape of the Web API.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private function error( int $status, string $code, string $message ): HttpResponse {
		return FakeHttpClient::json( array( 'error' => array( 'code' => $code, 'message' => array( 'lang' => 'en', 'value' => $message ) ) ), $status );
	}

	/**
	 * The `$metadata` of a module.
	 *
	 * @param string $module Module.
	 */
	private function metadata( string $module ): HttpResponse {
		if ( ! $this->metadataAvailable ) {
			return $this->error( 404, 'ResourceNotFound', 'No metadata.' );
		}
		$property = static fn( array $names ): string => implode( '', array_map( static fn( string $name ): string => '<Property Name="' . $name . '" Type="Edm.String"/>', $names ) );
		$xml      = '<?xml version="1.0" encoding="utf-8"?><edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema Namespace="Sage.' . $module . '" xmlns="http://docs.oasis-open.org/odata/ns/edm">';
		if ( 'OE' === $module ) {
			$xml .= '<EntityType Name="OEOrder">' . $property( $this->orderProps ) . '<NavigationProperty Name="OrderDetails" Type="Collection(Sage.OE.OEOrderDetail)"/></EntityType>';
			$xml .= '<EntityType Name="OEOrderDetail">' . $property( $this->detailProps ) . '</EntityType>';
			$xml .= '<EntityContainer Name="C"><EntitySet Name="OEOrders" EntityType="Sage.OE.OEOrder"/></EntityContainer>';
		} elseif ( 'AR' === $module ) {
			$xml .= '<EntityType Name="ARCustomer">' . $property( $this->customerProps ) . '</EntityType><EntityContainer Name="C"><EntitySet Name="ARCustomers" EntityType="Sage.AR.ARCustomer"/></EntityContainer>';
		}
		return new HttpResponse( 200, $xml . '</Schema></edmx:DataServices></edmx:Edmx>', array( 'content-type' => 'application/xml' ) );
	}

	/**
	 * GET a list.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $query    Query.
	 */
	private function listing( string $resource, array $query ): HttpResponse {
		$filter = (string) ( $query['$filter'] ?? '' );
		if ( $this->rejectStartswith && str_contains( strtolower( $filter ), 'startswith' ) ) {
			return $this->error( 400, 'InvalidParameters', 'startswith is not supported here.' );
		}
		$rows = array();
		foreach ( $this->records[ $resource ] ?? array() as $record ) {
			if ( FakeODataFilter::matches( $filter, array_filter( $record, static fn( mixed $value ): bool => ! is_array( $value ) ), true ) ) {
				$rows[] = $record;
			}
		}
		$top  = min( 100, (int) ( $query['$top'] ?? 100 ) );
		$page = array_slice( $rows, (int) ( $query['$skip'] ?? 0 ), $top );
		return FakeHttpClient::json( array( '@odata.context' => 'x', 'value' => $page ) );
	}

	/**
	 * GET one record by key.
	 *
	 * @param string $resource Resource.
	 * @param string $key      Key value.
	 */
	private function single( string $resource, string $key ): HttpResponse {
		$field = array(
			'AR/ARCustomers' => 'CustomerNumber',
			'IC/ICItems'     => 'ItemNumber',
			'IC/ICLocations' => 'Location',
			'TX/TXTaxGroups' => 'TaxGroupKey',
		)[ $resource ] ?? 'Reference';
		foreach ( $this->records[ $resource ] ?? array() as $record ) {
			if ( strtolower( (string) ( $record[ $field ] ?? '' ) ) === strtolower( $key ) ) {
				return FakeHttpClient::json( $record );
			}
		}
		return $this->error( 404, 'RecordNotFound', 'The record ' . $key . ' was not found.' );
	}

	/**
	 * POST a record.
	 *
	 * @param string              $resource Resource.
	 * @param array<string,mixed> $body     Body.
	 */
	private function create( string $resource, array $body ): HttpResponse {
		if ( 'OE/OEOrders' === $resource ) {
			return $this->createOrder( $body );
		}
		if ( 'OE/OECreditDebitNotes' === $resource ) {
			return $this->createNote( $body );
		}
		return $this->error( 405, 'MethodNotAllowed', 'Not allowed.' );
	}

	/**
	 * POST OEOrders.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createOrder( array $body ): HttpResponse {
		foreach ( array_keys( $body ) as $name ) {
			if ( 'OrderDetails' !== $name && ! in_array( $name, $this->orderProps, true ) ) {
				return $this->error( 400, 'InvalidPayload', "The property '" . $name . "' does not exist on the order." );
			}
		}
		$customer = $this->customer( (string) ( $body['CustomerNumber'] ?? '' ) );
		if ( null === $customer ) {
			return $this->error( 400, 'RecordInvalid', 'Customer number is not valid.' );
		}
		$rate = 0.0;
		if ( isset( $body['TaxGroup'] ) ) {
			if ( ! isset( $this->rates[ (string) $body['TaxGroup'] ] ) ) {
				return $this->error( 400, 'RecordInvalid', 'Tax group is not valid.' );
			}
			$rate = $this->rates[ (string) $body['TaxGroup'] ];
		}
		$net = 0.0;
		foreach ( (array) ( $body['OrderDetails'] ?? array() ) as $index => $line ) {
			$line = (array) $line;
			foreach ( array_keys( $line ) as $name ) {
				if ( ! in_array( $name, $this->detailProps, true ) ) {
					return $this->error( 400, 'InvalidPayload', "The property '" . $name . "' does not exist on the order line " . ( $index + 1 ) . '.' );
				}
			}
			if ( 'Item' === ( $line['LineType'] ?? '' ) ) {
				if ( null === $this->find( 'IC/ICItems', 'ItemNumber', (string) ( $line['Item'] ?? '' ) ) ) {
					return $this->error( 400, 'RecordInvalid', 'Item is not valid.' );
				}
				$net += round( (float) $line['QuantityOrdered'] * (float) ( $line['UnitPrice'] ?? 0 ), 2 );
			} else {
				$net += (float) ( $line['ExtendedAmount'] ?? 0 );
			}
		}
		$record = array(
			'OrderUniquifier' => (string) ( ++$this->next ),
			'OrderNumber'     => 'OE' . str_pad( (string) ( count( $this->records['OE/OEOrders'] ) + 1 ), 6, '0', STR_PAD_LEFT ),
		) + $body + array( 'CurrencyCode' => $customer['CurrencyCode'] );
		if ( $this->totalInAnswer ) {
			$record['OrderTotal'] = round( $net + round( $net * $rate / 100, 2 ), 2 );
		}
		$this->records['OE/OEOrders'][] = $record;
		return FakeHttpClient::json( $record, 201 );
	}

	/**
	 * POST OECreditDebitNotes.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createNote( array $body ): HttpResponse {
		if ( empty( $body['Reference'] ) || null === $this->customer( (string) ( $body['CustomerNumber'] ?? '' ) ) ) {
			return $this->error( 400, 'RecordInvalid', 'Reference and a valid customer number are required.' );
		}
		if ( 'CreditNote' !== ( $body['CreditDebitNoteType'] ?? '' ) ) {
			return $this->error( 400, 'RecordInvalid', 'Credit/debit note type is not valid.' );
		}
		foreach ( (array) ( $body['CreditDebitDetails'] ?? array() ) as $line ) {
			$line = (array) $line;
			if ( 'Item' === ( $line['LineType'] ?? '' ) && null === $this->find( 'IC/ICItems', 'ItemNumber', (string) ( $line['Item'] ?? '' ) ) ) {
				return $this->error( 400, 'RecordInvalid', 'Item is not valid.' );
			}
		}
		$record = array(
			'CreditDebitNoteUniquifier' => (string) ( ++$this->next ),
			'CreditDebitNoteNumber'     => 'CN' . str_pad( (string) ( count( $this->records['OE/OECreditDebitNotes'] ) + 1 ), 6, '0', STR_PAD_LEFT ),
		) + $body;
		$this->records['OE/OECreditDebitNotes'][] = $record;
		return FakeHttpClient::json( $record, 201 );
	}

	/**
	 * A customer record by number.
	 *
	 * @param string $number Customer number.
	 * @return array<string,mixed>|null
	 */
	private function customer( string $number ): ?array {
		return $this->find( 'AR/ARCustomers', 'CustomerNumber', $number );
	}

	/**
	 * A record by one field, ignoring case.
	 *
	 * @param string $resource Resource.
	 * @param string $field    Field.
	 * @param string $value    Value.
	 * @return array<string,mixed>|null
	 */
	private function find( string $resource, string $field, string $value ): ?array {
		foreach ( $this->records[ $resource ] ?? array() as $record ) {
			if ( strtolower( (string) ( $record[ $field ] ?? '' ) ) === strtolower( $value ) ) {
				return $record;
			}
		}
		return null;
	}
}
