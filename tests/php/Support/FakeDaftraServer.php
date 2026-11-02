<?php
/**
 * A small in-memory Daftra for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Daftra connector makes, in the shapes of the reference pages at https://docs.daftara.dev: the key travels in `apikey`,
 * answers are `{result, code, data, pagination}` with each record wrapped in its name, a create answers 202 with the new id, an invoice created as a
 * draft is issued by `invoices/update_draft/{id}/0`, and amounts are numbers. Totals are computed here with the server's own arithmetic, line by line,
 * so a mistake in the connector shows up as a total mismatch. The product code filter matches by containment (as a `like` filter would) so the
 * connector's exact check is exercised.
 */
final class FakeDaftraServer {

	/**
	 * The key the server accepts.
	 */
	public const KEY = 'dft-test-key-0123456789';

	/**
	 * Today's date for `created` fields (the test clock starts on 2026-11-02).
	 */
	public string $today = '2026-11-02';

	/**
	 * Records by list name.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'clients'      => array(),
		'products'     => array(),
		'taxes'        => array(),
		'stores'       => array(),
		'invoices'     => array(),
		'credit_notes' => array(),
	);

	/**
	 * Rows per page the server allows (the connector asks for 100).
	 */
	public int $pageSize = 100;

	/**
	 * Host the server answers on.
	 */
	public string $host = 'acme.daftara.com';

	/**
	 * Whether the subscription is expired.
	 */
	public bool $expired = false;

	/**
	 * Refuse to delete documents.
	 */
	public bool $refuseDelete = false;

	/**
	 * Timeouts seen on requests.
	 *
	 * @var array<int,int>
	 */
	public array $timeouts = array();

	/**
	 * The next write fails this way.
	 */
	private string $failNext = '';

	/**
	 * Seconds for a scripted rate limit.
	 */
	private int $failSeconds = 0;

	/**
	 * Writes still to pass before the scripted failure.
	 */
	private int $failSkip = 0;

	/**
	 * The next write is performed and its answer is lost.
	 */
	private bool $loseAnswer = false;

	/**
	 * Counter for ids.
	 */
	private int $counter = 100;

	/**
	 * Counter for official invoice numbers.
	 */
	private int $numbers = 0;

	/**
	 * Seed an account with a 15 percent tax, a 5 percent tax included in prices, two warehouses and two products.
	 */
	public function __construct() {
		$this->tables['taxes']    = array(
			1 => array( 'id' => 1, 'name' => 'VAT', 'value' => 15, 'description' => 'Value Added Tax', 'included' => 0, 'is_active' => 1 ),
			2 => array( 'id' => 2, 'name' => 'Included VAT', 'value' => 5, 'description' => '', 'included' => 1, 'is_active' => 1 ),
			3 => array( 'id' => 3, 'name' => 'Old tax', 'value' => 10, 'description' => '', 'included' => 0, 'is_active' => 0 ),
		);
		$this->tables['stores']   = array(
			1 => array( 'id' => 1, 'name' => 'Main Warehouse', 'primary' => 1, 'active' => 1 ),
		);
		$this->tables['products'] = array(
			11 => array( 'id' => 11, 'name' => 'Ceramic mug', 'product_code' => 'CS-MUG-01', 'barcode' => '', 'unit_price' => 42, 'track_stock' => 1, 'stock_balance' => 42, 'tax1' => 1 ),
			12 => array( 'id' => 12, 'name' => 'قميص قطني', 'product_code' => 'CS-TEE-02', 'barcode' => '', 'unit_price' => 80, 'track_stock' => 1, 'stock_balance' => 7.5, 'tax1' => 1 ),
			13 => array( 'id' => 13, 'name' => 'Gift card', 'product_code' => 'CS-MUG-01-GIFT', 'barcode' => '', 'unit_price' => 10, 'track_stock' => 0, 'stock_balance' => 0, 'tax1' => null ),
		);
	}

	/**
	 * Answer every request of an HTTP client.
	 *
	 * @param FakeHttpClient $http Client.
	 */
	public function install( FakeHttpClient $http ): void {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Make the next write fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	public function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->failNext    = $kind;
		$this->failSeconds = $seconds;
		$this->failSkip    = 0;
	}

