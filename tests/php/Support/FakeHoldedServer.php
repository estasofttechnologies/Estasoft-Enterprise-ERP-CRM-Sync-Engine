<?php
/**
 * A small in-memory Holded for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Holded connector makes, in the shapes recorded in docs/connectors/holded.md (third-party sources): the key travels in
 * `key`, lists are plain JSON arrays paged with `page`, a create answers `{"status":1,"info":"Created","id":"..."}`, a refusal can arrive as
 * `{"status":0,"info":"..."}` inside a 200, and a document list can be filtered with `starttmp` and `endtmp`. Totals are computed here, item by
 * item, with integer arithmetic, so a mistake in the connector shows up as a total mismatch. An invoice and a credit note cannot be deleted; a
 * sales order can.
 */
final class FakeHoldedServer {

	/**
	 * The key the server accepts.
	 */
	public const KEY = 'hld-test-key-0123456789';

	/**
	 * Records by list name.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $tables = array(
		'contacts'   => array(),
		'products'   => array(),
		'warehouse'  => array(),
		'invoice'    => array(),
		'salesorder' => array(),
		'creditnote' => array(),
	);

	/**
	 * Rows per page the server allows.
	 */
	public int $pageSize = 100;

	/**
	 * The server ignores `page` and always answers the first page.
	 */
	public bool $ignorePage = false;

	/**
	 * List rows leave out `notes` and `desc` (a document read on its own has them).
	 */
	public bool $listWithoutNotes = false;

	/**
	 * Answer 403 with a plan message to every call.
	 */
	public bool $apiDisabled = false;

	/**
	 * Refuse every delete.
	 */
	public bool $refuseDelete = false;

	/**
	 * Add this many minor units to the total of the next document created (a server that computes differently).
	 */
	public int $skew = 0;

	/**
	 * Calls left in the quota; every request uses one and every answer reports what is left.
	 */
	public int $quotaLeft = 2000;

	/**
	 * Window the quota belongs to: month or minute.
	 */
	public string $quotaWindow = 'month';

	/**
	 * Value of the reset header, or an empty string for none.
	 */
	public string $quotaReset = '';

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
	private int $counter = 0;

	/**
	 * Counters for document numbers.
	 *
	 * @var array<string,int>
	 */
	private array $numbers = array();

