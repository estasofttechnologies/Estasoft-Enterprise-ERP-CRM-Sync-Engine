<?php
/**
 * A small in-memory Wafeq for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the calls the Wafeq connector makes, in the shapes of the reference pages at https://developer.wafeq.com/reference (list answers
 * are `{count,next,previous,results}`, a create answers 201, ids are prefixed strings, `X-Wafeq-Idempotency-Key` replays a stored answer).
 * Field names the reference does not render (the total is `amount`, a tax rate has `rate`) are the ones the connector reads first; the
 * server computes totals with its own integer arithmetic, line by line, so a mistake in the connector shows up as a total mismatch.
 * It models behaviours that once hid bugs elsewhere: a missing or wrong key is a 401, a switched-off API is a 403, lists are paged, and an
 * invoice with a wrong currency or a missing line field is a 400.
 */
final class FakeWafeqServer {

	/**
	 * The key the server accepts.
	 */
	public const KEY = 'wfq-test-key-0123456789';

	/**
	 * Decimal places by currency (the fake's own table).
	 *
	 * @var array<string,int>
	 */
	private const EXPONENTS = array(
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JPY' => 0,
	);

	/**
	 * Records by list name.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $tables = array(
		'contacts'    => array(),
		'items'       => array(),
		'accounts'    => array(),
		'tax-rates'   => array(),
		'invoices'    => array(),
		'credit-notes' => array(),
		'payments'    => array(),
	);

	/**
	 * Rows per page.
	 */
	public int $pageSize = 100;

	/**
	 * Whether the API switch is on (off answers 403).
	 */
	public bool $apiEnabled = true;

	/**
	 * Currency of the organisation.
	 */
	public string $baseCurrency = 'SAR';

	/**
	 * Stored answers by idempotency key.
	 *
	 * @var array<string,HttpResponse>
	 */
	private array $replays = array();

	/**
	 * Counter for ids.
	 */
	private int $counter = 0;

	/**
	 * The next write fails this way.
	 */
	private string $failNext = '';

	/**
	 * Seconds for a scripted rate limit.
	 */
	private int $failSeconds = 0;

	/**
	 * Writes still to pass before the scripted failure (0 means the next write).
	 */
	private int $failSkip = 0;

	/**
	 * The next write is performed and its answer is lost.
	 */
	private bool $loseAnswer = false;

	/**
	 * Reject negative unit amounts.
	 */
	public bool $rejectNegative = false;

	/**
	 * Refuse to apply credit notes (as Wafeq does for an invoice with no balance).
	 */
	public bool $refuseApplications = false;

	/**
	 * Timeouts seen on requests.
	 *
	 * @var array<int,int>
	 */
	public array $timeouts = array();