	/**
	 * Let some writes pass, then make the next one fail.
	 *
	 * @param int    $pass Writes that succeed first.
	 * @param string $kind Failure kind.
	 */
	public function failWriteAfter( int $pass, string $kind ): void {
		$this->failNext    = $kind;
		$this->failSeconds = 0;
		$this->failSkip    = $pass;
	}

	/**
	 * Perform the next write but lose the answer.
	 */
	public function loseNextAnswer(): void {
		$this->loseAnswer = true;
	}

	/**
	 * Records of a list.
	 *
	 * @param string $table List name.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $table ): array {
		return array_values( $this->tables[ $table ] );
	}

	/**
	 * Handle one request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function handle( array $request ): HttpResponse {
		$this->timeouts[] = $request['timeout'];
		$parts            = parse_url( $request['url'] );
		$path             = (string) ( $parts['path'] ?? '' );
		$query            = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$headers = array_change_key_case( $request['headers'], CASE_LOWER );
		$method  = $request['method'];

		if ( ( $parts['host'] ?? '' ) !== $this->host || 0 !== strpos( $path, '/api2/' ) ) {
			return FakeHttpClient::status( 404, '{"result":"failed","code":404}' );
		}
		if ( isset( $headers['authorization'] ) || ( $headers['apikey'] ?? '' ) !== self::KEY ) {
			return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 401, 'message' => 'Unauthorized' ), 401 );
		}
		$write = in_array( $method, array( 'POST', 'PATCH', 'PUT', 'DELETE' ), true ) || str_contains( $path, '/update_draft/' );
		if ( $write && '' !== $this->failNext ) {
			if ( $this->failSkip > 0 ) {
				--$this->failSkip;
			} else {
				$kind           = $this->failNext;
				$this->failNext = '';
				return $this->scripted( $kind );
			}
		}
		$route = preg_replace( '/\.json$/', '', substr( $path, 6 ) );
		$segs  = explode( '/', trim( (string) $route, '/' ) );
		$body  = null === $request['body'] || '' === $request['body'] ? array() : (array) json_decode( $request['body'], true );
		$answer = $this->route( $method, $segs, $query, $body );
		if ( $write && $this->loseAnswer ) {
			$this->loseAnswer = false;
			return FakeHttpClient::timeout();
		}
		return $answer;
	}

	/**
	 * A scripted failure.
	 *
	 * @param string $kind Kind.
	 */
	private function scripted( string $kind ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => FakeHttpClient::tooManyRequests( $this->failSeconds ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 500, '{"result":"failed","code":500}' ),
			'auth'         => FakeHttpClient::json( array( 'result' => 'failed', 'code' => 401 ), 401 ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => FakeHttpClient::status( 418, 'teapot' ),
		};
	}

