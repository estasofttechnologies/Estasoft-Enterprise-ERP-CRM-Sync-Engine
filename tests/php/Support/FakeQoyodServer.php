<?php
/**
 * A small in-memory Qoyod for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Qoyod connector makes, in the shapes of the Qoyod collection (https://apidoc.qoyod.com): the key travels in `API-KEY`,
 * lists are `{"invoices": [...]}` and are searched with Ransack (`q[reference_eq]`), a create wraps its body (`{"invoice": {...}}`) and answers 201,
 * amounts are decimal strings, a reference must be unique, and an approved document cannot be edited (except its description). Totals are computed
 * here with the server's own integer arithmetic, line by line, so a mistake in the connector shows up as a total mismatch. Request names the
 * documentation does not show (`line_items_attributes`, `tax_id`) are the ones the connector uses; a request with a different name fails like
 * a Rails API would ("can't be blank").
 */
final class FakeQoyodServer {

	/**
	 * The key the server accepts.
	 */
	public const KEY = 'qyd-test-key-0123456789';

	/**
	 * Records by list name.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'contacts'         => array(),
		'products'         => array(),
		'inventories'      => array(),
		'invoices'         => array(),
		'credit_notes'     => array(),
		'invoice_payments' => array(),
	);

	/**
	 * Rows per page.
	 */
	public int $pageSize = 25;

	/**
	 * Currency of the company (Qoyod keeps one account currency; the fake uses it for the number of decimals).
	 */
	public string $currency = 'SAR';

	/**
	 * Answer the first page whatever the page asked for (a server without pagination).
	 */
	public bool $ignorePage = false;

	/**
	 * Invoice searches that find nothing before the server answers truthfully (a search that missed a record another request created).
	 */
	public int $blindSearches = 0;

	/**
	 * The server ignores the Ransack filters `q[...]` and answers the whole list.
	 */
	public bool $ignoreFilters = false;

	/**
	 * Refuse the allocation of credit notes.
	 */
	public bool $refuseAllocations = false;

	/**
	 * Refuse edits of an approved document, even of its description.
	 */
	public bool $lockApproved = false;

	/**
	 * Host the server answers on.
	 *
	 * @var array<int,string>
	 */
	public array $hosts = array( 'api.qoyod.com' );

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
	 * Seed a company with two branches, a mug (stock 12 in the first branch and 3 in the second), a service product and a tee.
	 */
	public function __construct() {
		$this->tables['inventories'] = array(
			1 => array( 'id' => 1, 'name' => 'Main', 'ar_name' => 'الرئيسي' ),
		);
		$this->tables['products']    = array(
			1 => $this->product( 1, 'CS-MUG-01', 'Ceramic mug', 'قدح', '1', array( 1 => '12.0' ), true ),
			2 => $this->product( 2, 'CS-TEE-02', 'Cotton tee', 'قميص قطني', '1', array( 1 => '5.0' ), true ),
			3 => $this->product( 3, 'SRV-SHIP', 'Shipping', 'الشحن', '1', array(), false ),
			4 => $this->product( 4, 'SRV-FEE', 'Fees', 'رسوم', '1', array(), false ),
		);
	}

	/**
	 * Add a second branch.
	 */
	public function addBranch(): void {
		$this->tables['inventories'][2] = array( 'id' => 2, 'name' => 'Jeddah', 'ar_name' => 'جدة' );
		foreach ( array( 1 ) as $id ) {
			$this->tables['products'][ $id ]['inventories'][] = array( 'id' => 2, 'name_en' => 'Jeddah', 'name_ar' => 'جدة', 'stock' => '3.0' );
		}
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
	 * Make the next write (POST or PATCH) fail.
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

		if ( ! in_array( $parts['host'] ?? '', $this->hosts, true ) || 0 !== strpos( $path, '/2.0/' ) ) {
			return FakeHttpClient::status( 404, '{"error":"Not found"}' );
		}
		if ( ( $headers['api-key'] ?? '' ) !== self::KEY ) {
			return FakeHttpClient::json( array( 'error' => 'Invalid API key' ), 401 );
		}
		$write = in_array( $method, array( 'POST', 'PATCH', 'PUT', 'DELETE' ), true );
		if ( $write && '' !== $this->failNext ) {
			if ( $this->failSkip > 0 ) {
				--$this->failSkip;
			} else {
				$kind           = $this->failNext;
				$this->failNext = '';
				return $this->scripted( $kind );
			}
		}
		$segments = explode( '/', trim( substr( $path, 5 ), '/' ) );
		$body     = null === $request['body'] || '' === $request['body'] ? array() : (array) json_decode( $request['body'], true );
		$answer   = $this->route( $method, $segments, $query, $body );
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
			'server_error' => FakeHttpClient::status( 500, '{"error":"Internal Server Error"}' ),
			'auth'         => FakeHttpClient::json( array( 'error' => 'Invalid API key' ), 401 ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => FakeHttpClient::status( 418, 'teapot' ),
		};
	}