	/**
	 * Seed an account with two warehouses and three products.
	 */
	public function __construct() {
		$this->tables['warehouse'] = array(
			'w1' => array( 'id' => 'w1', 'name' => 'Main warehouse' ),
			'w2' => array( 'id' => 'w2', 'name' => 'Shop' ),
		);
		$this->tables['products']  = array(
			'p1' => array( 'id' => 'p1', 'name' => 'Ceramic mug', 'sku' => 'CS-MUG-01', 'price' => 42 ),
			'p2' => array( 'id' => 'p2', 'name' => 'قميص قطني', 'sku' => 'CS-TEE-02', 'price' => 80 ),
			'p3' => array( 'id' => 'p3', 'name' => 'Gift card', 'sku' => 'CS-MUG-01-GIFT', 'price' => 10 ),
			'p4' => array( 'id' => 'p4', 'name' => 'No sku product', 'sku' => '', 'price' => 5 ),
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
	 * @param string $kind    rate_limit, month_limit, timeout, server_error, auth, certificate or status_zero.
	 * @param int    $seconds Seconds for a rate limit.
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
	 * Put a document in the account as another tool would have made it.
	 *
	 * @param string              $type   invoice, salesorder or creditnote.
	 * @param array<string,mixed> $fields Fields that replace the defaults.
	 * @return string The id.
	 */
	public function seedDocument( string $type, array $fields ): string {
		$id                            = 'd' . ++$this->counter;
		$this->tables[ $type ][ $id ] = array_merge(
			array(
				'id'          => $id,
				'docNumber'   => $this->nextNumber( $type ),
				'contact'     => '',
				'contactName' => '',
				'date'        => strtotime( '2026-11-02 12:00:00 UTC' ),
				'currency'    => 'eur',
				'total'       => 0,
				'notes'       => '',
				'desc'        => '',
				'products'    => array(),
			),
			$fields,
			array( 'id' => $id )
		);
		return $id;
	}

	/**
	 * Answer one request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function handle( array $request ): HttpResponse {
		$answer = $this->answer( $request );
		if ( 0 === $answer->status ) {
			return $answer;
		}
		$this->quotaLeft = max( 0, $this->quotaLeft - 1 );
		$headers         = $answer->headers + array(
			'x-ratelimit-remaining' => (string) $this->quotaLeft,
			'x-ratelimit-window'    => $this->quotaWindow,
		);
		if ( '' !== $this->quotaReset ) {
			$headers['x-ratelimit-reset'] = $this->quotaReset;
		}
		return new HttpResponse( $answer->status, $answer->body, $headers, $answer->error, $answer->timedOut );
	}

	/**
	 * Answer one request without the quota headers.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	private function answer( array $request ): HttpResponse {
		$this->timeouts[] = $request['timeout'];
		$parts            = parse_url( $request['url'] );
		$path             = (string) ( $parts['path'] ?? '' );
		$query            = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$headers = array_change_key_case( $request['headers'], CASE_LOWER );
		$method  = $request['method'];

		if ( 'api.holded.com' !== ( $parts['host'] ?? '' ) || 0 !== strpos( $path, '/api/invoicing/v1/' ) ) {
			return FakeHttpClient::status( 404, '{"error":"Not found"}' );
		}
		if ( ( $headers['key'] ?? '' ) !== self::KEY || isset( $headers['authorization'] ) ) {
			return FakeHttpClient::json( array( 'error' => 'Invalid API key' ), 401 );
		}
		if ( $this->apiDisabled ) {
			return FakeHttpClient::json( array( 'info' => 'Your plan does not include API access. Please upgrade your plan.' ), 403 );
		}
		$write = in_array( $method, array( 'POST', 'PUT', 'DELETE' ), true );
		if ( $write && '' !== $this->failNext ) {
			if ( $this->failSkip > 0 ) {
				--$this->failSkip;
			} else {
				$kind           = $this->failNext;
				$this->failNext = '';
				return $this->scripted( $kind );
			}
		}
		$segs   = explode( '/', trim( substr( $path, strlen( '/api/invoicing/v1/' ) ), '/' ) );
		$body   = null === $request['body'] || '' === $request['body'] ? array() : (array) json_decode( $request['body'], true );
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
			'rate_limit'   => FakeHttpClient::status( 429, '{"type":"about:blank","title":"Too Many Requests"}', array( 'retry-after' => (string) $this->failSeconds, 'x-ratelimit-window' => 'minute' ) ),
			'month_limit'  => FakeHttpClient::status( 429, '{"type":"about:blank","title":"Too Many Requests"}', array( 'retry-after' => (string) $this->failSeconds, 'x-ratelimit-window' => 'month' ) ),
			'timeout'      => FakeHttpClient::timeout(),
			'server_error' => FakeHttpClient::status( 500, '{"error":"Internal error"}' ),
			'auth'         => FakeHttpClient::json( array( 'error' => 'Invalid API key' ), 401 ),
			'certificate'  => FakeHttpClient::certificateError(),
			'status_zero'  => FakeHttpClient::json( array( 'status' => 0, 'info' => 'Contact not found' ) ),
			default        => FakeHttpClient::status( 418, 'teapot' ),
		};
	}

	/**
	 * Route a request.
	 *
	 * @param string              $method Method.
	 * @param array<int,string>   $segs   Path after /api/invoicing/v1/.
	 * @param array<string,mixed> $query  Query.
	 * @param array<string,mixed> $body   Body.
	 */
	private function route( string $method, array $segs, array $query, array $body ): HttpResponse {
		$head = $segs[0] ?? '';
		if ( 'documents' === $head ) {
			$type = $segs[1] ?? '';
			if ( ! isset( $this->tables[ $type ] ) || ! in_array( $type, array( 'invoice', 'salesorder', 'creditnote' ), true ) ) {
				return FakeHttpClient::json( array( 'status' => 0, 'info' => 'Invalid document type' ), 400 );
			}
			return $this->documents( $method, $type, $segs[2] ?? '', $query, $body );
		}
		if ( ! in_array( $head, array( 'contacts', 'products', 'warehouse' ), true ) ) {
			return FakeHttpClient::status( 404, '{"error":"Not found"}' );
		}
		if ( 'GET' === $method && ! isset( $segs[1] ) ) {
			return $this->listing( $head, array_values( $this->tables[ $head ] ), $query );
		}
		if ( 'POST' === $method && 'contacts' === $head ) {
			return $this->createContact( $body );
		}
		return FakeHttpClient::status( 405, '{"error":"Method not allowed"}' );
	}

	/**
	 * Documents of one type.
	 *
	 * @param string              $method Method.
	 * @param string              $type   Document type.
	 * @param string              $id     Document id or an empty string.
	 * @param array<string,mixed> $query  Query.
	 * @param array<string,mixed> $body   Body.
	 */
	private function documents( string $method, string $type, string $id, array $query, array $body ): HttpResponse {
		if ( '' === $id ) {
			if ( 'POST' === $method ) {
				return $this->createDocument( $type, $body );
			}
			$rows = array_values( $this->tables[ $type ] );
			if ( isset( $query['starttmp'], $query['endtmp'] ) ) {
				$from = (int) $query['starttmp'];
				$to   = (int) $query['endtmp'];
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['date'] >= $from && (int) $row['date'] <= $to ) );
			}
			if ( $this->listWithoutNotes ) {
				$rows = array_map( static fn( array $row ): array => array_diff_key( $row, array( 'notes' => 1, 'desc' => 1 ) ), $rows );
			}
			return $this->listing( $type, $rows, $query );
		}
		if ( ! isset( $this->tables[ $type ][ $id ] ) ) {
			return FakeHttpClient::json( array( 'status' => 0, 'info' => 'Document not found' ), 404 );
		}
		if ( 'GET' === $method ) {
			return FakeHttpClient::json( $this->tables[ $type ][ $id ] );
		}
		if ( 'DELETE' === $method ) {
			if ( $this->refuseDelete || 'salesorder' !== $type ) {
				return FakeHttpClient::json( array( 'status' => 0, 'info' => 'This document cannot be deleted' ), 400 );
			}
			unset( $this->tables[ $type ][ $id ] );
			return FakeHttpClient::json( array( 'status' => 1, 'info' => 'Deleted' ) );
		}
		return FakeHttpClient::status( 405, '{"error":"Method not allowed"}' );
	}