	/**
	 * Route a request.
	 *
	 * @param string              $method Method.
	 * @param array<int,string>   $segs   Path after /api2/ without .json.
	 * @param array<string,mixed> $query  Query.
	 * @param array<string,mixed> $body   Body.
	 */
	private function route( string $method, array $segs, array $query, array $body ): HttpResponse {
		$table = $segs[0] ?? '';
		if ( 'site_info' === $table ) {
			return $this->ok( array( 'Site' => array( 'id' => '3418499', 'business_name' => 'مؤسسة الاختبار', 'subdomain' => 'acme', 'country_code' => 'SA', 'currency_code' => 'SAR', 'package_id' => 'pro', 'is_expired' => $this->expired ) ) );
		}
		if ( 'invoice_payments' === $table && 'POST' === $method ) {
			return $this->addPayment( (array) ( $body['InvoicePayment'] ?? array() ) );
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			return $this->failure( 404, 'Not found' );
		}
		if ( 'invoices' === $table && 'update_draft' === ( $segs[1] ?? '' ) ) {
			return $this->issue( (int) ( $segs[2] ?? 0 ) );
		}
		$id = isset( $segs[1] ) ? (int) $segs[1] : 0;
		if ( 0 === $id ) {
			return 'GET' === $method ? $this->listing( $table, $query ) : $this->create( $table, $body );
		}
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
			return $this->failure( 404, 'Record Not Found' );
		}
		if ( 'GET' === $method ) {
			return $this->show( $table, $id );
		}
		if ( 'DELETE' === $method ) {
			if ( $this->refuseDelete || ( 'invoices' === $table && 0 === (int) $this->tables[ $table ][ $id ]['draft'] ) ) {
				return $this->failure( 400, 'This record cannot be deleted' );
			}
			unset( $this->tables[ $table ][ $id ] );
			return $this->ok( array(), 200 );
		}
		if ( 'PUT' === $method || 'POST' === $method ) {
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], (array) ( $body['Invoice'] ?? array() ) );
			return $this->ok( array(), 200 );
		}
		return $this->failure( 405, 'Method not allowed' );
	}

	/**
	 * A successful answer.
	 *
	 * @param array<string,mixed> $data   Data.
	 * @param int                 $status Status.
	 * @param array<string,mixed> $extra  Extra top-level keys.
	 */
	private function ok( array $data, int $status = 200, array $extra = array() ): HttpResponse {
		return FakeHttpClient::json( array_merge( array( 'result' => 'successful', 'code' => $status, 'data' => $data ), $extra ), $status );
	}

	/**
	 * A failed answer.
	 *
	 * @param int    $status  Status.
	 * @param string $message Message.
	 */
	private function failure( int $status, string $message ): HttpResponse {
		return FakeHttpClient::json( array( 'result' => 'failed', 'code' => $status, 'message' => $message ), $status );
	}

	/**
	 * The record name each row of a list is wrapped in.
	 *
	 * @param string $table List name.
	 */
	private function wrapper( string $table ): string {
		return array( 'clients' => 'Client', 'products' => 'Product', 'taxes' => 'Tax', 'stores' => 'Store', 'invoices' => 'Invoice', 'credit_notes' => 'CreditNote' )[ $table ];
	}

	/**
	 * A paged list.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $query Query.
	 */
	private function listing( string $table, array $query ): HttpResponse {
		$rows = array_values( $this->tables[ $table ] );
		if ( 'invoices' === $table && isset( $query['po_number'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['po_number'] === $query['po_number'] ) );
		}
		if ( 'credit_notes' === $table && isset( $query['created_from'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['created'] >= $query['created_from'] ) );
		}
		if ( 'products' === $table && isset( $query['product_code'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( strtolower( $row['product_code'] ), strtolower( (string) $query['product_code'] ) ) ) );
		}
		if ( isset( $query['keywords'] ) ) {
			$needle = mb_strtolower( (string) $query['keywords'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( mb_strtolower( ( $row['business_name'] ?? $row['name'] ?? '' ) . ' ' . ( $row['email'] ?? '' ) . ' ' . ( $row['product_code'] ?? '' ) ), $needle ) ) );
		}
		$size  = max( 1, min( $this->pageSize, (int) ( $query['limit'] ?? $this->pageSize ) ) );
		$page  = max( 1, (int) ( $query['page'] ?? 1 ) );
		$count = max( 1, (int) ceil( count( $rows ) / $size ) );
		$slice = array_slice( $rows, ( $page - 1 ) * $size, $size );
		$name  = $this->wrapper( $table );
		return $this->ok(
			array_map( fn( array $row ): array => array( $name => $this->public( $table, $row ) ), $slice ),
			200,
			array(
				'pagination' => array(
					'prev'          => $page > 1 ? "/api2/{$table}/?page=" . ( $page - 1 ) : null,
					'next'          => $page < $count ? "/api2/{$table}/?page=" . ( $page + 1 ) : null,
					'page'          => $page,
					'page_count'    => $count,
					'total_results' => count( $rows ),
				),
			)
		);
	}

	/**
	 * One record with its nested items and payments.
	 *
	 * @param string $table List name.
	 * @param int    $id    Record id.
	 */
	private function show( string $table, int $id ): HttpResponse {
		$row  = $this->tables[ $table ][ $id ];
		$name = $this->wrapper( $table );
		$data = array( $name => $this->public( $table, $row ) );
		if ( 'invoices' === $table || 'credit_notes' === $table ) {
			$data['InvoiceItem']    = $row['_items'];
			$data['InvoicePayment'] = $row['_payments'];
		}
		return $this->ok( $data );
	}

	/**
	 * A record as the API shows it.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $row   Stored record.
	 * @return array<string,mixed>
	 */
	private function public( string $table, array $row ): array {
		unset( $row['_items'], $row['_payments'], $row['_cents'], $row['created'] );
		return $row;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $body  Body.
	 */
	private function create( string $table, array $body ): HttpResponse {
		return match ( $table ) {
			'clients'      => $this->createClient( (array) ( $body['Client'] ?? array() ) ),
			'invoices'     => $this->createDocument( 'invoices', $body ),
			'credit_notes' => $this->createDocument( 'credit_notes', $body ),
			default        => $this->failure( 405, 'Method not allowed' ),
		};
	}

	/**
	 * Create a client.
	 *
	 * @param array<string,mixed> $client Fields.
	 */
	private function createClient( array $client ): HttpResponse {
		if ( empty( $client['business_name'] ) ) {
			return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( 'business_name' => array( 'This field is required' ) ) ), 400 );
		}
		$id                            = ++$this->counter;
		$this->tables['clients'][ $id ] = array_merge( array( 'email' => '' ), $client, array( 'id' => $id, 'client_number' => str_pad( (string) $id, 7, '0', STR_PAD_LEFT ) ) );
		return $this->ok( array(), 202, array( 'id' => $id, 'client_number' => $this->tables['clients'][ $id ]['client_number'] ) );
	}

	/**
	 * Create an invoice or a credit note.
	 *
	 * @param string              $table invoices or credit_notes.
	 * @param array<string,mixed> $body  Body.
	 */
	private function createDocument( string $table, array $body ): HttpResponse {
		$fields = (array) ( $body['Invoice'] ?? array() );
		$items  = (array) ( $body['InvoiceItem'] ?? array() );
		if ( empty( $fields['client_id'] ) || ! isset( $this->tables['clients'][ (int) $fields['client_id'] ] ) ) {
			return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( 'client_id' => array( 'Client does not exist' ) ) ), 400 );
		}
		$currency = (string) ( $fields['currency_code'] ?? 'SAR' );
		$stored   = array();
		$total    = 0;
		$subtotal = 0;
		foreach ( $items as $index => $item ) {
			$item = (array) $item;
			if ( empty( $item['item'] ) || ! isset( $item['unit_price'], $item['quantity'] ) ) {
				return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( "InvoiceItem.{$index}.item" => array( 'This field is required' ) ) ), 400 );
			}
			if ( isset( $item['product_id'] ) && ! isset( $this->tables['products'][ (int) $item['product_id'] ] ) ) {
				return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( "InvoiceItem.{$index}.product_id" => array( 'Product does not exist' ) ) ), 400 );
			}
			$tax = null;
			if ( isset( $item['tax1'] ) ) {
				$tax = $this->tables['taxes'][ (int) $item['tax1'] ] ?? null;
				if ( null === $tax ) {
					return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( "InvoiceItem.{$index}.tax1" => array( 'Tax does not exist' ) ) ), 400 );
				}
			}
			$line     = $this->lineMinor( (float) $item['unit_price'], (float) $item['quantity'], $currency );
			$bp       = null === $tax ? 0 : (int) round( (float) $tax['value'] * 100 );
			$included = null !== $tax && 1 === (int) $tax['included'];
			$net      = $included ? intdiv( abs( $line ) * 10000 + intdiv( 10000 + $bp, 2 ), 10000 + $bp ) : $line;
			$lineTax  = $included ? $line - $net : intdiv( abs( $net ) * $bp + 5000, 10000 );
			$subtotal += $net;
			$total    += $net + $lineTax;
			$stored[]  = $item;
		}
		$id    = ++$this->counter;
		$draft = 'invoices' === $table ? (int) ( $fields['draft'] ?? 0 ) : 0;
		$this->tables[ $table ][ $id ] = array_merge(
			array(
				'id'               => $id,
				'no'               => $draft ? '' : $this->nextNumber( $table ),
				'po_number'        => '',
				'date'             => $this->today,
				'currency_code'    => $currency,
				'draft'            => $draft,
				'summary_subtotal' => $this->decimal( $subtotal, $currency ),
				'summary_total'    => $this->decimal( $total, $currency ),
				'summary_paid'     => 0,
				'created'          => $this->today,
				'_items'           => $stored,
				'_payments'        => array(),
				'_cents'           => $total,
			),
			$fields,
			array( 'id' => $id, 'draft' => $draft )
		);
		return $this->ok( array(), 202, array( 'id' => (string) $id ) );
	}

	/**
	 * Issue a draft invoice.
	 *
	 * @param int $id Invoice id.
	 */
	private function issue( int $id ): HttpResponse {
		if ( ! isset( $this->tables['invoices'][ $id ] ) ) {
			return $this->failure( 404, 'Record Not Found' );
		}
		if ( 0 === (int) $this->tables['invoices'][ $id ]['draft'] ) {
			return $this->failure( 400, 'The invoice is already issued' );
		}
		$this->tables['invoices'][ $id ]['draft'] = 0;
		$this->tables['invoices'][ $id ]['no']    = $this->nextNumber( 'invoices' );
		return FakeHttpClient::json( array( 'result' => 'successful', 'code' => 200, 'message' => '' ) );
	}

	/**
	 * The next official number.
	 *
	 * @param string $table List name.
	 */
	private function nextNumber( string $table ): string {
		++$this->numbers;
		return ( 'credit_notes' === $table ? 'CN-' : 'INV-' ) . str_pad( (string) $this->numbers, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Quantity times unit price in minor units, rounded half away from zero.
	 *
	 * @param float  $unit     Unit price.
	 * @param float  $quantity Quantity.
	 * @param string $currency Currency.
	 */
	private function lineMinor( float $unit, float $quantity, string $currency ): int {
		return (int) round( (float) number_format( $unit * $quantity, 6, '.', '' ) * ( 10 ** $this->exponent( $currency ) ) );
	}

	/**
	 * Decimal places of a currency (the fake's own table).
	 *
	 * @param string $currency Currency.
	 */
	private function exponent( string $currency ): int {
		return array( 'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JPY' => 0 )[ $currency ] ?? 2;
	}

	/**
	 * Minor units as a number.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 * @return int|float
	 */
	private function decimal( int $minor, string $currency ): int|float {
		$exp = $this->exponent( $currency );
		return 0 === $exp ? $minor : $minor / ( 10 ** $exp );
	}

	/**
	 * Record a payment of an invoice.
	 *
	 * @param array<string,mixed> $payment Fields.
	 */
	private function addPayment( array $payment ): HttpResponse {
		$invoice = $this->tables['invoices'][ (int) ( $payment['invoice_id'] ?? 0 ) ] ?? null;
		foreach ( array( 'invoice_id', 'amount', 'payment_method' ) as $field ) {
			if ( empty( $payment[ $field ] ) ) {
				return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( $field => array( 'This field is required' ) ) ), 400 );
			}
		}
		if ( null === $invoice || 1 === (int) $invoice['draft'] ) {
			return FakeHttpClient::json( array( 'result' => 'failed', 'code' => 400, 'validation' => array( 'invoice_id' => array( 'The invoice must be issued' ) ) ), 400 );
		}
		$this->tables['invoices'][ (int) $payment['invoice_id'] ]['_payments'][] = array_merge( $payment, array( 'status' => 1 ) );
		$this->tables['invoices'][ (int) $payment['invoice_id'] ]['summary_paid'] = (float) $payment['amount'];
		return $this->ok( array(), 202, array( 'id' => ++$this->counter, 'invoice_number' => $invoice['no'] ) );
	}
}
