<?php
/**
 * A small in-memory Business Central for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers the API v2.0 calls the connector makes, over FakeHttpClient: the company list, customers, items, tax groups, sales orders,
 * sales invoices and sales credit memos with their lines, the `post` and `shipAndInvoice` actions, OData filters and paging. It follows the
 * shapes of Microsoft's pages: 201 with the new record, 204 for actions, `{"error":{"code","message"}}`, `lastModifiedDateTime`, GUID ids.
 *
 * It models on purpose what once hid bugs in other connectors: a text filter that is case sensitive unless tolower() is used, the local
 * currency code shown in `currencyCode`, a currency that is not set up, prices with or without tax by customer, totals computed by the
 * server (rounded at the currency's decimals), a shipped-and-invoiced order that disappears into a posted invoice, a posted invoice whose
 * number differs from the draft's, PATCH and actions that need `If-Match`, and a lost answer after the write succeeded.
 */
final class FakeBusinessCentralServer {

	public const TENANT        = '11111111-2222-3333-4444-555555555555';
	public const CLIENT_ID     = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	public const SECRET        = 'fake-client-secret-value-9f3a';
	public const COMPANY_ID    = '824820a2-508c-ed11-aada-000d3a298ab3';
	public const ENVIRONMENT   = 'production';
	public const RESOURCE      = 'https://api.businesscentral.dynamics.com';

	/**
	 * The token endpoint.
	 */
	public FakeMicrosoftEntra $entra;

	/**
	 * Filters and paging.
	 */
	public FakeMicrosoftOData $odata;

	/**
	 * Local currency of the company.
	 */
	public string $lcy = 'AED';

	/**
	 * Currencies set up besides the local one.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'USD', 'KWD', 'EUR' );

	/**
	 * Whether the customers of the company treat prices as including tax.
	 */
	public bool $pricesIncludeTax = false;

	/**
	 * Tax percent by tax group code.
	 *
	 * @var array<string,float>
	 */
	public array $taxRates = array(
		'VAT5' => 5.0,
		'ZERO' => 0.0,
	);

	/**
	 * Tax group an item or a line gets when it names none.
	 */
	public string $defaultTaxGroup = 'VAT5';

	/**
	 * Companies.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $companies = array();

	/**
	 * Records by collection: customers, items, taxGroups, salesOrders, salesInvoices, salesCreditMemos.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $data = array();

	/**
	 * Lines by document id.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $lines = array();

	/**
	 * Failure for the next write: timeout, server_error, unknown_fault, auth, certificate, rate_limit, conflict.
	 */
	public string $failNextWrite = '';

	/**
	 * Seconds for rate_limit.
	 */
	public int $retryAfter = 0;

	/**
	 * Fail the Nth next write call of the given kind (0 = the next one), then clear. Used to cut a flow in the middle.
	 *
	 * @var array<string,int>
	 */
	public array $failAfterWrites = array();

	/**
	 * Substrings of URLs whose next write is applied but whose answer is lost (the caller sees a time-out).
	 *
	 * @var array<int,string>
	 */
	public array $loseAnswers = array();

	/**
	 * Count of write requests handled.
	 */
	public int $writes = 0;

	/**
	 * Headers of the actions called, for tests.
	 *
	 * @var array<int,array{action:string,if_match:?string}>
	 */
	public array $actions = array();

	/**
	 * Counter for numbers.
	 */
	private int $sequence = 0;

	/**
	 * @param FakeHttpClient $http The client to answer.
	 */
	public function __construct( private readonly FakeHttpClient $http ) {
		$this->entra = new FakeMicrosoftEntra( self::TENANT, self::CLIENT_ID, self::SECRET, self::RESOURCE );
		$this->odata = new FakeMicrosoftOData();
		$this->companies = array(
			array(
				'id'            => self::COMPANY_ID,
				'systemVersion' => '21.2.49946.51685',
				'name'          => 'CRONUS',
				'displayName'   => 'CRONUS International Ltd.',
			),
		);
		foreach ( array( 'customers', 'items', 'taxGroups', 'salesOrders', 'salesInvoices', 'salesCreditMemos' ) as $collection ) {
			$this->data[ $collection ] = array();
		}
		foreach ( array_keys( $this->taxRates ) as $code ) {
			$this->data['taxGroups'][] = array(
				'id'          => $this->guid(),
				'code'        => $code,
				'displayName' => 'Group ' . $code,
				'taxType'     => 'VAT',
			);
		}
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
	}