	/**
	 * A paged list.
	 *
	 * @param string                         $table List name.
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 * @param array<string,mixed>            $query Query.
	 */
	private function listing( string $table, array $rows, array $query ): HttpResponse {
		unset( $table );
		$page = $this->ignorePage ? 1 : max( 1, (int) ( $query['page'] ?? 1 ) );
		$out  = array_slice( $rows, ( $page - 1 ) * $this->pageSize, $this->pageSize );
		return FakeHttpClient::json( $out );
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createContact( array $body ): HttpResponse {
		if ( empty( $body['name'] ) ) {
			return FakeHttpClient::json( array( 'status' => 0, 'info' => 'name is required' ), 400 );
		}
		$id                                = 'c' . ++$this->counter;
		$this->tables['contacts'][ $id ] = array_merge( array( 'id' => $id ), $body );
		return FakeHttpClient::json( array( 'status' => 1, 'info' => 'Created', 'id' => $id ) );
	}

	/**
	 * Create a document and compute its total.
	 *
	 * @param string              $type Document type.
	 * @param array<string,mixed> $body Body.
	 */
	private function createDocument( string $type, array $body ): HttpResponse {
		$contact = $this->tables['contacts'][ (string) ( $body['contactId'] ?? '' ) ] ?? null;
		if ( null === $contact ) {
			return FakeHttpClient::json( array( 'status' => 0, 'info' => 'Contact not found' ) );
		}
		$items = (array) ( $body['items'] ?? array() );
		if ( array() === $items ) {
			return FakeHttpClient::json( array( 'status' => 0, 'info' => 'items is required' ), 400 );
		}
		$currency = strtolower( (string) ( $body['currency'] ?? 'eur' ) );
		$exponent = $this->exponent( $currency );
		$total    = 0;
		$stored   = array();
		foreach ( $items as $index => $item ) {
			$item = (array) $item;
			if ( ! isset( $item['name'], $item['units'], $item['subtotal'] ) ) {
				return FakeHttpClient::json( array( 'status' => 0, 'info' => "items.{$index}: name, units and subtotal are required" ), 400 );
			}
			if ( isset( $item['sku'] ) && '' !== $item['sku'] && null === $this->productBySku( (string) $item['sku'] ) ) {
				return FakeHttpClient::json( array( 'status' => 0, 'info' => "items.{$index}.sku: unknown product" ), 400 );
			}
			$net      = (int) round( (float) number_format( (float) $item['units'] * (float) $item['subtotal'], 6, '.', '' ) * ( 10 ** $exponent ) );
			$tax      = (float) ( $item['tax'] ?? 0 );
			$taxMinor = (int) round( abs( $net ) * $tax / 100 ) * ( $net < 0 ? -1 : 1 );
			$total   += $net + $taxMinor;
			$stored[] = $item;
		}
		$total += $this->skew;
		$this->skew = 0;
		$id    = 'd' . ++$this->counter;
		$this->tables[ $type ][ $id ] = array(
			'id'          => $id,
			'docNumber'   => $this->nextNumber( $type ),
			'contact'     => (string) $body['contactId'],
			'contactName' => (string) ( $contact['name'] ?? '' ),
			'date'        => (int) ( $body['date'] ?? strtotime( '2026-11-02 12:00:00 UTC' ) ),
			'currency'    => $currency,
			'total'       => 0 === $exponent ? $total : $total / ( 10 ** $exponent ),
			'notes'       => (string) ( $body['notes'] ?? '' ),
			'desc'        => '',
			'products'    => $stored,
			'warehouseId' => $body['warehouseId'] ?? null,
		);
		return FakeHttpClient::json( array( 'status' => 1, 'info' => 'Created', 'id' => $id ) );
	}

	/**
	 * A product by SKU.
	 *
	 * @param string $sku SKU.
	 * @return array<string,mixed>|null
	 */
	private function productBySku( string $sku ): ?array {
		foreach ( $this->tables['products'] as $product ) {
			if ( 0 === strcasecmp( (string) $product['sku'], $sku ) ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * The next document number of a type.
	 *
	 * @param string $type Document type.
	 */
	private function nextNumber( string $type ): string {
		$this->numbers[ $type ] = ( $this->numbers[ $type ] ?? 0 ) + 1;
		return array( 'invoice' => 'F26', 'salesorder' => 'SO26', 'creditnote' => 'R26' )[ $type ] . '-' . str_pad( (string) $this->numbers[ $type ], 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Decimal places of a currency (the fake's own table).
	 *
	 * @param string $currency Currency, lower case.
	 */
	private function exponent( string $currency ): int {
		return array( 'kwd' => 3, 'bhd' => 3, 'jpy' => 0 )[ $currency ] ?? 2;
	}
}
