<?php
/**
 * A small in-memory Frappe/ERPNext site for connector tests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the REST calls the ERPNext connector makes (`/api/resource/...` and `/api/method/...`) the way the Frappe source does:
 * statuses by exception class, `exc_type` and `_server_messages` in error bodies, `limit_page_length` paging, filters on child tables,
 * draft and submitted documents, totals worked out from items and tax rows. It models on purpose what once hides bugs: a second Sales
 * Order with the same `po_no` and customer is refused, `delivery_date` is mandatory, a Sales Invoice date is today unless
 * `set_posting_time` is on, the Customer insert makes its Contact, and a document in another currency than its price list is refused.
 * It does not model everything ERPNext checks.
 */
final class FakeErpnextServer {

	public const ADDRESS = 'https://erp.example.test';

	public const KEY = 'ak_4f8d2c91e7';

	public const SECRET = 'as_9b13c7a05d';

	/**
	 * Documents by DocType and name.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $docs = array();

	/**
	 * Every call: method, path, query, body.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $calls = array();

	/**
	 * Exchange rates by "FROM>TO".
	 *
	 * @var array<string,float>
	 */
	public array $rates = array( 'KWD>AED' => 12.0 );

	/**
	 * An answer to give to the next call that matches, once.
	 *
	 * @var array<int,array{0:string,1:string,2:HttpResponse|callable}>
	 */
	private array $failures = array();

	private int $sequence = 0;

	private int $clock = 0;

	public string $today = '2026-11-02';

	public bool $allowMultipleOrdersPerPo = false;

	public bool $roundedTotalOnly = false;

	/**
	 * @param FakeHttpClient $http Client to answer through.
	 */
	public function __construct( FakeHttpClient $http ) {
		$http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
		$this->seed();
	}

	/**
	 * Give a failure to the next call whose method and path pattern match.
	 *
	 * @param string                  $method  HTTP method or *.
	 * @param string                  $pattern Regular expression for the path.
	 * @param HttpResponse|callable   $answer  Answer.
	 */
	public function failNext( string $method, string $pattern, HttpResponse|callable $answer ): void {
		$this->failures[] = array( strtoupper( $method ), $pattern, $answer );
	}

	/**
	 * Add a document directly.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $doc     Fields.
	 * @return array<string,mixed>
	 */
	public function put( string $doctype, array $doc ): array {
		$doc['name']                 = (string) ( $doc['name'] ?? $this->nextName( $doctype ) );
		$doc['doctype']              = $doctype;
		$doc['docstatus']            = $doc['docstatus'] ?? 0;
		$doc['creation']             = $doc['creation'] ?? $this->stamp();
		$doc['modified']             = $doc['modified'] ?? $doc['creation'];
		$this->docs[ $doctype ][ $doc['name'] ] = $doc;
		return $doc;
	}

