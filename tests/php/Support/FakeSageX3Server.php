<?php
/**
 * A small in-memory Sage X3 REST web service (Syracuse `/api1/`) for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Sage X3 connector makes in the shapes of the integration guide: `{"$itemsPerPage", "$resources", "$links"}` lists
 * paged through `$links.$next.$url`, 20 rows by default, `$template`, `$prototypes('REP.$edit')`, a 201 with a `Location` header on a create,
 * and `$diagnoses` entries that can sit inside a successful answer. It models on purpose what once hides bugs: a create that fails a
 * business rule still answers 201 with an error diagnosis and stores nothing, a mandatory property is checked, and the currency of the order
 * is silently the customer's. It does not model everything Sage X3 checks. All data is synthetic.
 */
final class FakeSageX3Server {

	public const ADDRESS = 'https://x3.example.test:8443';

	public const FOLDER = 'SEED';

	public const USER = 'syncbot';

	public const PASSWORD = 'x3-pass-6b1d90e4c7';

	/**
	 * Records by class.
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
	 * Properties of the sales order representation the folder describes (null: it does not describe itself).
	 *
	 * @var array<int,string>|null
	 */
	public ?array $orderProps = array( 'SOHNUM', 'BPCORD', 'ORDDAT', 'CUSORDREF', 'CUR', 'SALFCY', 'ORDINVATI', 'SORDERQ' );

	/**
	 * Properties of an order line the folder describes.
	 *
	 * @var array<int,string>
	 */
	public array $lineProps = array( 'ITMREF', 'QTY', 'GROPRI' );

	/**
	 * Classes the folder exposes with their writable representation.
	 *
	 * @var array<string,string>
	 */
	public array $classes = array(
		'SORDER'     => 'SOH',
		'ITMMASTER'  => 'ITMMASTER',
		'BPCUSTOMER' => 'BPCUSTOMER',
	);

	/**
	 * Tax added to the lines in the total, as a fraction.
	 */
	public float $vat = 0.05;

	/**
	 * Whether the answer of a create holds the total property.
	 */
	public bool $totalInAnswer = true;

	/**
	 * Whether every answer is a login page (Basic authentication switched off for the user).
	 */
	public bool $loginPage = false;

	/**
	 * Rows per page.
	 */
	public int $pageSize = 20;

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