	/**
	 * Seed a standard organisation: a revenue account, a bank account, two tax rates and two items.
	 */
	public function __construct() {
		$this->tables['accounts']  = array(
			'acc_rev'  => array( 'id' => 'acc_rev', 'name' => 'Sales', 'code' => '4000', 'classification' => 'REVENUE', 'is_payment_enabled' => false ),
			'acc_bank' => array( 'id' => 'acc_bank', 'name' => 'Main bank', 'code' => '1010', 'classification' => 'ASSET', 'is_payment_enabled' => true ),
		);
		$this->tables['tax-rates'] = array(
			'tax_15' => array( 'id' => 'tax_15', 'name' => 'VAT 15%', 'rate' => '15.00', 'tax_type' => 'SALES' ),
			'tax_5'  => array( 'id' => 'tax_5', 'name' => 'VAT 5%', 'rate' => '5.00', 'tax_type' => 'SALES' ),
			'tax_0'  => array( 'id' => 'tax_0', 'name' => 'Zero rated', 'rate' => '0.00', 'tax_type' => 'SALES' ),
		);
		$this->tables['items']     = array(
			'itm_mug' => array( 'id' => 'itm_mug', 'name' => 'Ceramic mug', 'sku' => 'CS-MUG-01', 'external_id' => '', 'is_active' => true ),
			'itm_tee' => array( 'id' => 'itm_tee', 'name' => 'قميص قطني', 'sku' => 'CS-TEE-02', 'external_id' => '', 'is_active' => true ),
			'itm_bowl' => array( 'id' => 'itm_bowl', 'name' => 'Stoneware bowl set', 'sku' => 'CS-BOWL-02', 'external_id' => '', 'is_active' => true ),
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
	 * Perform the next write but lose the answer, as a time-out after the work was done.
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
		$method  = $request['method'];
		$headers = array_change_key_case( $request['headers'], CASE_LOWER );

		if ( 'api.wafeq.com' !== ( $parts['host'] ?? '' ) || 0 !== strpos( $path, '/v1/' ) ) {
			return FakeHttpClient::status( 404, '{"detail":"Not found."}' );
		}
		if ( ( $headers['authorization'] ?? '' ) !== 'Api-Key ' . self::KEY ) {
			return FakeHttpClient::json( array( 'detail' => 'Invalid API key.' ), 401 );
		}
		if ( ! $this->apiEnabled ) {
			return FakeHttpClient::json( array( 'detail' => 'The public API is not enabled for this organization. Enable the API in your settings.' ), 403 );
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

		$key = $headers['x-wafeq-idempotency-key'] ?? '';
		if ( $write && 'POST' === $method && '' !== $key && isset( $this->replays[ $key ] ) ) {
			$stored = $this->replays[ $key ];
			return new HttpResponse( $stored->status, $stored->body, $stored->headers + array( 'x-wafeq-idempotent-replayed' => 'true' ) );
		}

		$route    = trim( substr( $path, 4 ), '/' );
		$segments = '' === $route ? array() : explode( '/', $route );
		$body     = null === $request['body'] || '' === $request['body'] ? array() : (array) json_decode( $request['body'], true );
		$answer   = $this->route( $method, $segments, $query, $body );

		if ( $write && 'POST' === $method && '' !== $key && $answer->status < 300 ) {
			$this->replays[ $key ] = $answer;
		}
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
			'server_error' => FakeHttpClient::status( 500, '{"detail":"Internal error"}' ),
			'auth'         => FakeHttpClient::json( array( 'detail' => 'Invalid API key.' ), 401 ),
			'certificate'  => FakeHttpClient::certificateError(),
			default        => FakeHttpClient::status( 418, 'teapot' ),
		};
	}

	/**
	 * Route a request.
	 *
	 * @param string              $method   Method.
	 * @param array<int,string>   $segments Path after /v1/.
	 * @param array<string,mixed> $query    Query.
	 * @param array<string,mixed> $body     Body.
	 */
	private function route( string $method, array $segments, array $query, array $body ): HttpResponse {
		$table = $segments[0] ?? '';
		if ( 'organization' === $table && 'GET' === $method ) {
			return FakeHttpClient::json( array( 'id' => 'org_1', 'name' => 'مؤسسة الاختبار', 'base_currency' => $this->baseCurrency ) );
		}
		if ( 'invoice-credit-notes' === $table && 'POST' === $method ) {
			return $this->applyCreditNote( $body );
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			return FakeHttpClient::json( array( 'detail' => 'Not found.' ), 404 );
		}
		$id = $segments[1] ?? '';
		if ( '' === $id ) {
			return 'GET' === $method ? $this->listing( $table, $query ) : $this->create( $table, $body );
		}
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
			return FakeHttpClient::json( array( 'detail' => 'Not found.' ), 404 );
		}
		if ( 'GET' === $method ) {
			return FakeHttpClient::json( $this->present( $table, $this->tables[ $table ][ $id ] ) );
		}
		if ( 'PATCH' === $method ) {
			return $this->patch( $table, $id, $body );
		}
		return FakeHttpClient::json( array( 'detail' => 'Method not allowed.' ), 405 );
	}