	/**
	 * Documents of a DocType.
	 *
	 * @param string $doctype DocType.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $doctype ): array {
		return array_values( $this->docs[ $doctype ] ?? array() );
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
	 * Master data of a small shop in the Emirates.
	 */
	private function seed(): void {
		$this->put( 'Company', array( 'name' => 'Cairo Sand LLC', 'default_currency' => 'AED', 'country' => 'United Arab Emirates', 'docstatus' => 0 ) );
		$this->put( 'Price List', array( 'name' => 'Standard Selling', 'currency' => 'AED', 'enabled' => 1, 'selling' => 1 ) );
		$this->put( 'Price List', array( 'name' => 'Kuwait Selling', 'currency' => 'KWD', 'enabled' => 1, 'selling' => 1 ) );
		$this->put( 'Item', array( 'name' => 'CS-MUG-01', 'item_code' => 'CS-MUG-01', 'item_name' => 'Ceramic mug, sand', 'stock_uom' => 'Nos', 'is_stock_item' => 1, 'disabled' => 0 ) );
		$this->put( 'Item', array( 'name' => 'CS-BOWL-02', 'item_code' => 'CS-BOWL-02', 'item_name' => 'Stoneware bowl set', 'stock_uom' => 'Nos', 'is_stock_item' => 1, 'disabled' => 0 ) );
		$this->put( 'Item', array( 'name' => 'SHIPPING', 'item_code' => 'SHIPPING', 'item_name' => 'Shipping', 'stock_uom' => 'Nos', 'is_stock_item' => 0, 'disabled' => 0 ) );
		$this->put( 'Item', array( 'name' => 'FEE', 'item_code' => 'FEE', 'item_name' => 'Handling fee', 'stock_uom' => 'Nos', 'is_stock_item' => 0, 'disabled' => 0 ) );
		$this->put( 'Item', array( 'name' => 'OLD-ITEM', 'item_code' => 'OLD-ITEM', 'item_name' => 'Retired', 'stock_uom' => 'Nos', 'is_stock_item' => 1, 'disabled' => 1 ) );
		$this->put(
			'Sales Taxes and Charges Template',
			array(
				'name'     => 'UAE VAT 5% - CS',
				'title'    => 'UAE VAT 5%',
				'company'  => 'Cairo Sand LLC',
				'disabled' => 0,
				'taxes'    => array(
					array( 'charge_type' => 'On Net Total', 'account_head' => 'VAT 5% - CS', 'description' => 'VAT 5%', 'rate' => 5, 'included_in_print_rate' => 0, 'cost_center' => 'Main - CS' ),
				),
			)
		);
		$this->put(
			'Sales Taxes and Charges Template',
			array(
				'name'     => 'UAE VAT 5% incl - CS',
				'title'    => 'UAE VAT 5% included',
				'company'  => 'Cairo Sand LLC',
				'disabled' => 0,
				'taxes'    => array(
					array( 'charge_type' => 'On Net Total', 'account_head' => 'VAT 5% - CS', 'description' => 'VAT 5% included', 'rate' => 5, 'included_in_print_rate' => 1, 'cost_center' => 'Main - CS' ),
				),
			)
		);
		$this->put(
			'Sales Taxes and Charges Template',
			array(
				'name'     => 'Compound - CS',
				'title'    => 'Compound',
				'company'  => 'Cairo Sand LLC',
				'disabled' => 0,
				'taxes'    => array(
					array( 'charge_type' => 'On Net Total', 'account_head' => 'A - CS', 'description' => 'A', 'rate' => 5, 'included_in_print_rate' => 1 ),
					array( 'charge_type' => 'On Previous Row Total', 'row_id' => 1, 'account_head' => 'B - CS', 'description' => 'B', 'rate' => 2, 'included_in_print_rate' => 1 ),
				),
			)
		);
		$this->put( 'Warehouse', array( 'name' => 'Stores - CS', 'company' => 'Cairo Sand LLC', 'disabled' => 0, 'is_group' => 0 ) );
		$this->put( 'Warehouse', array( 'name' => 'Showroom - CS', 'company' => 'Cairo Sand LLC', 'disabled' => 0, 'is_group' => 0 ) );
		$this->put( 'Country', array( 'name' => 'United Arab Emirates', 'code' => 'ae' ) );
		$this->put( 'Country', array( 'name' => 'Kuwait', 'code' => 'kw' ) );
		$this->put( 'Account', array( 'name' => 'Bank - CS', 'company' => 'Cairo Sand LLC', 'account_currency' => 'AED', 'is_group' => 0, 'disabled' => 0 ) );
		$this->put( 'Account', array( 'name' => 'Bank KWD - CS', 'company' => 'Cairo Sand LLC', 'account_currency' => 'KWD', 'is_group' => 0, 'disabled' => 0 ) );
		$this->docs['Selling Settings']['Selling Settings'] = array( 'name' => 'Selling Settings', 'customer_group' => 'Individual', 'territory' => 'Rest Of The World' );
	}

	/**
	 * Answer one request.
	 *
	 * @param array<string,mixed> $request Request from the client.
	 */
	private function handle( array $request ): HttpResponse {
		$parts  = parse_url( (string) $request['url'] );
		$path   = rawurldecode( (string) ( $parts['path'] ?? '/' ) );
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$method = (string) $request['method'];
		$body   = null === $request['body'] ? array() : (array) json_decode( (string) $request['body'], true );
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body );

		foreach ( $this->failures as $index => $failure ) {
			if ( ( '*' === $failure[0] || $failure[0] === $method ) && 1 === preg_match( $failure[1], $path ) ) {
				unset( $this->failures[ $index ] );
				return $failure[2] instanceof HttpResponse ? $failure[2] : ( $failure[2] )( $request );
			}
		}