	/**
	 * Attach the server to a fake network.
	 *
	 * @param FakeHttpClient $http Network.
	 */
	public function __construct( FakeHttpClient $http ) {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
		$this->records = array(
			'SORDER'     => array(),
			'ITMMASTER'  => array(
				array( 'ITMREF' => 'CS-MUG-01', 'ITMDES1' => 'Mug' ),
				array( 'ITMREF' => 'CS-BOWL-02', 'ITMDES1' => 'Bowl' ),
			),
			'BPCUSTOMER' => array(
				array( 'BPCNUM' => 'WEB001', 'BPCNAM' => 'Web shop', 'CUR' => 'AED' ),
				array( 'BPCNUM' => 'USD001', 'BPCNAM' => 'Dollar customer', 'CUR' => 'USD' ),
			),
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
			'auth'         => FakeHttpClient::status( 401, '' ),
			default        => FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'fatal', '$message' => 'boom ' . self::PASSWORD ) ) ), 500 ),
		};
		$this->failNext( 'POST', '#/SORDER$#', $response );
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
	 * Records of a class.
	 *
	 * @param string $classCode Class code.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $classCode ): array {
		return array_values( $this->records[ $classCode ] ?? array() );
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
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'headers' => (array) $request['headers'], 'host' => $parts['host'] ?? '' );

		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path ) ) {
				unset( $this->failures[ $index ] );
				return $failure[2] instanceof HttpResponse ? $failure[2] : ( $failure[2] )( $request );
			}
		}
		$prefix = '/api1/x3/erp/' . self::FOLDER . '/';
		if ( 'x3.example.test' !== ( $parts['host'] ?? '' ) || ! str_starts_with( $path, '/api1/x3/erp/' ) ) {
			return new HttpResponse( 404, '<html><body>Not Found</body></html>', array( 'content-type' => 'text/html' ) );
		}
		$headers = array_change_key_case( (array) $request['headers'], CASE_LOWER );
		if ( $this->loginPage ) {
			return new HttpResponse( 200, '<!DOCTYPE html><html><body>Sign in to Syracuse</body></html>', array( 'content-type' => 'text/html' ) );
		}
		if ( 'Basic ' . base64_encode( self::USER . ':' . self::PASSWORD ) !== ( $headers['authorization'] ?? '' ) ) {
			return new HttpResponse( 401, '', array( 'www-authenticate' => 'Basic realm="Syracuse"' ) );
		}
		if ( ! str_starts_with( $path, $prefix ) ) {
			return FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'The endpoint does not exist.' ) ) ), 404 );
		}
		$rest = substr( $path, strlen( $prefix ) );
		if ( 1 === preg_match( "/^\\\$prototypes\\('([^']+)'\\)$/", $rest, $found ) ) {
			return $this->prototype( $found[1] );
		}
		if ( 1 === preg_match( '#^(\w+)/\$template$#', $rest, $found ) ) {
			return $this->template( $found[1], (string) ( $query['representation'] ?? '' ) );
		}
		if ( 1 !== preg_match( '#^(\w+)$#', $rest, $found ) ) {
			return FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'Unknown resource.' ) ) ), 404 );
		}
		$classCode = $found[1];
		$rep   = (string) ( $query['representation'] ?? '' );
		if ( ! isset( $this->classes[ $classCode ] ) || strtok( $rep, '.' ) !== $this->classes[ $classCode ] ) {
			return FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'The class ' . $classCode . ' with the representation ' . strtok( $rep, '.' ) . ' does not exist.' ) ) ), 404 );
		}
		if ( 'GET' === $method ) {
			return $this->listing( $classCode, $query );
		}
		if ( 'POST' === $method && str_ends_with( $rep, '.$create' ) ) {
			return 'SORDER' === $classCode ? $this->createOrder( $body ) : FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'Read only.' ) ) ), 403 );
		}
		return new HttpResponse( 405, '', array() );
	}

	/**
	 * The template of a create facet.
	 *
	 * @param string $classCode Class code.
	 * @param string $rep   Representation with facet.
	 */
	private function template( string $classCode, string $rep ): HttpResponse {
		if ( ! isset( $this->classes[ $classCode ] ) || strtok( $rep, '.' ) !== $this->classes[ $classCode ] ) {
			return FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'The class ' . $classCode . ' with the representation ' . strtok( $rep, '.' ) . ' does not exist.' ) ) ), 404 );
		}
		return FakeHttpClient::json( array( 'ORDDAT' => array( '$isMandatory' => true ), '$diagnoses' => array() ) );
	}

	/**
	 * The prototype of a representation.
	 *
	 * @param string $rep Representation with facet.
	 */
	private function prototype( string $rep ): HttpResponse {
		if ( null === $this->orderProps || 'SOH' !== strtok( $rep, '.' ) ) {
			return FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => 'No prototype.' ) ) ), 404 );
		}
		$properties = array();
		foreach ( $this->orderProps as $name ) {
			$properties[ $name ] = array( '$type' => 'application/x-string' );
		}
		if ( isset( $properties['SORDERQ'] ) ) {
			$inner = array();
			foreach ( $this->lineProps as $name ) {
				$inner[ $name ] = array( '$type' => 'application/x-string' );
			}
			$properties['SORDERQ'] = array( '$item' => array( '$properties' => $inner ) );
		}
		return FakeHttpClient::json( array( '$properties' => $properties ) );
	}

	/**
	 * GET a list.
	 *
	 * @param string              $classCode Class code.
	 * @param array<string,mixed> $query Query.
	 */
	private function listing( string $classCode, array $query ): HttpResponse {
		$rows = array();
		foreach ( $this->records[ $classCode ] ?? array() as $record ) {
			if ( FakeODataFilter::matches( (string) ( $query['where'] ?? '' ), array_filter( $record, static fn( mixed $value ): bool => ! is_array( $value ) ), true ) ) {
				$rows[] = $record;
			}
		}
		$size  = max( 1, min( $this->pageSize, (int) ( $query['count'] ?? $this->pageSize ) ) );
		$start = (int) ( $query['startIndex'] ?? 0 );
		$page  = array_slice( $rows, $start, $size );
		$body  = array( '$itemsPerPage' => $size, '$resources' => $page, '$links' => array() );
		if ( $start + $size < count( $rows ) ) {
			$next                    = $query;
			$next['startIndex']      = $start + $size;
			$next['count']           = $size;
			$body['$links']['$next'] = array( '$url' => self::ADDRESS . '/api1/x3/erp/' . self::FOLDER . '/' . $classCode . '?' . http_build_query( $next ) );
		}
		return FakeHttpClient::json( $body );
	}

	/**
	 * POST a sales order.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createOrder( array $body ): HttpResponse {
		$diagnose = static fn( string $message ): HttpResponse => FakeHttpClient::json( array( '$diagnoses' => array( array( '$severity' => 'error', '$message' => $message ) ) ), 201 );
		if ( null !== $this->orderProps ) {
			foreach ( array_keys( $body ) as $name ) {
				if ( ! in_array( $name, $this->orderProps, true ) ) {
					return $diagnose( "The property '" . $name . "' does not exist." );
				}
			}
		}
		if ( empty( $body['ORDDAT'] ) ) {
			return $diagnose( 'The order date is mandatory.' );
		}
		$customer = null;
		foreach ( $this->records['BPCUSTOMER'] as $row ) {
			if ( strtolower( (string) $row['BPCNUM'] ) === strtolower( (string) ( $body['BPCORD'] ?? '' ) ) ) {
				$customer = $row;
			}
		}
		if ( null === $customer ) {
			return $diagnose( 'The customer does not exist.' );
		}
		$net = 0.0;
		foreach ( (array) ( $body['SORDERQ'] ?? array() ) as $line ) {
			$line = (array) $line;
			$have = false;
			foreach ( $this->records['ITMMASTER'] as $item ) {
				$have = $have || strtolower( (string) $item['ITMREF'] ) === strtolower( (string) ( $line['ITMREF'] ?? '' ) );
			}
			if ( ! $have ) {
				return $diagnose( 'The item ' . (string) ( $line['ITMREF'] ?? '' ) . ' does not exist.' );
			}
			$net += round( (float) $line['QTY'] * (float) ( $line['GROPRI'] ?? 0 ), 2 );
		}
		$number = 'SOH' . str_pad( (string) ( count( $this->records['SORDER'] ) + 1 ), 5, '0', STR_PAD_LEFT );
		$record = array( 'SOHNUM' => $number ) + $body;
		$record['CUR'] = $customer['CUR'];
		if ( $this->totalInAnswer ) {
			$record['ORDINVATI'] = round( $net * ( 1 + $this->vat ), 2 );
		}
		$this->records['SORDER'][] = $record;
		return FakeHttpClient::json( $record + array( '$diagnoses' => array( array( '$severity' => 'warning', '$message' => 'Credit limit is near.' ) ) ), 201, array( 'location' => self::ADDRESS . '/api1/x3/erp/' . self::FOLDER . "/SORDER('" . $number . "')" ) );
	}
}