	/**
	 * Route a request.
	 *
	 * @param string              $method   Method.
	 * @param array<int,string>   $segments Path after /2.0/.
	 * @param array<string,mixed> $query    Query.
	 * @param array<string,mixed> $body     Body.
	 */
	private function route( string $method, array $segments, array $query, array $body ): HttpResponse {
		$table = $segments[0] ?? '';
		$map   = array( 'customers' => 'contacts' );
		$table = $map[ $table ] ?? $table;
		if ( ! isset( $this->tables[ $table ] ) ) {
			return FakeHttpClient::json( array( 'error' => 'Not found' ), 404 );
		}
		$id = $segments[1] ?? '';
		if ( '' === $id ) {
			return 'GET' === $method ? $this->listing( $table, $query ) : $this->create( $table, $body );
		}
		$id = (int) $id;
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
			return FakeHttpClient::json( array( 'error' => 'Not found' ), 404 );
		}
		if ( 'allocations' === ( $segments[2] ?? '' ) && 'POST' === $method && 'invoices' === $table ) {
			return $this->allocate( $id, $body );
		}
		if ( 'GET' === $method ) {
			return FakeHttpClient::json( array( $this->singular( $table ) => $this->present( $table, $this->tables[ $table ][ $id ] ) ) );
		}
		if ( 'PATCH' === $method ) {
			return $this->patch( $table, $id, $body );
		}
		return FakeHttpClient::json( array( 'error' => 'Method not allowed' ), 405 );
	}

	/**
	 * The wrapper key of a record.
	 *
	 * @param string $table List name.
	 */
	private function singular( string $table ): string {
		return match ( $table ) {
			'contacts'         => 'contact',
			'credit_notes'     => 'credit_note',
			'invoice_payments' => 'invoice_payment',
			default            => rtrim( $table, 's' ),
		};
	}

	/**
	 * A list, searched with Ransack and paged.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $query Query.
	 */
	private function listing( string $table, array $query ): HttpResponse {
		$rows = array_values( $this->tables[ $table ] );
		if ( 'invoices' === $table && $this->blindSearches > 0 ) {
			--$this->blindSearches;
			$rows = array();
		}
		$q = isset( $query['q'] ) && is_array( $query['q'] ) ? $query['q'] : array();
		foreach ( $this->ignoreFilters ? array() : $q as $predicate => $value ) {
			$rows = array_values( array_filter( $rows, fn( array $row ): bool => $this->matches( $row, (string) $predicate, $value ) ) );
		}
		$page = $this->ignorePage ? 1 : max( 1, (int) ( $query['page'] ?? 1 ) );
		$rows = array_slice( $rows, ( $page - 1 ) * $this->pageSize, $this->pageSize );
		return FakeHttpClient::json( array( $table => array_map( fn( array $row ): array => $this->present( $table, $row ), $rows ) ) );
	}

	/**
	 * Whether a row matches one Ransack predicate.
	 *
	 * @param array<string,mixed> $row       Row.
	 * @param string              $predicate For example reference_eq.
	 * @param mixed               $value     Value.
	 */
	private function matches( array $row, string $predicate, mixed $value ): bool {
		if ( 1 !== preg_match( '/^(.*)_(eq|cont|in)$/', $predicate, $parts ) ) {
			return true;
		}
		$fields = explode( '_or_', $parts[1] );
		foreach ( $fields as $field ) {
			$actual = (string) ( $row[ $field ] ?? '' );
			if ( 'eq' === $parts[2] && $actual === (string) $value ) {
				return true;
			}
			if ( 'cont' === $parts[2] && '' !== $actual && str_contains( mb_strtolower( $actual ), mb_strtolower( (string) $value ) ) ) {
				return true;
			}
			if ( 'in' === $parts[2] && in_array( $actual, (array) $value, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $body  Body.
	 */
	private function create( string $table, array $body ): HttpResponse {
		return match ( $table ) {
			'contacts'         => $this->createContact( (array) ( $body['contact'] ?? array() ) ),
			'invoices'         => $this->createDocument( 'invoices', (array) ( $body['invoice'] ?? array() ) ),
			'credit_notes'     => $this->createDocument( 'credit_notes', (array) ( $body['credit_note'] ?? array() ) ),
			'invoice_payments' => $this->createPayment( (array) ( $body['invoice_payment'] ?? array() ) ),
			default            => FakeHttpClient::json( array( 'error' => 'Method not allowed' ), 405 ),
		};
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,mixed> $contact Fields.
	 */
	private function createContact( array $contact ): HttpResponse {
		if ( empty( $contact['name'] ) ) {
			return FakeHttpClient::json( array( 'errors' => array( 'name' => array( "can't be blank" ) ) ), 422 );
		}
		$id                              = $this->newId();
		$this->tables['contacts'][ $id ] = array_merge( array( 'email' => null, 'phone_number' => null, 'tax_number' => null ), $contact, array( 'id' => $id ) );
		return FakeHttpClient::json( array( 'contact' => $this->tables['contacts'][ $id ] ), 201 );
	}

	/**
	 * Create an invoice or a credit note.
	 *
	 * @param string              $table  invoices or credit_notes.
	 * @param array<string,mixed> $fields Fields.
	 */
	private function createDocument( string $table, array $fields ): HttpResponse {
		$errors = array();
		foreach ( array( 'contact_id', 'reference', 'issue_date', 'due_date', 'inventory_id' ) as $field ) {
			if ( empty( $fields[ $field ] ) ) {
				$errors[ $field ] = array( "can't be blank" );
			}
		}
		if ( empty( $fields['line_items_attributes'] ) ) {
			$errors['line_items'] = array( "can't be blank" );
		}
		if ( ! in_array( $fields['status'] ?? '', array( 'Draft', 'Approved' ), true ) ) {
			$errors['status'] = array( 'is not included in the list' );
		}
		if ( ! isset( $this->tables['contacts'][ (int) ( $fields['contact_id'] ?? 0 ) ] ) ) {
			$errors['contact'] = array( 'must exist' );
		}
		foreach ( $this->tables[ $table ] as $existing ) {
			if ( ( $existing['reference'] ?? '' ) === ( $fields['reference'] ?? null ) ) {
				$errors['reference'] = array( 'has already been taken' );
			}
		}
		if ( array() !== $errors ) {
			return FakeHttpClient::json( array( 'errors' => $errors ), 422 );
		}
		$lines = array();
		$total = 0;
		foreach ( (array) $fields['line_items_attributes'] as $line ) {
			$line = (array) $line;
			$prod = $this->tables['products'][ (int) ( $line['product_id'] ?? 0 ) ] ?? null;
			foreach ( array( 'product_id', 'unit_price', 'quantity' ) as $field ) {
				if ( ! isset( $line[ $field ] ) || '' === $line[ $field ] ) {
					return FakeHttpClient::json( array( 'errors' => array( 'line_items.' . $field => array( "can't be blank" ) ) ), 422 );
				}
			}
			if ( null === $prod ) {
				return FakeHttpClient::json( array( 'errors' => array( 'line_items.product' => array( 'must exist' ) ) ), 422 );
			}
			$bp = $this->taxFor( $line, $prod );
			if ( null === $bp ) {
				return FakeHttpClient::json( array( 'errors' => array( 'line_items.tax' => array( 'is invalid' ) ) ), 422 );
			}
			$base      = $this->lineAmount( (string) $line['unit_price'], $line['quantity'] );
			$inclusive = ! empty( $line['is_inclusive'] );
			$net       = $inclusive ? intdiv( abs( $base ) * 10000 + intdiv( 10000 + $bp, 2 ), 10000 + $bp ) : $base;
			$tax       = $inclusive ? $base - $net : $this->percent( $base, $bp );
			$total    += $net + $tax;
			$lines[]   = array(
				'product_id'  => (int) $line['product_id'],
				'description' => $line['description'] ?? '',
				'unit_price'  => (string) $line['unit_price'],
				'quantity'    => (string) $line['quantity'],
				'tax_percent' => sprintf( '%d.%02d', intdiv( $bp, 100 ), $bp % 100 ),
				'line_total'  => $this->money( $net + $tax ),
			);
		}
		$id     = $this->newId();
		$record = array(
			'id'          => $id,
			'contact_id'  => (int) $fields['contact_id'],
			'reference'   => (string) $fields['reference'],
			'description' => (string) ( $fields['description'] ?? '' ),
			'status'      => (string) $fields['status'],
			'issue_date'  => (string) $fields['issue_date'],
			'due_date'    => (string) $fields['due_date'],
			'notes'       => null,
			'total'       => $this->money( $total ),
			'paid_amount' => '0.0',
			'due_amount'  => $this->money( $total ),
			'line_items'  => $lines,
			'payments'    => array(),
			'cents'       => $total,
			'paid_cents'  => 0,
		);
		$this->tables[ $table ][ $id ] = $record;
		return FakeHttpClient::json( array( $this->singular( $table ) => $this->present( $table, $record ) ), 201 );
	}

	/**
	 * Create a payment of an invoice.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function createPayment( array $fields ): HttpResponse {
		foreach ( array( 'account_id', 'amount', 'date', 'invoice_id', 'reference' ) as $field ) {
			if ( empty( $fields[ $field ] ) ) {
				return FakeHttpClient::json( array( 'errors' => array( $field => array( "can't be blank" ) ) ), 422 );
			}
		}
		foreach ( $this->tables['invoice_payments'] as $existing ) {
			if ( $existing['reference'] === $fields['reference'] ) {
				return FakeHttpClient::json( array( 'errors' => array( 'reference' => array( 'has already been taken' ) ) ), 422 );
			}
		}
		$invoice = $this->tables['invoices'][ (int) $fields['invoice_id'] ] ?? null;
		if ( null === $invoice || 'Draft' === $invoice['status'] ) {
			return FakeHttpClient::json( array( 'errors' => array( 'invoice' => array( 'must be approved' ) ) ), 422 );
		}
		$cents = $this->lineAmount( (string) $fields['amount'], 1 );
		$this->settle( (int) $fields['invoice_id'], $cents, 'Receipt', 0 );
		$id                                      = $this->newId();
		$this->tables['invoice_payments'][ $id ] = array( 'id' => $id, 'reference' => (string) $fields['reference'], 'invoice_id' => (int) $fields['invoice_id'], 'amount' => (string) $fields['amount'], 'date' => $fields['date'] );
		return FakeHttpClient::json( $this->tables['invoice_payments'][ $id ], 201 );
	}

	/**
	 * Allocate a credit note to an invoice.
	 *
	 * @param int                 $invoiceId Invoice id.
	 * @param array<string,mixed> $body      Body.
	 */
	private function allocate( int $invoiceId, array $body ): HttpResponse {
		if ( $this->refuseAllocations ) {
			return FakeHttpClient::json( array( 'errors' => array( 'allocations' => array( 'cannot be created' ) ) ), 422 );
		}
		$rows = (array) ( $body['invoice']['allocations_attributes'] ?? array() );
		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$note = $this->tables['credit_notes'][ (int) ( $row['source_id'] ?? 0 ) ] ?? null;
			if ( 'CreditNote' !== ( $row['source_type'] ?? '' ) || null === $note || 'Draft' === $note['status'] ) {
				return FakeHttpClient::json( array( 'errors' => array( 'source' => array( 'is invalid' ) ) ), 422 );
			}
			$cents = $this->lineAmount( (string) ( $row['amount'] ?? '0' ), 1 );
			if ( $cents <= 0 || $cents > $this->tables['invoices'][ $invoiceId ]['cents'] - $this->tables['invoices'][ $invoiceId ]['paid_cents'] ) {
				return FakeHttpClient::json( array( 'errors' => array( 'amount' => array( 'is more than is due' ) ) ), 422 );
			}
			$this->settle( $invoiceId, $cents, 'CreditNote', (int) $row['source_id'] );
		}
		return FakeHttpClient::json( array( 'invoice' => $this->present( 'invoices', $this->tables['invoices'][ $invoiceId ] ) ) );
	}

	/**
	 * Record a settlement on an invoice.
	 *
	 * @param int    $invoiceId Invoice.
	 * @param int    $cents     Amount in minor units.
	 * @param string $type      Receipt or CreditNote.
	 * @param int    $sourceId  Source id.
	 */
	private function settle( int $invoiceId, int $cents, string $type, int $sourceId ): void {
		$invoice               = &$this->tables['invoices'][ $invoiceId ];
		$invoice['paid_cents'] += $cents;
		$invoice['paid_amount'] = $this->money( $invoice['paid_cents'] );
		$invoice['due_amount']  = $this->money( $invoice['cents'] - $invoice['paid_cents'] );
		$invoice['payments'][]  = array( 'id' => $this->newId(), 'source_type' => $type, 'source_id' => $sourceId, 'amount' => $this->money( $cents ) );
		$invoice['status']      = $invoice['paid_cents'] >= $invoice['cents'] ? 'Paid' : 'Partially Paid';
	}

	/**
	 * Change a record.
	 *
	 * @param string              $table List name.
	 * @param int                 $id    Record id.
	 * @param array<string,mixed> $body  Body.
	 */
	private function patch( string $table, int $id, array $body ): HttpResponse {
		$changes = (array) ( $body[ $this->singular( $table ) ] ?? array() );
		$record  = $this->tables[ $table ][ $id ];
		if ( 'contacts' !== $table && 'Draft' !== $record['status'] ) {
			$allowed = $this->lockApproved ? array() : array( 'description' );
			if ( array() !== array_diff( array_keys( $changes ), $allowed ) ) {
				return FakeHttpClient::json( array( 'errors' => array( 'base' => array( 'An approved document cannot be changed' ) ) ), 422 );
			}
		}
		if ( isset( $changes['status'] ) && ! in_array( $changes['status'], array( 'Draft', 'Approved' ), true ) ) {
			return FakeHttpClient::json( array( 'errors' => array( 'status' => array( 'is not included in the list' ) ) ), 422 );
		}
		$this->tables[ $table ][ $id ] = array_merge( $record, $changes );
		return FakeHttpClient::json( array( $this->singular( $table ) => $this->present( $table, $this->tables[ $table ][ $id ] ) ) );
	}

	/**
	 * A record as the API shows it.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $row   Stored record.
	 * @return array<string,mixed>
	 */
	private function present( string $table, array $row ): array {
		unset( $row['cents'], $row['paid_cents'] );
		return $row;
	}

	/**
	 * A product row.
	 *
	 * @param int                 $id        Id.
	 * @param string              $sku       SKU.
	 * @param string              $en        English name.
	 * @param string              $ar        Arabic name.
	 * @param string              $tax       Default tax id.
	 * @param array<int,string>   $stock     Stock by branch id.
	 * @param bool                $track     Whether quantity is tracked.
	 * @return array<string,mixed>
	 */
	private function product( int $id, string $sku, string $en, string $ar, string $tax, array $stock, bool $track ): array {
		$inventories = array();
		foreach ( $stock as $branch => $quantity ) {
			$inventories[] = array( 'id' => $branch, 'name_en' => 'Main', 'name_ar' => 'الرئيسي', 'stock' => $quantity );
		}
		return array( 'id' => $id, 'sku' => $sku, 'name_en' => $en, 'name_ar' => $ar, 'tax_id' => $tax, 'track_quantity' => $track, 'type' => 'Product', 'inventories' => $inventories, 'selling_price' => '42.0' );
	}

	/**
	 * The tax of a line in basis points, or null for a tax the server does not know (1 is 15 percent, 2 and 3 are zero, 9 is a custom 5 percent).
	 *
	 * @param array<string,mixed> $line Line.
	 * @param array<string,mixed> $prod Product (its default tax applies when the line names none).
	 */
	private function taxFor( array $line, array $prod ): ?int {
		if ( isset( $line['tax_percent'] ) ) {
			return (int) round( (float) $line['tax_percent'] * 100 );
		}
		$id = (string) ( $line['tax_id'] ?? $prod['tax_id'] );
		return array( '1' => 1500, '2' => 0, '3' => 0, '9' => 500 )[ $id ] ?? null;
	}

	/**
	 * Quantity times unit price in minor units (two decimals), rounded half away from zero.
	 *
	 * @param string    $unit     Unit price as text.
	 * @param int|float|string $quantity Quantity.
	 */
	private function lineAmount( string $unit, int|float|string $quantity ): int {
		return (int) round( (float) number_format( (float) $unit * (float) $quantity, 6, '.', '' ) * ( 10 ** $this->exponent() ) );
	}

	/**
	 * Decimal places of the company currency (the fake's own table).
	 */
	private function exponent(): int {
		return array( 'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JPY' => 0 )[ $this->currency ] ?? 2;
	}

	/**
	 * Tax on a net amount at a rate in basis points, rounded half away from zero.
	 *
	 * @param int $net Net minor units.
	 * @param int $bp  Basis points.
	 */
	private function percent( int $net, int $bp ): int {
		$tax = intdiv( abs( $net ) * $bp + 5000, 10000 );
		return $net < 0 ? -$tax : $tax;
	}

	/**
	 * Minor units as a decimal string with at least one decimal, like "55.0".
	 *
	 * @param int $cents Amount in minor units.
	 */
	private function money( int $cents ): string {
		$exp   = $this->exponent();
		$sign  = $cents < 0 ? '-' : '';
		$digits = str_pad( (string) abs( $cents ), $exp + 1, '0', STR_PAD_LEFT );
		return $sign . ( 0 === $exp ? $digits : substr( $digits, 0, -$exp ) . '.' . substr( $digits, -$exp ) );
	}

	/**
	 * A new id.
	 */
	private function newId(): int {
		return ++$this->counter;
	}
}