		$authorization = (string) ( $request['headers']['Authorization'] ?? '' );
		if ( 'token ' . self::KEY . ':' . self::SECRET !== $authorization ) {
			return $this->error( 401, 'AuthenticationError', '' );
		}

		if ( 1 === preg_match( '#^/api/method/(.+)$#', $path, $m ) ) {
			return $this->method( $m[1], $query );
		}
		if ( 1 === preg_match( '#^/api/resource/([^/]+)/(.+)$#', $path, $m ) ) {
			return $this->document( $method, $m[1], $m[2], $body );
		}
		if ( 1 === preg_match( '#^/api/resource/([^/]+)$#', $path, $m ) ) {
			return 'GET' === $method ? $this->listing( $m[1], $query ) : $this->insert( $m[1], $body );
		}
		return new HttpResponse( 404, '<!DOCTYPE html><html><body>Page Not Found</body></html>', array( 'content-type' => 'text/html' ) );
	}

	/**
	 * A whitelisted method.
	 *
	 * @param string              $name  Dotted path.
	 * @param array<string,mixed> $query Query.
	 */
	private function method( string $name, array $query ): HttpResponse {
		if ( 'frappe.auth.get_logged_user' === $name ) {
			return $this->ok( array( 'message' => 'sync@example.test' ) );
		}
		if ( 'frappe.utils.change_log.get_versions' === $name ) {
			return $this->ok( array( 'message' => array( 'frappe' => array( 'title' => 'Frappe Framework', 'version' => '15.125.0' ), 'erpnext' => array( 'title' => 'ERPNext', 'version' => '15.52.0' ) ) ) );
		}
		if ( 'erpnext.setup.utils.get_exchange_rate' === $name ) {
			$from = (string) ( $query['from_currency'] ?? '' );
			$to   = (string) ( $query['to_currency'] ?? '' );
			return $this->ok( array( 'message' => $from === $to ? 1 : ( $this->rates[ $from . '>' . $to ] ?? 0.0 ) ) );
		}
		return $this->error( 404, 'DoesNotExistError', 'Method ' . $name . ' not found' );
	}

	/**
	 * GET list.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $query   Query.
	 */
	private function listing( string $doctype, array $query ): HttpResponse {
		$filters = isset( $query['filters'] ) ? (array) json_decode( (string) $query['filters'], true ) : array();
		$fields  = isset( $query['fields'] ) ? (array) json_decode( (string) $query['fields'], true ) : array( 'name' );
		$start   = (int) ( $query['limit_start'] ?? 0 );
		$size    = (int) ( $query['limit_page_length'] ?? 20 );
		$rows    = array_values( array_filter( $this->docs[ $doctype ] ?? array(), fn( array $doc ): bool => $this->matches( $doc, $filters ) ) );
		$order   = (string) ( $query['order_by'] ?? '' );
		if ( 1 === preg_match( '/^(\w+)\s+(asc|desc)$/', $order, $o ) ) {
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a[ $o[1] ] ?? '' ), (string) ( $b[ $o[1] ] ?? '' ) ) * ( 'desc' === $o[2] ? -1 : 1 ) );
		}
		$page = array_slice( $rows, $start, $size );
		$out  = array();
		foreach ( $page as $doc ) {
			$row = array();
			foreach ( $fields as $field ) {
				$row[ $field ] = $doc[ $field ] ?? null;
			}
			$out[] = $row;
		}
		return $this->ok( array( 'data' => $out ) );
	}

	/**
	 * Whether a document matches filters.
	 *
	 * @param array<string,mixed>       $doc     Document.
	 * @param array<int,array<int,mixed>> $filters Filters.
	 */
	private function matches( array $doc, array $filters ): bool {
		foreach ( $filters as $filter ) {
			if ( 4 === count( $filter ) ) {
				$tables = array( 'Sales Invoice Item' => 'items', 'Payment Entry Reference' => 'references', 'Contact Email' => 'email_ids', 'Dynamic Link' => 'links' );
				$rows   = (array) ( $doc[ $tables[ $filter[0] ] ?? '' ] ?? array() );
				$hit    = false;
				foreach ( $rows as $row ) {
					if ( $this->compare( $row[ $filter[1] ] ?? null, (string) $filter[2], $filter[3] ) ) {
						$hit = true;
					}
				}
				if ( ! $hit ) {
					return false;
				}
				continue;
			}
			if ( ! $this->compare( $doc[ $filter[0] ] ?? null, (string) $filter[1], $filter[2] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One comparison.
	 *
	 * @param mixed  $actual   Value in the document.
	 * @param string $operator Operator.
	 * @param mixed  $wanted   Wanted value.
	 */
	private function compare( mixed $actual, string $operator, mixed $wanted ): bool {
		$low = is_scalar( $actual ) ? strtolower( (string) $actual ) : '';
		switch ( $operator ) {
			case '=':
				return $low === strtolower( (string) $wanted );
			case '!=':
				return $low !== strtolower( (string) $wanted );
			case 'in':
				return in_array( $low, array_map( 'strtolower', array_map( 'strval', (array) $wanted ) ), true );
			case 'like':
				$regex = '/^' . str_replace( '%', '.*', preg_quote( strtolower( (string) $wanted ), '/' ) ) . '$/s';
				$regex = str_replace( '\\%', '.*', $regex );
				return 1 === preg_match( $regex, $low );
			case '>=':
				return (string) $actual >= (string) $wanted;
			default:
				return false;
		}
	}

	/**
	 * Single document routes.
	 *
	 * @param string              $method  Method.
	 * @param string              $doctype DocType.
	 * @param string              $name    Name.
	 * @param array<string,mixed> $body    Body.
	 */
	private function document( string $method, string $doctype, string $name, array $body ): HttpResponse {
		$doc = $this->docs[ $doctype ][ $name ] ?? null;
		if ( null === $doc ) {
			return $this->error( 404, 'DoesNotExistError', $doctype . ' ' . $name . ' not found' );
		}
		if ( 'GET' === $method ) {
			return $this->ok( array( 'data' => $doc ) );
		}
		if ( 'PUT' === $method ) {
			if ( isset( $body['docstatus'] ) && 1 === (int) $body['docstatus'] && 0 === (int) $doc['docstatus'] ) {
				$doc['docstatus'] = 1;
				$doc              = $this->afterSubmit( $doctype, $doc );
				unset( $body['docstatus'] );
			}
			$doc = array_merge( $doc, $body );
			$this->docs[ $doctype ][ $name ] = $doc;
			return $this->ok( array( 'data' => $doc ) );
		}
		return $this->error( 417, 'ValidationError', 'Not supported by the fake' );
	}

	/**
	 * What submitting does.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $doc     Document.
	 * @return array<string,mixed>
	 */
	private function afterSubmit( string $doctype, array $doc ): array {
		if ( 'Sales Order' === $doctype ) {
			$doc['status'] = 'To Deliver and Bill';
		}
		if ( 'Sales Invoice' === $doctype ) {
			$doc['status']             = ! empty( $doc['is_return'] ) ? 'Return' : 'Unpaid';
			$doc['outstanding_amount'] = $doc['grand_total'];
		}
		if ( 'Payment Entry' === $doctype ) {
			foreach ( (array) ( $doc['references'] ?? array() ) as $ref ) {
				$invoice = $this->docs['Sales Invoice'][ $ref['reference_name'] ] ?? null;
				if ( null !== $invoice ) {
					$invoice['outstanding_amount'] = round( (float) $invoice['outstanding_amount'] - (float) $ref['allocated_amount'], 3 );
					$invoice['status']             = $invoice['outstanding_amount'] <= 0 ? 'Paid' : 'Partly Paid';
					$this->docs['Sales Invoice'][ $ref['reference_name'] ] = $invoice;
				}
			}
		}
		return $doc;
	}

	/**
	 * POST insert.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $body    Document.
	 */
	private function insert( string $doctype, array $body ): HttpResponse {
		$problem = $this->validate( $doctype, $body );
		if ( null !== $problem ) {
			return $problem;
		}
		$doc = $body;
		if ( 'Customer' === $doctype ) {
			$doc = $this->newCustomer( $doc );
		}
		if ( 'Sales Order' === $doctype || 'Sales Invoice' === $doctype ) {
			$doc = $this->totals( $doctype, $doc );
		}
		if ( 'Sales Order' === $doctype ) {
			$doc['contact_email'] = (string) ( $this->docs['Customer'][ $doc['customer'] ]['email_id'] ?? '' );
		}
		if ( 'Sales Invoice' === $doctype ) {
			$doc['is_return'] = (int) ( $doc['is_return'] ?? 0 );
		}
		if ( 'Sales Invoice' === $doctype && empty( $doc['set_posting_time'] ) ) {
			$doc['posting_date'] = $this->today;
		}
		if ( 'Payment Entry' === $doctype ) {
			$doc['paid_from'] = 'Debtors - CS';
		}
		unset( $doc['docstatus'] );
		return $this->ok( array( 'data' => $this->put( $doctype, $doc ) ) );
	}

	/**
	 * Checks ERPNext would make.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $doc     Document.
	 */
	private function validate( string $doctype, array $doc ): ?HttpResponse {
		if ( 'Sales Order' === $doctype ) {
			if ( empty( $doc['delivery_date'] ) ) {
				return $this->error( 417, 'ValidationError', 'Please enter Delivery Date' );
			}
			if ( empty( $this->docs['Customer'][ $doc['customer'] ?? '' ] ) ) {
				return $this->error( 417, 'LinkValidationError', 'Could not find Customer: ' . ( $doc['customer'] ?? '' ) );
			}
			$list = $this->docs['Price List'][ $doc['selling_price_list'] ?? '' ] ?? null;
			if ( null === $list || $list['currency'] !== ( $doc['currency'] ?? '' ) ) {
				return $this->error( 417, 'ValidationError', 'Price List currency must match the document currency' );
			}
			foreach ( (array) ( $doc['items'] ?? array() ) as $row ) {
				if ( empty( $this->docs['Item'][ $row['item_code'] ?? '' ] ) ) {
					return $this->error( 417, 'LinkValidationError', 'Could not find Item: ' . ( $row['item_code'] ?? '' ) );
				}
			}
			if ( ! $this->allowMultipleOrdersPerPo && ! empty( $doc['po_no'] ) ) {
				foreach ( $this->docs['Sales Order'] ?? array() as $other ) {
					if ( (string) $other['po_no'] === (string) $doc['po_no'] && $other['customer'] === $doc['customer'] && (int) $other['docstatus'] < 2 ) {
						return $this->error( 417, 'ValidationError', "Sales Order {$other['name']} already exists against Customer's Purchase Order {$doc['po_no']}." );
					}
				}
			}
		}
		if ( 'Sales Invoice' === $doctype && ! empty( $doc['is_return'] ) && ! empty( $doc['return_against'] ) ) {
			$original = $this->docs['Sales Invoice'][ $doc['return_against'] ] ?? null;
			if ( null === $original || 1 !== (int) $original['docstatus'] ) {
				return $this->error( 417, 'ValidationError', 'Invalid Return Against: ' . $doc['return_against'] );
			}
			foreach ( (array) $doc['items'] as $row ) {
				$found = false;
				foreach ( (array) $original['items'] as $orig ) {
					if ( $orig['item_code'] === $row['item_code'] && abs( (float) $row['qty'] ) <= (float) $orig['qty'] ) {
						$found = true;
					}
				}
				if ( ! $found ) {
					return $this->error( 417, 'ValidationError', 'Row: Item ' . $row['item_code'] . ' returned more than invoiced' );
				}
			}
		}
		if ( 'Payment Entry' === $doctype ) {
			foreach ( (array) ( $doc['references'] ?? array() ) as $ref ) {
				if ( empty( $this->docs['Sales Invoice'][ $ref['reference_name'] ?? '' ] ) ) {
					return $this->error( 417, 'LinkValidationError', 'Could not find Sales Invoice' );
				}
			}
		}
		return null;
	}

	/**
	 * A new customer with its primary contact.
	 *
	 * @param array<string,mixed> $doc Customer.
	 * @return array<string,mixed>
	 */
	private function newCustomer( array $doc ): array {
		$doc['name'] = (string) $doc['customer_name'];
		$doc['disabled'] = 0;
		if ( ! empty( $doc['email_id'] ) ) {
			$contact = $this->put(
				'Contact',
				array(
					'first_name' => $doc['first_name'] ?? $doc['customer_name'],
					'email_ids'  => array( array( 'email_id' => $doc['email_id'], 'is_primary' => 1 ) ),
					'links'      => array( array( 'link_doctype' => 'Customer', 'link_name' => $doc['name'] ) ),
				)
			);
			$doc['customer_primary_contact'] = $contact['name'];
			$doc['contact_email']            = $doc['email_id'];
		}
		return $doc;
	}

	/**
	 * Work out item amounts, taxes and totals like calculate_taxes_and_totals does for On Net Total rows.
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $doc     Document.
	 * @return array<string,mixed>
	 */
	private function totals( string $doctype, array $doc ): array {
		$currency = (string) ( $doc['currency'] ?? 'AED' );
		$digits   = in_array( $currency, array( 'KWD', 'BHD', 'OMR' ), true ) ? 3 : 2;
		$net      = 0.0;
		$rate     = 0.0;
		$included = false;
		foreach ( (array) ( $doc['taxes'] ?? array() ) as $row ) {
			if ( 'On Net Total' === ( $row['charge_type'] ?? '' ) ) {
				$rate += (float) $row['rate'];
			}
			$included = $included || 1 === (int) ( $row['included_in_print_rate'] ?? 0 );
		}
		$items = array();
		foreach ( array_values( (array) ( $doc['items'] ?? array() ) ) as $index => $row ) {
			$row['name']   = 'row-' . ( $index + 1 ) . '-' . ++$this->sequence;
			$row['amount'] = round( (float) $row['qty'] * (float) $row['rate'], $digits );
			$net          += $row['amount'];
			$items[]       = $row;
		}
		$doc['items'] = $items;
		$net          = $included ? $net / ( 1 + $rate / 100 ) : $net;
		$net         -= (float) ( $doc['discount_amount'] ?? 0 ) / ( $included ? ( 1 + $rate / 100 ) : 1 );
		$doc['net_total']   = round( $net, $digits );
		$doc['grand_total'] = round( $net * ( 1 + $rate / 100 ), $digits );
		if ( 'Sales Order' === $doctype ) {
			$doc['status']          = 'Draft';
			$doc['transaction_date'] = (string) $doc['transaction_date'];
		}
		if ( 'Sales Invoice' === $doctype ) {
			$doc['status']             = 'Draft';
			$doc['outstanding_amount'] = $doc['grand_total'];
		}
		return $doc;
	}

	/**
	 * Success.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function ok( array $body ): HttpResponse {
		return new HttpResponse( 200, (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), array( 'content-type' => 'application/json' ) );
	}

	/**
	 * A Frappe error answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $type    Exception class name.
	 * @param string $message Server message.
	 */
	private function error( int $status, string $type, string $message ): HttpResponse {
		$body = array( 'exc_type' => $type );
		if ( '' !== $message ) {
			$body['_server_messages'] = json_encode( array( json_encode( array( 'message' => $message, 'indicator' => 'red' ) ) ) );
		}
		return new HttpResponse( $status, (string) json_encode( $body ), array( 'content-type' => 'application/json' ) );
	}

	/**
	 * Next generated name.
	 *
	 * @param string $doctype DocType.
	 */
	private function nextName( string $doctype ): string {
		$prefix = array(
			'Sales Order'   => 'SAL-ORD-2026-',
			'Sales Invoice' => 'ACC-SINV-2026-',
			'Payment Entry' => 'ACC-PAY-2026-',
			'Address'       => 'Address-',
			'Contact'       => 'Contact-',
			'Delivery Note' => 'MAT-DN-2026-',
			'Comment'       => 'CMT-',
		);
		++$this->sequence;
		return ( $prefix[ $doctype ] ?? strtoupper( substr( $doctype, 0, 3 ) ) . '-' ) . str_pad( (string) $this->sequence, 5, '0', STR_PAD_LEFT );
	}

	/**
	 * A creation time that grows with every document, so ordering by creation is stable.
	 */
	private function stamp(): string {
		++$this->clock;
		return $this->today . ' 10:' . str_pad( (string) intdiv( $this->clock, 60 ), 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) ( $this->clock % 60 ), 2, '0', STR_PAD_LEFT );
	}
}