	/**
	 * Add an item.
	 *
	 * @param string              $number Item number (the SKU).
	 * @param array<string,mixed> $more   More fields.
	 * @return array<string,mixed>
	 */
	public function addItem( string $number, array $more = array() ): array {
		$item                  = $more + array(
			'id'            => $this->guid(),
			'number'        => $number,
			'displayName'   => 'Item ' . $number,
			'type'          => 'Inventory',
			'blocked'       => false,
			'gtin'          => '',
			'unitPrice'     => 10.0,
			'taxGroupCode'  => $this->defaultTaxGroup,
			'inventory'     => 100,
		);
		$this->data['items'][] = $item;
		return $item;
	}

	/**
	 * Add a customer.
	 *
	 * @param string              $email Email.
	 * @param string              $name  Display name.
	 * @param array<string,mixed> $more  More fields.
	 * @return array<string,mixed>
	 */
	public function addCustomer( string $email, string $name, array $more = array() ): array {
		$customer                = $more + array(
			'id'                    => $this->guid(),
			'number'                => (string) ( 10000 + count( $this->data['customers'] ) * 10 ),
			'displayName'           => $name,
			'type'                  => 'Company',
			'email'                 => $email,
			'phoneNumber'           => '',
			'taxRegistrationNumber' => '',
			'blocked'               => ' ',
		);
		$this->data['customers'][] = $customer;
		return $customer;
	}