	/**
	 * A paged list.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $query Query.
	 */
	private function listing( string $table, array $query ): HttpResponse {
		$rows = array_values( $this->tables[ $table ] );
		if ( isset( $query['external_id'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => ( $row['external_id'] ?? '' ) === $query['external_id'] ) );
		}
		if ( isset( $query['keyword'] ) && 'contacts' === $table ) {
			$needle = mb_strtolower( (string) $query['keyword'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( mb_strtolower( $row['name'] . ' ' . ( $row['email'] ?? '' ) ), $needle ) ) );
		}
		if ( isset( $query['tax_type'] ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => ( $row['tax_type'] ?? '' ) === $query['tax_type'] ) );
		}
		$size  = max( 1, min( $this->pageSize, (int) ( $query['page_size'] ?? $this->pageSize ) ) );
		$page  = max( 1, (int) ( $query['page'] ?? 1 ) );
		$slice = array_slice( $rows, ( $page - 1 ) * $size, $size );
		$more  = count( $rows ) > $page * $size;
		$next  = null;
		if ( $more ) {
			$next = 'https://api.wafeq.com/v1/' . $table . '/?' . http_build_query( array_merge( $query, array( 'page' => $page + 1, 'page_size' => $size ) ) );
		}
		return FakeHttpClient::json(
			array(
				'count'    => count( $rows ),
				'next'     => $next,
				'previous' => null,
				'results'  => array_map( fn( array $row ): array => $this->present( $table, $row ), $slice ),
			)
		);
	}

	/**
	 * Create a record.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $body  Body.
	 */
	private function create( string $table, array $body ): HttpResponse {
		return match ( $table ) {
			'contacts'     => $this->createContact( $body ),
			'invoices'     => $this->createDocument( 'invoices', $body ),
			'credit-notes' => $this->createDocument( 'credit-notes', $body ),
			'payments'     => $this->createPayment( $body ),
			default        => FakeHttpClient::json( array( 'detail' => 'Method not allowed.' ), 405 ),
		};
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createContact( array $body ): HttpResponse {
		if ( empty( $body['name'] ) ) {
			return FakeHttpClient::json( array( 'name' => array( 'This field is required.' ) ), 400 );
		}
		$id                              = $this->newId( 'cnt' );
		$this->tables['contacts'][ $id ] = array_merge( array( 'id' => $id, 'email' => '', 'external_id' => '' ), $body );
		return FakeHttpClient::json( $this->tables['contacts'][ $id ], 201 );
	}

	/**
	 * Create an invoice or a credit note.
	 *
	 * @param string              $table invoices or credit-notes.
	 * @param array<string,mixed> $body  Body.
	 */
	private function createDocument( string $table, array $body ): HttpResponse {
		$credit   = 'credit-notes' === $table;
		$required = $credit ? array( 'contact', 'credit_note_date', 'credit_note_number', 'currency', 'line_items' ) : array( 'contact', 'currency', 'invoice_date', 'invoice_due_date', 'invoice_number', 'line_items' );
		$errors   = array();
		foreach ( $required as $field ) {
			if ( empty( $body[ $field ] ) ) {
				$errors[ $field ] = array( 'This field is required.' );
			}
		}
		if ( array() !== $errors ) {
			return FakeHttpClient::json( $errors, 400 );
		}
		if ( ! isset( $this->tables['contacts'][ $body['contact'] ] ) ) {
			return FakeHttpClient::json( array( 'contact' => array( 'Invalid pk - object does not exist.' ) ), 400 );
		}
		$lines = array();
		$total = 0;
		$tax   = 0;
		foreach ( (array) $body['line_items'] as $index => $line ) {
			$line = (array) $line;
			foreach ( array( 'account', 'description', 'quantity', 'unit_amount' ) as $field ) {
				if ( ! array_key_exists( $field, $line ) || '' === $line[ $field ] ) {
					return FakeHttpClient::json( array( 'line_items' => array( $index => array( $field => array( 'This field is required.' ) ) ) ), 400 );
				}
			}
			if ( ! isset( $this->tables['accounts'][ $line['account'] ] ) ) {
				return FakeHttpClient::json( array( 'line_items' => array( $index => array( 'account' => array( 'Invalid pk.' ) ) ) ), 400 );
			}
			if ( $this->rejectNegative && (float) $line['unit_amount'] < 0 ) {
				return FakeHttpClient::json( array( 'line_items' => array( $index => array( 'unit_amount' => array( 'Ensure this value is greater than or equal to 0.' ) ) ) ), 400 );
			}
			$net  = $this->toMinor( $line['quantity'] * 1.0, $line['unit_amount'], (string) $body['currency'] );
			$rate = 0;
			if ( ! empty( $line['tax_rate'] ) ) {
				if ( ! isset( $this->tables['tax-rates'][ $line['tax_rate'] ] ) ) {
					return FakeHttpClient::json( array( 'line_items' => array( $index => array( 'tax_rate' => array( 'Invalid pk.' ) ) ) ), 400 );
				}
				$rate = (int) round( (float) $this->tables['tax-rates'][ $line['tax_rate'] ]['rate'] * 100 );
			}
			$lineTax = $this->percent( $net, $rate );
			$total  += $net + $lineTax;
			$tax    += $lineTax;
			$lines[] = $line + array( 'id' => $this->newId( 'li' ) );
		}
		$id     = $this->newId( $credit ? 'cn' : 'inv' );
		$status = $body['status'] ?? 'DRAFT';
		$record = array_merge(
			$body,
			array(
				'id'           => $id,
				'status'       => $status,
				'line_items'   => $lines,
				'amount'       => $this->fromMinor( $total, (string) $body['currency'] ),
				'tax_amount'   => $this->fromMinor( $tax, (string) $body['currency'] ),
				'external_id'  => $body['external_id'] ?? '',
				'credit_notes' => array(),
			)
		);
		$this->tables[ $table ][ $id ] = $record;
		return FakeHttpClient::json( $this->present( $table, $record ), 201 );
	}

	/**
	 * Create a payment.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function createPayment( array $body ): HttpResponse {
		foreach ( array( 'amount', 'currency', 'date', 'paid_through_account' ) as $field ) {
			if ( empty( $body[ $field ] ) ) {
				return FakeHttpClient::json( array( $field => array( 'This field is required.' ) ), 400 );
			}
		}
		$account = $this->tables['accounts'][ $body['paid_through_account'] ] ?? null;
		if ( null === $account || empty( $account['is_payment_enabled'] ) ) {
			return FakeHttpClient::json( array( 'paid_through_account' => array( 'Must be a valid payment account.' ) ), 400 );
		}
		foreach ( (array) ( $body['invoice_payments'] ?? array() ) as $pay ) {
			$pay = (array) $pay;
			if ( ! isset( $this->tables['invoices'][ $pay['invoice'] ?? '' ] ) ) {
				return FakeHttpClient::json( array( 'invoice_payments' => array( array( 'invoice' => array( 'Invalid pk.' ) ) ) ), 400 );
			}
			if ( 'FINALIZED' !== $this->tables['invoices'][ $pay['invoice'] ]['status'] ) {
				return FakeHttpClient::json( array( 'invoice_payments' => array( array( 'invoice' => array( 'The invoice must be finalized.' ) ) ) ), 400 );
			}
		}
		$id                              = $this->newId( 'pay' );
		$this->tables['payments'][ $id ] = array_merge( array( 'external_id' => '' ), $body, array( 'id' => $id ) );
		return FakeHttpClient::json( $this->tables['payments'][ $id ], 201 );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function applyCreditNote( array $body ): HttpResponse {
		$note    = $this->tables['credit-notes'][ $body['credit_note'] ?? '' ] ?? null;
		$invoice = $this->tables['invoices'][ $body['invoice'] ?? '' ] ?? null;
		if ( $this->refuseApplications ) {
			return FakeHttpClient::json( array( 'detail' => 'The invoice has no balance to apply the credit note to.' ), 400 );
		}
		if ( null === $note || null === $invoice || (float) ( $body['amount'] ?? 0 ) <= 0 ) {
			return FakeHttpClient::json( array( 'detail' => 'Invalid credit note application.' ), 400 );
		}
		if ( 'DRAFT' === $note['status'] || 'DRAFT' === $invoice['status'] ) {
			return FakeHttpClient::json( array( 'detail' => 'Both documents must be posted.' ), 400 );
		}
		if ( $note['currency'] !== $invoice['currency'] || $note['contact'] !== $invoice['contact'] ) {
			return FakeHttpClient::json( array( 'detail' => 'The documents must have the same customer and currency.' ), 400 );
		}
		$this->tables['invoices'][ $invoice['id'] ]['credit_notes'][] = array( 'credit_note' => $note['id'], 'amount' => (string) $body['amount'] );
		return FakeHttpClient::json( array( 'id' => $this->newId( 'ap' ) ), 201 );
	}

	/**
	 * Change a record.
	 *
	 * @param string              $table List name.
	 * @param string              $id    Record id.
	 * @param array<string,mixed> $body  Changes.
	 */
	private function patch( string $table, string $id, array $body ): HttpResponse {
		if ( isset( $body['status'] ) && ! in_array( $body['status'], array( 'DRAFT', 'SENT', 'FINALIZED' ), true ) ) {
			return FakeHttpClient::json( array( 'status' => array( 'Not a valid choice.' ) ), 400 );
		}
		$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $body );
		return FakeHttpClient::json( $this->present( $table, $this->tables[ $table ][ $id ] ) );
	}

	/**
	 * A record as the API shows it.
	 *
	 * @param string              $table List name.
	 * @param array<string,mixed> $row   Stored record.
	 * @return array<string,mixed>
	 */
	private function present( string $table, array $row ): array {
		if ( 'invoices' === $table || 'credit-notes' === $table ) {
			$row['balance'] = $row['amount'];
		}
		return $row;
	}

	/**
	 * A new id.
	 *
	 * @param string $prefix Prefix.
	 */
	private function newId( string $prefix ): string {
		++$this->counter;
		return $prefix . '_' . str_pad( (string) $this->counter, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Quantity times unit amount in minor units, rounded half away from zero (the server's own arithmetic, with decimal strings).
	 *
	 * @param float|int  $quantity Quantity.
	 * @param float|int  $unit     Unit amount.
	 * @param string     $currency Currency.
	 */
	private function toMinor( float|int $quantity, float|int $unit, string $currency ): int {
		$exponent = self::EXPONENTS[ $currency ] ?? 2;
		return (int) round( (float) number_format( (float) $unit * (float) $quantity, 6, '.', '' ) * ( 10 ** $exponent ) );
	}

	/**
	 * Tax on a net amount at a rate in basis points, rounded half away from zero.
	 *
	 * @param int $net Net minor units.
	 * @param int $bp  Basis points.
	 */
	private function percent( int $net, int $bp ): int {
		$value = abs( $net ) * $bp;
		$tax   = intdiv( $value + 5000, 10000 );
		return $net < 0 ? -$tax : $tax;
	}

	/**
	 * Minor units as a decimal string.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 */
	private function fromMinor( int $minor, string $currency ): string {
		$exponent = self::EXPONENTS[ $currency ] ?? 2;
		$sign     = $minor < 0 ? '-' : '';
		$digits   = str_pad( (string) abs( $minor ), $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . ( 0 === $exponent ? $digits : substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent ) );
	}
}