	/**
	 * The records of a collection.
	 *
	 * @param string $collection Collection name.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $collection ): array {
		return $this->data[ $collection ];
	}

	/**
	 * Route a request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function handle( array $request ): HttpResponse {
		$url = $request['url'];
		if ( $this->entra->handles( $url ) ) {
			return $this->entra->answer( $request );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 1 !== preg_match( '#^/v2\.0/([^/]+)/api/v2\.0/(.*)$#', $path, $found ) ) {
			return FakeHttpClient::status( 404, '' );
		}
		if ( self::ENVIRONMENT !== rawurldecode( $found[1] ) && 'sandbox' !== rawurldecode( $found[1] ) ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The environment does not exist.' ), 404 );
		}
		$auth  = $request['headers']['Authorization'] ?? '';
		if ( ! str_starts_with( $auth, 'Bearer ' ) || ! $this->entra->accepts( substr( $auth, 7 ) ) ) {
			return FakeHttpClient::json( $this->error( 'Unauthorized', 'The request is not authorized.' ), 401 );
		}
		$write = 'GET' !== $request['method'];
		if ( $write ) {
			$failure = $this->writeFailure( $request );
			if ( null !== $failure ) {
				return $failure;
			}
		}
		$response = $this->route( $request, '/' . $found[2], $url );
		if ( $write ) {
			foreach ( $this->loseAnswers as $key => $needle ) {
				if ( str_contains( $url, $needle ) ) {
					unset( $this->loseAnswers[ $key ] );
					return FakeHttpClient::timeout();
				}
			}
		}
		return $response;
	}

	/**
	 * Decide whether this write fails.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function writeFailure( array $request ): ?HttpResponse {
		++$this->writes;
		$kind = $this->failNextWrite;
		foreach ( $this->failAfterWrites as $key => $left ) {
			if ( str_contains( (string) $request['url'], $key ) || '*' === $key ) {
				if ( $left <= 0 ) {
					unset( $this->failAfterWrites[ $key ] );
					$kind = 'timeout';
					break;
				}
				--$this->failAfterWrites[ $key ];
			}
		}
		if ( '' === $kind ) {
			return null;
		}
		$this->failNextWrite = '';
		return match ( $kind ) {
			'timeout'       => FakeHttpClient::timeout(),
			'certificate'   => FakeHttpClient::certificateError(),
			'server_error'  => FakeHttpClient::json( $this->error( 'Internal_ServerError', 'The server hit an error.' ), 500 ),
			'unknown_fault' => FakeHttpClient::json( $this->error( 'Internal_Unknown', 'Something unexpected happened.' ), 500 ),
			'auth'          => FakeHttpClient::json( $this->error( 'Unauthorized', 'The request is not authorized.' ), 401 ),
			'rate_limit'    => FakeHttpClient::tooManyRequests( $this->retryAfter ),
			'conflict'      => FakeHttpClient::json( $this->error( 'Internal_Conflict', 'The record was changed by another user.' ), 409 ),
			default         => null,
		};
	}

	/**
	 * Route by path.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $path    Path after api/v2.0.
	 * @param string              $url     Full URL.
	 */
	private function route( array $request, string $path, string $url ): HttpResponse {
		$method   = (string) $request['method'];
		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );
		if ( array() === $segments || 1 !== preg_match( '/^companies(?:\(([0-9a-f-]{36})\))?$/i', $segments[0], $match ) ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'Unknown resource.' ), 404 );
		}
		if ( ! isset( $match[1] ) ) {
			return $this->list( $this->companies, $url, 'https://api.businesscentral.dynamics.com/v2.0/' . self::ENVIRONMENT . '/api/v2.0/companies' );
		}
		$known = array_values( array_filter( $this->companies, static fn( array $company ): bool => strtolower( (string) $company['id'] ) === strtolower( $match[1] ) ) );
		if ( array() === $known ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The company does not exist.' ), 404 );
		}
		if ( ! isset( $segments[1] ) ) {
			return FakeHttpClient::json( $known[0] );
		}
		if ( 1 !== preg_match( '/^(\w+)(?:\(([0-9a-f-]{36})\))?$/i', $segments[1], $part ) || ! isset( $this->data[ $part[1] ] ) ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'Unknown resource.' ), 404 );
		}
		$collection = $part[1];
		$id         = $part[2] ?? null;
		$sub        = $segments[2] ?? null;
		if ( null === $id ) {
			return 'GET' === $method
				? $this->list( $this->data[ $collection ], $url, 'https://api.businesscentral.dynamics.com/v2.0/' . self::ENVIRONMENT . '/api/v2.0/companies(' . self::COMPANY_ID . ')/' . $collection, $collection )
				: $this->create( $collection, $this->body( $request ) );
		}
		$index = $this->indexOf( $collection, strtolower( $id ) );
		if ( null === $index ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The record does not exist.' ), 404 );
		}
		if ( null === $sub ) {
			return $this->record( $method, $collection, $index, $request );
		}
		if ( str_starts_with( $sub, 'Microsoft.NAV.' ) ) {
			return $this->action( $collection, $index, substr( $sub, 14 ), $request );
		}
		if ( in_array( $sub, array( 'salesOrderLines', 'salesInvoiceLines', 'salesCreditMemoLines' ), true ) ) {
			$docId = (string) $this->data[ $collection ][ $index ]['id'];
			return 'GET' === $method
				? $this->list( $this->lines[ $docId ] ?? array(), $url, 'https://api.businesscentral.dynamics.com/v2.0/' . self::ENVIRONMENT . '/api/v2.0/companies(' . self::COMPANY_ID . ')/' . $collection . '(' . $docId . ')/' . $sub )
				: $this->createLine( $collection, $index, $this->body( $request ) );
		}
		return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'Unknown resource.' ), 404 );
	}

	/**
	 * A list answer.
	 *
	 * @param array<int,array<string,mixed>> $rows       Rows.
	 * @param string                         $url        URL.
	 * @param string                         $base       URL without query.
	 * @param string                         $collection Collection name for $expand.
	 */
	private function list( array $rows, string $url, string $base, string $collection = '' ): HttpResponse {
		$options = $this->odata->options( $url );
		if ( 'salesCreditMemos' === $collection && isset( $options['$expand'] ) ) {
			foreach ( $rows as $key => $row ) {
				$rows[ $key ]['salesCreditMemoLines'] = $this->lines[ (string) $row['id'] ] ?? array();
			}
		}
		$answer = $this->odata->query( $this->withEtag( $rows ), $url, $base );
		return FakeHttpClient::json( $answer['body'], $answer['status'] );
	}

	/**
	 * Add the version tag every record carries.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function withEtag( array $rows ): array {
		return $rows;
	}

	/**
	 * A single record.
	 *
	 * @param string              $method     Method.
	 * @param string              $collection Collection.
	 * @param int                 $index      Index.
	 * @param array<string,mixed> $request    Request.
	 */
	private function record( string $method, string $collection, int $index, array $request ): HttpResponse {
		if ( 'GET' === $method ) {
			return FakeHttpClient::json( $this->data[ $collection ][ $index ] );
		}
		if ( 'PATCH' === $method ) {
			if ( ( $request['headers']['If-Match'] ?? '' ) === '' ) {
				return FakeHttpClient::json( $this->error( 'BadRequest_ETagRequired', 'The If-Match header is required for PATCH.' ), 400 );
			}
			$this->data[ $collection ][ $index ] = $this->body( $request ) + $this->data[ $collection ][ $index ];
			return FakeHttpClient::json( $this->data[ $collection ][ $index ] );
		}
		if ( 'DELETE' === $method ) {
			$docId = (string) $this->data[ $collection ][ $index ]['id'];
			unset( $this->data[ $collection ][ $index ], $this->lines[ $docId ] );
			$this->data[ $collection ] = array_values( $this->data[ $collection ] );
			return FakeHttpClient::status( 204 );
		}
		return FakeHttpClient::status( 405 );
	}

	/**
	 * Create a record.
	 *
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $body       Fields.
	 */
	private function create( string $collection, array $body ): HttpResponse {
		$now = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		if ( in_array( $collection, array( 'salesOrders', 'salesInvoices', 'salesCreditMemos' ), true ) ) {
			return $this->createDocument( $collection, $body, $now );
		}
		if ( 'customers' === $collection ) {
			if ( '' === trim( (string) ( $body['displayName'] ?? '' ) ) ) {
				return FakeHttpClient::json( $this->error( 'BadRequest_Validation', 'The Name field must be filled in.' ), 400 );
			}
			$record = $body + array(
				'id'                    => $this->guid(),
				'number'                => (string) ( 10000 + count( $this->data['customers'] ) * 10 ),
				'type'                  => 'Company',
				'blocked'               => ' ',
				'lastModifiedDateTime'  => $now,
			);
			$this->data['customers'][] = $record;
			return FakeHttpClient::json( $record, 201 );
		}
		return FakeHttpClient::json( $this->error( 'BadRequest_MethodNotAllowed', 'Read only.' ), 405 );
	}

	/**
	 * Create a sales document header.
	 *
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $body       Fields.
	 * @param string              $now        Time text.
	 */
	private function createDocument( string $collection, array $body, string $now ): HttpResponse {
		$customer = null;
		foreach ( $this->data['customers'] as $row ) {
			if ( (string) $row['number'] === (string) ( $body['customerNumber'] ?? '' ) ) {
				$customer = $row;
			}
		}
		if ( null === $customer ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The customer does not exist.' ), 400 );
		}
		$currency = strtoupper( (string) ( $body['currencyCode'] ?? $this->lcy ) );
		if ( $currency !== $this->lcy && ! in_array( $currency, $this->currencies, true ) ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The currency code ' . $currency . ' does not exist.' ), 400 );
		}
		$this->sequence++;
		$prefix = array(
			'salesOrders'      => 'SO',
			'salesInvoices'    => 'DRAFT-INV',
			'salesCreditMemos' => 'DRAFT-CM',
		)[ $collection ];
		$record = $body + array(
			'id'                       => $this->guid(),
			'number'                   => $prefix . '-' . $this->sequence,
			'externalDocumentNumber'   => '',
			'customerId'               => $customer['id'],
			'customerNumber'           => $customer['number'],
			'customerName'             => $customer['displayName'],
			'email'                    => $customer['email'],
			'currencyCode'             => $currency,
			'pricesIncludeTax'         => $this->pricesIncludeTax,
			'totalAmountExcludingTax'  => 0,
			'totalTaxAmount'           => 0,
			'totalAmountIncludingTax'  => 0,
			'status'                   => 'Draft',
			'lastModifiedDateTime'     => $now,
		);
		$record['currencyCode'] = $currency;
		$dateField              = array(
			'salesOrders'      => 'orderDate',
			'salesInvoices'    => 'invoiceDate',
			'salesCreditMemos' => 'creditMemoDate',
		)[ $collection ];
		$record[ $dateField ]   = $record[ $dateField ] ?? gmdate( 'Y-m-d' );
		$this->data[ $collection ][] = $record;
		$this->lines[ (string) $record['id'] ] = array();
		return FakeHttpClient::json( $record, 201 );
	}

	/**
	 * Create a line and recompute the totals.
	 *
	 * @param string              $collection Collection of the document.
	 * @param int                 $index      Index of the document.
	 * @param array<string,mixed> $body       Line fields.
	 */
	private function createLine( string $collection, int $index, array $body ): HttpResponse {
		$doc   = $this->data[ $collection ][ $index ];
		$docId = (string) $doc['id'];
		$type  = (string) ( $body['lineType'] ?? '' );
		if ( ! in_array( $type, array( 'Comment', 'Item', 'Account', 'Resource', 'Charge' ), true ) ) {
			return FakeHttpClient::json( $this->error( 'BadRequest_Validation', 'The line type is not valid.' ), 400 );
		}
		$line = $body + array(
			'id'         => $this->guid(),
			'documentId' => $docId,
			'sequence'   => 10000 * ( count( $this->lines[ $docId ] ) + 1 ),
		);
		if ( 'Item' === $type ) {
			$item = null;
			foreach ( $this->data['items'] as $row ) {
				if ( strcasecmp( (string) $row['number'], (string) ( $body['lineObjectNumber'] ?? '' ) ) === 0 ) {
					$item = $row;
				}
			}
			if ( null === $item ) {
				return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'The item ' . ( $body['lineObjectNumber'] ?? '' ) . ' does not exist.' ), 400 );
			}
			$line['itemId']  = $item['id'];
			$line['taxCode'] = $line['taxCode'] ?? $item['taxGroupCode'];
		} elseif ( 'Account' === $type ) {
			$line['taxCode'] = $line['taxCode'] ?? $this->defaultTaxGroup;
		}
		$exponent = $this->exponent( (string) $doc['currencyCode'] );
		if ( 'Comment' !== $type ) {
			$rate     = $this->taxRates[ (string) ( $line['taxCode'] ?? '' ) ] ?? 0.0;
			$gross    = (float) ( $body['quantity'] ?? 0 ) * (float) ( $body['unitPrice'] ?? 0 );
			if ( true === $doc['pricesIncludeTax'] ) {
				$including = round( $gross, $exponent );
				$excluding = round( $gross / ( 1 + $rate / 100 ), $exponent );
			} else {
				$excluding = round( $gross, $exponent );
				$including = round( $excluding * ( 1 + $rate / 100 ), $exponent );
			}
			$line['amountExcludingTax'] = $excluding;
			$line['amountIncludingTax'] = $including;
			$line['totalTaxAmount']     = round( $including - $excluding, $exponent );
			$line['taxPercent']         = $rate;
		}
		$this->lines[ $docId ][] = $line;
		$this->recompute( $collection, $index );
		return FakeHttpClient::json( $line, 201 );
	}

	/**
	 * Recompute the totals of a document.
	 *
	 * @param string $collection Collection.
	 * @param int    $index      Index.
	 */
	private function recompute( string $collection, int $index ): void {
		$doc   = $this->data[ $collection ][ $index ];
		$sumEx = 0.0;
		$sumIn = 0.0;
		foreach ( $this->lines[ (string) $doc['id'] ] as $line ) {
			$sumEx += (float) ( $line['amountExcludingTax'] ?? 0 );
			$sumIn += (float) ( $line['amountIncludingTax'] ?? 0 );
		}
		$exponent = $this->exponent( (string) $doc['currencyCode'] );
		$this->data[ $collection ][ $index ]['totalAmountExcludingTax'] = round( $sumEx, $exponent );
		$this->data[ $collection ][ $index ]['totalAmountIncludingTax'] = round( $sumIn, $exponent );
		$this->data[ $collection ][ $index ]['totalTaxAmount']          = round( $sumIn - $sumEx, $exponent );
	}

	/**
	 * Run a bound action.
	 *
	 * @param string              $collection Collection.
	 * @param int                 $index      Index.
	 * @param string              $action     Action name.
	 * @param array<string,mixed> $request    Request.
	 */
	private function action( string $collection, int $index, string $action, array $request ): HttpResponse {
		$this->actions[] = array(
			'action'   => $collection . '.' . $action,
			'if_match' => $request['headers']['If-Match'] ?? null,
		);
		if ( 'POST' !== $request['method'] ) {
			return FakeHttpClient::status( 405 );
		}
		if ( 'salesOrders' === $collection && 'shipAndInvoice' === $action ) {
			$order = $this->data['salesOrders'][ $index ];
			$this->postedFrom( 'salesInvoices', $order, 'PINV-' );
			$docId = (string) $order['id'];
			unset( $this->data['salesOrders'][ $index ], $this->lines[ $docId ] );
			$this->data['salesOrders'] = array_values( $this->data['salesOrders'] );
			return FakeHttpClient::status( 204 );
		}
		if ( in_array( $collection, array( 'salesInvoices', 'salesCreditMemos' ), true ) && 'post' === $action ) {
			if ( 'Draft' !== $this->data[ $collection ][ $index ]['status'] ) {
				return FakeHttpClient::json( $this->error( 'Internal_Validation', 'The document is already posted.' ), 400 );
			}
			$this->data[ $collection ][ $index ]['status'] = 'Open';
			$this->data[ $collection ][ $index ]['number'] = ( 'salesInvoices' === $collection ? 'PINV-' : 'PCM-' ) . $this->sequence++;
			return FakeHttpClient::status( 204 );
		}
		return FakeHttpClient::json( $this->error( 'BadRequest_NotFound', 'Unknown action.' ), 404 );
	}

	/**
	 * Make a posted invoice from an order.
	 *
	 * @param string              $collection Target collection.
	 * @param array<string,mixed> $order      The order.
	 * @param string              $prefix     Number prefix.
	 */
	private function postedFrom( string $collection, array $order, string $prefix ): void {
		$invoice = $order;
		$invoice['id']          = $this->guid();
		$invoice['number']      = $prefix . ++$this->sequence;
		$invoice['orderId']     = $order['id'];
		$invoice['orderNumber'] = $order['number'];
		$invoice['status']      = 'Open';
		$invoice['invoiceDate'] = $order['orderDate'] ?? gmdate( 'Y-m-d' );
		unset( $invoice['orderDate'] );
		$this->data[ $collection ][] = $invoice;
		$this->lines[ (string) $invoice['id'] ] = $this->lines[ (string) $order['id'] ] ?? array();
	}

	/**
	 * Index of a record by id.
	 *
	 * @param string $collection Collection.
	 * @param string $id         Id.
	 */
	private function indexOf( string $collection, string $id ): ?int {
		foreach ( $this->data[ $collection ] as $index => $row ) {
			if ( strtolower( (string) $row['id'] ) === $id ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * The decoded body of a request.
	 *
	 * @param array<string,mixed> $request Request.
	 * @return array<string,mixed>
	 */
	private function body( array $request ): array {
		$decoded = json_decode( (string) ( $request['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * An OData error body.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Decimal places of a currency.
	 *
	 * @param string $currency Code.
	 */
	private function exponent( string $currency ): int {
		return \Estasoft\SyncEngine\Core\Support\Money::exponent( $currency );
	}

	/**
	 * A random GUID.
	 */
	private function guid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
